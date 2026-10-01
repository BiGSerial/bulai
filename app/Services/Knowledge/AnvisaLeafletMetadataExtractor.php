<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentIdentification;
use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Support\Str;

final class AnvisaLeafletMetadataExtractor implements KnowledgeDocumentMetadataExtractor
{
    public function supports(KnowledgeSource $source): bool
    {
        $identity = Str::ascii(Str::lower($source->name.' '.($source->collection ?? $source->organization ?? '')));

        return str_contains($identity, 'anvisa') && (str_contains($identity, 'bular') || str_contains($identity, 'bula'));
    }

    public function identify(ExtractedPdf $pdf): DocumentIdentification
    {
        $pages = $this->identityPages($pdf);
        $fields = ['product_name' => null, 'active_ingredient' => null, 'registration_number' => null, 'registration_number_normalized' => null,
            'holder' => null, 'audience' => null, 'document_type' => null, 'title' => null];
        $evidence = [];
        $ambiguous = [];
        $this->identifyProductAndIngredient($pages, $fields, $evidence);
        $this->identifyRegistration($pages, $fields, $evidence, $ambiguous);
        $this->identifyAudience($pages, $fields, $evidence, $ambiguous);
        $this->identifyHolder($pages, $fields, $evidence);

        if ($fields['product_name'] !== null && $fields['document_type'] !== null) {
            $label = $fields['document_type'] === 'bula_paciente' ? 'Bula do paciente' : 'Bula do profissional de saúde';
            $fields['title'] = $fields['product_name'].' — '.$label;
        }

        $score = ($fields['registration_number_normalized'] !== null ? 40 : 0) + ($fields['product_name'] !== null ? 25 : 0)
            + ($fields['document_type'] !== null ? 20 : 0) + ($fields['active_ingredient'] !== null ? 10 : 0) + ($fields['holder'] !== null ? 5 : 0);
        $eligible = $score >= 80 && $ambiguous === [] && $fields['title'] !== null && $fields['document_type'] !== null && $fields['registration_number_normalized'] !== null;

        return new DocumentIdentification(self::class, $fields, $evidence, $ambiguous, $score, $eligible, count($pages));
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

    /** @param list<ExtractedPage> $pages @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence */
    private function identifyProductAndIngredient(array $pages, array &$fields, array &$evidence): void
    {
        foreach ($pages as $page) {
            if (preg_match('/^\s*([^\n]{2,100})\R\s*([^\n]{3,120})\R\s*(?:APRESENTAÇÕES|APRESENTACAO)/imu', $page->text, $match) === 1) {
                $fields['product_name'] = $this->clean($match[1]);
                $fields['active_ingredient'] = $this->clean($match[2]);
                $evidence['product_name'] = ['value' => $fields['product_name'], 'page' => $page->number, 'pattern' => 'heading_near_active_ingredient'];
                $evidence['active_ingredient'] = ['value' => $fields['active_ingredient'], 'page' => $page->number, 'pattern' => 'ingredient_near_heading'];

                return;
            }
        }

        foreach ($pages as $page) {
            if (preg_match('/^\s*([^\n]{2,100})\R\s*\(([^\n)]{3,120})\)/mu', $page->text, $match) === 1) {
                $fields['product_name'] = $this->clean($match[1]);
                $fields['active_ingredient'] = $this->clean($match[2]);
                $evidence['product_name'] = ['value' => $fields['product_name'], 'page' => $page->number, 'pattern' => 'title_followed_by_parenthesized_ingredient'];
                $evidence['active_ingredient'] = ['value' => $fields['active_ingredient'], 'page' => $page->number, 'pattern' => 'parenthesized_ingredient'];

                return;
            }
        }
    }

    /** @param list<ExtractedPage> $pages @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence @param list<string> $ambiguous */
    private function identifyRegistration(array $pages, array &$fields, array &$evidence, array &$ambiguous): void
    {
        $candidates = [];
        foreach ($pages as $page) {
            preg_match_all('/(?:Registro|Reg\.)\h*[:.]?\h*([0-9][0-9.\t -]{7,20})/iu', $page->text, $matches);
            foreach ($matches[1] as $candidate) {
                $normalized = preg_replace('/\D/', '', $candidate) ?? '';
                if (in_array(strlen($normalized), [9, 13], true)) {
                    $candidates[$normalized] = ['original' => trim($candidate), 'page' => $page->number];
                }
            }
        }
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

    /** @param list<ExtractedPage> $pages @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence @param list<string> $ambiguous */
    private function identifyAudience(array $pages, array &$fields, array &$evidence, array &$ambiguous): void
    {
        $patient = null;
        $professional = null;
        $firstPageLimit = (int) config('knowledge.identification.first_pages', 2);
        foreach (array_filter($pages, fn (ExtractedPage $page): bool => $page->number <= $firstPageLimit) as $page) {
            $professional ??= preg_match('/(?:Bula para o profissional|INFORMAÇÕES (?:TÉCNICAS )?AOS? PROFISSIONAIS|^[^\r\n]{2,100}\bPROFISSIONAL\b)/imu', $page->text) === 1 ? $page->number : null;
            $patient ??= preg_match('/(?:Bula para o paciente|INFORMAÇÕES AO PACIENTE|^[^\r\n]{2,100}\bPACIENTE\b)/imu', $page->text) === 1 ? $page->number : null;
        }
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

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " \t\n\r\0\x0B-");
    }
}
