<?php

namespace Tests\Feature\Admin;

use App\Jobs\ClassifyPendingDocument;
use App\Jobs\GenerateReport;
use App\Jobs\IndexKnowledgeDocumentEmbeddings;
use App\Jobs\ProcessKnowledgeDocument;
use App\Jobs\RunInteractionConsultation;
use App\Livewire\Admin\Knowledge\Pending\Index as PendingIndex;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Livewire\Admin\Knowledge\QueueStatus;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Health\ServiceHealthChecker;
use App\Services\Queue\QueueStatusService;
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

final class QueueStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('ai.classifier.default', 'llamafile');
        config()->set('ai.local_ai.url', 'http://local-ai:8080');
        config()->set('ai.local_ai.admin_token', 'admin-secret');
        config()->set('document_ocr.enabled', true);
        config()->set('document_ocr.drivers.http.url', 'http://document-ocr:8000');
        config()->set('document_ocr.drivers.http.token', 'ocr-secret');
    }

    public function test_page_is_admin_only_and_reports_a_stopped_worker(): void
    {
        $this->get('/admin/knowledge/queue')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/knowledge/queue')->assertForbidden();

        Http::fake(['*' => Http::response(['status' => 'ok', 'busy' => false])]);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/queue')
            ->assertOk()->assertSee('data-state="stopped"', false)->assertSee('queue:work')->assertSee('Fila e serviços · BULAI', false);
    }

    public function test_worker_heartbeat_marks_the_worker_alive_and_busy(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        $this->assertSame('stopped', $heartbeat->status()['state']);

        $heartbeat->beat(force: true);
        $this->assertSame('idle', $heartbeat->status()['state']);

        $heartbeat->beat(busy: true);
        $this->assertSame('running', $heartbeat->status()['state']);

        Cache::put('queue:worker-heartbeat', ['at' => time() - 600, 'busy' => false], 60);
        $this->assertSame('stopped', $heartbeat->status()['state']);
    }

    public function test_snapshot_lists_active_items_flags_stale_and_matches_jobs(): void
    {
        Queue::fake();
        [$batch, $fresh] = $this->pendingItem('fresh.pdf', 'queued');
        $stale = $this->addItem($batch, 'stale.pdf', 'processing');
        IngestionBatchItem::query()->whereKey($stale->id)->update(['updated_at' => now()->subMinutes(30)]);

        $snapshot = app(QueueStatusService::class)->snapshot();

        $this->assertCount(2, $snapshot['items']);
        $this->assertSame(1, $snapshot['stale']);
        $this->assertFalse(collect($snapshot['items'])->firstWhere('id', $fresh->id)['stale']);
    }

    public function test_snapshot_reads_the_redis_queue(): void
    {
        config()->set('queue.default', 'redis');
        [, $item] = $this->pendingItem('r.pdf', 'queued');
        $payload = json_encode(['data' => ['command' => serialize(new ClassifyPendingDocument($item->ingestion_batch_id, $item->id))]]);
        $connection = \Mockery::mock();
        $connection->shouldReceive('llen')->with('queues:default')->andReturn(1);
        $connection->shouldReceive('zcard')->with('queues:default:delayed')->andReturn(0);
        $connection->shouldReceive('zcard')->with('queues:default:reserved')->andReturn(2);
        $connection->shouldReceive('lrange')->with('queues:default', 0, 499)->andReturn([$payload]);
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $snapshot = app(QueueStatusService::class)->snapshot();

        $this->assertSame(1, $snapshot['jobs']['waiting']);
        $this->assertSame(2, $snapshot['jobs']['running']);
        $this->assertTrue($snapshot['items'][0]['has_job']);

        $connection->shouldReceive('lrange')->with('queues:default', 0, -1)->andReturn([$payload]);
        $connection->shouldReceive('lrem')->once()->with('queues:default', 1, $payload)->andReturn(1);
        $this->assertSame(1, app(QueueStatusService::class)->removeWaitingJobs($item->id));
    }

    public function test_requeue_and_cancel_act_only_on_the_chosen_item(): void
    {
        Queue::fake();
        [$batch, $item] = $this->pendingItem('a.pdf', 'processing');
        $other = $this->addItem($batch, 'b.pdf', 'queued');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(QueueStatus::class)->call('requeueItem', $item->id)->assertSee('Item reenfileirado.');
        Queue::assertPushed(ClassifyPendingDocument::class, 1);
        Queue::assertPushed(ClassifyPendingDocument::class, fn (ClassifyPendingDocument $job): bool => $job->itemId === $item->id);
        $this->assertSame('queued', data_get($item->refresh()->metadata, 'reanalysis_progress.state'));

        Livewire::actingAs($admin)->test(QueueStatus::class)->call('cancelItem', $other->id);
        $this->assertSame('failed', data_get($other->refresh()->metadata, 'reanalysis_progress.state'));
        $this->assertSame('PENDING', $other->status);
        $this->assertSame('reanalysis_cancelled', collect(data_get($other->metadata, 'audit_history'))->last()['event']);
    }

    public function test_pending_list_and_review_reanalyze_a_single_document_through_the_queue(): void
    {
        Queue::fake();
        [$batch, $item] = $this->pendingItem('um.pdf');
        $this->addItem($batch, 'dois.pdf');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(PendingIndex::class)->call('reanalyzeItem', $item->id)->assertSee('Documento enviado para reanálise.');
        Queue::assertPushed(ClassifyPendingDocument::class, 1);
        $this->assertSame('queued', data_get($item->refresh()->metadata, 'reanalysis_progress.state'));

        // Já enfileirado: não duplica.
        Livewire::actingAs($admin)->test(PendingIndex::class)->call('reanalyzeItem', $item->id)->assertSee('já está em análise');
        Queue::assertPushed(ClassifyPendingDocument::class, 1);

        [, $reviewItem] = $this->pendingItem('tres.pdf');
        Livewire::actingAs($admin)->test(Review::class, ['batch' => $reviewItem->ingestionBatch, 'item' => $reviewItem])->call('reanalyze');
        Queue::assertPushed(ClassifyPendingDocument::class, 2);
    }

    public function test_restart_calls_the_service_with_its_own_token_and_reports_failures(): void
    {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['http://document-ocr:8000/admin/restart' => Http::response(['status' => 'restarting'], 202), 'http://local-ai:8080/admin/restart' => Http::response('', 401), '*' => Http::response(['status' => 'ok'])]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(QueueStatus::class)->call('restartService', 'ocr')->assertSee('Reinício solicitado');
        Http::assertSent(fn ($request): bool => $request->url() === 'http://document-ocr:8000/admin/restart' && $request->hasHeader('Authorization', 'Bearer ocr-secret'));

        Livewire::actingAs($admin)->test(QueueStatus::class)->call('restartService', 'local_ai')->assertSee('recusou');
        Livewire::actingAs($admin)->test(QueueStatus::class)->call('restartService', 'database');

        config()->set('ai.local_ai.admin_token', null);
        Livewire::actingAs($admin)->test(QueueStatus::class)->call('restartService', 'local_ai')->assertSee('não configurado');
    }

    public function test_health_checker_reads_activity_from_status(): void
    {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake([
            '*/status' => Http::response(['status' => 'READY', 'busy' => true, 'active_requests' => 1, 'current_seconds' => 42, 'restarts' => 2]),
        ]);

        $health = app(ServiceHealthChecker::class)->localAi();

        $this->assertTrue($health->isWorking());
        $this->assertSame(42, $health->currentSeconds);
        $this->assertSame(2, $health->restarts);
        $this->assertTrue(app(ServiceHealthChecker::class)->ocr()->isWorking());
    }

    public function test_snapshot_lists_documents_in_processing_and_matches_the_ocr_page_by_correlation_id(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'http://document-ocr:8000/status' => Http::response(['status' => 'ok', 'busy' => true, 'active' => 1, 'current' => ['correlation_id' => 'corr-ocr-1', 'page' => 7, 'total_pages' => 12, 'elapsed_seconds' => 65.4]]),
            '*' => Http::response(['status' => 'READY', 'busy' => false]),
        ]);
        $ocr = KnowledgeProcessingEvent::factory()->running()->create(['step' => KnowledgeProcessingEvent::STEP_OCR, 'detail' => ['correlation_id' => 'corr-ocr-1', 'reason' => 'low_quality']]);
        $otherOcr = KnowledgeProcessingEvent::factory()->running()->create(['step' => KnowledgeProcessingEvent::STEP_OCR, 'detail' => ['correlation_id' => 'corr-ocr-2']]);
        $embed = KnowledgeProcessingEvent::factory()->running()->create(['step' => KnowledgeProcessingEvent::STEP_EMBED, 'detail' => ['batch' => 2, 'batches' => 5, 'chunks_done' => 32, 'chunks' => 80]]);
        KnowledgeProcessingEvent::query()->whereKey($otherOcr->id)->update(['started_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)]);
        KnowledgeProcessingEvent::factory()->create(['step' => KnowledgeProcessingEvent::STEP_CHUNK]);

        $documents = collect(app(QueueStatusService::class)->snapshot()['documents']);

        $this->assertCount(3, $documents, 'só etapas em andamento entram');
        $matched = $documents->firstWhere('id', $ocr->document_id);
        $this->assertSame(['page' => 7, 'total' => 12], $matched['progress']);
        $this->assertSame(['Leitura por imagem (OCR)', 'página 7 de 12 · 65 s', false], [$matched['step_label'], $matched['summary'], $matched['stale']]);
        $unmatched = $documents->firstWhere('id', $otherOcr->document_id);
        $this->assertSame([null, true], [$unmatched['progress'], $unmatched['stale']], 'outro correlation_id não ganha a página e sem sinal há 30 min fica parado');
        $this->assertSame([null, '32 de 80 trechos · lote 2 de 5'], [$documents->firstWhere('id', $embed->document_id)['progress'], $documents->firstWhere('id', $embed->document_id)['summary']]);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/queue')->assertOk()
            ->assertSee('Bulas em processamento')->assertSee('3 bulas em processamento · 1 sem sinal')
            ->assertSee('Página 7 de 12')->assertSee('Sem sinal há mais de')->assertSee('32 de 80 trechos · lote 2 de 5')
            ->assertSee(route('admin.documents.show', $ocr->document_id), false);
    }

    public function test_queue_page_without_documents_in_processing_shows_a_compact_empty_state(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'busy' => false])]);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/queue')->assertOk()
            ->assertSee('Nenhuma bula sendo processada agora.');
    }

    public function test_stream_carries_documents_in_processing_and_service_hints(): void
    {
        config()->set('knowledge.stream.seconds', 0);
        Http::fake([
            'http://document-ocr:8000/status' => Http::response(['status' => 'ok', 'busy' => true, 'current' => ['correlation_id' => 'corr-stream', 'page' => 3, 'total_pages' => 9, 'elapsed_seconds' => 12]]),
            '*' => Http::response(['status' => 'READY', 'busy' => false]),
        ]);
        $event = KnowledgeProcessingEvent::factory()->running()->create(['step' => KnowledgeProcessingEvent::STEP_OCR, 'detail' => ['correlation_id' => 'corr-stream']]);

        $body = $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/stream')->assertOk()->streamedContent();

        preg_match('/event: changed\ndata: (.+)\n/', $body, $match);
        $payload = json_decode($match[1] ?? '{}', true);
        $this->assertSame([['id' => $event->document_id, 'title' => 'Bula de teste', 'step' => 'ocr', 'summary' => 'página 3 de 9 · 12 s', 'progress' => ['page' => 3, 'total' => 9]]], $payload['documents']);
        $this->assertSame(['state' => 'READY', 'busy' => true, 'progress' => ['page' => 3, 'total' => 9], 'hint' => 'Página 3 de 9 · 12 s'], $payload['services']['ocr']);
    }

    public function test_stream_emits_changes_requires_admin_and_limits_connections(): void
    {
        config()->set('knowledge.stream.seconds', 0);
        Http::fake(['*' => Http::response(['status' => 'READY', 'busy' => false])]);

        $this->get('/admin/knowledge/stream')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/knowledge/stream')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/knowledge/stream')->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString("event: changed\n", $body);
        $this->assertStringContainsString('"worker":"stopped"', $body);
        $this->assertSame(0, (int) Cache::get("knowledge-stream:{$admin->id}"));

        Cache::put("knowledge-stream:{$admin->id}", 2, 60);
        $this->actingAs($admin)->get('/admin/knowledge/stream')->assertStatus(429);
    }

    /** @return array{0: IngestionBatch, 1: IngestionBatchItem} */
    private function pendingItem(string $name, ?string $state = null): array
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA'], ['active' => true]);
        $batch = IngestionBatch::query()->create(['name' => 'Lote', 'source_id' => $source->id, 'status' => 'COMPLETED', 'total_items' => 1, 'pending_items' => 1]);

        return [$batch, $this->addItem($batch, $name, $state)];
    }

    private function addItem(IngestionBatch $batch, string $name, ?string $state = null): IngestionBatchItem
    {
        $metadata = ['ingestion_channel' => 'knowledge_admin'];
        if ($state !== null) {
            $metadata['reanalysis_progress'] = ['state' => $state, 'percent' => 10, 'label' => 'Teste', 'updated_at' => now()->toISOString()];
        }

        return $batch->items()->create(['original_filename' => $name, 'normalized_filename' => $name, 'sha256' => hash('sha256', $name), 'mime_type' => 'application/pdf', 'file_size' => 10, 'storage_disk' => 'knowledge', 'storage_key' => "k/{$name}", 'storage_path' => "k/{$name}", 'status' => 'PENDING', 'reason' => 'no_match_found', 'metadata' => $metadata]);
    }

    public function test_queue_page_shows_kpis_including_items_resolved_today(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'busy' => false])]);
        [$batch] = $this->pendingItem('a.pdf', 'queued');
        $this->addItem($batch, 'b.pdf', 'queued')->update(['status' => IngestionBatchItem::STATUS_IGNORED]);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/queue')->assertOk()
            ->assertSeeInOrder(['Na fila', 'Processando', 'Com falha', 'Resolvidos hoje'])
            ->assertSee('Arquivos que saíram da fila hoje')->assertSee('a.pdf')->assertDontSee('b.pdf');
    }

    /** Cada tarefa da fila aparece: a que roda agora no topo e as que esperam na ordem, com o assunto e sem dados de paciente. */
    public function test_tasks_list_every_queued_job_in_order_with_the_one_running_now(): void
    {
        config()->set('queue.default', 'database');
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA'], ['active' => true]);
        $make = fn (string $title): KnowledgeDocument => KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $title, 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => uniqid('h_')]);
        $dipirona = $make('DIPIRONA — Bula do paciente');
        $naprix = $make('NAPRIX D — Bula do paciente');
        [, $item] = $this->pendingItem('importado.pdf');
        ProcessKnowledgeDocument::dispatch($dipirona->id, true);
        ProcessKnowledgeDocument::dispatch($naprix->id, true);
        ClassifyPendingDocument::dispatch($item->ingestion_batch_id, $item->id);
        RunInteractionConsultation::dispatch(77);
        DB::table('jobs')->orderBy('id')->limit(1)->update(['reserved_at' => now()->subSeconds(40)->getTimestamp(), 'attempts' => 1]);

        $tasks = app(QueueStatusService::class)->tasks();

        $this->assertSame([['Processar bula', 'DIPIRONA — Bula do paciente', 'running']], array_map(fn (array $task): array => [$task['label'], $task['subject'], $task['state']], $tasks['running']));
        $this->assertSame(
            [['Processar bula', 'NAPRIX D — Bula do paciente'], ['Analisar arquivo importado', 'importado.pdf'], ['Consulta de interações de um paciente', '#77']],
            array_map(fn (array $task): array => [$task['label'], $task['subject']], $tasks['waiting']),
        );
        $this->assertSame(3, $tasks['waiting_total']);

        Http::fake(['*' => Http::response(['status' => 'ok', 'busy' => false])]);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/queue')->assertOk()
            ->assertSee('Tarefas na fila')->assertSee('1 rodando agora · 3 esperando')
            ->assertSeeInOrder(['Processando agora', 'NAPRIX D — Bula do paciente', 'importado.pdf', '#77']);
    }

    public function test_tasks_read_running_waiting_and_scheduled_jobs_from_redis(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.retry_after', 600);
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA'], ['active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'dip', 'title' => 'DIPIRONA — Bula do paciente', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'h']);
        $payload = fn (object $job, int $attempts = 0): string => (string) json_encode(['uuid' => uniqid(), 'displayName' => $job::class, 'attempts' => $attempts, 'createdAt' => now()->subMinutes(3)->getTimestamp(), 'data' => ['command' => serialize($job)]]);
        $running = $payload(new ProcessKnowledgeDocument($document->id, true), 1);
        $connection = \Mockery::mock();
        $connection->shouldReceive('zrange')->with('queues:default:reserved', 0, -1, ['withscores' => true])->andReturn([$running => now()->addSeconds(540)->getTimestamp()]);
        $connection->shouldReceive('llen')->with('queues:default')->andReturn(1);
        $connection->shouldReceive('zcard')->with('queues:default:delayed')->andReturn(1);
        $connection->shouldReceive('lrange')->with('queues:default', 0, 199)->andReturn([$payload(new IndexKnowledgeDocumentEmbeddings($document->id))]);
        $connection->shouldReceive('zrange')->with('queues:default:delayed', 0, 199, ['withscores' => true])->andReturn([$payload(new GenerateReport(5)) => now()->addMinutes(2)->getTimestamp()]);
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $tasks = app(QueueStatusService::class)->tasks();

        $this->assertSame('Processar bula', $tasks['running'][0]['label']);
        $this->assertEqualsWithDelta(now()->subSeconds(60)->getTimestamp(), $tasks['running'][0]['started_at'], 2, 'início = expiração da reserva − retry_after');
        $this->assertSame([['Indexar bula para a busca', 'DIPIRONA — Bula do paciente', 'waiting'], ['Gerar relatório', '#5', 'delayed']], array_map(fn (array $task): array => [$task['label'], $task['subject'], $task['state']], $tasks['waiting']));
        $this->assertSame(2, $tasks['waiting_total']);
    }
}
