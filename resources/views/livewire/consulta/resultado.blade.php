@php
    use App\Services\Interactions\InteractionSeverity;
    use App\Services\Interactions\PairVerdict;
    $running = ! $consultation->isFinished();
    // Faixa colorida + UMA frase em linguagem comum por par (a gravidade vem do motor estruturado).
    $faixa = function ($result): array {
        $sev = $result->severity instanceof InteractionSeverity ? $result->severity : InteractionSeverity::tryFrom((string) $result->severity);
        $found = in_array($result->verdict, [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true);
        return match (true) {
            $found && $sev === InteractionSeverity::Major => ['bg-rose-600', 'border-rose-300', 'danger', 'Pode ter interação forte. Fale com seu médico antes de usar os dois.'],
            $found && $sev === InteractionSeverity::Moderate => ['bg-amber-500', 'border-amber-300', 'warning', 'Pode ter interação moderada. Fale com seu médico ou farmacêutico.'],
            $found && $sev === InteractionSeverity::Minor => ['bg-yellow-300', 'border-yellow-300', 'warning', 'Pode ter interação leve. Vale comentar com seu médico ou farmacêutico.'],
            $found => ['bg-slate-400', 'border-slate-300', 'neutral', 'Há interação registrada, mas a gravidade não foi classificada. Fale com seu médico.'],
            $result->verdict === PairVerdict::NotFoundInSources => ['bg-slate-300', 'border-slate-200', 'neutral', 'Nenhuma interação registrada. Isto não significa que a combinação seja segura.'],
            default => ['bg-slate-300', 'border-slate-200', 'neutral', 'Não foi possível concluir se há interação. Na dúvida, fale com seu médico.'],
        };
    };
@endphp
<x-consulta.shell title="Interações" :back="route('consulta.pesquisa')">
    <div class="-mt-1 flex flex-wrap gap-2 px-1" aria-label="Remédios analisados">
        @foreach ($consultation->medicines as $medicine)<x-consulta.selo tone="brand">{{ $medicine['name'] }}</x-consulta.selo>@endforeach
    </div>

    @foreach ($alertas as $alerta)
        <x-consulta.cartao tone="danger" role="alert" class="border-2">
            <div class="flex items-start gap-3">
                <x-consulta.icone name="alerta" tone="danger" />
                <div class="min-w-0"><p class="break-words text-xl font-semibold leading-snug">Atenção à sua alergia: {{ $alerta['medicine'] }}</p><p class="mt-1 break-words">Você registrou alergia a <strong>{{ $alerta['allergy'] }}</strong>; este remédio {{ $alerta['via'] === 'classe' ? 'pertence à mesma classe de' : 'contém' }} <strong>{{ $alerta['ingredient'] }}</strong>. Fale com seu médico ou farmacêutico antes de usar.</p></div>
            </div>
        </x-consulta.cartao>
    @endforeach

    {{-- Só este bloco se atualiza sozinho enquanto a análise roda. --}}
    @if ($running)
        <div wire:poll.2s role="status" aria-live="polite" class="grid gap-3 rounded-2xl border border-brand-600/30 bg-brand-50 px-5 py-4 text-brand-900">
            <p class="flex items-center gap-2 text-xl font-semibold"><svg class="size-6 shrink-0 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>{{ $consultation->status === 'PENDING' ? 'Aguardando o início da análise…' : 'Analisando…' }}</p>
            <progress class="h-3 w-full overflow-hidden rounded-full bg-white [&::-moz-progress-bar]:bg-accent-500 [&::-webkit-progress-bar]:bg-white [&::-webkit-progress-value]:bg-accent-500" value="{{ $pairs->count() }}" max="{{ max(1, $total) }}" aria-hidden="true"></progress>
            <p>{{ $pairs->count() }} de {{ $total }} combinações prontas.</p>
        </div>
    @elseif ($consultation->status === 'FAILED')
        <x-consulta.cartao tone="danger" role="alert" class="border-2">
            <p class="text-xl font-semibold leading-snug">{{ $consultation->error_code === 'CONSULTATION_TIMEOUT' ? 'A análise demorou mais que o esperado e foi interrompida.' : 'Não foi possível concluir a análise.' }}</p>
            <p>Nenhum resultado parcial deve ser lido como ausência de interação.</p>
            <x-consulta.botao size="md" wire:click="repetir" loadingTarget="repetir" loading="Reiniciando…">Tentar novamente</x-consulta.botao>
        </x-consulta.cartao>
    @endif

    @foreach ($pairs as $result)
        @php([$cor, $borda, $tom, $frase] = $faixa($result))
        <article wire:key="p-{{ $result->id }}" class="min-w-0 overflow-hidden rounded-2xl border bg-white shadow-sm {{ $borda }}" aria-labelledby="par-{{ $result->id }}">
            <div class="h-3 {{ $cor }}" aria-hidden="true"></div>
            <div class="grid min-w-0 gap-3 p-4 sm:p-5">
                <h2 id="par-{{ $result->id }}" class="break-words text-xl font-semibold leading-snug text-brand-900">{{ $result->label_a }} <span class="text-slate-400">+</span> {{ $result->label_b }}</h2>
                @if ($result->severity)<x-consulta.severity :severity="$result->severity" size="lg" />@else<x-consulta.selo :tone="$tom" class="justify-self-start">{{ $result->verdict->label() }}</x-consulta.selo>@endif
                <p class="break-words text-xl leading-relaxed text-slate-900">{{ $frase }}</p>

                @if ($result->explanation)
                    <x-consulta.secao titulo="O que a bula diz" icon="bula" tone="brand">
                        <p class="break-words leading-relaxed text-slate-900">{{ $result->explanation }}</p>
                        <p class="text-base text-slate-700">Baseado na bula oficial, conferido automaticamente.</p>
                        @if (! empty($result->evidences))
                            <ul class="grid gap-0.5 text-base text-slate-700" aria-label="Fontes consultadas">
                                @foreach ($result->evidences as $evidence)<li class="break-words">{{ $evidence['document_title'] }} · {{ $evidence['section'] ?? 'seção não identificada' }} · p. {{ $evidence['page_start'] ?? '?' }}</li>@endforeach
                            </ul>
                        @endif
                    </x-consulta.secao>
                @elseif (! empty($result->evidences))
                    <x-consulta.secao titulo="O que a bula diz" :resumo="count($result->evidences).' trechos'" icon="bula" tone="brand">
                        <ol class="grid gap-3">
                            @foreach ($result->evidences as $evidence)
                                <li class="grid min-w-0 gap-1">
                                    <p class="break-words text-base text-slate-700"><span class="font-semibold text-slate-900">{{ $evidence['document_title'] }}</span> · {{ $evidence['section'] ?? 'seção não identificada' }} · p. {{ $evidence['page_start'] ?? '?' }}</p>
                                    <blockquote class="max-h-72 overflow-y-auto whitespace-pre-line break-words rounded-lg bg-slate-50 px-3 py-2 text-base leading-relaxed text-slate-800 ring-1 ring-slate-200">{{ $evidence['excerpt'] }}</blockquote>
                                </li>
                            @endforeach
                        </ol>
                    </x-consulta.secao>
                @endif

                @if (! empty($result->limitations) || $result->structured)
                    <x-consulta.secao titulo="Entenda esta análise" icon="info" tone="neutral" class="text-base text-slate-700">
                        @foreach ($result->limitations ?? [] as $limitation)<p class="flex gap-2"><span aria-hidden="true">•</span><span class="min-w-0 break-words">{{ $limitation }}</span></p>@endforeach
                        @if ($result->structured)<p class="break-words">Base de interações: {{ collect($result->structured['provenance'] ?? [])->map(fn ($p) => $p['source'])->unique()->join('; ') }}</p>@endif
                    </x-consulta.secao>
                @endif
            </div>
        </article>
    @endforeach

    @if ($consultation->isFinished())
        <x-consulta.botao variant="secondary" size="md" wire:click="repetir" loadingTarget="repetir" loading="Repetindo…">Repetir esta consulta</x-consulta.botao>
    @endif
</x-consulta.shell>
