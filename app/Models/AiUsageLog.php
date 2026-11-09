<?php

namespace App\Models;

use Database\Factories\AiUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de consumo por requisição a um provedor de IA.
 *
 * Nunca guarda prompt, resposta nem chave de API: apenas métricas, identificadores técnicos, o id de quem gerou a
 * chamada (user_id, nulo para rotinas e avaliações) e o objeto `usage` devolvido pelo provedor (provider_usage).
 * input_tokens é a entrada total; cache_read_tokens e cache_write_tokens detalham a parte que passou pelo cache.
 */
#[Fillable([
    'user_id', 'origin', 'provider', 'model', 'response_model', 'run_id', 'capability', 'input_tokens', 'output_tokens',
    'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'provider_usage', 'latency_ms',
    'status', 'error_code', 'http_status', 'provider_request_id', 'estimated_cost_usd', 'pricing_reference',
])]
class AiUsageLog extends Model
{
    /** @use HasFactory<AiUsageLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_usage' => 'array',
            'latency_ms' => 'integer',
            'http_status' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }
}
