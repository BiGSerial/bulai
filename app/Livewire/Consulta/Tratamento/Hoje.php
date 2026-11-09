<?php

namespace App\Livewire\Consulta\Tratamento;

use App\Livewire\Consulta\Concerns\AtuaComoCuidador;
use App\Models\CaregiverLink;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Services\Caregiving\CaregiverLinkService;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Remédios de hoje: cada horário programado do tratamento, agrupado por período numa linha do tempo, com "Tomei" e
 * "Pular" em um toque. Ontem/Amanhã permitem conferir ou corrigir marcações; tudo é privado do paciente.
 *
 * Modo cuidador (`/cuidando/{vinculo}/hoje`): a mesma tela com os dados do paciente cuidado, a faixa "Você está vendo
 * os remédios de Fulano" e os botões de marcar só com a permissão (conferida no servidor pela policy a cada ação).
 */
class Hoje extends Component
{
    use AtuaComoCuidador;

    /** Deslocamento em dias em relação a hoje (-1 = ontem, 0 = hoje, 1 = amanhã). */
    public int $dia = 0;

    public function mount(?CaregiverLink $vinculo = null): void
    {
        $this->entrarNoVinculo($vinculo);
    }

    public function mudarDia(int $delta): void
    {
        $this->dia = max(-1, min(1, $this->dia + $delta));
    }

    public function irPara(int $dia): void
    {
        $this->dia = max(-1, min(1, $dia));
    }

    public function tomei(TreatmentScheduleService $schedule, int $medicationId, string $horario): void
    {
        $this->marcar($schedule, $medicationId, $horario, MedicationDoseEvent::STATUS_TAKEN);
    }

    public function pular(TreatmentScheduleService $schedule, int $medicationId, string $horario): void
    {
        $this->marcar($schedule, $medicationId, $horario, MedicationDoseEvent::STATUS_SKIPPED);
    }

    public function desfazer(TreatmentScheduleService $schedule, int $medicationId, string $horario): void
    {
        $medication = $this->remedio($medicationId);
        if ($medication === null) {
            return;
        }
        $vinculo = $this->vinculo();
        if ($vinculo !== null) {
            $this->authorize('markDose', $medication);
            app(CaregiverLinkService::class)->unmarkDose($vinculo, auth()->user(), $medication, CarbonImmutable::parse($horario));
        } else {
            $schedule->unmark(auth()->user(), $medication, CarbonImmutable::parse($horario));
        }
        $this->dispatch('notify', message: 'Marcação desfeita.');
    }

    private function marcar(TreatmentScheduleService $schedule, int $medicationId, string $horario, string $status): void
    {
        $medication = $this->remedio($medicationId);
        if ($medication === null) {
            return;
        }
        $vinculo = $this->vinculo();
        if ($vinculo !== null) {
            $this->authorize('markDose', $medication);
            app(CaregiverLinkService::class)->markDose($vinculo, auth()->user(), $medication, CarbonImmutable::parse($horario), $status);
            $quem = CaregiverLink::firstName($vinculo->patient).' tomou ';
        } else {
            $schedule->mark(auth()->user(), $medication, CarbonImmutable::parse($horario), $status);
            $quem = 'você tomou ';
        }
        $this->dispatch('notify', message: $status === MedicationDoseEvent::STATUS_TAKEN ? 'Anotado: '.$quem.$medication->displayName().'.' : 'Anotado: '.$medication->displayName().' pulado neste horário.');
    }

    private function remedio(int $medicationId): ?PatientMedication
    {
        return PatientMedication::query()->where('user_id', $this->paciente()->id)->find($medicationId);
    }

    public function render(TreatmentScheduleService $schedule): View
    {
        $vinculo = $this->vinculo();
        $user = $this->paciente();
        $consentido = $user->hasHealthConsent();
        $hoje = CarbonImmutable::now($user->treatmentTimezone())->startOfDay();
        $data = $hoje->addDays($this->dia);
        $agenda = $consentido ? self::anotarAjustes($schedule->agenda($user, $data)) : collect();

        $periodos = ['manha' => ['Manhã', 'sol', []], 'tarde' => ['Tarde', 'relogio', []], 'noite' => ['Noite', 'lua', []]];
        foreach ($agenda as $dose) {
            $hora = (int) substr($dose['time'], 0, 2);
            $chave = match (true) {
                $hora < 12 => 'manha', $hora < 18 => 'tarde', default => 'noite'
            };
            $periodos[$chave][2][] = $dose;
        }

        $remedios = $consentido ? PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)->orderBy('id')->get() : collect();

        return view('livewire.consulta.tratamento.hoje', [
            'vinculo' => $vinculo,
            'podeMarcar' => $this->podeGerenciar(),
            'consentido' => $consentido,
            'data' => $data,
            'titulo' => match ($this->dia) {
                -1 => 'Remédios de ontem', 1 => 'Remédios de amanhã', default => 'Remédios de hoje'
            },
            'periodos' => array_filter($periodos, fn (array $periodo): bool => $periodo[2] !== []),
            'total' => $agenda->count(),
            'feitos' => $agenda->filter(fn (array $dose): bool => in_array($dose['status'], [TreatmentScheduleService::DOSE_TAKEN, TreatmentScheduleService::DOSE_SKIPPED], true))->count(),
            'tomadas' => $agenda->where('status', TreatmentScheduleService::DOSE_TAKEN)->count(),
            'atrasadas' => $agenda->where('status', TreatmentScheduleService::DOSE_LATE)->count(),
            'temRemedios' => $remedios->isNotEmpty(),
            'temDinamico' => $agenda->contains(fn (array $dose): bool => $dose['dynamic']),
            'quandoPrecisar' => $remedios->filter(fn (PatientMedication $item): bool => $item->as_needed && $item->isActive())->values(),
        ]);
    }

    /**
     * Em remédios dinâmicos, uma dose projetada depois de uma tomada fora do horário ganha `ajustado` = "HH:MM" (o
     * horário real da dose anterior, de onde a cadeia foi recontada). As demais linhas ficam com `ajustado` nulo.
     *
     * @param  Collection<int, array<string, mixed>>  $agenda
     * @return Collection<int, array<string, mixed>>
     */
    public static function anotarAjustes(Collection $agenda): Collection
    {
        $base = [];

        return $agenda->sortBy('time')->values()->map(function (array $dose) use (&$base): array {
            $id = $dose['medication']->id;
            $dose['ajustado'] = $dose['dynamic'] && $dose['event'] === null ? ($base[$id] ?? null) : null;
            if ($dose['dynamic'] && $dose['taken_at'] !== null) {
                $base[$id] = $dose['taken_at']->format('H:i') !== $dose['time'] ? $dose['taken_at']->format('H:i') : null;
            }

            return $dose;
        });
    }
}
