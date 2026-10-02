<x-knowledge-admin.shell title="Pendências" subtitle="Arquivos que ainda precisam ser associados a um documento.">
    <x-slot:actions>
        <x-knowledge-admin.button wire:click="reanalyzeAll" loading-target="reanalyzeAll" loading="Enfileirando…">Reanalisar fila</x-knowledge-admin.button>
    </x-slot:actions>
    @if($feedback)
        <x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>
    @endif
    @if($activity['total'] > 0)
        <section class="rounded-xl border {{ $activity['stale'] > 0 ? 'border-amber-300 bg-amber-50' : ($activity['active'] > 0 ? 'border-brand-100 bg-brand-50' : 'border-emerald-200 bg-emerald-50') }} p-4" aria-live="polite">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="mt-1 size-2.5 shrink-0 rounded-full {{ $activity['stale'] > 0 ? 'bg-amber-600' : ($activity['active'] > 0 ? 'animate-pulse bg-brand-600' : 'bg-emerald-600') }}"></span>
                    <div>
                        <h2 class="font-semibold text-slate-900">{{ $activity['stale'] > 0 ? 'Processamento sem atualização' : ($activity['active'] > 0 ? 'Processamento em andamento' : 'Última reanálise finalizada') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            @if($activity['active'] > 0)
                                {{ $activity['processing'] }} {{ $activity['processing'] === 1 ? 'item sendo executado' : 'itens sendo executados' }} agora · {{ $activity['queued'] }} {{ $activity['queued'] === 1 ? 'item aguardando' : 'itens aguardando' }} na fila.
                            @else
                                Não há itens em execução. {{ $activity['completed'] }} {{ $activity['completed'] === 1 ? 'item concluído ainda requer' : 'itens concluídos ainda requerem' }} revisão.
                            @endif
                        </p>
                        @if($activity['stale'] > 0)
                            <p class="mt-1 text-sm font-medium text-amber-900">{{ $activity['stale'] }} {{ $activity['stale'] === 1 ? 'item está' : 'itens estão' }} sem avanço há mais de {{ config('knowledge.processing.progress_stale_minutes', 5) }} minutos. Verifique se o worker da fila está ativo.</p>
                        @endif
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-x-5 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Na fila</dt><dd class="text-xl font-semibold text-slate-900">{{ $activity['queued'] }}</dd></div>
                    <div><dt class="text-slate-500">Executando</dt><dd class="text-xl font-semibold text-slate-900">{{ $activity['processing'] }}</dd></div>
                    <div><dt class="text-slate-500">Revisão necessária</dt><dd class="text-xl font-semibold text-slate-900">{{ $activity['completed'] }}</dd></div>
                    <div><dt class="text-slate-500">Falhas</dt><dd class="text-xl font-semibold text-slate-900">{{ $activity['failed'] }}</dd></div>
                </dl>
            </div>
            <p class="mt-3 text-xs text-slate-500">
                Atualização automática a cada 2 segundos
                @if($activity['last_update'])
                    · Última atividade {{ \Illuminate\Support\Carbon::parse($activity['last_update'])->diffForHumans() }}
                @endif
            </p>
        </section>
    @endif
    <x-knowledge-admin.search wire:model.live.debounce.350ms="search" placeholder="Nome do arquivo ou identificador" />
    <section class="grid gap-3" wire:poll.2s wire:loading.class="opacity-60" wire:target="search">
        @forelse ($items as $item)
            @php
                $progress = data_get($item->metadata, 'reanalysis_progress');
                $progressState = is_array($progress) ? ($progress['state'] ?? null) : null;
                $progressPercent = is_array($progress) ? max(0, min(100, (int) ($progress['percent'] ?? 0))) : 0;
                $progressWidthClass = match(true) {
                    $progressPercent >= 100 => 'w-full', $progressPercent >= 90 => 'w-11/12', $progressPercent >= 80 => 'w-4/5',
                    $progressPercent >= 65 => 'w-2/3', $progressPercent >= 35 => 'w-1/3', $progressPercent >= 15 => 'w-1/6',
                    $progressPercent > 0 => 'w-1/12', default => 'w-0',
                };
                $isActive = in_array($progressState, ['queued', 'processing'], true);
                $reasonLabel = match($item->reason) {
                    'no_match_found' => 'Nenhum Documento de Conhecimento correspondente foi encontrado.',
                    'document_already_has_different_file' => 'O documento identificado já possui outro arquivo e precisa de revisão.',
                    'ambiguous_source_document_id', 'ambiguous_normalized_filename' => 'Mais de um documento pode corresponder a este PDF.',
                    default => $item->reason ?: 'Associação manual necessária.',
                };
            @endphp
            <article class="relative isolate overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                @if($progress)
                    <div class="absolute inset-y-0 left-0 -z-10 transition-all duration-700 {{ $progressWidthClass }} {{ $progressState === 'failed' ? 'bg-rose-50' : ($progressState === 'completed' ? 'bg-emerald-50' : 'bg-brand-50') }}"></div>
                    @if($isActive)
                        <div class="absolute inset-x-0 top-0 h-1 overflow-hidden bg-slate-100"><div class="h-full bg-brand-600 transition-all duration-700 {{ $progressWidthClass }}"></div></div>
                    @endif
                @endif
                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-base font-semibold text-slate-900">{{ $item->original_filename }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $item->ingestionBatch->name ?: 'Importação #'.$item->ingestion_batch_id }} · {{ $item->ingestionBatch->source?->name ?? 'Sem origem' }} · {{ $item->created_at->format('d/m/Y H:i') }}</p>
                    @if($progress)
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm"><span class="inline-flex items-center gap-1.5 font-semibold {{ $progressState === 'failed' ? 'text-rose-700' : ($progressState === 'completed' ? 'text-emerald-700' : 'text-brand-700') }}">@if($isActive)<span class="size-2 animate-pulse rounded-full bg-current"></span>@endif {{ $progress['label'] ?? 'Reanalisando' }}</span><span class="text-xs font-medium text-slate-500">{{ $progressPercent }}%</span></div>
                    @else
                        <p class="mt-1 text-sm text-slate-600">{{ $reasonLabel }}</p>
                    @endif
                </div>
                @if($isActive)<span class="rounded-lg border border-brand-100 bg-white/80 px-3 py-2 text-sm font-semibold text-brand-800">{{ $progressState === 'queued' ? 'Na fila' : 'Processando' }}</span>@else<x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$item->ingestionBatch, $item])">Revisar</x-knowledge-admin.button>@endif
                </div>
            </article>
        @empty
            <x-knowledge-admin.empty-state>Nenhuma pendência encontrada.</x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
