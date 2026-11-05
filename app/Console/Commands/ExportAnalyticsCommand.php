<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('analytics:export {--from= : Data inicial (AAAA-MM-DD)} {--to= : Data final (AAAA-MM-DD, inclusive)} {--format=json : json|csv} {--out= : Caminho do arquivo (padrão: storage/app/evaluation/analytics-<data>.<ext>)}')]
#[Description('Exporta o agregado de analytics e as linhas anonimizadas (pares e perguntas) para o TCC')]
class ExportAnalyticsCommand extends Command
{
    public function handle(AnalyticsService $analytics): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['json', 'csv'], true)) {
            $this->components->error('Formato inválido: use json ou csv.');

            return self::INVALID;
        }

        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to'))->endOfDay() : null;
        $path = (string) ($this->option('out') ?: storage_path('app/evaluation/analytics-'.now()->format('Ymd-His').'.'.$format));
        File::ensureDirectoryExists(dirname($path));

        $overview = $analytics->overview($from, $to);
        $pairs = $analytics->pairRows($from, $to);
        $questions = $analytics->questionRows($from, $to);

        if ($format === 'json') {
            File::put($path, json_encode([
                'generated_at' => now()->toIso8601String(),
                'code_version' => config('bulai.code_version'),
                'overview' => $overview,
                'pairs' => $pairs,
                'questions' => $questions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            File::put($path, $this->csv($pairs, $questions));
        }

        $this->components->info(sprintf('Exportado: %s (%d pares, %d perguntas).', $path, count($pairs), count($questions)));

        return self::SUCCESS;
    }

    /**
     * Uma linha por par e por pergunta; colunas unificadas (vazias quando não se aplicam).
     *
     * @param  list<array<string, mixed>>  $pairs
     * @param  list<array<string, mixed>>  $questions
     */
    private function csv(array $pairs, array $questions): string
    {
        $columns = ['kind', 'uuid', 'created_at', 'status', 'retrieval_mode', 'verdict', 'severity', 'position', 'has_explanation', 'evidence_count', 'generation_status', 'claims_accepted', 'claims_rejected', 'input_tokens', 'output_tokens', 'duration_ms', 'lookup_ms', 'retrieval_ms', 'generation_ms', 'treatment_consented', 'treatment_in_use', 'treatment_interactions', 'treatment_highest_severity', 'error_code'];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns, escape: '');
        foreach ($pairs as $row) {
            fputcsv($handle, $this->row($columns, ['kind' => 'pair', 'uuid' => $row['consultation_uuid'], 'created_at' => $row['consultation_created_at'], 'status' => $row['consultation_status'], 'duration_ms' => $row['consultation_duration_ms']] + $row), escape: '');
        }
        foreach ($questions as $row) {
            fputcsv($handle, $this->row($columns, ['kind' => 'question', 'uuid' => $row['question_uuid']] + $row), escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function row(array $columns, array $row): array
    {
        return array_map(function (string $column) use ($row): string {
            $value = $row[$column] ?? null;

            return match (true) {
                $value === null => '',
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        }, $columns);
    }
}
