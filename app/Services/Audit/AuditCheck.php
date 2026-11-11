<?php

namespace App\Services\Audit;

/** Resultado de uma verificação de auditoria: o que foi checado, quantos registros violam a regra e exemplos (ids). */
final readonly class AuditCheck
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** @param list<int|string> $examples */
    public function __construct(
        public string $key,
        public string $label,
        public string $severity,
        public int $violations,
        public ?int $checked = null,
        public array $examples = [],
    ) {}

    /** @return array{key: string, label: string, severity: string, ok: bool, violations: int, checked: int|null, examples: list<int|string>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'severity' => $this->severity, 'ok' => $this->violations === 0, 'violations' => $this->violations, 'checked' => $this->checked, 'examples' => array_slice($this->examples, 0, 5)];
    }

    /**
     * @param  list<AuditCheck>  $checks
     * @return array{checks: int, passed: int, errors: int, warnings: int}
     */
    public static function summarize(array $checks): array
    {
        $failed = array_filter($checks, fn (self $check): bool => $check->violations > 0);

        return [
            'checks' => count($checks),
            'passed' => count($checks) - count($failed),
            'errors' => count(array_filter($failed, fn (self $check): bool => $check->severity === self::ERROR)),
            'warnings' => count(array_filter($failed, fn (self $check): bool => $check->severity === self::WARNING)),
        ];
    }
}
