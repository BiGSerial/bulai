<x-knowledge-admin.shell title="Pendências">
    <label class="grid max-w-xl gap-1 text-sm font-semibold text-slate-700">Buscar pendência<input class="rounded-md border border-slate-300 bg-white px-3 py-2 text-base font-normal" type="search" wire:model.live.debounce.350ms="search" placeholder="Arquivo ou identificador"></label>
    <section class="grid gap-3">
        @forelse ($items as $item)
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><h2 class="truncate font-semibold text-slate-950">{{ $item->original_filename }}</h2><p class="mt-1 text-sm text-slate-500">{{ $item->ingestionBatch->name ?: 'Lote #'.$item->ingestion_batch_id }} · {{ $item->ingestionBatch->source?->name ?? 'Sem fonte' }} · {{ $item->created_at->format('d/m/Y H:i') }}</p><p class="mt-2 text-sm text-slate-600">{{ $item->reason ?: 'Associação manual necessária' }}</p></div><div class="flex items-center gap-2"><x-knowledge-admin.status-badge :status="$item->status" /><a class="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white" href="{{ route('admin.knowledge.pending.review', [$item->ingestionBatch, $item]) }}" wire:navigate>Revisar</a></div></div></article>
        @empty<div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-sm text-slate-600">Nenhum item pendente encontrado.</div>@endforelse
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
