<x-knowledge-admin.shell title="Pendências" subtitle="Arquivos que precisam da sua decisão antes de entrar na base.">
    <x-slot:actions>
        <x-knowledge-admin.button variant="secondary" wire:click="reanalyzeAll" loading-target="reanalyzeAll" loading="Enfileirando…">Reanalisar todas</x-knowledge-admin.button>
    </x-slot:actions>
    @if ($feedback)<x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>@endif

    <div class="flex flex-wrap items-end gap-3">
        <x-knowledge-admin.search wrapper-class="min-w-64 flex-1" wire:model.live.debounce.350ms="search" placeholder="Nome do arquivo ou identificador" />
        <p class="ml-auto text-sm text-slate-500">{{ number_format($items->total(), 0, ',', '.') }} {{ $items->total() === 1 ? 'pendência' : 'pendências' }}</p>
    </div>

    {{-- Só esta parte muda sozinha: andamento das reanálises e a lista. --}}
    <section class="grid gap-3" wire:poll.5s x-data x-on:bulai-realtime.window="$wire.$refresh()" wire:loading.class="opacity-60" wire:target="search" aria-live="polite">
        @if ($activity['total'] > 0 && ($activity['active'] > 0 || $activity['stale'] > 0))
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border px-4 py-2 text-sm {{ $activity['stale'] > 0 ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-brand-100 bg-brand-50 text-brand-800' }}" role="status">
                <span class="size-2 shrink-0 rounded-full {{ $activity['stale'] > 0 ? 'bg-amber-600' : 'animate-pulse bg-brand-600' }}" aria-hidden="true"></span>
                @if ($activity['stale'] > 0)
                    <span class="font-medium">Processamento sem atualização:</span><span>{{ $activity['stale'] }} {{ $activity['stale'] === 1 ? 'item está' : 'itens estão' }} sem avanço há mais de {{ config('knowledge.processing.progress_stale_minutes', 5) }} minutos ({{ $activity['queued'] }} {{ $activity['queued'] === 1 ? 'item aguardando' : 'itens aguardando' }}). Verifique se o worker da fila está ativo em <a class="font-medium underline" href="{{ route('admin.knowledge.queue') }}" wire:navigate>Fila e serviços</a>.</span>
                @else
                    <span class="font-medium">Processamento em andamento:</span><span>Executando {{ $activity['processing'] }} ({{ $activity['processing'] === 1 ? '1 item sendo executado' : $activity['processing'].' itens sendo executados' }}) · {{ $activity['queued'] }} {{ $activity['queued'] === 1 ? 'item aguardando' : 'itens aguardando' }} na fila. Esta lista atualiza sozinha.</span>
                @endif
            </p>
        @endif

        @forelse ($items as $item)
            @php
                $progress = data_get($item->metadata, 'reanalysis_progress');
                $progressState = is_array($progress) ? ($progress['state'] ?? null) : null;
                $isActive = in_array($progressState, ['queued', 'processing'], true);
                $found = (array) data_get($item->metadata, 'preliminary_identification.fields', []);
                $title = filled($found['product_name'] ?? null) ? $found['product_name'] : $item->original_filename;
                $ingredient = filled($found['active_ingredient'] ?? null) ? $found['active_ingredient'] : null;
                [$badgeTone, $badgeLabel] = match (true) {
                    $isActive => ['info', $progressState === 'queued' ? 'Na fila' : 'Processando'],
                    $progressState === 'failed' => ['danger', 'Reanálise falhou'],
                    $ingredient === null && data_get($item->metadata, 'preliminary_identification') !== null => ['danger', 'Sem princípio ativo'],
                    $item->reason === 'document_already_has_different_file' => ['warning', 'Documento já tem PDF'],
                    in_array($item->reason, ['ambiguous_source_document_id', 'ambiguous_normalized_filename'], true) => ['warning', 'Mais de um destino possível'],
                    default => ['warning', 'Sem documento correspondente'],
                };
            @endphp
            <article wire:key="pending-{{ $item->id }}" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-base font-semibold text-slate-900" title="{{ $title }}">{{ $title }}@if ($ingredient)<span class="font-normal text-slate-500"> · {{ $ingredient }}</span>@endif</h2>
                    <p class="mt-0.5 truncate text-sm text-slate-500">{{ $title !== $item->original_filename ? $item->original_filename.' · ' : '' }}{{ $item->ingestionBatch->name ?: 'Importação #'.$item->ingestion_batch_id }} · {{ $item->ingestionBatch->source?->name ?? 'Sem origem' }} · {{ $item->created_at->format('d/m/Y H:i') }}</p>
                    @if ($isActive)<p class="mt-1 flex items-center gap-2 text-sm font-medium text-brand-700"><span class="size-2 animate-pulse rounded-full bg-current" aria-hidden="true"></span>{{ $progress['label'] ?? 'Reanalisando' }} · {{ max(0, min(100, (int) ($progress['percent'] ?? 0))) }}%</p>@elseif ($progressState === 'failed')<p class="mt-1 text-sm font-medium text-rose-700">{{ $progress['label'] ?? 'A reanálise falhou' }}</p>@endif
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <x-knowledge-admin.badge :tone="$badgeTone">{{ $badgeLabel }}</x-knowledge-admin.badge>
                    @unless ($isActive)
                        <x-knowledge-admin.button variant="quiet" wire:click="reanalyzeItem({{ $item->id }})" loading-target="reanalyzeItem({{ $item->id }})" loading="Enfileirando…">Reanalisar</x-knowledge-admin.button>
                        <x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$item->ingestionBatch, $item])">Revisar</x-knowledge-admin.button>
                    @endunless
                </div>
            </article>
        @empty
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhuma pendência.</p>
                <p class="mt-1">Todos os arquivos enviados já foram incorporados ou resolvidos.</p>
                <x-knowledge-admin.button class="mt-4" variant="secondary" :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
            </x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
