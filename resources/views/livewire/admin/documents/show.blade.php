<x-knowledge-admin.shell :title="$document->title" :back="route('admin.knowledge.documents.index')" back-label="Documentos">
    @php
        $hasFile = $document->storage_key || $document->storage_path;
        [$tone, $label] = match (true) {
            $document->file_status === 'FAILED' || $document->content_status === 'FAILED' || $document->embedding_status === 'FAILED' => ['danger', 'Com falha'],
            $document->content_status === 'PROCESSING' || $document->file_status === 'PROCESSING' || $document->embedding_status === 'PROCESSING' => ['info', 'Processando'],
            ! $hasFile => ['neutral', 'Sem PDF'],
            $document->content_status !== 'READY' => ['warning', 'PDF sem trechos'],
            $document->embedding_status !== 'READY' => ['warning', 'Sem índice de busca'],
            default => ['success', 'Pronto'],
        };
        $ingredients = $document->relationLoaded('ingredients') ? $document->ingredients : collect();
    @endphp
    <div x-data="{ tab: ['trechos', 'arquivo', 'processamento'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'trechos' }" x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))" class="grid gap-5">
        <x-knowledge-admin.page-header :badge="$label" :badge-tone="$tone" :meta="[$document->documentTypeLabel(), $document->source->name]">
            @if ($hasFile)
                <x-slot:primary>
                    <x-knowledge-admin.confirm-modal
                        :action="$document->content_status === 'READY' ? 'processDocument(true)' : 'processDocument(false)'"
                        :tone="$document->content_status === 'READY' ? 'danger' : 'primary'"
                        :title="$document->content_status === 'READY' ? 'Reprocessar documento?' : 'Processar documento?'"
                        :message="$document->content_status === 'READY' ? 'Os trechos atuais deste documento serão substituídos pelos do novo processamento, que roda em segundo plano.' : 'O texto do PDF será extraído e dividido em trechos. O processamento roda em segundo plano.'"
                        :confirm-label="$document->content_status === 'READY' ? 'Reprocessar' : 'Processar documento'"
                        loading="Enfileirando…">
                        <x-knowledge-admin.button :variant="$document->content_status === 'READY' ? 'secondary' : 'primary'">{{ $document->content_status === 'READY' ? 'Reprocessar' : 'Processar documento' }}</x-knowledge-admin.button>
                    </x-knowledge-admin.confirm-modal>
                </x-slot:primary>
            @else
                <x-slot:primary><x-knowledge-admin.button :href="route('admin.knowledge.upload')">Enviar o PDF</x-knowledge-admin.button></x-slot:primary>
            @endif
            <x-slot:more>
                @if ($document->ingestionBatch)<x-knowledge-admin.menu-item :href="route('admin.knowledge.batches.show', $document->ingestionBatch)">Ver a importação</x-knowledge-admin.menu-item>@endif
                <x-knowledge-admin.menu-item :href="route('admin.knowledge.sources.show', $document->source)">Ver a origem</x-knowledge-admin.menu-item>
                <x-knowledge-admin.menu-item x-on:click="tab = 'processamento'">Situação do processamento</x-knowledge-admin.menu-item>
            </x-slot:more>
        </x-knowledge-admin.page-header>

        @if ($processingFeedback)<x-knowledge-admin.alert>{{ $processingFeedback }}</x-knowledge-admin.alert>@endif
        @if (($processing['error_code'] ?? null) !== null)
            <x-knowledge-admin.alert tone="danger">Falha no processamento: {{ $processing['error_code'] }}@if (($processing['error_id'] ?? null) !== null) · Código de referência: {{ $processing['error_id'] }}@endif</x-knowledge-admin.alert>
        @endif

        {{-- Resumo decisório --}}
        <x-knowledge-admin.card>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-slate-500">Trechos</dt><dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $document->content_status === 'READY' ? $document->chunks_count : 'Ainda não gerados' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Índice de busca</dt><dd class="mt-0.5 text-base font-semibold text-slate-900">{{ match ($document->embedding_status) { 'READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou', default => 'Aguardando' } }}</dd></div>
                <div><dt class="text-xs text-slate-500">Processado em</dt><dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $document->processed_at?->format('d/m/Y H:i') ?? 'Pendente' }}</dd></div>
                <div class="min-w-0"><dt class="text-xs text-slate-500">Princípios ativos ligados</dt><dd class="mt-0.5 truncate text-base font-semibold text-slate-900" title="{{ $ingredients->pluck('canonical_name')->join(', ') }}">{{ $ingredients->isNotEmpty() ? $ingredients->pluck('canonical_name')->join(', ') : 'Nenhum' }}</dd></div>
            </dl>
        </x-knowledge-admin.card>

        <div class="grid gap-4">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Detalhes do documento">
                @foreach ([['trechos', 'Trechos ('.$document->chunks_count.')'], ['arquivo', 'Arquivo'], ['processamento', 'Processamento']] as [$tabKey, $tabLabel])
                    <a href="#{{ $tabKey }}" role="tab" x-on:click.prevent="tab = '{{ $tabKey }}'" x-bind:aria-selected="tab === '{{ $tabKey }}'" x-bind:class="tab === '{{ $tabKey }}' ? 'border-accent-500 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900'" class="inline-flex min-h-11 items-center whitespace-nowrap border-b-2 px-3 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $tabLabel }}</a>
                @endforeach
            </nav>

            <div x-show="tab === 'trechos'" role="tabpanel" class="grid gap-3">
                @forelse ($chunks as $chunk)
                    <x-knowledge-admin.card wire:key="chunk-{{ $chunk->id }}">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold text-slate-900">#{{ $chunk->chunk_index }}</span>
                            <span class="text-slate-500">{{ $chunk->section ?? 'Sem seção' }}</span>
                            @if ($chunk->page_start || $chunk->page_end)<x-knowledge-admin.badge>páginas {{ $chunk->page_start ?? '?' }}-{{ $chunk->page_end ?? '?' }}</x-knowledge-admin.badge>@endif
                        </div>
                        <pre class="mt-3 whitespace-pre-wrap break-words font-sans text-sm text-slate-800">{{ $chunk->content }}</pre>
                    </x-knowledge-admin.card>
                @empty
                    <x-knowledge-admin.empty-state>Nenhum trecho ainda. {{ $hasFile ? 'Processe o documento para extrair o texto.' : 'Envie o PDF pela importação para gerar os trechos.' }}</x-knowledge-admin.empty-state>
                @endforelse
                {{ $chunks->links() }}
            </div>

            <div x-show="tab === 'arquivo'" x-cloak role="tabpanel">
                <x-knowledge-admin.card>
                    <x-knowledge-admin.facts class="lg:grid-cols-3">
                        <x-knowledge-admin.fact label="Arquivo">{{ $document->original_filename ?? 'Sem PDF' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Arquivo PDF">{{ match ($document->file_status) { 'READY' => 'Disponível', 'PROCESSING' => 'Armazenando', 'FAILED' => 'Falhou', default => 'Aguardando' } }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Importação">{{ $document->ingestionBatch?->name ?? 'Sem importação' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Chave externa">{{ $document->external_key ?: 'Não informada' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Identificador oficial">{{ $document->source_document_id ?: 'Não informado' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Idioma">{{ $document->language ?: 'Não informado' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact class="sm:col-span-2 lg:col-span-3" label="Assinatura do arquivo (SHA-256)"><span class="break-all font-mono text-xs">{{ $document->sha256 ?? 'Sem PDF' }}</span></x-knowledge-admin.fact>
                    </x-knowledge-admin.facts>
                </x-knowledge-admin.card>
            </div>

            <div x-show="tab === 'processamento'" x-cloak role="tabpanel">
                <x-knowledge-admin.card class="grid gap-4">
                    <x-knowledge-admin.document-processing-states :document="$document" />
                    @if ($processing !== [])
                        <x-knowledge-admin.facts class="border-t border-slate-100 pt-4 lg:grid-cols-3">
                            @foreach (['pages' => 'Páginas lidas', 'chunks' => 'Trechos gerados', 'method' => 'Método de leitura', 'ocr' => 'Leitura por imagem (OCR)', 'duration_ms' => 'Duração (ms)', 'error_code' => 'Código do erro', 'error_id' => 'Código de referência'] as $key => $factLabel)
                                @if (isset($processing[$key]) && is_scalar($processing[$key]))<x-knowledge-admin.fact :label="$factLabel">{{ is_bool($processing[$key]) ? ($processing[$key] ? 'Sim' : 'Não') : $processing[$key] }}</x-knowledge-admin.fact>@endif
                            @endforeach
                        </x-knowledge-admin.facts>
                    @else
                        <p class="text-sm text-slate-500">Ainda não há registro de processamento.</p>
                    @endif
                </x-knowledge-admin.card>
            </div>
        </div>
    </div>
</x-knowledge-admin.shell>
