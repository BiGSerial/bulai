<?php

namespace App\Livewire\Admin\Knowledge\Catalog;

use App\Jobs\ImportAnvisaCatalog;
use App\Models\Knowledge\AnvisaCatalogImport;
use App\Models\Knowledge\AnvisaMedicine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    public string $encoding = 'ISO-8859-1';

    public ?string $feedback = null;

    public string $feedbackTone = 'success';

    /** @return array<string, string> */
    public static function encodingOptions(): array
    {
        return ['ISO-8859-1' => 'ISO-8859-1 (padrão do arquivo da ANVISA)', 'UTF-8' => 'UTF-8'];
    }

    public function import(): void
    {
        $maxKilobytes = (int) config('knowledge.anvisa_catalog.max_kilobytes');
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', "max:{$maxKilobytes}"],
            'encoding' => ['required', Rule::in(array_keys(self::encodingOptions()))],
        ], [
            'file.required' => 'Selecione o arquivo CSV do catálogo.',
            'file.mimes' => 'Envie um arquivo no formato CSV ou TXT.',
            'file.mimetypes' => 'Envie um arquivo no formato CSV ou TXT.',
            'file.max' => 'O arquivo ultrapassa o tamanho máximo de '.round($maxKilobytes / 1024).' MB.',
            'encoding.in' => 'Selecione uma codificação válida.',
        ]);

        if (AnvisaCatalogImport::query()->active()->exists()) {
            $this->notify('Já existe uma importação em andamento. Aguarde a conclusão para enviar outro arquivo.', 'warning');

            return;
        }

        $sha256 = hash_file('sha256', $this->file->getRealPath());
        $previous = AnvisaCatalogImport::query()->where('sha256', $sha256)->first();
        if ($previous?->status === 'COMPLETED') {
            $this->notify('Este arquivo já foi importado anteriormente. Nenhuma alteração foi feita.', 'info');
            $this->reset('file');

            return;
        }

        $disk = config('knowledge.filesystem_disk');
        $storedPath = $this->file->storeAs('anvisa-catalog', Str::uuid()->toString().'.csv', $disk);
        $import = $previous ?? new AnvisaCatalogImport;
        $import->forceFill([
            'dataset_name' => 'ANVISA - Dados Abertos de Medicamentos',
            'original_filename' => Str::limit(basename($this->file->getClientOriginalName()), 200, ''),
            'sha256' => $sha256, 'encoding' => $this->encoding, 'delimiter' => ';', 'status' => 'QUEUED',
            'uploaded_by' => auth()->id(), 'stored_path' => $storedPath, 'error_reference' => null,
            'rows_read' => 0, 'rows_inserted' => 0, 'rows_updated' => 0, 'rows_skipped' => 0, 'rows_rejected' => 0,
            'metadata' => [], 'started_at' => now(), 'completed_at' => null,
        ])->save();

        ImportAnvisaCatalog::dispatch($import->id);
        $this->reset('file');
        $this->notify('Arquivo recebido. A importação foi colocada na fila e o progresso aparece abaixo.', 'success');
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    private function notify(string $message, string $tone): void
    {
        $this->feedback = $message;
        $this->feedbackTone = $tone;
    }

    public function render(): View
    {
        $history = AnvisaCatalogImport::query()->with('uploader:id,name')->latest('id')->limit(10)->get();

        return view('livewire.admin.knowledge.catalog.index', [
            'totalRecords' => AnvisaMedicine::query()->count(),
            'lastCompleted' => AnvisaCatalogImport::query()->where('status', 'COMPLETED')->latest('completed_at')->first(),
            'activeImport' => AnvisaCatalogImport::query()->active()->latest('id')->first(),
            'history' => $history,
            'maxMegabytes' => (int) round((int) config('knowledge.anvisa_catalog.max_kilobytes') / 1024),
            'encodingOptions' => self::encodingOptions(),
        ])->title('Catálogo ANVISA · BULAI');
    }
}
