<?php

namespace App\Livewire\Admin\Knowledge\Sources;

use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeSourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    #[Locked]
    public ?int $sourceId = null;

    public string $name = '';

    public string $collection = '';

    public string $type = KnowledgeSource::TYPE_REGULATORY;

    public string $url = '';

    public string $country = '';

    public string $description = '';

    public function mount(?KnowledgeSource $source = null): void
    {
        if ($source === null || ! $source->exists) {
            return;
        }

        $this->sourceId = $source->id;
        $this->name = $source->name;
        $this->collection = $source->collection ?? $source->organization ?? '';
        $this->type = array_key_exists($source->type, KnowledgeSource::typeOptions()) ? $source->type : KnowledgeSource::TYPE_OTHER;
        $this->url = $source->url ?? '';
        $this->country = $source->country ?? '';
        $this->description = $source->description ?? '';
    }

    public function save(KnowledgeSourceService $service): mixed
    {
        $validated = $this->validate($this->rules(), messages: $this->messages(), attributes: $this->validationAttributes());
        $source = $this->sourceId === null
            ? $service->create($validated)
            : $service->update(KnowledgeSource::query()->findOrFail($this->sourceId), $validated);

        session()->flash('sourceFeedback', $this->sourceId === null ? 'Origem cadastrada com sucesso.' : 'Origem atualizada com sucesso.');

        return $this->redirectRoute('admin.knowledge.sources.show', $source, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.knowledge.sources.form', [
            'typeOptions' => KnowledgeSource::typeOptions(),
        ])->title(($this->sourceId === null ? 'Nova origem' : 'Editar origem').' - BULAI');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'collection' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(KnowledgeSource::typeOptions()))],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'country' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Preencha :attribute.',
            'url' => 'Informe uma URL válida iniciada por http:// ou https://.',
        ];
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        return [
            'name' => 'o nome',
            'collection' => 'a base/coleção',
            'type' => 'o tipo',
            'url' => 'a URL da fonte',
            'country' => 'o país',
            'description' => 'a descrição',
        ];
    }
}
