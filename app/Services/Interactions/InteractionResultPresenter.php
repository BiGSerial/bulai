<?php

namespace App\Services\Interactions;

use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\InteractionPairResult;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use App\Services\Patient\AllergyChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Como a consulta de interações é pedida e mostrada ao paciente, no site (Livewire `Pesquisa`/`Resultado`) e no aplicativo
 * (`/api/mobile/v1/interacoes`): limite por hora, a faixa e a frase de cada par, e os alertas de alergia.
 */
final class InteractionResultPresenter
{
    public function __construct(
        private readonly InteractionConsultationService $consultations,
        private readonly AllergyChecker $allergies,
    ) {}

    /**
     * Começa a consulta respeitando o limite por hora e uma consulta por vez.
     *
     * @param  list<int>  $medicineIds
     *
     * @throws InteractionRequestException com a frase para a tela
     */
    public function start(User $user, array $medicineIds): InteractionConsultation
    {
        $medicineIds = array_values(array_unique(array_map('intval', $medicineIds)));
        if (count($medicineIds) < 2) {
            throw new InteractionRequestException('Selecione pelo menos dois medicamentos.');
        }
        $max = (int) config('bulai.consultation.max_medicines');
        if (count($medicineIds) > $max) {
            throw new InteractionRequestException("Limite de {$max} medicamentos por consulta.");
        }
        $key = 'consulta:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            throw new InteractionRequestException('Você atingiu o limite de consultas por hora. Tente novamente mais tarde.', limited: true);
        }
        $lock = Cache::lock('consulta-iniciar:'.$user->id, 10);
        if (! $lock->get()) {
            throw new InteractionRequestException('Já existe uma consulta começando. Aguarde um instante.');
        }
        try {
            RateLimiter::hit($key, 3600);

            return $this->consultations->start($user, $medicineIds);
        } finally {
            $lock->release();
        }
    }

    /**
     * Por que um remédio não pode entrar na lista da consulta (ou nulo, se pode): repetido ou acima do máximo.
     *
     * @param  list<int>  $selected
     */
    public function additionRefusal(array $selected, int $medicineId): ?string
    {
        if (in_array($medicineId, $selected, true)) {
            return 'Este medicamento já está na lista.';
        }
        $max = (int) config('bulai.consultation.max_medicines');

        return count($selected) >= $max ? "Limite de {$max} medicamentos por consulta." : null;
    }

    /**
     * Nível, tom e a frase em linguagem comum de um par (a gravidade vem do motor estruturado).
     *
     * @return array{level: string, tone: string, phrase: string}
     */
    public function band(InteractionPairResult $result): array
    {
        $severity = $result->severity instanceof InteractionSeverity ? $result->severity : InteractionSeverity::tryFrom((string) $result->severity);
        $found = in_array($result->verdict, [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true);

        return match (true) {
            $found && $severity === InteractionSeverity::Major => ['level' => 'major', 'tone' => 'danger', 'phrase' => 'Pode ter interação forte. Fale com seu médico antes de usar os dois.'],
            $found && $severity === InteractionSeverity::Moderate => ['level' => 'moderate', 'tone' => 'warning', 'phrase' => 'Pode ter interação moderada. Fale com seu médico ou farmacêutico.'],
            $found && $severity === InteractionSeverity::Minor => ['level' => 'minor', 'tone' => 'warning', 'phrase' => 'Pode ter interação leve. Vale comentar com seu médico ou farmacêutico.'],
            $found => ['level' => 'unclassified', 'tone' => 'neutral', 'phrase' => 'Há interação registrada, mas a gravidade não foi classificada. Fale com seu médico.'],
            $result->verdict === PairVerdict::NotFoundInSources => ['level' => 'none', 'tone' => 'neutral', 'phrase' => 'Nenhuma interação registrada. Isto não significa que a combinação seja segura.'],
            default => ['level' => 'inconclusive', 'tone' => 'neutral', 'phrase' => 'Não foi possível concluir se há interação. Na dúvida, fale com seu médico.'],
        };
    }

    /**
     * Alergias registradas que batem com os remédios (só com consentimento de saúde).
     *
     * @param  list<int>  $medicineIds
     * @return list<array{medicine: string, medicine_id: int, allergy: string, via: string, ingredient: string, message: string}>
     */
    public function allergyAlerts(User $user, array $medicineIds): array
    {
        if ($medicineIds === [] || ! $user->hasHealthConsent() || ! $user->allergies()->exists()) {
            return [];
        }
        $allergies = $user->allergies()->get();
        $alerts = [];
        foreach (AnvisaMedicine::query()->with('ingredients')->whereIn('id', $medicineIds)->get() as $medicine) {
            foreach ($this->allergies->check($allergies, $medicine) as $match) {
                $alerts[] = [
                    'medicine' => $medicine->product_name,
                    'medicine_id' => $medicine->id,
                    'allergy' => $match['allergy'],
                    'via' => $match['via'],
                    'ingredient' => $match['ingredient'],
                    'message' => 'Você registrou alergia a '.$match['allergy'].($match['via'] === 'classe' ? ' (mesma classe de '.$match['ingredient'].')' : ' (contém '.$match['ingredient'].')'),
                ];
            }
        }

        return $alerts;
    }
}
