<?php

namespace App\Services\Reports\Types;

use App\Services\Reports\ReportType;
use Illuminate\Support\Facades\File;

/** Avaliação: pacote JSON com todos os registros de `storage/app/evaluation/runs` (experimentos reproduzíveis do TCC). */
final class EvaluationReport implements ReportType
{
    public function key(): string
    {
        return 'evaluation';
    }

    public function label(): string
    {
        return 'Registros de avaliação';
    }

    public function description(): string
    {
        return 'Todos os registros de experimentos (identificação, interações, recuperação, geração, baseline) em um único arquivo JSON.';
    }

    public function formats(): array
    {
        return ['json'];
    }

    public function acceptsPeriod(): bool
    {
        return false;
    }

    public function build(string $format, array $parameters): array
    {
        $directory = storage_path('app/evaluation/runs');
        $runs = [];
        if (File::isDirectory($directory)) {
            foreach (File::files($directory) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }
                $decoded = json_decode((string) File::get($file->getPathname()), true);
                $runs[] = ['file' => $file->getFilename(), 'size' => $file->getSize(), 'run' => is_array($decoded) ? $decoded : null];
            }
        }
        usort($runs, fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return ['content' => (string) json_encode(['generated_at' => now()->toIso8601String(), 'runs' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'rows' => count($runs)];
    }
}
