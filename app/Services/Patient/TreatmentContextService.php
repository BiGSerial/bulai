<?php

namespace App\Services\Patient;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Interactions\InteractionLookup;
use App\Services\Interactions\InteractionSeverity;

/**
 * Contexto de tratamento de um usuário em relação a UM medicamento: se ele está em uso, com que posologia, alergias
 * cruzadas e interações estruturadas (DDInter) com cada medicamento em uso, classificadas por gravidade.
 *
 * Tudo aqui é determinístico (motor estruturado), nunca gerado por LLM. Sem consentimento de dados de saúde devolve
 * um contexto vazio e marcado como `consented: false`.
 */
final class TreatmentContextService
{
    public function __construct(
        private readonly InteractionLookup $lookup,
        private readonly AllergyChecker $allergies,
    ) {}

    /**
     * @return array{
     *   consented: bool,
     *   in_use: bool,
     *   posology: array{dosage: string|null, frequency: string|null, prescriber: string|null}|null,
     *   allergy_alerts: list<array{allergy: string, ingredient: string, via: string}>,
     *   interactions: list<array{medicine_id: int, medicine: string, severity: string, severity_label: string, divergent: bool}>,
     *   unresolved: list<string>,
     *   highest_severity: string|null,
     *   medications_count: int
     * }
     */
    public function for(User $user, AnvisaMedicine $medicine): array
    {
        $empty = ['consented' => false, 'in_use' => false, 'posology' => null, 'allergy_alerts' => [], 'interactions' => [], 'unresolved' => [], 'highest_severity' => null, 'medications_count' => 0];
        if (! $user->hasHealthConsent()) {
            return $empty;
        }

        $medicine->loadMissing('ingredients:id');
        $ingredientIds = $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $medications = PatientMedication::query()->with(['medicine.ingredients:id', 'prescription'])->where('user_id', $user->id)->get()
            ->filter(fn (PatientMedication $item): bool => $item->isActive())->values();

        $own = $medications->first(fn (PatientMedication $item): bool => $item->medicine_id === $medicine->id);
        $interactions = [];
        $unresolved = [];
        $highest = null;
        foreach ($medications as $item) {
            if ($item->medicine_id === $medicine->id) {
                continue;
            }
            if ($item->medicine === null) {
                // Remédio cadastrado pelo nome (fora do catálogo): não há bula nem princípio ativo para cruzar.
                $unresolved[] = $item->displayName().' (sem bula cadastrada no BULA-IA)';

                continue;
            }
            $otherIds = $item->medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($ingredientIds === [] || $otherIds === []) {
                $unresolved[] = $item->medicine->product_name;

                continue;
            }
            if (array_intersect($ingredientIds, $otherIds) !== []) {
                $interactions[] = ['medicine_id' => $item->medicine_id, 'medicine' => $item->medicine->product_name, 'severity' => 'duplicate', 'severity_label' => 'Mesmo princípio ativo (possível duplicidade)', 'divergent' => false];

                continue;
            }
            $structured = $this->lookup->between($ingredientIds, $otherIds);
            if ($structured === null) {
                continue;
            }
            $interactions[] = ['medicine_id' => $item->medicine_id, 'medicine' => $item->medicine->product_name, 'severity' => $structured->severity->value, 'severity_label' => $structured->severity->label(), 'divergent' => $structured->divergentSeverities];
            if ($highest === null || $structured->severity->rank() > $highest->rank()) {
                $highest = $structured->severity;
            }
        }
        usort($interactions, fn (array $a, array $b): int => (InteractionSeverity::tryFrom($b['severity'])?->rank() ?? 0) <=> (InteractionSeverity::tryFrom($a['severity'])?->rank() ?? 0));

        return [
            'consented' => true,
            'in_use' => $own !== null,
            'posology' => $own === null ? null : ['dosage' => $own->dosage, 'frequency' => $own->frequency, 'prescriber' => $own->prescription?->prescriber_name],
            'allergy_alerts' => $this->allergies->check($user->allergies()->get(), $medicine),
            'interactions' => $interactions,
            'unresolved' => $unresolved,
            'highest_severity' => $highest?->value,
            'medications_count' => $medications->count(),
        ];
    }

    /**
     * Texto determinístico (fatos do motor estruturado) que pode ser entregue ao modelo como evidência citável.
     * Nunca inclui dados identificáveis do usuário: apenas nomes de medicamentos e gravidades.
     *
     * @param  array<string, mixed>  $context
     */
    public function asEvidenceText(array $context, string $medicineName): ?string
    {
        if (! $context['consented']) {
            return null;
        }
        $lines = [];
        $lines[] = $context['in_use']
            ? sprintf('%s está registrado entre os medicamentos em uso pelo paciente%s.', $medicineName, $this->posologyText($context['posology']))
            : sprintf('%s não está registrado entre os medicamentos em uso pelo paciente.', $medicineName);
        foreach ($context['interactions'] as $interaction) {
            $lines[] = $interaction['severity'] === 'duplicate'
                ? sprintf('%s, em uso, tem o mesmo princípio ativo de %s (possível duplicidade; não avaliada por este sistema).', $interaction['medicine'], $medicineName)
                : sprintf('A base de interações (DDInter) registra interação de gravidade %s entre %s e %s, que está em uso.', mb_strtolower($interaction['severity_label']), $medicineName, $interaction['medicine']);
        }
        foreach ($context['allergy_alerts'] as $alert) {
            $lines[] = sprintf('O paciente registrou alergia a %s; %s %s %s.', $alert['allergy'], $medicineName, $alert['via'] === 'classe' ? 'pertence à mesma classe de' : 'contém', $alert['ingredient']);
        }
        if ($context['unresolved'] !== []) {
            $lines[] = 'Não foi possível verificar interações com: '.implode(', ', $context['unresolved']).' (princípio ativo não identificado ou remédio sem bula cadastrada).';
        }

        return implode(' ', $lines);
    }

    /** @param array{dosage: string|null, frequency: string|null, prescriber: string|null}|null $posology */
    private function posologyText(?array $posology): string
    {
        if ($posology === null) {
            return '';
        }
        $parts = array_filter([$posology['dosage'], $posology['frequency']]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }
}
