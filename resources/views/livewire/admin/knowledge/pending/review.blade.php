<x-knowledge-admin.shell :title="(data_get($analysis, 'medicine.rows.0.value') ?: 'Arquivo sem identificação')" :back="route('admin.knowledge.pending.index')" back-label="Pendências">
@php
    $isPending = $item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING;
    $analysisActive = in_array($analysis['progress']['state'] ?? null, ['queued', 'processing'], true);
    $medicine = $analysis['medicine'];
    $rows = collect($medicine['rows']);
    $productRow = $rows->firstWhere('label', 'Medicamento');
    $registrationRow = $rows->firstWhere('label', 'Registro ANVISA');
    $typeRow = $rows->firstWhere('label', 'Tipo de bula');
    $extraRows = $rows->whereNotIn('label', ['Medicamento', 'Princípio ativo', 'Registro ANVISA', 'Tipo de bula'])->filter(fn (array $row): bool => $row['value'] !== null);
    $comparison = collect($analysis['classification']['comparison'] ?? []);
    $divergences = $comparison->where('relation', 'different')->count();
    $agreements = $comparison->where('relation', 'equal')->count();
    $canCreate = filled($newDocumentTitle) && filled($newDocumentType);
    $medicineName = $productRow['value'] ?? null;
    $validationLabel = match ($medicine['validation']) { 'CONFIRMED' => 'Confirmado no catálogo', 'CONFLICT' => 'Diverge do catálogo', 'NOT_FOUND' => 'Não encontrado no catálogo', 'NOT_AVAILABLE' => 'Catálogo não importado', default => 'Sem validação' };
    $validationTone = match ($medicine['validation']) { 'CONFIRMED' => 'success', 'CONFLICT' => 'danger', default => 'neutral' };
    // Uma única ação primária, escolhida pelo contexto; as demais ficam em "Mais".
    $primary = match (true) {
        ! $isPending => 'done',
        $analysisActive => 'waiting',
        $selectedDocument !== null => 'associate',
        $medicine['ingredient_missing'] && $aiConfigured => 'ai',
        $canCreate => 'create',
        default => 'resolve',
    };
    $consequence = match ($primary) {
        'waiting' => 'A análise automática está rodando. Esta tela atualiza sozinha; a decisão fica liberada ao terminar.',
        'associate' => 'O PDF será anexado ao documento escolhido e enviado para processamento. O arquivo original e o histórico são preservados.',
        'ai' => 'Falta o princípio ativo, indispensável para verificar interações. A IA local pode encontrá-lo no texto do PDF; você confere antes de finalizar.',
        'create' => $divergences > 0
            ? 'Há '.$divergences.' '.($divergences === 1 ? 'campo em que as leituras divergem' : 'campos em que as leituras divergem').': confira em "Análise automática" antes de criar. Criar gera o documento com os dados acima e envia o PDF para processamento.'
            : 'Cria o documento com os dados acima e envia o PDF para processamento. O arquivo original e o histórico são preservados.',
        'resolve' => 'Informe ao menos o título e o tipo do documento em "Resolver" para criar, ou associe a um documento existente.',
        default => 'Pendência concluída: '.match ($item->status) { 'ASSOCIATED' => 'o arquivo foi associado a um documento e está em processamento.', 'DUPLICATE' => 'o arquivo já existia na base e foi marcado como duplicado.', 'IGNORED' => 'o arquivo não será incorporado à base; o original e o histórico ficam guardados.', default => 'este item não está mais pendente.' },
    };
    $defaultTab = $isPending ? ($analysis['analyzed'] ? 'analise' : 'resolver') : 'arquivo';
    $nextUrl = $nextPending ? route('admin.knowledge.pending.review', [$nextPending->ingestionBatch, $nextPending]) : null;
    $historyLabels = ['reanalysis_requested' => 'Reanálise solicitada', 'preliminary_identification_completed' => 'Identificação preliminar concluída', 'reanalysis_remained_pending' => 'Item permaneceu pendente', 'document_associated' => 'Documento associado', 'duplicate_confirmed' => 'Duplicidade confirmada', 'integrity_check_failed' => 'Falha de integridade detectada', 'automatic_analysis' => 'Análise automática executada', 'reanalysis_queued' => 'Reanálise enfileirada', 'ai_suggestion_accepted' => 'Valores da IA/catálogo aceitos', 'ai_suggestion_rejected' => 'Análise da IA rejeitada', 'ai_decision_reverted' => 'Decisão sobre a IA desfeita', 'not_incorporated' => 'Resolvido sem incorporação'];
@endphp

    <div x-data="{ tab: ['analise', 'resolver', 'arquivo'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : '{{ $defaultTab }}', option: '{{ $canCreate ? 'create' : 'associate' }}', go(t, o) { this.tab = t; if (o) this.option = o; $nextTick(() => document.getElementById('detalhe')?.scrollIntoView({ behavior: 'smooth', block: 'start' })) } }"
         x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))" class="grid gap-5" @if ($analysisActive) wire:poll.3s="refreshAnalysis" @endif>

        <x-knowledge-admin.page-header :badge="match ($item->status) { 'PENDING' => 'Pendente', 'ASSOCIATED' => 'Associado', 'DUPLICATE' => 'Duplicado', 'IGNORED' => 'Não incorporado', default => $item->status }" :badge-tone="match ($item->status) { 'PENDING' => 'warning', 'ASSOCIATED' => 'success', 'DUPLICATE' => 'info', default => 'neutral' }" :meta="[$item->original_filename, ($item->ingestionBatch->source?->name ?? 'Sem origem').' · '.$item->created_at->format('d/m/Y H:i')]">
            <x-slot:primary>
                @switch ($primary)
                    @case ('waiting')
                        <x-knowledge-admin.button disabled>Analisando… {{ $analysis['progress']['percent'] ?? 0 }}%</x-knowledge-admin.button>
                        @break
                    @case ('associate')
                        <x-knowledge-admin.button wire:click="associate" loading-target="associate" loading="Associando…">Associar a “{{ \Illuminate\Support\Str::limit($selectedDocument->title, 36) }}”</x-knowledge-admin.button>
                        @break
                    @case ('ai')
                        <x-knowledge-admin.button wire:click="requestAiAnalysis" loading-target="requestAiAnalysis" loading="Enfileirando…">Consultar IA agora</x-knowledge-admin.button>
                        @break
                    @case ('create')
                        <x-knowledge-admin.button wire:click="createDocument" loading-target="createDocument" loading="Criando…">Criar documento e processar</x-knowledge-admin.button>
                        @break
                    @case ('resolve')
                        <x-knowledge-admin.button x-on:click="go('resolver', 'create')">Resolver pendência</x-knowledge-admin.button>
                        @break
                    @default
                        @if ($nextUrl)
                            <x-knowledge-admin.button :href="$nextUrl">Próxima pendência ({{ $pendingRemaining }} {{ $pendingRemaining === 1 ? 'restante' : 'restantes' }})</x-knowledge-admin.button>
                        @elseif ($item->knowledge_document_id)
                            <x-knowledge-admin.button :href="route('admin.knowledge.documents.show', $item->knowledge_document_id)">Abrir o documento</x-knowledge-admin.button>
                        @else
                            <x-knowledge-admin.button :href="route('admin.knowledge.pending.index')">Voltar às pendências</x-knowledge-admin.button>
                        @endif
                @endswitch
            </x-slot:primary>
            <x-slot:more>
                @if ($isPending)
                    @if ($primary !== 'create' && $canCreate)<x-knowledge-admin.menu-item wire:click="createDocument" :disabled="$analysisActive">Criar documento e processar</x-knowledge-admin.menu-item>@endif
                    <x-knowledge-admin.menu-item x-on:click="go('resolver', 'associate')">Associar a documento existente</x-knowledge-admin.menu-item>
                    <x-knowledge-admin.menu-item wire:click="reanalyze" :disabled="$analysisActive">Reanalisar este arquivo</x-knowledge-admin.menu-item>
                    @if ($aiConfigured && $primary !== 'ai')<x-knowledge-admin.menu-item wire:click="requestAiAnalysis" :disabled="$analysisActive">Consultar IA agora</x-knowledge-admin.menu-item>@endif
                @endif
                <x-knowledge-admin.menu-item target="_blank" :href="route('admin.knowledge.items.file', [$batch, $item])">Visualizar PDF</x-knowledge-admin.menu-item>
                <x-knowledge-admin.menu-item :href="route('admin.knowledge.items.download', [$batch, $item])" download>Baixar original</x-knowledge-admin.menu-item>
                <x-knowledge-admin.menu-item :href="route('admin.knowledge.batches.show', $batch)">Ver a importação</x-knowledge-admin.menu-item>
                @if ($isPending)<x-knowledge-admin.menu-item tone="danger" x-on:click="go('resolver', 'ignore')">Não incorporar…</x-knowledge-admin.menu-item>@endif
                @if (! $isPending && $item->knowledge_document_id && $nextUrl)<x-knowledge-admin.menu-item :href="route('admin.knowledge.documents.show', $item->knowledge_document_id)">Abrir o documento</x-knowledge-admin.menu-item>@endif
            </x-slot:more>
        </x-knowledge-admin.page-header>

        @if ($feedbackMessage)
            <x-knowledge-admin.alert :tone="match ($feedbackType) { 'success' => 'success', 'error' => 'danger', 'info' => 'info', default => 'warning' }">{{ $feedbackMessage }}@if ($errorId)<span class="mt-1 block">Código de referência: {{ $errorId }}</span>@endif</x-knowledge-admin.alert>
        @endif

        {{-- Resumo decisório: cada fato aparece uma única vez, com a sua origem. --}}
        <x-knowledge-admin.card class="grid gap-4">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Dados do medicamento">
                <div class="min-w-0">
                    <dt class="text-xs text-slate-500">Medicamento</dt>
                    <dd class="mt-0.5 truncate text-base font-semibold text-slate-900" title="{{ $medicineName ?? '' }}">{{ $medicineName ?? 'Não identificado' }}</dd>
                    @if ($productRow['source'] ?? null)<dd class="text-xs text-slate-500">{{ $productRow['source'] }}</dd>@endif
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-slate-500">Princípio ativo</dt>
                    @if ($medicine['ingredient_missing'])
                        <dd class="mt-0.5 text-base font-semibold text-rose-700">Não identificado</dd>
                        <dd class="text-xs text-slate-500">Necessário para verificar interações</dd>
                    @else
                        <dd class="mt-1 flex flex-wrap gap-1">@foreach (array_slice($medicine['ingredients'], 0, 3) as $ingredient)<x-knowledge-admin.badge tone="success" class="max-w-full truncate">{{ $ingredient }}</x-knowledge-admin.badge>@endforeach @if (count($medicine['ingredients']) > 3)<x-knowledge-admin.badge>+{{ count($medicine['ingredients']) - 3 }}</x-knowledge-admin.badge>@endif</dd>
                        <dd class="text-xs text-slate-500">Origem: {{ $medicine['ingredient_source'] }}</dd>
                    @endif
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-slate-500">Registro ANVISA</dt>
                    <dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $registrationRow['value'] ?? 'Não identificado' }}</dd>
                    <dd class="mt-1"><x-knowledge-admin.badge :tone="$validationTone">{{ $validationLabel }}</x-knowledge-admin.badge></dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-slate-500">Tipo de bula</dt>
                    <dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $typeRow['value'] ? (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions()[$typeRow['value']] ?? $typeRow['value']) : 'Não identificado' }}</dd>
                    @if ($typeRow['source'] ?? null)<dd class="text-xs text-slate-500">{{ $typeRow['source'] }}</dd>@endif
                </div>
            </dl>
            <p class="text-sm {{ $isPending ? 'text-slate-700' : 'text-emerald-800' }}">@if ($isPending)<span class="font-semibold text-brand-800">Próximo passo:</span> @endif{{ $consequence }}</p>
            @if ($isPending && $medicine['ingredient_missing'])
                <x-knowledge-admin.alert tone="danger"><p class="font-medium">Princípio ativo não identificado</p><p class="mt-1">Ele é indispensável para verificar interações medicamentosas. Use “Consultar IA agora” ou informe o registro correto em “Resolver” para buscá-lo no catálogo ANVISA.</p></x-knowledge-admin.alert>
            @endif
            @if ($selectedDocument && $isPending)
                <x-knowledge-admin.alert tone="info">
                    <p class="font-medium">Confirme a associação</p>
                    <p class="mt-1">O arquivo será anexado a <strong>{{ $selectedDocument->title }}</strong> ({{ $selectedDocument->source?->name ?? 'sem origem' }}). Use o botão “Associar” acima ou <x-knowledge-admin.button variant="quiet" wire:click="$set('selectedDocumentId', null)">escolha outro documento</x-knowledge-admin.button>.</p>
                </x-knowledge-admin.alert>
            @endif
            @if (! $isPending && $nextUrl)
                <div x-data="{ seconds: 6, timer: null, start() { this.timer = setInterval(() => { if (--this.seconds <= 0) { clearInterval(this.timer); Livewire.navigate(@js($nextUrl)) } }, 1000) }, stay() { clearInterval(this.timer); this.timer = null } }" x-init="start()" class="flex flex-wrap items-center gap-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                    <template x-if="timer"><span>Abrindo a próxima pendência em <span x-text="seconds"></span> s…</span></template>
                    <template x-if="!timer"><span>Você escolheu ficar nesta pendência.</span></template>
                    <x-knowledge-admin.button variant="quiet" x-show="timer" x-on:click="stay()">Ficar aqui</x-knowledge-admin.button>
                </div>
            @endif
        </x-knowledge-admin.card>

        {{-- Detalhe sob demanda: três abas, nenhuma repete o resumo acima. --}}
        <div id="detalhe" class="grid gap-4">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Detalhes da pendência">
                @foreach ([['analise', 'Análise automática', $divergences > 0 ? $divergences.' '.($divergences === 1 ? 'divergência' : 'divergências') : null], ['resolver', $isPending ? 'Resolver' : 'Resultado', null], ['arquivo', 'Arquivo e histórico', null]] as [$tabKey, $tabLabel, $tabBadge])
                    <a href="#{{ $tabKey }}" role="tab" x-on:click.prevent="tab = '{{ $tabKey }}'" x-bind:aria-selected="tab === '{{ $tabKey }}'" x-bind:class="tab === '{{ $tabKey }}' ? 'border-accent-500 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900'" class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $tabLabel }}@if ($tabBadge)<x-knowledge-admin.badge tone="warning">{{ $tabBadge }}</x-knowledge-admin.badge>@endif</a>
                @endforeach
            </nav>

            {{-- Aba: análise automática (só o que diverge; o que concorda vira uma frase) --}}
            <div x-show="tab === 'analise'" role="tabpanel" class="grid gap-4">
                @if ($analysisActive)
                    <x-knowledge-admin.alert tone="info">Em andamento: {{ $analysis['progress']['label'] }} ({{ $analysis['progress']['percent'] }}%).</x-knowledge-admin.alert>
                @elseif (($analysis['progress']['state'] ?? null) === 'failed')
                    <x-knowledge-admin.alert tone="danger">A última análise falhou: {{ $analysis['progress']['label'] }}. Use “Reanalisar este arquivo” no menu Mais.</x-knowledge-admin.alert>
                @endif

                @if (! $analysis['analyzed'])
                    <x-knowledge-admin.alert tone="warning">Este arquivo só passou pela leitura rápida do envio: o OCR e a IA ainda não rodaram. Use “Reanalisar este arquivo” no menu Mais.</x-knowledge-admin.alert>
                @else
                    @if ($agreements > 0 && $divergences === 0)
                        <p class="text-sm text-slate-600">As leituras do PDF, do catálogo ANVISA e da IA concordam em {{ $agreements }} {{ $agreements === 1 ? 'campo' : 'campos' }}. Abaixo, só o resumo de cada etapa.</p>
                    @elseif ($agreements > 0)
                        <p class="text-sm text-slate-600">{{ $agreements }} {{ $agreements === 1 ? 'campo concorda' : 'campos concordam' }} entre as leituras e {{ $divergences === 1 ? 'fica oculto' : 'ficam ocultos' }}; abaixo, só o que diverge ou só uma leitura encontrou.</p>
                    @endif
                    @include('livewire.shared.analysis-run', ['extraction' => $analysis['extraction'], 'classification' => $analysis['classification'] ? ['comparison' => $comparison->where('relation', '!=', 'equal')->values()->all()] + $analysis['classification'] : null, 'interactive' => $isPending])
                    @if ($agreements > 0)
                        <details class="rounded-xl border border-slate-200 bg-white p-3">
                            <summary class="cursor-pointer text-sm font-medium text-slate-800">Campos em que as leituras concordam ({{ $agreements }})</summary>
                            <ul class="mt-2 grid gap-1 text-sm">
                                @foreach ($comparison->where('relation', 'equal') as $row)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2"><span><span class="text-slate-500">{{ $row['label'] }}:</span> <span class="font-medium text-slate-900">{{ $row['heuristic'] ?? $row['catalog'] ?? $row['ai'] }}</span></span><x-knowledge-admin.badge tone="success">Concordam</x-knowledge-admin.badge></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    @if ($isPending && $comparison->isNotEmpty())
                        @if ($analysis['review'])
                            <x-knowledge-admin.alert :tone="$analysis['review']['accepted'] ? 'success' : 'warning'">
                                <p class="font-medium">{{ $analysis['review']['accepted'] ? 'Você aceitou valores da IA: '.implode(', ', $analysis['review']['fields']).'.' : 'Você rejeitou a análise da IA.' }}</p>
                                <p class="mt-1">Por {{ $analysis['review']['actor'] }}{{ $analysis['review']['decided_at'] ? ' em '.\Illuminate\Support\Carbon::parse($analysis['review']['decided_at'])->format('d/m/Y H:i') : '' }}.</p>
                                <x-knowledge-admin.button class="mt-3" variant="secondary" wire:click="revertAiDecision" loading-target="revertAiDecision" loading="Desfazendo…">Desfazer decisão</x-knowledge-admin.button>
                            </x-knowledge-admin.alert>
                        @elseif ($comparison->contains(fn ($row) => $row['ai_selectable'] || $row['catalog_selectable']))
                            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <p class="mr-auto text-sm text-slate-600">Marque os valores que quer usar e aplique.</p>
                                <x-knowledge-admin.button wire:click="applyAiSuggestion" loading-target="applyAiSuggestion" loading="Aplicando…" :disabled="$selectedAiFields === []">Aplicar marcados ({{ count($selectedAiFields) }})</x-knowledge-admin.button>
                                <x-knowledge-admin.button variant="secondary" wire:click="applyAiSuggestion(true)" loading-target="applyAiSuggestion" loading="Aplicando…">Aceitar tudo da IA</x-knowledge-admin.button>
                                <x-knowledge-admin.button variant="quiet" wire:click="rejectAiSuggestion" loading-target="rejectAiSuggestion" loading="Salvando…">Rejeitar a IA</x-knowledge-admin.button>
                            </div>
                        @endif
                    @endif
                @endif

                {{-- O que o PDF leu e o que o catálogo ANVISA informou, lado a lado, quando não há quadro da IA ou quando o catálogo diverge. --}}
                @php($catalogData = is_array($regulatoryValidation['catalog'] ?? null) ? $regulatoryValidation['catalog'] : [])
                @if ($identification !== [] && (! $analysis['analyzed'] || $catalogData !== [] || ($regulatoryValidation['conflicts'] ?? []) !== []))
                    @php($regulatoryStatus = $regulatoryValidation['status'] ?? null)
                    <div class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-slate-900">Validação no catálogo ANVISA</h3>
                            @if ($regulatoryStatus)<x-knowledge-admin.badge :tone="match ($regulatoryStatus) { 'CONFIRMED' => 'success', 'CONFLICT' => 'danger', default => 'neutral' }">{{ match ($regulatoryStatus) { 'CONFIRMED' => 'Confirmada', 'CONFLICT' => 'Divergente', 'NOT_FOUND' => 'Registro não encontrado', 'NOT_AVAILABLE' => 'Catálogo indisponível', default => 'Inconclusiva' } }}</x-knowledge-admin.badge>@endif
                        </div>
                        @if (($regulatoryValidation['conflicts'] ?? []) !== [])
                            <x-knowledge-admin.alert tone="danger">Conflitos: {{ collect($regulatoryValidation['conflicts'])->map(fn ($conflict) => match ($conflict) { 'registration_product_mismatch' => 'o produto do PDF não corresponde ao produto do catálogo', 'active_ingredient_mismatch' => 'o princípio ativo diverge do catálogo', default => str($conflict)->headline() })->implode('; ') }}.</x-knowledge-admin.alert>
                        @elseif ($regulatoryStatus === 'NOT_AVAILABLE')
                            <x-knowledge-admin.alert tone="warning">O catálogo da ANVISA ainda não foi importado, então o registro não pôde ser conferido. <a class="font-medium underline" href="{{ route('admin.knowledge.catalog.index') }}" wire:navigate>Importar o catálogo</a>.</x-knowledge-admin.alert>
                        @elseif (($regulatoryValidation['reason'] ?? null) === 'registration_not_identified')
                            <p class="text-sm text-slate-600">Inconclusiva porque o PDF não trouxe um número de registro identificável para consultar.</p>
                        @endif
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dados identificados no PDF @if ($identificationSummary)<span class="font-normal normal-case">· confiança {{ $identificationSummary['score'] }}/100</span>@endif</h4>
                                <x-knowledge-admin.facts class="mt-2 sm:grid-cols-1">
                                    @foreach (['product_name' => 'Medicamento', 'active_ingredient' => 'Princípio ativo', 'registration_number' => 'Registro ANVISA', 'document_type' => 'Tipo', 'holder' => 'Titular'] as $field => $fieldLabel)
                                        @if (isset($identification[$field]))<x-knowledge-admin.fact :label="$fieldLabel"><span class="line-clamp-2" title="{{ $identification[$field] }}">{{ $identification[$field] }}</span></x-knowledge-admin.fact>@endif
                                    @endforeach
                                </x-knowledge-admin.facts>
                                @if ($identificationSummary && $identificationSummary['ambiguous'])<p class="mt-2 text-xs text-amber-900">Campos ambíguos: {{ implode(', ', $identificationSummary['ambiguous']) }}.</p>@endif
                            </div>
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">O que o catálogo informou</h4>
                                @if ($catalogData !== [])
                                    <x-knowledge-admin.facts class="mt-2 sm:grid-cols-1">
                                        @foreach (['registration_number' => 'Registro', 'product_name' => 'Produto', 'active_ingredient' => 'Princípio ativo', 'registration_holder' => 'Titular', 'registration_status' => 'Situação regulatória'] as $field => $fieldLabel)
                                            @if (filled($catalogData[$field] ?? null))<x-knowledge-admin.fact :label="$fieldLabel">{{ $catalogData[$field] }}</x-knowledge-admin.fact>@endif
                                        @endforeach
                                    </x-knowledge-admin.facts>
                                    @if (is_array($regulatoryValidation['provenance'] ?? null))<p class="mt-2 text-xs text-slate-500">{{ $regulatoryValidation['provenance']['dataset'] ?? 'ANVISA' }} · Importação #{{ $regulatoryValidation['provenance']['catalog_import_id'] ?? '—' }}</p>@endif
                                @else
                                    <p class="mt-2 text-sm text-slate-500">Nenhum registro consultado.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                @if ($extraRows->isNotEmpty())
                    <details class="rounded-xl border border-slate-200 bg-white p-3">
                        <summary class="cursor-pointer text-sm font-medium text-slate-800">Outros dados ({{ $extraRows->count() }})</summary>
                        <x-knowledge-admin.facts class="mt-3 lg:grid-cols-3">
                            @foreach ($extraRows as $row)
                                <x-knowledge-admin.fact :label="$row['label']"><span class="line-clamp-2" title="{{ $row['value'] }}">{{ $row['value'] }}</span><span class="block text-xs font-normal text-slate-500">{{ $row['source'] }}</span></x-knowledge-admin.fact>
                            @endforeach
                        </x-knowledge-admin.facts>
                    </details>
                @endif

                @if (count($analysis['runs']) > 1)
                    <details class="rounded-xl border border-slate-200 bg-white p-3">
                        <summary class="cursor-pointer text-sm font-medium text-slate-800">Execuções anteriores ({{ count($analysis['runs']) - 1 }})</summary>
                        <div class="mt-3 grid max-h-96 gap-4 overflow-y-auto">
                            @foreach (array_slice($analysis['runs'], 1) as $run)
                                <div class="grid gap-3 border-l-2 border-slate-200 pl-3">
                                    <p class="text-sm font-medium text-slate-900">{{ $run['occurred_at'] ? \Illuminate\Support\Carbon::parse($run['occurred_at'])->format('d/m/Y H:i:s') : 'Data não registrada' }} · {{ $run['actor'] }}</p>
                                    @include('livewire.shared.analysis-run', ['extraction' => $run['extraction'], 'classification' => $run['classification']])
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>

            {{-- Aba: resolver --}}
            <div x-show="tab === 'resolver'" x-cloak role="tabpanel" class="grid gap-4">
                @if ($isPending)
                    <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Como finalizar">
                        @foreach ([['create', 'Criar novo documento', 'O medicamento ainda não existe na base.'], ['associate', 'Associar a existente', 'Já há um documento sem PDF para ele.'], ['ignore', 'Não incorporar', 'Não é bula ou não deve entrar na base.']] as [$optionKey, $optionTitle, $optionHelp])
                            <a href="#{{ $optionKey }}" role="radio" x-on:click.prevent="option = '{{ $optionKey }}'" x-bind:aria-checked="option === '{{ $optionKey }}'" x-bind:class="option === '{{ $optionKey }}' ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/20' : 'border-slate-200 bg-white hover:border-slate-400'" class="grid min-h-11 gap-0.5 rounded-xl border p-3 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                                <span class="text-sm font-semibold text-slate-900">{{ $optionTitle }}</span><span class="text-xs text-slate-600">{{ $optionHelp }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div x-show="option === 'create'" class="grid gap-4 rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-sm text-slate-600">Os campos vêm da análise e das suas decisões; ajuste se precisar.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-knowledge-admin.input label="Título" required wire:model="newDocumentTitle" wrapper-class="sm:col-span-2" />
                            <x-knowledge-admin.select label="Tipo" required wire:model="newDocumentType">@foreach (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
                            <x-knowledge-admin.input label="Identificador oficial" optional wire:model="newDocumentSourceId" hint="Número de registro ou código da origem." />
                            <x-knowledge-admin.input label="Chave externa" optional wire:model="newDocumentExternalKey" wrapper-class="sm:col-span-2" />
                        </div>
                        <div><x-knowledge-admin.button wire:click="createDocument" loading-target="createDocument" loading="Criando…" :disabled="$analysisActive">Criar com estes dados</x-knowledge-admin.button></div>
                    </div>

                    <div x-show="option === 'associate'" x-cloak class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4">
                        <x-knowledge-admin.search label="Buscar documento existente" wire:model.live.debounce.350ms="documentSearch" placeholder="Título, chave ou identificador" />
                        <div class="grid max-h-72 gap-2 overflow-y-auto" wire:loading.class="opacity-60" wire:target="documentSearch">
                            @foreach ($documents as $document)
                                <button type="button" wire:click="selectDocument({{ $document->id }})" class="rounded-lg border p-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $selectedDocumentId === $document->id ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/20' : 'border-slate-200 hover:border-slate-400' }}">
                                    <span class="block truncate text-sm font-semibold text-slate-900">{{ $document->title }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-slate-500">{{ $document->source?->name ?? 'Sem origem' }} · {{ $document->external_key ?: 'Sem chave externa' }} · <span class="{{ ($document->storage_key || $document->storage_path) ? 'text-rose-700' : 'text-emerald-700' }}">{{ ($document->storage_key || $document->storage_path) ? 'já possui PDF' : 'sem PDF' }}</span></span>
                                </button>
                            @endforeach
                            @if (mb_strlen(trim($documentSearch)) >= 2 && $documents->isEmpty())<x-knowledge-admin.empty-state>Nenhum documento encontrado.</x-knowledge-admin.empty-state>@endif
                        </div>
                        @error('selectedDocumentId')<p class="text-sm text-rose-700">Selecione um documento válido.</p>@enderror
                        @if ($selectedDocument)
                            <div><x-knowledge-admin.button wire:click="associate" loading-target="associate" loading="Associando…">Associar documento</x-knowledge-admin.button></div>
                        @endif
                    </div>

                    <div x-show="option === 'ignore'" x-cloak class="grid gap-4 rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-sm text-slate-600">O arquivo e o histórico são preservados; o item sai da fila de pendências.</p>
                        <x-knowledge-admin.select label="Motivo" required wire:model="ignoreReason">
                            <option value="">Selecione…</option><option value="not_leaflet">Documento não é bula</option><option value="wrong_source">Origem incorreta</option><option value="out_of_scope">Fora do escopo</option><option value="invalid_document">Documento inválido</option><option value="duplicate_unresolved">Duplicidade</option><option value="inappropriate_content">Conteúdo inadequado</option><option value="other">Outro</option>
                        </x-knowledge-admin.select>
                        <x-knowledge-admin.textarea label="Observação" optional wire:model="ignoreNotes" />
                        <div><x-knowledge-admin.button variant="danger" wire:click="ignore" loading-target="ignore" loading="Salvando…">Confirmar sem incorporar</x-knowledge-admin.button></div>
                    </div>
                @else
                    <x-knowledge-admin.alert tone="info">{{ $consequence }}</x-knowledge-admin.alert>
                @endif
            </div>

            {{-- Aba: arquivo e histórico --}}
            <div x-show="tab === 'arquivo'" x-cloak role="tabpanel" class="grid gap-4">
                <x-knowledge-admin.card>
                    <x-knowledge-admin.facts>
                        <x-knowledge-admin.fact label="Arquivo">{{ $item->original_filename }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Tamanho">{{ $item->file_size ? number_format($item->file_size / 1024, 1, ',', '.').' KB' : 'Não informado' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Identificador externo">{{ $item->external_key ?: 'Não informado' }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact label="Canal">{{ data_get($item->metadata, 'ingestion_channel') === 'knowledge_admin' ? 'Envio administrativo' : (data_get($item->metadata, 'ingestion_channel') ?? 'Não informado') }}</x-knowledge-admin.fact>
                        <x-knowledge-admin.fact class="sm:col-span-2" label="Assinatura do arquivo (SHA-256)"><span class="break-all font-mono text-xs">{{ $item->sha256 ?? 'Não informada' }}</span> @if ($item->sha256)<x-knowledge-admin.button variant="quiet" x-data x-on:click="navigator.clipboard.writeText(@js($item->sha256))">Copiar</x-knowledge-admin.button>@endif</x-knowledge-admin.fact>
                        @foreach ($metadata as $key => $value)
                            <x-knowledge-admin.fact :label="str($key)->headline()"><span class="line-clamp-2" title="{{ is_bool($value) ? '' : $value }}">{{ is_bool($value) ? ($value ? 'Sim' : 'Não') : $value }}</span></x-knowledge-admin.fact>
                        @endforeach
                    </x-knowledge-admin.facts>
                </x-knowledge-admin.card>

                <x-knowledge-admin.section title="Histórico" description="Do mais recente para o mais antigo.">
                    <div x-data="{ all: false }" class="grid gap-3">
                        <ol class="grid gap-2">
                            @forelse ($history as $event)
                                <li class="border-l-2 border-slate-200 pl-3" @if ($loop->index >= 6) x-show="all" x-cloak @endif>
                                    <p class="text-sm font-medium text-slate-900">{{ $historyLabels[$event['event'] ?? ''] ?? str($event['event'] ?? 'Evento')->headline() }}</p>
                                    <p class="text-xs text-slate-500">{{ isset($event['occurred_at']) ? \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('d/m/Y H:i') : '' }}@if ($event['actor_name'] ?? null) · {{ $event['actor_name'] }}@endif</p>
                                </li>
                            @empty
                                <li class="text-sm text-slate-500">Nenhum evento registrado para este item.</li>
                            @endforelse
                        </ol>
                        @if ($history->count() > 6)<div><x-knowledge-admin.button variant="quiet" x-on:click="all = ! all"><span x-text="all ? 'Mostrar menos' : 'Mostrar todos ({{ $history->count() }})'"></span></x-knowledge-admin.button></div>@endif
                    </div>
                </x-knowledge-admin.section>
            </div>
        </div>
    </div>
</x-knowledge-admin.shell>
