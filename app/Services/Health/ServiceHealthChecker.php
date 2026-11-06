<?php

namespace App\Services\Health;

use App\Data\Health\ServiceHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Estado dos serviços auxiliares (IA local, OCR e embeddings). A sondagem roda em segundo plano
 * (`services:probe-health`, a cada 5 s) e grava no cache; as telas e o SSE só leem a última leitura. Sem scheduler
 * (desenvolvimento), uma leitura velha é refeita na hora. Nunca lança: falhas viram UNRESPONSIVE e, se persistirem, UNREACHABLE.
 */
final class ServiceHealthChecker
{
    /** Leitura mais velha que isto é refeita na requisição (o scheduler deveria tê-la renovado). */
    public const FRESH_SECONDS = 15;

    /** Sondagens seguidas sem resposta até o serviço ser dado como inacessível. */
    public const FAILURES_BEFORE_UNREACHABLE = 3;

    private const CACHE_DAYS = 1;

    public function localAi(): ServiceHealth
    {
        return $this->fresh('local_ai');
    }

    public function ocr(): ServiceHealth
    {
        return $this->fresh('ocr');
    }

    /** Serviço de embeddings (API compatível com OpenAI): sondado em /models, sem gerar vetores. */
    public function embeddings(): ServiceHealth
    {
        return $this->fresh('embeddings');
    }

    /** @return array{local_ai: ServiceHealth, ocr: ServiceHealth, embeddings: ServiceHealth} */
    public function all(): array
    {
        return ['local_ai' => $this->localAi(), 'ocr' => $this->ocr(), 'embeddings' => $this->embeddings()];
    }

    /**
     * Sonda os três serviços agora e grava as leituras no cache (usado pelo comando agendado e pelos testes).
     *
     * @return array{local_ai: ServiceHealth, ocr: ServiceHealth, embeddings: ServiceHealth}
     */
    public function refresh(): array
    {
        return ['local_ai' => $this->observe('local_ai'), 'ocr' => $this->observe('ocr'), 'embeddings' => $this->observe('embeddings')];
    }

    /** @return array{url: string, reports_state: bool}|null null quando o serviço está desativado na configuração */
    private function target(string $service): ?array
    {
        return match ($service) {
            'local_ai' => config('ai.classifier.default') !== 'llamafile' || blank(config('ai.local_ai.url'))
                ? null : ['url' => rtrim((string) config('ai.local_ai.url'), '/').'/status', 'reports_state' => true],
            'ocr' => ! config('document_ocr.enabled') || blank(config('document_ocr.drivers.http.url'))
                ? null : ['url' => rtrim((string) config('document_ocr.drivers.http.url'), '/').'/status', 'reports_state' => false],
            'embeddings' => config('ai.embedding.default') !== 'openai_compatible' || blank(config('ai.embedding.drivers.openai_compatible.base_url'))
                ? null : ['url' => rtrim((string) config('ai.embedding.drivers.openai_compatible.base_url'), '/').'/models', 'reports_state' => false],
            default => null,
        };
    }

    private function fresh(string $service): ServiceHealth
    {
        if ($this->target($service) === null) {
            return new ServiceHealth($service, ServiceHealth::DISABLED);
        }

        $stored = $this->stored($service);
        if ($stored !== null && $stored->observedAt !== null && time() - $stored->observedAt <= self::FRESH_SECONDS) {
            return $stored;
        }

        return $this->observe($service);
    }

    private function observe(string $service): ServiceHealth
    {
        $target = $this->target($service);
        if ($target === null) {
            return new ServiceHealth($service, ServiceHealth::DISABLED);
        }

        $now = time();
        $probed = $this->probe($service, $target['url'], $target['reports_state']);
        $health = $probed === null
            ? ServiceHealth::unanswered($service, $this->stored($service), $now, self::FAILURES_BEFORE_UNREACHABLE)
            : $probed->observed($now);
        $this->store($health);

        return $health;
    }

    private function stored(string $service): ?ServiceHealth
    {
        try {
            $cached = Cache::get($this->key($service));
        } catch (\Throwable) {
            return null;
        }

        return is_array($cached) && is_string($cached['state'] ?? null) ? ServiceHealth::fromArray($service, $cached) : null;
    }

    private function store(ServiceHealth $health): void
    {
        try {
            Cache::put($this->key($health->service), $health->toArray(), now()->addDays(self::CACHE_DAYS));
        } catch (\Throwable) {
            // Cache indisponível: a leitura vale só para esta requisição.
        }
    }

    private function key(string $service): string
    {
        return "service-health:{$service}";
    }

    /** null quando o serviço não respondeu (conexão recusada ou tempo esgotado). */
    private function probe(string $service, string $url, bool $reportsState): ?ServiceHealth
    {
        $startedAt = hrtime(true);
        try {
            $response = Http::acceptJson()->timeout(3)->connectTimeout(2)->get($url);
            if (! $reportsState && $response->status() === 404) {
                // Imagem antiga do OCR sem /status: cai para /health (sem dados de atividade).
                $response = Http::acceptJson()->timeout(3)->connectTimeout(2)->get(preg_replace('#/status$#', '/health', $url));
            }
        } catch (ConnectionException) {
            return null;
        }

        $latency = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        if (! $reportsState) {
            return new ServiceHealth($service, $response->successful() ? ServiceHealth::READY : ServiceHealth::ERROR, $latency, null, ...$this->activity($response));
        }

        $state = $response->json('status');
        $known = [ServiceHealth::READY, ServiceHealth::SERVICE_AVAILABLE, ServiceHealth::MODEL_LOADING, ServiceHealth::MODEL_NOT_INSTALLED, ServiceHealth::ERROR];
        $model = $response->json('model');

        return new ServiceHealth($service, in_array($state, $known, true) ? $state : ServiceHealth::ERROR, $latency, is_string($model) ? mb_substr($model, 0, 100) : null, ...$this->activity($response));
    }

    /**
     * Ocupação e, no OCR, o documento em curso (`current`: página atual, total e correlação com o job).
     *
     * @return array{busy: ?bool, activeRequests: ?int, currentSeconds: ?int, restarts: ?int, progress: ?array{page: int, total: int}, correlationId: ?string}
     */
    private function activity(Response $response): array
    {
        $int = fn (mixed $value): ?int => is_int($value) ? max(0, $value) : null;
        $busy = $response->json('busy');
        $current = $response->json('current');
        $current = is_array($current) ? $current : [];
        $seconds = $response->json('current_seconds') ?? ($current['elapsed_seconds'] ?? null);
        $page = $current['page'] ?? null;
        $total = $current['total_pages'] ?? null;

        return [
            'busy' => is_bool($busy) ? $busy : null,
            'activeRequests' => $int($response->json('active_requests') ?? $response->json('active')),
            'currentSeconds' => $int(is_numeric($seconds) ? (int) $seconds : null),
            'restarts' => $int($response->json('restarts')),
            'progress' => is_int($page) && is_int($total) && $page >= 0 && $total > 0 ? ['page' => min($page, $total), 'total' => $total] : null,
            'correlationId' => is_string($current['correlation_id'] ?? null) ? mb_substr($current['correlation_id'], 0, 100) : null,
        ];
    }
}
