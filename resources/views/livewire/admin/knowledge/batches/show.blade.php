<x-knowledge-admin.shell :title="$batch->name ?: 'Importação #'.$batch->id">
    <div><a class="text-sm font-semibold text-teal-700" href="{{ route('admin.knowledge.batches.index') }}" wire:navigate>← Voltar às importações</a></div>
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-sm text-slate-500">{{ $batch->source?->name ?? 'Sem fonte' }} · {{ $batch->created_at->format('d/m/Y H:i') }}</p><x-knowledge-admin.status-badge :status="$batch->status" /></div>
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">@foreach ([['Total', $batch->total_items], ['Associados', $batch->associated_items], ['Pendentes', $batch->pending_items], ['Duplicados', $batch->duplicate_items], ['Erros', $batch->error_items]] as [$label, $value])<div><dt class="text-xs uppercase text-slate-500">{{ $label }}</dt><dd class="text-xl font-semibold text-slate-950">{{ $value }}</dd></div>@endforeach</dl>
    </section>
    <section class="grid gap-3">
        @forelse ($items as $item)
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><p class="truncate font-semibold text-slate-950">{{ $item->original_filename }}</p><p class="mt-1 text-sm text-slate-500">Item #{{ $item->id }} @if($item->external_key) · {{ $item->external_key }} @endif</p></div><x-knowledge-admin.status-badge :status="$item->status" /></div><div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600"><span>{{ $item->knowledgeDocument?->title ?? $item->reason ?? 'Aguardando resolução' }}</span>@if ($item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)<a class="rounded-md bg-teal-700 px-3 py-2 font-semibold text-white" href="{{ route('admin.knowledge.pending.review', [$batch, $item]) }}" wire:navigate>Revisar</a>@endif</div></article>
        @empty<div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-sm text-slate-600">Este lote não possui itens.</div>@endforelse
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
