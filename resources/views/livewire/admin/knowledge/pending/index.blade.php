<x-knowledge-admin.shell title="Pendências" subtitle="Arquivos que precisam da sua decisão antes de entrar na base.">
    <x-slot:actions>
        <x-knowledge-admin.button variant="secondary" wire:click="reanalyzeAll" loading-target="reanalyzeAll" loading="Enfileirando…">Reanalisar todas</x-knowledge-admin.button>
    </x-slot:actions>
    @if ($feedback)<x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.toolbar :count="number_format($items->total(), 0, ',', '.').' '.($items->total() === 1 ? 'pendência' : 'pendências')">
        <x-knowledge-admin.search compact wrapper-class="w-full sm:w-80" wire:model.live.debounce.350ms="search" placeholder="Nome do arquivo ou identificador" />
        @if ($search !== '')<x-knowledge-admin.button variant="quiet" wire:click="$set('search', '')">Limpar</x-knowledge-admin.button>@endif
    </x-knowledge-admin.toolbar>

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

        @if ($items->isEmpty())
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhuma pendência.</p>
                <p class="mt-1">Todos os arquivos enviados já foram incorporados ou resolvidos.</p>
                <x-knowledge-admin.button class="mt-3" variant="secondary" :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
            </x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :columns="[['label' => 'Arquivo'], ['label' => 'Importação'], ['label' => 'Origem'], ['label' => 'Enviado em'], ['label' => 'Situação'], ['label' => '', 'class' => 'w-px']]">
                @foreach ($items as $item)
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
                    <tr wire:key="pending-{{ $item->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-md px-3 py-2">
                            <a class="block truncate font-semibold text-slate-900 hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.pending.review', [$item->ingestionBatch, $item]) }}" wire:navigate title="{{ $title }}">{{ $title }}@if ($ingredient)<span class="font-normal text-slate-500"> · {{ $ingredient }}</span>@endif</a>
                            <span class="block truncate text-xs text-slate-500">{{ $title !== $item->original_filename ? $item->original_filename : '' }}@if ($isActive)<span class="font-medium text-brand-700">{{ $title !== $item->original_filename ? ' · ' : '' }}{{ $progress['label'] ?? 'Reanalisando' }} · {{ max(0, min(100, (int) ($progress['percent'] ?? 0))) }}%</span>@elseif ($progressState === 'failed')<span class="font-medium text-rose-700">{{ $title !== $item->original_filename ? ' · ' : '' }}{{ $progress['label'] ?? 'A reanálise falhou' }}</span>@endif</span>
                        </td>
                        <td class="max-w-xs truncate whitespace-nowrap px-3 py-2 text-slate-700">{{ $item->ingestionBatch->name ?: 'Importação #'.$item->ingestion_batch_id }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $item->ingestionBatch->source?->name ?? 'Sem origem' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $item->created_at->format('d/m/Y H:i') }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><x-knowledge-admin.badge :tone="$badgeTone">{{ $badgeLabel }}</x-knowledge-admin.badge></td>
                        <td class="px-2 py-1">
                            @unless ($isActive)
                                <x-knowledge-admin.row-actions>
                                    <x-knowledge-admin.button variant="quiet" size="sm" wire:click="reanalyzeItem({{ $item->id }})" loading-target="reanalyzeItem({{ $item->id }})" loading="Enfileirando…">Reanalisar</x-knowledge-admin.button>
                                    <x-knowledge-admin.button variant="secondary" size="sm" :href="route('admin.knowledge.pending.review', [$item->ingestionBatch, $item])">Revisar</x-knowledge-admin.button>
                                </x-knowledge-admin.row-actions>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
