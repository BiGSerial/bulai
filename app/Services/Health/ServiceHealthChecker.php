<?php

namespace App\Services\Health;

use App\Data\Health\ServiceHealth;
use Illuminate\Http\Client\ConnectionException;
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

        return $this->cached('ocr', fn (): ServiceHealth => $this->probe('ocr', rtrim((string) $url, '/').'/health', false));
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
                return new ServiceHealth($service, $cached['state'], is_int($cached['latencyMs'] ?? null) ? $cached['latencyMs'] : null, is_string($cached['model'] ?? null) ? $cached['model'] : null);
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
        } catch (ConnectionException) {
            return new ServiceHealth($service, ServiceHealth::UNREACHABLE);
        }

        $latency = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        if (! $reportsState) {
            return new ServiceHealth($service, $response->successful() ? ServiceHealth::READY : ServiceHealth::ERROR, $latency);
        }

        $state = $response->json('status');
        $known = [ServiceHealth::READY, ServiceHealth::SERVICE_AVAILABLE, ServiceHealth::MODEL_LOADING, ServiceHealth::MODEL_NOT_INSTALLED, ServiceHealth::ERROR];
        $model = $response->json('model');

        return new ServiceHealth($service, in_array($state, $known, true) ? $state : ServiceHealth::ERROR, $latency, is_string($model) ? mb_substr($model, 0, 100) : null);
    }
}
