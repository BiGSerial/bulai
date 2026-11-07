<?php

namespace App\Services\Retrieval;

use App\Data\Ai\EmbeddingRequest;
use App\Data\Knowledge\EvidenceReference;
use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recuperação em PostgreSQL: lexical (FTS `portuguese`, ts_rank_cd), vetorial (pgvector, distância cosseno) e híbrida
 * (Reciprocal Rank Fusion). Só participam chunks de documentos READY e de fontes ativas.
 */
final class PostgresKnowledgeRetriever implements KnowledgeRetriever
{
    /** Constante k do RRF (Cormack et al., 2009). */
    public const RRF_K = 60;

    private const CANDIDATE_MULTIPLIER = 4;

    public function __construct(private readonly AiProviderManager $providers) {}

    public function retrieve(RetrievalQuery $query): RetrievalResult
    {
        $startedAt = hrtime(true);
        $configuration = ['mode' => $query->mode, 'limit' => $query->limit];
        $candidates = $query->limit * self::CANDIDATE_MULTIPLIER;

        $lexical = $query->mode === RetrievalQuery::VECTOR ? [] : $this->lexical($query, $candidates);
        $vector = [];
        if ($query->mode !== RetrievalQuery::LEXICAL) {
            [$vector, $embeddingInfo] = $this->vector($query, $candidates);
            $configuration += $embeddingInfo;
        }

        $ranked = match ($query->mode) {
            RetrievalQuery::LEXICAL => $this->tag($lexical, RetrievalQuery::LEXICAL),
            RetrievalQuery::VECTOR => $this->tag($vector, RetrievalQuery::VECTOR),
            default => $this->fuse($lexical, $vector, $configuration),
        };
        $ranked = array_slice($ranked, 0, $query->limit);

        return new RetrievalResult($this->hydrate($ranked), $query->mode, (int) ((hrtime(true) - $startedAt) / 1_000_000), $configuration);
    }

    private function base(RetrievalQuery $query): Builder
    {
        $builder = DB::table('knowledge_chunks as c')
            ->join('knowledge_documents as d', 'd.id', '=', 'c.document_id')
            ->join('knowledge_sources as s', 's.id', '=', 'd.source_id')
            ->where('d.content_status', KnowledgeDocument::STATUS_READY)
            ->where('s.active', true);

        if ($query->documentIds !== null) {
            $builder->whereIn('c.document_id', $query->documentIds === [] ? [0] : $query->documentIds);
        }
        if ($query->language !== null) {
            $builder->where('d.language', $query->language);
        }
        if ($query->sectionPatterns !== null && $query->sectionPatterns !== []) {
            // Padrões que começam com "!" excluem seções ("indica" casaria com "CONTRAINDICAÇÕES"; "!contra" tira essas).
            $normalized = "translate(lower(c.section), 'áàâãäéèêëíìîïóòôõöúùûüç', 'aaaaaeeeeiiiiooooouuuuc')";
            $positive = array_values(array_filter($query->sectionPatterns, fn (string $pattern): bool => ! str_starts_with($pattern, '!')));
            $negative = array_values(array_filter($query->sectionPatterns, fn (string $pattern): bool => str_starts_with($pattern, '!')));
            $builder->where(function (Builder $where) use ($positive, $normalized): void {
                foreach ($positive as $pattern) {
                    $where->orWhereRaw("{$normalized} like ?", ['%'.Str::lower(Str::ascii($pattern)).'%']);
                }
            });
            foreach ($negative as $pattern) {
                $builder->whereRaw("{$normalized} not like ?", ['%'.Str::lower(Str::ascii(substr($pattern, 1))).'%']);
            }
        }

        return $builder;
    }

    /** @return list<array{id: int, score: float}> */
    private function lexical(RetrievalQuery $query, int $limit): array
    {
        $tsQuery = $this->tsQuery($query->text);
        if ($tsQuery === null) {
            return [];
        }

        return $this->base($query)
            ->whereRaw("c.search_vector @@ to_tsquery('portuguese', ?)", [$tsQuery])
            ->selectRaw("c.id, ts_rank_cd(c.search_vector, to_tsquery('portuguese', ?)) as score", [$tsQuery])
            ->orderByDesc('score')->orderBy('c.id')
            ->limit($limit)->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'score' => (float) $row->score])->all();
    }

    /** @return array{0: list<array{id: int, score: float}>, 1: array<string, mixed>} */
    private function vector(RetrievalQuery $query, int $limit): array
    {
        try {
            $response = $this->providers->embedding()->embed(new EmbeddingRequest($query->text));
        } catch (AiProviderException $exception) {
            return [[], ['embedding_error' => $exception->errorCode]];
        }
        $info = ['embedding_model' => $response->model, 'embedding_dimensions' => $response->dimensions()];
        $literal = '['.implode(',', array_map(fn (float $value): string => (string) $value, $response->vector)).']';

        $rows = $this->base($query)
            ->where('c.embedding_model', $response->model)
            ->where('c.embedding_dimensions', $response->dimensions())
            ->whereNotNull('c.embedding')
            ->selectRaw('c.id, 1 - (c.embedding <=> ?::vector) as score', [$literal])
            ->orderByRaw('c.embedding <=> ?::vector', [$literal])->orderBy('c.id')
            ->limit($limit)->get();

        return [$rows->map(fn (object $row): array => ['id' => (int) $row->id, 'score' => (float) $row->score])->all(), $info];
    }

    /**
     * @param  list<array{id: int, score: float}>  $lexical
     * @param  list<array{id: int, score: float}>  $vector
     * @param  array<string, mixed>  $configuration
     * @return list<array{id: int, score: float, method: string}>
     */
    private function fuse(array $lexical, array $vector, array &$configuration): array
    {
        $configuration['rrf_k'] = self::RRF_K;
        $scores = [];
        $methods = [];
        foreach (['lexical' => $lexical, 'vector' => $vector] as $name => $list) {
            foreach ($list as $position => $item) {
                $scores[$item['id']] = ($scores[$item['id']] ?? 0.0) + 1 / (self::RRF_K + $position + 1);
                $methods[$item['id']][] = $name;
            }
        }
        arsort($scores);

        $fused = [];
        foreach ($scores as $id => $score) {
            $fused[] = ['id' => $id, 'score' => $score, 'method' => implode('+', $methods[$id])];
        }

        return $fused;
    }

    /**
     * @param  list<array{id: int, score: float}>  $list
     * @return list<array{id: int, score: float, method: string}>
     */
    private function tag(array $list, string $method): array
    {
        return array_map(fn (array $item): array => $item + ['method' => $method], $list);
    }

    /**
     * @param  list<array{id: int, score: float, method: string}>  $ranked
     * @return list<EvidenceReference>
     */
    private function hydrate(array $ranked): array
    {
        if ($ranked === []) {
            return [];
        }
        $rows = DB::table('knowledge_chunks as c')
            ->join('knowledge_documents as d', 'd.id', '=', 'c.document_id')
            ->leftJoin('knowledge_sources as s', 's.id', '=', 'd.source_id')
            ->whereIn('c.id', array_column($ranked, 'id'))
            ->get(['c.id', 'c.document_id', 'c.section', 'c.page_start', 'c.page_end', 'c.content', 'd.title', 'd.document_type', 'd.content_hash', 'd.processed_at', 's.name as source_name'])
            ->keyBy('id');

        $references = [];
        foreach ($ranked as $item) {
            $row = $rows[$item['id']] ?? null;
            if ($row === null) {
                continue;
            }
            $references[] = new EvidenceReference(
                (int) $row->id, (int) $row->document_id, (string) $row->title, (string) $row->document_type, $row->source_name,
                $row->section, $row->page_start, $row->page_end, (string) $row->content, $item['score'], $item['method'],
                $row->content_hash !== null ? substr((string) $row->content_hash, 0, 16) : ($row->processed_at !== null ? (string) $row->processed_at : null),
            );
        }

        return $references;
    }

    /** Termos alfanuméricos unidos por OR; nunca interpola texto livre como operador do tsquery. */
    private function tsQuery(string $text): ?string
    {
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text)) ?: [],
            fn (string $term): bool => mb_strlen($term) >= 3,
        )));

        return $terms === [] ? null : implode(' | ', array_map(fn (string $term): string => "'".str_replace("'", '', $term)."'", $terms));
    }
}
