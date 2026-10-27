<?php

namespace App\Services\Queue;

use Illuminate\Support\Facades\Cache;

/** Registra e lê o sinal de vida do `queue:work`, para a interface mostrar se a fila está sendo consumida. */
final class WorkerHeartbeat
{
    private const KEY = 'queue:worker-heartbeat';

    private const WRITE_EVERY_SECONDS = 10;

    private static int $lastWrite = 0;

    public function beat(bool $busy = false, bool $force = false): void
    {
        $now = time();
        if (! $force && ! $busy && $now - self::$lastWrite < self::WRITE_EVERY_SECONDS) {
            return;
        }
        self::$lastWrite = $now;

        try {
            Cache::put(self::KEY, ['at' => $now, 'busy' => $busy, 'host' => gethostname() ?: 'desconhecido', 'pid' => getmypid()], 3600);
        } catch (\Throwable) {
            // Cache indisponível não pode derrubar o worker.
        }
    }

    /** @return array{state: string, last_seen_at: ?int, host: ?string, busy: bool} state: running|idle|stopped */
    public function status(): array
    {
        try {
            $beat = Cache::get(self::KEY);
        } catch (\Throwable) {
            $beat = null;
        }
        if (! is_array($beat) || ! is_int($beat['at'] ?? null)) {
            return ['state' => 'stopped', 'last_seen_at' => null, 'host' => null, 'busy' => false];
        }

        $busy = (bool) ($beat['busy'] ?? false);
        // Um job em execução não gera batimentos: tolera até o timeout máximo de um job.
        $tolerance = $busy ? 900 : 30;
        $alive = time() - $beat['at'] <= $tolerance;

        return [
            'state' => ! $alive ? 'stopped' : ($busy ? 'running' : 'idle'),
            'last_seen_at' => $beat['at'],
            'host' => is_string($beat['host'] ?? null) ? $beat['host'] : null,
            'busy' => $busy && $alive,
        ];
    }
}
