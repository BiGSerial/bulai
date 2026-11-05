<?php

namespace App\Services\Evaluation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Knowledge\IngredientNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Gera os datasets versionados a partir do gabarito de identificação escrito à mão (seed).
 * O rótulo de interação de cada par vem do DDInter pelos NOMES esperados dos ingredientes, nunca pelo resolvedor do sistema:
 * assim o sistema precisa ir do produto até o ingrediente por conta própria.
 */
final class DatasetBuilder
{
    private const SEVERITY_RANK = ['unknown' => 0, 'minor' => 1, 'moderate' => 2, 'major' => 3];

    public function __construct(private readonly IngredientNameNormalizer $normalizer) {}

    /**
     * @return array{identification: int, interactions: int, positives: int, negatives: int, dropped: list<string>, excluded_unreferenced: list<string>}
     */
    public function build(string $seedPath, string $directory, string $name = 'v1', bool $withInteractions = true): array
    {
        $seed = json_decode((string) File::get($seedPath), true, flags: JSON_THROW_ON_ERROR);
        $reference = $this->referencePairs();

        $items = [];
        $dropped = [];
        foreach ($seed['items'] as $entry) {
            $medicine = $this->medicineFor($entry['query']);
            if ($medicine === null) {
                $dropped[] = $entry['query'];

                continue;
            }
            $items[] = [
                'id' => count($items) + 1,
                'query' => $entry['query'],
                'registration_number' => $medicine->registration_number,
                'product_name' => $medicine->product_name,
                'expected_ingredients' => array_values(array_unique(array_map(fn (string $name): string => $this->normalizer->normalize($name), $entry['expected']))),
            ];
        }

        $eligible = [];
        $excluded = [];
        foreach ($items as $item) {
            $unreferenced = array_values(array_filter($item['expected_ingredients'], fn (string $name): bool => ! isset($reference['names'][$name])));
            if ($unreferenced === []) {
                $eligible[] = $item;
            } else {
                $excluded[] = $item['query'].' ('.implode(', ', $unreferenced).')';
            }
        }

        $pairs = [];
        $positives = 0;
        for ($i = 0; $i < count($eligible); $i++) {
            for ($j = $i + 1; $j < count($eligible); $j++) {
                [$a, $b] = [$eligible[$i], $eligible[$j]];
                if (array_intersect($a['expected_ingredients'], $b['expected_ingredients']) !== []) {
                    continue;
                }
                $severity = $this->goldSeverity($a['expected_ingredients'], $b['expected_ingredients'], $reference['pairs']);
                $positives += $severity !== null ? 1 : 0;
                $pairs[] = [
                    'id' => count($pairs) + 1, 'a_registration' => $a['registration_number'], 'a_name' => $a['product_name'],
                    'b_registration' => $b['registration_number'], 'b_name' => $b['product_name'],
                    'gold_interaction' => $severity !== null, 'gold_severity' => $severity,
                ];
            }
        }

        $meta = ['version' => $name, 'seed_sha256' => hash_file('sha256', $seedPath), 'reference_imports' => DB::table('drug_interaction_imports')->where('status', 'COMPLETED')->orderBy('original_filename')->pluck('sha256', 'original_filename')->all()];
        File::ensureDirectoryExists($directory);
        $unreferencedExpected = array_values(array_unique(array_merge(...array_map(fn (array $item): array => array_values(array_filter($item['expected_ingredients'], fn (string $n): bool => ! isset($reference['names'][$n]))), $items) ?: [[]])));
        $this->write($directory.'/identification.'.$name.'.json', $meta + ['description' => $seed['description'], 'items' => $items, 'dropped_not_in_catalog' => $dropped, 'expected_names_absent_from_reference' => $unreferencedExpected]);
        if (! $withInteractions) {
            return ['identification' => count($items), 'interactions' => 0, 'positives' => 0, 'negatives' => 0, 'dropped' => $dropped, 'excluded_unreferenced' => $unreferencedExpected];
        }
        $this->write($directory.'/interactions.protocol30.'.$name.'.json', $meta + [
            'description' => 'Reprodução do protocolo da seção 3.5.1 do projeto: 30 pares estratificados (10 graves, 5 moderadas, 5 leves, 10 sem interação), amostra determinística (semente 42) do conjunto completo. Mesmo rótulo e mesmas limitações do conjunto completo.',
            'sampling' => ['seed' => 42, 'strata' => self::PROTOCOL_STRATA],
            'items' => $this->protocolSample($pairs),
        ]);
        $this->write($directory.'/interactions.'.$name.'.json', $meta + [
            'description' => 'Pares de produtos; rótulo = existência/gravidade no DDInter para os ingredientes ESPERADOS (gabarito manual). Negativo = par ausente no DDInter (não prova ausência clínica).',
            'excluded_unreferenced' => $excluded, 'items' => $pairs,
        ]);

        return ['identification' => count($items), 'interactions' => count($pairs), 'positives' => $positives, 'negatives' => count($pairs) - $positives, 'dropped' => $dropped, 'excluded_unreferenced' => $excluded];
    }

    private const PROTOCOL_STRATA = ['major' => 10, 'moderate' => 5, 'minor' => 5, 'none' => 10];

    /**
     * @param  list<array<string, mixed>>  $pairs
     * @return list<array<string, mixed>>
     */
    private function protocolSample(array $pairs): array
    {
        $sample = [];
        foreach (self::PROTOCOL_STRATA as $stratum => $count) {
            $pool = array_values(array_filter($pairs, fn (array $pair): bool => $stratum === 'none' ? ! $pair['gold_interaction'] : $pair['gold_severity'] === $stratum));
            array_push($sample, ...Sampling::seeded($pool, $count, 42));
        }

        return array_map(fn (array $pair, int $index): array => ['id' => $index + 1] + array_diff_key($pair, ['id' => true]), $sample, array_keys($sample));
    }

    private function medicineFor(string $query): ?AnvisaMedicine
    {
        $normalized = $this->normalizer->normalize($query);

        return AnvisaMedicine::query()->whereNotNull('registration_number')->where('registration_status', 'Ativo')
            ->where(fn ($query) => $query->where('normalized_product_name', 'like', $normalized.'%')->orWhere('normalized_product_name', 'like', '% '.$normalized.'%'))
            ->orderByRaw('case when normalized_product_name = ? then 0 else 1 end', [$normalized])->orderBy('id')->first();
    }

    /** @return array{names: array<string, true>, pairs: array<string, string>} nomes (sem qualificadores) e par ordenado => gravidade máxima */
    private function referencePairs(): array
    {
        $names = [];
        $pairs = [];
        DB::table('drug_interactions')->orderBy('id')->select(['normalized_drug_a_name', 'normalized_drug_b_name', 'severity'])->chunk(20000, function ($rows) use (&$names, &$pairs): void {
            foreach ($rows as $row) {
                $a = $this->normalizer->withoutQualifiers($row->normalized_drug_a_name);
                $b = $this->normalizer->withoutQualifiers($row->normalized_drug_b_name);
                $names[$a] = $names[$b] = true;
                $key = $a < $b ? $a.'|'.$b : $b.'|'.$a;
                if (! isset($pairs[$key]) || self::SEVERITY_RANK[$row->severity] > self::SEVERITY_RANK[$pairs[$key]]) {
                    $pairs[$key] = $row->severity;
                }
            }
        });

        return ['names' => $names, 'pairs' => $pairs];
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @param  array<string, string>  $pairs
     */
    private function goldSeverity(array $a, array $b, array $pairs): ?string
    {
        $top = null;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $key = $x < $y ? $x.'|'.$y : $y.'|'.$x;
                if (isset($pairs[$key]) && ($top === null || self::SEVERITY_RANK[$pairs[$key]] > self::SEVERITY_RANK[$top])) {
                    $top = $pairs[$key];
                }
            }
        }

        return $top;
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }
}
