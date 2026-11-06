<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\UserAuditEvent;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Knowledge\IngredientNameNormalizer;
use App\Services\Treatment\DoseScheduleParser;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Perfil de saúde do paciente: consentimento, alergias, receitas médicas e remédios em uso (com posologia e horários).
 * Remédio que o catálogo não reconhece pode ser cadastrado pelo nome (vira pendência para a administração).
 * Tudo é apagável a qualquer momento; o apagamento também remove as perguntas registradas.
 */
class Perfil extends Component
{
    /** Aba inicial (remedios | receitas | alergias); a troca é feita no cliente, sem recarregar. */
    public string $aba = 'remedios';

    public string $substancia = '';

    public string $reacao = '';

    public string $busca = '';

    public ?string $mensagem = null;

    public string $prescritor = '';

    public string $registroPrescritor = '';

    public ?string $dataReceita = null;

    public string $observacoesReceita = '';

    public bool $mostrarFormularioReceita = false;

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

    public function mount(): void
    {
        $aba = (string) request()->query('aba', 'remedios');
        $this->aba = in_array($aba, ['remedios', 'receitas', 'alergias'], true) ? $aba : 'remedios';
    }

    public function aceitarConsentimento(): void
    {
        PatientProfile::query()->updateOrCreate(['user_id' => auth()->id()], ['health_consent_at' => now()]);
        $this->auditar('health_consent_given');
    }

    public function apagarDadosDeSaude(): void
    {
        $userId = auth()->id();
        $count = PatientAllergy::query()->where('user_id', $userId)->delete()
            + PatientMedication::query()->where('user_id', $userId)->delete()
            + PatientPrescription::query()->where('user_id', $userId)->delete()
            + MedicationQuestion::query()->where('user_id', $userId)->delete();
        PatientProfile::query()->where('user_id', $userId)->delete();
        $this->auditar('health_data_deleted', ['records' => $count]);
        $this->mensagem = 'Seus dados de saúde (alergias, receitas, remédios e perguntas) foram apagados e o consentimento foi revogado.';
    }

    public function adicionarAlergia(IngredientNameNormalizer $normalizer): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $this->mensagem = null;
        $substance = trim($this->substancia);
        $normalized = $normalizer->normalize($substance);
        if (mb_strlen($substance) < 3 || mb_strlen($substance) > 120 || $normalized === '') {
            $this->mensagem = 'Informe o nome da substância ou classe (de 3 a 120 caracteres).';

            return;
        }

        PatientAllergy::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'normalized_substance' => $normalized],
            ['substance' => $substance, 'reaction' => trim($this->reacao) !== '' ? mb_substr(trim($this->reacao), 0, 255) : null],
        );
        $this->reset('substancia', 'reacao');
    }

    public function removerAlergia(int $id): void
    {
        PatientAllergy::query()->where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function adicionarReceita(): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $this->mensagem = null;
        try {
            $data = $this->validate([
                'prescritor' => ['required', 'string', 'min:3', 'max:120'],
                'registroPrescritor' => ['nullable', 'string', 'max:40'],
                'dataReceita' => ['nullable', 'date', 'before_or_equal:today'],
                'observacoesReceita' => ['nullable', 'string', 'max:500'],
            ]);
        } catch (ValidationException $exception) {
            $this->mensagem = $exception->validator->errors()->first();

            return;
        }

        PatientPrescription::query()->create([
            'user_id' => auth()->id(),
            'prescriber_name' => trim($data['prescritor']),
            'prescriber_registration' => filled($data['registroPrescritor']) ? trim($data['registroPrescritor']) : null,
            'issued_at' => $data['dataReceita'] ?: null,
            'notes' => filled($data['observacoesReceita']) ? trim($data['observacoesReceita']) : null,
        ]);
        $this->reset('prescritor', 'registroPrescritor', 'dataReceita', 'observacoesReceita');
        $this->mostrarFormularioReceita = false;
    }

    public function removerReceita(int $id): void
    {
        PatientPrescription::query()->where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function adicionarMedicamento(int $id): void
    {
        if (! $this->temConsentimento() || ! AnvisaMedicine::query()->whereKey($id)->exists()) {
            return;
        }
        $medication = PatientMedication::query()->firstOrCreate(['user_id' => auth()->id(), 'medicine_id' => $id], ['catalog_status' => PatientMedication::CATALOG_MATCHED]);
        $this->busca = '';
        $this->editarPosologia($medication->id);
    }

    /** O catálogo não tem o remédio: cadastra pelo nome digitado e avisa a administração (pendência). */
    public function cadastrarPeloNome(TreatmentScheduleService $schedule): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $this->mensagem = null;
        $nome = trim($this->busca);
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) {
            $this->mensagem = 'Digite o nome do remédio (de 3 a 120 letras) para cadastrar.';

            return;
        }
        $medication = $schedule->register(auth()->user(), ['name_as_written' => $nome]);
        $this->busca = '';
        $this->mensagem = "“{$nome}” entrou na sua lista. Ainda não está no nosso catálogo: nossa equipe vai conferir.";
        $this->editarPosologia($medication->id);
    }

    public function removerMedicamento(int $id): void
    {
        PatientMedication::query()->where('user_id', auth()->id())->whereKey($id)->delete();
        if ($this->editandoId === $id) {
            $this->cancelarPosologia();
        }
    }

    public function editarPosologia(int $id): void
    {
        $medication = PatientMedication::query()->where('user_id', auth()->id())->find($id);
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
        $medication = $this->editandoId !== null ? PatientMedication::query()->where('user_id', auth()->id())->find($this->editandoId) : null;
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

        $prescriptionId = filled($data['prescription_id']) ? (int) $data['prescription_id'] : null;
        if ($prescriptionId !== null && ! PatientPrescription::query()->where('user_id', auth()->id())->whereKey($prescriptionId)->exists()) {
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
        $this->cancelarPosologia();
    }

    private function temConsentimento(): bool
    {
        return PatientProfile::query()->where('user_id', auth()->id())->whereNotNull('health_consent_at')->exists();
    }

    /** @param array<string, mixed> $details */
    private function auditar(string $event, array $details = []): void
    {
        UserAuditEvent::query()->create(['user_id' => auth()->id(), 'actor_id' => auth()->id(), 'event' => $event, 'details' => $details, 'ip' => request()->ip()]);
    }

    public function render(MedicationSearchService $search): View
    {
        $consentido = $this->temConsentimento();
        $emUso = $consentido ? PatientMedication::query()->with(['medicine', 'prescription'])->where('user_id', auth()->id())->orderBy('id')->get() : collect();
        $usadosIds = $emUso->pluck('medicine_id')->filter()->all();

        return view('livewire.consulta.perfil', [
            'consentido' => $consentido,
            'alergias' => $consentido ? PatientAllergy::query()->where('user_id', auth()->id())->orderBy('substance')->get() : collect(),
            'receitas' => $consentido ? PatientPrescription::query()->with('medications.medicine')->withCount('medications')->where('user_id', auth()->id())->orderByDesc('issued_at')->orderByDesc('id')->get() : collect(),
            'emUso' => $emUso,
            'resultados' => $consentido ? array_values(array_filter($search->search($this->busca, 6), fn (array $item): bool => ! in_array($item['id'], $usadosIds, true))) : [],
            'buscou' => strlen(trim($this->busca)) >= 3,
        ]);
    }
}
