<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\AnvisaCatalogImport;
use App\Models\Knowledge\AnvisaMedicine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AnvisaMedicineCsvImporter
{
    private const DATASET = 'ANVISA - Dados Abertos de Medicamentos';

    private const HEADERS = ['TIPO_PRODUTO', 'NOME_PRODUTO', 'DATA_FINALIZACAO_PROCESSO', 'CATEGORIA_REGULATORIA', 'NUMERO_REGISTRO_PRODUTO', 'DATA_VENCIMENTO_REGISTRO', 'NUMERO_PROCESSO', 'CLASSE_TERAPEUTICA', 'EMPRESA_DETENTORA_REGISTRO', 'SITUACAO_REGISTRO', 'PRINCIPIO_ATIVO'];

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

    public function import(string $path, ?int $sourceId = null, string $encoding = 'ISO-8859-1', ?AnvisaCatalogImport $tracking = null): AnvisaCatalogImport
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('CATALOG_FILE_NOT_READABLE');
        }
        $sha256 = hash_file('sha256', $path);
        $existing = AnvisaCatalogImport::query()->where('sha256', $sha256)->where('status', 'COMPLETED')->first();
        if ($existing !== null && $existing->isNot($tracking)) {
            $tracking?->delete();

            return $existing;
        }

        $attributes = [
            'source_id' => $sourceId, 'dataset_name' => self::DATASET, 'original_filename' => $tracking?->original_filename ?? basename($path),
            'sha256' => $sha256, 'encoding' => $encoding, 'delimiter' => ';', 'status' => 'PROCESSING', 'started_at' => now(),
            'metadata' => [...($tracking?->metadata ?? []), 'catalog_version' => basename($path)],
        ];
        $import = $tracking ?? new AnvisaCatalogImport;
        $import->forceFill($attributes)->save();

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CATALOG_FILE_NOT_READABLE');
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '\\');
            if ($header !== self::HEADERS) {
                throw new RuntimeException('CATALOG_HEADERS_INVALID');
            }

            $counters = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0];
            $buffer = [];
            $seenRecordKeys = [];
            while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                $counters['read']++;
                $attributes = $this->mapRow($row, $encoding, $sourceId, $import->id, $counters['read']);
                if ($attributes === null) {
                    $counters['rejected']++;

                    continue;
                }
                if (isset($seenRecordKeys[$attributes['record_key']])) {
                    $counters['skipped']++;

                    continue;
                }
                $seenRecordKeys[$attributes['record_key']] = true;
                $buffer[$attributes['record_key']] = $attributes;
                if (count($buffer) >= 750) {
                    $this->flush($buffer, $counters);
                    $this->reportProgress($import, $counters);
                }
            }
            $this->flush($buffer, $counters);

            $import->forceFill([
                'status' => 'COMPLETED', 'rows_read' => $counters['read'], 'rows_inserted' => $counters['inserted'],
                'rows_updated' => $counters['updated'], 'rows_skipped' => $counters['skipped'],
                'rows_rejected' => $counters['rejected'], 'completed_at' => now(),
            ])->save();

            return $import->refresh();
        } catch (\Throwable $exception) {
            $import->forceFill(['status' => 'FAILED', 'completed_at' => now(), 'metadata' => [...($import->metadata ?? []), 'error_code' => 'CATALOG_IMPORT_FAILED']])->save();
            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /** @param array{read: int, inserted: int, updated: int, skipped: int, rejected: int} $counters */
    private function reportProgress(AnvisaCatalogImport $import, array $counters): void
    {
        $import->forceFill([
            'rows_read' => $counters['read'], 'rows_inserted' => $counters['inserted'], 'rows_updated' => $counters['updated'],
            'rows_skipped' => $counters['skipped'], 'rows_rejected' => $counters['rejected'],
        ])->saveQuietly();
    }

    /** @return array<string, mixed>|null */
    private function mapRow(array $row, string $encoding, ?int $sourceId, int $importId, int $rowNumber): ?array
    {
        if (count($row) !== count(self::HEADERS)) {
            return null;
        }
        $row = array_map(fn (string $value): string => trim(mb_convert_encoding($value, 'UTF-8', $encoding)), $row);
        [$type, $product, $completed, $category, $registrationOriginal, $expires, $process, $therapeuticClass, $holder, $status, $ingredient] = $row;
        if ($product === '' || $holder === '' || $status === '') {
            return null;
        }
        $registration = $this->normalizer->registrationNumber($registrationOriginal);
        $recordKey = $registration !== null ? 'registration:'.$registration : ($process !== '' ? 'process:'.$process : 'row:'.hash('sha256', implode('|', $row)));
        $now = now();

        return [
            'source_id' => $sourceId, 'catalog_import_id' => $importId, 'record_key' => $recordKey,
            'registration_number_original' => $registrationOriginal !== '' ? $registrationOriginal : null,
            'registration_number' => $registration, 'process_number' => $process !== '' ? $process : null,
            'product_name' => $product, 'normalized_product_name' => $this->normalizer->text($product),
            'regulatory_category' => $category !== '' ? $category : null, 'active_ingredient' => $ingredient !== '' ? $ingredient : null,
            'normalized_active_ingredient' => $ingredient !== '' ? implode('|', $this->normalizer->activeIngredientSet($ingredient)) : null,
            'therapeutic_class' => $therapeuticClass !== '' ? $therapeuticClass : null,
            'registration_holder' => $holder, 'normalized_registration_holder' => implode('|', $this->normalizer->holderTokens($holder)),
            'registration_status' => $status, 'process_completed_on' => $completed !== '' ? $completed : null,
            'registration_expires_on' => $expires !== '' ? $expires : null,
            'metadata' => json_encode(['product_type' => $type, 'source_row' => $rowNumber], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /** @param array<string, array<string, mixed>> $buffer @param array{read: int, inserted: int, updated: int, skipped: int, rejected: int} $counters */
    private function flush(array &$buffer, array &$counters): void
    {
        if ($buffer === []) {
            return;
        }
        $existing = AnvisaMedicine::query()->whereIn('record_key', array_keys($buffer))->pluck('record_key')->all();
        $counters['updated'] += count($existing);
        $counters['inserted'] += count($buffer) - count($existing);
        DB::table((new AnvisaMedicine)->getTable())->upsert(array_values($buffer), ['record_key'], [
            'source_id', 'catalog_import_id', 'registration_number_original', 'registration_number', 'process_number', 'product_name',
            'normalized_product_name', 'regulatory_category', 'active_ingredient', 'normalized_active_ingredient', 'therapeutic_class',
            'registration_holder', 'normalized_registration_holder', 'registration_status', 'process_completed_on',
            'registration_expires_on', 'metadata', 'updated_at',
        ]);
        $buffer = [];
    }
}
