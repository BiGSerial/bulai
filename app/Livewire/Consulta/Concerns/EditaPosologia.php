<?php

namespace App\Livewire\Consulta\Concerns;

use App\Models\PatientMedication;
use App\Services\Patient\HealthProfileService;
use Carbon\CarbonImmutable;
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
     * @var array{dosage: string, frequency: string, started_at: string|null, ends_at: string|null, continuous: bool, prescription_id: int|string|null, notes: string, times: list<string>, as_needed: bool, reminders_enabled: bool, interval_hours: int|null, schedule_mode: string}
     */
    public array $posologia = self::POSOLOGIA_VAZIA;

    private const POSOLOGIA_VAZIA = HealthProfileService::EMPTY_POSOLOGY;

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
        $this->posologia = app(HealthProfileService::class)->posologyOf($medication);
    }

    /** Ao mudar "quando tomar" sem horários definidos, sugere horários a partir do texto. */
    public function updatedPosologiaFrequency(string $value): void
    {
        $this->posologia = app(HealthProfileService::class)->applyFrequency($this->posologia, $value);
    }

    /** Atalho de "quando tomar" tocado ("2 vezes ao dia"): refaz os horários sugeridos do zero. */
    public function escolherFrequencia(string $frequencia): void
    {
        $this->posologia = app(HealthProfileService::class)->choosePreset($this->posologia, mb_substr($frequencia, 0, 120));
    }

    /** "7 dias": o último dia é o início + 6 (o primeiro dia conta). */
    public function escolherDuracao(int $dias): void
    {
        $dias = max(1, min(365, $dias));
        $inicio = CarbonImmutable::parse(($this->posologia['started_at'] ?? null) ?: now()->toDateString());
        $this->posologia['continuous'] = false;
        $this->posologia['ends_at'] = $inicio->addDays($dias - 1)->toDateString();
    }

    public function updatedPosologiaContinuous(bool $continuo): void
    {
        if ($continuo) {
            $this->posologia['ends_at'] = null;
        }
    }

    public function escolherDose(string $dose): void
    {
        $this->posologia['dosage'] = mb_substr($dose, 0, 120);
    }

    public function adicionarHorario(): void
    {
        if (count($this->posologia['times']) >= 12) {
            return;
        }
        $this->posologia['as_needed'] = false;
        $ultimo = end($this->posologia['times']);
        $this->posologia['times'][] = is_string($ultimo) && preg_match('/^(\d{2}):\d{2}$/', $ultimo, $hora) === 1 ? sprintf('%02d:00', ((int) $hora[1] + 4) % 24) : '08:00';
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
            $mudancas = app(HealthProfileService::class)->updatePosology($medication, $this->posologia);
        } catch (ValidationException $exception) {
            $this->mensagem = $exception->validator->errors()->first();

            return;
        }
        $this->posologiaSalva($medication, $mudancas);
        $this->cancelarPosologia();
    }
}
