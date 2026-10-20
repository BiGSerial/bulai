<?php

namespace App\Data\Knowledge;

final readonly class ExtractedPdf
{
    /**
     * @param  list<ExtractedPage>  $pages
     * @param  array<string, mixed>  $metadata  Informações do extrator (engine, versão, duração), nunca conteúdo do documento.
     */
    public function __construct(public array $pages, public array $metadata = []) {}

    public function characterCount(): int
    {
        return array_sum(array_map(fn (ExtractedPage $page): int => mb_strlen($page->text), $this->pages));
    }
}
