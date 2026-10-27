<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Health\ServiceHealthChecker;
use App\Services\Queue\QueueStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events da administração: avisa o navegador quando a fila, o worker ou os serviços mudam.
 * Conexões curtas (o EventSource reconecta sozinho) para não prender workers do PHP-FPM por muito tempo.
 */
class KnowledgeStreamController extends Controller
{
    public function __invoke(Request $request, QueueStatusService $queue, ServiceHealthChecker $health): StreamedResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $slotKey = "knowledge-stream:{$userId}";
        $maxConnections = (int) config('knowledge.stream.max_per_user', 2);

        if ($maxConnections < 1 || $this->acquireSlot($slotKey, $maxConnections) === false) {
            abort(429, 'Muitas conexões em tempo real abertas.');
        }

        // Libera o lock da sessão: o stream não precisa dela e não pode bloquear outras requisições do usuário.
        $request->hasSession() && $request->session()->save();

        $seconds = max(0, (int) config('knowledge.stream.seconds', 25));
        $intervalMs = max(200, (int) config('knowledge.stream.interval_ms', 2000));

        return response()->stream(function () use ($queue, $health, $slotKey, $seconds, $intervalMs): void {
            try {
                echo "retry: 3000\n\n";
                $lastSignature = null;
                $deadline = microtime(true) + $seconds;
                do {
                    $snapshot = $queue->snapshot();
                    $services = array_map(fn ($service): array => $service->toArray(), $health->all());
                    $signature = md5(json_encode([$snapshot, $services], JSON_THROW_ON_ERROR));
                    if ($signature !== $lastSignature) {
                        $payload = json_encode([
                            'worker' => $snapshot['worker']['state'],
                            'jobs' => $snapshot['jobs'],
                            'active_items' => count($snapshot['items']),
                            'stale' => $snapshot['stale'],
                            'services' => array_map(fn (array $service): array => ['state' => $service['state'], 'busy' => $service['busy']], $services),
                        ], JSON_THROW_ON_ERROR);
                        echo "event: changed\ndata: {$payload}\n\n";
                        $lastSignature = $signature;
                    } else {
                        echo ": ping\n\n";
                    }
                    $this->flush();
                    if (connection_aborted() || microtime(true) >= $deadline) {
                        break;
                    }
                    usleep($intervalMs * 1000);
                } while (true);
            } finally {
                Cache::decrement($slotKey);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function acquireSlot(string $key, int $max): bool
    {
        Cache::add($key, 0, 120);
        if (Cache::increment($key) > $max) {
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
