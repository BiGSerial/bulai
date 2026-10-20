<?php

namespace App\Livewire\Admin\Knowledge;

use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeBatchIngestionService;
use App\Services\Knowledge\KnowledgeSourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Upload extends Component
{
    use WithFileUploads;

    public ?int $sourceId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    public ?int $completedBatchId = null;

    /** @var array<string, int> */
    public array $summary = [];

    public ?string $feedback = null;

    public ?string $errorId = null;

    public bool $sourceModalOpen = false;

    public string $newSourceName = '';

    public string $newSourceCollection = '';

    public string $newSourceType = KnowledgeSource::TYPE_REGULATORY;

    public string $newSourceUrl = '';

    public string $newSourceCountry = '';

    public string $newSourceDescription = '';

    public function openSourceModal(): void
    {
        $this->resetValidation([
            'newSourceName',
            'newSourceCollection',
            'newSourceType',
            'newSourceUrl',
            'newSourceCountry',
            'newSourceDescription',
        ]);
        $this->sourceModalOpen = true;
    }

    public function closeSourceModal(): void
    {
        $this->sourceModalOpen = false;
    }

    public function createSource(KnowledgeSourceService $service): void
    {
        $validated = $this->validate([
            'newSourceName' => ['required', 'string', 'max:255'],
            'newSourceCollection' => ['required', 'string', 'max:255'],
            'newSourceType' => ['required', Rule::in(array_keys(KnowledgeSource::typeOptions()))],
            'newSourceUrl' => ['nullable', 'url:http,https', 'max:2048'],
            'newSourceCountry' => ['nullable', 'string', 'max:100'],
            'newSourceDescription' => ['nullable', 'string', 'max:2000'],
        ], [
            'required' => 'Preencha :attribute.',
            'url' => 'Informe uma URL válida iniciada por http:// ou https://.',
        ], [
            'newSourceName' => 'o nome',
            'newSourceCollection' => 'a base/coleção',
            'newSourceType' => 'o tipo',
            'newSourceUrl' => 'a URL da fonte',
            'newSourceCountry' => 'o país',
            'newSourceDescription' => 'a descrição',
        ]);

        try {
            $source = $service->create([
                'name' => $validated['newSourceName'],
                'collection' => $validated['newSourceCollection'],
                'type' => $validated['newSourceType'],
                'url' => $validated['newSourceUrl'],
                'country' => $validated['newSourceCountry'],
                'description' => $validated['newSourceDescription'],
            ]);
        } catch (ValidationException $exception) {
            $this->addError('newSourceName', $exception->errors()['name'][0] ?? 'Não foi possível cadastrar esta origem.');

            return;
        }

        $this->sourceId = $source->id;
        $this->sourceModalOpen = false;
        $this->reset(
            'newSourceName',
            'newSourceCollection',
            'newSourceUrl',
            'newSourceCountry',
            'newSourceDescription',
        );
        $this->newSourceType = KnowledgeSource::TYPE_REGULATORY;
    }

    /**
     * Retira um PDF da seleção e apaga o upload temporário correspondente.
     *
     * O arquivo é identificado pelo nome temporário gerado pelo Livewire, e não pela posição na lista:
     * a posição muda a cada remoção, e um clique repetido removeria o arquivo seguinte. Um identificador
     * que não pertence à seleção atual é ignorado.
     */
    public function removeFile(string $temporaryFilename): void
    {
        $position = collect($this->files)->search(
            fn (mixed $file): bool => $file instanceof TemporaryUploadedFile && $file->getFilename() === $temporaryFilename,
        );

        if ($position === false) {
            return;
        }

        $this->files[$position]->delete();
        unset($this->files[$position]);
        $this->files = array_values($this->files);
        $this->resetValidation(['files', 'files.*']);
    }

    public function clearFiles(): void
    {
        foreach ($this->files as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            }
        }

        $this->reset('files');
        $this->resetValidation(['files', 'files.*']);
    }

    public function ingest(KnowledgeBatchIngestionService $service): void
    {
        $maxBatchKilobytes = (int) config('knowledge.ingestion.max_batch_kilobytes');
        $maxFileKilobytes = (int) config('knowledge.ingestion.max_file_kilobytes');

        $validated = $this->validate([
            'sourceId' => ['required', 'integer', Rule::exists('knowledge_sources', 'id')->where('active', true)],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf,application/x-pdf', "max:{$maxFileKilobytes}"],
        ], [
            'sourceId.required' => 'Selecione a origem dos documentos.',
            'sourceId.exists' => 'Selecione uma origem ativa válida.',
            'files.*.max' => 'Um dos PDFs ultrapassa o limite permitido para a importação.',
        ]);

        $totalKilobytes = (int) ceil(collect($validated['files'])->sum(fn (TemporaryUploadedFile $file): int => $file->getSize()) / 1024);
        if ($totalKilobytes > $maxBatchKilobytes) {
            throw ValidationException::withMessages([
                'files' => 'O tamanho total dos PDFs não pode ultrapassar o limite de 100 MB por importação.',
            ]);
        }

        $source = KnowledgeSource::query()->whereKey($validated['sourceId'])->where('active', true)->firstOrFail();
        $this->feedback = null;
        $this->errorId = null;

        try {
            $batch = $service->ingest([
                'name' => 'Importação administrativa '.now()->format('d/m/Y H:i:s'),
                'source_id' => $source->id,
            ], array_map(fn (TemporaryUploadedFile $file): array => [
                'file' => $file,
                'original_filename' => $file->getClientOriginalName(),
                'metadata' => ['ingestion_channel' => 'knowledge_admin'],
            ], $this->files));

            $this->completedBatchId = $batch->id;
            $this->summary = [
                'ASSOCIATED' => $batch->associated_items,
                'PENDING' => $batch->pending_items,
                'DUPLICATE' => $batch->duplicate_items,
                'ERROR' => $batch->error_items,
            ];
            $this->feedback = 'Importação concluída. O processamento dos documentos associados continuará em segundo plano.';
            $this->reset('files');
        } catch (Throwable $exception) {
            $this->errorId = Str::uuid()->toString();
            $this->feedback = 'Não foi possível concluir a importação.';
            Log::error('knowledge_upload_failed', [
                'error_id' => $this->errorId,
                'source_id' => $this->sourceId,
                'files' => count($this->files),
                'exception' => $exception,
            ]);
        }
    }

    public function render(): View
    {
        return view('livewire.admin.knowledge.upload', [
            'sources' => KnowledgeSource::query()->active()->orderBy('name')->orderBy('collection')->get(['id', 'name', 'collection', 'organization']),
            'sourceTypeOptions' => KnowledgeSource::typeOptions(),
            'maxBatchMegabytes' => round((int) config('knowledge.ingestion.max_batch_kilobytes') / 1024),
        ])->title('Adicionar documentos · BULAI');
    }
}
