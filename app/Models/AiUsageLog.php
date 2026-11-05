<?php

namespace App\Models;

use Database\Factories\AiUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de consumo por requisição a um provedor de IA.
 *
 * Nunca guarda prompt, resposta, chave de API nem dados do usuário: apenas métricas e identificadores técnicos.
 */
#[Fillable([
    'provider', 'model', 'run_id', 'capability', 'input_tokens', 'output_tokens', 'latency_ms',
    'status', 'error_code', 'http_status', 'provider_request_id', 'estimated_cost_usd', 'pricing_reference',
])]
class AiUsageLog extends Model
{
    /** @use HasFactory<AiUsageLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'http_status' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }
}
