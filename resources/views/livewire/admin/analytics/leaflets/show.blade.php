<x-knowledge-admin.shell :title="$ingredients !== [] ? implode(', ', $ingredients) : 'Princípios ativos '.$summary->ingredient_key" :back="route('admin.analytics.leaflets.index')" backLabel="Bulas humanizadas">
@php
    $statusLabels = ['generated' => 'Gerada', 'partial' => 'Parcial', 'invalid' => 'Rejeitada na validação', 'unavailable' => 'Indisponível'];
@endphp
    <x-knowledge-admin.page-header :badge="$statusLabels[$summary->status] ?? $summary->status" :badge-tone="$summary->status === 'generated' ? 'success' : 'warning'" :meta="['Bula humanizada '.substr($summary->uuid, 0, 8), 'gerada em '.$summary->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i'), $summary->views.' visualizações (reuso do cache)', $summary->duration_ms !== null ? number_format($summary->duration_ms, 0, ',', '.').' ms' : null]" />

    <x-knowledge-admin.card class="grid gap-4 !p-0">
        <x-knowledge-admin.tabs class="px-2" :tabs="[['key' => 'secoes', 'label' => 'Seções geradas', 'count' => count($summary->sections ?? [])], ['key' => 'geracao', 'label' => 'Geração, recuperação e cache'], ['key' => 'evidencias', 'label' => 'Evidências', 'count' => count($summary->evidences ?? [])]]" :active="$tab" model="tab" />

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
