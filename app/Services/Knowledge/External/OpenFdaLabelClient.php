<?php

namespace App\Services\Knowledge\External;

use App\Models\Knowledge\ExternalLabelSection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Bulas de prescrição da FDA pelo openFDA (https://open.fda.gov/apis/drug/label/, dados CC0): as mesmas bulas SPL publicadas
 * na DailyMed, já com as seções em texto. Busca pelo nome genérico em inglês e devolve as candidatas normalizadas.
 */
final class OpenFdaLabelClient implements LabelSource
{
    /** Campo do openFDA de cada seção importada (e a tabela correspondente, quando houver). */
    private const FIELDS = [
        ExternalLabelSection::INTERACTIONS => ['drug_interactions', 'drug_interactions_table'],
        ExternalLabelSection::ADVERSE_REACTIONS => ['adverse_reactions', null],
    ];

    /**
     * @return list<array{set_id: string, version: int|null, effective_date: string|null, title: string, labeler: string|null, substances: list<string>, unii: list<string>, sections: array<string, string>}>
     */
    public function candidates(string $genericName, int $limit): array
    {
        $query = ['search' => sprintf('openfda.generic_name:"%s" AND openfda.product_type:"HUMAN PRESCRIPTION DRUG"', str_replace('"', '', $genericName)), 'limit' => max(1, min(100, $limit))];
        if (($key = config('bulai.external_labels.openfda.api_key')) !== null && $key !== '') {
            $query['api_key'] = $key;
        }
        $response = Http::timeout((int) config('bulai.external_labels.openfda.timeout'))
            ->withUserAgent('BULA-IA/1.0 (projeto academico)')
            // "Nenhum resultado" é 404 no openFDA: não adianta repetir; erros de rede e 5xx/429 são repetidos.
            ->retry(2, 1000, fn (Throwable $exception): bool => ! ($exception instanceof RequestException && $exception->response->status() === 404), throw: false)
            ->get((string) config('bulai.external_labels.openfda.url'), $query);
        if ($response->status() === 404) {
            return [];
        }
        if (! $response->successful()) {
            throw new RuntimeException('OPENFDA_HTTP_'.$response->status());
        }

        $labels = [];
        foreach ((array) $response->json('results', []) as $result) {
            $openFda = (array) ($result['openfda'] ?? []);
            if (! is_string($result['set_id'] ?? null) || $openFda === []) {
                continue;
            }
            $sections = [];
            foreach (self::FIELDS as $code => [$field, $table]) {
                $text = implode("\n", array_filter((array) ($result[$field] ?? []), 'is_string'));
                if ($table !== null) {
                    $text .= "\n".implode("\n", array_map($this->tableText(...), array_filter((array) ($result[$table] ?? []), 'is_string')));
                }
                if (trim($text) !== '') {
                    $sections[$code] = trim($text);
                }
            }
            $substances = array_values(array_unique(array_filter((array) ($openFda['substance_name'] ?? []), 'is_string')));
            $brand = is_string($openFda['brand_name'][0] ?? null) ? mb_strtoupper($openFda['brand_name'][0]) : null;
            $effective = is_string($result['effective_time'] ?? null) && preg_match('/^(\d{4})(\d{2})(\d{2})/', $result['effective_time'], $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
            $labels[] = [
                'set_id' => $result['set_id'],
                'version' => is_numeric($result['version'] ?? null) ? (int) $result['version'] : null,
                'effective_date' => $effective,
                'title' => trim(($brand ?? '').($substances !== [] && ! in_array($brand, $substances, true) ? ' ('.implode(', ', $substances).')' : '')),
                'labeler' => is_string($openFda['manufacturer_name'][0] ?? null) ? $openFda['manufacturer_name'][0] : null,
                'substances' => $substances,
                'unii' => array_values(array_filter((array) ($openFda['unii'] ?? []), 'is_string')),
                'sections' => $sections,
            ];
        }

        return $labels;
    }

    /** Tabela HTML da bula em linhas de texto ("célula ; célula"). */
    private function tableText(string $html): string
    {
        $html = (string) preg_replace(['#<br\s*/?>#i', '#</(tr|caption|p)>#i'], "\n", $html);
        $html = (string) preg_replace('#</(td|th)>#i', ' ; ', $html);
        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line), " ;\t"), explode("\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
