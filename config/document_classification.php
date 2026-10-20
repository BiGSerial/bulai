<?php

return [
    // Limiares ajustáveis pelos experimentos; não são valores científicos fixos.
    'auto_accept_score' => (int) env('DOCUMENT_CLASSIFICATION_AUTO_ACCEPT_SCORE', 85),
    'ai_min_score' => (int) env('DOCUMENT_CLASSIFICATION_AI_MIN_SCORE', 40),
    'ai_min_confidence' => (float) env('DOCUMENT_CLASSIFICATION_AI_MIN_CONFIDENCE', 0.85),
    // Grava no item o prompt enviado à IA (só trechos curtos já extraídos, nunca o PDF) e a resposta bruta, para auditoria.
    'store_prompt' => (bool) env('DOCUMENT_CLASSIFICATION_STORE_PROMPT', true),
    'dispatch_on_ingest' => (bool) env('DOCUMENT_CLASSIFICATION_DISPATCH_ON_INGEST', true),
];
