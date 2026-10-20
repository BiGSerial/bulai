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
    ) {}

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

    /** @return array{service: string, state: string, label: string, latency_ms: ?int, model: ?string} */
    public function toArray(): array
    {
        return ['service' => $this->service, 'state' => $this->state, 'label' => $this->label(), 'latency_ms' => $this->latencyMs, 'model' => $this->model];
    }
}
