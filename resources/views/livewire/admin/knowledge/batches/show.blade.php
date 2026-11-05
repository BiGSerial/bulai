<x-knowledge-admin.shell :title="$batch->name ?: 'Importação #'.$batch->id" :back="route('admin.knowledge.batches.index')" back-label="Importações">
    <x-knowledge-admin.page-header :badge="match ($batch->status) { 'COMPLETED' => ($batch->pending_items > 0 ? $batch->pending_items.' '.($batch->pending_items === 1 ? 'pendente' : 'pendentes') : 'Concluída'), 'PROCESSING' => 'Processando', default => 'Na fila' }" :badge-tone="match (true) { $batch->status !== 'COMPLETED' => 'info', $batch->pending_items > 0 => 'warning', default => 'success' }" :meta="[$batch->source?->name ?? 'Sem origem', $batch->created_at->format('d/m/Y H:i')]">
        <x-slot:primary>
            @if ($batch->pending_items > 0)
                @if ($firstPending)<x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$batch, $firstPending])">Revisar pendências</x-knowledge-admin.button>@endif
            @else
                <x-knowledge-admin.button :href="route('admin.knowledge.documents.index')">Ver documentos</x-knowledge-admin.button>
            @endif
        </x-slot:primary>
        <x-slot:more>
            @if ($batch->pending_items > 0)<x-knowledge-admin.menu-item wire:click="reanalyzePending">Reanalisar pendentes</x-knowledge-admin.menu-item>@endif
            <x-knowledge-admin.menu-item :href="route('admin.knowledge.upload')">Adicionar mais documentos</x-knowledge-admin.menu-item>
        </x-slot:more>
    </x-knowledge-admin.page-header>

    @if (session('batch-feedback'))<x-knowledge-admin.alert tone="success">{{ session('batch-feedback') }}</x-knowledge-admin.alert>@endif

    {{-- Único trecho com atualização automática. --}}
    <livewire:admin.knowledge.batches.progress :batch="$batch" :key="'progress-'.$batch->id" />

    <section class="grid gap-2" aria-label="Arquivos desta importação">
        @forelse ($items as $item)
            @php
                $found = (array) data_get($item->metadata, 'preliminary_identification.fields', []);
                $title = $item->knowledgeDocument?->title ?? (filled($found['product_name'] ?? null) ? $found['product_name'] : $item->original_filename);
                $support = match ($item->status) {
                    'PENDING' => match ($item->reason) { 'no_match_found' => 'Sem documento correspondente', 'document_already_has_different_file' => 'O documento identificado já tem outro PDF', 'ambiguous_source_document_id', 'ambiguous_normalized_filename' => 'Mais de um destino possível', default => 'Aguardando sua decisão' },
                    'IGNORED' => 'Resolvido sem incorporação',
                    'DUPLICATE' => 'Já existia na base',
                    'ERROR' => 'Falha ao processar o arquivo',
                    default => 'Incorporado à base',
                };
            @endphp
            <article wire:key="item-{{ $item->id }}" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-base font-semibold text-slate-900" title="{{ $title }}">{{ $title }}</h2>
                    <p class="truncate text-sm text-slate-500">{{ $title !== $item->original_filename ? $item->original_filename.' · ' : '' }}{{ $support }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <x-knowledge-admin.status-badge :status="$item->status" />
                    @if ($item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)
                        <x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$batch, $item])">Revisar</x-knowledge-admin.button>
                    @elseif ($item->knowledge_document_id)
                        <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.documents.show', $item->knowledge_document_id)">Abrir</x-knowledge-admin.button>
                    @else
                        <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.pending.review', [$batch, $item])">Detalhes</x-knowledge-admin.button>
                    @endif
                </div>
            </article>
        @empty
            <x-knowledge-admin.empty-state>Esta importação não possui arquivos.</x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
