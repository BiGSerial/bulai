<?php

namespace App\Services\Patient;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Interactions\FuzzyMedicineMatcher;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use App\Services\Knowledge\IngredientNameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detecta medicamentos citados em texto livre (nome comercial ou princípio ativo, PT/EN) contra o catálogo ANVISA.
 *
 * Determinístico: n-gramas de 1–3 palavras do texto normalizado (mais a junção de palavras vizinhas, para o ditado que
 * parte "ome prazol"), uma consulta agregada ao catálogo (igualdade ou prefixo de palavra, um grupo por nome × princípio
 * ativo, com um produto representativo) e uma aos aliases de princípios ativos. Registros equivalentes são agrupados por
 * conjunto de princípios ativos ligados ou, quando o catálogo ainda não tem vínculo, pelos componentes do ativo sem sais
 * ("losartana potassica" = "losartana"); um produto representativo é devolvido por grupo, na ordem em que aparecem no texto.
 *
 * Termo que casa com vários grupos: se existe produto com exatamente esse nome, vale ele; se todos os grupos partilham
 * o princípio ativo que o termo nomeia ("losartana" → losartana e losartana + hidroclorotiazida), vale o grupo só com
 * esse ativo; senão, mais de três grupos distintos ("acido") é ambíguo e o termo é descartado. "meus remédios" acrescenta
 * os medicamentos em uso. Quando nada é reconhecido, `guesses()` sugere nomes parecidos para a pessoa escolher.
 */
final class MedicationMentionDetector
{
    public const MAX_MENTIONS = 4;

    private const MAX_CANDIDATES = 40;

    private const MAX_GROUPS_PER_TERM = 3;

    private const MAX_CATALOG_GROUPS = 800;

    private const MIN_TERM_LENGTH = 4;

    /** Nome já conferido pela interpretação: a sigla de 2 ou 3 letras ("AAS") vale. */
    private const MIN_VERIFIED_NAME_LENGTH = 2;

    private const TREATMENT_PATTERN = '/\b(meus? (remedios?|medicamentos?|comprimidos?|tratamento|medicacao)|o que (eu )?(tomo|uso)|que eu (tomo|uso)|minha medicacao|meu tratamento|tudo que tomo)\b/';

    /** Palavras frequentes em perguntas (já normalizadas) que nunca são nomes de medicamento. */
    private const STOPWORDS = [
        'para', 'como', 'tomar', 'tomo', 'toma', 'tomei', 'tomando', 'tomado', 'posso', 'pode', 'podem', 'poderia', 'devo', 'deve', 'junto', 'juntos', 'juntas',
        'meus', 'minha', 'minhas', 'remedio', 'remedios', 'medicamento', 'medicamentos', 'medicacao', 'tratamento', 'tenho', 'estou', 'usar', 'usando',
        'dose', 'doses', 'dosagem', 'comprimido', 'comprimidos', 'capsula', 'capsulas', 'gotas', 'xarope', 'manha', 'noite', 'tarde', 'jejum', 'antes',
        'depois', 'durante', 'gravidez', 'gravida', 'amamentando', 'amamentacao', 'alcool', 'cerveja', 'vinho', 'cafe', 'leite', 'comida', 'alimento',
        'alimentos', 'febre', 'cabeca', 'pressao', 'alta', 'baixa', 'efeito', 'efeitos', 'colateral', 'colaterais', 'quais', 'qual', 'quando', 'quanto',
        'quantos', 'quantas', 'onde', 'porque', 'sobre', 'entre', 'esse', 'essa', 'este', 'esta', 'isso', 'mais', 'menos', 'muito', 'pouco', 'todo',
        'todos', 'toda', 'todas', 'dias', 'horas', 'vezes', 'mesmo', 'tempo', 'sera', 'seria', 'faz', 'fazer', 'causa', 'causar', 'sono', 'tontura',
        'enjoo', 'nausea', 'estomago', 'vazio', 'cheio', 'crianca', 'criancas', 'idoso', 'idosos', 'bebe', 'anos', 'meses', 'semana', 'semanas',
        'interage', 'interacao', 'interacoes', 'perigoso', 'seguro', 'risco', 'riscos', 'serve', 'contra', 'indicado', 'bula', 'bulas', 'nome',
        'problema', 'problemas', 'coracao', 'figado', 'rins', 'diabetes', 'diabetico', 'hipertensao', 'ansiedade', 'depressao', 'infeccao', 'cirurgia',
        'exame', 'exames', 'medico', 'medica', 'farmaceutico', 'receita', 'receitou', 'passou', 'esqueci', 'esquecer', 'atrasar', 'parar', 'engordar',
        'emagrecer', 'dirigir', 'trabalhar', 'dormir', 'acordar', 'ajuda', 'obrigado', 'obrigada', 'sinto', 'sentindo', 'comecei', 'quero', 'preciso',
        // Corpo, sintomas, hábitos e formas farmacêuticas: parecem nomes de marca para a tolerância a erros ("muscular" × Musculare).
        'muscular', 'musculares', 'dores', 'garganta', 'barriga', 'costas', 'perna', 'pernas', 'braco', 'bracos', 'joelho', 'ouvido', 'olhos', 'nariz', 'boca',
        'dente', 'dentes', 'pele', 'sangue', 'urina', 'intestino', 'pulmao', 'gripe', 'resfriado', 'tosse', 'alergia', 'alergico', 'alergica', 'vomito',
        'diarreia', 'azia', 'queimacao', 'inchaco', 'coceira', 'mancha', 'manchas', 'cansaco', 'fraqueza', 'formigamento', 'insonia', 'calor', 'frio', 'suor',
        'peso', 'apetite', 'fome', 'sede', 'agua', 'suco', 'acucar', 'gordura', 'exercicio', 'caminhada', 'academia', 'viagem', 'praia', 'vacina', 'gestante',
        'lactante', 'marido', 'esposa', 'filho', 'filha', 'senhor', 'senhora', 'doutor', 'doutora', 'hospital', 'farmacia', 'posto', 'consulta', 'retorno',
        'urgente', 'emergencia', 'normal', 'melhor', 'pior', 'forte', 'fraco', 'grande', 'pequeno', 'rapido', 'devagar', 'sempre', 'nunca', 'agora', 'hoje',
        'ontem', 'amanha', 'sozinho', 'sozinha', 'generico', 'similar', 'referencia', 'original', 'caixa', 'frasco', 'ampola', 'injecao', 'pomada', 'creme',
        'spray', 'adesivo', 'supositorio', 'colirio', 'beber', 'comer', 'bastante', 'nada', 'coisa', 'assim', 'ainda', 'tambem', 'outro', 'outra', 'outros',
    ];

    /** Semelhança mínima para aceitar uma palavra digitada errado como nome de remédio ("lozartana" → Losartana). */
    private const FUZZY_MIN_SIMILARITY = 0.5;

    /** Semelhança mínima para apenas SUGERIR um nome ("você quis dizer…?"), quando nada foi reconhecido. */
    private const GUESS_MIN_SIMILARITY = 0.3;

    private const FUZZY_MIN_TERM_LENGTH = 5;

    private const FUZZY_MAX_TERMS = 6;

    public function __construct(
        private readonly AnvisaCatalogNormalizer $normalizer,
        private readonly FuzzyMedicineMatcher $fuzzy,
        private readonly IngredientNameNormalizer $ingredients,
    ) {}

    /**
     * @param  list<int>  $forcedMedicineIds  medicamentos escolhidos explicitamente pelo usuário (entram primeiro)
     * @param  bool  $verifiedNames  o texto é só a lista de nomes que a interpretação apontou e conferiu na pergunta: então uma
     *                               sigla curta ("AAS") também é procurada, porque ali ela não pode ser uma palavra comum
     * @return list<array{id: int, name: string, ingredients: string, identified: bool, matched_text: string, fuzzy: bool, corrected: string|null}>
     */
    public function detect(string $text, ?User $user = null, array $forcedMedicineIds = [], bool $verifiedNames = false): array
    {
        $mentions = [];
        foreach (AnvisaMedicine::query()->with('ingredients:id,normalized_name')->whereIn('id', $forcedMedicineIds)->get()->sortBy(fn (AnvisaMedicine $m): int => array_search($m->id, $forcedMedicineIds, true) ?: 0) as $medicine) {
            $mentions[$this->groupKey($medicine)] ??= $this->mention($medicine, 'escolhido');
        }

        $normalized = $this->normalizer->text($text);
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        $candidates = $this->candidates($tokens, $verifiedNames ? self::MIN_VERIFIED_NAME_LENGTH : self::MIN_TERM_LENGTH);
        $covered = [];

        if ($candidates !== []) {
            $rows = $this->catalogMatches($candidates)->merge($this->ingredientMatches($candidates));
            foreach ($this->groupByTerm($rows, $candidates) as $position => $groups) {
                $covered = [...$covered, ...range($candidates[$position]['start'], $candidates[$position]['start'] + $candidates[$position]['size'] - 1)];
                foreach ($groups as $group) {
                    $key = $this->groupKey($group['medicine']);
                    if (! isset($mentions[$key]) && count($mentions) < self::MAX_MENTIONS) {
                        $mentions[$key] = $this->mention($group['medicine'], $candidates[$position]['text']);
                    }
                }
            }
        }

        // Palavras que não casaram com nada: tolerância a erro de digitação, uma por vez e só com correspondência clara.
        $attempts = 0;
        foreach ($tokens as $position => $token) {
            if (in_array($position, $covered, true) || mb_strlen($token) < self::FUZZY_MIN_TERM_LENGTH || ctype_digit($token[0]) || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            if ($attempts++ >= self::FUZZY_MAX_TERMS || count($mentions) >= self::MAX_MENTIONS) {
                break;
            }
            $best = $this->fuzzy->best($token, self::FUZZY_MIN_SIMILARITY);
            if ($best === null) {
                continue;
            }
            $key = $this->groupKey($best['medicine']);
            $mentions[$key] ??= $this->mention($best['medicine'], $token, $best['matched']);
        }

        if ($user !== null && preg_match(self::TREATMENT_PATTERN, ' '.$normalized.' ') === 1 && $user->hasHealthConsent()) {
            $inUse = PatientMedication::query()->with('medicine.ingredients:id')->where('user_id', $user->id)->orderBy('id')->get()
                ->filter(fn (PatientMedication $item): bool => $item->isActive() && $item->medicine !== null);
            foreach ($inUse as $item) {
                $key = $this->groupKey($item->medicine);
                if (! isset($mentions[$key]) && count($mentions) < self::MAX_MENTIONS) {
                    $mentions[$key] = $this->mention($item->medicine, 'tratamento');
                }
            }
        }

        return $this->collapseSameName(array_values($mentions));
    }

    /**
     * O mesmo remédio não aparece duas vezes: registros do catálogo com o mesmo nome comercial (ex.: três "DIPIRONA" com
     * textos de princípio ativo diferentes) viram uma só menção, a primeira (a escolhida ou a mais bem posicionada).
     *
     * @param  list<array{id: int, name: string, ingredients: string, identified: bool, matched_text: string, fuzzy: bool, corrected: string|null}>  $mentions
     * @return list<array{id: int, name: string, ingredients: string, identified: bool, matched_text: string, fuzzy: bool, corrected: string|null}>
     */
    private function collapseSameName(array $mentions): array
    {
        $seen = [];
        $unique = [];
        foreach ($mentions as $mention) {
            $name = $this->normalizer->text($mention['name']);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $unique[] = $mention;
        }

        return $unique;
    }

    /**
     * Nomes parecidos com as palavras da pergunta, para a tela perguntar "você quis dizer…?" quando nada foi reconhecido.
     * Mais tolerante que a correção automática (que exige semelhança alta e correspondência inequívoca).
     *
     * @return list<array{id: int, name: string, ingredients: string, matched: string, term: string}>
     */
    public function guesses(string $text, int $limit = 3): array
    {
        $guesses = [];
        $attempts = 0;
        foreach (explode(' ', $this->normalizer->text($text)) as $token) {
            if (mb_strlen($token) < self::MIN_TERM_LENGTH || ctype_digit($token[0]) || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            if ($attempts++ >= self::FUZZY_MAX_TERMS) {
                break;
            }
            foreach ($this->fuzzy->match($token, 2, self::GUESS_MIN_SIMILARITY) as $match) {
                $key = $this->groupKey($match['medicine']);
                $guesses[$key] ??= ['id' => $match['medicine']->id, 'name' => $match['medicine']->product_name, 'ingredients' => (string) ($match['medicine']->active_ingredient ?: ''), 'matched' => $match['matched'], 'term' => $token];
                if (count($guesses) >= $limit) {
                    return array_values($guesses);
                }
            }
        }

        return array_values($guesses);
    }

    /**
     * N-gramas de 1 a 3 palavras cujas pontas são termos úteis (≥ 4 letras, não numéricos, fora das stopwords), em ordem de
     * aparição e com os maiores primeiro em cada posição; depois, a junção de cada par de palavras vizinhas ("ome prazol",
     * "dor flex"), para o ditado por voz que parte um nome ao meio.
     *
     * @param  list<string>  $tokens
     * @return list<array{text: string, start: int, size: int}>
     */
    private function candidates(array $tokens, int $minimumLength = self::MIN_TERM_LENGTH): array
    {
        $useful = fn (string $token): bool => mb_strlen($token) >= $minimumLength && ! ctype_digit($token[0]) && ! in_array($token, self::STOPWORDS, true);
        $candidates = [];
        foreach ($tokens as $start => $token) {
            if (! $useful($token)) {
                continue;
            }
            for ($size = 3; $size >= 1; $size--) {
                $slice = array_slice($tokens, $start, $size);
                if (count($slice) < $size || ! $useful($slice[$size - 1])) {
                    // "naprix d", "naprix a", "losartana hct": sufixo curto de linha de produto é testado como nome exato.
                    if ($size === 2 && count($slice) === 2 && $this->isProductSuffix($slice[1])) {
                        $candidates[] = ['text' => implode(' ', $slice), 'start' => $start, 'size' => 2];
                    }

                    continue;
                }
                $candidates[] = ['text' => implode(' ', $slice), 'start' => $start, 'size' => $size];
            }
        }
        $joinable = fn (string $token): bool => $token !== '' && ! ctype_digit($token[0]) && ! in_array($token, self::STOPWORDS, true);
        for ($start = 0; $start < count($tokens) - 1; $start++) {
            if ($joinable($tokens[$start]) && $joinable($tokens[$start + 1]) && mb_strlen($tokens[$start].$tokens[$start + 1]) >= self::FUZZY_MIN_TERM_LENGTH) {
                $candidates[] = ['text' => $tokens[$start].$tokens[$start + 1], 'start' => $start, 'size' => 2];
            }
        }

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * Nome-base que o catálogo escreve depois do sal ("etexilato de dabigatrana", "cloridrato de pseudoefedrina"): quem pergunta
     * escreve só "dabigatrana". Exige 6+ letras para que "sodio" ou "ferro" não virem "cloreto de sódio" numa pergunta qualquer.
     */
    private function isSaltBase(string $term): bool
    {
        return ! str_contains($term, ' ') && mb_strlen($term) >= 6;
    }

    /** Sufixo de linha de produto: 1 a 3 letras/dígitos ("d", "a", "hct", "xr", "50") que não seja palavra comum. */
    private function isProductSuffix(string $token): bool
    {
        // Só vale como nome exato do catálogo ("naprix a" existe; "losartana a" não), então a lista de exclusão é mínima.
        return $token !== '' && mb_strlen($token) <= 3 && ctype_alnum($token) && ! in_array($token, ['de', 'da', 'do', 'em', 'com', 'ou'], true);
    }

    /**
     * Uma consulta agregada ao catálogo: nome comercial ou princípio ativo igual ao termo, ou começando pelo termo seguido
     * de espaço. Devolve um produto representativo por nome × princípio ativo (preferindo um registro já ligado a
     * princípios ativos) com o tamanho do grupo, para que centenas de "dipirona" não expulsem a "losartana" da resposta.
     *
     * @param  list<array{text: string, start: int, size: int}>  $candidates
     * @return Collection<int, AnvisaMedicine> com o atributo transitório `group_members`
     */
    private function catalogMatches(array $candidates): Collection
    {
        $terms = array_values(array_unique(array_column($candidates, 'text')));

        $groups = DB::table('anvisa_medicines as m')
            ->leftJoin('anvisa_medicine_ingredients as l', 'l.medicine_id', '=', 'm.id')
            ->selectRaw('coalesce(min(m.id) filter (where l.id is not null), min(m.id)) as id, count(distinct m.id) as members, min(case when m.registration_status = ? then 0 else 1 end) as inactive', ['Ativo'])
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($terms): void {
                $query->whereIn('m.normalized_product_name', $terms)->orWhereIn('m.normalized_active_ingredient', $terms);
                foreach ($terms as $term) {
                    $query->orWhere('m.normalized_product_name', 'like', $term.' %')->orWhere('m.normalized_active_ingredient', 'like', $term.' %');
                    if ($this->isSaltBase($term)) {
                        $query->orWhere('m.normalized_active_ingredient', 'like', '% de '.$term)->orWhere('m.normalized_active_ingredient', 'like', '% de '.$term.' %');
                    }
                }
            })
            ->groupBy('m.normalized_product_name', 'm.normalized_active_ingredient')
            ->orderBy('inactive')->orderByDesc('members')->orderBy('id')
            ->limit(self::MAX_CATALOG_GROUPS)
            ->get();
        if ($groups->isEmpty()) {
            return new Collection;
        }
        $members = $groups->mapWithKeys(fn (object $row): array => [(int) $row->id => (int) $row->members]);

        return AnvisaMedicine::query()->with('ingredients:id,normalized_name')->whereIn('id', $members->keys()->all())->get()
            ->each(fn (AnvisaMedicine $medicine) => $medicine->setAttribute('group_members', $members[$medicine->id] ?? 1));
    }

    /**
     * Termos que são nomes (ou aliases, inclusive em inglês) de princípios ativos: devolve um produto representativo por ingrediente.
     *
     * @param  list<array{text: string, start: int, size: int}>  $candidates
     * @return Collection<int, AnvisaMedicine>
     */
    private function ingredientMatches(array $candidates): Collection
    {
        $terms = array_values(array_unique(array_column($candidates, 'text')));
        $salts = array_values(array_filter($terms, fn (string $term): bool => $this->isSaltBase($term)));
        $ingredientIds = DB::table('active_ingredients')->where(function (\Illuminate\Database\Query\Builder $query) use ($terms, $salts): void {
            $query->whereIn('normalized_name', $terms);
            foreach ($salts as $term) {
                $query->orWhere('normalized_name', 'like', '% de '.$term);
            }
        })->pluck('id')
            ->merge(DB::table('active_ingredient_aliases')->whereIn('normalized_alias', $terms)->pluck('ingredient_id'))->unique()->values()->all();
        if ($ingredientIds === []) {
            return new Collection;
        }

        return AnvisaMedicine::query()->with('ingredients:id,normalized_name')
            ->whereHas('ingredients', fn (Builder $query) => $query->whereIn('active_ingredients.id', $ingredientIds))
            ->orderByRaw("case when registration_status = 'Ativo' then 0 else 1 end")->orderByRaw('case when normalized_product_name = normalized_active_ingredient then 0 else 1 end')->orderBy('id')
            ->limit(200)->get();
    }

    /**
     * Associa cada produto ao primeiro candidato (posição no texto) que ele satisfaz, agrupa por princípios ativos, aplica a
     * regra de foco do termo (nome exato > ativo partilhado > até três grupos > ambíguo) e escolhe um representativo por
     * grupo. Termos contidos num n-grama maior já satisfeito são ignorados.
     *
     * @param  Collection<int, AnvisaMedicine>  $rows
     * @param  list<array{text: string, start: int, size: int}>  $candidates
     * @return array<int, list<array{medicine: AnvisaMedicine}>> índice do candidato => grupos, em ordem de aparição
     */
    private function groupByTerm(Collection $rows, array $candidates): array
    {
        $byTerm = [];
        foreach ($rows->unique('id') as $medicine) {
            foreach ($candidates as $position => $candidate) {
                if ($this->satisfies($medicine, $candidate['text'])) {
                    $byTerm[$position][$this->groupKey($medicine)][] = $medicine;

                    break;
                }
            }
        }

        $result = [];
        $covered = [];
        ksort($byTerm);
        foreach ($byTerm as $position => $groups) {
            $candidate = $candidates[$position];
            $span = range($candidate['start'], $candidate['start'] + $candidate['size'] - 1);
            if (array_intersect($span, $covered) !== []) {
                continue;
            }
            $chosen = $this->focus($groups, $candidate['text']);
            if ($chosen === []) {
                continue;
            }
            $covered = [...$covered, ...$span];
            $result[$position] = array_map(fn (array $members): array => ['medicine' => $this->representative($members, $candidate['text'])], $chosen);
        }

        return $result;
    }

    /**
     * Regra de foco de um termo que casou com vários grupos. Grupos ordenados do maior (mais registros no catálogo) para o menor.
     *
     * @param  array<string, list<AnvisaMedicine>>  $groups
     * @return list<list<AnvisaMedicine>> grupos escolhidos; vazio quando o termo é ambíguo
     */
    private function focus(array $groups, string $term): array
    {
        $groups = $this->mergeUnlinked($groups);
        uasort($groups, fn (array $a, array $b): int => $this->members($b) <=> $this->members($a));
        $exact = array_filter($groups, fn (array $members): bool => collect($members)->contains(fn (AnvisaMedicine $m): bool => $m->normalized_product_name === $term));
        if ($exact !== []) {
            return array_values($exact);
        }
        if (count($groups) === 1) {
            return array_values($groups);
        }

        $linkedSingle = $this->linkedSingleIngredientGroups($groups);
        if ($linkedSingle !== []) {
            return array_slice($linkedSingle, 0, self::MAX_GROUPS_PER_TERM);
        }

        // Todos os grupos têm em comum o princípio ativo que o termo nomeia ("losartana" → losartana; losartana + hidroclorotiazida;
        // "anlodipino" → besilato de anlodipino):
        // a pessoa quis o remédio só com esse ativo, se existir; senão, os maiores grupos.
        $shared = null;
        foreach ($groups as $members) {
            $names = $this->componentNames($members);
            $shared = $shared === null ? $names : array_values(array_intersect($shared, $names));
        }
        $focus = array_values(array_filter($shared ?? [], fn (string $name): bool => $name === $term || str_starts_with($name, $term.' ') || ($this->isSaltBase($term) && str_ends_with($name, ' de '.$term))));
        if ($focus !== []) {
            $single = array_filter($groups, fn (array $members): bool => $this->componentNames($members) === [$focus[0]]);

            return array_slice(array_values($single !== [] ? $single : $groups), 0, self::MAX_GROUPS_PER_TERM);
        }

        return count($groups) > self::MAX_GROUPS_PER_TERM ? [] : array_values($groups);
    }

    /**
     * Foco pelos vínculos, antes do foco pelo texto: se todos os grupos já ligados partilham um princípio ativo e existe um
     * grupo ligado só a ele, é esse o remédio. O texto do catálogo tem grafias incoerentes para o mesmo fármaco ("BESILATO DE
     * AMLODIPINA, BESILATO DE ANLODIPINO" num registro; "ANLODIPINO 5 MG" com ativo "AMLODIPINA" noutro): pelo texto, o grupo
     * do anlodipino parecia uma associação e o termo ficava ambíguo ou caía num registro inativo e sem vínculo. Produção, 12/11/2026.
     *
     * @param  array<string, list<AnvisaMedicine>>  $groups
     * @return list<list<AnvisaMedicine>>
     */
    private function linkedSingleIngredientGroups(array $groups): array
    {
        $idsByKey = [];
        foreach (array_keys($groups) as $key) {
            if (str_starts_with($key, 'i:')) {
                $idsByKey[$key] = explode('-', substr($key, 2));
            }
        }
        if ($idsByKey === []) {
            return [];
        }
        $shared = array_values(array_intersect(...array_values($idsByKey)));
        if ($shared === []) {
            return [];
        }

        return array_values(array_filter($groups, fn (string $key): bool => isset($idsByKey[$key]) && count($idsByKey[$key]) === 1 && in_array($idsByKey[$key][0], $shared, true), ARRAY_FILTER_USE_KEY));
    }

    /**
     * Catálogo com vínculo parcial: registros ainda sem princípio ativo ligado ("a:dipirona") entram no grupo já ligado com
     * os mesmos componentes ("i:12"), para o mesmo remédio não aparecer duas vezes.
     *
     * @param  array<string, list<AnvisaMedicine>>  $groups
     * @return array<string, list<AnvisaMedicine>>
     */
    private function mergeUnlinked(array $groups): array
    {
        $linkedByNames = [];
        foreach ($groups as $key => $members) {
            if (str_starts_with($key, 'i:')) {
                $linkedByNames[implode('+', $this->componentNames($members))] ??= $key;
            }
        }
        foreach ($groups as $key => $members) {
            $target = str_starts_with($key, 'a:') ? ($linkedByNames[substr($key, 2)] ?? null) : null;
            if ($target !== null) {
                $groups[$target] = [...$groups[$target], ...$members];
                unset($groups[$key]);
            }
        }

        return $groups;
    }

    /** @param list<AnvisaMedicine> $members */
    private function members(array $members): int
    {
        return array_sum(array_map(fn (AnvisaMedicine $m): int => (int) ($m->getAttribute('group_members') ?? 1), $members));
    }

    /**
     * Componentes do princípio ativo do grupo sem sais e hidratos, a partir do texto do catálogo (vale com ou sem vínculo).
     *
     * @param  list<AnvisaMedicine>  $members
     * @return list<string>
     */
    private function componentNames(array $members): array
    {
        $names = [];
        foreach ($members as $medicine) {
            foreach ($this->ingredients->components($medicine->normalized_active_ingredient ?: $medicine->normalized_product_name) as $component) {
                $base = $this->ingredients->base($component);
                if ($base !== '') {
                    $names[$base] = $base;
                }
            }
        }
        $names = array_values($names);
        sort($names);

        return $names;
    }

    private function satisfies(AnvisaMedicine $medicine, string $term): bool
    {
        foreach ([(string) $medicine->normalized_product_name, (string) $medicine->normalized_active_ingredient] as $field) {
            if ($field === $term || str_starts_with($field, $term.' ')) {
                return true;
            }
        }
        $salt = $this->isSaltBase($term);
        if ($salt && (str_ends_with((string) $medicine->normalized_active_ingredient, ' de '.$term) || str_contains((string) $medicine->normalized_active_ingredient, ' de '.$term.' '))) {
            return true;
        }
        foreach ($medicine->ingredients as $ingredient) {
            if ($ingredient->normalized_name === $term || ($salt && str_ends_with((string) $ingredient->normalized_name, ' de '.$term))) {
                return true;
            }
        }

        return $this->aliasTerms($medicine)->contains($term);
    }

    /** @return Collection<int, string> */
    private function aliasTerms(AnvisaMedicine $medicine): Collection
    {
        static $cache = [];
        $ids = $medicine->ingredients->pluck('id')->all();
        $key = implode('-', $ids);
        if ($ids === []) {
            return new Collection;
        }

        return $cache[$key] ??= DB::table('active_ingredient_aliases')->whereIn('ingredient_id', $ids)->pluck('normalized_alias');
    }

    /**
     * @param  list<AnvisaMedicine>  $members
     */
    private function representative(array $members, string $term): AnvisaMedicine
    {
        // Um registro já ligado a princípios ativos representa melhor o grupo (interações, tratamento e bula funcionam por ele).
        return collect($members)->sortBy(fn (AnvisaMedicine $m): array => [
            $m->ingredients->isNotEmpty() ? 0 : 1,
            $m->normalized_product_name === $term ? 0 : 1,
            $m->normalized_product_name === $m->normalized_active_ingredient ? 0 : 1,
            $m->registration_status === 'Ativo' ? 0 : 1,
            $m->id,
        ])->first();
    }

    /**
     * Grupo de equivalência: princípios ativos ligados; sem vínculo, os componentes do ativo sem sais ("losartana potassica"
     * e "losartana potássica 50 mg" caem no mesmo grupo); sem ativo informado, o próprio nome do produto.
     */
    private function groupKey(AnvisaMedicine $medicine): string
    {
        $ids = $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        if ($ids !== []) {
            return 'i:'.implode('-', $ids);
        }
        if (filled($medicine->normalized_active_ingredient)) {
            return 'a:'.implode('+', $this->componentNames([$medicine]));
        }

        return 'p:'.$medicine->normalized_product_name;
    }

    /**
     * @param  string|null  $corrected  palavra do catálogo que se pareceu com o que foi digitado (só na tolerância a erros)
     * @return array{id: int, name: string, ingredients: string, identified: bool, matched_text: string, fuzzy: bool, corrected: string|null}
     */
    private function mention(AnvisaMedicine $medicine, string $matchedText, ?string $corrected = null): array
    {
        return [
            'id' => $medicine->id,
            'name' => $medicine->product_name,
            'ingredients' => (string) ($medicine->active_ingredient ?: 'Princípio ativo não informado no catálogo'),
            'identified' => $medicine->ingredients->isNotEmpty(),
            'matched_text' => $matchedText,
            'fuzzy' => $corrected !== null,
            'corrected' => $corrected,
        ];
    }
}
