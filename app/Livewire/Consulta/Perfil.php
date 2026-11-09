<?php

namespace App\Livewire\Consulta;

use App\Livewire\Consulta\Concerns\EditaPosologia;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\UserAuditEvent;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Knowledge\IngredientNameNormalizer;
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
    use EditaPosologia;

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

    /** Só remédios do próprio paciente. */
    protected function remedioEditavel(int $id): ?PatientMedication
    {
        return PatientMedication::query()->where('user_id', auth()->id())->find($id);
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
