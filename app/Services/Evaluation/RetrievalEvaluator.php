<?php

namespace App\Services\Evaluation;

use App\Data\Knowledge\RetrievalQuery;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Interactions\PairEvidenceRetriever;
use App\Services\Retrieval\KnowledgeRetriever;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Avalia a recuperação contra julgamentos de relevância (chunk "external_key#índice"). Dois formatos de dataset: o de interações
 * (trechos das bulas de `owner` que tratam de `other`, marcados à mão) e o por assunto (`subjects`), que se monta no banco
 * consultado e serve para medir qualquer ambiente. Os modos são comparados com o MESMO conjunto de documentos candidatos e sem o
 * filtro de menção, que beneficiaria o modo lexical.
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
        $queries = isset($dataset['subjects']) ? $this->subjectQueries($dataset) : $this->interactionQueries($dataset['items']);
        $report = ['items' => count($queries), 'modes' => []];
        if (isset($dataset['subjects'])) {
            $report['ingredients'] = ['listed' => count($dataset['items']), 'with_leaflet' => count(array_unique(array_column($queries, 'ingredient')))];
            $report['by_subject'] = array_count_values(array_column($queries, 'subject'));
        }

        foreach ($modes as $mode) {
            $perItem = [];
            $skipped = null;
            foreach ($queries as $query) {
                $result = $this->retriever->retrieve(new RetrievalQuery($query['text'], $mode, max(self::KS), $query['documents']));
                if (isset($result->configuration['embedding_error'])) {
                    $skipped = $result->configuration['embedding_error'];
                }
                $ranked = $this->keys(array_map(fn ($reference): int => $reference->chunkId, $result->references));
                $perItem[] = ['ranked' => $ranked, 'relevant' => $query['relevant']];
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
            foreach (self::KS as $k) {
                $hits = count(array_filter($perItem, fn (array $i): bool => array_intersect(array_slice($i['ranked'], 0, $k), $i['relevant']) !== []));
                $metrics["hit@$k"] = Metrics::wilson($hits, count($perItem));
            }
            $report['modes'][$mode] = ['status' => 'executed'] + $metrics;
        }

        return $report;
    }

    /**
     * Julgamento original: trechos das bulas de `owner` que tratam da interação com `other`, marcados à mão (external_key#índice).
     *
     * @param  list<array{owner: string, other: string, relevant: list<string>}>  $items
     * @return list<array{text: string, documents: list<int>, relevant: list<string>}>
     */
    private function interactionQueries(array $items): array
    {
        return array_map(function (array $item): array {
            $owner = ActiveIngredient::query()->where('normalized_name', $item['owner'])->value('id');
            $other = ActiveIngredient::query()->where('normalized_name', $item['other'])->value('id');

            return [
                'text' => implode(' ', $other === null ? [$item['other']] : $this->pairs->names([$other])),
                'documents' => $owner === null ? [] : DB::table('knowledge_document_ingredients')->where('ingredient_id', $owner)->pluck('document_id')->all(),
                'relevant' => $item['relevant'],
            ];
        }, $items);
    }

    /**
     * Julgamento por assunto, portável entre bancos: para cada princípio ativo com bula pronta e cada assunto, a consulta é a
     * pergunta do paciente e os relevantes são os trechos das bulas daquele princípio ativo cuja seção trata do assunto (padrão
     * sobre o nome da seção, sem acento). Não depende de id nem da menção de palavras, então não favorece o modo lexical.
     * Princípio ativo ausente ou sem trecho na seção do assunto fica fora (não há o que recuperar).
     *
     * @param  array{subjects: array<string, array{question: string, sections: string}>, items: list<array{ingredient: string, name: string}>}  $dataset
     * @return list<array{ingredient: string, subject: string, text: string, documents: list<int>, relevant: list<string>}>
     */
    private function subjectQueries(array $dataset): array
    {
        $queries = [];
        foreach ($dataset['items'] as $item) {
            $ingredient = ActiveIngredient::query()->whereRaw('lower(canonical_name) = ?', [mb_strtolower($item['ingredient'])])->value('id');
            $documents = $ingredient === null ? [] : DB::table('knowledge_document_ingredients as di')->join('knowledge_documents as d', 'd.id', '=', 'di.document_id')
                ->where('di.ingredient_id', $ingredient)->where('d.content_status', KnowledgeDocument::STATUS_READY)->pluck('d.id')->map(fn ($id): int => (int) $id)->all();
            if ($documents === []) {
                continue;
            }
            $chunks = DB::table('knowledge_chunks')->whereIn('document_id', $documents)->get(['id', 'section']);
            foreach ($dataset['subjects'] as $subject => $definition) {
                $relevant = $chunks->filter(fn (object $chunk): bool => preg_match('/'.$definition['sections'].'/', Str::upper(Str::ascii((string) $chunk->section))) === 1)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                if ($relevant === []) {
                    continue;
                }
                $queries[] = ['ingredient' => $item['ingredient'], 'subject' => $subject, 'text' => str_replace('{name}', $item['name'], $definition['question']), 'documents' => $documents, 'relevant' => $this->keys($relevant)];
            }
        }

        return $queries;
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
