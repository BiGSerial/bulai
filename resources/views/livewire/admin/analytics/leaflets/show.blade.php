<x-knowledge-admin.shell :title="$summary->subjectLabel()" :back="route('admin.analytics.leaflets.index')" backLabel="Bulas humanizadas">
@php
    $statusLabels = ['generated' => 'Gerada', 'partial' => 'Parcial', 'invalid' => 'Rejeitada na validação', 'unavailable' => 'Indisponível'];
@endphp
    <x-knowledge-admin.page-header :badge="$statusLabels[$summary->status] ?? $summary->status" :badge-tone="$summary->status === 'generated' ? 'success' : 'warning'" :meta="['Bula humanizada '.substr($summary->uuid, 0, 8), 'gerada em '.$summary->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i'), $summary->views.' visualizações (reuso do cache)', $summary->duration_ms !== null ? number_format($summary->duration_ms, 0, ',', '.').' ms' : null]">
        <x-slot:primary>
            <x-knowledge-admin.confirm-modal action="refazer" title="Refazer esta bula humanizada?" message="Gera de novo, na versão atual do prompt, com a medição de legibilidade e até duas reescritas (até 3 chamadas ao modelo). Roda em segundo plano; se já for a versão atual, o texto é substituído e o índice anterior fica registrado." confirm-label="Refazer">
                <x-knowledge-admin.button>Refazer</x-knowledge-admin.button>
            </x-knowledge-admin.confirm-modal>
        </x-slot:primary>
    </x-knowledge-admin.page-header>
    @if ($mensagem)<x-knowledge-admin.alert tone="success">{{ $mensagem }}</x-knowledge-admin.alert>@endif
    @if ($newer)<x-knowledge-admin.alert tone="warning">Esta é uma versão anterior, já substituída. <a class="font-medium underline" href="{{ route('admin.analytics.leaflets.show', $newer) }}" wire:navigate>Ver a versão atual</a> ({{ $newer->prompt_version }}, gerada em {{ $newer->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}).</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.card class="grid gap-4 !p-0">
        <x-knowledge-admin.tabs class="px-2" :tabs="[['key' => 'texto', 'label' => 'Texto e legibilidade'], ['key' => 'secoes', 'label' => 'Seções geradas', 'count' => count($summary->sections ?? [])], ['key' => 'geracao', 'label' => 'Geração, recuperação e cache'], ['key' => 'evidencias', 'label' => 'Evidências', 'count' => count($summary->evidences ?? [])]]" :active="$tab" model="tab" />

        @php
            $legibilidade = $summary->readability();
            $indice = fn (?array $r): string => ($r['index'] ?? null) === null ? '—' : number_format($r['index'], 1, ',', '').' · '.$r['band'];
            $tom = fn (?array $r): string => ($r['index'] ?? null) === null ? 'neutral' : ($r['index'] >= $legibilidade['target'] ? 'success' : ($r['index'] < 25 ? 'danger' : 'warning'));
            $tentativas = $summary->generation['readability']['attempts'] ?? [];
        @endphp
        <div id="painel-texto" role="tabpanel" class="grid gap-5 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'texto') hidden @endif>
            <x-knowledge-admin.facts class="sm:grid-cols-4">
                <x-knowledge-admin.fact label="Legibilidade da bula humanizada"><x-knowledge-admin.badge :tone="$tom($legibilidade['summary'])">{{ $indice($legibilidade['summary']) }}</x-knowledge-admin.badge></x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Alvo">{{ number_format($legibilidade['target'], 0, ',', '') }} ou mais ({{ $legibilidade['meets_target'] === null ? 'sem texto' : ($legibilidade['meets_target'] ? 'atingido' : 'não atingido') }})</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Trechos de origem (bula original)">{{ $indice($legibilidade['sources']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Palavras por frase">{{ ($legibilidade['summary']['sentences'] ?? 0) > 0 ? number_format($legibilidade['summary']['words'] / $legibilidade['summary']['sentences'], 1, ',', '') : '—' }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
            <p class="text-xs text-slate-500">Índice de Flesch adaptado ao português (Martins et al., 1996): 75 ou mais, muito fácil; 50 a 75, fácil; 25 a 50, difícil; abaixo de 25, muito difícil. Mede a facilidade de leitura, não a compreensão; nomes de remédios baixam o índice mesmo em frases claras.</p>
            @if (! empty($summary->generation['readability']['previous']))
                <p class="text-sm text-slate-700">Refeita pelo Admin. Índice das versões anteriores: {{ collect($summary->generation['readability']['previous'])->map(fn ($p) => ($p['index'] ?? null) === null ? '—' : number_format($p['index'], 1, ',', ''))->join(' → ') }} → {{ $indice($legibilidade['summary']) }}.</p>
            @endif
            @if (count($tentativas) > 1)
                <div class="grid gap-1">
                    <h3 class="text-sm font-semibold text-slate-800">Reescritas para linguagem mais simples</h3>
                    <ol class="grid gap-1 text-sm text-slate-700">
                        @foreach ($tentativas as $tentativa)
                            <li>Tentativa {{ $tentativa['attempt'] }}: @if (isset($tentativa['error_code'])) falha do provedor ({{ $tentativa['error_code'] }}) @else Flesch {{ $tentativa['flesch'] === null ? '—' : number_format($tentativa['flesch'], 1, ',', '') }}, {{ $tentativa['sections_with_claims'] }} seções, {{ $tentativa['claims_accepted'] }} frases aceitas @endif @if (($summary->generation['readability']['chosen_attempt'] ?? null) === $tentativa['attempt'])<strong>(versão usada)</strong>@endif</li>
                        @endforeach
                    </ol>
                </div>
            @endif
            <article class="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:p-5">
                @forelse ($summary->sections ?? [] as $key => $section)
                    @php $frases = $summary->claimTexts($section); $secaoIndice = $legibilidade['sections'][$key] ?? null; @endphp
                    <section class="grid gap-1" wire:key="t-{{ $key }}">
                        <h3 class="flex flex-wrap items-center gap-2 text-base font-semibold text-slate-900">{{ $sectionLabels[$key] ?? (is_array($section) ? ($section['label'] ?? $key) : $key) }}
                            @if ($secaoIndice)<x-knowledge-admin.badge :tone="$tom($secaoIndice)">Flesch {{ $indice($secaoIndice) }}</x-knowledge-admin.badge>@endif
                        </h3>
                        @if ($frases === [])
                            <p class="text-sm text-slate-500">Sem texto validado para esta seção.</p>
                        @else
                            <ul class="grid list-disc gap-1 pl-5 text-base leading-relaxed text-slate-800">
                                @foreach ($frases as $frase)<li>{{ $frase }}</li>@endforeach
                            </ul>
                        @endif
                    </section>
                @empty
                    <p class="text-sm text-slate-500">Nenhuma seção gerada.</p>
                @endforelse
            </article>
        </div>

        <div id="painel-secoes" role="tabpanel" class="grid gap-4 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'secoes') hidden @endif>
            <p class="text-xs text-slate-500">Cada afirmação cita os trechos da lista de evidências (aba ao lado).</p>
            @forelse ($summary->sections ?? [] as $key => $section)
                @php
                    $claims = is_array($section) && array_is_list($section) ? $section : ($section['claims'] ?? []);
                    $status = is_array($section) && ! array_is_list($section) ? ($section['status'] ?? null) : null;
                @endphp
                <div class="grid gap-1" wire:key="s-{{ $key }}">
                    <h3 class="text-sm font-semibold text-slate-800">{{ $sectionLabels[$key] ?? $key }}@if ($status) <span class="font-normal text-slate-500">({{ $status }})</span>@endif</h3>
                    @if ($claims === [])
                        <p class="text-sm text-slate-500">Sem afirmações validadas para esta seção.</p>
                    @else
                        <ol class="grid gap-1 text-sm text-slate-800">
                            @foreach ($claims as $claim)
                                <li>{{ is_array($claim) ? ($claim['text'] ?? '') : $claim }}@if (is_array($claim) && ! empty($claim['evidence_chunk_ids'])) <span class="text-xs text-slate-500">[trechos {{ implode(', ', $claim['evidence_chunk_ids']) }}]</span>@endif</li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Nenhuma seção gerada.</p>
            @endforelse
        </div>

        <div id="painel-geracao" role="tabpanel" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'geracao') hidden @endif>
            <x-knowledge-admin.facts class="sm:grid-cols-3">
                <x-knowledge-admin.fact label="Chave (ids dos princípios ativos)">{{ $summary->ingredient_key }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Versão do prompt">{{ $summary->prompt_version }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Impressão digital dos documentos">{{ $summary->documents_fingerprint }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
            <x-knowledge-admin.generation-facts :generation="$summary->generation" :retrieval="$summary->retrieval" />
            @if ($summary->pipeline)<p class="text-xs text-slate-500">Pipeline: {{ collect($summary->pipeline)->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'sim' : 'não') : (is_array($v) ? json_encode($v) : ($v ?? '—'))))->join(' · ') }}</p>@endif
        </div>

        <div id="painel-evidencias" role="tabpanel" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'evidencias') hidden @endif>
            <p class="text-xs text-slate-500">Trechos completos recuperados das bulas e quais afirmações cada um sustenta.</p>
            <x-knowledge-admin.evidences :evidences="$summary->evidences ?? []" />
        </div>
    </x-knowledge-admin.card>
</x-knowledge-admin.shell>
