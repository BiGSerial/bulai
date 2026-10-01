<?php

namespace App\Jobs;

use App\Services\Knowledge\KnowledgeDocumentProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessKnowledgeDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $documentId,
        public readonly bool $force = false,
    ) {
        $this->timeout = (int) config('knowledge.processing.job_timeout_seconds');
    }

    /**
     * Execute the job.
     */
    public function handle(KnowledgeDocumentProcessor $processor): void
    {
        $processor->process($this->documentId, $this->force);
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }
}
