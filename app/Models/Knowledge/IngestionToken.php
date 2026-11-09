<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'token_hash', 'scope', 'expires_at', 'last_used_at', 'revoked_at'])]
class IngestionToken extends Model
{
    public const SCOPE_KNOWLEDGE_INGEST = 'knowledge:ingest';

    /** API de leitura: estatísticas, consumo de IA e rastreio das perguntas (`/api/v1`). */
    public const SCOPE_API_READ = 'api:read';

    /** Leitura e, além dela, avaliação: faz perguntas reais ao Perguntar (consome IA) com a conta de avaliação. */
    public const SCOPE_API_EVALUATE = 'api:evaluate';

    /** Escopos que o admin emite pela tela "Acesso à API". */
    public const API_SCOPES = [self::SCOPE_API_READ, self::SCOPE_API_EVALUATE];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function issue(string $name, string $scope, mixed $expiresAt): array
    {
        $plainToken = Str::random(64);

        $token = self::create([
            'name' => $name,
            'scope' => $scope,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        return [$token, $plainToken];
    }

    /** @param  string|list<string>  $scope  um escopo ou qualquer um entre vários */
    public static function findValid(string $plainToken, string|array $scope): ?self
    {
        if ($plainToken === '') {
            return null;
        }

        return self::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereIn('scope', (array) $scope)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
