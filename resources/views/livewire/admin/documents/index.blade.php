<x-knowledge-admin.shell title="Documentos" subtitle="Documentos cadastrados na base de conhecimento.">
    <x-slot:actions>
        <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.documents.create')">Novo documento</x-knowledge-admin.button>
        <x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-[minmax(0,28rem)_14rem]">
        <x-knowledge-admin.search wire:model.live.debounce.300ms="search" placeholder="Título, chave externa ou tipo" />
        <x-knowledge-admin.select label="Arquivo PDF" wire:model.live="fileStatus">
            <option value="">Todos</option>
            @foreach ($fileStatuses as $status)<option value="{{ $status }}">{{ match ($status) { 'PENDING' => 'Aguardando arquivo', 'PROCESSING' => 'Armazenando arquivo', 'READY' => 'PDF disponível', 'FAILED' => 'Falha no arquivo', default => $status } }}</option>@endforeach
        </x-knowledge-admin.select>
    </div>

    <x-knowledge-admin.table :columns="['Documento', 'Origem', 'Tipo', 'Situação', 'Trechos']" wire:loading.class="opacity-60" wire:target="search,fileStatus">
        @forelse ($documents as $document)
            <tr class="hover:bg-slate-50">
                <td><a class="font-medium text-brand-700 hover:text-brand-800" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate>{{ $document->title }}</a><div class="mt-0.5 text-slate-500">{{ $document->external_key }}</div></td>
                <td>{{ $document->source->name }}</td>
                <td>{{ $document->documentTypeLabel() }}</td>
                <td><x-knowledge-admin.document-processing-states :document="$document" compact /></td>
                <td>{{ $document->content_status === 'READY' ? $document->chunks_count : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-slate-600">Nenhum documento encontrado.</td></tr>
        @endforelse
    </x-knowledge-admin.table>
    {{ $documents->links() }}
</x-knowledge-admin.shell>
