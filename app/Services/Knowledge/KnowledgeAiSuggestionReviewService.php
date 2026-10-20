<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Decisão humana sobre a sugestão da IA local: aceitar campos escolhidos, rejeitar tudo ou desfazer.
 * Só os valores gravados pela própria análise podem ser aplicados (o administrador escolhe campos, nunca digita valores),
 * e toda decisão entra na trilha de auditoria do item.
 */
final class KnowledgeAiSuggestionReviewService
{
    public const FIELDS = ['product_name', 'active_ingredient', 'document_type'];

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public function __construct(private readonly KnowledgeIngestionAuditService $audit) {}

    /**
     * @param  list<string>  $fields  Campos a aplicar: "campo" (sugestão da IA) ou "ai:campo" / "catalog:campo" (catálogo ANVISA).
     * @return array{status: string, applied: list<string>}
     */
    public function accept(int $batchId, int $itemId, array $fields, User $actor): array
    {
        return DB::transaction(function () use ($batchId, $itemId, $fields, $actor): array {
            $item = $this->lockPending($batchId, $itemId);
            $values = $item === null ? [] : ['ai' => $this->suggestion($item), 'catalog' => $this->catalogValues($item)];
            $chosen = [];
            foreach ($fields as $selection) {
                [$source, $field] = str_contains($selection, ':') ? explode(':', $selection, 2) : ['ai', $selection];
                if (in_array($field, self::FIELDS, true) && isset($values[$source][$field]) && ! isset($chosen[$field])) {
                    $chosen[$field] = $source;
                }
            }
            if ($item === null || $chosen === []) {
                return ['status' => $item === null ? 'ALREADY_RESOLVED' : 'NOTHING_SELECTED', 'applied' => []];
            }
            $fields = array_keys($chosen);

            $metadata = $item->metadata ?? [];
            $identification = $metadata['preliminary_identification'] ?? [];
            $current = is_array($identification['fields'] ?? null) ? $identification['fields'] : [];
            $previous = $applied = [];
            foreach ($fields as $field) {
                $previous[$field] = $current[$field] ?? null;
                $applied[$field] = $values[$chosen[$field]][$field];
                $current[$field] = $applied[$field];
            }
            if (isset($applied['document_type'])) {
                $current['audience'] = $applied['document_type'] === 'bula_profissional' ? 'professional' : 'patient';
            }
            if (filled($current['product_name'] ?? null) && filled($current['document_type'] ?? null)) {
                $previous['title'] = $identification['fields']['title'] ?? null;
                $current['title'] = $current['product_name'].' — '.($current['document_type'] === 'bula_paciente' ? 'Bula do paciente' : 'Bula do profissional de saúde');
            }
            $identification['fields'] = $current;
            $metadata['preliminary_identification'] = $identification;
            foreach (['title', 'document_type', 'active_ingredient', 'product_name'] as $key) {
                if (array_key_exists($key, $current)) {
                    $metadata[$key] = $current[$key];
                }
            }
            $metadata['ai_review'] = ['status' => self::ACCEPTED, 'fields' => $fields, 'sources' => $chosen, 'previous' => $previous, 'applied' => $applied,
                'decided_at' => now()->toISOString(), 'actor_id' => $actor->id, 'actor_name' => $actor->name];
            $item->forceFill(['metadata' => $metadata])->save();
            $this->audit->append($item, 'ai_suggestion_accepted', $actor, ['fields' => $fields, 'sources' => $chosen, 'previous' => $previous, 'applied' => $applied]);

            return ['status' => self::ACCEPTED, 'applied' => $fields];
        });
    }

    /** @return array{status: string, applied: list<string>} */
    public function reject(int $batchId, int $itemId, User $actor): array
    {
        return DB::transaction(function () use ($batchId, $itemId, $actor): array {
            $item = $this->lockPending($batchId, $itemId);
            if ($item === null) {
                return ['status' => 'ALREADY_RESOLVED', 'applied' => []];
            }
            $metadata = $item->metadata ?? [];
            $metadata['ai_review'] = ['status' => self::REJECTED, 'decided_at' => now()->toISOString(), 'actor_id' => $actor->id, 'actor_name' => $actor->name];
            $item->forceFill(['metadata' => $metadata])->save();
            $this->audit->append($item, 'ai_suggestion_rejected', $actor);

            return ['status' => self::REJECTED, 'applied' => []];
        });
    }

    /** Desfaz a decisão: restaura os valores anteriores (se foram aceitos) e volta ao estado "sem decisão". */
    public function revert(int $batchId, int $itemId, User $actor): bool
    {
        return DB::transaction(function () use ($batchId, $itemId, $actor): bool {
            $item = $this->lockPending($batchId, $itemId);
            $review = $item === null ? null : ($item->metadata['ai_review'] ?? null);
            if (! is_array($review)) {
                return false;
            }
            $metadata = $item->metadata;
            if (($review['status'] ?? null) === self::ACCEPTED) {
                foreach ((array) ($review['previous'] ?? []) as $field => $value) {
                    $metadata['preliminary_identification']['fields'][$field] = $value;
                    if (in_array($field, ['title', 'document_type', 'active_ingredient', 'product_name'], true)) {
                        $metadata[$field] = $value;
                    }
                }
                if (array_key_exists('document_type', (array) $review['previous'])) {
                    $type = $review['previous']['document_type'];
                    $metadata['preliminary_identification']['fields']['audience'] = $type === null ? null : ($type === 'bula_profissional' ? 'professional' : 'patient');
                }
            }
            unset($metadata['ai_review']);
            $item->forceFill(['metadata' => $metadata])->save();
            $this->audit->append($item, 'ai_decision_reverted', $actor, ['was' => $review['status'] ?? null]);

            return true;
        });
    }

    /** @return array<string, string> Valores sugeridos pela última consulta à IA, por campo. */
    public function suggestion(IngestionBatchItem $item): array
    {
        $result = data_get($item->metadata, 'preliminary_identification.classification.result');

        return is_array($result) ? array_filter(array_intersect_key($result, array_flip(self::FIELDS)), fn (mixed $value): bool => is_string($value) && $value !== '') : [];
    }

    /** @return array<string, string> Valores do catálogo ANVISA (achados pelo registro) que podem ser aplicados, por campo. */
    public function catalogValues(IngestionBatchItem $item): array
    {
        $catalog = data_get($item->metadata, 'preliminary_identification.regulatory_validation.catalog');

        return is_array($catalog) ? array_filter(array_intersect_key($catalog, array_flip(['product_name', 'active_ingredient'])), fn (mixed $value): bool => is_string($value) && $value !== '') : [];
    }

    private function lockPending(int $batchId, int $itemId): ?IngestionBatchItem
    {
        $item = IngestionBatchItem::query()->where('ingestion_batch_id', $batchId)->whereKey($itemId)->lockForUpdate()->firstOrFail();

        return $item->status === IngestionBatchItem::STATUS_PENDING ? $item : null;
    }
}
