<?php

namespace App\Livewire\Consulta\Receita;

use App\Services\Patient\PrescriptionReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Receita por foto, passo 1: o paciente fotografa (ou escolhe) a imagem; ela vai para uma área transitória privada,
 * a leitura é disparada e o paciente segue para a revisão. Exige consentimento de dados de saúde.
 */
class Nova extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $foto = null;

    public ?string $aviso = null;

    public function updatedFoto(): void
    {
        $this->aviso = null;
        try {
            $this->validate($this->regras());
        } catch (ValidationException $exception) {
            $this->aviso = $exception->validator->errors()->first();
            $this->foto = null;
        }
    }

    public function enviar(PrescriptionReviewService $service): ?RedirectResponse
    {
        $this->aviso = null;
        if (! auth()->user()->hasHealthConsent()) {
            $this->aviso = 'Para ler uma receita, aceite primeiro guardar seus dados de saúde no perfil.';

            return null;
        }
        try {
            $this->validate($this->regras());
        } catch (ValidationException $exception) {
            $this->aviso = $exception->validator->errors()->first();

            return null;
        }
        if (! PrescriptionReviewService::attempt(auth()->user())) {
            $this->aviso = 'Você atingiu o limite de receitas lidas por hora. Tente novamente mais tarde.';

            return null;
        }

        $prescription = $service->start(auth()->user(), $this->foto->getRealPath(), $this->foto->getClientOriginalExtension());
        $this->foto->delete();
        $this->foto = null;

        return redirect()->route('consulta.receita.revisar', $prescription);
    }

    /** @return array<string, list<string>> */
    private function regras(): array
    {
        return ['foto' => PrescriptionReviewService::photoRules()];
    }

    public function render(): View
    {
        return view('livewire.consulta.receita.nova', [
            'consentido' => auth()->user()->hasHealthConsent(),
            'maxMb' => round((int) config('bulai.prescription.max_image_kilobytes') / 1024),
        ]);
    }
}
