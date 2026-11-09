<?php

namespace App\Data\Ai;

/**
 * Consumo de tokens de uma chamada, normalizado entre provedores, mais o objeto `usage` original da API.
 *
 * - inputTokens: toda a entrada processada, inclusive a parte lida ou gravada em cache.
 * - outputTokens: toda a saída cobrada, inclusive o raciocínio interno.
 * - cacheReadTokens / cacheWriteTokens: parte da entrada lida do cache / gravada no cache.
 * - reasoningTokens: parte da saída gasta em raciocínio, quando o provedor informa (a Anthropic não separa).
 * - raw: o objeto `usage` como a API devolveu, sem alteração.
 */
final readonly class TokenUsage
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
        public ?int $reasoningTokens = null,
        public array $raw = [],
    ) {}

    /**
     * Anthropic: input_tokens conta só a entrada fora do cache; a entrada total soma as leituras e gravações.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromAnthropic(?array $usage): self
    {
        $usage ??= [];
        $uncached = self::integer($usage['input_tokens'] ?? null);
        $cacheRead = self::integer($usage['cache_read_input_tokens'] ?? null);
        $cacheWrite = self::integer($usage['cache_creation_input_tokens'] ?? null);

        return new self(
            $uncached === null ? null : $uncached + ($cacheRead ?? 0) + ($cacheWrite ?? 0),
            self::integer($usage['output_tokens'] ?? null),
            $cacheRead,
            $cacheWrite,
            null,
            $usage,
        );
    }

    /**
     * Padrão OpenAI (chat/completions): prompt_tokens já inclui o que veio do cache; completion_tokens inclui o raciocínio.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromOpenAi(?array $usage): self
    {
        $usage ??= [];

        return new self(
            self::integer($usage['prompt_tokens'] ?? null),
            self::integer($usage['completion_tokens'] ?? null),
            self::integer($usage['prompt_tokens_details']['cached_tokens'] ?? null),
            null,
            self::integer($usage['completion_tokens_details']['reasoning_tokens'] ?? null),
            $usage,
        );
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
