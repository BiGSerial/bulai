<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatchItem;
use Illuminate\Support\Str;

/**
 * Traduz o que a análise automática gravou no item (extração, heurística, classificador local) em dados legíveis
 * para a tela de revisão. Não lê o PDF nem chama serviços: apenas apresenta o que foi registrado.
 */
final class AutomaticAnalysisReport
{
    private const DECISIONS = [
        KnowledgeDocumentClassificationService::ACCEPTED_HEURISTIC => ['Aceito pela heurística', 'success', 'A heurística identificou o documento com segurança; a IA não foi consultada.'],
        KnowledgeDocumentClassificationService::AI_ACCEPTED => ['Resolvido pela IA', 'success', 'A IA resolveu a ambiguidade e o catálogo ANVISA confirmou o produto.'],
        KnowledgeDocumentClassificationService::AI_SUGGESTED => ['Sugestão da IA', 'warning', 'A IA sugeriu valores, mas sem confirmação do catálogo ANVISA eles não foram aplicados.'],
        KnowledgeDocumentClassificationService::AI_LOW_CONFIDENCE => ['IA com baixa confiança', 'warning', 'A confiança da IA ficou abaixo do mínimo; nada foi aplicado.'],
        KnowledgeDocumentClassificationService::AI_CONFIRMED => ['IA confirmou a heurística', 'success', 'A IA concordou com os campos encontrados pela heurística e não acrescentou nada novo.'],
        KnowledgeDocumentClassificationService::AI_REJECTED => ['Resposta da IA rejeitada', 'danger', 'A resposta da IA foi descartada por não ter respaldo no documento ou por contradizer a heurística.'],
        KnowledgeDocumentClassificationService::AI_UNAVAILABLE => ['IA indisponível', 'danger', 'A IA local não respondeu de forma utilizável; o item segue para revisão manual.'],
        KnowledgeDocumentClassificationService::AI_DISABLED => ['IA desativada', 'neutral', 'O classificador local não está configurado; o item segue para revisão manual.'],
        KnowledgeDocumentClassificationService::MANUAL_REVIEW => ['Revisão manual', 'warning', 'A pontuação da heurística ficou abaixo do mínimo para consultar a IA.'],
    ];

    private const REASONS = [
        'heuristic_score_too_low' => 'Pontuação da heurística abaixo do mínimo para consultar a IA.',
        'regulatory_confirmation_missing' => 'O catálogo ANVISA não confirmou o produto sugerido.',
        'conflict_with_heuristic_product_name' => 'O medicamento sugerido contradiz o que a heurística encontrou.',
        'conflict_with_heuristic_active_ingredient' => 'O princípio ativo sugerido contradiz o que a heurística encontrou.',
        'conflict_with_heuristic_document_type' => 'O tipo sugerido (paciente/profissional) contradiz o que a heurística encontrou.',
        'nothing_resolved' => 'A IA não acrescentou nada além do que a heurística já tinha.',
        'implausible_active_ingredient' => 'O princípio ativo sugerido começa por quantidade (ex.: "50 mg de…") e não parece um nome de substância.',
        'ungrounded_product_name' => 'O medicamento sugerido não aparece nos trechos enviados à IA.',
        'ungrounded_active_ingredient' => 'O princípio ativo sugerido não aparece nos trechos enviados à IA.',
        'ungrounded_document_type' => 'O tipo sugerido não tem respaldo nos trechos enviados à IA.',
    ];

    private const ERRORS = [
        'AI_INVALID_RESPONSE' => 'A IA respondeu fora do formato esperado.',
        'AI_TIMEOUT' => 'A IA demorou mais que o limite.',
        'AI_UNAVAILABLE' => 'Não foi possível conectar à IA local.',
        'AI_RATE_LIMITED' => 'A IA recusou por excesso de requisições.',
        'AI_INVALID_REQUEST' => 'O pedido à IA excedeu os limites de tamanho.',
        'OCR_UNAVAILABLE' => 'O serviço de OCR não respondeu.',
    ];

    private const FIELDS = ['product_name' => 'Medicamento', 'active_ingredient' => 'Princípio ativo', 'document_type' => 'Tipo de bula'];

    /**
     * @return array{
     *     analyzed: bool,
     *     progress: array{state: string, percent: int, label: string}|null,
     *     extraction: array<string, mixed>|null,
     *     classification: array<string, mixed>|null,
     *     review: array<string, mixed>|null,
     *     medicine: array<string, mixed>,
     *     runs: list<array<string, mixed>>
     * }
     */
    public function forItem(IngestionBatchItem $item): array
    {
        $identification = data_get($item->metadata, 'preliminary_identification');
        $identification = is_array($identification) ? $identification : [];
        $progress = data_get($item->metadata, 'reanalysis_progress');

        return [
            'analyzed' => is_array($identification['extraction'] ?? null) || is_array($identification['classification'] ?? null),
            'progress' => is_array($progress) ? ['state' => (string) ($progress['state'] ?? ''), 'percent' => (int) ($progress['percent'] ?? 0), 'label' => (string) ($progress['label'] ?? '')] : null,
            'extraction' => is_array($identification['extraction'] ?? null) ? $this->extraction($identification['extraction']) : null,
            'classification' => is_array($identification['classification'] ?? null) ? $this->classification($identification['classification'], (int) ($identification['score'] ?? 0), (array) ($identification['fields'] ?? []), (array) data_get($identification, 'regulatory_validation.catalog', []), (array) ($identification['fields'] ?? [])) : null,
            'review' => $this->review($item),
            'medicine' => $this->medicine($item),
            'runs' => $this->runs($item),
        ];
    }

    /**
     * Painel consolidado do medicamento: cada dado com a origem (PDF, catálogo ANVISA ou IA) e o princípio ativo em destaque,
     * já que é por ele que as interações medicamentosas serão verificadas.
     *
     * @return array<string, mixed>
     */
    private function medicine(IngestionBatchItem $item): array
    {
        $identification = (array) data_get($item->metadata, 'preliminary_identification', []);
        $fields = (array) ($identification['fields'] ?? []);
        $validation = (array) ($identification['regulatory_validation'] ?? []);
        $catalog = (array) ($validation['catalog'] ?? []);
        $classification = (array) ($identification['classification'] ?? []);
        $review = (array) data_get($item->metadata, 'ai_review', []);
        $reviewSources = ($review['status'] ?? null) === 'ACCEPTED' ? (array) ($review['sources'] ?? []) : [];
        $filled = (array) ($validation['filled_from_catalog'] ?? []);

        $source = function (string $field) use ($classification, $reviewSources, $filled, $fields): string {
            return match (true) {
                ($reviewSources[$field] ?? null) === 'catalog' => 'Catálogo ANVISA (aceito por você)',
                ($reviewSources[$field] ?? null) !== null => 'IA (aceito por você)',
                in_array($field, $filled, true) => 'Catálogo ANVISA',
                $field === 'active_ingredient' && ($classification['ingredient_filled_by_ai'] ?? false) => 'IA (não confirmado no catálogo)',
                ($classification['decision'] ?? null) === 'AI_ACCEPTED' && filled($classification['result'][$field] ?? null) && ! filled($classification['heuristic'][$field] ?? null) && filled($fields[$field] ?? null) => 'IA',
                default => 'PDF',
            };
        };
        $row = fn (string $label, ?string $value, string $origin): array => ['label' => $label, 'value' => filled($value) ? $value : null, 'source' => filled($value) ? $origin : null];
        $ingredient = filled($fields['active_ingredient'] ?? null) ? (string) $fields['active_ingredient'] : null;
        $expires = (string) ($catalog['registration_expires_on'] ?? '');

        return [
            'ingredients' => $ingredient === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\s*(?:\+|;|,|\be\b)\s*/iu', $ingredient) ?: []))),
            'ingredient_missing' => $ingredient === null,
            'ingredient_source' => $ingredient === null ? null : $source('active_ingredient'),
            'validation' => $validation['status'] ?? null,
            'rows' => [
                $row('Medicamento', $fields['product_name'] ?? ($catalog['product_name'] ?? null), isset($fields['product_name']) ? $source('product_name') : 'Catálogo ANVISA'),
                $row('Princípio ativo', $ingredient, $source('active_ingredient')),
                $row('Fórmula molecular', $fields['molecular_formula'] ?? null, 'PDF'),
                $row('Tipo de bula', $fields['document_type'] ?? null, $source('document_type')),
                $row('Registro ANVISA', $fields['registration_number_normalized'] ?? ($catalog['registration_number'] ?? null), isset($fields['registration_number_normalized']) ? 'PDF' : 'Catálogo ANVISA'),
                $row('Processo', $catalog['process_number'] ?? null, 'Catálogo ANVISA'),
                $row('Titular do registro', $fields['holder'] ?? ($catalog['registration_holder'] ?? null), isset($fields['holder']) ? 'PDF' : 'Catálogo ANVISA'),
                $row('Categoria regulatória', $catalog['regulatory_category'] ?? null, 'Catálogo ANVISA'),
                $row('Classe terapêutica', $catalog['therapeutic_class'] ?? null, 'Catálogo ANVISA'),
                $row('Situação do registro', $catalog['registration_status'] ?? null, 'Catálogo ANVISA'),
                $row('Validade do registro', preg_match('/^\d{6}$/', $expires) === 1 ? substr($expires, 0, 2).'/'.substr($expires, 2) : ($expires !== '' ? $expires : null), 'Catálogo ANVISA'),
            ],
        ];
    }

    /** @return array<string, mixed>|null Decisão humana sobre a sugestão da IA. */
    private function review(IngestionBatchItem $item): ?array
    {
        $review = data_get($item->metadata, 'ai_review');
        if (! is_array($review)) {
            return null;
        }

        return [
            'status' => $review['status'] ?? null,
            'accepted' => ($review['status'] ?? null) === 'ACCEPTED',
            'fields' => array_map(fn (string $field): string => self::FIELDS[$field] ?? $field, (array) ($review['fields'] ?? [])),
            'actor' => $review['actor_name'] ?? 'Administrador',
            'decided_at' => $review['decided_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return array<string, mixed>
     */
    private function extraction(array $extraction): array
    {
        $ocr = ($extraction['extraction_method'] ?? null) === 'ocr';
        $error = $extraction['ocr_error_code'] ?? null;
        $supplement = is_array($extraction['ocr_supplement'] ?? null) ? $extraction['ocr_supplement'] : null;

        return [
            'method_label' => $supplement !== null ? 'Texto nativo + OCR da capa' : ($ocr ? 'OCR' : 'Texto nativo do PDF'),
            'ocr_used' => $ocr,
            'tone' => $error !== null ? 'danger' : ($ocr ? 'info' : 'success'),
            'engine' => $extraction['ocr_engine'] ?? null,
            'quality' => $extraction['quality_score'] ?? null,
            'quality_after_ocr' => $extraction['quality_score_after_ocr'] ?? null,
            'pages' => $extraction['pages_processed'] ?? null,
            'duration_ms' => $extraction['processing_duration'] ?? null,
            'reasons' => array_values(array_filter((array) ($extraction['quality_reasons'] ?? []), 'is_string')),
            'note' => match (true) {
                $error !== null => 'O OCR foi tentado, mas falhou: '.(self::ERRORS[$error] ?? $error).' Foi usado o texto nativo.',
                $ocr => 'O texto nativo era insuficiente e o OCR o substituiu.',
                isset($extraction['fallback_reason']) => 'O OCR foi tentado, mas o texto nativo teve resultado melhor e foi mantido.',
                $supplement !== null => sprintf('O texto nativo tinha qualidade, mas a heurística não estava confiante: o OCR leu as %d primeiras páginas e acrescentou %d linhas (pontuação %s → %s, %s).', (int) ($supplement['pages'] ?? 0), (int) ($supplement['added_lines'] ?? 0), $supplement['score_before'] ?? '?', $supplement['score_after'] ?? '?', ($supplement['adopted'] ?? false) ? 'resultado adotado' : 'resultado descartado por ser pior'),
                default => 'O texto nativo tinha qualidade suficiente; o OCR não foi necessário.',
            },
            'ocr_supplement' => $supplement,
        ];
    }

    /**
     * @param  array<string, mixed>  $classification
     * @return array<string, mixed>
     */
    private function classification(array $classification, int $score, array $heuristicFallback = [], array $catalog = [], array $current = []): array
    {
        $decision = (string) ($classification['decision'] ?? '');
        [$label, $tone, $explanation] = self::DECISIONS[$decision] ?? [$decision ?: 'Sem decisão', 'neutral', ''];
        $result = is_array($classification['result'] ?? null) ? $classification['result'] : [];
        $suggestion = [];
        foreach (self::FIELDS as $key => $fieldLabel) {
            if (filled($result[$key] ?? null)) {
                $suggestion[$fieldLabel] = (string) $result[$key];
            }
        }

        return [
            'decision' => $decision,
            'label' => $label,
            'tone' => $tone,
            'explanation' => $explanation,
            'heuristic_score' => $classification['heuristic_score'] ?? $score,
            'ai_used' => (bool) ($classification['ai_used'] ?? false),
            'model' => $classification['model'] ?? null,
            'confidence' => isset($classification['confidence']) ? (float) $classification['confidence'] : null,
            'duration_ms' => $classification['duration_ms'] ?? null,
            'suggestion' => $suggestion,
            'evidence' => array_values(array_filter((array) ($result['evidence'] ?? []), 'is_string')),
            'reason' => self::REASONS[$classification['reason'] ?? ''] ?? ($classification['reason'] ?? null),
            'error' => isset($classification['error_code']) ? (self::ERRORS[$classification['error_code']] ?? $classification['error_code']) : null,
            'thresholds' => is_array($classification['thresholds'] ?? null) ? $classification['thresholds'] : null,
            'forced' => (bool) ($classification['forced'] ?? false),
            'prompt' => $this->prompt($classification['prompt'] ?? null),
            'comparison' => $this->comparison($classification, $heuristicFallback, $catalog, $current),
            'gap_fill' => (bool) ($classification['gap_fill'] ?? false),
            'gap_fill_outcome' => $classification['gap_fill_outcome'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function prompt(mixed $prompt): ?array
    {
        if (! is_array($prompt)) {
            return null;
        }

        return [
            'system' => is_string($prompt['system'] ?? null) ? $prompt['system'] : null,
            'user' => json_encode($prompt['user'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null,
            'raw_response' => is_string($prompt['raw_response'] ?? null) ? $prompt['raw_response'] : null,
            'params' => collect($prompt)->only(['temperature', 'max_tokens', 'structured_output'])->all(),
            'sha256' => $prompt['sha256'] ?? null,
        ];
    }

    /**
     * Quadro comparativo campo a campo: o que a heurística, o catálogo ANVISA e a IA encontraram e como se relacionam.
     *
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $heuristicFallback  Campos da identificação, para registros anteriores ao snapshot da heurística.
     * @param  array<string, mixed>  $catalog  Registro do catálogo ANVISA achado pelo número de registro.
     * @param  array<string, mixed>  $current  Valores atualmente gravados no item (inclui o que já foi aplicado).
     * @return list<array<string, mixed>>
     */
    private function comparison(array $classification, array $heuristicFallback, array $catalog = [], array $current = []): array
    {
        $heuristic = is_array($classification['heuristic'] ?? null) ? $classification['heuristic'] : $heuristicFallback;
        $result = is_array($classification['result'] ?? null) ? $classification['result'] : [];
        $checks = is_array($classification['checks'] ?? null) ? $classification['checks'] : [];
        $catalogKeys = ['product_name' => 'product_name', 'active_ingredient' => 'active_ingredient', 'registration_number_normalized' => 'registration_number'];
        $rows = [];
        foreach ([...self::FIELDS, 'registration_number_normalized' => 'Registro ANVISA'] as $key => $label) {
            $values = [
                'heuristic' => filled($heuristic[$key] ?? null) ? (string) $heuristic[$key] : null,
                'catalog' => isset($catalogKeys[$key]) && filled($catalog[$catalogKeys[$key]] ?? null) ? (string) $catalog[$catalogKeys[$key]] : null,
                'ai' => filled($result[$key] ?? null) ? (string) $result[$key] : null,
            ];
            $present = array_filter($values, fn (?string $value): bool => $value !== null);
            if ($present === []) {
                continue;
            }
            $distinct = collect($present)->map(fn (string $value): string => $this->normalized($value))->unique()->count();
            $relation = match (true) {
                count($present) === 1 => 'only_'.array_key_first($present),
                $distinct === 1 => 'equal',
                default => 'different',
            };
            $now = filled($current[$key] ?? null) ? $this->normalized((string) $current[$key]) : null;
            $canApply = $key !== 'registration_number_normalized';
            $rows[] = ['field' => $key, 'label' => $label, ...$values, 'relation' => $relation, 'grounded' => $checks[$key]['grounded'] ?? null,
                'ai_selectable' => $canApply && $values['ai'] !== null && $this->normalized($values['ai']) !== $now,
                'catalog_selectable' => $canApply && $key !== 'document_type' && $values['catalog'] !== null && $this->normalized($values['catalog']) !== $now];
        }

        return $rows;
    }

    private function normalized(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::ascii(mb_strtolower($value))) ?? '';
    }

    /**
     * Histórico de execuções (mais recente primeiro), vindo da trilha de auditoria do item.
     *
     * @return list<array<string, mixed>>
     */
    private function runs(IngestionBatchItem $item): array
    {
        $runs = [];
        foreach ((array) data_get($item->metadata, 'audit_history', []) as $event) {
            if (! is_array($event) || ($event['event'] ?? null) !== 'automatic_analysis') {
                continue;
            }
            $details = is_array($event['details'] ?? null) ? $event['details'] : [];
            $classification = is_array($details['classification'] ?? null) ? $this->classification($details['classification'], (int) ($details['score'] ?? 0), (array) ($details['fields'] ?? [])) : null;
            $extraction = is_array($details['extraction'] ?? null) ? $this->extraction($details['extraction']) : null;
            $runs[] = [
                'occurred_at' => $event['occurred_at'] ?? null,
                'actor' => $event['actor_name'] ?? 'Sistema',
                'extraction' => $extraction,
                'classification' => $classification,
            ];
        }

        return array_reverse($runs);
    }
}
