<?php

namespace App\Services\Reports;

use App\Jobs\GenerateReport;
use App\Models\AdminReport;
use App\Models\User;
use App\Services\Reports\Types\AiUsageReport;
use App\Services\Reports\Types\AnalyticsReport;
use App\Services\Reports\Types\EvaluationReport;
use App\Services\Reports\Types\KnowledgeInventoryReport;
use App\Services\Reports\Types\UserAuditReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Catálogo de relatórios e ciclo de vida de cada geração: registro (PENDING) → job → arquivo no disco `reports`
 * (COMPLETED) ou FAILED com código. O mesmo serviço atende a tela de Relatórios e o comando `reports:generate`.
 */
final class ReportGenerator
{
    public const DISK = 'reports';

    public const UNKNOWN_TYPE = 'REPORT_UNKNOWN_TYPE';

    public const INVALID_FORMAT = 'REPORT_INVALID_FORMAT';

    /** @var array<string, ReportType> */
    private array $types;

    public function __construct(AnalyticsReport $analytics, KnowledgeInventoryReport $inventory, AiUsageReport $aiUsage, UserAuditReport $audit, EvaluationReport $evaluation)
    {
        $this->types = [];
        foreach ([$analytics, $inventory, $aiUsage, $audit, $evaluation] as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    /** @return array<string, ReportType> */
    public function types(): array
    {
        return $this->types;
    }

    public function type(string $key): ReportType
    {
        return $this->types[$key] ?? throw new InvalidArgumentException(self::UNKNOWN_TYPE);
    }

    /**
     * Registra o relatório e dispara a geração (fila, após a resposta ou síncrona, conforme `bulai.consultation.execution`).
     *
     * @param  array{from?: string|null, to?: string|null}  $parameters
     */
    public function start(string $type, string $format, array $parameters = [], ?User $user = null): AdminReport
    {
        $definition = $this->type($type);
        if (! in_array($format, $definition->formats(), true)) {
            throw new InvalidArgumentException(self::INVALID_FORMAT);
        }

        $report = AdminReport::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'format' => $format,
            'status' => AdminReport::STATUS_PENDING,
            'parameters' => ['from' => $definition->acceptsPeriod() ? ($parameters['from'] ?? null) : null, 'to' => $definition->acceptsPeriod() ? ($parameters['to'] ?? null) : null],
            'generated_by' => $user?->id,
        ]);

        match (config('bulai.consultation.execution')) {
            'sync' => GenerateReport::dispatchSync($report->id),
            'queue' => GenerateReport::dispatch($report->id),
            default => GenerateReport::dispatchAfterResponse($report->id),
        };

        return $report;
    }

    /** Gera o arquivo do relatório registrado. Nunca lança: falhas viram FAILED com `error_code`. */
    public function generate(AdminReport $report): AdminReport
    {
        if ($report->status !== AdminReport::STATUS_PENDING) {
            return $report;
        }
        $report->update(['status' => AdminReport::STATUS_PROCESSING]);

        try {
            $definition = $this->type($report->type);
            $built = $definition->build($report->format, ['from' => $report->parameters['from'] ?? null, 'to' => $report->parameters['to'] ?? null]);
            $path = $report->uuid.'/'.$report->fileName();
            Storage::disk(self::DISK)->put($path, $built['content']);
            $report->update([
                'status' => AdminReport::STATUS_COMPLETED,
                'path' => $path,
                'size' => strlen($built['content']),
                'rows' => $built['rows'],
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('report_generation_failed', ['report_id' => $report->id, 'type' => $report->type, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
            $report->update(['status' => AdminReport::STATUS_FAILED, 'error_code' => $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'REPORT_GENERATION_FAILED', 'completed_at' => now()]);
        }

        return $report->refresh();
    }

    /** Apaga o registro e o arquivo. */
    public function delete(AdminReport $report): void
    {
        if ($report->path !== null) {
            Storage::disk(self::DISK)->deleteDirectory($report->uuid);
        }
        $report->delete();
    }
}
