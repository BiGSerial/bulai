<?php

namespace App\Livewire\Consulta\Receita;

use App\Jobs\ExtractPrescription;
use App\Models\PatientPrescription;
use App\Services\Patient\PrescriptionExtractionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
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

    public function enviar(PrescriptionExtractionService $service): ?RedirectResponse
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

        $key = 'receita:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.prescription.rate_limit_per_hour'))) {
            $this->aviso = 'Você atingiu o limite de receitas lidas por hora. Tente novamente mais tarde.';

            return null;
        }
        RateLimiter::hit($key, 3600);

        $stored = $service->stash($this->foto->getRealPath(), $this->foto->getClientOriginalExtension() ?: 'jpg');
        $this->foto->delete();
        $this->foto = null;

        $prescription = PatientPrescription::query()->create([
            'user_id' => auth()->id(),
            'source' => PatientPrescription::SOURCE_PHOTO,
            'status' => PatientPrescription::STATUS_PENDING,
            'prescriber_name' => 'Receita em leitura',
        ]);
        match (config('bulai.consultation.execution')) {
            'sync' => ExtractPrescription::dispatchSync($prescription->id, $stored),
            'queue' => ExtractPrescription::dispatch($prescription->id, $stored)->onQueue((string) config('bulai.consultation.queue')),
            default => ExtractPrescription::dispatchAfterResponse($prescription->id, $stored),
        };

        return redirect()->route('consulta.receita.revisar', $prescription);
    }

    /** @return array<string, list<string>> */
    private function regras(): array
    {
        return ['foto' => ['required', 'mimes:jpg,jpeg,png,webp', 'image', 'max:'.(int) config('bulai.prescription.max_image_kilobytes')]];
    }

    public function render(): View
    {
        return view('livewire.consulta.receita.nova', [
            'consentido' => auth()->user()->hasHealthConsent(),
            'maxMb' => round((int) config('bulai.prescription.max_image_kilobytes') / 1024),
        ]);
    }
}
