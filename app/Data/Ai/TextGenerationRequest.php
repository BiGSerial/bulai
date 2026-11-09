<?php

namespace App\Data\Ai;

final readonly class TextGenerationRequest
{
    /**
     * @param  array<string, scalar|null>  $metadata  Rótulos de rastreabilidade; nunca enviados ao provider.
     * @param  'low'|'medium'|'high'|null  $effort  Profundidade do raciocínio (Anthropic `output_config.effort`); nulo = padrão do modelo.
     */
    public function __construct(
        public string $prompt,
        public ?string $system = null,
        public ?float $temperature = null,
        public ?int $maxOutputTokens = null,
        public ?string $model = null,
        public array $metadata = [],
        public ?string $effort = null,
    ) {}
}
