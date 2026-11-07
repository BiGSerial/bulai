<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\KnowledgeProcessingEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Linha do tempo do processamento de uma bula: uma linha por etapa, visível na tela do documento, na Fila e no SSE.
 * Nunca lança: falhar ao registrar a linha do tempo não pode derrubar o processamento.
 */
final class ProcessingTimeline
{
    /** @param array<string, mixed> $detail */
    public function start(int $documentId, string $step, array $detail = []): ?KnowledgeProcessingEvent
    {
        return $this->guard(fn (): KnowledgeProcessingEvent => KnowledgeProcessingEvent::query()->create([
            'document_id' => $documentId,
            'step' => $step,
            'state' => KnowledgeProcessingEvent::STATE_RUNNING,
            'started_at' => now(),
            'detail' => $detail === [] ? null : $detail,
        ]));
    }

    /** Mescla detalhes na etapa em andamento (ex.: lote 3 de 8). @param array<string, mixed> $detail */
    public function progress(KnowledgeProcessingEvent|int|null $event, array $detail): void
    {
        $this->guard(function () use ($event, $detail): void {
            $event = $this->resolve($event);
            if ($event === null) {
                return;
            }
            $event->forceFill(['detail' => [...($event->detail ?? []), ...$detail], 'updated_at' => now()])->save();
        });
    }

    /** @param array<string, mixed> $detail */
    public function finish(KnowledgeProcessingEvent|int|null $event, array $detail = []): void
    {
        $this->close($event, KnowledgeProcessingEvent::STATE_DONE, $detail);
    }

    /** @param array<string, mixed> $detail */
    public function fail(KnowledgeProcessingEvent|int|null $event, string $errorCode, array $detail = []): void
    {
        $this->close($event, KnowledgeProcessingEvent::STATE_FAILED, [...$detail, 'error_code' => $errorCode]);
    }

    /** @param array<string, mixed> $detail */
    public function skip(KnowledgeProcessingEvent|int|null $event, array $detail = []): void
    {
        $this->close($event, KnowledgeProcessingEvent::STATE_SKIPPED, $detail);
    }

    /** Marca como falha toda etapa ainda em andamento do documento (falha geral do processamento). */
    public function failRunning(int $documentId, string $errorCode): void
    {
        $this->guard(function () use ($documentId, $errorCode): void {
            KnowledgeProcessingEvent::query()->where('document_id', $documentId)->where('state', KnowledgeProcessingEvent::STATE_RUNNING)
                ->get()->each(fn (KnowledgeProcessingEvent $event) => $this->fail($event, $errorCode));
        });
    }

    /** Apaga a execução anterior: a tela mostra só a execução corrente. */
    public function reset(int $documentId): void
    {
        $this->guard(fn () => KnowledgeProcessingEvent::query()->where('document_id', $documentId)->delete());
    }

    /** Apaga só as linhas de uma etapa (ex.: refazer o índice de busca sem perder o restante da execução). */
    public function resetStep(int $documentId, string $step): void
    {
        $this->guard(fn () => KnowledgeProcessingEvent::query()->where('document_id', $documentId)->where('step', $step)->delete());
    }

    /** @return Collection<int, KnowledgeProcessingEvent> em ordem cronológica */
    public function for(int $documentId): Collection
    {
        return $this->guard(fn (): Collection => KnowledgeProcessingEvent::query()->where('document_id', $documentId)->orderBy('started_at')->orderBy('id')->get()) ?? new Collection;
    }

    /** Etapas em andamento de qualquer documento (com o documento carregado), para a Fila e o SSE. @return Collection<int, KnowledgeProcessingEvent> */
    public function active(): Collection
    {
        return $this->guard(fn (): Collection => KnowledgeProcessingEvent::query()->with('document:id,title,content_status')
            ->where('state', KnowledgeProcessingEvent::STATE_RUNNING)->orderBy('started_at')->orderBy('id')->limit(100)->get()) ?? new Collection;
    }

    /** @param array<string, mixed> $detail */
    private function close(KnowledgeProcessingEvent|int|null $event, string $state, array $detail): void
    {
        $this->guard(function () use ($event, $state, $detail): void {
            $event = $this->resolve($event);
            if ($event === null || ! $event->isRunning()) {
                return;
            }
            $finishedAt = now();
            $event->forceFill([
                'state' => $state,
                'finished_at' => $finishedAt,
                'duration_ms' => max(0, (int) $event->started_at->diffInMilliseconds($finishedAt)),
                'detail' => [...($event->detail ?? []), ...$detail] ?: null,
            ])->save();
        });
    }

    private function resolve(KnowledgeProcessingEvent|int|null $event): ?KnowledgeProcessingEvent
    {
        return $event instanceof KnowledgeProcessingEvent ? $event : ($event === null ? null : KnowledgeProcessingEvent::query()->find($event));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T|null
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            Log::warning('knowledge_processing_timeline_failed', ['exception' => $exception]);

            return null;
        }
    }
}
