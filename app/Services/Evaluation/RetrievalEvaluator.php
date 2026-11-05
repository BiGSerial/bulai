<?php

namespace App\Services\Evaluation;

use App\Data\Knowledge\RetrievalQuery;
use App\Models\Knowledge\ActiveIngredient;
use App\Services\Interactions\PairEvidenceRetriever;
use App\Services\Retrieval\KnowledgeRetriever;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Avalia a recuperação contra julgamentos de relevância (chunk "external_key#índice"). Cada item é uma consulta direcional:
 * trechos das bulas do fármaco `owner` que tratam da interação com `other`. Os modos são comparados com o MESMO conjunto de
 * documentos candidatos e sem o filtro de menção, que beneficiaria o modo lexical.
 */
final class RetrievalEvaluator
{
    public const KS = [1, 3, 5];

    public function __construct(private readonly KnowledgeRetriever $retriever, private readonly PairEvidenceRetriever $pairs) {}

    /**
     * @param  list<string>  $modes
     * @return array<string, mixed>
     */
    public function run(string $judgmentsPath, array $modes): array
    {
        $dataset = json_decode((string) File::get($judgmentsPath), true, flags: JSON_THROW_ON_ERROR);
        $report = ['items' => count($dataset['items']), 'modes' => []];

        foreach ($modes as $mode) {
            $perItem = [];
            $skipped = null;
            foreach ($dataset['items'] as $item) {
                $owner = ActiveIngredient::query()->where('normalized_name', $item['owner'])->value('id');
                $other = ActiveIngredient::query()->where('normalized_name', $item['other'])->value('id');
                $documentIds = $owner === null ? [] : DB::table('knowledge_document_ingredients')->where('ingredient_id', $owner)->pluck('document_id')->all();
                $text = implode(' ', $other === null ? [$item['other']] : $this->pairs->names([$other]));

                $result = $this->retriever->retrieve(new RetrievalQuery($text, $mode, max(self::KS), $documentIds));
                if (isset($result->configuration['embedding_error'])) {
                    $skipped = $result->configuration['embedding_error'];
                }
                $ranked = $this->keys(array_map(fn ($reference): int => $reference->chunkId, $result->references));
                $perItem[] = ['ranked' => $ranked, 'relevant' => $item['relevant']];
            }

            if ($skipped !== null) {
                $report['modes'][$mode] = ['status' => 'not_executed', 'reason' => 'embedding_provider:'.$skipped];

                continue;
            }
            $metrics = [];
            foreach (self::KS as $k) {
                $metrics["precision@$k"] = Metrics::round(Metrics::mean(array_map(fn (array $i): float => Metrics::precisionAtK($i['ranked'], $i['relevant'], $k), $perItem)));
                $metrics["recall@$k"] = Metrics::round(Metrics::mean(array_map(fn (array $i): ?float => Metrics::recallAtK($i['ranked'], $i['relevant'], $k), $perItem)));
            }
            $metrics['mrr'] = Metrics::round(Metrics::mean(array_map(fn (array $i): float => Metrics::reciprocalRank($i['ranked'], $i['relevant']), $perItem)));
            $report['modes'][$mode] = ['status' => 'executed'] + $metrics;
        }

        return $report;
    }

    /**
     * @param  list<int>  $chunkIds
     * @return list<string>
     */
    private function keys(array $chunkIds): array
    {
        $rows = DB::table('knowledge_chunks as c')->join('knowledge_documents as d', 'd.id', '=', 'c.document_id')->whereIn('c.id', $chunkIds)->get(['c.id', 'd.external_key', 'c.chunk_index'])->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?string => isset($rows[$id]) ? $rows[$id]->external_key.'#'.$rows[$id]->chunk_index : null, $chunkIds)));
    }
}
