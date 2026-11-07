<?php

namespace App\Services\Patient;

use App\Services\Knowledge\AnvisaCatalogNormalizer;

/**
 * Intenção de uma pergunta livre sobre remédio: quais partes da bula ela pede ("efeitos colaterais" → Reações adversas,
 * "como tomar" → Posologia…). Determinística, por palavras-chave configuradas em `bulai.leaflet.sections.*.keywords`.
 * É com ela que o Perguntar julga se um trecho veio da seção adequada e onde procurar na bula inteira.
 */
final class QuestionIntentDetector
{
    public const INTERACTIONS = 'interacoes';

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

    /**
     * Chaves das seções pedidas, da mais provável para a menos, ou vazio quando a pergunta não diz o assunto.
     *
     * @param  int  $medicines  quantidade de medicamentos citados (dois ou mais sugerem interação)
     * @return list<string>
     */
    public function topics(string $question, int $medicines = 1): array
    {
        $text = ' '.$this->normalizer->text($question).' ';
        $hits = [];
        foreach ((array) config('bulai.leaflet.sections') as $key => $section) {
            $count = 0;
            foreach ((array) ($section['keywords'] ?? []) as $keyword) {
                $count += preg_match_all('/\b'.preg_quote($this->normalizer->text($keyword), '/').'\b/', $text);
            }
            if ($count > 0) {
                $hits[$key] = $count;
            }
        }
        if ($medicines > 1) {
            $hits[self::INTERACTIONS] = ($hits[self::INTERACTIONS] ?? 0) + 1;
        }
        arsort($hits);

        return array_keys($hits);
    }

    /** Rótulo humano da seção ("Efeitos colaterais mais comuns"). */
    public function label(string $topic): string
    {
        return (string) (config('bulai.leaflet.sections.'.$topic.'.label') ?? $topic);
    }

    /**
     * Padrões de nome de seção da bula para os assuntos pedidos (comparação sem caixa/acentos na recuperação).
     *
     * @param  list<string>  $topics
     * @return list<string>
     */
    public function sectionPatterns(array $topics): array
    {
        $patterns = [];
        foreach ($topics as $topic) {
            $patterns = [...$patterns, ...(array) (config('bulai.leaflet.sections.'.$topic.'.patterns') ?? [])];
        }

        return array_values(array_unique($patterns));
    }

    /** O nome de seção de um trecho pertence a algum dos assuntos pedidos? @param list<string> $topics */
    public function sectionMatches(?string $section, array $topics): bool
    {
        $normalized = $this->normalizer->text($section);
        if ($normalized === '') {
            return false;
        }
        foreach ($this->sectionPatterns($topics) as $pattern) {
            if (str_contains($normalized, $this->normalizer->text($pattern))) {
                return true;
            }
        }

        return false;
    }
}
