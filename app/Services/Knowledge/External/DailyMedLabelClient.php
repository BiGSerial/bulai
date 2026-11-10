<?php

namespace App\Services\Knowledge\External;

use App\Models\Knowledge\ExternalLabelSection;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reserva do openFDA: a API pública da DailyMed (U.S. National Library of Medicine), com as mesmas bulas SPL da FDA em XML
 * (https://dailymed.nlm.nih.gov/dailymed/app-support-web-services.cfm). Busca pelo nome, baixa o XML de cada candidata e
 * extrai tipo do documento, produto, princípios ativos (nome e UNII) e as seções pelo código LOINC.
 */
final class DailyMedLabelClient implements LabelSource
{
    /** Bula de medicamento de prescrição para uso humano (LOINC 34391-3). */
    public const HUMAN_PRESCRIPTION_LABEL = '34391-3';

    private const NS = 'urn:hl7-org:v3';

    public function candidates(string $genericName, int $limit): array
    {
        $response = $this->http()->get('/spls.json', ['drug_name' => $genericName, 'pagesize' => max(1, min(100, $limit))]);
        if (! $response->successful()) {
            throw new RuntimeException('DAILYMED_SEARCH_HTTP_'.$response->status());
        }
        $labels = [];
        foreach ((array) $response->json('data', []) as $item) {
            if (! is_string($item['setid'] ?? null)) {
                continue;
            }
            $xml = $this->http()->accept('*/*')->get('/spls/'.rawurlencode($item['setid']).'.xml');
            $label = $xml->successful() ? $this->parse($xml->body()) : null;
            if ($label !== null && $label['document_type'] === self::HUMAN_PRESCRIPTION_LABEL) {
                unset($label['document_type']);
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return array{document_type: string|null, set_id: string, version: int|null, effective_date: string|null, title: string, labeler: string|null, substances: list<string>, unii: list<string>, sections: array<string, string>}|null
     */
    public function parse(string $xml): ?array
    {
        $document = new DOMDocument;
        if (@$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) === false) {
            return null;
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('v', self::NS);
        $value = fn (string $query): ?string => ($node = $xpath->query($query)?->item(0)) !== null ? trim((string) $node->nodeValue) : null;
        $attribute = fn (string $query, string $name): ?string => ($node = $xpath->query($query)?->item(0)) instanceof DOMElement && $node->getAttribute($name) !== '' ? $node->getAttribute($name) : null;

        // Princípios ativos de todos os produtos da bula; mais de um (associação ou kit) torna a bula inelegível no importador.
        $substances = [];
        $uniis = [];
        foreach ($xpath->query("//v:ingredient[@classCode='ACTIB' or @classCode='ACTIM' or @classCode='ACTIR']/v:ingredientSubstance | //v:activeIngredient/v:activeIngredientSubstance") ?: [] as $substance) {
            /** @var DOMElement $substance */
            $name = trim((string) $xpath->query('v:name', $substance)?->item(0)?->nodeValue);
            if ($name !== '') {
                $substances[mb_strtoupper($name)] = true;
                $code = $xpath->query('v:code', $substance)?->item(0);
                if ($code instanceof DOMElement && $code->getAttribute('code') !== '') {
                    $uniis[$code->getAttribute('code')] = true;
                }
            }
        }

        $sections = [];
        foreach (array_keys(ExternalLabelSection::SECTIONS) as $code) {
            $section = $xpath->query("//v:section[v:code/@code='{$code}']")?->item(0);
            if ($section instanceof DOMElement) {
                $text = $this->text($document->saveXML($section) ?: '');
                if ($text !== '') {
                    $sections[$code] = $text;
                }
            }
        }

        $setId = $attribute('/v:document/v:setId', 'root');
        if ($setId === null) {
            return null;
        }
        $effective = $attribute('/v:document/v:effectiveTime', 'value');
        $product = $value('//v:manufacturedProduct/v:manufacturedProduct/v:name | //v:manufacturedProduct/v:manufacturedMedicine/v:name');
        $names = array_keys($substances);

        return [
            'document_type' => $attribute('/v:document/v:code', 'code'),
            'set_id' => $setId,
            'version' => ($version = $attribute('/v:document/v:versionNumber', 'value')) !== null ? (int) $version : null,
            'effective_date' => $effective !== null && preg_match('/^(\d{4})(\d{2})(\d{2})/', $effective, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null,
            // O título do documento nas bulas da FDA costuma ser o aviso dos destaques; o nome do produto é mais útil.
            'title' => trim(mb_strtoupper((string) $product).($names !== [] && ! in_array(mb_strtoupper((string) $product), $names, true) ? ' ('.implode(', ', $names).')' : '')),
            'labeler' => $value('/v:document/v:author/v:assignedEntity/v:representedOrganization/v:name'),
            'substances' => $names,
            'unii' => array_keys($uniis),
            'sections' => $sections,
        ];
    }

    /** Texto legível de uma seção: parágrafos, itens, linhas de tabela e títulos viram linhas; marcação é descartada. */
    private function text(string $xml): string
    {
        $xml = (string) preg_replace(['#<br\s*/?>#i', '#</(paragraph|item|tr|title|caption|list|table)>#i'], "\n", $xml);
        $xml = (string) preg_replace('#</(td|th)>#i', ' ; ', $xml);
        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line), " ;\t"), explode("\n", html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8')));

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('bulai.external_labels.dailymed.base_url'), '/'))
            ->timeout((int) config('bulai.external_labels.dailymed.timeout'))
            ->withUserAgent('BULA-IA/1.0 (projeto academico)')
            ->retry(2, 500, throw: false);
    }
}
