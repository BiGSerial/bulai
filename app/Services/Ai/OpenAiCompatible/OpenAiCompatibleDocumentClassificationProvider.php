<?php

namespace App\Services\Ai\OpenAiCompatible;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\DocumentClassificationProvider;

/**
 * Classificador da ingestão sobre qualquer servidor compatível com a API de chat (llamafile, llama.cpp, Ollama, vLLM...).
 * Não é a IA principal do aplicativo: só resolve ambiguidades de identificação de documentos.
 */
final class OpenAiCompatibleDocumentClassificationProvider implements DocumentClassificationProvider
{
    private const SYSTEM_PROMPT = 'Você identifica bulas de medicamentos. Use SOMENTE os candidatos e trechos fornecidos; '
        .'não invente nomes. Responda apenas com um objeto JSON com exatamente as chaves product_name, active_ingredient, '
        .'document_type (bula_paciente, bula_profissional ou null), confidence (número de 0 a 1) e evidence (lista de até 3 trechos literais, cada um com no máximo 120 caracteres). '
        .'Use null quando não houver evidência.';

    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    public function classify(DocumentClassificationRequest $request): DocumentClassificationResponse
    {
        $startedAt = hrtime(true);
        $model = $this->client->defaultModel() ?? 'local';
        $payload = json_encode($request->toPayload(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (mb_strlen($payload) > (int) config('ai.max_input_characters')) {
            throw new AiProviderException(AiProviderException::INVALID_REQUEST);
        }

        $body = $this->client->post('chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 700,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $payload],
            ],
            'response_format' => $this->responseFormat(),
        ], 'classify', $model);

        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content)) {
            throw $this->client->invalidResponse('classify', $model, $startedAt);
        }

        try {
            $response = DocumentClassificationResponse::fromModelOutput($content, is_string($body['model'] ?? null) ? mb_substr($body['model'], 0, 100) : $model, [
                'provider' => $this->client->provider,
                'prompt' => ['system' => self::SYSTEM_PROMPT, 'temperature' => 0, 'max_tokens' => 700, 'structured_output' => $this->client->option('structured_output') === 'grammar' ? 'grammar' : 'json_schema'],
                'raw_response' => mb_substr($content, 0, 4000),
            ]);
        } catch (AiProviderException) {
            throw $this->client->invalidResponse('classify', $model, $startedAt);
        }

        $this->client->logSuccess('classify', $response->model, null, $startedAt);

        return $response;
    }

    /**
     * llamafile/llama.cpp aplicam a gramática com `json_object` + `schema`; o formato OpenAI `json_schema` é ignorado por eles.
     *
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        if ($this->client->option('structured_output') === 'grammar') {
            return ['type' => 'json_object', 'schema' => DocumentClassificationResponse::jsonSchema()];
        }

        return ['type' => 'json_schema', 'json_schema' => ['name' => 'document_classification', 'strict' => true, 'schema' => DocumentClassificationResponse::jsonSchema()]];
    }
}
