<?php

return [
    'filesystem_disk' => env('KNOWLEDGE_FILESYSTEM_DISK', 'knowledge'),
    'ingestion' => [
        'max_batch_kilobytes' => (int) env('KNOWLEDGE_INGESTION_MAX_BATCH_KILOBYTES', 102_400),
        'max_file_kilobytes' => (int) env('KNOWLEDGE_INGESTION_MAX_FILE_KILOBYTES', 102_400),
    ],
    'anvisa_catalog' => [
        'max_kilobytes' => (int) env('KNOWLEDGE_ANVISA_CATALOG_MAX_KILOBYTES', 51_200),
        'job_timeout_seconds' => (int) env('KNOWLEDGE_ANVISA_CATALOG_JOB_TIMEOUT_SECONDS', 600),
    ],
    // Interações medicamentosas (DDInter): cada CSV tem alguns MB; um job por arquivo.
    'drug_interactions' => [
        'max_kilobytes' => (int) env('KNOWLEDGE_INTERACTIONS_MAX_KILOBYTES', 20_480),
        'max_files' => (int) env('KNOWLEDGE_INTERACTIONS_MAX_FILES', 12),
        'job_timeout_seconds' => (int) env('KNOWLEDGE_INTERACTIONS_JOB_TIMEOUT_SECONDS', 600),
    ],
    'processing' => [
        'max_pdf_bytes' => (int) env('KNOWLEDGE_MAX_PDF_BYTES', 25 * 1024 * 1024),
        'max_pages' => (int) env('KNOWLEDGE_MAX_PAGES', 500),
        'max_text_characters' => (int) env('KNOWLEDGE_MAX_TEXT_CHARACTERS', 5_000_000),
        'min_text_characters' => (int) env('KNOWLEDGE_MIN_TEXT_CHARACTERS', 20),
        'job_timeout_seconds' => (int) env('KNOWLEDGE_JOB_TIMEOUT_SECONDS', 60),
        'progress_stale_minutes' => (int) env('KNOWLEDGE_PROGRESS_STALE_MINUTES', 5),
    ],
    'identification' => [
        'first_pages' => (int) env('KNOWLEDGE_IDENTIFICATION_FIRST_PAGES', 2),
        'last_pages' => (int) env('KNOWLEDGE_IDENTIFICATION_LAST_PAGES', 4),
    ],
    'chunking' => [
        'target_characters' => (int) env('KNOWLEDGE_CHUNK_TARGET_CHARACTERS', 1_800),
        'max_characters' => (int) env('KNOWLEDGE_CHUNK_MAX_CHARACTERS', 2_600),
        'overlap_characters' => (int) env('KNOWLEDGE_CHUNK_OVERLAP_CHARACTERS', 200),
    ],
    // Server-Sent Events da administração: cada conexão dura `seconds` e o navegador reconecta sozinho.
    'stream' => [
        'seconds' => (int) env('KNOWLEDGE_STREAM_SECONDS', 25),
        'interval_ms' => (int) env('KNOWLEDGE_STREAM_INTERVAL_MS', 2000),
        'max_per_user' => (int) env('KNOWLEDGE_STREAM_MAX_PER_USER', 2),
    ],
];
