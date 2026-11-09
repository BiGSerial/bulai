<?php

// Preços da Anthropic em USD por milhão de tokens (MTok), por ID de modelo; nulo ou ausente => custo não estimado.
// input = entrada fora do cache; cache_write = gravação no cache de 5 minutos (1,25x a entrada); cache_read = leitura
// do cache (0,1x no Sonnet, 0,05x no Opus 5.5 e 0,025x no Fable 5.1); output = saída, inclusive o raciocínio.
// Referência: tabela oficial consultada em 2026-11-03 (entrada/saída) e 2026-11-08 (cache). Revise ao trocar de modelo.
// Haiku: a documentação indica apenas "a partir de" 0,10/0,50; fica nulo até confirmação.
$anthropicPricing = [
    'reference' => '2026-11-08',
    'models' => [
        'claude-fable-5-1' => ['input' => 10.0, 'cache_write' => 12.5, 'cache_read' => 0.25, 'output' => 50.0],
        'claude-opus-5-5' => ['input' => 4.0, 'cache_write' => 5.0, 'cache_read' => 0.20, 'output' => 20.0],
        'claude-sonnet-5-5' => ['input' => 2.0, 'cache_write' => 2.5, 'cache_read' => 0.20, 'output' => 10.0],
        'claude-haiku-5-5' => ['input' => null, 'cache_write' => null, 'cache_read' => null, 'output' => null],
    ],
];

// Drivers no padrão OpenAI (OpenAI, gateways, servidores locais). `provider` é o nome gravado no consumo (vazio:
// `openai` se a URL for a da OpenAI, senão o nome do driver). Preços em USD/MTok por ID de modelo, no mesmo formato
// acima (input, cache_read, output); a OpenAI cobra a entrada lida do cache com desconto e o raciocínio como saída.
// Vazio = custo não estimado (o consumo em tokens continua registrado). Ex.: 'gpt-x' => ['input' => ..., 'cache_read' => ..., 'output' => ...].
$openAiCompatibleUsage = fn (string $prefix): array => [
    'provider' => env("{$prefix}_PROVIDER"),
    'pricing' => [
        'reference' => env("{$prefix}_PRICING_REFERENCE"),
        'models' => [],
    ],
];

return [
    'text' => [
        'default' => env('AI_TEXT_DRIVER'),
        'drivers' => [
            'openai_compatible' => [
                'base_url' => env('AI_TEXT_BASE_URL'),
                'api_key' => env('AI_TEXT_API_KEY'),
                'model' => env('AI_TEXT_MODEL'),
                'timeout' => (int) env('AI_TEXT_TIMEOUT', 60),
                'retries' => (int) env('AI_TEXT_RETRIES', 2),
                ...$openAiCompatibleUsage('AI_TEXT'),
            ],
            'anthropic' => [
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'api_key' => env('ANTHROPIC_API_KEY'),
                'api_version' => env('ANTHROPIC_API_VERSION', '2026-10-21'),
                // Sem modelo configurado o provedor responde NOT_CONFIGURED (não há padrão no código).
                'model' => env('ANTHROPIC_MODEL'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
                // System a partir deste tamanho vai com cache_control (mínimo cacheável do Sonnet 5.5: 512 tokens ≈ 1.500 caracteres).
                'cache_system_min_characters' => (int) env('ANTHROPIC_CACHE_SYSTEM_MIN_CHARACTERS', 1500),
                'timeout' => (int) env('ANTHROPIC_TIMEOUT', 90),
                'retries' => (int) env('ANTHROPIC_RETRIES', 2),
                // Backoff exponencial: base * 2^tentativa + jitter (ms). 429 só é reintentado com cabeçalho retry-after.
                'retry_base_ms' => (int) env('ANTHROPIC_RETRY_BASE_MS', 500),
                'retry_after_max_seconds' => (int) env('ANTHROPIC_RETRY_AFTER_MAX_SECONDS', 30),
                // Limites de consumo; nulo/0 = sem limite. Excedidos => erro controlado sem chamar a API.
                'limits' => [
                    'max_input_tokens' => env('ANTHROPIC_MAX_INPUT_TOKENS') !== null ? (int) env('ANTHROPIC_MAX_INPUT_TOKENS') : null,
                    'chars_per_token' => (float) env('ANTHROPIC_CHARS_PER_TOKEN', 3),
                    'daily_tokens' => env('ANTHROPIC_DAILY_TOKEN_BUDGET') !== null ? (int) env('ANTHROPIC_DAILY_TOKEN_BUDGET') : null,
                    'monthly_tokens' => env('ANTHROPIC_MONTHLY_TOKEN_BUDGET') !== null ? (int) env('ANTHROPIC_MONTHLY_TOKEN_BUDGET') : null,
                    'daily_usd' => env('ANTHROPIC_DAILY_USD_BUDGET') !== null ? (float) env('ANTHROPIC_DAILY_USD_BUDGET') : null,
                    'monthly_usd' => env('ANTHROPIC_MONTHLY_USD_BUDGET') !== null ? (float) env('ANTHROPIC_MONTHLY_USD_BUDGET') : null,
                ],
                'pricing' => $anthropicPricing,
            ],
        ],
    ],
    'embedding' => [
        'default' => env('AI_EMBEDDING_DRIVER'),
        // Tamanho de cada requisição de embeddings: por quantidade de trechos E por total de caracteres. O serviço local
        // (bge-m3 em CPU) calcula um texto por vez, então o tempo da requisição é a soma dos trechos; 4 trechos de bula
        // (~6.000 caracteres) levam ~30-60 s em 2 vCPU, bem abaixo do limite de tempo.
        'batch_size' => (int) env('AI_EMBEDDING_BATCH_SIZE', 4),
        'batch_characters' => (int) env('AI_EMBEDDING_BATCH_CHARACTERS', 6_000),
        'drivers' => [
            'openai_compatible' => [
                'base_url' => env('AI_EMBEDDING_BASE_URL'),
                'api_key' => env('AI_EMBEDDING_API_KEY'),
                'model' => env('AI_EMBEDDING_MODEL'),
                // Cobre uma requisição inteira (um lote); deve ser maior que LOCAL_AI_REQUEST_TIMEOUT do serviço `embeddings`.
                'timeout' => (int) env('AI_EMBEDDING_TIMEOUT', 180),
                'retries' => (int) env('AI_EMBEDDING_RETRIES', 2),
                ...$openAiCompatibleUsage('AI_EMBEDDING'),
            ],
        ],
    ],
    'vision' => [
        'default' => env('AI_VISION_DRIVER'),
        'drivers' => [
            'openai_compatible' => [
                'base_url' => env('AI_VISION_BASE_URL'),
                'api_key' => env('AI_VISION_API_KEY'),
                'model' => env('AI_VISION_MODEL'),
                'timeout' => (int) env('AI_VISION_TIMEOUT', 120),
                'retries' => (int) env('AI_VISION_RETRIES', 2),
                ...$openAiCompatibleUsage('AI_VISION'),
            ],
            // Visão via Anthropic (receita por foto): mesmas credenciais, limites e preços do driver de texto.
            // AI_VISION_MODEL pode apontar para outro modelo; vazio = ANTHROPIC_MODEL.
            'anthropic' => [
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'api_key' => env('ANTHROPIC_API_KEY'),
                'api_version' => env('ANTHROPIC_API_VERSION', '2026-10-21'),
                'model' => env('AI_VISION_MODEL') ?: env('ANTHROPIC_MODEL'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
                'timeout' => (int) env('AI_VISION_TIMEOUT', 120),
                'retries' => (int) env('ANTHROPIC_RETRIES', 2),
                'retry_base_ms' => (int) env('ANTHROPIC_RETRY_BASE_MS', 500),
                'retry_after_max_seconds' => (int) env('ANTHROPIC_RETRY_AFTER_MAX_SECONDS', 30),
                'limits' => [
                    'max_input_tokens' => env('ANTHROPIC_MAX_INPUT_TOKENS') !== null ? (int) env('ANTHROPIC_MAX_INPUT_TOKENS') : null,
                    'chars_per_token' => (float) env('ANTHROPIC_CHARS_PER_TOKEN', 3),
                    'daily_tokens' => env('ANTHROPIC_DAILY_TOKEN_BUDGET') !== null ? (int) env('ANTHROPIC_DAILY_TOKEN_BUDGET') : null,
                    'monthly_tokens' => env('ANTHROPIC_MONTHLY_TOKEN_BUDGET') !== null ? (int) env('ANTHROPIC_MONTHLY_TOKEN_BUDGET') : null,
                    'daily_usd' => env('ANTHROPIC_DAILY_USD_BUDGET') !== null ? (float) env('ANTHROPIC_DAILY_USD_BUDGET') : null,
                    'monthly_usd' => env('ANTHROPIC_MONTHLY_USD_BUDGET') !== null ? (float) env('ANTHROPIC_MONTHLY_USD_BUDGET') : null,
                ],
                'pricing' => $anthropicPricing,
            ],
        ],
    ],
    'local_ai' => [
        'url' => env('LOCAL_AI_URL'),
        // Token do endpoint administrativo de reinício; sem ele o botão de reiniciar fica indisponível.
        'admin_token' => env('LOCAL_AI_ADMIN_TOKEN'),
    ],
    'classifier' => [
        'default' => env('DOCUMENT_CLASSIFIER_DRIVER'),
        // Retaguarda (ex.: anthropic): chamada só quando o driver padrão falha, responde com confiança baixa ou não
        // acha o princípio ativo que faltava. Vazio = sem retaguarda.
        'fallback' => env('DOCUMENT_CLASSIFIER_FALLBACK_DRIVER'),
        'drivers' => [
            'llamafile' => [
                'base_url' => env('LOCAL_AI_URL') ? rtrim((string) env('LOCAL_AI_URL'), '/').'/v1' : null,
                'api_key' => env('LOCAL_AI_API_KEY'),
                'model' => env('LOCAL_AI_MODEL_NAME', 'local'),
                'timeout' => (int) env('LOCAL_AI_TIMEOUT', 90),
                'retries' => (int) env('LOCAL_AI_RETRIES', 0),
                'structured_output' => 'grammar',
                'provider' => 'llamafile',
            ],
            'openai_compatible' => [
                'base_url' => env('AI_CLASSIFIER_BASE_URL'),
                'api_key' => env('AI_CLASSIFIER_API_KEY'),
                'model' => env('AI_CLASSIFIER_MODEL'),
                'timeout' => (int) env('AI_CLASSIFIER_TIMEOUT', 60),
                'retries' => (int) env('AI_CLASSIFIER_RETRIES', 1),
                ...$openAiCompatibleUsage('AI_CLASSIFIER'),
            ],
            // Claude como classificador (retaguarda da IA local): mesmas credenciais, limites e preços do driver de texto.
            // AI_CLASSIFIER_MODEL pode apontar para um modelo mais barato; vazio = ANTHROPIC_MODEL.
            'anthropic' => [
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'api_key' => env('ANTHROPIC_API_KEY'),
                'api_version' => env('ANTHROPIC_API_VERSION', '2026-10-21'),
                'model' => env('AI_CLASSIFIER_MODEL') ?: env('ANTHROPIC_MODEL'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
                'timeout' => (int) env('AI_CLASSIFIER_TIMEOUT', 60),
                'retries' => (int) env('ANTHROPIC_RETRIES', 2),
                'retry_base_ms' => (int) env('ANTHROPIC_RETRY_BASE_MS', 500),
                'retry_after_max_seconds' => (int) env('ANTHROPIC_RETRY_AFTER_MAX_SECONDS', 30),
                'limits' => [
                    'max_input_tokens' => env('ANTHROPIC_MAX_INPUT_TOKENS') !== null ? (int) env('ANTHROPIC_MAX_INPUT_TOKENS') : null,
                    'chars_per_token' => (float) env('ANTHROPIC_CHARS_PER_TOKEN', 3),
                    'daily_tokens' => env('ANTHROPIC_DAILY_TOKEN_BUDGET') !== null ? (int) env('ANTHROPIC_DAILY_TOKEN_BUDGET') : null,
                    'monthly_tokens' => env('ANTHROPIC_MONTHLY_TOKEN_BUDGET') !== null ? (int) env('ANTHROPIC_MONTHLY_TOKEN_BUDGET') : null,
                    'daily_usd' => env('ANTHROPIC_DAILY_USD_BUDGET') !== null ? (float) env('ANTHROPIC_DAILY_USD_BUDGET') : null,
                    'monthly_usd' => env('ANTHROPIC_MONTHLY_USD_BUDGET') !== null ? (float) env('ANTHROPIC_MONTHLY_USD_BUDGET') : null,
                ],
                'pricing' => $anthropicPricing,
            ],
        ],
    ],
    'retry_sleep_ms' => (int) env('AI_RETRY_SLEEP_MS', 500),
    'max_input_characters' => (int) env('AI_MAX_INPUT_CHARACTERS', 200_000),
    'max_image_bytes' => (int) env('AI_MAX_IMAGE_BYTES', 10 * 1024 * 1024),
];
