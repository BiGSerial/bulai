<?php

namespace App\Services\Patient;

use App\Models\Consultation\MedicationQuestion;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\User;
use App\Services\Interactions\InteractionLookup;
use App\Services\Interactions\InteractionSeverity;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resumo de saúde que o paciente leva à consulta (PDF em `consulta.resumo`): o que importa para o médico, sem nada gerado por
 * IA. Alergias registradas, remédios em uso com dose, horários, receita e adesão dos últimos dias (doses marcadas no app),
 * alertas do motor estruturado (alergia que atinge um remédio em uso, interação DDInter e mesmo princípio ativo entre os
 * remédios em uso), tratamentos encerrados há pouco, receitas e as dúvidas recentes do paciente.
 */
final class MedicalSummaryService
{
    /** Janela da adesão (dias até hoje, inclusive). */
    public const ADHERENCE_DAYS = 30;

    /** Tratamentos encerrados nesta janela ainda aparecem no resumo. */
    public const ENDED_DAYS = 90;

    public function __construct(
        private readonly TreatmentScheduleService $schedule,
        private readonly InteractionLookup $lookup,
        private readonly AllergyChecker $allergies,
    ) {}

    /**
     * @return array{
     *   paciente: array{nome: string, email: string},
     *   gerado_em: CarbonImmutable,
     *   periodo: array{inicio: CarbonImmutable, fim: CarbonImmutable, dias: int},
     *   alergias: list<array{substancia: string, reacao: string|null}>,
     *   alertas: list<array{tipo: string, texto: string, gravidade: string|null}>,
     *   em_uso: list<array{nome: string, principio_ativo: string|null, dose: string|null, como: string, inicio: string|null, fim: string|null, prescritor: string|null, observacoes: string|null, adesao: array{previstas: int, tomadas: int, puladas: int, sem_marcacao: int, percentual: int|null}|null}>,
     *   encerrados: list<array{nome: string, dose: string|null, fim: string}>,
     *   receitas: list<array{prescritor: string, registro: string|null, data: string|null, remedios: list<string>}>,
     *   duvidas: list<array{data: string, pergunta: string}>
     * }
     */
    public function build(User $user, ?CarbonImmutable $now = null): array
    {
        $timezone = $user->treatmentTimezone();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $inicio = $now->startOfDay()->subDays(self::ADHERENCE_DAYS - 1);

        $remedios = PatientMedication::query()->with(['medicine.ingredients', 'prescription'])->where('user_id', $user->id)->orderBy('id')->get();
        $emUso = $remedios->filter(fn (PatientMedication $item): bool => $item->isActive())->values();
        $alergias = $user->allergies()->orderBy('substance')->get();
        $adesao = $this->adherence($user, $inicio, $now);

        return [
            'paciente' => ['nome' => (string) $user->name, 'email' => (string) $user->email],
            'gerado_em' => $now,
            'periodo' => ['inicio' => $inicio, 'fim' => $now, 'dias' => self::ADHERENCE_DAYS],
            'alergias' => $alergias->map(fn ($alergia): array => ['substancia' => (string) $alergia->substance, 'reacao' => $alergia->reaction])->all(),
            'alertas' => $this->alerts($emUso, $alergias),
            'em_uso' => $emUso->map(fn (PatientMedication $item): array => [
                'nome' => $item->displayName(),
                'principio_ativo' => $item->medicine?->ingredients->pluck('canonical_name')->filter()->map(fn ($nome): string => mb_strtolower((string) $nome))->join(' + ') ?: null,
                'dose' => $item->dosage,
                'como' => $this->howToTake($item),
                'inicio' => $item->started_at?->format('d/m/Y'),
                'fim' => $item->ends_at?->format('d/m/Y'),
                'prescritor' => $item->prescription?->prescriberDisplayName(),
                'observacoes' => $item->notes,
                'adesao' => $item->as_needed ? null : ($adesao[$item->id] ?? null),
            ])->all(),
            'encerrados' => $remedios->reject(fn (PatientMedication $item): bool => $item->isActive())
                ->filter(fn (PatientMedication $item): bool => $item->ends_at !== null && $item->ends_at->greaterThanOrEqualTo($now->subDays(self::ENDED_DAYS)->startOfDay()))
                ->sortByDesc('ends_at')->map(fn (PatientMedication $item): array => ['nome' => $item->displayName(), 'dose' => $item->dosage, 'fim' => $item->ends_at->format('d/m/Y')])->values()->all(),
            // Só receitas conferidas pelo paciente (as lidas por foto e ainda não conferidas podem ter erro de leitura).
            'receitas' => PatientPrescription::query()->with('medications.medicine')->where('user_id', $user->id)
                ->where(fn ($query) => $query->where('status', PatientPrescription::STATUS_CONFIRMED)->orWhere('source', PatientPrescription::SOURCE_MANUAL))
                ->orderByDesc('issued_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (PatientPrescription $receita): array => [
                    'prescritor' => $receita->prescriberDisplayName(),
                    'registro' => $receita->prescriber_registration,
                    'data' => $receita->issued_at?->format('d/m/Y'),
                    'remedios' => $receita->medications->map(fn (PatientMedication $item): string => $item->displayName())->all(),
                ])->all(),
            'duvidas' => MedicationQuestion::query()->where('user_id', $user->id)->where('created_at', '>=', $inicio->utc())->latest()->limit(5)->get(['question', 'created_at'])
                ->map(fn (MedicationQuestion $pergunta): array => ['data' => $pergunta->created_at->setTimezone($timezone)->format('d/m'), 'pergunta' => (string) $pergunta->question])->all(),
        ];
    }

    /** "08:00, 20:00" · "a cada 8 h, a partir da última dose" · "só quando precisar", com a frequência escrita na receita. */
    private function howToTake(PatientMedication $item): string
    {
        $quando = match (true) {
            $item->as_needed => 'só quando precisar',
            $item->isDynamic() => $item->intervalLabel().', a partir da última dose',
            $item->scheduledTimes() !== [] => implode(', ', $item->scheduledTimes()),
            default => 'sem horários definidos',
        };

        return $item->frequency && ! $item->as_needed ? $item->frequency.' ('.$quando.')' : $quando;
    }

    /**
     * Doses previstas na janela (até agora) e o que o paciente marcou. "Sem marcação" = o horário passou sem "Tomei" nem
     * "Pular": não quer dizer que não tomou, só que não marcou.
     *
     * @return array<int, array{previstas: int, tomadas: int, puladas: int, sem_marcacao: int, percentual: int|null}>
     */
    private function adherence(User $user, CarbonImmutable $inicio, CarbonImmutable $now): array
    {
        $porRemedio = [];
        for ($dia = $inicio; $dia->lessThanOrEqualTo($now); $dia = $dia->addDay()) {
            foreach ($this->schedule->agenda($user, $dia, $now) as $dose) {
                $status = $dose['status'];
                if (! in_array($status, [TreatmentScheduleService::DOSE_TAKEN, TreatmentScheduleService::DOSE_SKIPPED, TreatmentScheduleService::DOSE_LATE], true)) {
                    continue;
                }
                $id = $dose['medication']->id;
                $porRemedio[$id] ??= ['previstas' => 0, 'tomadas' => 0, 'puladas' => 0, 'sem_marcacao' => 0, 'percentual' => null];
                $porRemedio[$id]['previstas']++;
                $porRemedio[$id][match ($status) {
                    TreatmentScheduleService::DOSE_TAKEN => 'tomadas',
                    TreatmentScheduleService::DOSE_SKIPPED => 'puladas',
                    default => 'sem_marcacao',
                }]++;
            }
        }
        foreach ($porRemedio as &$linha) {
            $linha['percentual'] = (int) round(100 * $linha['tomadas'] / $linha['previstas']);
        }

        return $porRemedio;
    }

    /**
     * @param  Collection<int, PatientMedication>  $emUso
     * @param  Collection<int, PatientAllergy>  $alergias
     * @return list<array{tipo: string, texto: string, gravidade: string|null}>
     */
    private function alerts(Collection $emUso, Collection $alergias): array
    {
        $alertas = [];
        $catalogados = $emUso->filter(fn (PatientMedication $item): bool => $item->medicine !== null)->values();
        foreach ($catalogados as $item) {
            foreach ($this->allergies->check($alergias, $item->medicine) as $alerta) {
                $relacao = $alerta['via'] === 'classe' ? 'é da mesma classe de' : 'contém';
                $alertas[] = ['tipo' => 'alergia', 'texto' => "{$item->displayName()} {$relacao} {$alerta['ingredient']}; alergia registrada a {$alerta['allergy']}.", 'gravidade' => null];
            }
        }
        $interacoes = [];
        for ($i = 0; $i < $catalogados->count(); $i++) {
            for ($j = $i + 1; $j < $catalogados->count(); $j++) {
                [$a, $b] = [$catalogados[$i], $catalogados[$j]];
                $idsA = $a->medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $idsB = $b->medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
                if ($idsA === [] || $idsB === [] || $a->medicine_id === $b->medicine_id) {
                    continue;
                }
                if (array_intersect($idsA, $idsB) !== []) {
                    $interacoes[] = ['tipo' => 'duplicidade', 'texto' => "{$a->displayName()} e {$b->displayName()} têm o mesmo princípio ativo.", 'gravidade' => null, 'ordem' => 4];

                    continue;
                }
                $registro = $this->lookup->between($idsA, $idsB);
                if ($registro !== null) {
                    $interacoes[] = ['tipo' => 'interacao', 'texto' => "{$a->displayName()} + {$b->displayName()}", 'gravidade' => $registro->severity->label(), 'ordem' => $registro->severity->rank()];
                }
            }
        }
        usort($interacoes, fn (array $x, array $y): int => $y['ordem'] <=> $x['ordem']);

        return [...$alertas, ...array_map(fn (array $item): array => array_diff_key($item, ['ordem' => true]), $interacoes)];
    }

    /** Gravidade para a cor do selo no PDF. */
    public static function severityTone(?string $label): string
    {
        return match ($label) {
            InteractionSeverity::Major->label() => 'alta',
            InteractionSeverity::Moderate->label() => 'media',
            default => 'baixa',
        };
    }
}
