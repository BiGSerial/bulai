<?php

namespace App\Services\Ai;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use Generator;

/**
 * Capacidade opcional de streaming; provedores que a suportam implementam também TextGenerationProvider.
 *
 * Cada item gerado é um fragmento de texto. Ao final, Generator::getReturn() entrega a TextGenerationResponse
 * completa (texto concatenado, uso de tokens e metadados). Erros lançam AiProviderException durante a iteração,
 * inclusive os enviados pelo provedor depois do início do stream.
 */
interface StreamingTextGenerationProvider
{
    /**
     * @return Generator<int, string, mixed, TextGenerationResponse>
     *
     * @throws AiProviderException
     */
    public function stream(TextGenerationRequest $request): Generator;
}
