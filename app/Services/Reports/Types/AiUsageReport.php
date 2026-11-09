<?php

namespace App\Services\Reports\Types;

use App\Models\AiUsageLog;
use App\Services\Reports\CsvWriter;
use App\Services\Reports\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consumo e custo de IA por dia, usuário, provedor, modelo e capacidade: chamadas, falhas, tokens (entrada total, cache
 * lido, cache gravado, saída, raciocínio), custo estimado e latência. user_id vazio = rotinas, avaliações e comandos.
 */
final class AiUsageReport implements ReportType
{
    public function key(): string
    {
        return 'ai_usage';
    }

    public function label(): string
    {
        return 'Consumo e custo de IA';
    }

    public function description(): string
    {
        return 'Por dia, usuário, provedor, modelo e capacidade: chamadas, falhas, tokens de entrada, cache e saída, custo estimado e latência média.';
    }

    public function formats(): array
    {
        return ['csv'];
    }

    public function acceptsPeriod(): bool
    {
        return true;
    }

    public function build(string $format, array $parameters): array
    {
        $query = DB::table('ai_usage_logs');
        if ($parameters['from']) {
            $query->where('created_at', '>=', CarbonImmutable::parse($parameters['from'])->startOfDay());
        }
        if ($parameters['to']) {
            $query->where('created_at', '<=', CarbonImmutable::parse($parameters['to'])->endOfDay());
        }
        $rows = $query
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, user_id, provider, model, capability, count(*) as calls, sum(case when status = ? then 1 else 0 end) as failed, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(cache_read_tokens), 0) as cache_read_tokens, coalesce(sum(cache_write_tokens), 0) as cache_write_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(reasoning_tokens), 0) as reasoning_tokens, coalesce(sum(estimated_cost_usd), 0) as cost, avg(latency_ms) as latency_avg", [AiUsageLog::STATUS_FAILED])
            ->groupBy('day', 'user_id', 'provider', 'model', 'capability')->orderBy('day')->orderBy('user_id')->orderBy('provider')->orderBy('model')
            ->get()
            ->map(fn (object $row): array => [
                'day' => $row->day, 'user_id' => $row->user_id, 'provider' => $row->provider, 'model' => $row->model, 'capability' => $row->capability, 'calls' => (int) $row->calls, 'failed' => (int) $row->failed,
                'input_tokens' => (int) $row->input_tokens, 'cache_read_tokens' => (int) $row->cache_read_tokens, 'cache_write_tokens' => (int) $row->cache_write_tokens,
                'output_tokens' => (int) $row->output_tokens, 'reasoning_tokens' => (int) $row->reasoning_tokens, 'estimated_cost_usd' => number_format((float) $row->cost, 6, '.', ''),
                'latency_avg_ms' => $row->latency_avg !== null ? round((float) $row->latency_avg, 1) : null,
            ])->all();

        return CsvWriter::write(['day', 'user_id', 'provider', 'model', 'capability', 'calls', 'failed', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens', 'reasoning_tokens', 'estimated_cost_usd', 'latency_avg_ms'], $rows);
    }
}
