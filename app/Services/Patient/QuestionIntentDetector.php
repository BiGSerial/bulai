<?php

namespace App\Services\Patient;

use App\Services\Knowledge\AnvisaCatalogNormalizer;

/**
 * Intenção de uma pergunta livre sobre remédio: quais partes da bula ela pede. Uma pergunta pode ter várias intenções
 * ("posso tomar X com café?" = segurança de uso + interações). Determinística, por palavras-chave:
 *
 * - seções simples vêm de `bulai.leaflet.sections.*.keywords` (efeitos colaterais, como usar, interações…);
 * - intenções compostas vêm de `bulai.questions.intents` (segurança de uso → para que serve + quando não usar + cuidados;
 *   comparação entre medicamentos).
 *
 * `plan()` devolve as seções que a recuperação deve consultar, uma a uma (cada seção com a sua própria busca), em vez de uma
 * única busca com a pergunta inteira. Sem intenção reconhecida a pergunta é geral e consulta `bulai.questions.general_sections`.
 * Na pergunta de acompanhamento ("mas por quê?") a intenção da troca anterior é herdada.
 */
final class QuestionIntentDetector
{
    public const INTERACTIONS = 'interacoes';

    /** Palavras da pergunta que não ajudam a achar o trecho certo (a busca textual une os termos com OU). */
    private const SEARCH_STOPWORDS = [
        'posso', 'pode', 'podem', 'devo', 'deve', 'tomar', 'tomo', 'toma', 'usar', 'uso', 'para', 'como', 'com', 'sem', 'que', 'qual', 'quais', 'quando',
        'porque', 'por', 'uma', 'uns', 'umas', 'esse', 'essa', 'isso', 'este', 'esta', 'mais', 'menos', 'muito', 'meu', 'minha', 'meus', 'minhas', 'estou',
        'tenho', 'sobre', 'fazer', 'faz', 'remedio', 'remedios', 'medicamento', 'medicamentos', 'tambem', 'mas', 'ele', 'ela', 'dele', 'dela', 'sera', 'seria',
    ];

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

    /**
     * Intenções reconhecidas (chaves de seção e intenções compostas), da mais provável para a menos, ou vazio quando a
     * pergunta não diz o assunto.
     *
     * @param  int  $medicines  quantidade de medicamentos citados (dois ou mais sugerem interação)
     * @return list<string>
     */
    public function topics(string $question, int $medicines = 1): array
    {
        $text = ' '.$this->normalizer->text($question).' ';
        $hits = [];
        $sources = [...(array) config('bulai.leaflet.sections'), ...(array) config('bulai.questions.intents')];
        foreach ($sources as $key => $definition) {
            $count = 0;
            foreach ((array) ($definition['keywords'] ?? []) as $keyword) {
                $count += preg_match_all('/\b'.preg_quote($this->normalizer->text($keyword), '/').'\b/', $text);
            }
            if ($count > 0) {
                $hits[$key] = $count;
            }
        }
        if ($medicines > 1) {
            $hits[self::INTERACTIONS] = ($hits[self::INTERACTIONS] ?? 0) + 1;
        }
        // "posso" sozinho é fraco: só vale como segurança de uso quando nenhuma outra intenção específica apareceu.
        if (isset($hits['seguranca_de_uso']) && count($hits) > 1 && ! preg_match('/\b(posso|pode|devo) (tomar|usar)\b|faz mal|e seguro|tem (problema|perigo)/', $text)) {
            unset($hits['seguranca_de_uso']);
        }
        arsort($hits);

        return array_keys($hits);
    }

    /**
     * Plano de consulta à bula.
     *
     * @param  list<string>  $inherited  intenções da troca anterior (usadas quando esta não diz o assunto)
     * @return array{topics: list<string>, sections: list<string>, labels: list<string>, ambiguous: bool, inherited: bool}
     */
    public function plan(string $question, int $medicines = 1, array $inherited = []): array
    {
        $topics = $this->topics($question, $medicines);
        $isInherited = false;
        if ($topics === [] && $inherited !== []) {
            $topics = $inherited;
            $isInherited = true;
        }
        $ambiguous = $topics === [];

        $sections = [];
        foreach ($ambiguous ? (array) config('bulai.questions.general_sections') : $topics as $topic) {
            $expands = config('bulai.questions.intents.'.$topic.'.expands');
            foreach (is_array($expands) ? $expands : [$topic] as $section) {
                $sections[$section] = true;
            }
        }
        if ($medicines > 1 && ! $ambiguous) {
            $sections[self::INTERACTIONS] = true;
        }
        $sections = array_slice(array_keys($sections), 0, (int) config('bulai.questions.max_sections'));

        return [
            'topics' => $topics,
            'sections' => $sections,
            'labels' => array_values(array_map(fn (string $topic): string => $this->label($topic), $topics)),
            'ambiguous' => $ambiguous,
            'inherited' => $isInherited,
        ];
    }

    /** Palavras úteis da pergunta para a busca textual (sem as palavras comuns de pergunta). */
    public function searchTerms(string $question): string
    {
        $terms = array_filter(explode(' ', $this->normalizer->text($question)), fn (string $term): bool => mb_strlen($term) >= 4 && ! in_array($term, self::SEARCH_STOPWORDS, true));

        return implode(' ', array_unique($terms));
    }

    /** Rótulo humano da intenção ou da seção ("Efeitos colaterais mais comuns"). */
    public function label(string $topic): string
    {
        return (string) (config('bulai.questions.intents.'.$topic.'.label') ?? config('bulai.leaflet.sections.'.$topic.'.label') ?? $topic);
    }

    /** Consulta de recuperação da seção, em palavras de bula. */
    public function sectionQuery(string $section): string
    {
        return (string) config('bulai.leaflet.sections.'.$section.'.query', '');
    }

    /**
     * Padrões de nome de seção da bula para as seções pedidas (comparação sem caixa/acentos na recuperação).
     *
     * @param  list<string>  $topics
     * @return list<string>
     */
    public function sectionPatterns(array $topics): array
    {
        $patterns = [];
        foreach ($topics as $topic) {
            $expands = config('bulai.questions.intents.'.$topic.'.expands');
            foreach (is_array($expands) ? $expands : [$topic] as $section) {
                $patterns = [...$patterns, ...(array) (config('bulai.leaflet.sections.'.$section.'.patterns') ?? [])];
            }
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
        $patterns = $this->sectionPatterns($topics);
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!') && str_contains($normalized, $this->normalizer->text(substr($pattern, 1)))) {
                return false;
            }
        }
        foreach ($patterns as $pattern) {
            if (! str_starts_with($pattern, '!') && str_contains($normalized, $this->normalizer->text($pattern))) {
                return true;
            }
        }

        return false;
    }
}
