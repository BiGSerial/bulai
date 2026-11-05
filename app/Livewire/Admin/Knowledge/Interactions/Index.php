<?php

namespace App\Livewire\Admin\Knowledge\Interactions;

use App\Jobs\ImportDrugInteractions;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\DrugInteractionImport;
use App\Services\Knowledge\DrugInteractionCsvImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Ingestão dos CSVs de interações medicamentosas (DDInter) e visão geral da base. */
class Index extends Component
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    public ?string $feedback = null;

    public string $feedbackTone = 'success';

    public function import(): void
    {
        $maxKilobytes = (int) config('knowledge.drug_interactions.max_kilobytes');
        $maxFiles = (int) config('knowledge.drug_interactions.max_files');
        $this->validate([
            'files' => ['required', 'array', "max:{$maxFiles}"],
            'files.*' => ['file', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', "max:{$maxKilobytes}"],
        ], [
            'files.required' => 'Selecione ao menos um arquivo CSV.',
            'files.max' => "Envie no máximo {$maxFiles} arquivos por vez.",
            'files.*.mimes' => 'Envie arquivos no formato CSV ou TXT.',
            'files.*.mimetypes' => 'Envie arquivos no formato CSV ou TXT.',
            'files.*.max' => 'Um dos arquivos ultrapassa o tamanho máximo de '.round($maxKilobytes / 1024).' MB.',
        ]);

        $queued = 0;
        $repeated = 0;
        $disk = config('knowledge.filesystem_disk');
        foreach ($this->files as $file) {
            $sha256 = hash_file('sha256', $file->getRealPath());
            $previous = DrugInteractionImport::query()->where('sha256', $sha256)->first();
            if ($previous?->status === 'COMPLETED' || $previous?->updated_at?->gt(now()->subHours(2)) && in_array($previous->status, DrugInteractionImport::ACTIVE_STATUSES, true)) {
                $repeated++;

                continue;
            }
            $import = $previous ?? new DrugInteractionImport;
            $import->forceFill([
                'dataset_name' => DrugInteractionCsvImporter::DATASET,
                'original_filename' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
                'sha256' => $sha256, 'status' => 'QUEUED', 'uploaded_by' => auth()->id(),
                'stored_path' => $file->storeAs('drug-interactions', Str::uuid()->toString().'.csv', $disk), 'error_reference' => null,
                'rows_read' => 0, 'rows_inserted' => 0, 'rows_updated' => 0, 'rows_skipped' => 0, 'rows_rejected' => 0,
                'metadata' => [], 'started_at' => now(), 'completed_at' => null,
            ])->save();
            ImportDrugInteractions::dispatch($import->id);
            $queued++;
        }

        $this->reset('files');
        if ($queued === 0) {
            $this->notify('Estes arquivos já foram importados ou estão em andamento. Nenhuma alteração foi feita.', 'info');

            return;
        }
        $this->notify(($queued === 1 ? '1 arquivo recebido' : "{$queued} arquivos recebidos").'. A importação roda na fila e o progresso aparece abaixo.'.($repeated > 0 ? " {$repeated} já importado(s) foram ignorados." : ''), 'success');
    }

    public function render(): View
    {
        return view('livewire.admin.knowledge.interactions.index', [
            'total' => DrugInteraction::query()->count(),
            'bySeverity' => DrugInteraction::query()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity')->all(),
            'drugs' => DrugInteraction::query()->selectRaw('count(distinct drug_id) as total')->fromSub(
                DrugInteraction::query()->select('drug_a_external_id as drug_id')->union(DrugInteraction::query()->select('drug_b_external_id as drug_id')),
                'ids',
            )->value('total') ?? 0,
            'activeImports' => DrugInteractionImport::query()->active()->latest('id')->get(),
            'history' => DrugInteractionImport::query()->with('uploader:id,name')->latest('id')->limit(15)->get(),
            'maxMegabytes' => (int) round((int) config('knowledge.drug_interactions.max_kilobytes') / 1024),
            'maxFiles' => (int) config('knowledge.drug_interactions.max_files'),
        ])->title('Interações · BULAI');
    }

    private function notify(string $message, string $tone): void
    {
        $this->feedback = $message;
        $this->feedbackTone = $tone;
    }
}
