<?php

namespace App\Services\Reports\Types;

use App\Services\Analytics\AnalyticsService;
use App\Services\Reports\CsvWriter;
use App\Services\Reports\ReportType;
use Carbon\CarbonImmutable;

/** Analytics do período: agregado + linhas anonimizadas de pares e perguntas (mesmo conteúdo de `analytics:export`). */
final class AnalyticsReport implements ReportType
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function key(): string
    {
        return 'analytics';
    }

    public function label(): string
    {
        return 'Analytics do período';
    }

    public function description(): string
    {
        return 'Consultas, perguntas, bulas humanizadas, ancoragem, latências e consumo de IA; linhas anonimizadas por par e por pergunta.';
    }

    public function formats(): array
    {
        return ['json', 'csv'];
    }

    public function acceptsPeriod(): bool
    {
        return true;
    }

    public function build(string $format, array $parameters): array
    {
        $from = $parameters['from'] ? CarbonImmutable::parse($parameters['from'])->startOfDay() : null;
        $to = $parameters['to'] ? CarbonImmutable::parse($parameters['to'])->endOfDay() : null;
        $pairs = $this->analytics->pairRows($from, $to);
        $questions = $this->analytics->questionRows($from, $to);

        if ($format === 'json') {
            $payload = ['generated_at' => now()->toIso8601String(), 'code_version' => config('bulai.code_version'), 'overview' => $this->analytics->overview($from, $to), 'pairs' => $pairs, 'questions' => $questions];

            return ['content' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'rows' => count($pairs) + count($questions)];
        }

        $columns = ['kind', 'uuid', 'created_at', 'status', 'retrieval_mode', 'verdict', 'severity', 'position', 'has_explanation', 'evidence_count', 'generation_status', 'claims_accepted', 'claims_rejected', 'input_tokens', 'output_tokens', 'duration_ms', 'lookup_ms', 'retrieval_ms', 'generation_ms', 'treatment_consented', 'treatment_in_use', 'treatment_interactions', 'treatment_highest_severity', 'error_code'];
        $rows = [...array_map(fn (array $row): array => ['kind' => 'pair'] + $row, $pairs), ...array_map(fn (array $row): array => ['kind' => 'question'] + $row, $questions)];

        return CsvWriter::write($columns, $rows);
    }
}
