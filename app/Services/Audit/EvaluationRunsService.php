<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\File;

/**
 * Registros dos experimentos reproduzíveis (`evaluation:run`, `storage/app/evaluation/runs/*.json`): quais existem, de que
 * versão do código, com qual dataset e quais foram os resultados, sem as listas por item (vêm só contadas).
 */
final class EvaluationRunsService
{
    /** Experimentos do Quadro 3 do TCC e a tarefa do `evaluation:run` que os produz. */
    public const EXPERIMENTS = [
        'identificacao' => ['label' => 'Identificação do medicamento', 'task' => 'identification'],
        'interacoes' => ['label' => 'Verificação de interações (DDInter)', 'task' => 'interactions'],
        'recuperacao' => ['label' => 'Recuperação de trechos (lexical, vetorial, híbrida)', 'task' => 'retrieval'],
        'geracao' => ['label' => 'Geração com validação', 'task' => 'generation'],
        'sem_recuperacao' => ['label' => 'Modelo sem recuperação (baseline)', 'task' => 'baseline'],
    ];

    /** @return list<array<string, mixed>> */
    public function runs(?string $task = null): array
    {
        $directory = storage_path('app/evaluation/runs');
        if (! File::isDirectory($directory)) {
            return [];
        }
        $runs = [];
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $run = json_decode((string) File::get($file->getPathname()), true);
            if (! is_array($run) || ! isset($run['task']) || ($task !== null && $run['task'] !== $task)) {
                continue;
            }
            $runs[] = [
                'file' => $file->getFilename(),
                'run_id' => $run['run_id'] ?? null,
                'task' => $run['task'],
                'started_at' => $run['started_at'] ?? null,
                'finished_at' => $run['finished_at'] ?? null,
                'code' => $run['code'] ?? null,
                'dataset' => $run['dataset'] ?? null,
                'models' => $run['models'] ?? null,
                'parameters' => $run['parameters'] ?? null,
                'results' => $this->compact($run['results'] ?? []),
            ];
        }
        usort($runs, fn (array $a, array $b): int => strcmp((string) $b['started_at'], (string) $a['started_at']));

        return $runs;
    }

    /**
     * O quadro de experimentos do TCC: para cada um, quantos registros existem e o mais recente.
     *
     * @return list<array<string, mixed>>
     */
    public function experiments(): array
    {
        $runs = $this->runs();

        return array_map(function (string $key, array $experiment) use ($runs): array {
            $matching = array_values(array_filter($runs, fn (array $run): bool => $run['task'] === $experiment['task']));

            return ['key' => $key, 'label' => $experiment['label'], 'task' => $experiment['task'], 'records' => count($matching), 'status' => $matching === [] ? 'sem registro' : 'medido', 'latest' => $matching[0] ?? null];
        }, array_keys(self::EXPERIMENTS), self::EXPERIMENTS);
    }

    private function compact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value) && count($value) > 15) {
            return ['_items' => count($value)];
        }

        return array_map(fn (mixed $item): mixed => $this->compact($item), $value);
    }
}
