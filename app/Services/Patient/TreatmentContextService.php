<?php

namespace App\Services\Patient;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Interactions\InteractionLookup;
use App\Services\Interactions\InteractionSeverity;
use App\Services\Safety\PromptInjectionDetector;

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
        private readonly PromptInjectionDetector $injection,
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
     * Só os achados que justificam um alerta na resposta do Perguntar: interação registrada com um remédio em uso, mesmo
     * princípio ativo de um remédio em uso ou alergia registrada que atinge o remédio perguntado. Sem achado devolve null e o
     * modelo não recebe nada do perfil, então não fala de tratamento nem de alergias sem necessidade. A posologia do próprio
     * tratamento entra só quando pedida (`$withPosology`, perguntas de dose).
     *
     * @param  array<string, mixed>  $context
     */
    public function alertsText(array $context, string $medicineName, bool $withPosology = false): ?string
    {
        if (! $context['consented']) {
            return null;
        }
        $lines = [];
        foreach ($context['interactions'] as $interaction) {
            $lines[] = $interaction['severity'] === 'duplicate'
                ? sprintf('%s, em uso pelo paciente, tem o mesmo princípio ativo de %s (possível duplicidade; não avaliada por este sistema).', $interaction['medicine'], $medicineName)
                : sprintf('A base de interações (DDInter) registra interação de gravidade %s entre %s e %s, que o paciente usa.', mb_strtolower($interaction['severity_label']), $medicineName, $interaction['medicine']);
        }
        foreach ($context['allergy_alerts'] as $alert) {
            $lines[] = sprintf('O paciente registrou alergia a %s; %s %s %s.', $this->injection->clean((string) $alert['allergy'], '(anotação omitida)'), $medicineName, $alert['via'] === 'classe' ? 'pertence à mesma classe de' : 'contém', $alert['ingredient']);
        }
        if ($withPosology && $context['in_use'] && ($posology = $this->posologyText($context['posology'])) !== '') {
            $lines[] = sprintf('No tratamento registrado pelo paciente, %s está anotado assim%s.', $medicineName, $posology);
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /** @param array{dosage: string|null, frequency: string|null, prescriber: string|null}|null $posology */
    private function posologyText(?array $posology): string
    {
        if ($posology === null) {
            return '';
        }
        // Anotação digitada pelo paciente ou lida da receita: dado, nunca instrução para a IA.
        $parts = array_filter(array_map(fn (?string $part): ?string => $part === null || $this->injection->flags($part) !== [] ? null : $part, [$posology['dosage'], $posology['frequency']]));

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    /**
     * UMA frase de risco em linguagem comum, a partir do contexto determinístico (alergia > interação > duplicidade).
     * `tone` é `danger` (alergia ou interação maior) ou `warning`.
     *
     * @param  list<array{medicine?: string, context?: array<string, mixed>}>  $treatments
     * @return array{text: string, tone: string}|null
     */
    public static function riskSentence(array $treatments): ?array
    {
        $text = null;
        $tone = 'warning';
        foreach ($treatments as $item) {
            $context = $item['context'] ?? [];
            $name = $item['medicine'] ?? 'este remédio';
            if (! empty($context['allergy_alerts'])) {
                return ['text' => 'Atenção: você registrou alergia a '.$context['allergy_alerts'][0]['allergy'].', e '.$name.' tem relação com ela. Fale com seu médico antes de usar.', 'tone' => 'danger'];
            }
            foreach ($context['interactions'] ?? [] as $interaction) {
                if ($interaction['severity'] === 'duplicate') {
                    $text ??= 'Atenção: '.$name.' tem a mesma substância de '.$interaction['medicine'].', que você já usa. Fale com seu médico.';

                    continue;
                }
                $sentence = 'Atenção: '.$name.' pode interagir com '.$interaction['medicine'].', que você usa. Fale com seu médico.';
                if (in_array($interaction['severity'], ['major', 'moderate'], true)) {
                    return ['text' => $sentence, 'tone' => $interaction['severity'] === 'major' ? 'danger' : 'warning'];
                }
                $text ??= $sentence;
            }
        }

        return $text === null ? null : ['text' => $text, 'tone' => $tone];
    }

    /**
     * Painel "No seu tratamento" (o mesmo cruzamento do site): remédio em uso, alergias, interações e o que não deu para verificar.
     *
     * @param  array{medicine: string, context: array<string, mixed>}  $item
     * @return array<string, mixed>
     */
    public static function panel(array $item): array
    {
        $context = $item['context'];

        return [
            'medicine' => (string) $item['medicine'],
            'consented' => (bool) ($context['consented'] ?? false),
            'in_use' => (bool) ($context['in_use'] ?? false),
            'posology' => $context['posology'] ?? null,
            'medications_count' => (int) ($context['medications_count'] ?? 0),
            'highest_severity' => $context['highest_severity'] ?? null,
            'allergy_alerts' => array_map(fn (array $a): array => ['allergy' => (string) $a['allergy'], 'ingredient' => (string) $a['ingredient'], 'via' => (string) ($a['via'] ?? '')], $context['allergy_alerts'] ?? []),
            'interactions' => array_map(fn (array $i): array => [
                'medicine' => (string) $i['medicine'],
                'severity' => (string) $i['severity'],
                'severity_label' => $i['severity'] === 'duplicate' ? 'Mesma substância (possível duplicidade)' : self::severityLabel((string) $i['severity']),
                'divergent' => (bool) ($i['divergent'] ?? false),
            ], $context['interactions'] ?? []),
            'unresolved' => array_values($context['unresolved'] ?? []),
        ];
    }

    /** Como no site: "Gravidade: Maior"; sem classificação, o texto da fonte. */
    public static function severityLabel(?string $severity): ?string
    {
        $value = InteractionSeverity::tryFrom((string) $severity);

        return match ($value) {
            null => null,
            InteractionSeverity::Unknown => $value->label(),
            default => 'Gravidade: '.$value->label(),
        };
    }
}
