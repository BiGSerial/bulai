<?php

return [
    'filesystem_disk' => env('KNOWLEDGE_FILESYSTEM_DISK', 'knowledge'),
    'ingestion' => [
        'max_files' => (int) env('KNOWLEDGE_INGESTION_MAX_FILES', 15),
        'max_file_kilobytes' => (int) env('KNOWLEDGE_INGESTION_MAX_FILE_KILOBYTES', 20_480),
    ],
    'processing' => [
        'max_pdf_bytes' => (int) env('KNOWLEDGE_MAX_PDF_BYTES', 25 * 1024 * 1024),
        'max_pages' => (int) env('KNOWLEDGE_MAX_PAGES', 500),
        'max_text_characters' => (int) env('KNOWLEDGE_MAX_TEXT_CHARACTERS', 5_000_000),
        'min_text_characters' => (int) env('KNOWLEDGE_MIN_TEXT_CHARACTERS', 20),
        'job_timeout_seconds' => (int) env('KNOWLEDGE_JOB_TIMEOUT_SECONDS', 60),
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
];
