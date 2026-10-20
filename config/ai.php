<?php

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
            ],
        ],
    ],
    'embedding' => [
        'default' => env('AI_EMBEDDING_DRIVER'),
        'drivers' => [
            'openai_compatible' => [
                'base_url' => env('AI_EMBEDDING_BASE_URL'),
                'api_key' => env('AI_EMBEDDING_API_KEY'),
                'model' => env('AI_EMBEDDING_MODEL'),
                'timeout' => (int) env('AI_EMBEDDING_TIMEOUT', 60),
                'retries' => (int) env('AI_EMBEDDING_RETRIES', 2),
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
            ],
        ],
    ],
    'local_ai' => [
        'url' => env('LOCAL_AI_URL'),
    ],
    'classifier' => [
        'default' => env('DOCUMENT_CLASSIFIER_DRIVER'),
        'drivers' => [
            'llamafile' => [
                'base_url' => env('LOCAL_AI_URL') ? rtrim((string) env('LOCAL_AI_URL'), '/').'/v1' : null,
                'api_key' => env('LOCAL_AI_API_KEY'),
                'model' => env('LOCAL_AI_MODEL_NAME', 'local'),
                'timeout' => (int) env('LOCAL_AI_TIMEOUT', 90),
                'retries' => (int) env('LOCAL_AI_RETRIES', 0),
                'structured_output' => 'grammar',
            ],
            'openai_compatible' => [
                'base_url' => env('AI_CLASSIFIER_BASE_URL'),
                'api_key' => env('AI_CLASSIFIER_API_KEY'),
                'model' => env('AI_CLASSIFIER_MODEL'),
                'timeout' => (int) env('AI_CLASSIFIER_TIMEOUT', 60),
                'retries' => (int) env('AI_CLASSIFIER_RETRIES', 1),
            ],
        ],
    ],
    'retry_sleep_ms' => (int) env('AI_RETRY_SLEEP_MS', 500),
    'max_input_characters' => (int) env('AI_MAX_INPUT_CHARACTERS', 200_000),
    'max_image_bytes' => (int) env('AI_MAX_IMAGE_BYTES', 10 * 1024 * 1024),
];
