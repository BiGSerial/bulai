<x-knowledge-admin.shell :title="$sourceId === null ? 'Nova origem' : 'Editar origem'" subtitle="De onde os documentos vêm. A origem aparece nas evidências mostradas ao paciente." :back="route('admin.knowledge.sources.index')" back-label="Origens">
    <form class="max-w-2xl" wire:submit="save">
        <x-knowledge-admin.section title="Identificação">
            <x-knowledge-admin.input label="Nome" required wire:model="name" placeholder="Ex.: ANVISA" hint="Instituição ou organização responsável." />
            <x-knowledge-admin.input label="Base / coleção" required wire:model="collection" placeholder="Ex.: Bulário Eletrônico" hint="Conjunto específico dentro da instituição." />
            <x-knowledge-admin.select label="Tipo" required wire:model="type">@foreach ($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
            <x-knowledge-admin.input label="País" optional wire:model="country" placeholder="Ex.: Brasil" />
            <x-knowledge-admin.input type="url" label="Endereço oficial" optional wire:model="url" placeholder="https://" />
            <x-knowledge-admin.textarea label="Descrição" optional wire:model="description" placeholder="Procedência ou conteúdo da coleção, em uma ou duas frases." />
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                <x-knowledge-admin.button type="submit" loading-target="save" loading="Salvando…">{{ $sourceId === null ? 'Criar origem' : 'Salvar alterações' }}</x-knowledge-admin.button>
                <x-knowledge-admin.button variant="quiet" :href="route('admin.knowledge.sources.index')">Cancelar</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.section>
    </form>
</x-knowledge-admin.shell>
