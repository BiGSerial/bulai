<x-knowledge-admin.shell title="Documentos" subtitle="Bulas e materiais já incorporados à base de conhecimento.">
    <x-slot:actions>
        <x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
    </x-slot:actions>

    <div class="flex flex-wrap items-end gap-3">
        <x-knowledge-admin.search wrapper-class="min-w-64 flex-1" wire:model.live.debounce.300ms="search" placeholder="Título, chave externa ou tipo" />
        <x-knowledge-admin.select label="Arquivo PDF" wire:model.live="fileStatus" wrapper-class="w-48">
            <option value="">Todos</option>
            @foreach ($fileStatuses as $status)<option value="{{ $status }}">{{ match ($status) { 'PENDING' => 'Sem PDF ainda', 'PROCESSING' => 'Armazenando', 'READY' => 'Com PDF', 'FAILED' => 'Falha no arquivo', default => $status } }}</option>@endforeach
        </x-knowledge-admin.select>
        @if ($search !== '' || $fileStatus !== '')<x-knowledge-admin.button variant="quiet" wire:click="clearFilters">Limpar</x-knowledge-admin.button>@endif
        <p class="ml-auto text-sm text-slate-500">{{ number_format($documents->total(), 0, ',', '.') }} {{ $documents->total() === 1 ? 'documento' : 'documentos' }} · <a class="font-medium text-brand-700 hover:underline" href="{{ route('admin.knowledge.documents.create') }}" wire:navigate>cadastrar sem PDF</a></p>
    </div>

    <div class="grid gap-2" wire:loading.class="opacity-60" wire:target="search,fileStatus,clearFilters">
        @forelse ($documents as $document)
            @php
                [$tone, $label] = match (true) {
                    $document->file_status === 'FAILED' || $document->content_status === 'FAILED' || $document->embedding_status === 'FAILED' => ['danger', 'Com falha'],
                    $document->content_status === 'PROCESSING' || $document->file_status === 'PROCESSING' || $document->embedding_status === 'PROCESSING' => ['info', 'Processando'],
                    $document->file_status !== 'READY' => ['neutral', 'Sem PDF'],
                    $document->content_status !== 'READY' => ['warning', 'PDF sem trechos'],
                    $document->embedding_status !== 'READY' => ['warning', 'Sem índice de busca'],
                    default => ['success', 'Pronto'],
                };
                $stateText = fn (?string $status, array $texts): string => $texts[$status] ?? 'Aguardando';
                $detail = 'Arquivo PDF: '.$stateText($document->file_status, ['READY' => 'Disponível', 'PROCESSING' => 'Armazenando', 'FAILED' => 'Falhou']).' · Texto e trechos: '.$stateText($document->content_status, ['READY' => 'Processado', 'PROCESSING' => 'Processando', 'FAILED' => 'Falhou']).' · Índice de busca: '.$stateText($document->embedding_status, ['READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou']);
            @endphp
            <a wire:key="doc-{{ $document->id }}" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm transition hover:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-base font-semibold text-slate-900">{{ $document->title }}</span>
                    <span class="block truncate text-sm text-slate-500">{{ $document->documentTypeLabel() }} · {{ $document->source->name }} · {{ $document->content_status === 'READY' ? $document->chunks_count.' '.($document->chunks_count === 1 ? 'trecho' : 'trechos') : 'sem trechos' }}</span>
                </span>
                <x-knowledge-admin.badge :tone="$tone" :title="$detail">{{ $label }}</x-knowledge-admin.badge>
                <span class="shrink-0 text-sm font-semibold text-brand-700">Abrir</span>
            </a>
        @empty
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhum documento encontrado.</p>
                @if ($search !== '' || $fileStatus !== '')<x-knowledge-admin.button class="mt-4" variant="secondary" wire:click="clearFilters">Limpar filtros</x-knowledge-admin.button>@else<x-knowledge-admin.button class="mt-4" :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>@endif
            </x-knowledge-admin.empty-state>
        @endforelse
    </div>
    {{ $documents->links() }}
</x-knowledge-admin.shell>
