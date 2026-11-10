<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPdf;
use App\Data\Knowledge\KnowledgeSection;
use App\Data\Knowledge\KnowledgeTextBlock;
use Illuminate\Support\Str;

final class KnowledgeSectionDetector
{
    /** @var array<string, string> */
    private const KNOWN_SECTIONS = [
        'IDENTIFICACAO DO MEDICAMENTO' => 'IDENTIFICAÇÃO DO MEDICAMENTO',
        'APRESENTACOES' => 'APRESENTAÇÕES',
        'COMPOSICAO' => 'COMPOSIÇÃO',
        'INDICACOES' => 'INDICAÇÕES',
        'RESULTADOS DE EFICACIA' => 'RESULTADOS DE EFICÁCIA',
        'CARACTERISTICAS FARMACOLOGICAS' => 'CARACTERÍSTICAS FARMACOLÓGICAS',
        'CONTRAINDICACOES' => 'CONTRAINDICAÇÕES',
        'ADVERTENCIAS E PRECAUCOES' => 'ADVERTÊNCIAS E PRECAUÇÕES',
        'INTERACOES MEDICAMENTOSAS' => 'INTERAÇÕES MEDICAMENTOSAS',
        'CUIDADOS DE ARMAZENAMENTO' => 'CUIDADOS DE ARMAZENAMENTO',
        'POSOLOGIA E MODO DE USAR' => 'POSOLOGIA E MODO DE USAR',
        'REACOES ADVERSAS' => 'REAÇÕES ADVERSAS',
        'SUPERDOSE' => 'SUPERDOSE',
    ];

    /** @return list<KnowledgeSection> */
    public function detect(ExtractedPdf $document): array
    {
        $sections = [];
        $currentName = null;
        $currentOriginalTitle = null;
        $currentBlocks = [];

        foreach ($document->pages as $page) {
            $paragraph = [];
            foreach (explode("\n", $page->text) as $line) {
                $line = trim($line);
                if ($line === '') {
                    $this->appendParagraph($currentBlocks, $paragraph, $page->number);
                    $paragraph = [];

                    continue;
                }

                $heading = $this->detectHeading($line);
                if ($heading !== null) {
                    $this->appendParagraph($currentBlocks, $paragraph, $page->number);
                    $paragraph = [];
                    // Título quebrado em duas linhas ("4. O QUE DEVO SABER ANTES DE" + "USAR ESTE MEDICAMENTO?"): a segunda
                    // linha completa o título anterior, que ainda não tem texto e não terminou.
                    if ($currentBlocks === [] && $currentName !== null && ! preg_match('/[?:]$/u', (string) $currentOriginalTitle) && ! isset(self::KNOWN_SECTIONS[$this->key($currentName)]) && ! isset(self::KNOWN_SECTIONS[$this->key($heading[0])])) {
                        $currentName .= ' '.$heading[0];
                        $currentOriginalTitle .= ' '.$heading[1];

                        continue;
                    }
                    if ($currentBlocks !== []) {
                        $sections[] = new KnowledgeSection($currentName, $currentOriginalTitle, $currentBlocks);
                    }
                    [$currentName, $currentOriginalTitle] = $heading;
                    $currentBlocks = [];

                    continue;
                }

                $paragraph[] = $line;
            }
            $this->appendParagraph($currentBlocks, $paragraph, $page->number);
        }

        if ($currentBlocks !== []) {
            $sections[] = new KnowledgeSection($currentName, $currentOriginalTitle, $currentBlocks);
        }

        return $sections;
    }

    /** @return array{string, string}|null */
    private function detectHeading(string $line): ?array
    {
        $withoutNumber = trim(preg_replace('/^\s*(?:\d+(?:\.\d+)*[.\-)\s]+|[IVXLCDM]+[.\-)\s]+)/iu', '', $line) ?? $line);
        $key = $this->key($withoutNumber);

        if (isset(self::KNOWN_SECTIONS[$key])) {
            return [self::KNOWN_SECTIONS[$key], $line];
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $withoutNumber) ?? '';
        $isGenericHeading = mb_strlen($withoutNumber) <= 100
            && mb_strlen($letters) >= 3
            && ! preg_match('/\d/u', $withoutNumber)
            && $letters === mb_strtoupper($letters)
            // Títulos da bula do paciente são perguntas em maiúsculas ("PARA QUE ESTE MEDICAMENTO É INDICADO?").
            && ! preg_match('/[.!;]$/u', $withoutNumber);

        return $isGenericHeading ? [$withoutNumber, $line] : null;
    }

    private function key(string $title): string
    {
        $key = mb_strtoupper(Str::ascii($title));
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;

        return rtrim($key, ':');
    }

    /** @param list<KnowledgeTextBlock> $blocks @param list<string> $paragraph */
    private function appendParagraph(array &$blocks, array $paragraph, int $page): void
    {
        if ($paragraph !== []) {
            $blocks[] = new KnowledgeTextBlock($page, implode(' ', $paragraph));
        }
    }
}
