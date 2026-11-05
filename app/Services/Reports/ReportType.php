<?php

namespace App\Services\Reports;

/** Um tipo de relatório: identidade, formatos aceitos e a geração do conteúdo. */
interface ReportType
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** @return list<string> */
    public function formats(): array;

    /** Verdadeiro quando o relatório aceita filtro de período (`from`/`to`). */
    public function acceptsPeriod(): bool;

    /**
     * Produz o conteúdo do arquivo e o número de linhas (ou registros) que ele contém.
     *
     * @param  array{from: string|null, to: string|null}  $parameters
     * @return array{content: string, rows: int}
     */
    public function build(string $format, array $parameters): array;
}
