<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Instalação do aplicativo Android ligada à conta (docs/app-android.md). O token em texto só existe na resposta do
 * registro; o banco guarda o sha256. Registrar de novo a mesma instalação troca o token (o antigo deixa de valer).
 */
#[Fillable(['user_id', 'installation_id', 'token_hash', 'platform', 'device_name', 'app_version', 'last_seen_at'])]
#[Hidden(['token_hash'])]
class MobileDevice extends Model
{
    public const TOKEN_PREFIX = 'bulai_app_';

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashFor(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Cria (ou renova) o aparelho da instalação e devolve o token em texto, mostrado uma única vez.
     *
     * @param  array{installation_id: string, device_name?: string|null, app_version?: string|null}  $data
     * @return array{device: self, token: string}
     */
    public static function issueFor(User $user, array $data): array
    {
        $token = self::TOKEN_PREFIX.Str::random(48);
        $device = self::query()->updateOrCreate(
            ['user_id' => $user->id, 'installation_id' => $data['installation_id']],
            [
                'token_hash' => self::hashFor($token),
                'platform' => 'android',
                'device_name' => isset($data['device_name']) ? mb_substr($data['device_name'], 0, 120) : null,
                'app_version' => isset($data['app_version']) ? mb_substr($data['app_version'], 0, 40) : null,
                'last_seen_at' => now(),
            ],
        );

        return ['device' => $device, 'token' => $token];
    }

    public static function findByToken(string $plainToken): ?self
    {
        if (! str_starts_with($plainToken, self::TOKEN_PREFIX)) {
            return null;
        }

        return self::query()->with('user')->where('token_hash', self::hashFor($plainToken))->first();
    }
}
