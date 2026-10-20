<?php

namespace App\Data\Ai;

use DateTimeImmutable;

/**
 * Identifica a validade de um embedding: ele só vale para a combinação
 * conteúdo (hash) + provider + modelo (+ dimensões).
 */
final readonly class EmbeddingMetadata
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $dimensions,
        public string $generatedAt,
        public string $contentHash,
    ) {}

    public static function forContent(string $content, string $provider, EmbeddingResponse $response, ?DateTimeImmutable $generatedAt = null): self
    {
        return new self($provider, $response->model, $response->dimensions(), ($generatedAt ?? new DateTimeImmutable)->format(DATE_ATOM), self::hashContent($content));
    }

    public static function hashContent(string $content): string
    {
        return hash('sha256', $content);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        foreach (['provider', 'model', 'generated_at', 'content_hash'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                return null;
            }
        }

        return is_int($data['dimensions'] ?? null)
            ? new self($data['provider'], $data['model'], $data['dimensions'], $data['generated_at'], $data['content_hash'])
            : null;
    }

    public function isObsoleteFor(string $currentContent, string $currentProvider, string $currentModel): bool
    {
        return $this->contentHash !== self::hashContent($currentContent)
            || $this->provider !== $currentProvider
            || $this->model !== $currentModel;
    }

    /** @return array{provider: string, model: string, dimensions: int, generated_at: string, content_hash: string} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'generated_at' => $this->generatedAt,
            'content_hash' => $this->contentHash,
        ];
    }
}
