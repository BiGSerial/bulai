<x-knowledge-admin.shell title="Cadastrar documento sem PDF" subtitle="Registra só os dados; o PDF pode ser associado depois, por uma importação." :back="route('admin.knowledge.documents.index')" back-label="Documentos">
    <form class="max-w-2xl" wire:submit="save">
        <x-knowledge-admin.section title="Dados do documento">
            <x-knowledge-admin.select label="Origem" required wire:model="sourceId"><option value="">Selecione…</option>@foreach ($sources as $source)<option value="{{ $source->id }}">{{ $source->displayName() }}</option>@endforeach</x-knowledge-admin.select>
            <x-knowledge-admin.input label="Título" required wire:model="title" placeholder="Ex.: Omeprazol — Bula do paciente" />
            <x-knowledge-admin.select label="Tipo" required wire:model="documentType">@foreach (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
            <x-knowledge-admin.input label="Identificador oficial" optional wire:model="sourceDocumentId" hint="Número de registro ou código na origem; ajuda a associar o PDF depois." />
            <x-knowledge-admin.input label="Chave externa" optional wire:model="externalKey" hint="Identificador seu, se já existir em outro sistema." />
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                <x-knowledge-admin.button type="submit" loading-target="save" loading="Criando…">Criar documento</x-knowledge-admin.button>
                <x-knowledge-admin.button variant="quiet" :href="route('admin.knowledge.documents.index')">Cancelar</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.section>
    </form>
</x-knowledge-admin.shell>
