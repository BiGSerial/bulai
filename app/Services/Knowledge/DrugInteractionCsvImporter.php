<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\DrugInteractionImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importa de forma idempotente os CSVs do DDInter (DDInterID_A, Drug_A, DDInterID_B, Drug_B, Level).
 * Cada par é guardado uma vez, em ordem canônica de ID; o mesmo par em vários arquivos é atualizado, não duplicado.
 */
final class DrugInteractionCsvImporter
{
    public const DATASET = 'DDInter - Interações medicamentosas';

    private const HEADERS = ['DDInterID_A', 'Drug_A', 'DDInterID_B', 'Drug_B', 'Level'];

    private const BATCH_SIZE = 1000;

    public function import(string $path, ?DrugInteractionImport $tracking = null): DrugInteractionImport
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('INTERACTIONS_FILE_NOT_READABLE');
        }
        $sha256 = hash_file('sha256', $path);
        $existing = DrugInteractionImport::query()->where('sha256', $sha256)->where('status', 'COMPLETED')->first();
        if ($existing !== null && $existing->isNot($tracking)) {
            $tracking?->delete();

            return $existing;
        }

        $import = $tracking ?? new DrugInteractionImport;
        $import->forceFill([
            'dataset_name' => self::DATASET, 'original_filename' => $tracking?->original_filename ?? basename($path), 'sha256' => $sha256,
            'status' => 'PROCESSING', 'started_at' => now(), 'completed_at' => null,
        ])->save();

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('INTERACTIONS_FILE_NOT_READABLE');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if (is_array($header) && isset($header[0])) {
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            }
            if ($header !== self::HEADERS) {
                throw new RuntimeException('INTERACTIONS_HEADERS_INVALID');
            }

            $counters = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0];
            $buffer = [];
            $seen = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $counters['read']++;
                $record = $this->mapRow($row, $import->id);
                if ($record === null) {
                    $counters['rejected']++;

                    continue;
                }
                if (isset($seen[$record['pair_key']])) {
                    $counters['skipped']++;

                    continue;
                }
                $seen[$record['pair_key']] = true;
                $buffer[$record['pair_key']] = $record;
                if (count($buffer) >= self::BATCH_SIZE) {
                    $this->flush($buffer, $counters);
                    $this->reportProgress($import, $counters);
                }
            }
            $this->flush($buffer, $counters);

            $import->forceFill([
                'status' => 'COMPLETED', 'rows_read' => $counters['read'], 'rows_inserted' => $counters['inserted'],
                'rows_updated' => $counters['updated'], 'rows_skipped' => $counters['skipped'], 'rows_rejected' => $counters['rejected'],
                'completed_at' => now(),
            ])->save();

            return $import->refresh();
        } catch (\Throwable $exception) {
            $import->forceFill(['status' => 'FAILED', 'completed_at' => now(), 'metadata' => [...($import->metadata ?? []), 'error_code' => $exception->getMessage() === 'INTERACTIONS_HEADERS_INVALID' ? 'INTERACTIONS_HEADERS_INVALID' : 'INTERACTIONS_IMPORT_FAILED']])->save();
            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $row
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row, int $importId): ?array
    {
        if (count($row) !== count(self::HEADERS)) {
            return null;
        }
        [$idA, $nameA, $idB, $nameB, $level] = array_map(fn (?string $value): string => trim((string) $value), $row);
        $severity = strtolower($level);
        if (preg_match('/^DDInter(\d+)$/', $idA, $matchA) !== 1 || preg_match('/^DDInter(\d+)$/', $idB, $matchB) !== 1
            || $nameA === '' || $nameB === '' || $idA === $idB || ! in_array($severity, DrugInteraction::SEVERITIES, true)) {
            return null;
        }
        if ((int) $matchA[1] > (int) $matchB[1]) {
            [$idA, $nameA, $idB, $nameB] = [$idB, $nameB, $idA, $nameA];
        }
        $now = now();

        return [
            'import_id' => $importId, 'source' => 'ddinter', 'pair_key' => 'ddinter:'.strtolower($idA).':'.strtolower($idB),
            'drug_a_external_id' => $idA, 'drug_a_name' => Str::limit($nameA, 250, ''), 'normalized_drug_a_name' => $this->normalize($nameA),
            'drug_b_external_id' => $idB, 'drug_b_name' => Str::limit($nameB, 250, ''), 'normalized_drug_b_name' => $this->normalize($nameB),
            'severity' => $severity, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /** Minúsculas, sem acentos e com espaços simples: base para ligar o nome ao princípio ativo. */
    public function normalize(string $name): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($name)))), 250, '');
    }

    /**
     * @param  array<string, array<string, mixed>>  $buffer
     * @param  array{read: int, inserted: int, updated: int, skipped: int, rejected: int}  $counters
     */
    private function flush(array &$buffer, array &$counters): void
    {
        if ($buffer === []) {
            return;
        }
        $existing = DrugInteraction::query()->whereIn('pair_key', array_keys($buffer))->pluck('pair_key')->all();
        $counters['updated'] += count($existing);
        $counters['inserted'] += count($buffer) - count($existing);
        DB::table((new DrugInteraction)->getTable())->upsert(array_values($buffer), ['pair_key'], [
            'import_id', 'drug_a_name', 'normalized_drug_a_name', 'drug_b_name', 'normalized_drug_b_name', 'severity', 'updated_at',
        ]);
        $buffer = [];
    }

    /**
     * @param  array{read: int, inserted: int, updated: int, skipped: int, rejected: int}  $counters
     */
    private function reportProgress(DrugInteractionImport $import, array $counters): void
    {
        $import->forceFill([
            'rows_read' => $counters['read'], 'rows_inserted' => $counters['inserted'], 'rows_updated' => $counters['updated'],
            'rows_skipped' => $counters['skipped'], 'rows_rejected' => $counters['rejected'],
        ])->saveQuietly();
    }
}
