<x-knowledge-admin.shell title="Origens dos documentos">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid flex-1 gap-3 sm:grid-cols-[minmax(0,24rem)_12rem]">
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Pesquisar<input class="rounded-lg border-slate-300 bg-white" type="search" placeholder="Nome, coleção ou país" wire:model.live.debounce.300ms="search"></label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Situação<select class="rounded-lg border-slate-300 bg-white" wire:model.live="status"><option value="all">Todas</option><option value="active">Ativas</option><option value="inactive">Inativas</option></select></label>
        </div>
        <a class="rounded-lg bg-teal-700 px-4 py-2.5 text-center text-sm font-semibold text-white" href="{{ route('admin.knowledge.sources.create') }}" wire:navigate>Nova origem</a>
    </div>
    <section class="grid gap-3">
        @forelse ($sources as $source)
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950">{{ $source->displayName() }}</h2><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $source->active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $source->active ? 'Ativa' : 'Inativa' }}</span></div><p class="mt-1 text-sm text-slate-500">{{ \App\Models\Knowledge\KnowledgeSource::typeOptions()[$source->type] ?? 'Legada' }}@if($source->country) · {{ $source->country }}@endif</p><p class="mt-2 text-sm text-slate-600">{{ $source->documents_count }} documento(s) · {{ $source->batches_count }} importação(ões)</p></div><div class="flex flex-wrap gap-2"><a class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-800" href="{{ route('admin.knowledge.sources.show', $source) }}" wire:navigate>Visualizar</a><a class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-800" href="{{ route('admin.knowledge.sources.edit', $source) }}" wire:navigate>Editar</a><button class="rounded-md px-3 py-2 text-sm font-semibold {{ $source->active ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-800' }}" type="button" wire:click="toggleActive({{ $source->id }})" wire:loading.attr="disabled">{{ $source->active ? 'Desativar' : 'Ativar' }}</button></div></div></article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">Nenhuma origem encontrada.</div>
        @endforelse
    </section>
    {{ $sources->links() }}
</x-knowledge-admin.shell>
