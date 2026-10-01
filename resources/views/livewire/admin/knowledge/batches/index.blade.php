<x-knowledge-admin.shell title="Importações">
    <label class="grid max-w-xl gap-1 text-sm font-semibold text-slate-700">Buscar lote<input class="rounded-md border border-slate-300 bg-white px-3 py-2 text-base font-normal" type="search" wire:model.live.debounce.350ms="search" placeholder="Nome, descrição ou fonte"></label>
    <section class="grid gap-3">
        @forelse ($batches as $batch)
            <a class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-teal-300" href="{{ route('admin.knowledge.batches.show', $batch) }}" wire:navigate>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="font-semibold text-slate-950">{{ $batch->name ?: 'Lote #'.$batch->id }}</h2><p class="mt-1 text-sm text-slate-500">{{ $batch->source?->name ?? 'Sem fonte' }} · {{ $batch->created_at->format('d/m/Y H:i') }}</p></div><x-knowledge-admin.status-badge :status="$batch->status" /></div>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">@foreach ([['Total', $batch->total_items], ['Associados', $batch->associated_items], ['Pendentes', $batch->pending_items], ['Duplicados', $batch->duplicate_items], ['Erros', $batch->error_items]] as [$label, $value])<div><dt class="text-slate-500">{{ $label }}</dt><dd class="font-semibold">{{ $value }}</dd></div>@endforeach</dl>
            </a>
        @empty<div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-sm text-slate-600">Nenhuma importação encontrada.</div>@endforelse
    </section>
    {{ $batches->links() }}
</x-knowledge-admin.shell>
