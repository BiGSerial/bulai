<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentIdentification;
use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Support\Str;

final class AnvisaLeafletMetadataExtractor implements KnowledgeDocumentMetadataExtractor
{
    public function __construct(
        private readonly LeafletTextHeuristics $text = new LeafletTextHeuristics,
        private readonly ChemicalFormulaDetector $formulas = new ChemicalFormulaDetector,
    ) {}

    public function supports(KnowledgeSource $source): bool
    {
        $identity = Str::ascii(Str::lower($source->name.' '.($source->collection ?? $source->organization ?? '')));

        return str_contains($identity, 'anvisa') && (str_contains($identity, 'bular') || str_contains($identity, 'bula'));
    }

    public function identify(ExtractedPdf $pdf): DocumentIdentification
    {
        $pages = $this->identityPages($pdf);
        $fields = ['product_name' => null, 'active_ingredient' => null, 'registration_number' => null, 'registration_number_normalized' => null,
            'holder' => null, 'audience' => null, 'document_type' => null, 'title' => null, 'molecular_formula' => null];
        $evidence = [];
        $ambiguous = [];
        $candidates = ['product_name' => [], 'active_ingredient' => [], 'registration_number' => [], 'audience' => []];
        $titleBlock = [];
        $productIdentityIsReliable = $this->identifyProductAndIngredient($pages, $fields, $evidence, $ambiguous, $candidates, $titleBlock);
        $this->identifyRegistration($pages, $fields, $evidence, $ambiguous, $candidates);
        $this->identifyAudience($pages, $fields, $evidence, $ambiguous, $candidates);
        $this->identifyHolder($pages, $fields, $evidence);
        if ($fields['holder'] === null && $titleBlock !== [] && ($titleBlock[array_key_first($titleBlock)]['holder'] ?? null) !== null) {
            $first = $titleBlock[array_key_first($titleBlock)];
            $fields['holder'] = $first['holder'];
            $evidence['holder'] = ['value' => $first['holder'], 'page' => array_key_first($titleBlock), 'pattern' => 'cover_company_line'];
        }
        $formulaHits = $this->formulas->detect($pdf->pages);
        $candidates['molecular_formula'] = array_values(array_unique(array_column($formulaHits, 'formula')));
        if (count($candidates['molecular_formula']) === 1) {
            $fields['molecular_formula'] = $candidates['molecular_formula'][0];
            $evidence['molecular_formula'] = ['value' => $fields['molecular_formula'], 'page' => $formulaHits[0]['page'], 'pattern' => $formulaHits[0]['labeled'] ? 'labeled_molecular_formula' : 'chemical_formula_pattern'];
        }

        if ($fields['product_name'] !== null && $fields['document_type'] !== null) {
            $label = $fields['document_type'] === 'bula_paciente' ? 'Bula do paciente' : 'Bula do profissional de saúde';
            $fields['title'] = $fields['product_name'].' — '.$label;
        }

        // Marca® + nome genérico repetidos em mais de uma página (capa e folha de rosto) é evidência forte de identidade.
        $titleConverges = count($titleBlock) >= 2 && count(array_unique(array_map(fn (array $block): string => $this->normalizedIdentity($block['brand'].'|'.$block['generic']), $titleBlock))) === 1;
        $score = ($fields['registration_number_normalized'] !== null ? 40 : 0) + ($fields['product_name'] !== null ? 25 : 0)
            + ($fields['document_type'] !== null ? 20 : 0) + ($fields['active_ingredient'] !== null ? 10 : 0) + ($fields['holder'] !== null ? 5 : 0)
            + ($titleConverges && $fields['product_name'] !== null && $fields['active_ingredient'] !== null ? 15 : 0);
        $eligible = $score >= 80 && $productIdentityIsReliable && $ambiguous === [] && $fields['title'] !== null && $fields['document_type'] !== null && $fields['registration_number_normalized'] !== null;

        return new DocumentIdentification(self::class, $fields, $evidence, $ambiguous, $score, $eligible, count($pages), candidates: $candidates, excerpts: $this->excerpts($pages, $formulaHits));
    }

    /** @return list<ExtractedPage> */
    private function identityPages(ExtractedPdf $pdf): array
    {
        $pages = [];
        foreach ([...array_slice($pdf->pages, 0, (int) config('knowledge.identification.first_pages', 2)), ...array_slice($pdf->pages, -(int) config('knowledge.identification.last_pages', 4))] as $page) {
            $pages[$page->number] = $page;
        }
        ksort($pages);

        return array_values($pages);
    }

    /**
     * @param  list<ExtractedPage>  $pages
     * @param  array<string, string|null>  $fields
     * @param  array<string, array{value: string, page: int, pattern: string}>  $evidence
     * @param  list<string>  $ambiguous
     * @param  array<string, list<string>>  $candidateSink
     * @param  array<int, array{brand: string, generic: ?string, holder: ?string}>  $titleBlocks  Blocos de título por página (saída).
     */
    private function identifyProductAndIngredient(array $pages, array &$fields, array &$evidence, array &$ambiguous, array &$candidateSink, array &$titleBlocks = []): bool
    {
        $productCandidates = [];
        $ingredientCandidates = [];

        foreach ($pages as $page) {
            // Capa / folha de rosto: marca® + nome genérico (+ titular). É a fonte mais confiável da identidade.
            if ($page->number <= (int) config('knowledge.identification.first_pages', 2) + 1 && ($block = $this->text->titleBlock($page)) !== null) {
                $titleBlocks[$page->number] = $block;
                $this->addCandidate($productCandidates, trim(preg_replace('/[®™]+$/u', '', $block['brand']) ?? $block['brand']), $page->number, 'cover_brand', 60);
                $this->addCandidate($ingredientCandidates, (string) $block['generic'], $page->number, 'cover_generic_name', 55);
            }

            $formula = $this->text->compositionFormula($page);
            foreach ($formula['formula_lines'] as $name) {
                $this->addCandidate($ingredientCandidates, $name, $page->number, 'composition_formula_line', 45);
            }
            foreach ($formula['equivalent_bases'] as $base) {
                $this->addCandidate($ingredientCandidates, $base, $page->number, 'equivalent_base', 20);
            }

            if (preg_match('/IDENTIFICA[CÇ][AÃ]O\s+DO\s+MEDICAMENTO\s*\R+(?:\s*\R)*([^\r\n]{2,120})/iu', $page->text, $match) === 1) {
                $this->addCandidate($productCandidates, $match[1], $page->number, 'identification_section_heading', 40);
            }

            if (preg_match('/^\s*([^\n]{2,100})\R\s*([^\n]{3,120})\R\s*(?:APRESENTAÇÕES|APRESENTACAO)/imu', $page->text, $match) === 1) {
                $subtitle = $this->clean($match[2]);
                $isGenericMarker = preg_match('/\bmedicamento\s+gen[eé]rico\b.*\blei\s+n?[º°o]?\.?\s*9\.?787\b/iu', $subtitle) === 1;
                $this->addCandidate($productCandidates, $match[1], $page->number, 'heading_before_presentations', 40);
                if ($isGenericMarker) {
                    $evidence['generic_marker'] = ['value' => $subtitle, 'page' => $page->number, 'pattern' => 'generic_medicine_legal_marker'];
                    $this->addCandidate($ingredientCandidates, $match[1], $page->number, 'generic_product_name_is_active_ingredient', 50);
                } else {
                    $this->addCandidate($ingredientCandidates, $subtitle, $page->number, 'ingredient_near_heading', 25);
                }
            }

            if (preg_match('/^\s*([^\n]{2,100})\R\s*\(([^\n)]{3,120})\)/mu', $page->text, $match) === 1
                && ! $this->text->isCompositionLine($match[1]) && ! $this->text->isCompositionLine($match[2])) {
                $this->addCandidate($productCandidates, $match[1], $page->number, 'title_followed_by_parenthesized_ingredient', 35);
                $this->addCandidate($ingredientCandidates, $match[2], $page->number, 'parenthesized_ingredient', 50);
            }

            foreach ($this->compositionCandidates($page) as $candidate) {
                $this->addCandidate($ingredientCandidates, $candidate, $page->number, 'composition_statement', 40);
            }
        }

        // Uma quantidade ("50 mg de…", "10mL de…") não é nome de substância: descartar evita um princípio ativo falso e deixa o campo para o catálogo/IA.
        $ingredientCandidates = array_filter($ingredientCandidates, fn (array $candidate): bool => preg_match('/^\W*\d|^\W*equivalente\b/iu', $candidate['value']) !== 1
            && preg_match('/^\p{Lu}{2,5}$/u', $candidate['value']) !== 1);  // siglas ("IBP") não são substâncias
        $candidateSink['product_name'] = array_values(array_column($productCandidates, 'value'));
        $candidateSink['active_ingredient'] = array_values(array_column($ingredientCandidates, 'value'));
        $product = $this->selectCandidate($productCandidates, 'product_name', $ambiguous);
        $ingredient = $this->selectCandidate($ingredientCandidates, 'active_ingredient', $ambiguous);

        if ($product !== null) {
            $fields['product_name'] = $product['value'];
            $evidence['product_name'] = ['value' => $product['value'], 'page' => $product['page'], 'pattern' => $product['pattern']];
        }
        if ($ingredient !== null) {
            $fields['active_ingredient'] = $ingredient['value'];
            $evidence['active_ingredient'] = ['value' => $ingredient['value'], 'page' => $ingredient['page'], 'pattern' => $ingredient['pattern']];
        }

        $converges = $product !== null && $ingredient !== null && $this->normalizedIdentity($product['value']) === $this->normalizedIdentity($ingredient['value']);
        if ($converges) {
            $evidence['identity_convergence'] = ['value' => $product['value'], 'page' => $ingredient['page'], 'pattern' => 'product_confirmed_by_active_ingredient'];
        }

        return $product !== null && ($product['weight'] >= 35 || $converges);
    }

    /** @param list<ExtractedPage> $pages @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence @param list<string> $ambiguous @param array<string, list<string>> $candidateSink */
    private function identifyRegistration(array $pages, array &$fields, array &$evidence, array &$ambiguous, array &$candidateSink): void
    {
        $candidates = [];
        foreach ($pages as $page) {
            preg_match_all('/(?:Registro(?:\h+MS)?|Reg\.?|M\.?S\.?)\h*(?:n[º°o]\.?)?\h*[:.-]?\h*([0-9][0-9.\t \/-]{7,20})/iu', $page->text, $matches);
            foreach ($matches[1] as $candidate) {
                $normalized = preg_replace('/\D/', '', $candidate) ?? '';
                if (in_array(strlen($normalized), [9, 13], true)) {
                    $candidates[$normalized] = ['original' => trim($candidate), 'page' => $page->number];
                }
            }
        }
        $candidateSink['registration_number'] = array_map('strval', array_keys($candidates));
        if (count($candidates) > 1) {
            $ambiguous[] = 'registration_number';

            return;
        }
        if ($candidates !== []) {
            $key = array_key_first($candidates);
            $normalized = (string) $key;
            $candidate = $candidates[$key];
            $fields['registration_number'] = $candidate['original'];
            $fields['registration_number_normalized'] = $normalized;
            $evidence['registration_number'] = ['value' => $candidate['original'], 'page' => $candidate['page'], 'pattern' => 'anvisa_registration_after_label'];
        }
    }

    /** @param list<ExtractedPage> $pages @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence @param list<string> $ambiguous @param array<string, list<string>> $candidateSink */
    private function identifyAudience(array $pages, array &$fields, array &$evidence, array &$ambiguous, array &$candidateSink): void
    {
        $patient = null;
        $professional = null;
        $firstPageLimit = (int) config('knowledge.identification.first_pages', 2);
        foreach (array_filter($pages, fn (ExtractedPage $page): bool => $page->number <= $firstPageLimit) as $page) {
            $professional ??= preg_match('/(?:Bula para o profissional|INFORMAÇÕES (?:TÉCNICAS )?AOS? PROFISSIONAIS|^[^\r\n]{2,100}\bPROFISSIONAL\b)/imu', $page->text) === 1 ? $page->number : null;
            $patient ??= preg_match('/(?:Bula para o paciente|INFORMAÇÕES AO PACIENTE|^[^\r\n]{2,100}\bPACIENTE\b)/imu', $page->text) === 1 ? $page->number : null;
        }
        $candidateSink['audience'] = array_values(array_filter([$patient !== null ? 'patient' : null, $professional !== null ? 'professional' : null]));
        if ($patient !== null && $professional !== null) {
            $ambiguous[] = 'audience';

            return;
        }
        if ($professional !== null || $patient !== null) {
            $isProfessional = $professional !== null;
            $fields['audience'] = $isProfessional ? 'professional' : 'patient';
            $fields['document_type'] = $isProfessional ? 'bula_profissional' : 'bula_paciente';
            $evidence['audience'] = ['value' => $fields['audience'], 'page' => $professional ?? $patient, 'pattern' => $isProfessional ? 'professional_leaflet_marker' : 'patient_leaflet_marker'];
        }
    }

    /** @param list<ExtractedPage> $pages @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence */
    private function identifyHolder(array $pages, array &$fields, array &$evidence): void
    {
        foreach ($pages as $page) {
            if (preg_match('/(?:Importado e Registrado|Registrado) por:\s*([^\r\n]{3,180})/iu', $page->text, $match) === 1) {
                $fields['holder'] = $this->clean($match[1]);
                $evidence['holder'] = ['value' => $fields['holder'], 'page' => $page->number, 'pattern' => 'registered_by_label'];

                return;
            }
        }
    }

    /**
     * Trechos que sustentam a identificação; são o único conteúdo do PDF enviado ao classificador local.
     * Cobrem capa, identificação, composição ("fórmula"), contexto de fórmulas moleculares e linhas de titular/registro.
     *
     * @param  list<ExtractedPage>  $pages
     * @param  list<array{formula: string, page: int, context: string, labeled: bool}>  $formulaHits
     * @return list<array{page: int, type: string, text: string}>
     */
    private function excerpts(array $pages, array $formulaHits = []): array
    {
        $excerpts = [];
        foreach ($pages as $page) {
            $lines = $this->text->lines($page);
            $header = $this->clean(implode(' ', array_slice($lines, 0, 3)));
            if ($header !== '') {
                $excerpts[] = ['page' => $page->number, 'type' => 'page_header', 'text' => mb_substr($header, 0, 300)];
            }
            if ($page->number <= (int) config('knowledge.identification.first_pages', 2) && count($lines) > 3) {
                $excerpts[] = ['page' => $page->number, 'type' => 'cover_block', 'text' => mb_substr($this->clean(implode(' | ', array_slice($lines, 0, 14))), 0, 1200)];
            }
            if (preg_match('/IDENTIFICA[CÇ][AÃ]O\s+DO\s+MEDICAMENTO(.{0,900})/isu', $page->text, $match) === 1 && $this->clean($match[1]) !== '') {
                $excerpts[] = ['page' => $page->number, 'type' => 'identification_section', 'text' => $this->clean($match[1])];
            }
            if (preg_match('/\bCOMPOSI[CÇ][AÃ]O\b(.{0,1600})/isu', $page->text, $match) === 1 && $this->clean($match[1]) !== '') {
                $block = preg_split('/\R\s*(?:INFORMA[CÇ][OÕ]ES|\d+\.\s+[A-ZÀ-Ú])/u', $match[1])[0] ?? $match[1];
                $excerpts[] = ['page' => $page->number, 'type' => 'composition', 'text' => mb_substr($this->clean($block), 0, 1200)];
            }
            foreach (array_slice($lines, 0, 80) as $line) {
                if (preg_match('/\b(?:Registro|MS|Reg\.?|CNPJ|Registrado por|Importado e Registrado)\b.{0,160}/iu', $line, $match) === 1 && mb_strlen($line) <= 220) {
                    $excerpts[] = ['page' => $page->number, 'type' => 'holder_registration', 'text' => mb_substr($line, 0, 220)];
                    break;
                }
            }
        }
        foreach (array_slice($formulaHits, 0, 3) as $hit) {
            $excerpts[] = ['page' => $hit['page'], 'type' => 'formula_context', 'text' => mb_substr($hit['context'], 0, 420)];
        }

        return array_slice($excerpts, 0, 16);
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " \t\n\r\0\x0B-");
    }

    /** @param array<string, array{value: string, page: int, pattern: string, weight: int}> $candidates */
    private function addCandidate(array &$candidates, string $value, int $page, string $pattern, int $weight): void
    {
        $value = $this->clean($value);
        $value = preg_replace('/^Nome\h+gen[eé]rico\h*:\h*/iu', '', $value) ?? $value;
        $value = preg_replace('/\h*\(DCB\h*:\h*\d+\)\h*$/iu', '', $value) ?? $value;
        $value = $this->clean($value);
        // Líderes pontilhados e marcas de nota ("metformina* ..500 mg") nunca fazem parte do nome; linhas que começam por
        // "*", "(" ou "equivalente" são notas da composição, não nomes.
        $value = rtrim(preg_replace('/\s*\.{2,}.*$/u', '', $value) ?? $value, "*†‡ \t");
        if ($value === '' || preg_match('/^[*(]|^equivalente\b/iu', $value) === 1 || $this->isRejectedIdentityCandidate($value)) {
            return;
        }

        $key = $this->normalizedIdentity($value);
        if ($key === '') {
            return;
        }

        if (! isset($candidates[$key]) || $weight > $candidates[$key]['weight']) {
            $candidates[$key] = compact('value', 'page', 'pattern', 'weight');
        } elseif ($weight === $candidates[$key]['weight'] && mb_strtoupper($candidates[$key]['value']) === $candidates[$key]['value'] && mb_strtoupper($value) !== $value) {
            // Mesma identidade e mesmo peso: prefere a grafia com minúsculas ("Glifage") à caixa alta da capa ("GLIFAGE").
            $candidates[$key]['value'] = $value;
        }
    }

    /**
     * @param  array<string, array{value: string, page: int, pattern: string, weight: int}>  $candidates
     * @param  list<string>  $ambiguous
     * @return array{value: string, page: int, pattern: string, weight: int}|null
     */
    private function selectCandidate(array $candidates, string $field, array &$ambiguous): ?array
    {
        if ($candidates === []) {
            return null;
        }

        uasort($candidates, fn (array $left, array $right): int => $right['weight'] <=> $left['weight']);
        $ranked = array_values($candidates);
        if (isset($ranked[1]) && $ranked[0]['weight'] === $ranked[1]['weight']) {
            $ambiguous[] = $field;

            return null;
        }

        return $ranked[0];
    }

    /** @return list<string> */
    private function compositionCandidates(ExtractedPage $page): array
    {
        $candidates = [];
        if (preg_match('/\bCOMPOSI[CÇ][AÃ]O\b(.{0,1600})/isu', $page->text, $section) !== 1) {
            return [];
        }

        preg_match_all('/cada\h+[^\r\n:]{1,100}\h+cont[eé]m\h*:?\h*([^\r\n.;]{2,160})/iu', $section[1], $matches);
        foreach ($matches[1] as $candidate) {
            $candidate = preg_split('/\h+(?:[.·•…]{2,}|\d+(?:[,.]\d+)?\h*(?:mg|mcg|g|ml|%))/iu', $candidate, 2)[0] ?? $candidate;
            $candidates[] = $this->text->sanitizeSubstance($candidate);
        }

        return $candidates;
    }

    private function isRejectedIdentityCandidate(string $value): bool
    {
        if (mb_strlen($value) < 2 || preg_match('/^\d+[\d\s.\/-]*$/u', $value) === 1) {
            return true;
        }

        return preg_match('/^(?:I+\h*[-–.]?\h*)?(?:IDENTIFICA[CÇ][AÃ]O\h+DO\h+MEDICAMENTO|MODELO\h+DE\h+BULA|TEXTO\h+DE\h+BULA|RDC\b|APRESENTA[CÇ][OÕ]ES?|COMPOSI[CÇ][AÃ]O|MEDICAMENTO\h+GEN[EÉ]RICO|INFORMA[CÇ][OÕ]ES?\b|EXPEDIENTE\b)/iu', $value) === 1
            || preg_match('/\bLei\h+n?[º°o]?\.?\h*9\.?787\b/iu', $value) === 1
            || preg_match('/^(?:VP\h*\/\h*VPS|VPS?)(?:\h|$)/iu', $value) === 1
            || preg_match('/\bX\h*\d+(?:[,.]\d+)?\h*(?:ML|MG|G|COMPRIMIDOS?|C[ÁA]PSULAS?|FRASCOS?)\b/iu', $value) === 1;
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::ascii(Str::lower($value))) ?? '';
    }
}
