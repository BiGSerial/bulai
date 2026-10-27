<?php

namespace App\Data\Health;

final readonly class ServiceHealth
{
    public const READY = 'READY';

    public const SERVICE_AVAILABLE = 'SERVICE_AVAILABLE';

    public const MODEL_LOADING = 'MODEL_LOADING';

    public const MODEL_NOT_INSTALLED = 'MODEL_NOT_INSTALLED';

    public const ERROR = 'ERROR';

    public const UNREACHABLE = 'UNREACHABLE';

    public const DISABLED = 'DISABLED';

    public function __construct(
        public string $service,
        public string $state,
        public ?int $latencyMs = null,
        public ?string $model = null,
        public ?bool $busy = null,
        public ?int $activeRequests = null,
        public ?int $currentSeconds = null,
        public ?int $restarts = null,
    ) {}

    /** Verdadeiro quando o serviço informou que está processando algo agora. */
    public function isWorking(): bool
    {
        return $this->busy === true;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $service, array $data): self
    {
        $int = fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            $service,
            (string) $data['state'],
            $int('latencyMs'),
            is_string($data['model'] ?? null) ? $data['model'] : null,
            is_bool($data['busy'] ?? null) ? $data['busy'] : null,
            $int('activeRequests'),
            $int('currentSeconds'),
            $int('restarts'),
        );
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
            self::UNREACHABLE => 'Inacessível',
            default => 'Desativada',
        };
    }

    public function tone(): string
    {
        return match ($this->state) {
            self::READY => 'success',
            self::SERVICE_AVAILABLE, self::MODEL_LOADING => 'info',
            self::MODEL_NOT_INSTALLED => 'warning',
            self::ERROR, self::UNREACHABLE => 'danger',
            default => 'neutral',
        };
    }

    /** @return array{service: string, state: string, label: string, latency_ms: ?int, model: ?string, busy: ?bool, active_requests: ?int, current_seconds: ?int, restarts: ?int} */
    public function toArray(): array
    {
        return ['service' => $this->service, 'state' => $this->state, 'label' => $this->label(), 'latency_ms' => $this->latencyMs, 'model' => $this->model, 'busy' => $this->busy, 'active_requests' => $this->activeRequests, 'current_seconds' => $this->currentSeconds, 'restarts' => $this->restarts];
    }
}
