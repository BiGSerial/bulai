<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviderException;
use App\Services\Retrieval\KnowledgeIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:index-embeddings {--batch=16 : Chunks por requisição} {--limit= : Máximo de chunks nesta execução} {--reindex : Recalcula mesmo os chunks já indexados} {--model= : Modelo esperado (reindexa chunks de outro modelo)}')]
#[Description('Gera embeddings dos chunks de documentos READY (incremental) com o provedor de embeddings configurado')]
class IndexKnowledgeEmbeddingsCommand extends Command
{
    public function handle(KnowledgeIndexer $indexer): int
    {
        try {
            $summary = $indexer->index((int) $this->option('batch'), $this->option('limit') !== null ? (int) $this->option('limit') : null, (bool) $this->option('reindex'), $this->option('model') ?: null);
        } catch (AiProviderException $exception) {
            $this->components->error('Provedor de embeddings indisponível: '.$exception->errorCode);

            return self::FAILURE;
        }

        $this->table(['Métrica', 'Valor'], collect($summary)->map(fn ($value, string $key): array => [$key, (string) ($value ?? '-')])->values()->all());

        return $summary['failed_batches'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
