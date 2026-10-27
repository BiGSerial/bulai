<?php

return [
    'enabled' => (bool) env('DOCUMENT_OCR_ENABLED', true),
    'driver' => env('DOCUMENT_OCR_DRIVER', 'http'),
    'drivers' => [
        'http' => [
            'url' => env('DOCUMENT_OCR_URL'),
            'token' => env('DOCUMENT_OCR_TOKEN'),
            'timeout' => (int) env('DOCUMENT_OCR_TIMEOUT', 300),
            'connect_timeout' => (int) env('DOCUMENT_OCR_CONNECT_TIMEOUT', 5),
            'retries' => (int) env('DOCUMENT_OCR_RETRIES', 1),
            'retry_sleep_ms' => (int) env('DOCUMENT_OCR_RETRY_SLEEP_MS', 500),
            'max_pdf_bytes' => (int) env('DOCUMENT_OCR_MAX_PDF_BYTES', 25 * 1024 * 1024),
        ],
    ],
    // OCR complementar das N primeiras páginas (capa/identificação) quando a heurística, mesmo com texto nativo, não achou candidatos confiáveis. 0 = desativado.
    'supplement_pages' => (int) env('DOCUMENT_OCR_SUPPLEMENT_PAGES', 3),
    'quality' => [
        'min_score' => (float) env('DOCUMENT_QUALITY_MIN_SCORE', 0.6),
        'min_characters' => (int) env('DOCUMENT_QUALITY_MIN_CHARACTERS', 400),
        'min_characters_per_page' => (int) env('DOCUMENT_QUALITY_MIN_CHARACTERS_PER_PAGE', 150),
        'max_empty_page_ratio' => (float) env('DOCUMENT_QUALITY_MAX_EMPTY_PAGE_RATIO', 0.5),
        'max_invalid_character_ratio' => (float) env('DOCUMENT_QUALITY_MAX_INVALID_CHARACTER_RATIO', 0.05),
        'max_short_line_ratio' => (float) env('DOCUMENT_QUALITY_MAX_SHORT_LINE_RATIO', 0.6),
        'min_alphabetic_ratio' => (float) env('DOCUMENT_QUALITY_MIN_ALPHABETIC_RATIO', 0.6),
        'min_word_count' => (int) env('DOCUMENT_QUALITY_MIN_WORD_COUNT', 60),
    ],
];
