<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aparelho do paciente inscrito em Web Push (endpoint do serviço de push do navegador). */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'user_agent', 'last_success_at', 'last_failure_at', 'failures'])]
class PushSubscription extends Model
{
    protected function casts(): array
    {
        return ['last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
