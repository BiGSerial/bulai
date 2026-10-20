<x-knowledge-admin.shell :title="$item->original_filename" :subtitle="($item->ingestionBatch->source?->name ?? 'Sem origem').' · '.$item->created_at->format('d/m/Y H:i')" :back="route('admin.knowledge.batches.show', $batch)" back-label="Importação">
    <x-slot:actions>
        <x-knowledge-admin.status-badge :status="$item->status" />
        <x-knowledge-admin.button variant="secondary" target="_blank" :href="route('admin.knowledge.items.file', [$batch, $item])">Visualizar PDF</x-knowledge-admin.button>
        <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.items.download', [$batch, $item])" download>Baixar original</x-knowledge-admin.button>
    </x-slot:actions>

    @php
        $isPending = $item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING;
        $analysisActive = in_array($analysis['progress']['state'] ?? null, ['queued', 'processing'], true);
        $medicine = $analysis['medicine'];
        $divergences = collect($analysis['classification']['comparison'] ?? [])->where('relation', 'different')->count();
        $registrationRow = collect($medicine['rows'])->firstWhere('label', 'Registro ANVISA');
        $productRow = collect($medicine['rows'])->firstWhere('label', 'Medicamento');
        $canCreate = filled($newDocumentTitle) && filled($newDocumentType);
        $steps = [['Conferir os dados', true], ['Escolher o destino', ! $isPending ? true : false], ['Concluído', ! $isPending]];
    @endphp

    @if ($feedbackMessage)
        <x-knowledge-admin.alert :tone="match ($feedbackType) { 'success' => 'success', 'error' => 'danger', default => 'warning' }">{{ $feedbackMessage }}@if ($errorId)<span class="mt-1 block">Código de referência: {{ $errorId }}</span>@endif</x-knowledge-admin.alert>
    @endif

    <div x-data="{ tab: ['dados', 'analise', 'resolver', 'historico'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : '{{ $isPending ? 'dados' : 'resolver' }}', option: '{{ $canCreate ? 'create' : 'associate' }}' }" x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))" class="grid gap-5">

        {{-- Resumo e próximo passo --}}
        <x-knowledge-admin.card class="grid gap-5">
            <ol class="flex flex-wrap items-center gap-x-4 gap-y-2" aria-label="Etapas">
                @foreach ($steps as $index => [$stepLabel, $stepDone])
                    @php($stepCurrent = $isPending ? $index === 1 : $index === 2)
                    <li class="flex items-center gap-2 text-sm {{ $stepCurrent ? 'font-semibold text-brand-800' : ($stepDone ? 'text-slate-700' : 'text-slate-400') }}" @if ($stepCurrent) aria-current="step" @endif>
                        <span class="inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold {{ $stepCurrent ? 'bg-brand-700 text-white' : ($stepDone ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-600/20' : 'bg-slate-100 text-slate-500') }}">{{ $stepDone && ! $stepCurrent ? '✓' : $index + 1 }}</span>{{ $stepLabel }}
                    </li>
                @endforeach
            </ol>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="min-w-0"><p class="text-xs text-slate-500">Medicamento</p><p class="mt-0.5 truncate text-base font-semibold text-slate-900" title="{{ $productRow['value'] ?? '' }}">{{ $productRow['value'] ?? 'Não identificado' }}</p></div>
                <div class="min-w-0"><p class="text-xs text-slate-500">Princípio ativo</p>
                    @if ($medicine['ingredient_missing'])
                        <p class="mt-0.5 text-sm font-semibold text-rose-700">Não identificado</p>
                    @else
                        <div class="mt-1 flex flex-wrap gap-1">@foreach (array_slice($medicine['ingredients'], 0, 3) as $ingredient)<x-knowledge-admin.badge tone="success" class="max-w-full truncate">{{ $ingredient }}</x-knowledge-admin.badge>@endforeach @if (count($medicine['ingredients']) > 3)<x-knowledge-admin.badge>+{{ count($medicine['ingredients']) - 3 }}</x-knowledge-admin.badge>@endif</div>
                    @endif
                </div>
                <div class="min-w-0"><p class="text-xs text-slate-500">Registro ANVISA</p><p class="mt-0.5 text-base font-semibold text-slate-900">{{ $registrationRow['value'] ?? 'Não identificado' }}</p>
                    <x-knowledge-admin.badge class="mt-1" :tone="match ($medicine['validation']) { 'CONFIRMED' => 'success', 'CONFLICT' => 'danger', default => 'neutral' }">{{ match ($medicine['validation']) { 'CONFIRMED' => 'Confirmado no catálogo', 'CONFLICT' => 'Diverge do catálogo', 'NOT_FOUND' => 'Registro não encontrado', 'NOT_AVAILABLE' => 'Catálogo vazio', default => 'Sem validação' } }}</x-knowledge-admin.badge>
                </div>
            </div>

            @if ($isPending)
                <div class="grid gap-3 rounded-xl border border-brand-100 bg-brand-50 p-4">
                    <div>
                        <p class="text-sm font-semibold text-brand-800">Próximo passo</p>
                        <p class="mt-1 text-sm text-slate-700">
                            @if ($analysisActive)
                                A análise automática está em andamento. Aguarde alguns segundos: esta tela atualiza sozinha.
                            @elseif ($medicine['ingredient_missing'])
                                Falta o princípio ativo, indispensável para verificar interações. Peça à IA ou confira o registro antes de finalizar.
                            @elseif ($divergences > 0)
                                Há {{ $divergences }} {{ $divergences === 1 ? 'campo' : 'campos' }} em que a heurística, o catálogo e a IA divergem. Confira na aba Análise e depois finalize.
                            @else
                                Os dados parecem consistentes. Se estiverem corretos, finalize criando o documento ou associando a um já existente.
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($medicine['ingredient_missing'] && $aiConfigured)
                            <x-knowledge-admin.button wire:click="requestAiAnalysis" loading-target="requestAiAnalysis" loading="Enfileirando…" :disabled="$analysisActive">Consultar IA agora</x-knowledge-admin.button>
                        @endif
                        <x-knowledge-admin.button :variant="$medicine['ingredient_missing'] && $aiConfigured ? 'secondary' : 'primary'" wire:click="createDocument" loading-target="createDocument" loading="Finalizando…" :disabled="! $canCreate || $analysisActive">Criar documento com estes dados e finalizar</x-knowledge-admin.button>
                        <x-knowledge-admin.button variant="secondary" x-on:click="tab = 'resolver'; option = 'associate'; $nextTick(() => $el.closest('[x-data]').scrollIntoView({ behavior: 'smooth' }))">Associar a documento existente</x-knowledge-admin.button>
                        <x-knowledge-admin.button variant="quiet" x-on:click="tab = 'resolver'; option = 'ignore'">Não incorporar</x-knowledge-admin.button>
                    </div>
                    <p class="text-xs text-slate-500">Finalizar associa o PDF ao documento e o envia para processamento. O histórico e o arquivo original são sempre preservados.</p>
                </div>
            @else
                <div class="grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm font-semibold text-emerald-900">Pendência concluída: {{ match ($item->status) { 'ASSOCIATED' => 'o arquivo foi associado a um documento.', 'DUPLICATE' => 'o arquivo já existia e foi marcado como duplicado.', 'IGNORED' => 'o arquivo não será incorporado à base.', default => 'este item não está mais pendente.' } }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($item->knowledge_document_id)<x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.documents.show', $item->knowledge_document_id)">Abrir o documento</x-knowledge-admin.button>@endif
                        @if ($nextPending)
                            <x-knowledge-admin.button :href="route('admin.knowledge.pending.review', [$nextPending->ingestionBatch, $nextPending])">Próxima pendência ({{ $pendingRemaining }} {{ $pendingRemaining === 1 ? 'restante' : 'restantes' }})</x-knowledge-admin.button>
                        @endif
                        <x-knowledge-admin.button variant="quiet" :href="route('admin.knowledge.pending.index')">Voltar à fila</x-knowledge-admin.button>
                    </div>
                </div>
            @endif
        </x-knowledge-admin.card>

        {{-- Abas --}}
        <div class="sticky top-0 z-10 -mx-4 border-b border-slate-200 bg-slate-50/90 px-4 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <nav class="flex gap-1 overflow-x-auto" role="tablist" aria-label="Seções da pendência">
                @foreach ([['dados', 'Dados do medicamento', null], ['analise', 'Análise (OCR e IA)', $divergences > 0 ? $divergences.' '.($divergences === 1 ? 'divergência' : 'divergências') : null], ['resolver', $isPending ? 'Resolver pendência' : 'Resultado', null], ['historico', 'Arquivo e histórico', null]] as [$tabKey, $tabLabel, $tabBadge])
                    <a href="#{{ $tabKey }}" role="tab" x-on:click.prevent="tab = '{{ $tabKey }}'" x-bind:aria-selected="tab === '{{ $tabKey }}'" x-bind:class="tab === '{{ $tabKey }}' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900'" class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium">{{ $tabLabel }}@if ($tabBadge)<x-knowledge-admin.badge tone="warning">{{ $tabBadge }}</x-knowledge-admin.badge>@endif</a>
                @endforeach
            </nav>
        </div>

        {{-- Aba: dados --}}
        <div x-show="tab === 'dados'" role="tabpanel" class="grid gap-5">
            <x-knowledge-admin.section title="Dados do medicamento" description="Reunidos do PDF, do catálogo oficial da ANVISA e da IA, cada um com a sua origem.">
                @if ($medicine['ingredient_missing'])
                    <x-knowledge-admin.alert tone="danger">
                        <p class="font-medium">Princípio ativo não identificado</p>
                        <p class="mt-1">Ele é indispensável para verificar interações medicamentosas. Use “Consultar IA agora” ou informe o registro correto para buscá-lo no catálogo ANVISA.</p>
                    </x-knowledge-admin.alert>
                @else
                    <div class="flex flex-wrap items-center gap-2" aria-label="Princípios ativos">
                        <span class="text-sm text-slate-500">Princípios ativos:</span>
                        @foreach ($medicine['ingredients'] as $ingredient)<x-knowledge-admin.badge tone="success">{{ $ingredient }}</x-knowledge-admin.badge>@endforeach
                        <x-knowledge-admin.badge>Origem: {{ $medicine['ingredient_source'] }}</x-knowledge-admin.badge>
                    </div>
                @endif
                <x-knowledge-admin.facts class="lg:grid-cols-3">
                    @foreach ($medicine['rows'] as $medicineRow)
                        <x-knowledge-admin.fact :label="$medicineRow['label']">
                            @if ($medicineRow['value'])<span class="line-clamp-2" title="{{ $medicineRow['value'] }}">{{ $medicineRow['value'] }}</span> <span class="block text-xs font-normal text-slate-500">{{ $medicineRow['source'] }}</span>@else<span class="font-normal text-slate-400">Não encontrado</span>@endif
                        </x-knowledge-admin.fact>
                    @endforeach
                </x-knowledge-admin.facts>
            </x-knowledge-admin.section>

            <x-knowledge-admin.section title="Por que está pendente">
                <p class="text-sm text-slate-800">{{ match ($item->reason) { 'no_match_found' => 'Nenhum documento correspondente foi encontrado.', 'ambiguous_source_document_id', 'ambiguous_normalized_filename' => 'Mais de um documento é possível. Confirme qual é o correto.', default => $item->reason ?: 'Associação manual necessária.' } }}</p>
                @if ($identification)
                    <div class="grid gap-3 border-t border-slate-100 pt-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div><h3 class="text-sm font-semibold text-slate-900">Dados identificados no PDF</h3><p class="text-sm text-slate-500">Confira antes de usar: são sugestões, ainda não confirmadas.</p></div>
                            @if ($identificationSummary)<x-knowledge-admin.badge>Confiança {{ $identificationSummary['score'] }}/100</x-knowledge-admin.badge>@endif
                        </div>
                        <x-knowledge-admin.facts>
                            @foreach (['product_name' => 'Medicamento', 'active_ingredient' => 'Princípio ativo', 'registration_number' => 'Registro ANVISA', 'document_type' => 'Tipo', 'holder' => 'Titular'] as $field => $label)
                                @if (isset($identification[$field]))<x-knowledge-admin.fact :label="$label"><span class="line-clamp-2" title="{{ $identification[$field] }}">{{ $identification[$field] }}</span></x-knowledge-admin.fact>@endif
                            @endforeach
                        </x-knowledge-admin.facts>
                        @if ($identificationSummary && $identificationSummary['ambiguous'])<x-knowledge-admin.alert tone="warning">Campos ambíguos: {{ implode(', ', $identificationSummary['ambiguous']) }}.</x-knowledge-admin.alert>@endif
                    </div>
                @endif
                @if ($regulatoryValidation)
                    @php($regulatoryStatus = $regulatoryValidation['status'] ?? 'INCONCLUSIVE')
                    <div class="grid gap-4 border-t border-slate-100 pt-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div><h3 class="text-sm font-semibold text-slate-900">Validação no catálogo ANVISA</h3><p class="text-sm text-slate-500">Comparação independente com os Dados Abertos de Medicamentos (feita por regras do sistema, sem IA, pelo número de registro).</p></div>
                            <x-knowledge-admin.badge :tone="match ($regulatoryStatus) { 'CONFIRMED' => 'success', 'CONFLICT' => 'danger', default => 'neutral' }">{{ match ($regulatoryStatus) { 'CONFIRMED' => 'Confirmada', 'CONFLICT' => 'Divergente', 'NOT_FOUND' => 'Registro não encontrado', 'NOT_AVAILABLE' => 'Catálogo indisponível', default => 'Inconclusiva' } }}</x-knowledge-admin.badge>
                        </div>
                        @if (($regulatoryValidation['reason'] ?? null) === 'registration_not_identified')<p class="text-sm text-slate-600">Inconclusiva porque o PDF não trouxe um número de registro identificável para consultar.</p>@endif
                        @if ($regulatoryStatus === 'NOT_AVAILABLE')<x-knowledge-admin.alert tone="warning">O catálogo da ANVISA ainda não foi importado. <a class="font-medium underline" href="{{ route('admin.knowledge.catalog.index') }}" wire:navigate>Importar agora</a>.</x-knowledge-admin.alert>@endif
                        @if (is_array($regulatoryValidation['catalog'] ?? null))
                            <div><h4 class="mb-2 text-sm font-semibold text-slate-800">O que o catálogo informou</h4>
                                <x-knowledge-admin.facts>
                                    @foreach (['registration_number' => 'Registro', 'product_name' => 'Produto', 'active_ingredient' => 'Princípio ativo', 'registration_holder' => 'Titular', 'registration_status' => 'Situação regulatória'] as $field => $label)
                                        @if (filled($regulatoryValidation['catalog'][$field] ?? null))<x-knowledge-admin.fact :label="$label">{{ $regulatoryValidation['catalog'][$field] }}</x-knowledge-admin.fact>@endif
                                    @endforeach
                                </x-knowledge-admin.facts>
                            </div>
                        @endif
                        @if (($regulatoryValidation['conflicts'] ?? []) !== [])
                            <x-knowledge-admin.alert tone="danger">Conflitos: {{ collect($regulatoryValidation['conflicts'])->map(fn ($conflict) => match ($conflict) { 'registration_product_mismatch' => 'o produto do PDF não corresponde ao produto do catálogo', 'active_ingredient_mismatch' => 'o princípio ativo diverge do catálogo', default => str($conflict)->headline() })->implode('; ') }}.</x-knowledge-admin.alert>
                        @endif
                        @if (is_array($regulatoryValidation['provenance'] ?? null))<p class="text-xs text-slate-500">Origem: {{ $regulatoryValidation['provenance']['dataset'] ?? 'ANVISA' }} · Importação #{{ $regulatoryValidation['provenance']['catalog_import_id'] ?? '—' }}</p>@endif
                    </div>
                @endif
            </x-knowledge-admin.section>
            <div class="flex justify-end"><x-knowledge-admin.button variant="secondary" x-on:click="tab = 'analise'; window.scrollTo({ top: 0, behavior: 'smooth' })">Ver análise do OCR e da IA →</x-knowledge-admin.button></div>
        </div>

        {{-- Aba: análise --}}
        <div x-show="tab === 'analise'" x-cloak role="tabpanel" class="grid gap-5" @if ($analysisActive) wire:poll.3s="refreshAnalysis" @endif>
            <x-knowledge-admin.section title="Análise automática" description="O que a extração de texto, o OCR e a IA local fizeram neste arquivo, lado a lado, e a sua decisão sobre a IA.">
                @if ($isPending)
                    <x-slot:actions>
                        <x-knowledge-admin.button variant="secondary" wire:click="requestAiAnalysis" loading-target="requestAiAnalysis" loading="Enfileirando…" :disabled="! $aiConfigured || $analysisActive">Consultar IA agora</x-knowledge-admin.button>
                    </x-slot:actions>
                @endif
                @if ($analysisActive)
                    <x-knowledge-admin.alert tone="info">Em andamento: {{ $analysis['progress']['label'] }} ({{ $analysis['progress']['percent'] }}%). Esta tela atualiza sozinha.</x-knowledge-admin.alert>
                @elseif (($analysis['progress']['state'] ?? null) === 'failed')
                    <x-knowledge-admin.alert tone="danger">A última análise falhou: {{ $analysis['progress']['label'] }}. Use Reanalisar para tentar de novo.</x-knowledge-admin.alert>
                @endif

                @if (! $analysis['analyzed'])
                    <x-knowledge-admin.alert tone="warning">Este arquivo só passou pela leitura rápida do envio: o OCR e a IA ainda não rodaram. Use Reanalisar para executar a análise completa.</x-knowledge-admin.alert>
                @else
                    @include('livewire.shared.analysis-run', ['extraction' => $analysis['extraction'], 'classification' => $analysis['classification'], 'interactive' => $isPending])

                    @if ($isPending && ($analysis['classification']['comparison'] ?? []) !== [])
                        @if ($analysis['review'])
                            <x-knowledge-admin.alert :tone="$analysis['review']['accepted'] ? 'success' : 'warning'">
                                <p class="font-medium">{{ $analysis['review']['accepted'] ? 'Você aceitou valores da IA: '.implode(', ', $analysis['review']['fields']).'.' : 'Você rejeitou a análise da IA.' }}</p>
                                <p class="mt-1">Por {{ $analysis['review']['actor'] }}{{ $analysis['review']['decided_at'] ? ' em '.\Illuminate\Support\Carbon::parse($analysis['review']['decided_at'])->format('d/m/Y H:i') : '' }}. @if ($analysis['review']['accepted'])Os valores ainda não foram confirmados no catálogo ANVISA.@endif</p>
                                <x-knowledge-admin.button class="mt-3" variant="secondary" wire:click="revertAiDecision" loading-target="revertAiDecision" loading="Desfazendo…">Desfazer decisão</x-knowledge-admin.button>
                            </x-knowledge-admin.alert>
                        @elseif (collect($analysis['classification']['comparison'])->contains(fn ($row) => $row['ai_selectable'] || $row['catalog_selectable']))
                            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <p class="mr-auto text-sm text-slate-600">Marque as caixas dos valores que você quer usar e aplique.</p>
                                <x-knowledge-admin.button wire:click="applyAiSuggestion" loading-target="applyAiSuggestion" loading="Aplicando…" :disabled="$selectedAiFields === []">Aplicar campos marcados ({{ count($selectedAiFields) }})</x-knowledge-admin.button>
                                <x-knowledge-admin.button variant="secondary" wire:click="applyAiSuggestion(true)" loading-target="applyAiSuggestion" loading="Aplicando…">Aceitar tudo da IA</x-knowledge-admin.button>
                                <x-knowledge-admin.button variant="quiet" wire:click="rejectAiSuggestion" loading-target="rejectAiSuggestion" loading="Salvando…">Rejeitar análise da IA</x-knowledge-admin.button>
                            </div>
                        @endif
                    @endif
                @endif

                @if (count($analysis['runs']) > 1)
                    <details class="rounded-lg border border-slate-200 p-3">
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
            </x-knowledge-admin.section>
            <div class="flex justify-end"><x-knowledge-admin.button x-on:click="tab = 'resolver'; window.scrollTo({ top: 0, behavior: 'smooth' })">Ir para resolver a pendência →</x-knowledge-admin.button></div>
        </div>

        {{-- Aba: resolver --}}
        <div x-show="tab === 'resolver'" x-cloak role="tabpanel" class="grid gap-5">
            @if ($isPending)
                <x-knowledge-admin.section title="Resolver pendência" description="Escolha como finalizar. Só uma das opções é necessária.">
                    <x-slot:actions>
                        <x-knowledge-admin.button variant="secondary" wire:click="reanalyze" loading-target="reanalyze" loading="Reanalisando…">Reanalisar</x-knowledge-admin.button>
                    </x-slot:actions>

                    <div class="grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Como finalizar">
                        @foreach ([['create', 'Criar novo documento', 'O arquivo ainda não existe na base. Usa os dados identificados.'], ['associate', 'Associar a existente', 'Já existe um documento para este medicamento, sem PDF.'], ['ignore', 'Não incorporar', 'O arquivo não deve entrar na base (ex.: não é bula).']] as [$optionKey, $optionTitle, $optionHelp])
                            <a href="#{{ $optionKey }}" role="radio" x-on:click.prevent="option = '{{ $optionKey }}'" x-bind:aria-checked="option === '{{ $optionKey }}'" x-bind:class="option === '{{ $optionKey }}' ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/20' : 'border-slate-200 bg-white hover:border-slate-400'" class="grid gap-1 rounded-xl border p-4 transition">
                                <span class="text-sm font-semibold text-slate-900">{{ $optionTitle }}</span><span class="text-sm text-slate-600">{{ $optionHelp }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div x-show="option === 'create'" class="grid gap-4 rounded-xl border border-slate-200 p-4">
                        <p class="text-sm text-slate-700">A origem e o arquivo serão preservados. Revise os dados antes de confirmar; eles vêm da análise e das suas decisões na aba Análise.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-knowledge-admin.input label="Título" required wire:model="newDocumentTitle" wrapper-class="sm:col-span-2" />
                            <x-knowledge-admin.select label="Tipo" required wire:model="newDocumentType">@foreach (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
                            <x-knowledge-admin.input label="Identificador oficial" optional wire:model="newDocumentSourceId" />
                            <x-knowledge-admin.input label="Chave externa" optional wire:model="newDocumentExternalKey" wrapper-class="sm:col-span-2" />
                        </div>
                        <div><x-knowledge-admin.button wire:click="createDocument" loading-target="createDocument" loading="Criando e associando…">Criar e associar documento</x-knowledge-admin.button></div>
                    </div>

                    <div x-show="option === 'associate'" x-cloak class="grid gap-3 rounded-xl border border-slate-200 p-4">
                        <x-knowledge-admin.search label="Buscar documento existente" wire:model.live.debounce.350ms="documentSearch" placeholder="Título, chave ou identificador" />
                        <div class="grid max-h-72 gap-2 overflow-y-auto" wire:loading.class="opacity-60" wire:target="documentSearch">
                            @foreach ($documents as $document)
                                <button type="button" wire:click="selectDocument({{ $document->id }})" class="rounded-lg border p-3 text-left transition {{ $selectedDocumentId === $document->id ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/20' : 'border-slate-200 hover:border-slate-400' }}">
                                    <span class="block truncate text-sm font-semibold text-slate-900">{{ $document->title }}</span>
                                    <span class="mt-0.5 block truncate text-sm text-slate-500">{{ $document->source?->name ?? 'Sem origem' }} · {{ $document->external_key ?: 'Sem chave externa' }}</span>
                                    <span class="mt-1 block text-sm {{ ($document->storage_key || $document->storage_path) ? 'text-rose-700' : 'text-emerald-700' }}">{{ ($document->storage_key || $document->storage_path) ? 'Já possui PDF' : 'Sem PDF associado' }}</span>
                                </button>
                            @endforeach
                            @if (mb_strlen(trim($documentSearch)) >= 2 && $documents->isEmpty())<x-knowledge-admin.empty-state>Nenhum documento encontrado.</x-knowledge-admin.empty-state>@endif
                        </div>
                        @error('selectedDocumentId')<p class="text-sm text-rose-700">Selecione um documento válido.</p>@enderror
                        @if ($selectedDocument)
                            <x-knowledge-admin.alert tone="info">
                                <p class="font-medium">Confirme a associação</p>
                                <p class="mt-1">Arquivo: <strong>{{ $item->original_filename }}</strong></p>
                                <p>Documento selecionado: <strong>{{ $selectedDocument->title }}</strong></p>
                                <x-knowledge-admin.button class="mt-3" wire:click="associate" loading-target="associate" loading="Associando…">Associar documento</x-knowledge-admin.button>
                            </x-knowledge-admin.alert>
                        @endif
                    </div>

                    <div x-show="option === 'ignore'" x-cloak class="grid gap-4 rounded-xl border border-slate-200 p-4">
                        <p class="text-sm text-slate-700">Use quando o arquivo não deve entrar na base. O arquivo e o histórico são preservados.</p>
                        <x-knowledge-admin.select label="Motivo" required wire:model="ignoreReason">
                            <option value="">Selecione…</option><option value="not_leaflet">Documento não é bula</option><option value="wrong_source">Origem incorreta</option><option value="out_of_scope">Fora do escopo</option><option value="invalid_document">Documento inválido</option><option value="duplicate_unresolved">Duplicidade</option><option value="inappropriate_content">Conteúdo inadequado</option><option value="other">Outro</option>
                        </x-knowledge-admin.select>
                        <x-knowledge-admin.textarea label="Observação" optional wire:model="ignoreNotes" />
                        <div><x-knowledge-admin.button variant="danger" wire:click="ignore" loading-target="ignore" loading="Salvando…">Confirmar sem incorporar</x-knowledge-admin.button></div>
                    </div>
                </x-knowledge-admin.section>
            @else
                <x-knowledge-admin.alert>Este item não está mais pendente e não pode receber uma nova associação.</x-knowledge-admin.alert>
            @endif
        </div>

        {{-- Aba: arquivo e histórico --}}
        <div x-show="tab === 'historico'" x-cloak role="tabpanel" class="grid gap-5">
            <x-knowledge-admin.section title="Detalhes do arquivo">
                <x-knowledge-admin.facts>
                    <x-knowledge-admin.fact label="Identificador externo">{{ $item->external_key ?: 'Não informado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Recebido em">{{ $item->created_at->format('d/m/Y H:i') }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Canal">{{ data_get($item->metadata, 'ingestion_channel') === 'knowledge_admin' ? 'Envio administrativo' : (data_get($item->metadata, 'ingestion_channel') ?? 'Não informado') }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Tamanho">{{ $item->file_size ? number_format($item->file_size / 1024, 1, ',', '.').' KB' : 'Não informado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact class="sm:col-span-2" label="Assinatura do arquivo (SHA-256)"><span class="break-all font-mono text-xs">{{ $item->sha256 ?? 'Não informada' }}</span> @if ($item->sha256)<x-knowledge-admin.button variant="quiet" x-data x-on:click="navigator.clipboard.writeText(@js($item->sha256))">Copiar</x-knowledge-admin.button>@endif</x-knowledge-admin.fact>
                </x-knowledge-admin.facts>
                @if ($metadata)
                    <div class="border-t border-slate-100 pt-4"><h3 class="mb-3 text-sm font-semibold text-slate-900">Metadados importados</h3>
                        <x-knowledge-admin.facts>@foreach ($metadata as $key => $value)<x-knowledge-admin.fact :label="str($key)->headline()"><span class="line-clamp-2" title="{{ is_bool($value) ? '' : $value }}">{{ is_bool($value) ? ($value ? 'Sim' : 'Não') : $value }}</span></x-knowledge-admin.fact>@endforeach</x-knowledge-admin.facts>
                    </div>
                @endif
            </x-knowledge-admin.section>

            <x-knowledge-admin.section title="Histórico" description="Tudo o que aconteceu com este item, do mais recente para o mais antigo.">
                <div x-data="{ all: false }" class="grid gap-3">
                    <div class="grid max-h-96 gap-3 overflow-y-auto pr-1">
                        @forelse ($history as $event)
                            <div class="border-l-2 border-slate-200 pl-3" @if ($loop->index >= 6) x-show="all" x-cloak @endif>
                                <p class="text-sm font-medium text-slate-900">{{ match ($event['event'] ?? '') { 'reanalysis_requested' => 'Reanálise solicitada', 'preliminary_identification_completed' => 'Identificação preliminar concluída', 'reanalysis_remained_pending' => 'Item permaneceu pendente', 'document_associated' => 'Documento associado', 'duplicate_confirmed' => 'Duplicidade confirmada', 'integrity_check_failed' => 'Falha de integridade detectada', 'automatic_analysis' => 'Análise automática executada (OCR/IA)', 'reanalysis_queued' => 'Reanálise enfileirada', 'ai_suggestion_accepted' => 'Valores da IA/catálogo aceitos', 'ai_suggestion_rejected' => 'Análise da IA rejeitada', 'ai_decision_reverted' => 'Decisão sobre a IA desfeita', 'not_incorporated' => 'Resolvido sem incorporação', default => str($event['event'] ?? 'Evento')->headline() } }}</p>
                                <p class="text-sm text-slate-500">{{ isset($event['occurred_at']) ? \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('d/m/Y H:i') : '' }}@if ($event['actor_name'] ?? null) · {{ $event['actor_name'] }}@endif</p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Nenhum evento registrado para este item.</p>
                        @endforelse
                    </div>
                    @if ($history->count() > 6)<div><x-knowledge-admin.button variant="quiet" x-on:click="all = ! all"><span x-text="all ? 'Mostrar menos' : 'Mostrar todos ({{ $history->count() }})'"></span></x-knowledge-admin.button></div>@endif
                </div>
            </x-knowledge-admin.section>
        </div>
    </div>
</x-knowledge-admin.shell>
