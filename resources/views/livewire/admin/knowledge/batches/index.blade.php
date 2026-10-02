<x-knowledge-admin.shell title="Importações" subtitle="Histórico dos envios de documentos.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button></x-slot:actions>
    <x-knowledge-admin.search wire:model.live.debounce.350ms="search" placeholder="Nome, descrição ou origem" />
    <section class="grid gap-3" wire:loading.class="opacity-60" wire:target="search">
        @forelse ($batches as $batch)
            <a class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-600 sm:p-5" href="{{ route('admin.knowledge.batches.show', $batch) }}" wire:navigate>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-slate-900">{{ $batch->name ?: 'Importação #'.$batch->id }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $batch->source?->name ?? 'Sem origem' }} · {{ $batch->created_at->format('d/m/Y H:i') }}</p>
                    </div>
                    <x-knowledge-admin.status-badge :status="$batch->status" />
                </div>
                <p class="text-sm text-slate-600">{{ $batch->total_items }} {{ $batch->total_items === 1 ? 'arquivo' : 'arquivos' }} · {{ $batch->associated_items }} associados · {{ $batch->pending_items }} pendentes · {{ $batch->duplicate_items }} duplicados · {{ $batch->error_items }} com erro</p>
            </a>
        @empty
            <x-knowledge-admin.empty-state>Nenhuma importação encontrada.</x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $batches->links() }}
</x-knowledge-admin.shell>
