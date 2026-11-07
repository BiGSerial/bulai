<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Web Push sem dependência externa: autenticação VAPID (RFC 8292, JWT ES256 assinado com `openssl`) e push SEM conteúdo.
 * Um push vazio acorda o service worker do aparelho, que busca o texto do aviso em `/push/ultimo` e o mostra; assim não
 * é preciso a criptografia de payload (RFC 8291) nem guardar chaves do aparelho. Endpoints que respondem 404/410 expiraram
 * e são apagados.
 *
 * Chaves: `php artisan webpush:generate-keys` imprime as linhas do `app.env` (WEBPUSH_PUBLIC_KEY, WEBPUSH_PRIVATE_KEY, WEBPUSH_SUBJECT).
 */
final class WebPushSender
{
    public function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key')) && filled(config('services.webpush.subject'));
    }

    public function publicKey(): string
    {
        return (string) config('services.webpush.public_key');
    }

    /** @return array{private_pem: string, public_key: string} */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $ec = openssl_pkey_get_details($key)['ec'];
        $public = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return ['private_pem' => $pem, 'public_key' => self::base64Url($public)];
    }

    /** Envia o push vazio a um aparelho. Devolve true quando o serviço de push aceitou. */
    public function send(PushSubscription $subscription): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'vapid t='.$this->jwt($subscription->endpoint).', k='.$this->publicKey(),
                'TTL' => '3600',
                'Urgency' => 'high',
            ])->timeout(10)->withBody('', 'application/octet-stream')->post($subscription->endpoint);
        } catch (Throwable $exception) {
            $this->failed($subscription);
            Log::warning('web_push_failed', ['subscription_id' => $subscription->id, 'error' => $exception::class]);

            return false;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }
        if (! $response->successful()) {
            $this->failed($subscription);
            Log::warning('web_push_rejected', ['subscription_id' => $subscription->id, 'status' => $response->status()]);

            return false;
        }
        $subscription->forceFill(['last_success_at' => now(), 'failures' => 0])->save();

        return true;
    }

    private function failed(PushSubscription $subscription): void
    {
        $subscription->forceFill(['last_failure_at' => now(), 'failures' => $subscription->failures + 1])->save();
        if ($subscription->failures >= 10) {
            $subscription->delete();
        }
    }

    private function jwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $header = self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64Url((string) json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => (string) config('services.webpush.subject')]));
        $input = $header.'.'.$claims;
        $pem = base64_decode((string) config('services.webpush.private_key'), true) ?: '';
        openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::base64Url(self::derToRaw((string) $der));
    }

    /** Assinatura ECDSA em DER (SEQUENCE de dois INTEGER) → r‖s de 64 bytes, como o JWT exige. */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7F) : 0);
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $raw .= str_pad(ltrim($integer, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
