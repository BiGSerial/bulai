<?php

namespace App\Services\Audit;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Interactions\InteractionLookup;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Consultas de leitura para analisar o sistema inteiro pela API, sem tinker na VPS (auditoria de 16/11/2026): catálogo da
 * ANVISA com os vínculos, princípio ativo com aliases e cobertura no DDInter, par no DDInter, bulas humanizadas com
 * legibilidade, trechos de um documento e o registro completo de um `evaluation:run`. Nada aqui identifica pessoas.
 */
final class SystemInspectionService
{
    public function __construct(
        private readonly AnvisaCatalogNormalizer $normalizer,
        private readonly InteractionLookup $lookup,
        private readonly ReadabilityService $readability,
    ) {}

    /**
     * Registros do catálogo cujo nome do produto ou princípio ativo contém o termo, com os princípios ativos ligados.
     *
     * @return array{query: string, total: int, medicines: list<array<string, mixed>>}
     */
    public function catalog(string $term, int $limit): array
    {
        $needle = '%'.$this->normalizer->text($term).'%';
        $query = DB::table('anvisa_medicines')->where(fn ($q) => $q->where('normalized_product_name', 'like', $needle)->orWhere('normalized_active_ingredient', 'like', $needle));
        $rows = (clone $query)->orderBy('id')->limit($limit)->get(['id', 'product_name', 'active_ingredient', 'registration_number', 'registration_status']);
        $links = DB::table('anvisa_medicine_ingredients as l')->join('active_ingredients as i', 'i.id', '=', 'l.ingredient_id')
            ->whereIn('l.medicine_id', $rows->pluck('id'))->get(['l.medicine_id', 'i.id', 'i.canonical_name'])->groupBy('medicine_id');

        return [
            'query' => $term,
            'total' => $query->count(),
            'medicines' => $rows->map(fn (object $row): array => (array) $row + [
                'ingredients' => ($links[$row->id] ?? collect())->map(fn (object $link): array => ['id' => (int) $link->id, 'name' => $link->canonical_name])->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Princípios ativos pelo nome, nome normalizado ou alias: aliases, cobertura no DDInter, bulas e medicamentos ligados.
     *
     * @return list<array<string, mixed>>
     */
    public function ingredients(string $name, int $limit): array
    {
        $normalized = $this->normalizer->text($name);
        $ids = DB::table('active_ingredients')->where('normalized_name', 'like', '%'.$normalized.'%')->limit($limit)->pluck('id')
            ->merge(DB::table('active_ingredient_aliases')->where('normalized_alias', 'like', '%'.$normalized.'%')->limit($limit)->pluck('ingredient_id'))
            ->unique()->take($limit)->all();

        return ActiveIngredient::query()->with('aliases')->whereIn('id', $ids)->orderBy('canonical_name')->get()->map(fn (ActiveIngredient $ingredient): array => [
            'id' => $ingredient->id,
            'canonical_name' => $ingredient->canonical_name,
            'normalized_name' => $ingredient->normalized_name,
            'aliases' => $ingredient->aliases->map(fn ($alias): array => ['alias' => $alias->alias, 'language' => $alias->language, 'origin' => $alias->origin, 'review_status' => $alias->review_status])->values()->all(),
            'ddinter' => [
                'in_source' => $this->lookup->absentFromSource([$ingredient->id]) === [],
                'interactions_by_severity' => DB::table('drug_interactions')->where(fn ($q) => $q->where('drug_a_ingredient_id', $ingredient->id)->orWhere('drug_b_ingredient_id', $ingredient->id))
                    ->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity')->map(fn ($total): int => (int) $total)->all(),
            ],
            'leaflets' => DB::table('knowledge_document_ingredients as di')->join('knowledge_documents as d', 'd.id', '=', 'di.document_id')
                ->where('di.ingredient_id', $ingredient->id)->orderBy('d.id')->get(['d.id', 'd.title', 'd.document_type', 'd.content_status', 'di.method'])->map(fn (object $row): array => (array) $row)->all(),
            'catalog_medicines' => DB::table('anvisa_medicine_ingredients')->where('ingredient_id', $ingredient->id)->count(),
        ])->all();
    }

    /**
     * O que o DDInter registra entre dois princípios ativos (ids) e se cada um consta da base.
     *
     * @return array<string, mixed>
     */
    public function interaction(int $a, int $b): array
    {
        $names = ActiveIngredient::query()->whereIn('id', [$a, $b])->pluck('canonical_name', 'id');
        $structured = $this->lookup->between([$a], [$b]);
        $absent = $this->lookup->absentFromSource([$a, $b]);

        return [
            'a' => ['id' => $a, 'name' => $names[$a] ?? null, 'in_source' => ! in_array($a, $absent, true)],
            'b' => ['id' => $b, 'name' => $names[$b] ?? null, 'in_source' => ! in_array($b, $absent, true)],
            'verdict' => $structured !== null ? 'registered' : ($absent !== [] ? 'not_verifiable_absent_from_source' : 'not_registered'),
            'interaction' => $structured?->toArray(),
        ];
    }

    /**
     * Bulas humanizadas geradas, com as frases de cada seção, as fontes e o índice de Flesch (Martins et al., 1996) das frases
     * e dos trechos de origem. O índice mede facilidade de leitura, não compreensão.
     *
     * @return array{total: int, summaries: list<array<string, mixed>>}
     */
    public function leafletSummaries(int $limit): array
    {
        $summaries = MedicineLeafletSummary::query()->latest('id')->limit($limit)->get();

        return [
            'total' => MedicineLeafletSummary::query()->count(),
            'summaries' => $summaries->map(function (MedicineLeafletSummary $summary): array {
                $sections = collect($summary->sections ?? [])->map(function ($section, $key): array {
                    $claims = collect((array) ($section['claims'] ?? []))->map(fn ($claim): string => is_array($claim) ? (string) ($claim['text'] ?? '') : (string) $claim)->filter()->values()->all();

                    return ['key' => is_string($key) ? $key : ($section['key'] ?? null), 'label' => $section['label'] ?? null, 'claims' => $claims, 'readability' => $claims === [] ? null : $this->readability->flesch(implode(' ', $claims))];
                })->values()->all();
                $excerpts = collect($summary->evidences ?? [])->map(fn ($evidence): array => [
                    'document' => $evidence['document_title'] ?? null, 'section' => $evidence['section'] ?? null, 'page' => $evidence['page_start'] ?? null,
                    'excerpt' => mb_substr((string) ($evidence['excerpt'] ?? ''), 0, 700),
                ])->values()->all();
                $text = implode(' ', array_merge(...array_map(fn (array $section): array => $section['claims'], $sections ?: [['claims' => []]])));

                return [
                    'id' => $summary->id, 'uuid' => $summary->uuid, 'ingredient_key' => $summary->ingredient_key, 'prompt_version' => $summary->prompt_version,
                    'status' => $summary->status, 'created_at' => $summary->created_at?->toIso8601String(), 'views' => $summary->views,
                    'generation' => $summary->generation,
                    'readability' => [
                        'summary' => $text === '' ? null : $this->readability->flesch($text),
                        'sources' => $excerpts === [] ? null : $this->readability->flesch(implode(' ', array_column($excerpts, 'excerpt'))),
                    ],
                    'sections' => $sections,
                    'sources' => $excerpts,
                ];
            })->all(),
        ];
    }

    /**
     * Trechos de um documento: seção, páginas, tamanho e embedding; `full` inclui o texto inteiro, senão os primeiros 300 caracteres.
     *
     * @return array<string, mixed>
     */
    public function documentChunks(KnowledgeDocument $document, bool $full): array
    {
        $chunks = DB::table('knowledge_chunks as c')->where('c.document_id', $document->id)->orderBy('c.chunk_index')
            ->get(['c.id', 'c.chunk_index', 'c.section', 'c.page_start', 'c.page_end', 'c.content', 'c.token_count', 'c.embedding_model', DB::raw('c.embedding is not null as embedded')]);

        return [
            'document' => ['id' => $document->id, 'title' => $document->title, 'type' => $document->document_type, 'status' => $document->content_status],
            'chunks' => $chunks->map(fn (object $chunk): array => [
                'id' => (int) $chunk->id, 'index' => (int) $chunk->chunk_index, 'section' => $chunk->section,
                'pages' => [$chunk->page_start, $chunk->page_end], 'characters' => mb_strlen((string) $chunk->content),
                'token_count' => $chunk->token_count, 'embedded' => (bool) $chunk->embedded, 'embedding_model' => $chunk->embedding_model,
                'content' => $full ? $chunk->content : mb_substr((string) $chunk->content, 0, 300),
            ])->all(),
        ];
    }

    /**
     * Registro completo de um `evaluation:run`, inclusive as listas por item (o detalhe por consulta da recuperação), pelo
     * `run_id` ou pelo seu prefixo (8+ caracteres).
     *
     * @return array<string, mixed>|null
     */
    public function evaluationRun(string $runId): ?array
    {
        $directory = storage_path('app/evaluation/runs');
        if (! File::isDirectory($directory)) {
            return null;
        }
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $run = json_decode((string) File::get($file->getPathname()), true);
            if (is_array($run) && str_starts_with((string) ($run['run_id'] ?? ''), $runId)) {
                return ['file' => $file->getFilename()] + $run;
            }
        }

        return null;
    }
}
