<x-knowledge-admin.shell title="Origens" subtitle="Instituições e bases de onde os documentos são obtidos.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.sources.create')">Nova origem</x-knowledge-admin.button></x-slot:actions>
    <div class="grid gap-3 sm:grid-cols-[minmax(0,28rem)_12rem]">
        <x-knowledge-admin.search label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome, coleção ou país" />
        <x-knowledge-admin.select label="Situação" wire:model.live="status"><option value="all">Todas</option><option value="active">Ativas</option><option value="inactive">Inativas</option></x-knowledge-admin.select>
    </div>
    <section class="grid gap-3" wire:loading.class="opacity-60" wire:target="search,status">
        @forelse ($sources as $source)
            <x-knowledge-admin.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2"><h2 class="text-base font-semibold text-slate-900">{{ $source->displayName() }}</h2><x-knowledge-admin.badge :tone="$source->active ? 'success' : 'neutral'">{{ $source->active ? 'Ativa' : 'Inativa' }}</x-knowledge-admin.badge></div>
                    <p class="mt-1 text-sm text-slate-500">{{ \App\Models\Knowledge\KnowledgeSource::typeOptions()[$source->type] ?? 'Legada' }}@if ($source->country) · {{ $source->country }}@endif · {{ $source->documents_count }} {{ $source->documents_count === 1 ? 'documento' : 'documentos' }} · {{ $source->batches_count }} {{ $source->batches_count === 1 ? 'importação' : 'importações' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.sources.show', $source)">Ver</x-knowledge-admin.button>
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.sources.edit', $source)">Editar</x-knowledge-admin.button>
                    <x-knowledge-admin.button variant="quiet" wire:click="toggleActive({{ $source->id }})" loading-target="toggleActive({{ $source->id }})">{{ $source->active ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
                </div>
            </x-knowledge-admin.card>
        @empty
            <x-knowledge-admin.empty-state>Nenhuma origem encontrada.</x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $sources->links() }}
</x-knowledge-admin.shell>
