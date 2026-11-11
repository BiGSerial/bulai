<?php

namespace App\Services\Audit;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Evaluation\Metrics;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Os embeddings gravados estão corretos? (pgvector, PostgreSQL.) Verifica, sem chamar nenhum provedor:
 *  - cobertura (todo chunk tem vetor, de um único modelo e uma única dimensão, igual à declarada na linha);
 *  - integridade (vetor não nulo, sem duplicatas suspeitas, hash do texto igual ao hash com que foi indexado);
 *  - coerência semântica: o vizinho mais próximo de cada trecho amostrado costuma ser do mesmo documento, e trechos do mesmo
 *    documento são mais parecidos entre si do que trechos de documentos diferentes.
 * Com `live`, reembeda uma amostra pelo provedor e compara com o vetor gravado (cosseno ≈ 1 = índice fiel ao texto atual).
 */
final class EmbeddingAuditService
{
    public function __construct(private readonly AiProviderManager $providers) {}

    /** @return array<string, mixed> */
    public function audit(int $sample = 40, bool $live = false): array
    {
        $sample = max(5, min(200, $sample));
        $total = (int) DB::table('knowledge_chunks')->count();
        $embedded = (int) DB::table('knowledge_chunks')->whereNotNull('embedding')->count();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'coverage' => [
                'chunks' => $total,
                'embedded' => $embedded,
                'missing' => $total - $embedded,
                'rate' => $total > 0 ? round($embedded / $total, 4) : null,
                'by_model' => DB::table('knowledge_chunks')->whereNotNull('embedding')->selectRaw('embedding_model as model, embedding_dimensions as dimensions, count(*) as chunks')->groupBy('embedding_model', 'embedding_dimensions')->orderByDesc('chunks')->get()->map(fn (object $row): array => ['model' => $row->model, 'dimensions' => $row->dimensions === null ? null : (int) $row->dimensions, 'chunks' => (int) $row->chunks])->all(),
                'documents_by_embedding_status' => DB::table('knowledge_documents')->selectRaw('embedding_status as status, count(*) as total')->groupBy('embedding_status')->pluck('total', 'status')->map(fn ($n): int => (int) $n)->all(),
            ],
            'norms' => AuditSection::run(fn (): array => $this->norms()),
        ];

        $checks = AuditSection::run(fn (): array => $this->integrityChecks($total, $embedded));
        $report['checks'] = AuditSection::failed($checks) ? $checks : array_map(fn (AuditCheck $check): array => $check->toArray(), $checks);
        $report['summary'] = AuditSection::failed($checks) ? null : AuditCheck::summarize($checks);
        $report['coherence'] = $embedded > 1 ? AuditSection::run(fn (): array => $this->coherence($sample)) : null;
        $report['live'] = $live ? $this->live(min($sample, 12)) : null;

        return $report;
    }

    /** @return array{min: float|null, mean: float|null, max: float|null} */
    private function norms(): array
    {
        $row = DB::selectOne('select min(vector_norm(embedding)) as min, avg(vector_norm(embedding)) as mean, max(vector_norm(embedding)) as max from knowledge_chunks where embedding is not null');

        return ['min' => $row->min === null ? null : round((float) $row->min, 4), 'mean' => $row->mean === null ? null : round((float) $row->mean, 4), 'max' => $row->max === null ? null : round((float) $row->max, 4)];
    }

    /** @return list<AuditCheck> */
    private function integrityChecks(int $total, int $embedded): array
    {
        $ids = fn (string $where, array $bindings = []): array => array_map(fn (object $row): int => (int) $row->id, DB::select("select id from knowledge_chunks where {$where} order by id limit 5", $bindings));
        $count = fn (string $where): int => (int) DB::table('knowledge_chunks')->whereRaw($where)->count();

        $missing = 'embedding is null';
        $mixedModels = (int) DB::table('knowledge_chunks')->whereNotNull('embedding')->distinct()->count(DB::raw("coalesce(embedding_model, '')"));
        $dimensionMismatch = 'embedding is not null and (embedding_dimensions is null or embedding_dimensions <> vector_dims(embedding))';
        $staleHash = "embedding is not null and (embedding_content_hash is null or embedding_content_hash <> encode(sha256(convert_to(content, 'UTF8')), 'hex'))";
        $zeroNorm = 'embedding is not null and vector_norm(embedding) = 0';
        $noModel = 'embedding is not null and embedding_model is null';
        $duplicates = (int) (DB::selectOne(<<<'SQL'
            select count(*) as n from (
                select embedding from knowledge_chunks where embedding is not null
                group by embedding having count(distinct md5(lower(regexp_replace(content, '\s+', ' ', 'g')))) > 1
            ) as t
        SQL)->n ?? 0);
        $dimensions = (int) DB::table('knowledge_chunks')->whereNotNull('embedding')->distinct()->count(DB::raw('vector_dims(embedding)'));

        return [
            new AuditCheck('chunks_without_embedding', 'Trechos sem embedding', AuditCheck::ERROR, $count($missing), $total, $ids($missing)),
            new AuditCheck('single_model', 'Todos os vetores vêm de um único modelo (sem mistura)', AuditCheck::ERROR, max(0, $mixedModels - 1), $embedded),
            new AuditCheck('single_dimension', 'Todos os vetores têm a mesma dimensão', AuditCheck::ERROR, max(0, $dimensions - 1), $embedded),
            new AuditCheck('dimension_matches_declared', 'Dimensão gravada na linha = dimensão real do vetor', AuditCheck::ERROR, $count($dimensionMismatch), $embedded, $ids($dimensionMismatch)),
            new AuditCheck('model_recorded', 'Modelo de embedding registrado em cada vetor', AuditCheck::WARNING, $count($noModel), $embedded, $ids($noModel)),
            new AuditCheck('content_hash_current', 'Texto do trecho não mudou depois de indexado (hash igual)', AuditCheck::ERROR, $count($staleHash), $embedded, $ids($staleHash)),
            new AuditCheck('non_zero_vectors', 'Nenhum vetor nulo (norma zero)', AuditCheck::ERROR, $count($zeroNorm), $embedded, $ids($zeroNorm)),
            new AuditCheck('no_identical_vectors_for_different_text', 'Textos diferentes (além de espaços e quebras de linha) nunca compartilham o mesmo vetor', AuditCheck::WARNING, $duplicates, $embedded),
        ];
    }

    /**
     * @return array{sample: int, nearest_neighbor_same_document: array{rate: float|null, low: float|null, high: float|null, n: int}, nearest_similarity_mean: float|null, same_document_similarity: float|null, other_document_similarity: float|null, separation: float|null}
     */
    private function coherence(int $sample): array
    {
        $rows = DB::select(<<<'SQL'
            select s.id, s.document_id, n.document_id as neighbor_document_id, n.similarity
            from (select id, document_id, embedding from knowledge_chunks where embedding is not null order by hashtext(id::text) limit ?) as s
            cross join lateral (
                select c.document_id, 1 - (c.embedding <=> s.embedding) as similarity
                from knowledge_chunks c where c.id <> s.id and c.embedding is not null
                order by c.embedding <=> s.embedding limit 1
            ) as n
        SQL, [$sample]);
        $same = count(array_filter($rows, fn (object $row): bool => $row->document_id === $row->neighbor_document_id));

        $pairs = DB::selectOne(<<<'SQL'
            with s as (select id, document_id, embedding from knowledge_chunks where embedding is not null order by hashtext(id::text) limit ?)
            select
                avg(1 - (a.embedding <=> b.embedding)) filter (where a.document_id = b.document_id) as same_document,
                avg(1 - (a.embedding <=> b.embedding)) filter (where a.document_id <> b.document_id) as other_document
            from s a join s b on a.id < b.id
        SQL, [$sample]);
        $sameSimilarity = $pairs->same_document === null ? null : (float) $pairs->same_document;
        $otherSimilarity = $pairs->other_document === null ? null : (float) $pairs->other_document;

        return [
            'sample' => count($rows),
            'nearest_neighbor_same_document' => Metrics::wilson($same, count($rows)),
            'nearest_similarity_mean' => $rows === [] ? null : round(array_sum(array_map(fn (object $row): float => (float) $row->similarity, $rows)) / count($rows), 4),
            'same_document_similarity' => $sameSimilarity === null ? null : round($sameSimilarity, 4),
            'other_document_similarity' => $otherSimilarity === null ? null : round($otherSimilarity, 4),
            'separation' => $sameSimilarity !== null && $otherSimilarity !== null ? round($sameSimilarity - $otherSimilarity, 4) : null,
        ];
    }

    /**
     * Reembeda uma amostra pelo provedor configurado e compara com o vetor gravado.
     *
     * @return array<string, mixed>
     */
    private function live(int $sample): array
    {
        if (! $this->providers->isConfigured('embedding')) {
            return ['status' => 'unavailable', 'reason' => 'EMBEDDING_PROVIDER_NOT_CONFIGURED'];
        }
        $chunks = DB::table('knowledge_chunks')->whereNotNull('embedding')->orderByRaw('hashtext(id::text)')->limit($sample)->get(['id', 'content', 'embedding_model', DB::raw('embedding::text as vector')]);
        if ($chunks->isEmpty()) {
            return ['status' => 'unavailable', 'reason' => 'NO_EMBEDDED_CHUNKS'];
        }

        try {
            $started = microtime(true);
            $response = $this->providers->embedding()->embedMany(new EmbeddingBatchRequest($chunks->pluck('content')->map(fn ($text): string => (string) $text)->values()->all(), $chunks->first()->embedding_model));
        } catch (AiProviderException|Throwable $exception) {
            return ['status' => 'failed', 'reason' => $exception instanceof AiProviderException ? $exception->errorCode : 'EMBEDDING_CALL_FAILED'];
        }

        $cosines = [];
        foreach ($chunks->values() as $position => $chunk) {
            $fresh = $response->embeddings[$position]->vector ?? null;
            if ($fresh !== null) {
                $cosines[] = $this->cosine($this->parse((string) $chunk->vector), $fresh);
            }
        }
        sort($cosines);

        return [
            'status' => 'ok',
            'model' => $response->model,
            'compared' => count($cosines),
            'cosine_min' => $cosines === [] ? null : round($cosines[0], 6),
            'cosine_mean' => $cosines === [] ? null : round(array_sum($cosines) / count($cosines), 6),
            'faithful' => $cosines !== [] && $cosines[0] >= 0.99,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** @return list<float> */
    private function parse(string $vector): array
    {
        return array_map('floatval', explode(',', trim($vector, '[]')));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $value) {
            $other = (float) ($b[$i] ?? 0.0);
            $dot += $value * $other;
            $normA += $value ** 2;
            $normB += $other ** 2;
        }

        return $normA > 0 && $normB > 0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
