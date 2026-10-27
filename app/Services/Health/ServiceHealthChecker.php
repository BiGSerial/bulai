<?php

namespace App\Services\Health;

use App\Data\Health\ServiceHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Consulta o estado dos serviços auxiliares (IA local e OCR). Nunca lança: falhas viram UNREACHABLE. */
final class ServiceHealthChecker
{
    private const CACHE_SECONDS = 5;

    public function localAi(): ServiceHealth
    {
        $url = config('ai.local_ai.url');
        if (config('ai.classifier.default') !== 'llamafile' || blank($url)) {
            return new ServiceHealth('local_ai', ServiceHealth::DISABLED);
        }

        return $this->cached('local_ai', fn (): ServiceHealth => $this->probe('local_ai', rtrim((string) $url, '/').'/status', true));
    }

    public function ocr(): ServiceHealth
    {
        $url = config('document_ocr.drivers.http.url');
        if (! config('document_ocr.enabled') || blank($url)) {
            return new ServiceHealth('ocr', ServiceHealth::DISABLED);
        }

        return $this->cached('ocr', fn (): ServiceHealth => $this->probe('ocr', rtrim((string) $url, '/').'/status', false));
    }

    /** @return array{local_ai: ServiceHealth, ocr: ServiceHealth} */
    public function all(): array
    {
        return ['local_ai' => $this->localAi(), 'ocr' => $this->ocr()];
    }

    private function cached(string $service, callable $resolver): ServiceHealth
    {
        try {
            $cached = Cache::remember("service-health:{$service}", self::CACHE_SECONDS, fn (): array => get_object_vars($resolver()));
            if (is_array($cached) && is_string($cached['state'] ?? null)) {
                return ServiceHealth::fromArray($service, $cached);
            }
        } catch (\Throwable) {
            // Cache indisponível: sonda diretamente.
        }

        return $resolver();
    }

    private function probe(string $service, string $url, bool $reportsState): ServiceHealth
    {
        $startedAt = hrtime(true);
        try {
            $response = Http::acceptJson()->timeout(3)->connectTimeout(2)->get($url);
            if (! $reportsState && $response->status() === 404) {
                // Imagem antiga do OCR sem /status: cai para /health (sem dados de atividade).
                $response = Http::acceptJson()->timeout(3)->connectTimeout(2)->get(preg_replace('#/status$#', '/health', $url));
            }
        } catch (ConnectionException) {
            return new ServiceHealth($service, ServiceHealth::UNREACHABLE);
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
     * @return array{busy: ?bool, activeRequests: ?int, currentSeconds: ?int, restarts: ?int}
     */
    private function activity(Response $response): array
    {
        $int = fn (mixed $value): ?int => is_int($value) ? max(0, $value) : null;
        $busy = $response->json('busy');

        return [
            'busy' => is_bool($busy) ? $busy : null,
            'activeRequests' => $int($response->json('active_requests') ?? $response->json('active')),
            'currentSeconds' => $int(is_numeric($response->json('current_seconds')) ? (int) $response->json('current_seconds') : null),
            'restarts' => $int($response->json('restarts')),
        ];
    }
}
