<?php

namespace App\Models\Knowledge;

use Database\Factories\Knowledge\KnowledgeProcessingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma etapa do processamento de uma bula (ver App\Services\Knowledge\ProcessingTimeline). */
#[Fillable(['document_id', 'step', 'state', 'started_at', 'finished_at', 'duration_ms', 'detail'])]
class KnowledgeProcessingEvent extends Model
{
    /** @use HasFactory<KnowledgeProcessingEventFactory> */
    use HasFactory;

    public const STEP_READ_NATIVE = 'read_native';

    public const STEP_OCR = 'ocr';

    public const STEP_NORMALIZE = 'normalize';

    public const STEP_CHUNK = 'chunk';

    public const STEP_EMBED = 'embed';

    public const STEP_LINK = 'link';

    public const STATE_RUNNING = 'running';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    public const STATE_SKIPPED = 'skipped';

    /** Ordem natural das etapas e nome exibido. @return array<string, string> */
    public static function stepLabels(): array
    {
        return [
            self::STEP_READ_NATIVE => 'Leitura do texto do PDF',
            self::STEP_OCR => 'Leitura por imagem (OCR)',
            self::STEP_NORMALIZE => 'Normalização do texto',
            self::STEP_CHUNK => 'Seções e trechos',
            self::STEP_EMBED => 'Índice de busca (embeddings)',
            self::STEP_LINK => 'Vínculo com o princípio ativo',
        ];
    }

    public function stepLabel(): string
    {
        return self::stepLabels()[$this->step] ?? $this->step;
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_RUNNING => 'Em andamento',
            self::STATE_DONE => 'Concluída',
            self::STATE_FAILED => 'Falhou',
            default => 'Pulada',
        };
    }

    public function tone(): string
    {
        return match ($this->state) {
            self::STATE_RUNNING => 'info',
            self::STATE_DONE => 'success',
            self::STATE_FAILED => 'danger',
            default => 'neutral',
        };
    }

    public function isRunning(): bool
    {
        return $this->state === self::STATE_RUNNING;
    }

    /** Resumo curto dos detalhes para a tela ("11 páginas · 108 s · PaddleOCR"). */
    public function summary(): string
    {
        $detail = $this->detail ?? [];
        $parts = [];
        $int = fn (string $key): ?int => is_numeric($detail[$key] ?? null) ? (int) $detail[$key] : null;
        if (($pages = $int('pages')) !== null) {
            $parts[] = $pages === 1 ? '1 página' : "{$pages} páginas";
        }
        if (($page = $int('page')) !== null && $pages !== null && $this->isRunning()) {
            $parts[] = "página {$page} de {$pages}";
        }
        if (($chunks = $int('chunks')) !== null) {
            $done = $int('chunks_done');
            $parts[] = $this->isRunning() && $done !== null ? "{$done} de {$chunks} trechos" : ($chunks === 1 ? '1 trecho' : "{$chunks} trechos");
        }
        if (($batch = $int('batch')) !== null && ($batches = $int('batches')) !== null && $this->isRunning()) {
            $parts[] = "lote {$batch} de {$batches}";
        }
        if (($sections = $int('sections')) !== null) {
            $parts[] = $sections === 1 ? '1 seção' : "{$sections} seções";
        }
        $quality = $detail['quality_after'] ?? $detail['quality_score'] ?? null;
        if (is_numeric($quality)) {
            $parts[] = 'qualidade '.number_format((float) $quality, 2, ',', '');
        }
        if (is_string($detail['engine'] ?? null) && $detail['engine'] !== '') {
            $parts[] = $detail['engine'];
        }
        if (is_string($detail['model'] ?? null) && $detail['model'] !== '') {
            $parts[] = $detail['model'];
        }
        if (($ingredients = $int('ingredients')) !== null) {
            $parts[] = $ingredients === 1 ? '1 princípio ativo' : "{$ingredients} princípios ativos";
        }
        if (is_string($detail['error_code'] ?? null)) {
            $parts[] = $detail['error_code'];
        }

        return implode(' · ', $parts);
    }

    /** Explicação em português do código de erro da etapa (nulo sem erro ou para código desconhecido). */
    public function errorLabel(): ?string
    {
        $code = $this->detail['error_code'] ?? null;

        return is_string($code) ? (self::errorLabels()[$code] ?? null) : null;
    }

    /** @return array<string, string> */
    public static function errorLabels(): array
    {
        return [
            'AI_TIMEOUT' => 'o serviço de IA demorou mais que o limite de tempo de uma requisição',
            'AI_UNAVAILABLE' => 'o serviço de IA não respondeu ou devolveu erro interno',
            'AI_NOT_CONFIGURED' => 'o provedor de IA não está configurado',
            'AI_RATE_LIMITED' => 'o provedor de IA limitou a taxa de requisições',
            'AI_AUTH_FAILED' => 'a credencial do provedor de IA foi recusada',
            'AI_INVALID_RESPONSE' => 'o serviço de IA devolveu uma resposta fora do contrato',
            'AI_INVALID_REQUEST' => 'a requisição ao serviço de IA foi considerada inválida',
            'EMBEDDING_INCOMPLETE' => 'alguns trechos ficaram sem vetor',
            'INDEX_JOB_FAILED' => 'o job do índice foi interrompido (limite de tempo do worker ou erro inesperado)',
            'POST_PROCESSING_ERROR' => 'erro inesperado após a geração dos trechos',
        ];
    }

    public function durationLabel(): ?string
    {
        $ms = $this->duration_ms;
        if ($ms === null && $this->isRunning()) {
            $ms = max(0, (int) $this->started_at->diffInMilliseconds(now()));
        }
        if ($ms === null) {
            return null;
        }

        return $ms < 1000 ? "{$ms} ms" : number_format($ms / 1000, $ms < 10_000 ? 1 : 0, ',', '').' s';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    protected static function newFactory(): KnowledgeProcessingEventFactory
    {
        return KnowledgeProcessingEventFactory::new();
    }
}
