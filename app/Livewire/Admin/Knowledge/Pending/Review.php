<?php

namespace App\Livewire\Admin\Knowledge\Pending;

use App\Jobs\ClassifyPendingDocument;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Ai\AiProviderManager;
use App\Services\Knowledge\AutomaticAnalysisReport;
use App\Services\Knowledge\KnowledgeAiSuggestionReviewService;
use App\Services\Knowledge\KnowledgeIngestionAuditService;
use App\Services\Knowledge\KnowledgePendingItemAssociationService;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Review extends Component
{
    public IngestionBatch $batch;

    public IngestionBatchItem $item;

    public string $documentSearch = '';

    public ?int $selectedDocumentId = null;

    public ?string $feedbackType = null;

    public ?string $feedbackMessage = null;

    public ?string $errorId = null;

    public bool $creatingDocument = false;

    public string $newDocumentTitle = '';

    public string $newDocumentType = 'bula';

    public string $newDocumentExternalKey = '';

    public string $newDocumentSourceId = '';

    /** @var list<string> Campos da sugestão da IA marcados para aplicar. */
    public array $selectedAiFields = [];

    public bool $showIgnoreForm = false;

    public string $ignoreReason = '';

    public string $ignoreNotes = '';

    public function mount(IngestionBatch $batch, IngestionBatchItem $item): void
    {
        abort_unless($item->ingestion_batch_id === $batch->id, 404);
        $this->batch = $batch;
        $this->item = $item;
        $this->newDocumentTitle = str($item->original_filename)->beforeLast('.')->replace(['-', '_'], ' ')->headline()->toString();
        $this->newDocumentExternalKey = $item->external_key ?? '';
        $this->newDocumentSourceId = $item->source_document_id ?? '';
        $this->prefillNewDocument();
    }

    private function prefillNewDocument(): void
    {
        $identified = $this->identificationFields();
        $this->newDocumentTitle = (string) ($identified['title'] ?? $this->newDocumentTitle);
        $this->newDocumentType = (string) ($identified['document_type'] ?? $this->newDocumentType);
        $this->newDocumentSourceId = (string) ($identified['registration_number_normalized'] ?? $this->newDocumentSourceId);
    }

    /** Pede à IA local uma nova análise deste arquivo, na fila e independentemente da pontuação da heurística. */
    public function requestAiAnalysis(KnowledgePendingItemReanalysisService $service, AiProviderManager $providers): void
    {
        if (! $providers->isConfigured(AiProviderManager::CLASSIFIER)) {
            [$this->feedbackType, $this->feedbackMessage] = ['warning', 'A IA local não está configurada neste ambiente.'];

            return;
        }
        if (! $service->queueItem($this->item->id, auth()->user(), forceAi: true)) {
            [$this->feedbackType, $this->feedbackMessage] = ['info', 'Este item já está em análise ou foi resolvido.'];
            $this->refreshItem();

            return;
        }
        ClassifyPendingDocument::dispatch($this->batch->id, $this->item->id, true, auth()->id());
        $this->refreshItem();
        [$this->feedbackType, $this->feedbackMessage] = ['info', 'Análise da IA enfileirada. Esta tela atualiza sozinha quando terminar.'];
    }

    public function applyAiSuggestion(KnowledgeAiSuggestionReviewService $reviews, bool $all = false): void
    {
        $fields = $all ? array_keys($reviews->suggestion($this->item)) : $this->selectedAiFields;
        $result = $reviews->accept($this->batch->id, $this->item->id, $fields, auth()->user());
        $this->selectedAiFields = [];
        $this->refreshItem();
        $this->prefillNewDocument();
        [$this->feedbackType, $this->feedbackMessage] = match ($result['status']) {
            KnowledgeAiSuggestionReviewService::ACCEPTED => ['success', 'Valores da IA aplicados a este item. Eles não foram confirmados no catálogo: confira antes de associar.'],
            'NOTHING_SELECTED' => ['warning', 'Marque ao menos um campo da IA para aplicar.'],
            default => ['info', 'Este item já foi resolvido por outra operação.'],
        };
    }

    public function rejectAiSuggestion(KnowledgeAiSuggestionReviewService $reviews): void
    {
        $result = $reviews->reject($this->batch->id, $this->item->id, auth()->user());
        $this->selectedAiFields = [];
        $this->refreshItem();
        [$this->feedbackType, $this->feedbackMessage] = $result['status'] === KnowledgeAiSuggestionReviewService::REJECTED
            ? ['success', 'Análise da IA rejeitada. Os valores da heurística foram mantidos.']
            : ['info', 'Este item já foi resolvido por outra operação.'];
    }

    public function revertAiDecision(KnowledgeAiSuggestionReviewService $reviews): void
    {
        $reverted = $reviews->revert($this->batch->id, $this->item->id, auth()->user());
        $this->refreshItem();
        $this->prefillNewDocument();
        [$this->feedbackType, $this->feedbackMessage] = $reverted ? ['success', 'Decisão sobre a IA desfeita.'] : ['info', 'Não há decisão para desfazer.'];
    }

    public function selectDocument(int $documentId): void
    {
        KnowledgeDocument::query()->findOrFail($documentId);
        $this->selectedDocumentId = $documentId;
        $this->reset(['feedbackType', 'feedbackMessage', 'errorId']);
    }

    public function associate(KnowledgePendingItemAssociationService $service): void
    {
        $this->validate(['selectedDocumentId' => ['required', 'integer', 'exists:knowledge_documents,id']]);

        $result = $service->associate($this->batch->id, $this->item->id, (int) $this->selectedDocumentId);
        $this->refreshItem();
        $this->applyFeedback($result);
    }

    public function createDocument(KnowledgePendingItemAssociationService $service): void
    {
        $validated = $this->validate([
            'newDocumentTitle' => ['required', 'string', 'max:255'],
            'newDocumentType' => ['required', Rule::in(array_keys(KnowledgeDocument::documentTypeOptions()))],
            'newDocumentExternalKey' => ['nullable', 'string', 'max:255'],
            'newDocumentSourceId' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $service->createAndAssociate($this->batch->id, $this->item->id, [
            'title' => $validated['newDocumentTitle'],
            'document_type' => $validated['newDocumentType'],
            'external_key' => $validated['newDocumentExternalKey'] ?: null,
            'source_document_id' => $validated['newDocumentSourceId'] ?: null,
            'language' => 'pt-BR',
        ]);
        $this->refreshItem();
        $this->applyFeedback($result);
        if ($result['status'] === IngestionBatchItem::STATUS_ASSOCIATED) {
            $this->creatingDocument = false;
        }
    }

    /** Reanalisa só este documento, na fila, sem bloquear a tela. */
    public function reanalyze(KnowledgePendingItemReanalysisService $service): void
    {
        if (! $service->queueItem($this->item->id, auth()->user())) {
            [$this->feedbackType, $this->feedbackMessage] = ['info', 'Este item já está em análise ou foi resolvido.'];
            $this->refreshItem();

            return;
        }
        ClassifyPendingDocument::dispatch($this->batch->id, $this->item->id, false, auth()->id());
        $this->refreshItem();
        [$this->feedbackType, $this->feedbackMessage] = ['info', 'Reanálise enfileirada. Esta tela atualiza sozinha quando terminar.'];
    }

    public function ignore(KnowledgeIngestionAuditService $audit): void
    {
        $validated = $this->validate([
            'ignoreReason' => ['required', Rule::in(['not_leaflet', 'wrong_source', 'out_of_scope', 'invalid_document', 'duplicate_unresolved', 'inappropriate_content', 'other'])],
            'ignoreNotes' => ['nullable', 'string', 'max:1000'],
        ], ['ignoreReason.required' => 'Informe o motivo para não incorporar o documento.']);
        $status = $audit->ignore($this->batch->id, $this->item->id, $validated['ignoreReason'], $validated['ignoreNotes'] ?: null, auth()->user());
        $this->refreshItem();
        $this->feedbackType = $status === IngestionBatchItem::STATUS_IGNORED ? 'success' : 'info';
        $this->feedbackMessage = $status === IngestionBatchItem::STATUS_IGNORED ? 'Item resolvido sem incorporação; o arquivo e a evidência foram preservados.' : 'Este item já foi resolvido por outra operação.';
        $this->showIgnoreForm = false;
    }

    /** Atualiza o item enquanto a análise automática roda na fila (usado pelo polling da tela). */
    public function refreshAnalysis(): void
    {
        $this->refreshItem();
    }

    public function render(AutomaticAnalysisReport $analysisReport): View
    {
        $this->item->loadMissing('ingestionBatch.source', 'knowledgeDocument');

        $documents = collect();
        if (mb_strlen(trim($this->documentSearch)) >= 2) {
            $search = '%'.trim($this->documentSearch).'%';
            $documents = KnowledgeDocument::query()
                ->with('source')
                ->when($this->batch->source_id !== null, fn (Builder $query) => $query->where('source_id', $this->batch->source_id))
                ->where(function (Builder $query) use ($search): void {
                    $query->whereLike('title', $search)
                        ->orWhereLike('external_key', $search)
                        ->orWhereLike('source_document_id', $search)
                        ->orWhereLike('normalized_filename', $search);
                })
                ->orderBy('title')->orderBy('id')->limit(10)->get();
        }

        $selectedDocument = $this->selectedDocumentId === null
            ? null
            : KnowledgeDocument::query()->with('source')->find($this->selectedDocumentId);

        return view('livewire.admin.knowledge.pending.review', [
            'documents' => $documents,
            'selectedDocument' => $selectedDocument,
            'metadata' => $this->safeMetadata(),
            'identification' => $this->identificationFields(),
            'identificationSummary' => $this->identificationSummary(),
            'regulatoryValidation' => $this->regulatoryValidation(),
            'analysis' => $analysisReport->forItem($this->item),
            'aiConfigured' => app(AiProviderManager::class)->isConfigured(AiProviderManager::CLASSIFIER),
            'nextPending' => $this->nextPending(),
            'pendingRemaining' => IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->whereKeyNot($this->item->id)->count(),
            'history' => collect(data_get($this->item->metadata, 'audit_history', []))->filter(fn (mixed $event): bool => is_array($event))->reverse()->values(),
        ])->title("Revisar pendência #{$this->item->id} · BULAI");
    }

    /** Próxima pendência a revisar (da mesma importação primeiro), para o fluxo contínuo depois de concluir um item. */
    private function nextPending(): ?IngestionBatchItem
    {
        $pending = IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->whereKeyNot($this->item->id);

        return (clone $pending)->where('ingestion_batch_id', $this->batch->id)->orderBy('id')->first() ?? $pending->orderBy('id')->first();
    }

    /** @param array{status: string, document_id: ?int, file_attached: bool, error_code: ?string, error_id: ?string, http_status: int} $result */
    private function applyFeedback(array $result): void
    {
        $this->errorId = $result['error_id'];

        [$this->feedbackType, $this->feedbackMessage] = match ($result['status']) {
            IngestionBatchItem::STATUS_ASSOCIATED => ['success', 'Documento associado com sucesso.'],
            IngestionBatchItem::STATUS_DUPLICATE => ['info', 'O arquivo já estava associado e o item foi marcado como duplicado.'],
            'ALREADY_RESOLVED' => ['info', 'Este item já foi resolvido por outra operação.'],
            default => match ($result['error_code']) {
                'DOCUMENT_ALREADY_HAS_FILE' => ['warning', 'O documento escolhido já possui um arquivo. Selecione outro documento.'],
                'DOCUMENT_SOURCE_MISMATCH' => ['warning', 'O documento escolhido pertence a outra origem. Selecione um documento da origem desta importação.'],
                'PENDING_FILE_MISSING', 'PENDING_FILE_INVALID' => ['warning', 'O arquivo pendente não está disponível ou não corresponde mais ao arquivo registrado. A associação não foi realizada.'],
                default => ['error', 'Não foi possível concluir a operação.'],
            },
        };

        if ($result['status'] !== 'CONFLICT') {
            $this->selectedDocumentId = null;
        }
    }

    private function refreshItem(): void
    {
        $this->item = IngestionBatchItem::query()
            ->where('ingestion_batch_id', $this->batch->id)
            ->findOrFail($this->item->id);
        $this->batch->refresh();
    }

    /** @return array<string, bool|int|float|string> */
    private function safeMetadata(): array
    {
        return collect($this->item->metadata ?? [])
            ->reject(fn (mixed $value, string $key): bool => ! is_scalar($value) || preg_match('/path|storage|sql|stack|exception|secret|token/i', $key) === 1)
            ->take(6)
            ->all();
    }

    /** @return array<string, string> */
    private function identificationFields(): array
    {
        $fields = data_get($this->item->metadata, 'preliminary_identification.fields', []);

        return is_array($fields) ? array_filter($fields, fn (mixed $value): bool => is_string($value) && $value !== '') : [];
    }

    /** @return array{score: int, pages: int, ambiguous: list<string>}|null */
    private function identificationSummary(): ?array
    {
        $identification = data_get($this->item->metadata, 'preliminary_identification');
        if (! is_array($identification)) {
            return null;
        }

        return ['score' => (int) ($identification['score'] ?? 0), 'pages' => (int) ($identification['pages_analyzed'] ?? 0),
            'ambiguous' => array_values(array_filter($identification['ambiguous_fields'] ?? [], 'is_string'))];
    }

    /** @return array<string, mixed>|null */
    private function regulatoryValidation(): ?array
    {
        $validation = data_get($this->item->metadata, 'preliminary_identification.regulatory_validation');

        return is_array($validation) && $validation !== [] ? $validation : null;
    }
}
