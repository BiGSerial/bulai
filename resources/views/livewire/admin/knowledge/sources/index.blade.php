<x-knowledge-admin.shell title="Origens" subtitle="Instituições e coleções de onde os documentos vêm (ex.: ANVISA).">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.sources.create')">Nova origem</x-knowledge-admin.button></x-slot:actions>
    <div class="flex flex-wrap items-end gap-3">
        <x-knowledge-admin.search wrapper-class="min-w-64 flex-1" label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome, coleção ou país" />
        <x-knowledge-admin.select label="Situação" wire:model.live="status" wrapper-class="w-40"><option value="all">Todas</option><option value="active">Ativas</option><option value="inactive">Inativas</option></x-knowledge-admin.select>
        <p class="ml-auto text-sm text-slate-500">{{ number_format($sources->total(), 0, ',', '.') }} {{ $sources->total() === 1 ? 'origem' : 'origens' }}</p>
    </div>
    <section class="grid gap-2" wire:loading.class="opacity-60" wire:target="search,status">
        @forelse ($sources as $source)
            <article wire:key="source-{{ $source->id }}" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <a class="min-w-0 flex-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.sources.show', $source) }}" wire:navigate>
                    <span class="block truncate text-base font-semibold text-slate-900">{{ $source->displayName() }}</span>
                    <span class="block truncate text-sm text-slate-500">{{ \App\Models\Knowledge\KnowledgeSource::typeOptions()[$source->type] ?? 'Legada' }}@if ($source->country) · {{ $source->country }}@endif · {{ $source->documents_count }} {{ $source->documents_count === 1 ? 'documento' : 'documentos' }} · {{ $source->batches_count }} {{ $source->batches_count === 1 ? 'importação' : 'importações' }}</span>
                </a>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <x-knowledge-admin.badge :tone="$source->active ? 'success' : 'neutral'">{{ $source->active ? 'Ativa' : 'Inativa' }}</x-knowledge-admin.badge>
                    <x-knowledge-admin.button variant="quiet" wire:click="toggleActive({{ $source->id }})" loading-target="toggleActive({{ $source->id }})">{{ $source->active ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.sources.show', $source)">Abrir</x-knowledge-admin.button>
                </div>
            </article>
        @empty
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhuma origem encontrada.</p>
                <x-knowledge-admin.button class="mt-4" :href="route('admin.knowledge.sources.create')">Nova origem</x-knowledge-admin.button>
            </x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $sources->links() }}
</x-knowledge-admin.shell>
