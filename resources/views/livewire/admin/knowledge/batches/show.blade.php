<x-knowledge-admin.shell :title="$batch->name ?: 'Importação #'.$batch->id" :back="route('admin.knowledge.batches.index')" back-label="Importações">
    <x-knowledge-admin.page-header :badge="match ($batch->status) { 'COMPLETED' => ($batch->pending_items > 0 ? $batch->pending_items.' '.($batch->pending_items === 1 ? 'pendente' : 'pendentes') : 'Concluída'), 'PROCESSING' => 'Processando', default => 'Na fila' }" :badge-tone="match (true) { $batch->status !== 'COMPLETED' => 'info', $batch->pending_items > 0 => 'warning', default => 'success' }" :meta="[$batch->source?->name ?? 'Sem origem', $batch->created_at->format('d/m/Y H:i')]">
        <x-slot:primary>
            @if ($batch->pending_items > 0)
                @if ($firstPending)<x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$batch, $firstPending])">Revisar pendências</x-knowledge-admin.button>@endif
            @else
                <x-knowledge-admin.button :href="route('admin.knowledge.documents.index')">Ver documentos</x-knowledge-admin.button>
            @endif
        </x-slot:primary>
        <x-slot:more>
            @if ($batch->pending_items > 0)<x-knowledge-admin.menu-item wire:click="reanalyzePending">Reanalisar pendentes</x-knowledge-admin.menu-item>@endif
            <x-knowledge-admin.menu-item :href="route('admin.knowledge.upload')">Adicionar mais documentos</x-knowledge-admin.menu-item>
        </x-slot:more>
    </x-knowledge-admin.page-header>

    @if (session('batch-feedback'))<x-knowledge-admin.alert tone="success">{{ session('batch-feedback') }}</x-knowledge-admin.alert>@endif

    {{-- Único trecho com atualização automática. --}}
    <livewire:admin.knowledge.batches.progress :batch="$batch" :key="'progress-'.$batch->id" />

    <section class="grid gap-2" aria-label="Arquivos desta importação">
        @if ($items->isEmpty())
            <x-knowledge-admin.empty-state>Esta importação não possui arquivos.</x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :columns="[['label' => 'Arquivo'], ['label' => 'Documento identificado'], ['label' => 'Resultado'], ['label' => 'Situação'], ['label' => '', 'class' => 'w-px']]">
                @foreach ($items as $item)
                    @php
                        $found = (array) data_get($item->metadata, 'preliminary_identification.fields', []);
                        $title = $item->knowledgeDocument?->title ?? (filled($found['product_name'] ?? null) ? $found['product_name'] : null);
                        $support = match ($item->status) {
                            'PENDING' => match ($item->reason) { 'no_match_found' => 'Sem documento correspondente', 'document_already_has_different_file' => 'O documento identificado já tem outro PDF', 'ambiguous_source_document_id', 'ambiguous_normalized_filename' => 'Mais de um destino possível', default => 'Aguardando sua decisão' },
                            'IGNORED' => 'Resolvido sem incorporação',
                            'DUPLICATE' => 'Já existia na base',
                            'ERROR' => 'Falha ao processar o arquivo',
                            default => 'Incorporado à base',
                        };
                    @endphp
                    <tr wire:key="item-{{ $item->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-xs px-3 py-2"><span class="block truncate font-semibold text-slate-900" title="{{ $item->original_filename }}">{{ $item->original_filename }}</span></td>
                        <td class="max-w-sm px-3 py-2">
                            @if ($item->knowledge_document_id)
                                <a class="block truncate text-slate-900 hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.documents.show', $item->knowledge_document_id) }}" wire:navigate title="{{ $title }}">{{ $title }}</a>
                            @else
                                <span class="block truncate text-slate-700" title="{{ $title ?? '' }}">{{ $title ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $support }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><x-knowledge-admin.status-badge :status="$item->status" /></td>
                        <td class="px-2 py-1">
                            <x-knowledge-admin.row-actions>
                                @if ($item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)
                                    <x-knowledge-admin.button variant="secondary" size="sm" :href="route('admin.knowledge.pending.review', [$batch, $item])">Revisar</x-knowledge-admin.button>
                                @elseif ($item->knowledge_document_id)
                                    <x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.knowledge.documents.show', $item->knowledge_document_id)">Abrir</x-knowledge-admin.button>
                                @else
                                    <x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.knowledge.pending.review', [$batch, $item])">Detalhes</x-knowledge-admin.button>
                                @endif
                            </x-knowledge-admin.row-actions>
                        </td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </section>
    {{ $items->links() }}
</x-knowledge-admin.shell>
