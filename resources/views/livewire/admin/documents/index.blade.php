<x-knowledge-admin.shell title="Documentos" subtitle="Bulas e materiais já incorporados à base de conhecimento.">
    <x-slot:actions>
        <x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
    </x-slot:actions>

    <x-knowledge-admin.toolbar :count="number_format($documents->total(), 0, ',', '.').' '.($documents->total() === 1 ? 'documento' : 'documentos')">
        <x-knowledge-admin.search compact wrapper-class="w-full sm:w-80" wire:model.live.debounce.300ms="search" placeholder="Título, identificador ou tipo" />
        <x-knowledge-admin.select compact label="Arquivo PDF" wire:model.live="fileStatus" wrapper-class="w-full sm:w-44">
            <option value="">Todos os arquivos</option>
            @foreach ($fileStatuses as $status)<option value="{{ $status }}">{{ match ($status) { 'PENDING' => 'Sem PDF ainda', 'PROCESSING' => 'Armazenando', 'READY' => 'Com PDF', 'FAILED' => 'Falha no arquivo', default => $status } }}</option>@endforeach
        </x-knowledge-admin.select>
        @if ($search !== '' || $fileStatus !== '')<x-knowledge-admin.button variant="quiet" wire:click="clearFilters">Limpar</x-knowledge-admin.button>@endif
        <x-slot:actions>
            @if ($unlinkedCount > 0)
                <x-knowledge-admin.button variant="secondary" wire:click="linkUnlinkedDocuments" loading-target="linkUnlinkedDocuments" loading="Enfileirando…" title="Liga ao princípio ativo os documentos prontos que não têm nenhum; é por esse vínculo que o Perguntar encontra a bula. Não lê os PDFs de novo." data-testid="link-unlinked-documents">Vincular {{ $unlinkedCount === 1 ? '1 documento sem princípio ativo' : $unlinkedCount.' documentos sem princípio ativo' }}</x-knowledge-admin.button>
            @endif
            @if ($pendingIndexCount > 0)
                <x-knowledge-admin.button variant="secondary" wire:click="generatePendingSearchIndexes" loading-target="generatePendingSearchIndexes" loading="Enfileirando…" title="Gera só o índice de busca (embeddings) dos documentos prontos que estão aguardando ou com falha; os trechos e os PDFs não mudam." data-testid="generate-pending-search-indexes">Gerar {{ $pendingIndexCount === 1 ? '1 índice de busca pendente' : $pendingIndexCount.' índices de busca pendentes' }}</x-knowledge-admin.button>
            @endif
            <x-knowledge-admin.button variant="quiet" :href="route('admin.knowledge.documents.create')">Cadastrar sem PDF</x-knowledge-admin.button>
        </x-slot:actions>
    </x-knowledge-admin.toolbar>
    @if ($reindexFeedback)<x-knowledge-admin.alert>{{ $reindexFeedback }}</x-knowledge-admin.alert>@endif

    <div wire:loading.class="opacity-60" wire:target="search,fileStatus,clearFilters,sortBy">
        @if ($documents->isEmpty())
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhum documento encontrado.</p>
                @if ($search !== '' || $fileStatus !== '')<x-knowledge-admin.button class="mt-3" variant="secondary" wire:click="clearFilters">Limpar filtros</x-knowledge-admin.button>@else<x-knowledge-admin.button class="mt-3" :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>@endif
            </x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :sort="$sort" :direction="$direction" :columns="[
                ['label' => 'Título', 'sort' => 'title'],
                ['label' => 'Tipo', 'sort' => 'type'],
                ['label' => 'Origem'],
                ['label' => 'Trechos', 'sort' => 'trechos', 'align' => 'right'],
                ['label' => 'Índice de busca'],
                ['label' => 'Situação'],
                ['label' => 'Atualizado', 'sort' => 'updated'],
                ['label' => '', 'class' => 'w-px'],
            ]">
                @foreach ($documents as $document)
                    @php
                        [$tone, $label] = match (true) {
                            $document->file_status === 'FAILED' || $document->content_status === 'FAILED' || $document->embedding_status === 'FAILED' => ['danger', 'Com falha'],
                            $document->content_status === 'PROCESSING' || $document->file_status === 'PROCESSING' || $document->embedding_status === 'PROCESSING' => ['info', 'Processando'],
                            $document->file_status !== 'READY' => ['neutral', 'Sem PDF'],
                            $document->content_status !== 'READY' => ['warning', 'PDF sem trechos'],
                            $document->embedding_status !== 'READY' => ['warning', 'Sem índice de busca'],
                            default => ['success', 'Pronto'],
                        };
                        $stateText = fn (?string $status, array $texts): string => $texts[$status] ?? 'Aguardando';
                        $detail = 'Arquivo PDF: '.$stateText($document->file_status, ['READY' => 'Disponível', 'PROCESSING' => 'Armazenando', 'FAILED' => 'Falhou']).' · Texto e trechos: '.$stateText($document->content_status, ['READY' => 'Processado', 'PROCESSING' => 'Processando', 'FAILED' => 'Falhou']).' · Índice de busca: '.$stateText($document->embedding_status, ['READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou']);
                        $index = $stateText($document->embedding_status, ['READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou']);
                    @endphp
                    <tr wire:key="doc-{{ $document->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-md px-3 py-2">
                            <a class="block truncate font-semibold text-slate-900 hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate title="{{ $document->title }}">{{ $document->title }}</a>
                            @if ($document->external_key)<span class="block truncate text-xs text-slate-500">{{ $document->external_key }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $document->documentTypeLabel() }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $document->source->name }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $document->content_status === 'READY' ? number_format($document->chunks_count, 0, ',', '.') : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $index }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><x-knowledge-admin.badge :tone="$tone" :title="$detail">{{ $label }}</x-knowledge-admin.badge></td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600" title="{{ $document->updated_at->format('d/m/Y H:i') }}">{{ $document->updated_at->format('d/m/Y') }}</td>
                        <td class="px-2 py-1"><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.knowledge.documents.show', $document)">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </div>
    {{ $documents->links() }}
</x-knowledge-admin.shell>
