<?php

namespace App\Livewire\Consulta\Concerns;

use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Services\Treatment\DoseScheduleParser;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Validation\ValidationException;

/**
 * Formulário "Dose e horários" de um remédio do tratamento, usado em Meus remédios (o próprio paciente) e nos remédios
 * de quem é cuidado (modo cuidador). Quem usa diz qual remédio pode ser editado (`remedioEditavel()`, onde fica a
 * autorização) e pode reagir ao salvar (`posologiaSalva()`, por exemplo para a auditoria do cuidador).
 *
 * A tela usa o componente `<x-consulta.form-posologia>`.
 */
trait EditaPosologia
{
    /** Remédio em uso cuja posologia está sendo editada (id de `patient_medications`). */
    public ?int $editandoId = null;

    /**
     * `interval_hours` vem da frequência ("de 8 em 8 horas"); com ele, `schedule_mode` escolhe entre contar a partir do
     * horário real em que o paciente toma (`interval`, padrão) ou horários fixos todos os dias (`fixed`).
     *
     * @var array{dosage: string, frequency: string, started_at: string|null, ends_at: string|null, prescription_id: int|string|null, notes: string, times: list<string>, as_needed: bool, reminders_enabled: bool, interval_hours: int|null, schedule_mode: string}
     */
    public array $posologia = self::POSOLOGIA_VAZIA;

    private const POSOLOGIA_VAZIA = ['dosage' => '', 'frequency' => '', 'started_at' => null, 'ends_at' => null, 'prescription_id' => null, 'notes' => '', 'times' => [], 'as_needed' => false, 'reminders_enabled' => true, 'interval_hours' => null, 'schedule_mode' => PatientMedication::MODE_FIXED];

    /** Remédio que a pessoa logada pode editar agora (ou nulo). Pode recusar com 403. */
    abstract protected function remedioEditavel(int $id): ?PatientMedication;

    /** Chamado depois de salvar, com os campos que mudaram. @param array<string, mixed> $mudancas */
    protected function posologiaSalva(PatientMedication $medication, array $mudancas): void {}

    public function editarPosologia(int $id): void
    {
        $medication = $this->remedioEditavel($id);
        if ($medication === null) {
            return;
        }
        $this->editandoId = $medication->id;
        $this->posologia = [
            'dosage' => (string) $medication->dosage,
            'frequency' => (string) $medication->frequency,
            'started_at' => $medication->started_at?->format('Y-m-d'),
            'ends_at' => $medication->ends_at?->format('Y-m-d'),
            'prescription_id' => $medication->prescription_id,
            'notes' => (string) $medication->notes,
            'times' => TreatmentScheduleService::cleanTimes((array) ($medication->dose_times ?? [])),
            'as_needed' => (bool) $medication->as_needed,
            'reminders_enabled' => (bool) $medication->reminders_enabled,
            'interval_hours' => (int) $medication->interval_minutes > 0 ? intdiv((int) $medication->interval_minutes, 60) : null,
            'schedule_mode' => $medication->isDynamic() ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED,
        ];
    }

    /** Ao mudar "quando tomar" sem horários definidos, sugere horários a partir do texto. */
    public function updatedPosologiaFrequency(string $value): void
    {
        $schedule = app(DoseScheduleParser::class)->parse($value);
        if ($schedule === null) {
            $this->posologia['interval_hours'] = null;
            $this->posologia['schedule_mode'] = PatientMedication::MODE_FIXED;

            return;
        }
        $this->posologia['as_needed'] = $schedule['as_needed'];
        if ($this->posologia['times'] === [] || $schedule['as_needed']) {
            $this->posologia['times'] = $schedule['times'];
        }
        // Frequência por intervalo: contar a partir de quando o paciente toma é o padrão (ele pode trocar para horários fixos).
        $this->posologia['interval_hours'] = $schedule['interval_hours'];
        $this->posologia['schedule_mode'] = $schedule['interval_hours'] !== null ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED;
    }

    public function adicionarHorario(): void
    {
        if (count($this->posologia['times']) >= 12) {
            return;
        }
        $this->posologia['as_needed'] = false;
        $this->posologia['times'][] = $this->posologia['times'] === [] ? '08:00' : '';
    }

    public function tirarHorario(int $posicao): void
    {
        unset($this->posologia['times'][$posicao]);
        $this->posologia['times'] = array_values($this->posologia['times']);
    }

    public function cancelarPosologia(): void
    {
        $this->editandoId = null;
        $this->posologia = self::POSOLOGIA_VAZIA;
    }

    public function salvarPosologia(): void
    {
        $medication = $this->editandoId !== null ? $this->remedioEditavel($this->editandoId) : null;
        if ($medication === null) {
            return;
        }
        $this->mensagem = null;
        try {
            $data = $this->validate([
                'posologia.dosage' => ['nullable', 'string', 'max:120'],
                'posologia.frequency' => ['nullable', 'string', 'max:120'],
                'posologia.started_at' => ['nullable', 'date'],
                'posologia.ends_at' => ['nullable', 'date', 'after_or_equal:posologia.started_at'],
                'posologia.prescription_id' => ['nullable', 'integer'],
                'posologia.notes' => ['nullable', 'string', 'max:255'],
                'posologia.times' => ['array', 'max:12'],
                'posologia.times.*' => ['nullable', 'string', 'max:5'],
                'posologia.as_needed' => ['boolean'],
                'posologia.reminders_enabled' => ['boolean'],
                'posologia.interval_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
                'posologia.schedule_mode' => ['required', 'in:'.PatientMedication::MODE_FIXED.','.PatientMedication::MODE_INTERVAL],
            ], ['posologia.ends_at.after_or_equal' => 'A data de término deve ser igual ou posterior ao início.'])['posologia'];
        } catch (ValidationException $exception) {
            $this->mensagem = $exception->validator->errors()->first();

            return;
        }

        // A receita precisa ser do dono do remédio (o paciente), mesmo quando quem edita é quem cuida dele.
        $prescriptionId = filled($data['prescription_id']) ? (int) $data['prescription_id'] : null;
        if ($prescriptionId !== null && ! PatientPrescription::query()->where('user_id', $medication->user_id)->whereKey($prescriptionId)->exists()) {
            $prescriptionId = null;
        }

        $intervalMinutes = filled($data['interval_hours']) && ! (bool) $data['as_needed'] ? (int) $data['interval_hours'] * 60 : null;
        $dinamico = $intervalMinutes !== null && $data['schedule_mode'] === PatientMedication::MODE_INTERVAL;
        $times = (bool) $data['as_needed'] ? [] : TreatmentScheduleService::cleanTimes((array) $data['times']);

        $medication->update([
            'interval_minutes' => $intervalMinutes,
            'schedule_mode' => $dinamico ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED,
            'dosage' => filled($data['dosage']) ? trim($data['dosage']) : null,
            'frequency' => filled($data['frequency']) ? trim($data['frequency']) : null,
            'started_at' => $data['started_at'] ?: null,
            'ends_at' => $data['ends_at'] ?: null,
            'prescription_id' => $prescriptionId,
            'notes' => filled($data['notes']) ? trim($data['notes']) : null,
            // No modo dinâmico só o primeiro horário importa: é onde a cadeia começa antes da primeira marcação.
            'dose_times' => $dinamico ? array_slice($times, 0, 1) : $times,
            'as_needed' => (bool) $data['as_needed'],
            'reminders_enabled' => (bool) $data['reminders_enabled'],
        ]);
        $this->posologiaSalva($medication, $medication->getChanges());
        $this->cancelarPosologia();
    }
}
