<?php

namespace App\Livewire\Admin\Documents;

use App\Jobs\IndexKnowledgeDocumentEmbeddings;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeLeafletFact;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    public KnowledgeDocument $document;

    public ?string $processingFeedback = null;

    /** Edição da identificação da bula (medicamento, princípio ativo declarado, tipo, registro, título). */
    public bool $editingIdentity = false;

    /** @var array{product_name: string, active_ingredient: string, document_type: string, registration_number: string, holder: string, title: string} */
    public array $identity = ['product_name' => '', 'active_ingredient' => '', 'document_type' => '', 'registration_number' => '', 'holder' => '', 'title' => ''];

    /** Busca de princípio ativo para ligar à bula (aba Princípios ativos). */
    public string $ingredientSearch = '';

    public function mount(KnowledgeDocument $document): void
    {
        $this->document = $document;
    }

    public function processDocument(bool $force = false): void
    {
        ProcessKnowledgeDocument::dispatch($this->document->id, $force);
        $this->processingFeedback = $force ? 'Reprocessamento enfileirado.' : 'Processamento enfileirado.';
        $this->document->refresh();
    }

    /** Refaz só o índice de busca (embeddings): os trechos ficam como estão, nada do PDF é lido de novo. */
    public function rebuildSearchIndex(): void
    {
        if ($this->document->content_status !== KnowledgeDocument::STATUS_READY) {
            $this->processingFeedback = 'O índice de busca só pode ser gerado depois que o texto e os trechos estiverem prontos.';

            return;
        }

        IndexKnowledgeDocumentEmbeddings::dispatch($this->document->id);
        $this->processingFeedback = 'Geração do índice de busca enfileirada. Só os trechos sem vetor são enviados ao serviço de embeddings; os trechos e o PDF não mudam.';
        $this->document->refresh();
    }

    /** Refaz só o vínculo com os princípios ativos (é por ele que o Perguntar encontra a bula); rápido, roda na hora. */
    public function linkIngredients(KnowledgeDocumentIngredientLinker $linker, ProcessingTimeline $timeline): void
    {
        if ($this->document->content_status !== KnowledgeDocument::STATUS_READY) {
            $this->processingFeedback = 'O vínculo com o princípio ativo só pode ser feito depois que o texto e os trechos estiverem prontos.';

            return;
        }

        $timeline->resetStep($this->document->id, KnowledgeProcessingEvent::STEP_LINK);
        $event = $timeline->start($this->document->id, KnowledgeProcessingEvent::STEP_LINK);
        $count = $linker->link($this->document);
        $timeline->finish($event, ['ingredients' => $count]);
        $this->processingFeedback = match (true) {
            $count === 0 => 'Nenhum princípio ativo encontrado para esta bula: o nome do produto não está no catálogo ANVISA ligado a um princípio ativo e os metadados não informam o ativo. Confira o catálogo (Admin → Catálogo ANVISA) ou reprocesse o documento.',
            $count === 1 => '1 princípio ativo ligado. O Perguntar já encontra esta bula.',
            default => "{$count} princípios ativos ligados. O Perguntar já encontra esta bula.",
        };
        $this->document->refresh();
    }

    public function editIdentity(): void
    {
        $metadata = (array) $this->document->metadata;
        $this->identity = [
            'product_name' => $this->document->medicineName() !== '' ? $this->document->medicineName() : (string) $this->suggestedMedicineName(),
            'active_ingredient' => (string) ($metadata['active_ingredient'] ?? ''),
            'document_type' => (string) $this->document->document_type,
            'registration_number' => (string) ($metadata['registration_number'] ?? ''),
            'holder' => (string) ($metadata['holder'] ?? ''),
            'title' => (string) $this->document->title,
        ];
        $this->resetValidation();
        $this->editingIdentity = true;
    }

    /**
     * Nome do medicamento sugerido pelo próprio texto: a primeira linha curta do começo da bula que é um produto do
     * catálogo ANVISA ("paracetamol", "RENITEC"). Serve para bulas de título genérico ("Bula 1790900554429").
     */
    private function suggestedMedicineName(): ?string
    {
        $catalog = app(AnvisaCatalogNormalizer::class);
        foreach ($this->document->chunks()->orderBy('chunk_index')->limit(3)->pluck('content') as $content) {
            foreach (preg_split('/\R+/u', (string) $content) ?: [] as $line) {
                $line = trim((string) preg_replace('/[®™"“”]/u', '', $line));
                if (mb_strlen($line) < 4 || mb_strlen($line) > 60 || preg_match('/^\p{L}[\p{L} +\-]*$/u', $line) !== 1) {
                    continue;
                }
                $product = DB::table('anvisa_medicines')->where('normalized_product_name', $catalog->text($line))->value('product_name');
                if ($product !== null) {
                    return (string) $product;
                }
            }
        }

        return null;
    }

    /**
     * Grava a identificação corrigida e refaz o vínculo com os princípios ativos (os vínculos e exclusões manuais ficam).
     * O nome do medicamento é o que o Perguntar e a bula simples usam para achar a bula da marca.
     */
    public function saveIdentity(KnowledgeDocumentIngredientLinker $linker): void
    {
        $data = $this->validate([
            'identity.product_name' => ['required', 'string', 'max:160'],
            'identity.active_ingredient' => ['nullable', 'string', 'max:300'],
            'identity.document_type' => ['required', 'in:'.implode(',', array_keys(KnowledgeDocument::documentTypeOptions()))],
            'identity.registration_number' => ['nullable', 'string', 'max:40'],
            'identity.holder' => ['nullable', 'string', 'max:200'],
            'identity.title' => ['required', 'string', 'max:255'],
        ], attributes: ['identity.product_name' => 'nome do medicamento', 'identity.title' => 'título', 'identity.document_type' => 'tipo'])['identity'];
        $metadata = (array) $this->document->metadata;
        foreach (['product_name', 'active_ingredient', 'registration_number', 'holder'] as $key) {
            $metadata[$key] = filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        }
        $metadata['identity_edited_at'] = now()->toIso8601String();
        $this->document->forceFill(['title' => trim($data['title']), 'document_type' => $data['document_type'], 'metadata' => $metadata])->save();
        $this->editingIdentity = false;
        if ($this->document->content_status === KnowledgeDocument::STATUS_READY) {
            $count = $linker->link($this->document);
            $this->processingFeedback = 'Identificação salva. '.($count === 0 ? 'Nenhum princípio ativo ligado: ligue à mão na aba Princípios ativos.' : "Princípios ativos ligados: {$count}.");
        } else {
            $this->processingFeedback = 'Identificação salva.';
        }
        $this->document->refresh();
    }

    /** Correção manual: liga o princípio ativo à bula (o vínculo automático não o desfaz). */
    public function attachIngredient(int $ingredientId, KnowledgeDocumentIngredientLinker $linker): void
    {
        $ingredient = ActiveIngredient::query()->find($ingredientId);
        if ($ingredient === null) {
            return;
        }
        $linker->attach($this->document, $ingredient);
        $this->ingredientSearch = '';
        $this->processingFeedback = "{$ingredient->canonical_name} ligado a esta bula. O Perguntar e a bula simples já a encontram por ele.";
        $this->document->refresh();
    }

    /** Correção manual: tira o princípio ativo da bula; o vínculo automático não o traz de volta. */
    public function detachIngredient(int $ingredientId, KnowledgeDocumentIngredientLinker $linker): void
    {
        $linker->detach($this->document, $ingredientId);
        $name = ActiveIngredient::query()->whereKey($ingredientId)->value('canonical_name');
        $this->processingFeedback = "{$name} não está mais ligado a esta bula.";
        $this->document->refresh();
    }

    /** Princípio ativo que o catálogo não tem (ex.: dipirona): cria pelo nome buscado e liga à bula. */
    public function createIngredient(KnowledgeDocumentIngredientLinker $linker): void
    {
        $ingredient = $linker->createIngredient($this->ingredientSearch);
        if ($ingredient === null) {
            $this->processingFeedback = 'Informe o nome do princípio ativo com pelo menos 4 letras.';

            return;
        }
        $this->attachIngredient($ingredient->id, $linker);
    }

    /**
     * Princípios ativos pelo nome canônico ou por um alias (português ou inglês), até 8.
     *
     * @return list<array{id: int, name: string, alias: string|null}>
     */
    private function ingredientOptions(): array
    {
        $term = Str::lower(Str::ascii(trim($this->ingredientSearch)));
        if (mb_strlen($term) < 3) {
            return [];
        }

        return DB::table('active_ingredients as i')
            ->leftJoin('active_ingredient_aliases as a', fn ($join) => $join->on('a.ingredient_id', '=', 'i.id')->where('a.normalized_alias', 'like', $term.'%'))
            ->where(fn ($query) => $query->where('i.normalized_name', 'like', $term.'%')->orWhereNotNull('a.id'))
            ->selectRaw('i.id, i.canonical_name, min(a.alias) as alias')
            ->groupBy('i.id', 'i.canonical_name')->orderBy('i.canonical_name')->limit(8)->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->canonical_name, 'alias' => $row->alias !== null && Str::lower((string) $row->alias) !== Str::lower((string) $row->canonical_name) ? (string) $row->alias : null])
            ->all();
    }

    /** Revisão farmacêutica de uma evidência extraída da bula pela IA (verificada ou rejeitada; rejeitada deixa de ser usada). */
    public function reviewFact(int $factId, string $status): void
    {
        if (! in_array($status, [KnowledgeLeafletFact::REVIEW_VERIFIED, KnowledgeLeafletFact::REVIEW_REJECTED, KnowledgeLeafletFact::REVIEW_PENDING], true)) {
            return;
        }
        KnowledgeLeafletFact::query()->where('document_id', $this->document->id)->whereKey($factId)->update(['review_status' => $status, 'updated_at' => now()]);
        $this->processingFeedback = $status === KnowledgeLeafletFact::REVIEW_REJECTED ? 'Evidência rejeitada: o Perguntar não a usa mais.' : ($status === KnowledgeLeafletFact::REVIEW_VERIFIED ? 'Evidência marcada como verificada.' : 'Evidência devolvida para revisão.');
    }

    public function deleteFact(int $factId): void
    {
        KnowledgeLeafletFact::query()->where('document_id', $this->document->id)->whereKey($factId)->delete();
        $this->processingFeedback = 'Evidência apagada.';
    }

    public function render(ProcessingTimeline $timeline): View
    {
        $this->document->load(['source', 'ingestionBatch', 'ingredients'])->loadCount('chunks');
        $events = $timeline->for($this->document->id);

        return view('livewire.admin.documents.show', [
            'chunks' => $this->document->chunks()->paginate(10),
            'ingredientOptions' => $this->ingredientOptions(),
            'suggestedName' => $this->document->medicineName() === '' ? $this->suggestedMedicineName() : null,
            'pdfAvailable' => $this->document->storage_key !== null || $this->document->storage_path !== null,
            // Começo do texto: identifica a bula de título genérico sem abrir o PDF.
            'opening' => Str::limit(trim((string) $this->document->chunks()->orderBy('chunk_index')->value('content')), 400),
            // Evidências extraídas da bula inteira pela IA em perguntas de pacientes (auditáveis: trecho, seção, página, hash).
            'facts' => KnowledgeLeafletFact::query()->with(['chunk:id,chunk_index', 'medicine:id,product_name'])->where('document_id', $this->document->id)->orderByDesc('id')->limit(100)->get(),
            'processing' => $this->document->metadata['processing'] ?? [],
            'timeline' => $events,
            // Etapa em andamento: a página se atualiza sozinha (wire:poll + evento em tempo real) até concluir.
            'timelineActive' => $events->first(fn (KnowledgeProcessingEvent $event): bool => $event->isRunning()),
            // Última falha do índice de busca: explica o código e oferece refazer só essa etapa.
            'embeddingFailure' => $this->document->embedding_status === KnowledgeDocument::STATUS_FAILED
                ? $events->last(fn (KnowledgeProcessingEvent $event): bool => $event->step === KnowledgeProcessingEvent::STEP_EMBED && $event->state === KnowledgeProcessingEvent::STATE_FAILED)
                : null,
        ])->title($this->document->displayName().' · BULAI');
    }
}
