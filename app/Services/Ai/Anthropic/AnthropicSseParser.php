<?php

namespace App\Services\Ai\Anthropic;

use Generator;
use Psr\Http\Message\StreamInterface;

/** Decodifica Server-Sent Events da Anthropic em pares evento/dados. */
final class AnthropicSseParser
{
    /**
     * @return Generator<int, string>
     */
    public static function chunks(StreamInterface $stream, int $size = 2048): Generator
    {
        while (! $stream->eof()) {
            $chunk = $stream->read($size);
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /**
     * @param  iterable<string>  $chunks
     * @return Generator<int, array{event: string, data: array<string, mixed>|null}>
     */
    public function parse(iterable $chunks): Generator
    {
        $buffer = '';
        foreach ($chunks as $chunk) {
            $buffer .= str_replace("\r\n", "\n", $chunk);
            while (($position = strpos($buffer, "\n\n")) !== false) {
                $event = $this->decode(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 2);
                if ($event !== null) {
                    yield $event;
                }
            }
        }

        $event = $this->decode($buffer);
        if ($event !== null) {
            yield $event;
        }
    }

    /** @return array{event: string, data: array<string, mixed>|null}|null */
    private function decode(string $block): ?array
    {
        $name = null;
        $data = '';
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event:')) {
                $name = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data .= trim(substr($line, 5));
            }
        }
        if ($name === null && $data === '') {
            return null;
        }

        $decoded = $data === '' ? null : json_decode($data, true);

        return ['event' => $name ?? '', 'data' => is_array($decoded) ? $decoded : null];
    }
}
