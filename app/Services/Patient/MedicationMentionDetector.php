<?php

namespace App\Services\Patient;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Interactions\FuzzyMedicineMatcher;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detecta medicamentos citados em texto livre (nome comercial ou princípio ativo, PT/EN) contra o catálogo ANVISA.
 *
 * Determinístico: n-gramas de 1–3 palavras do texto normalizado, uma consulta ao catálogo (igualdade ou prefixo de
 * palavra) e uma aos aliases de princípios ativos; registros equivalentes são agrupados por conjunto de princípios ativos
 * e um produto representativo é devolvido por grupo, na ordem em que aparecem no texto. Termos que casam com muitos
 * grupos distintos (ex.: "acido") são descartados como ambíguos. "meus remédios" acrescenta os medicamentos em uso.
 */
final class MedicationMentionDetector
{
    public const MAX_MENTIONS = 4;

    private const MAX_CANDIDATES = 30;

    private const MAX_GROUPS_PER_TERM = 3;

    private const MIN_TERM_LENGTH = 4;

    private const TREATMENT_PATTERN = '/\b(meus? (remedios?|medicamentos?|comprimidos?|tratamento|medicacao)|o que (eu )?(tomo|uso)|que eu (tomo|uso)|minha medicacao|meu tratamento|tudo que tomo)\b/';

    /** Palavras frequentes em perguntas (já normalizadas) que nunca são nomes de medicamento. */
    private const STOPWORDS = [
        'para', 'como', 'tomar', 'tomo', 'toma', 'tomei', 'tomando', 'posso', 'pode', 'podem', 'poderia', 'devo', 'deve', 'junto', 'juntos', 'juntas',
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
        'spray', 'adesivo', 'supositorio', 'colirio', 'beber', 'comer', 'bastante',
    ];

    /** Semelhança mínima para aceitar uma palavra digitada errado como nome de remédio ("lozartana" → Losartana). */
    private const FUZZY_MIN_SIMILARITY = 0.5;

    private const FUZZY_MIN_TERM_LENGTH = 5;

    private const FUZZY_MAX_TERMS = 6;

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer, private readonly FuzzyMedicineMatcher $fuzzy) {}

    /**
     * @param  list<int>  $forcedMedicineIds  medicamentos escolhidos explicitamente pelo usuário (entram primeiro)
     * @return list<array{id: int, name: string, ingredients: string, identified: bool, matched_text: string, fuzzy: bool, corrected: string|null}>
     */
    public function detect(string $text, ?User $user = null, array $forcedMedicineIds = []): array
    {
        $mentions = [];
        foreach (AnvisaMedicine::query()->with('ingredients:id')->whereIn('id', $forcedMedicineIds)->get()->sortBy(fn (AnvisaMedicine $m): int => array_search($m->id, $forcedMedicineIds, true) ?: 0) as $medicine) {
            $mentions[$this->groupKey($medicine)] ??= $this->mention($medicine, 'escolhido');
        }

        $normalized = $this->normalizer->text($text);
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        $candidates = $this->candidates($tokens);
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

        return array_values($mentions);
    }

    /**
     * N-gramas de 1 a 3 palavras cujas pontas são termos úteis (≥ 4 letras, não numéricos, fora das stopwords), em ordem de
     * aparição e com os maiores primeiro em cada posição.
     *
     * @param  list<string>  $tokens
     * @return list<array{text: string, start: int, size: int}>
     */
    private function candidates(array $tokens): array
    {
        $useful = fn (string $token): bool => mb_strlen($token) >= self::MIN_TERM_LENGTH && ! ctype_digit($token[0]) && ! in_array($token, self::STOPWORDS, true);
        $candidates = [];
        foreach ($tokens as $start => $token) {
            if (! $useful($token)) {
                continue;
            }
            for ($size = 3; $size >= 1; $size--) {
                $slice = array_slice($tokens, $start, $size);
                if (count($slice) < $size || ! $useful($slice[$size - 1])) {
                    continue;
                }
                $candidates[] = ['text' => implode(' ', $slice), 'start' => $start, 'size' => $size];
            }
        }

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * Uma consulta ao catálogo: nome comercial ou princípio ativo igual ao termo, ou começando pelo termo seguido de espaço.
     *
     * @param  list<array{text: string, start: int, size: int}>  $candidates
     * @return Collection<int, AnvisaMedicine>
     */
    private function catalogMatches(array $candidates): Collection
    {
        $terms = array_values(array_unique(array_column($candidates, 'text')));

        return AnvisaMedicine::query()->with('ingredients:id')
            ->where(function (Builder $query) use ($terms): void {
                $query->whereIn('normalized_product_name', $terms)->orWhereIn('normalized_active_ingredient', $terms);
                foreach ($terms as $term) {
                    $query->orWhere('normalized_product_name', 'like', $term.' %')->orWhere('normalized_active_ingredient', 'like', $term.' %');
                }
            })
            ->orderByRaw("case when registration_status = 'Ativo' then 0 else 1 end")->orderBy('normalized_product_name')->orderBy('id')
            ->limit(600)->get();
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
        $ingredientIds = DB::table('active_ingredients')->whereIn('normalized_name', $terms)->pluck('id')
            ->merge(DB::table('active_ingredient_aliases')->whereIn('normalized_alias', $terms)->pluck('ingredient_id'))->unique()->values()->all();
        if ($ingredientIds === []) {
            return new Collection;
        }

        return AnvisaMedicine::query()->with('ingredients:id')
            ->whereHas('ingredients', fn (Builder $query) => $query->whereIn('active_ingredients.id', $ingredientIds))
            ->orderByRaw("case when registration_status = 'Ativo' then 0 else 1 end")->orderByRaw('case when normalized_product_name = normalized_active_ingredient then 0 else 1 end')->orderBy('id')
            ->limit(200)->get();
    }

    /**
     * Associa cada produto ao primeiro candidato (posição no texto) que ele satisfaz, agrupa por conjunto de princípios
     * ativos, escolhe um representativo e descarta termos ambíguos (muitos grupos distintos). Termos contidos num n-grama
     * maior já satisfeito são ignorados.
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
            $exact = array_filter($groups, fn (array $members): bool => collect($members)->contains(fn (AnvisaMedicine $m): bool => $m->normalized_product_name === $candidate['text']));
            if (count($groups) > self::MAX_GROUPS_PER_TERM && $exact === []) {
                continue;
            }
            $chosen = $exact !== [] ? $exact : $groups;
            $covered = [...$covered, ...$span];
            $result[$position] = array_map(fn (array $members): array => ['medicine' => $this->representative($members, $candidate['text'])], array_values($chosen));
        }

        return $result;
    }

    private function satisfies(AnvisaMedicine $medicine, string $term): bool
    {
        foreach ([(string) $medicine->normalized_product_name, (string) $medicine->normalized_active_ingredient] as $field) {
            if ($field === $term || str_starts_with($field, $term.' ')) {
                return true;
            }
        }
        foreach ($medicine->ingredients as $ingredient) {
            if ($ingredient->normalized_name === $term) {
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
        return collect($members)->sortBy(fn (AnvisaMedicine $m): array => [
            $m->normalized_product_name === $term ? 0 : 1,
            $m->normalized_product_name === $m->normalized_active_ingredient ? 0 : 1,
            $m->registration_status === 'Ativo' ? 0 : 1,
            $m->ingredients->isNotEmpty() ? 0 : 1,
            $m->id,
        ])->first();
    }

    private function groupKey(AnvisaMedicine $medicine): string
    {
        $ids = $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return $ids !== [] ? 'i:'.implode('-', $ids) : 'p:'.$medicine->normalized_product_name.'|'.$medicine->normalized_active_ingredient;
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
