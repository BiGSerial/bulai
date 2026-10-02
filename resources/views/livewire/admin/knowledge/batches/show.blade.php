<x-knowledge-admin.shell :title="$batch->name ?: 'Importação #'.$batch->id" :subtitle="($batch->source?->name ?? 'Sem origem').' · '.$batch->created_at->format('d/m/Y H:i')" :back="route('admin.knowledge.batches.index')" back-label="Importações">
    <x-slot:actions>
        <x-knowledge-admin.status-badge :status="$batch->status" />
        @if ($batch->pending_items > 0)<x-knowledge-admin.button variant="secondary" wire:click="reanalyzePending" loading-target="reanalyzePending" loading="Enviando…">Reanalisar pendentes</x-knowledge-admin.button>@endif
    </x-slot:actions>

    @if (session('batch-feedback'))<x-knowledge-admin.alert tone="success">{{ session('batch-feedback') }}</x-knowledge-admin.alert>@endif
    @if ($batch->status === 'COMPLETED' && $batch->pending_items > 0)
        <x-knowledge-admin.alert tone="warning">Processamento concluído com {{ $batch->pending_items }} {{ $batch->pending_items === 1 ? 'pendência' : 'pendências' }}.</x-knowledge-admin.alert>
    @endif

    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([['Total', $batch->total_items], ['Associados', $batch->associated_items], ['Pendentes', $batch->pending_items], ['Duplicados', $batch->duplicate_items], ['Com erro', $batch->error_items], ['Não incorporados', $batch->ignored_items]] as [$label, $value])
            <x-knowledge-admin.card><p class="text-sm text-slate-600">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-slate-900">{{ $value }}</p></x-knowledge-admin.card>
        @endforeach
    </section>

    <section class="grid gap-3">
        @forelse ($items as $item)
            <x-knowledge-admin.card class="grid gap-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-slate-900">{{ $item->original_filename }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $item->knowledgeDocument?->title ?? match($item->reason) { 'no_match_found' => 'Nenhum documento correspondente encontrado.', 'not_incorporated' => 'Resolvido sem incorporação à base.', default => $item->reason ?? 'Aguardando resolução' } }}</p>
                    </div>
                    <x-knowledge-admin.status-badge :status="$item->status" />
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)<x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$batch, $item])">Revisar</x-knowledge-admin.button>@endif
                    <x-knowledge-admin.button variant="secondary" target="_blank" :href="route('admin.knowledge.items.file', [$batch, $item])">Visualizar</x-knowledge-admin.button>
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.items.download', [$batch, $item])" download>Baixar</x-knowledge-admin.button>
                </div>
            </x-knowledge-admin.card>
        @empty
            <x-knowledge-admin.empty-state>Esta importação não possui arquivos.</x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
