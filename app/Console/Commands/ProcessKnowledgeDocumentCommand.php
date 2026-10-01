<?php

namespace App\Console\Commands;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeDocumentProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:process-document {document : Knowledge document ID} {--force : Rebuild chunks even when content is ready} {--sync : Process in the current CLI process}')]
#[Description('Dispatch or run the document-to-chunks pipeline')]
class ProcessKnowledgeDocumentCommand extends Command
{
    public function handle(KnowledgeDocumentProcessor $processor): int
    {
        $documentId = (int) $this->argument('document');
        if (! KnowledgeDocument::query()->whereKey($documentId)->exists()) {
            $this->error('Documento não encontrado.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        if (! $this->option('sync')) {
            ProcessKnowledgeDocument::dispatch($documentId, $force);
            $this->info('Processamento enfileirado.');

            return self::SUCCESS;
        }

        $result = $processor->process($documentId, $force);
        if ($result['status'] === 'FAILED') {
            $this->error("Processamento falhou ({$result['error_code']}). Referência: {$result['error_id']}");

            return self::FAILURE;
        }

        $this->info($result['status'] === 'SKIPPED' ? 'Documento já processado.' : "Documento processado: {$result['chunks']} chunks.");

        return self::SUCCESS;
    }
}
