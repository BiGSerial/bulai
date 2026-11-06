<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\IngredientResolution;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ActiveIngredientAlias;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liga cada bula aos princípios ativos que ela cobre, para que a recuperação de evidências de um par de fármacos
 * parta das bulas dos próprios fármacos. Prioridade: registro ANVISA > metadado de princípio ativo > nome do produto.
 */
final class KnowledgeDocumentIngredientLinker
{
    public function __construct(
        private readonly ActiveIngredientResolver $resolver,
        private readonly IngredientNameNormalizer $normalizer,
        private readonly AnvisaCatalogNormalizer $catalog,
    ) {}

    /** @return array{documents: int, documents_linked: int, links: int} */
    public function linkAll(): array
    {
        $documents = 0;
        $linkedDocuments = 0;
        $links = 0;

        KnowledgeDocument::query()->where('content_status', KnowledgeDocument::STATUS_READY)->orderBy('id')->each(function (KnowledgeDocument $document) use (&$documents, &$linkedDocuments, &$links): void {
            $documents++;
            $count = $this->link($document);
            $linkedDocuments += $count > 0 ? 1 : 0;
            $links += $count;
        });

        return ['documents' => $documents, 'documents_linked' => $linkedDocuments, 'links' => $links];
    }

    public function link(KnowledgeDocument $document): int
    {
        $found = [];
        $metadata = (array) $document->metadata;

        $registration = $this->catalog->registrationNumber($metadata['registration_number'] ?? null);
        if ($registration !== null) {
            DB::table('anvisa_medicine_ingredients as l')
                ->join('anvisa_medicines as m', 'm.id', '=', 'l.medicine_id')
                ->where('m.registration_number', $registration)
                ->pluck('l.ingredient_id')
                ->each(function (int $id) use (&$found): void {
                    $found[$id] = ['registration', 1.0];
                });
        }

        foreach ($this->normalizer->components($metadata['active_ingredient'] ?? null) as $component) {
            $this->remember($found, $component, 'metadata', 0.9, createWhenUnknown: true);
        }

        $product = $metadata['product_name'] ?? explode(' — ', (string) $document->title)[0];
        foreach ($this->normalizer->components($product) as $component) {
            $this->remember($found, $component, 'title', 0.7);
        }

        $now = now();
        $rows = [];
        foreach ($found as $ingredientId => [$method, $confidence]) {
            $rows[] = ['document_id' => $document->id, 'ingredient_id' => $ingredientId, 'method' => $method, 'confidence' => $confidence, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->delete();
        if ($rows !== []) {
            DB::table('knowledge_document_ingredients')->insert($rows);
        }

        return count($rows);
    }

    /** @param array<int, array{0: string, 1: float}> $found */
    private function remember(array &$found, string $text, string $method, float $confidence, bool $createWhenUnknown = false): void
    {
        $resolution = $this->resolver->resolve($text);
        if ($resolution->isResolved() && ! isset($found[$resolution->ingredientId])) {
            $found[$resolution->ingredientId] = [$method, $confidence];

            return;
        }

        if ($createWhenUnknown && $resolution->status === IngredientResolution::UNRESOLVED && $text !== '') {
            $ingredient = $this->createFromDocument($text);
            $found[$ingredient->id] ??= [$method, $confidence];
        }
    }

    /**
     * O catálogo nasce do DDInter, que não cobre todo medicamento brasileiro. Uma bula que declara o princípio ativo
     * cria o princípio (sem interações) e liga os medicamentos da ANVISA com esse ativo, para o Perguntar achar a bula.
     */
    private function createFromDocument(string $normalizedName): ActiveIngredient
    {
        $ingredient = ActiveIngredient::query()->firstOrCreate(['normalized_name' => $normalizedName], ['canonical_name' => Str::title($normalizedName)]);
        ActiveIngredientAlias::query()->firstOrCreate(
            ['ingredient_id' => $ingredient->id, 'normalized_alias' => $normalizedName],
            ['alias' => $normalizedName, 'language' => 'pt', 'origin' => 'document', 'review_status' => 'pending_review'],
        );
        $this->resolver->flush();

        $now = now();
        $rows = DB::table('anvisa_medicines')->whereRaw('lower(active_ingredient) = ?', [$normalizedName])->pluck('id')->map(fn ($medicineId): array => [
            'medicine_id' => $medicineId, 'ingredient_id' => $ingredient->id, 'component_text' => mb_substr($normalizedName, 0, 250),
            'method' => 'document', 'confidence' => 0.9, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('anvisa_medicine_ingredients')->upsert($chunk, ['medicine_id', 'ingredient_id'], ['component_text', 'method', 'confidence', 'updated_at']);
        }

        return $ingredient;
    }
}
