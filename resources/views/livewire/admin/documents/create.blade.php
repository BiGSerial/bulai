<x-knowledge-admin.shell title="Novo documento" subtitle="Cadastre o documento, não o arquivo. Um PDF poderá ser associado depois, pela importação." :back="route('admin.knowledge.documents.index')" back-label="Documentos">
    <form class="max-w-3xl" wire:submit="save">
        <x-knowledge-admin.section>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-knowledge-admin.select label="Origem" required wire:model="sourceId" wrapper-class="sm:col-span-2"><option value="">Selecione…</option>@foreach ($sources as $source)<option value="{{ $source->id }}">{{ $source->displayName() }}</option>@endforeach</x-knowledge-admin.select>
                <x-knowledge-admin.input label="Título" required wire:model="title" wrapper-class="sm:col-span-2" />
                <x-knowledge-admin.select label="Tipo" required wire:model="documentType">@foreach (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
                <x-knowledge-admin.input label="Identificador oficial" optional wire:model="sourceDocumentId" />
                <x-knowledge-admin.input label="Chave externa" optional wire:model="externalKey" wrapper-class="sm:col-span-2" />
            </div>
            <div><x-knowledge-admin.button type="submit" loading-target="save" loading="Criando…">Criar documento</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>
</x-knowledge-admin.shell>
