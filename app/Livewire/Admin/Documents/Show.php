<?php

namespace App\Livewire\Admin\Documents;

use App\Jobs\IndexKnowledgeDocumentEmbeddings;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeLeafletFact;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    public KnowledgeDocument $document;

    public ?string $processingFeedback = null;

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
        ])->title($this->document->title.' · BULAI');
    }
}
