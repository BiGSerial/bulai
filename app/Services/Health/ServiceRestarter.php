<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pede o reinício de um serviço auxiliar. O Laravel não tem acesso ao Docker (fronteira de confiança):
 * o próprio serviço se reinicia (OCR encerra o processo e o orquestrador o sobe; a IA reinicia o llamafile).
 */
final class ServiceRestarter
{
    public const SERVICES = ['local_ai', 'ocr'];

    /** @return array{ok: bool, message: string} */
    public function restart(string $service): array
    {
        [$url, $token] = $this->target($service);
        if (blank($url) || blank($token)) {
            return ['ok' => false, 'message' => 'Reinício não configurado para este serviço (token administrativo ausente).'];
        }

        try {
            $response = Http::withToken((string) $token)->acceptJson()->timeout(5)->connectTimeout(2)
                ->post(rtrim((string) $url, '/').'/admin/restart');
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Não foi possível contatar o serviço para reiniciar.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'O serviço recusou o pedido de reinício.'];
        }

        Cache::forget("service-health:{$service}");

        return ['ok' => true, 'message' => 'Reinício solicitado. O serviço volta em instantes.'];
    }

    public function canRestart(string $service): bool
    {
        [$url, $token] = $this->target($service);

        return filled($url) && filled($token);
    }

    /** @return array{0: mixed, 1: mixed} */
    private function target(string $service): array
    {
        return match ($service) {
            'local_ai' => [config('ai.local_ai.url'), config('ai.local_ai.admin_token')],
            'ocr' => [config('document_ocr.drivers.http.url'), config('document_ocr.drivers.http.token')],
            default => [null, null],
        };
    }
}
