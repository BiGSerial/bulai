<x-knowledge-admin.shell :title="$sourceId === null ? 'Nova origem' : 'Editar origem'" :back="route('admin.knowledge.sources.index')" back-label="Origens">
    <form class="max-w-3xl" wire:submit="save">
        <x-knowledge-admin.section>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-knowledge-admin.input label="Nome" required wire:model="name" placeholder="Ex.: ANVISA" />
                <x-knowledge-admin.input label="Base / coleção" required wire:model="collection" placeholder="Ex.: Bulário Eletrônico" />
                <x-knowledge-admin.select label="Tipo" required wire:model="type">@foreach ($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
                <x-knowledge-admin.input label="País" optional wire:model="country" placeholder="Ex.: Brasil" />
            </div>
            <x-knowledge-admin.input type="url" label="URL oficial" optional wire:model="url" placeholder="https://" />
            <x-knowledge-admin.textarea label="Descrição" optional wire:model="description" placeholder="Descreva brevemente a procedência ou o conteúdo da coleção." />
            <div class="flex flex-wrap gap-3">
                <x-knowledge-admin.button type="submit" loading-target="save" loading="Salvando…">Salvar origem</x-knowledge-admin.button>
                <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.sources.index')">Cancelar</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.section>
    </form>
</x-knowledge-admin.shell>
