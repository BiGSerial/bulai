<?php

namespace App\Services\Interactions;

use App\Data\Knowledge\EvidenceReference;
use App\Data\Knowledge\RetrievalQuery;
use App\Models\Knowledge\ActiveIngredient;
use App\Services\Retrieval\KnowledgeRetriever;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recupera, para um par de princípios ativos, trechos das bulas de cada fármaco que mencionam o outro.
 * Um trecho só é aceito se citar de fato um nome do outro fármaco (filtro lexical pós-recuperação).
 */
final class PairEvidenceRetriever
{
    public function __construct(private readonly KnowledgeRetriever $retriever) {}

    /**
     * @param  list<int>  $ingredientsA
     * @param  list<int>  $ingredientsB
     * @return array{references: list<EvidenceReference>, meta: array<string, mixed>}
     */
    public function forPair(array $ingredientsA, array $ingredientsB, ?string $mode = null): array
    {
        $mode ??= (string) config('bulai.retrieval.mode');
        $limit = (int) config('bulai.retrieval.evidence_per_pair');
        $found = [];
        $meta = ['mode' => $mode, 'duration_ms' => 0, 'directions' => []];

        $combinationDocuments = $this->documentsLinkedToBoth($ingredientsA, $ingredientsB);

        foreach ([[$ingredientsA, $ingredientsB], [$ingredientsB, $ingredientsA]] as [$owners, $mentioned]) {
            $documentIds = array_values(array_diff(
                DB::table('knowledge_document_ingredients')->whereIn('ingredient_id', $owners)->pluck('document_id')->all(),
                $combinationDocuments,
            ));
            $names = $this->names($mentioned);
            if ($documentIds === [] || $names === []) {
                $meta['directions'][] = ['documents' => count($documentIds), 'candidates' => 0, 'accepted' => 0];

                continue;
            }

            $candidates = 0;
            $accepted = 0;
            foreach ([config('bulai.retrieval.sections'), null] as $sections) {
                $result = $this->retriever->retrieve(new RetrievalQuery(implode(' ', $names), $mode, $limit * 2, $documentIds, $sections));
                $meta['duration_ms'] += $result->durationMs;
                $meta['configuration'] = $result->configuration;
                $candidates += count($result->references);
                foreach ($result->references as $reference) {
                    if ($this->mentions($reference->excerpt, $names) && ! isset($found[$reference->chunkId])) {
                        $found[$reference->chunkId] = $reference;
                        $accepted++;
                    }
                }
                if ($accepted > 0) {
                    break;
                }
            }
            $meta['directions'][] = ['documents' => count($documentIds), 'candidates' => $candidates, 'accepted' => $accepted];
        }

        $references = array_values($found);
        usort($references, fn (EvidenceReference $a, EvidenceReference $b): int => $b->score <=> $a->score);

        return ['references' => array_slice($references, 0, $limit), 'meta' => $meta];
    }

    /**
     * Bulas de produtos que contêm os dois princípios ativos (associações): a menção ao outro fármaco é composição, não interação.
     *
     * @param  list<int>  $ingredientsA
     * @param  list<int>  $ingredientsB
     * @return list<int>
     */
    private function documentsLinkedToBoth(array $ingredientsA, array $ingredientsB): array
    {
        $linked = fn (array $ids): array => DB::table('knowledge_document_ingredients')->whereIn('ingredient_id', $ids)->pluck('document_id')->all();

        return array_values(array_intersect($linked($ingredientsA), $linked($ingredientsB)));
    }

    /**
     * Nomes (EN canônico + aliases PT/curados) usados como termos de busca e como filtro de menção.
     *
     * @param  list<int>  $ingredientIds
     * @return list<string>
     */
    public function names(array $ingredientIds): array
    {
        $names = ActiveIngredient::query()->whereIn('id', $ingredientIds)->pluck('normalized_name')->all();
        $aliases = DB::table('active_ingredient_aliases')->whereIn('ingredient_id', $ingredientIds)
            ->whereIn('origin', ['curated', 'rule'])->pluck('normalized_alias')->all();

        return array_values(array_unique(array_filter([...$names, ...$aliases], fn (string $name): bool => strlen($name) >= 4 && ! str_contains($name, '('))));
    }

    /** @param list<string> $names */
    private function mentions(string $text, array $names): bool
    {
        $haystack = ' '.preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))).' ';
        foreach ($names as $name) {
            if (str_contains($haystack, ' '.preg_replace('/[^a-z0-9]+/', ' ', $name).' ')) {
                return true;
            }
        }

        return false;
    }
}
