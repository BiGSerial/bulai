@php
    use App\Services\Interactions\InteractionSeverity;
    use App\Services\Interactions\PairVerdict;
    $running = ! $consultation->isFinished();
    // Faixa colorida + UMA frase em linguagem comum por par (a gravidade vem do motor estruturado).
    $faixa = function ($result): array {
        $sev = $result->severity instanceof InteractionSeverity ? $result->severity : InteractionSeverity::tryFrom((string) $result->severity);
        return match (true) {
            in_array($result->verdict, [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true) && $sev === InteractionSeverity::Major => ['bg-rose-600', 'border-rose-300', 'Pode ter interação forte. Fale com seu médico antes de usar os dois.'],
            in_array($result->verdict, [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true) && $sev === InteractionSeverity::Moderate => ['bg-amber-500', 'border-amber-300', 'Pode ter interação moderada. Fale com seu médico ou farmacêutico.'],
            in_array($result->verdict, [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true) && $sev === InteractionSeverity::Minor => ['bg-yellow-300', 'border-yellow-300', 'Pode ter interação leve. Vale comentar com seu médico ou farmacêutico.'],
            in_array($result->verdict, [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true) => ['bg-slate-400', 'border-slate-300', 'Há interação registrada, mas a gravidade não foi classificada. Fale com seu médico.'],
            $result->verdict === PairVerdict::NotFoundInSources => ['bg-slate-300', 'border-slate-200', 'Nenhuma interação registrada. Isto não significa que a combinação seja segura.'],
            default => ['bg-slate-300', 'border-slate-200', 'Não foi possível concluir se há interação. Na dúvida, fale com seu médico.'],
        };
    };
@endphp
<x-consulta.shell title="Interações" :back="route('consulta.pesquisa')">
    <p class="-mt-1 px-1 text-base text-slate-700">{{ collect($consultation->medicines)->pluck('name')->join(' + ') }}</p>

    @foreach ($alertas as $alerta)
        <div role="alert" class="rounded-2xl border-2 border-rose-400 bg-rose-50 px-5 py-4 text-rose-900"><p class="text-xl font-semibold leading-snug">⚠ Atenção à sua alergia: {{ $alerta['medicine'] }}</p><p class="mt-1">Você registrou alergia a <strong>{{ $alerta['allergy'] }}</strong>; este remédio {{ $alerta['via'] === 'classe' ? 'pertence à mesma classe de' : 'contém' }} <strong>{{ $alerta['ingredient'] }}</strong>. Fale com seu médico ou farmacêutico antes de usar.</p></div>
    @endforeach

    {{-- Só este bloco se atualiza sozinho enquanto a análise roda. --}}
    @if ($running)
        <div wire:poll.2s role="status" aria-live="polite" class="grid gap-1 rounded-2xl border border-brand-600/30 bg-brand-50 px-5 py-4 text-brand-900">
            <p class="flex items-center gap-2 text-xl font-semibold"><svg class="size-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>{{ $consultation->status === 'PENDING' ? 'Aguardando o início da análise…' : 'Analisando…' }}</p>
            <p>{{ $pairs->count() }} de {{ $total }} combinações prontas.</p>
        </div>
    @elseif ($consultation->status === 'FAILED')
        <div role="alert" class="grid gap-3 rounded-2xl border-2 border-rose-400 bg-rose-50 px-5 py-4 text-rose-900">
            <p class="text-xl font-semibold leading-snug">{{ $consultation->error_code === 'CONSULTATION_TIMEOUT' ? 'A análise demorou mais que o esperado e foi interrompida.' : 'Não foi possível concluir a análise.' }}</p>
            <p>Nenhum resultado parcial deve ser lido como ausência de interação.</p>
            <x-knowledge-admin.button class="min-h-14 w-full text-lg" wire:click="repetir" loadingTarget="repetir" loading="Reiniciando…">Tentar novamente</x-knowledge-admin.button>
        </div>
    @endif

    @foreach ($pairs as $result)
        @php([$cor, $borda, $frase] = $faixa($result))
        <article wire:key="p-{{ $result->id }}" class="overflow-hidden rounded-2xl border bg-white shadow-sm {{ $borda }}" aria-labelledby="par-{{ $result->id }}">
            <div class="h-3 {{ $cor }}" aria-hidden="true"></div>
            <div class="grid gap-3 p-5">
                <h2 id="par-{{ $result->id }}" class="text-xl font-semibold leading-snug text-brand-900">{{ $result->label_a }} <span class="text-slate-400">+</span> {{ $result->label_b }}</h2>
                @if ($result->severity)<x-consulta.severity :severity="$result->severity" class="text-base" />@else<p class="text-base font-semibold text-slate-700">{{ $result->verdict->label() }}</p>@endif
                <p class="text-xl leading-relaxed text-slate-900">{{ $frase }}</p>

                @if ($result->explanation)
                    <details class="rounded-xl bg-slate-50 ring-1 ring-slate-200">
                        <summary class="min-h-12 cursor-pointer select-none px-4 py-3 text-base font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">O que a bula diz</summary>
                        <div class="grid gap-2 border-t border-slate-200 px-4 py-3">
                            <p class="leading-relaxed text-slate-900">{{ $result->explanation }}</p>
                            <p class="text-base text-slate-700">Baseado na bula oficial, conferido automaticamente.</p>
                            @if (! empty($result->evidences))
                                <ul class="grid gap-0.5 text-base text-slate-700" aria-label="Fontes consultadas">
                                    @foreach ($result->evidences as $evidence)<li>{{ $evidence['document_title'] }} · {{ $evidence['section'] ?? 'seção não identificada' }} · p. {{ $evidence['page_start'] ?? '?' }}</li>@endforeach
                                </ul>
                            @endif
                        </div>
                    </details>
                @elseif (! empty($result->evidences))
                    <details class="rounded-xl bg-slate-50 ring-1 ring-slate-200">
                        <summary class="min-h-12 cursor-pointer select-none px-4 py-3 text-base font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">O que a bula diz ({{ count($result->evidences) }} trechos)</summary>
                        <ol class="grid gap-3 border-t border-slate-200 p-4">
                            @foreach ($result->evidences as $evidence)
                                <li class="grid gap-1">
                                    <p class="text-base text-slate-700"><span class="font-semibold text-slate-900">{{ $evidence['document_title'] }}</span> · {{ $evidence['section'] ?? 'seção não identificada' }} · p. {{ $evidence['page_start'] ?? '?' }}</p>
                                    <blockquote class="max-h-72 overflow-y-auto whitespace-pre-line rounded-lg bg-white px-3 py-2 text-base leading-relaxed text-slate-800 ring-1 ring-slate-200">{{ $evidence['excerpt'] }}</blockquote>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @endif

                @if (! empty($result->limitations) || $result->structured)
                    <details class="text-base text-slate-700">
                        <summary class="min-h-12 cursor-pointer select-none py-2 font-semibold text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Entenda esta análise</summary>
                        <div class="grid gap-1.5 pt-1">
                            @foreach ($result->limitations ?? [] as $limitation)<p class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $limitation }}</span></p>@endforeach
                            @if ($result->structured)<p>Base de interações: {{ collect($result->structured['provenance'] ?? [])->map(fn ($p) => $p['source'])->unique()->join('; ') }}</p>@endif
                        </div>
                    </details>
                @endif
            </div>
        </article>
    @endforeach

    @if ($consultation->isFinished())
        <x-knowledge-admin.button variant="secondary" class="min-h-14 w-full text-lg" wire:click="repetir" loadingTarget="repetir" loading="Repetindo…">Repetir esta consulta</x-knowledge-admin.button>
    @endif
</x-consulta.shell>
