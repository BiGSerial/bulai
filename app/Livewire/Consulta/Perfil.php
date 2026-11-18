<?php

namespace App\Livewire\Consulta;

use App\Livewire\Consulta\Concerns\EditaPosologia;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\HealthProfileService;
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

    public function aceitarConsentimento(HealthProfileService $perfil): void
    {
        $perfil->acceptConsent(auth()->user(), request()->ip());
    }

    public function apagarDadosDeSaude(HealthProfileService $perfil): void
    {
        $perfil->deleteHealthData(auth()->user(), request()->ip());
        $this->mensagem = 'Seus dados de saúde (alergias, receitas, remédios e perguntas) foram apagados e o consentimento foi revogado.';
    }

    public function adicionarAlergia(HealthProfileService $perfil): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $this->mensagem = null;
        try {
            $perfil->addAllergy(auth()->user(), $this->substancia, $this->reacao);
        } catch (ValidationException $exception) {
            $this->mensagem = $exception->validator->errors()->first();

            return;
        }
        $this->reset('substancia', 'reacao');
    }

    public function removerAlergia(int $id): void
    {
        PatientAllergy::query()->where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function adicionarReceita(HealthProfileService $perfil): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $this->mensagem = null;
        try {
            $perfil->addPrescription(auth()->user(), [
                'prescriber' => $this->prescritor,
                'registration' => $this->registroPrescritor,
                'issued_at' => $this->dataReceita,
                'notes' => $this->observacoesReceita,
            ]);
        } catch (ValidationException $exception) {
            $this->mensagem = $exception->validator->errors()->first();

            return;
        }
        $this->reset('prescritor', 'registroPrescritor', 'dataReceita', 'observacoesReceita');
        $this->mostrarFormularioReceita = false;
    }

    public function removerReceita(int $id): void
    {
        PatientPrescription::query()->where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function adicionarMedicamento(HealthProfileService $perfil, int $id): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $medication = $perfil->addCatalogMedication(auth()->user(), $id);
        if ($medication === null) {
            return;
        }
        $this->busca = '';
        $this->editarPosologia($medication->id);
    }

    /** O catálogo não tem o remédio: cadastra pelo nome digitado e avisa a administração (pendência). */
    public function cadastrarPeloNome(HealthProfileService $perfil): void
    {
        if (! $this->temConsentimento()) {
            return;
        }
        $this->mensagem = null;
        $nome = trim($this->busca);
        try {
            $medication = $perfil->addMedicationByName(auth()->user(), $nome);
        } catch (ValidationException $exception) {
            $this->mensagem = $exception->validator->errors()->first();

            return;
        }
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
        return app(HealthProfileService::class)->hasConsent(auth()->user());
    }

    public function render(MedicationSearchService $search): View
    {
        $consentido = $this->temConsentimento();
        $remedios = $consentido ? PatientMedication::query()->with(['medicine', 'prescription'])->where('user_id', auth()->id())->orderBy('id')->get() : collect();
        $usadosIds = $remedios->pluck('medicine_id')->filter()->all();

        return view('livewire.consulta.perfil', [
            'consentido' => $consentido,
            'alergias' => $consentido ? PatientAllergy::query()->where('user_id', auth()->id())->orderBy('substance')->get() : collect(),
            'receitas' => $consentido ? PatientPrescription::query()->with('medications.medicine')->withCount('medications')->where('user_id', auth()->id())->orderByDesc('issued_at')->orderByDesc('id')->get() : collect(),
            // Tratamento terminado (data final já passou) sai da lista e vai para o histórico, do mais recente ao mais antigo.
            'emUso' => $remedios->filter(fn (PatientMedication $item): bool => $item->isActive())->values(),
            'encerrados' => $remedios->reject(fn (PatientMedication $item): bool => $item->isActive())->sortByDesc('ends_at')->values(),
            'resultados' => $consentido ? array_values(array_filter($search->search($this->busca, 6), fn (array $item): bool => ! in_array($item['id'], $usadosIds, true))) : [],
            'buscou' => strlen(trim($this->busca)) >= 3,
        ]);
    }
}
