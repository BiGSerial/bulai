<x-knowledge-admin.shell :title="$document->displayName()" :back="route('admin.knowledge.documents.index')" back-label="Documentos">
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
    <div x-data="{ tab: ['trechos', 'principios', 'evidencias', 'arquivo', 'processamento'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'trechos' }" x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))" x-on:bulai-realtime.window="$wire.$refresh()" @if ($timelineActive) wire:poll.3s @endif class="grid gap-5">
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
                @if ($document->content_status === 'READY')
                    <x-knowledge-admin.menu-item wire:click="rebuildSearchIndex" wire:loading.attr="disabled" wire:target="rebuildSearchIndex" data-testid="rebuild-search-index-menu">Refazer o índice de busca</x-knowledge-admin.menu-item>
                    <x-knowledge-admin.menu-item wire:click="linkIngredients" wire:loading.attr="disabled" wire:target="linkIngredients" data-testid="link-ingredients-menu">Revincular princípios ativos</x-knowledge-admin.menu-item>
                @endif
            </x-slot:more>
        </x-knowledge-admin.page-header>

        @if ($processingFeedback)<x-knowledge-admin.alert>{{ $processingFeedback }}</x-knowledge-admin.alert>@endif
        @if ($document->content_status === 'READY' && ! in_array($document->embedding_status, ['READY', 'PROCESSING'], true) && ! $timelineActive)
            @php
                $indexFailed = $document->embedding_status === 'FAILED';
            @endphp
            <x-knowledge-admin.alert :tone="$indexFailed ? 'danger' : 'warning'" data-testid="embedding-pending">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $indexFailed ? 'O índice de busca falhou' : 'O índice de busca ainda não foi gerado' }}@if ($indexFailed && is_string($embeddingFailure?->detail['error_code'] ?? null)) ({{ $embeddingFailure->detail['error_code'] }})@endif.</p>
                        <p class="mt-1">@if ($indexFailed && $embeddingFailure?->errorLabel()){{ ucfirst($embeddingFailure->errorLabel()) }}. @endif{{ $indexFailed ? 'Os trechos estão prontos e o PDF não será lido de novo: só os trechos sem vetor vão ao serviço de embeddings, em lotes menores.' : 'Sem o índice, o Perguntar só encontra esta bula por busca textual. Os trechos já estão prontos; gerar o índice não lê o PDF de novo.' }}</p>
                    </div>
                    <x-knowledge-admin.button variant="secondary" wire:click="rebuildSearchIndex" loading-target="rebuildSearchIndex" loading="Enfileirando…" data-testid="rebuild-search-index">{{ $indexFailed ? 'Refazer só o índice de busca' : 'Gerar o índice de busca' }}</x-knowledge-admin.button>
                </div>
            </x-knowledge-admin.alert>
        @endif
        @if ($timelineActive)
            <x-knowledge-admin.callout title="Processando agora:" data-testid="timeline-live">{{ $timelineActive->stepLabel() }}@if ($timelineActive->summary() !== '') · {{ $timelineActive->summary() }}@endif · há {{ $timelineActive->durationLabel() }}. <a class="underline" href="#processamento" x-on:click.prevent="tab = 'processamento'">Ver a linha do tempo</a></x-knowledge-admin.callout>
        @endif
        @if ($document->content_status === 'READY' && $ingredients->isEmpty() && ! $timelineActive)
            <x-knowledge-admin.alert tone="warning" data-testid="ingredients-missing">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Nenhum princípio ativo ligado a esta bula.</p>
                        <p class="mt-1">É por esse vínculo que o Perguntar chega à bula a partir do nome do remédio; sem ele, a resposta é "ainda não consigo ligar a uma bula". O vínculo usa o registro ANVISA, o princípio ativo identificado ou o nome do produto no catálogo; não lê o PDF de novo.</p>
                    </div>
                    <x-knowledge-admin.button variant="secondary" wire:click="linkIngredients" loading-target="linkIngredients" loading="Vinculando…" data-testid="link-ingredients">Vincular princípios ativos</x-knowledge-admin.button>
                </div>
            </x-knowledge-admin.alert>
        @endif
        @if (($processing['error_code'] ?? null) !== null)
            <x-knowledge-admin.alert tone="danger">Falha no processamento: {{ $processing['error_code'] }}@if (($processing['error_id'] ?? null) !== null) · Código de referência: {{ $processing['error_id'] }}@endif</x-knowledge-admin.alert>
        @endif

        {{-- Identificação: o que esta bula é e a que está ligada; editável quando o arquivo veio sem metadados ou errado. --}}
        <x-knowledge-admin.card class="grid gap-3" data-testid="document-identity">
            @php $metadata = (array) $document->metadata; @endphp
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-800">Identificação</h2>
                @unless ($editingIdentity)<x-knowledge-admin.button variant="secondary" size="sm" wire:click="editIdentity" data-testid="edit-identity">Editar</x-knowledge-admin.button>@endunless
            </div>
            @if ($editingIdentity)
                <form wire:submit="saveIdentity" class="grid gap-3 sm:grid-cols-2">
                    <x-knowledge-admin.input label="Nome do medicamento" required wire:model="identity.product_name" hint="Como na caixa (ex.: DORFLEX, DORFLEX UNO). É por ele que a marca acha esta bula." />
                    <x-knowledge-admin.input label="Princípio ativo declarado" optional wire:model="identity.active_ingredient" hint="Como na bula (ex.: dipirona monoidratada + citrato de orfenadrina + cafeína)." />
                    <x-knowledge-admin.select label="Tipo" required wire:model="identity.document_type">
                        @foreach (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions() as $value => $typeLabel)<option value="{{ $value }}">{{ $typeLabel }}</option>@endforeach
                    </x-knowledge-admin.select>
                    <x-knowledge-admin.input label="Registro ANVISA" optional wire:model="identity.registration_number" />
                    <x-knowledge-admin.input label="Detentor do registro" optional wire:model="identity.holder" />
                    <x-knowledge-admin.input label="Título" required wire:model="identity.title" />
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <x-knowledge-admin.button type="submit" loading-target="saveIdentity" loading="Salvando…">Salvar e refazer o vínculo</x-knowledge-admin.button>
                        <x-knowledge-admin.button variant="secondary" wire:click="$set('editingIdentity', false)">Cancelar</x-knowledge-admin.button>
                    </div>
                </form>
            @else
                <x-knowledge-admin.facts class="sm:grid-cols-2 lg:grid-cols-4">
                    <x-knowledge-admin.fact label="Medicamento">{{ $document->medicineName() !== '' ? $document->medicineName() : 'Não identificado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Princípio ativo declarado">{{ $metadata['active_ingredient'] ?? null ?: 'Não informado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Princípios ativos ligados">{{ $ingredients->isNotEmpty() ? $ingredients->pluck('canonical_name')->join(', ') : 'Nenhum' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Tipo">{{ $document->documentTypeLabel() }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Registro ANVISA">{{ $metadata['registration_number'] ?? null ?: 'Não informado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Detentor do registro">{{ $metadata['holder'] ?? null ?: 'Não informado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Título">{{ $document->title }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Arquivo original">{{ $document->original_filename ?: 'Não informado' }}</x-knowledge-admin.fact>
                </x-knowledge-admin.facts>
                @if ($opening !== '')
                    <div class="grid gap-1">
                        <p class="text-xs font-medium text-slate-500">Começo do texto da bula</p>
                        <p class="whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700" data-testid="document-opening">{{ $opening }}</p>
                    </div>
                @endif
            @endif
        </x-knowledge-admin.card>

        {{-- Resumo decisório --}}
        <x-knowledge-admin.card class="py-3 sm:py-3">
            <x-knowledge-admin.facts class="sm:grid-cols-3 lg:grid-cols-6">
                <x-knowledge-admin.fact label="Trechos">{{ $document->content_status === 'READY' ? $document->chunks_count : 'Ainda não gerados' }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Índice de busca">{{ match ($document->embedding_status) { 'READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou', default => 'Aguardando' } }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Arquivo PDF">{{ match ($document->file_status) { 'READY' => 'Disponível', 'PROCESSING' => 'Armazenando', 'FAILED' => 'Falhou', default => 'Aguardando' } }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Processado em">{{ $document->processed_at?->format('d/m/Y H:i') ?? 'Pendente' }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Idioma">{{ $document->language ?: 'Não informado' }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Princípios ativos ligados"><span class="block truncate" title="{{ $ingredients->pluck('canonical_name')->join(', ') }}">{{ $ingredients->isNotEmpty() ? $ingredients->pluck('canonical_name')->join(', ') : 'Nenhum' }}</span></x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
        </x-knowledge-admin.card>

        <div class="grid gap-4">
            <x-knowledge-admin.tabs alpine aria-label="Detalhes do documento" model="tab" :tabs="[['key' => 'trechos', 'label' => 'Trechos ('.$document->chunks_count.')'], ['key' => 'principios', 'label' => 'Princípios ativos ('.$ingredients->count().')'], ['key' => 'evidencias', 'label' => 'Evidências extraídas ('.$facts->count().')'], ['key' => 'arquivo', 'label' => 'Arquivo'], ['key' => 'processamento', 'label' => 'Processamento']]" />

            {{-- Correção do vínculo bula ↔ princípio ativo: é por ele que o Perguntar e a bula simples acham esta bula. --}}
            <div x-show="tab === 'principios'" x-cloak role="tabpanel" class="grid gap-3" data-testid="ingredient-links">
                <p class="text-sm text-slate-600">Princípios ativos de que esta bula trata. Tire o que estiver errado e ligue o certo: a correção manual fica, e o "Revincular" automático não a desfaz.</p>
                <ul class="grid gap-2">
                    @forelse ($ingredients as $ingredient)
                        <li wire:key="ing-{{ $ingredient->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                            <span class="min-w-0"><span class="font-medium text-slate-800">{{ $ingredient->canonical_name }}</span> <x-knowledge-admin.badge :tone="$ingredient->pivot->method === 'manual' ? 'success' : 'neutral'">{{ $ingredient->pivot->method === 'manual' ? 'manual' : 'automático · '.$ingredient->pivot->method }}</x-knowledge-admin.badge></span>
                            <x-knowledge-admin.button variant="secondary" size="sm" wire:click="detachIngredient({{ $ingredient->id }})" wire:loading.attr="disabled" data-testid="detach-ingredient-{{ $ingredient->id }}">Tirar</x-knowledge-admin.button>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Nenhum princípio ativo ligado.</li>
                    @endforelse
                </ul>
                <div class="grid gap-2">
                    <x-knowledge-admin.input label="Ligar princípio ativo" wire:model.live.debounce.300ms="ingredientSearch" placeholder="Nome em português ou inglês (ex.: dipirona)" autocomplete="off" data-testid="ingredient-search" />
                    @if (mb_strlen(trim($ingredientSearch)) >= 3)
                        <ul class="grid gap-1">
                            @foreach ($ingredientOptions as $option)
                                <li wire:key="opt-{{ $option['id'] }}" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                    <span>{{ $option['name'] }}@if ($option['alias'] !== null) <span class="text-slate-500">({{ $option['alias'] }})</span>@endif</span>
                                    <x-knowledge-admin.button size="sm" wire:click="attachIngredient({{ $option['id'] }})" wire:loading.attr="disabled">Ligar</x-knowledge-admin.button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($ingredientOptions === [] && mb_strlen(trim($ingredientSearch)) >= 4)
                            <x-knowledge-admin.button variant="secondary" wire:click="createIngredient" wire:loading.attr="disabled" data-testid="create-ingredient">Criar o princípio ativo “{{ trim($ingredientSearch) }}” e ligar</x-knowledge-admin.button>
                        @endif
                    @endif
                </div>
            </div>

            <div x-show="tab === 'evidencias'" x-cloak role="tabpanel" class="grid gap-2" data-testid="leaflet-facts">
                <p class="text-sm text-slate-600">Trechos que a IA localizou na bula inteira quando a busca por trechos não respondeu a uma pergunta de paciente. Cada um aponta para o trecho, a seção, a página e a versão do documento; o Perguntar reaproveita os não rejeitados. Revise como farmacêutico: verificada, rejeitada ou apagada.</p>
                @forelse ($facts as $fact)
                    <article wire:key="fact-{{ $fact->id }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <x-knowledge-admin.badge :tone="match ($fact->review_status) { 'verified' => 'success', 'rejected' => 'danger', default => 'warning' }">{{ $fact->reviewLabel() }}</x-knowledge-admin.badge>
                            <span class="text-slate-500">{{ $fact->section ?: 'Sem seção' }}</span>
                            @if ($fact->page_start)<x-knowledge-admin.badge>página {{ $fact->page_start }}</x-knowledge-admin.badge>@endif
                            @if ($fact->chunk)<span class="text-slate-500">trecho #{{ $fact->chunk->chunk_index }}</span>@endif
                            <span class="text-slate-500">confiança {{ number_format((float) $fact->confidence, 2, ',', '') }} · {{ $fact->extraction_method }} · {{ $fact->model }} · {{ $fact->extracted_at?->format('d/m/Y H:i') }}</span>
                            @if ($fact->medicine)<span class="text-slate-500">pergunta sobre {{ $fact->medicine->product_name }}</span>@endif
                        </div>
                        @if ($fact->question)<p class="mt-1 text-xs italic text-slate-500">“{{ $fact->question }}”</p>@endif
                        <blockquote class="mt-1.5 whitespace-pre-wrap break-words border-l-4 border-brand-200 pl-3 text-sm text-slate-800">{{ $fact->excerpt }}</blockquote>
                        @if ($fact->summary)<p class="mt-1 text-sm text-slate-700">Em palavras simples: {{ $fact->summary }}</p>@endif
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($fact->review_status !== 'verified')<x-knowledge-admin.button variant="secondary" size="sm" wire:click="reviewFact({{ $fact->id }}, 'verified')">Verificada</x-knowledge-admin.button>@endif
                            @if ($fact->review_status !== 'rejected')<x-knowledge-admin.button variant="quiet" size="sm" wire:click="reviewFact({{ $fact->id }}, 'rejected')">Rejeitar</x-knowledge-admin.button>@endif
                            @if ($fact->review_status !== 'pending')<x-knowledge-admin.button variant="quiet" size="sm" wire:click="reviewFact({{ $fact->id }}, 'pending')">Devolver à revisão</x-knowledge-admin.button>@endif
                            <x-knowledge-admin.confirm-modal :action="'deleteFact('.$fact->id.')'" tone="danger" title="Apagar esta evidência?" message="O Perguntar deixa de usá-la nas próximas respostas. O trecho da bula continua no documento." confirm-label="Apagar" loading="Apagando…">
                                <x-knowledge-admin.button variant="quiet" size="sm">Apagar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        </div>
                    </article>
                @empty
                    <x-knowledge-admin.empty-state>Nenhuma evidência extraída ainda. Elas surgem quando uma pergunta de paciente leva o Perguntar a ler a bula inteira.</x-knowledge-admin.empty-state>
                @endforelse
            </div>

            <div x-show="tab === 'trechos'" role="tabpanel" class="grid gap-2">
                @forelse ($chunks as $chunk)
                    <article wire:key="chunk-{{ $chunk->id }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-semibold text-slate-900">#{{ $chunk->chunk_index }}</span>
                            <span class="text-slate-500">{{ $chunk->section ?? 'Sem seção' }}</span>
                            @if ($chunk->page_start || $chunk->page_end)<x-knowledge-admin.badge>páginas {{ $chunk->page_start ?? '?' }}-{{ $chunk->page_end ?? '?' }}</x-knowledge-admin.badge>@endif
                        </div>
                        <pre class="mt-1.5 max-h-96 overflow-auto whitespace-pre-wrap break-words font-sans text-sm text-slate-800">{{ $chunk->content }}</pre>
                    </article>
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

            <div x-show="tab === 'processamento'" x-cloak role="tabpanel" class="grid gap-4">
                <x-knowledge-admin.section title="Linha do tempo do processamento" description="Cada etapa da execução atual, com o que foi lido e quanto demorou. Em andamento, a lista se atualiza sozinha.">
                    <x-knowledge-admin.timeline :events="$timeline" />
                </x-knowledge-admin.section>
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
