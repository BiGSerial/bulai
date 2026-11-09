<?php

namespace App\Livewire\Consulta\Cuidado;

use App\Livewire\Consulta\Concerns\AtuaComoCuidador;
use App\Livewire\Consulta\Concerns\EditaPosologia;
use App\Models\CaregiverLink;
use App\Models\PatientMedication;
use App\Services\Caregiving\CaregiverLinkService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Remédios de quem a pessoa cuida (`/cuidando/{vinculo}/remedios`): a lista com posologia e horários (permissão "ver")
 * e o formulário "Dose e horários" (permissão "marcar e editar", conferida pela `PatientMedicationPolicy::update` a cada
 * ação). Cada alteração vai para a auditoria do vínculo. Cadastrar e tirar remédios continua com o paciente.
 */
class Remedios extends Component
{
    use AtuaComoCuidador;
    use EditaPosologia;

    public ?string $mensagem = null;

    public function mount(CaregiverLink $vinculo): void
    {
        $this->entrarNoVinculo($vinculo);
    }

    protected function remedioEditavel(int $id): ?PatientMedication
    {
        $medication = PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $this->paciente()->id)->find($id);
        if ($medication !== null) {
            $this->authorize('update', $medication);
        }

        return $medication;
    }

    /** @param array<string, mixed> $mudancas */
    protected function posologiaSalva(PatientMedication $medication, array $mudancas): void
    {
        unset($mudancas['updated_at']);
        if ($mudancas !== []) {
            app(CaregiverLinkService::class)->medicationUpdated($this->vinculo(), auth()->user(), $medication, $mudancas);
        }
        $this->mensagem = 'Dose e horários de '.$medication->displayName().' salvos.';
    }

    public function render(): View
    {
        $vinculo = $this->vinculo();
        $paciente = $vinculo->patient;

        return view('livewire.consulta.cuidado.remedios', [
            'vinculo' => $vinculo,
            'nome' => CaregiverLink::firstName($paciente),
            'podeEditar' => $this->podeGerenciar(),
            'remedios' => PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $paciente->id)->orderBy('id')->get(),
        ]);
    }
}
