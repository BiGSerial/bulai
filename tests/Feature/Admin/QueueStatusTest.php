<?php

namespace Tests\Feature\Admin;

use App\Jobs\ClassifyPendingDocument;
use App\Livewire\Admin\Knowledge\Pending\Index as PendingIndex;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Livewire\Admin\Knowledge\QueueStatus;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Health\ServiceHealthChecker;
use App\Services\Queue\QueueStatusService;
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
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
}
