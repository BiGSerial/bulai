<x-knowledge-admin.shell title="Importações" subtitle="Cada envio de PDFs e o que aconteceu com os arquivos.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button></x-slot:actions>

    <div class="flex flex-wrap items-end gap-3">
        <x-knowledge-admin.search wrapper-class="min-w-64 flex-1" wire:model.live.debounce.350ms="search" placeholder="Nome, descrição ou origem" />
        <p class="ml-auto text-sm text-slate-500">{{ number_format($batches->total(), 0, ',', '.') }} {{ $batches->total() === 1 ? 'importação' : 'importações' }}</p>
    </div>

    <section class="grid gap-2" wire:loading.class="opacity-60" wire:target="search">
        @forelse ($batches as $batch)
            @php
                $resolved = $batch->associated_items + $batch->duplicate_items + $batch->ignored_items;
                $summary = $batch->total_items.' '.($batch->total_items === 1 ? 'arquivo' : 'arquivos').($batch->pending_items > 0 ? ' · '.$batch->pending_items.' '.($batch->pending_items === 1 ? 'pendente' : 'pendentes') : '').($batch->error_items > 0 ? ' · '.$batch->error_items.' com erro' : '').($batch->pending_items === 0 && $batch->error_items === 0 && $batch->total_items > 0 ? ' · todos resolvidos' : '');
            @endphp
            <a wire:key="batch-{{ $batch->id }}" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm transition hover:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.batches.show', $batch) }}" wire:navigate>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-base font-semibold text-slate-900">{{ $batch->name ?: 'Importação #'.$batch->id }}</span>
                    <span class="block truncate text-sm text-slate-500">{{ $batch->source?->name ?? 'Sem origem' }} · {{ $batch->created_at->format('d/m/Y H:i') }} · {{ $summary }}</span>
                </span>
                @if ($batch->pending_items > 0 && $batch->status === 'COMPLETED')
                    <x-knowledge-admin.badge tone="warning">{{ $batch->pending_items }} {{ $batch->pending_items === 1 ? 'pendente' : 'pendentes' }}</x-knowledge-admin.badge>
                @else
                    <x-knowledge-admin.status-badge :status="$batch->status" />
                @endif
                <span class="shrink-0 text-sm font-semibold text-brand-700">Abrir</span>
            </a>
        @empty
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhuma importação ainda.</p>
                <x-knowledge-admin.button class="mt-4" :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
            </x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $batches->links() }}
</x-knowledge-admin.shell>
