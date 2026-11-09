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
 *
 * Os assuntos saem em ordem de prioridade (o primeiro é o principal) e também como intenções canônicas (`INTENTS`), que guiam
 * o tamanho e o tom da resposta. Sintomas ("dor de cabeça", "febre") não são efeito adverso por si: "para dor de cabeça" é o
 * que a pessoa quer tratar (indicação) e "dá dor de cabeça" é efeito adverso. "Posso tomar" com uma finalidade, uma dose ou
 * uma alergia não vira avaliação de segurança completa: a finalidade, a dose ou a contraindicação vêm primeiro.
 */
final class QuestionIntentDetector
{
    public const INTERACTIONS = 'interacoes';

    public const PERSONAL_USE_TOPIC = 'seguranca_de_uso';

    /** Intenções canônicas e o assunto (seção ou intenção composta) que cada uma representa. */
    public const INTENTS = [
        'EMERGENCY' => 'procure_ajuda',
        'CONTRAINDICATION' => 'nao_use_se',
        'DRUG_INTERACTION' => 'interacoes',
        'DOSAGE' => 'como_usar',
        'PERSONAL_USE' => 'seguranca_de_uso',
        'INDICATION' => 'para_que_serve',
        'ADVERSE_EFFECT' => 'efeitos_colaterais',
    ];

    /** Ordem dos assuntos: o primeiro reconhecido é a intenção principal. Os que não estão aqui vêm depois, na ordem da config. */
    private const PRIORITY = ['procure_ajuda', 'nao_use_se', 'interacoes', 'como_usar', 'seguranca_de_uso', 'para_que_serve', 'efeitos_colaterais', 'riscos', 'beneficios', 'comparacao'];

    /** Assuntos que, junto de "posso tomar", já dizem o que a pessoa quer saber: a segurança de uso vira só uma ressalva curta. */
    private const CONCRETE_TOPICS = ['para_que_serve', 'como_usar', 'nao_use_se', 'interacoes', 'efeitos_colaterais', 'procure_ajuda'];

    /** Verbo de causa logo antes do sintoma: "dá sono", "causa tontura", "me deu dor de cabeça". */
    private const ADVERSE_CUE = '(causa|causar|provoca|provocar|da|dar|deu|deixa|faz|fazer|traz|me deixou|me deu|me da)( muita| muito| mais)?';

    /** Finalidade logo antes do sintoma: "para dor de cabeça", "alivia a febre", "baixa febre", "tira a dor". */
    private const TARGET_CUE = '(para|pra|contra|alivia|aliviar|tira|tirar|baixa|baixar|abaixa|abaixar|melhora|melhorar|trata|tratar|cura|curar|resolve|ajuda (com|na|no|a))( a| o| as| os| de| da| do| minha| meu| essa| esse| uma| um)?';

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
        // Dois remédios citados sugerem interação, mas um assunto dito com todas as letras ("qual a diferença") continua o principal.
        $implicitInteraction = $medicines > 1 && ! isset($hits[self::INTERACTIONS]);
        $role = $this->symptom($text)['role'];
        if ($role === 'target') {
            $hits['para_que_serve'] = ($hits['para_que_serve'] ?? 0) + 1;
        } elseif ($role === 'adverse') {
            $hits['efeitos_colaterais'] = ($hits['efeitos_colaterais'] ?? 0) + 1;
        }
        // "posso" sozinho é fraco: só vale como segurança de uso quando nenhuma outra intenção específica apareceu.
        if (isset($hits[self::PERSONAL_USE_TOPIC]) && count($hits) > 1 && ! preg_match('/\b(posso|pode|devo) (tomar|usar)\b|faz mal|e seguro|tem (problema|perigo)/', $text)) {
            unset($hits[self::PERSONAL_USE_TOPIC]);
        }
        // "Quantas gotas posso tomar?" pergunta a dose, não pede uma avaliação de segurança.
        if (isset($hits[self::PERSONAL_USE_TOPIC], $hits['como_usar'])) {
            unset($hits[self::PERSONAL_USE_TOPIC]);
        }
        $order = array_flip(array_values(array_unique([...self::PRIORITY, ...array_keys($sources), self::INTERACTIONS])));
        uksort($hits, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        if ($implicitInteraction) {
            $hits[self::INTERACTIONS] = 1;
        }

        return array_keys($hits);
    }

    /**
     * Sintoma citado e o papel dele na pergunta: `target` (o que a pessoa quer tratar: "serve para dor de cabeça?", "estou com
     * febre, posso tomar?"), `adverse` (o que o remédio causa: "dá sono?", "fiquei tonto depois de tomar") ou null.
     *
     * @return array{symptom: string|null, role: string|null}
     */
    public function symptom(string $question): array
    {
        $text = ' '.$this->normalizer->text($question).' ';
        foreach ((array) config('bulai.questions.symptoms') as $symptom) {
            $term = preg_quote($this->normalizer->text((string) $symptom), '/');
            if (preg_match('/\b'.$term.'\b/', $text) !== 1) {
                continue;
            }
            if (preg_match('/\b'.self::ADVERSE_CUE.' '.$term.'\b/', $text) === 1 || preg_match('/\b(depois de|apos|desde que) (tomar|usar|comecei)\b/', $text) === 1) {
                return ['symptom' => (string) $symptom, 'role' => 'adverse'];
            }
            if (preg_match('/\b'.self::TARGET_CUE.' '.$term.'\b/', $text) === 1 || preg_match('/\b(estou com|to com|tenho|sinto|sentindo|estou sentindo)( uma| um| muita| muito)? '.$term.'\b/', $text) === 1) {
                return ['symptom' => (string) $symptom, 'role' => 'target'];
            }

            // Sintoma solto ("dipirona e tontura?"): como antes, é lido como efeito adverso.
            return ['symptom' => (string) $symptom, 'role' => 'adverse'];
        }

        return ['symptom' => null, 'role' => null];
    }

    /**
     * Intenções canônicas da pergunta, a principal primeiro. `FOLLOW_UP` acompanha a pergunta que continua a conversa e
     * `EMERGENCY` a que tem sinal de urgência (detectado antes, por `UrgencyDetector`).
     *
     * @param  list<string>  $topics  assuntos do plano, em ordem de prioridade
     * @return array{primary: string, intents: list<string>}
     */
    public function canonical(array $topics, bool $followUp = false, bool $urgent = false): array
    {
        $byTopic = array_flip(self::INTENTS);
        $intents = $urgent ? ['EMERGENCY'] : [];
        foreach ($topics as $topic) {
            $intents[] = $byTopic[$topic] ?? 'DRUG_INFORMATION';
        }
        if ($intents === []) {
            $intents[] = 'DRUG_INFORMATION';
        }
        if ($followUp) {
            $intents[] = 'FOLLOW_UP';
        }
        $intents = array_values(array_unique($intents));

        return ['primary' => $intents[0], 'intents' => $intents];
    }

    /** "Quantas gotas…", "comprimido", "xarope": a apresentação que a pessoa citou, quando citou. */
    public function presentation(string $question): ?string
    {
        $text = ' '.$this->normalizer->text($question).' ';
        foreach (['gotas' => 'gotas', 'comprimido' => 'comprimido', 'capsula' => 'cápsula', 'xarope' => 'xarope', 'solucao oral' => 'solução oral', 'injetavel' => 'injetável', 'injecao' => 'injetável', 'supositorio' => 'supositório', 'pomada' => 'pomada', 'creme' => 'creme', 'suspensao' => 'suspensão', 'sache' => 'sachê', 'efervescente' => 'efervescente'] as $needle => $label) {
            if (preg_match('/\b'.$needle.'s?\b/', $text) === 1) {
                return $label;
            }
        }

        return null;
    }

    /** "Outro remédio" sem dizer qual: a resposta precisa perguntar o nome. */
    public function mentionsUnnamedMedicine(string $question): bool
    {
        return preg_match('/\b(outro|outra|outros|outras|algum|alguma) (remedio|remedios|medicamento|medicamentos|comprimido|comprimidos)\b/', ' '.$this->normalizer->text($question).' ') === 1;
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

        // "Posso tomar" junto de um assunto concreto (finalidade, dose, alergia, outro remédio): o assunto concreto manda e a segurança
        // de uso entra só como a seção "quando não usar", depois dele. Sozinho, "posso tomar?" continua pedindo as três seções.
        // Só conta o que a pessoa disse: a interação presumida por haver dois remédios não substitui o "posso tomar?".
        $concrete = array_values(array_intersect($topics, $this->topics($question), self::CONCRETE_TOPICS));
        $ordered = $concrete !== [] && in_array(self::PERSONAL_USE_TOPIC, $topics, true)
            ? [...array_values(array_diff($topics, [self::PERSONAL_USE_TOPIC])), 'nao_use_se']
            : $topics;
        $sections = [];
        foreach ($ambiguous ? (array) config('bulai.questions.general_sections') : $ordered as $topic) {
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
