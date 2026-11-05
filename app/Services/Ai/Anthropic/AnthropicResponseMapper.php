<?php

namespace App\Services\Ai\Anthropic;

use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;

/**
 * Converte o corpo de uma Message da Anthropic em TextGenerationResponse.
 *
 * Só blocos type=text são concatenados (thinking, tool_use e demais são ignorados).
 * stop_reason=refusal vira REJECTED; stop_reason=max_tokens devolve o texto parcial com metadata truncated=true
 * (decisão: o consumidor decide se aceita resposta truncada; não se perde o conteúdo já pago).
 */
final class AnthropicResponseMapper
{
    /**
     * @param  array<string, mixed>  $body
     *
     * @throws AiProviderException
     */
    public function map(array $body, string $requestedModel, string $correlationId, ?string $requestId): TextGenerationResponse
    {
        $content = $body['content'] ?? null;
        if (! is_array($content) || ! array_is_list($content)) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $correlationId, 200, $requestId);
        }

        $stopReason = is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : null;
        if ($stopReason === 'refusal') {
            throw new AiProviderException(AiProviderException::REJECTED, $correlationId, 200, $requestId);
        }

        $text = '';
        $hasTextBlock = false;
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
                $hasTextBlock = true;
            }
        }
        if (! $hasTextBlock) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $correlationId, 200, $requestId);
        }

        $usage = new TokenUsage(
            is_int($body['usage']['input_tokens'] ?? null) ? $body['usage']['input_tokens'] : null,
            is_int($body['usage']['output_tokens'] ?? null) ? $body['usage']['output_tokens'] : null,
        );

        return new TextGenerationResponse($text, is_string($body['model'] ?? null) ? $body['model'] : $requestedModel, $usage, [
            'provider' => 'anthropic',
            'stop_reason' => $stopReason,
            'truncated' => $stopReason === 'max_tokens',
            'provider_request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);
    }
}
