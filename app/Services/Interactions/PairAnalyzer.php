<?php

namespace App\Services\Interactions;

use App\Data\Knowledge\EvidenceReference;
use Illuminate\Support\Str;

/**
 * Decide o veredito de um par. Existência e gravidade vêm de `InteractionLookup`; o texto vem de trechos de bulas e
 * só é exibido após validação. Sem base estruturada ou sem evidência o resultado é explicitamente inconclusivo.
 */
class PairAnalyzer
{
    public function __construct(
        private readonly InteractionLookup $lookup,
        private readonly PairEvidenceRetriever $evidence,
        private readonly ExplanationGenerator $generator,
    ) {}

    /**
     * @param  array{id: int|null, name: string, ingredients: list<array{id: int, name: string}>}  $a
     * @param  array{id: int|null, name: string, ingredients: list<array{id: int, name: string}>}  $b
     * @return array<string, mixed> atributos de `interaction_pair_results` (exceto consultation_id e position)
     */
    public function analyze(array $a, array $b, ?string $retrievalMode = null): array
    {
        $runId = (string) Str::uuid();
        $base = [
            'medicine_a_id' => $a['id'], 'medicine_b_id' => $b['id'], 'label_a' => $a['name'], 'label_b' => $b['name'],
            'ingredients_a' => $a['ingredients'], 'ingredients_b' => $b['ingredients'],
            'limitations' => [], 'evidences' => [], 'timings' => [],
        ];
        $idsA = array_column($a['ingredients'], 'id');
        $idsB = array_column($b['ingredients'], 'id');

        if ($idsA === [] || $idsB === []) {
            $missing = array_filter([$idsA === [] ? $a['name'] : null, $idsB === [] ? $b['name'] : null]);

            return array_replace($base, ['verdict' => PairVerdict::MedicationNotIdentified, 'limitations' => ['Princípio ativo não identificado no catálogo para: '.implode(', ', $missing).'. Sem ele a consulta de interações não é possível.']]);
        }
        if (array_intersect($idsA, $idsB) !== []) {
            return array_replace($base, ['verdict' => PairVerdict::InsufficientEvidence, 'limitations' => ['Os dois medicamentos têm o mesmo princípio ativo (possível duplicidade). Este sistema não avalia duplicidade terapêutica.']]);
        }

        $timings = [];
        $started = hrtime(true);
        $structured = $this->lookup->between($idsA, $idsB);
        $timings['lookup_ms'] = $this->elapsed($started);

        $started = hrtime(true);
        $retrieval = $this->evidence->forPair($idsA, $idsB, $retrievalMode);
        $timings['retrieval_ms'] = $this->elapsed($started);
        $references = $retrieval['references'];
        $evidences = array_map(fn (EvidenceReference $reference): array => $reference->toArray(), $references);

        if ($structured === null) {
            return array_replace($base, $this->withoutStructured($references, $evidences, $retrieval['meta'], $timings));
        }

        $generated = ['explanation' => null, 'claims' => [], 'status' => ExplanationGenerator::STATUS_UNAVAILABLE, 'generation' => ['run_id' => $runId]];
        if ($references !== []) {
            $started = hrtime(true);
            $generated = $this->generator->explain($a['name'], $b['name'], $structured, $references, $runId);
            $timings['generation_ms'] = $this->elapsed($started);
        }

        $limitations = ['A gravidade segue a classificação da base DDInter; não é uma avaliação clínica individual.'];
        if ($structured->divergentSeverities) {
            $limitations[] = 'A base registra variantes com gravidades muito diferentes (ex.: por via de administração); exibimos a mais alta.';
        }
        if ($references === []) {
            $limitations[] = 'Nenhum trecho das bulas cadastradas descreve esta interação; a explicação textual não está disponível.';
        } elseif ($generated['status'] !== ExplanationGenerator::STATUS_GENERATED) {
            $limitations[] = 'A explicação em linguagem simples não pôde ser gerada ou validada; exibimos apenas os trechos originais das bulas.';
        }

        return array_replace($base, [
            'verdict' => $structured->divergentSeverities ? PairVerdict::ContradictoryEvidence : PairVerdict::InteractionFound,
            'severity' => $structured->severity,
            'severity_source' => 'ddinter',
            'structured' => $structured->toArray(),
            'explanation' => $generated['explanation'],
            'evidences' => $this->withClaims($evidences, $generated['claims']),
            'limitations' => $limitations,
            'generation' => $generated['generation'] + ['status' => $generated['status']],
            'retrieval' => $retrieval['meta'],
            'timings' => $timings,
        ]);
    }

    /**
     * @param  list<EvidenceReference>  $references
     * @param  list<array<string, mixed>>  $evidences
     * @param  array<string, mixed>  $meta
     * @param  array<string, int>  $timings
     * @return array<string, mixed>
     */
    private function withoutStructured(array $references, array $evidences, array $meta, array $timings): array
    {
        if ($references !== []) {
            return [
                'verdict' => PairVerdict::InsufficientEvidence,
                'evidences' => $evidences,
                'limitations' => ['Trechos de bulas citam o outro medicamento, mas a base estruturada não registra a interação. Não é possível concluir; os trechos abaixo precisam de avaliação profissional.'],
                'retrieval' => $meta, 'timings' => $timings,
            ];
        }

        return [
            'verdict' => PairVerdict::NotFoundInSources,
            'limitations' => ['Nenhuma interação foi encontrada nas fontes consultadas (DDInter e bulas cadastradas). Isso não comprova que a combinação seja segura.'],
            'retrieval' => $meta, 'timings' => $timings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $evidences
     * @param  list<array{text: string, evidence_chunk_ids: list<int>}>  $claims
     * @return list<array<string, mixed>>
     */
    private function withClaims(array $evidences, array $claims): array
    {
        foreach ($evidences as &$evidence) {
            $evidence['supports_claims'] = array_values(array_keys(array_filter($claims, fn (array $claim): bool => in_array($evidence['chunk_id'], $claim['evidence_chunk_ids'], true))));
        }

        return $evidences;
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
