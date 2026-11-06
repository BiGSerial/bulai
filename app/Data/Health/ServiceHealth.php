<?php

namespace App\Data\Health;

final readonly class ServiceHealth
{
    public const READY = 'READY';

    public const SERVICE_AVAILABLE = 'SERVICE_AVAILABLE';

    public const MODEL_LOADING = 'MODEL_LOADING';

    public const MODEL_NOT_INSTALLED = 'MODEL_NOT_INSTALLED';

    public const ERROR = 'ERROR';

    /** Não respondeu à última sondagem, mas ainda não deu para afirmar que caiu (serviço ocupado ou lento). */
    public const UNRESPONSIVE = 'UNRESPONSIVE';

    public const UNREACHABLE = 'UNREACHABLE';

    public const DISABLED = 'DISABLED';

    /**
     * @param  ?array{page: int, total: int}  $progress  Página atual do OCR, quando o serviço informa.
     * @param  ?int  $observedAt  Quando esta leitura foi feita (timestamp).
     * @param  ?int  $respondedAt  Última vez que o serviço respondeu (timestamp); é a referência de "sem resposta há N s".
     * @param  int  $failures  Sondagens seguidas sem resposta.
     */
    public function __construct(
        public string $service,
        public string $state,
        public ?int $latencyMs = null,
        public ?string $model = null,
        public ?bool $busy = null,
        public ?int $activeRequests = null,
        public ?int $currentSeconds = null,
        public ?int $restarts = null,
        public ?array $progress = null,
        public ?string $correlationId = null,
        public ?int $observedAt = null,
        public ?int $respondedAt = null,
        public int $failures = 0,
    ) {}

    /** Verdadeiro quando o serviço informou que está processando algo agora. */
    public function isWorking(): bool
    {
        return $this->busy === true;
    }

    /** @param array<string, mixed> $data Formato de toArray() (snake_case) ou o antigo de get_object_vars (camelCase). */
    public static function fromArray(string $service, array $data): self
    {
        $int = fn (string $snake, string $camel): ?int => is_int($data[$snake] ?? $data[$camel] ?? null) ? ($data[$snake] ?? $data[$camel]) : null;
        $progress = $data['progress'] ?? null;

        return new self(
            $service,
            (string) $data['state'],
            $int('latency_ms', 'latencyMs'),
            is_string($data['model'] ?? null) ? $data['model'] : null,
            is_bool($data['busy'] ?? null) ? $data['busy'] : null,
            $int('active_requests', 'activeRequests'),
            $int('current_seconds', 'currentSeconds'),
            $int('restarts', 'restarts'),
            is_array($progress) && is_int($progress['page'] ?? null) && is_int($progress['total'] ?? null) ? ['page' => $progress['page'], 'total' => $progress['total']] : null,
            is_string($data['correlation_id'] ?? $data['correlationId'] ?? null) ? ($data['correlation_id'] ?? $data['correlationId']) : null,
            $int('observed_at', 'observedAt'),
            $int('responded_at', 'respondedAt'),
            $int('failures', 'failures') ?? 0,
        );
    }

    /** Leitura bem-sucedida feita agora: zera as falhas e marca quando o serviço respondeu. */
    public function observed(int $now): self
    {
        return $this->with(['observedAt' => $now, 'respondedAt' => $now, 'failures' => 0]);
    }

    /**
     * Sondagem sem resposta: até o limite de falhas seguidas o serviço é tratado como ocupado/lento, preservando a última
     * leitura boa (modelo, ocupação, página do OCR); a partir dele passa a inacessível.
     */
    public static function unanswered(string $service, ?self $previous, int $now, int $failuresBeforeUnreachable): self
    {
        $failures = ($previous?->failures ?? 0) + 1;
        $base = $previous !== null && $previous->state !== self::UNREACHABLE ? $previous : new self($service, self::UNRESPONSIVE);

        return $base->with([
            'state' => $failures >= $failuresBeforeUnreachable ? self::UNREACHABLE : self::UNRESPONSIVE,
            'latencyMs' => null,
            'observedAt' => $now,
            'failures' => $failures,
        ]);
    }

    public function isReady(): bool
    {
        return $this->state === self::READY;
    }

    public function label(): string
    {
        return match ($this->state) {
            self::READY => 'Pronta',
            self::SERVICE_AVAILABLE => 'Iniciando',
            self::MODEL_LOADING => 'Carregando modelo',
            self::MODEL_NOT_INSTALLED => 'Modelo não instalado',
            self::ERROR => 'Com erro',
            self::UNRESPONSIVE => $this->isWorking() ? 'Ocupado, sem resposta'.$this->since($this->respondedAt) : 'Sem resposta',
            self::UNREACHABLE => 'Inacessível',
            default => 'Desativada',
        };
    }

    /** Complemento curto do rótulo: página do OCR, há quanto tempo processa ou quando foi lido. */
    public function hint(): ?string
    {
        if ($this->progress !== null) {
            $seconds = $this->currentSeconds !== null ? ' · '.$this->currentSeconds.' s' : '';

            return 'Página '.$this->progress['page'].' de '.$this->progress['total'].$seconds;
        }
        if ($this->state === self::UNRESPONSIVE) {
            return $this->respondedAt === null ? null : 'Última resposta'.$this->since($this->respondedAt);
        }
        if ($this->isWorking()) {
            return $this->currentSeconds === null ? 'Processando' : 'Processando há '.$this->currentSeconds.' s';
        }

        return $this->observedAt === null ? null : 'Lido'.$this->since($this->observedAt);
    }

    public function tone(): string
    {
        return match ($this->state) {
            self::READY => 'success',
            self::SERVICE_AVAILABLE, self::MODEL_LOADING => 'info',
            self::MODEL_NOT_INSTALLED, self::UNRESPONSIVE => 'warning',
            self::ERROR, self::UNREACHABLE => 'danger',
            default => 'neutral',
        };
    }

    /** @return array{service: string, state: string, label: string, hint: ?string, latency_ms: ?int, model: ?string, busy: ?bool, active_requests: ?int, current_seconds: ?int, restarts: ?int, progress: ?array{page: int, total: int}, correlation_id: ?string, observed_at: ?int, responded_at: ?int, failures: int} */
    public function toArray(): array
    {
        return [
            'service' => $this->service, 'state' => $this->state, 'label' => $this->label(), 'hint' => $this->hint(),
            'latency_ms' => $this->latencyMs, 'model' => $this->model, 'busy' => $this->busy, 'active_requests' => $this->activeRequests,
            'current_seconds' => $this->currentSeconds, 'restarts' => $this->restarts, 'progress' => $this->progress,
            'correlation_id' => $this->correlationId, 'observed_at' => $this->observedAt, 'responded_at' => $this->respondedAt, 'failures' => $this->failures,
        ];
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    private function since(?int $timestamp): string
    {
        return $timestamp === null ? '' : ' há '.max(0, time() - $timestamp).' s';
    }
}
