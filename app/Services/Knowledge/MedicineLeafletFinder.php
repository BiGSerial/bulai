<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Support\Facades\DB;

/**
 * Localiza as bulas cadastradas de um medicamento do catálogo ANVISA, na ordem de confiança:
 *
 * 1. vínculo pelos princípios ativos (`anvisa_medicine_ingredients` → `knowledge_document_ingredients`), que é o caminho
 *    normal; quando o medicamento ainda não tem princípio ativo ligado (catálogo importado sem `knowledge:build-ingredients`,
 *    ou registro novo), o vínculo é feito na hora a partir do texto do ativo, com os mesmos aliases do construtor do
 *    catálogo, e gravado para as próximas consultas (interações DDInter e contexto do tratamento passam a funcionar);
 * 2. o nome: bulas cujo produto (metadado ou título) ou princípio ativo declarado é o mesmo do medicamento, comparados
 *    sem sais e hidratos ("maleato de enalapril" = "enalapril"). Cobre catálogo sem ingredientes e bulas sem vínculo.
 *
 * Nunca escolhe uma bula "parecida": sem igualdade de nome ou de princípio ativo, devolve vazio e o Perguntar diz que não
 * tem a bula. A lista de bulas prontas é pequena (dezenas a centenas), por isso a comparação por nome roda em PHP.
 */
final class MedicineLeafletFinder
{
    public const BY_INGREDIENTS = 'ingredients';

    public const BY_NAME = 'name';

    /** @var list<array{id: int, product: string, components: list<string>}>|null bulas prontas desta requisição */
    private ?array $leaflets = null;

    public function __construct(
        private readonly ActiveIngredientResolver $resolver,
        private readonly IngredientNameNormalizer $normalizer,
        private readonly AnvisaCatalogNormalizer $catalog,
        private readonly IngredientTranslationService $translation,
    ) {}

    /**
     * @return array{ingredient_ids: list<int>, document_ids: list<int>, method: string|null, linked_now: int}
     */
    public function find(AnvisaMedicine $medicine): array
    {
        $medicine->loadMissing('ingredients:id');
        $linkedNow = 0;
        if ($medicine->ingredients->isEmpty()) {
            $linkedNow = $this->linkFromCatalogText($medicine);
            if ($linkedNow > 0) {
                $medicine->load('ingredients:id');
            }
        }
        $ingredientIds = $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $result = ['ingredient_ids' => $ingredientIds, 'document_ids' => [], 'method' => null, 'linked_now' => $linkedNow];

        if ($ingredientIds !== []) {
            $documentIds = DB::table('knowledge_document_ingredients as di')
                ->join('knowledge_documents as d', 'd.id', '=', 'di.document_id')
                ->where('d.content_status', KnowledgeDocument::STATUS_READY)
                ->whereIn('di.ingredient_id', $ingredientIds)
                ->distinct()->orderBy('di.document_id')->pluck('di.document_id')->map(fn ($id): int => (int) $id)->all();
            if ($documentIds !== []) {
                return ['document_ids' => $documentIds, 'method' => self::BY_INGREDIENTS] + $result;
            }
        }

        $documentIds = $this->byName($medicine);

        return $documentIds === [] ? $result : ['document_ids' => $documentIds, 'method' => self::BY_NAME] + $result;
    }

    /** Esquece a lista de bulas desta requisição (depois de cadastrar ou vincular documentos em testes ou jobs). */
    public function flush(): void
    {
        $this->leaflets = null;
    }

    /**
     * Mesma regra de `ActiveIngredientCatalogBuilder::linkMedicines`, para um único medicamento e só com aliases já
     * gravados (sem gerar cognatos). Devolve quantos princípios ativos foram ligados.
     */
    private function linkFromCatalogText(AnvisaMedicine $medicine): int
    {
        $fromProductName = blank($medicine->active_ingredient);
        $texts = $fromProductName ? [(string) $medicine->product_name] : $this->normalizer->components($medicine->active_ingredient);
        $rows = [];
        $untranslated = [];
        foreach ($texts as $text) {
            $resolution = $this->resolver->resolve($text);
            if (! $resolution->isResolved() && ! $fromProductName) {
                $untranslated[] = $text;
            }
            if (! $resolution->isResolved()) {
                continue;
            }
            $rows[$resolution->ingredientId] = [
                'medicine_id' => $medicine->id, 'ingredient_id' => $resolution->ingredientId, 'component_text' => mb_substr($text, 0, 250),
                'method' => $fromProductName ? 'product_name' : (string) $resolution->method,
                'confidence' => $fromProductName ? min(0.6, (float) $resolution->confidence) : (float) $resolution->confidence,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        // Nome em português que aliases e regras não conhecem ("dipirona"): a IA traduz para o nome do DDInter; o alias
        // fica `pending_review` e vale para todos os medicamentos com esse ativo (é por ele que as interações aparecem).
        if ($untranslated !== [] && $this->translation->isEnabled() && $this->translation->hasCatalog()) {
            $translated = $this->translation->translate($untranslated);
            foreach ($translated['resolved'] as $base => $ingredientId) {
                $rows[$ingredientId] ??= [
                    'medicine_id' => $medicine->id, 'ingredient_id' => $ingredientId, 'component_text' => mb_substr((string) $base, 0, 250),
                    'method' => 'ai_translation', 'confidence' => 0.8, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        if ($rows !== []) {
            DB::table('anvisa_medicine_ingredients')->upsert(array_values($rows), ['medicine_id', 'ingredient_id'], ['component_text', 'method', 'confidence', 'updated_at']);
        }

        return count($rows);
    }

    /** @return list<int> */
    private function byName(AnvisaMedicine $medicine): array
    {
        $product = $this->catalog->text($medicine->product_name);
        $components = $this->componentSet($medicine->active_ingredient);
        $ids = [];
        foreach ($this->leaflets() as $leaflet) {
            $sameProduct = $product !== '' && $leaflet['product'] === $product;
            $sameIngredients = $components !== [] && $leaflet['components'] === $components;
            if ($sameProduct || $sameIngredients) {
                $ids[] = $leaflet['id'];
            }
        }

        return $ids;
    }

    /**
     * Bulas prontas com o produto (metadado `product_name` ou título antes de " — ") e os componentes do princípio ativo
     * (metadado `active_ingredient` ou, na falta dele, o próprio produto, que nas bulas genéricas é o ativo).
     *
     * @return list<array{id: int, product: string, components: list<string>}>
     */
    private function leaflets(): array
    {
        if ($this->leaflets !== null) {
            return $this->leaflets;
        }
        $this->leaflets = [];
        $rows = DB::table('knowledge_documents')->where('content_status', KnowledgeDocument::STATUS_READY)->orderBy('id')->get(['id', 'title', 'metadata']);
        foreach ($rows as $row) {
            $metadata = is_array($row->metadata) ? $row->metadata : (array) json_decode((string) $row->metadata, true);
            $product = (string) ($metadata['product_name'] ?? explode(' — ', (string) $row->title)[0]);
            $ingredient = (string) ($metadata['active_ingredient'] ?? '');
            $this->leaflets[] = [
                'id' => (int) $row->id,
                'product' => $this->catalog->text($product),
                'components' => $this->componentSet($ingredient !== '' ? $ingredient : $product),
            ];
        }

        return $this->leaflets;
    }

    /** Componentes sem sais/hidratos, ordenados: "maleato de enalapril + hidroclorotiazida" → ["enalapril", "hidroclorotiazida"]. @return list<string> */
    private function componentSet(?string $raw): array
    {
        $set = array_values(array_unique(array_filter(array_map(fn (string $part): string => $this->normalizer->base($part), $this->normalizer->components($raw)), fn (string $part): bool => $part !== '')));
        sort($set);

        return $set;
    }
}
