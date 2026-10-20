<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Identificação profunda (OCR + classificador local) de um item pendente, fora da requisição de upload.
 * Falhas de OCR/IA nunca descartam o item: ele permanece PENDING para revisão manual.
 */
class ClassifyPendingDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $batchId, public readonly int $itemId, public readonly bool $forceAi = false, public readonly ?int $actorId = null)
    {
        // OCR + classificador local + margem; manter queue retry_after acima deste valor.
        $this->timeout = max(
            (int) config('knowledge.processing.job_timeout_seconds'),
            (int) config('document_ocr.drivers.http.timeout') + (int) config('ai.classifier.drivers.llamafile.timeout') + 30,
        );
    }

    public function handle(KnowledgePendingItemReanalysisService $service): void
    {
        $service->reanalyze($this->batchId, $this->itemId, $this->actorId === null ? null : User::query()->find($this->actorId), $this->forceAi);
    }

    public function failed(?\Throwable $exception): void
    {
        app(KnowledgePendingItemReanalysisService::class)->markFailed($this->itemId);
    }

    public function uniqueId(): string
    {
        return $this->batchId.':'.$this->itemId;
    }
}
