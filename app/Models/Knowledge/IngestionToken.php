<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'token_hash', 'scope', 'expires_at', 'last_used_at', 'revoked_at'])]
class IngestionToken extends Model
{
    public const SCOPE_KNOWLEDGE_INGEST = 'knowledge:ingest';

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

    public static function findValid(string $plainToken, string $scope): ?self
    {
        if ($plainToken === '') {
            return null;
        }

        return self::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('scope', $scope)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
