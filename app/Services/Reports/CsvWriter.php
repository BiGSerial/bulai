<?php

namespace App\Services\Reports;

/** Escrita de CSV em memória (UTF-8 com BOM para abrir corretamente em planilhas; separador vírgula). */
final class CsvWriter
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{content: string, rows: int}
     */
    public static function write(array $columns, iterable $rows): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns, escape: '');
        $count = 0;
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): string => self::scalar($row[$column] ?? null), $columns), escape: '');
            $count++;
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return ['content' => $content, 'rows' => $count];
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
            default => (string) $value,
        };
    }
}
