@php
    use App\Livewire\Consulta\Bula;
    use App\Models\Consultation\MedicineLeafletSummary;
    // Cada seção tem cor, ícone e tom próprios; "Para que serve" fica em destaque na capa.
    $estilo = fn (string $key): array => match ($key) {
        'para_que_serve' => ['brand', 'info'],
        'beneficios' => ['success', 'check'],
        'riscos', 'interacoes' => ['warning', 'alerta'],
        'nao_use_se', 'procure_ajuda' => ['danger', 'alerta'],
        'efeitos_colaterais' => ['warning', 'coracao'],
        'como_usar' => ['brand', 'relogio'],
        default => ['brand', 'bula'],
    };
    $secoesCom = $secoes['com'] ?? [];
    $capa = collect($secoesCom)->firstWhere('key', 'para_que_serve');
    $restantes = array_values(array_filter($secoesCom, fn (array $s): bool => $s['key'] !== 'para_que_serve'));
    $falando = collect($secoesCom)->map(fn (array $s): string => $s['label'].'. '.implode(' ', array_slice($s['claims'], 0, 3)))->join(' ');
@endphp
<x-consulta.shell :title="$medicine->product_name" :back="url()->previous() !== url()->current() && str_starts_with(url()->previous(), url('/')) ? url()->previous() : route('consulta.inicio')">
    {{-- Capa: nome, substância e "Para que serve" em destaque. --}}
    <section class="grid gap-3 rounded-3xl bg-brand-700 p-5 text-white shadow-md" aria-labelledby="capa-titulo">
        <div class="flex items-start gap-3">
            <x-consulta.icone name="bula" tone="white" size="lg" />
            <div class="min-w-0">
                <p class="text-base font-semibold uppercase tracking-wide text-brand-100">Bula simples</p>
                <h2 id="capa-titulo" class="break-words text-2xl font-semibold leading-tight">{{ $medicine->product_name }}</h2>
                <p class="break-words text-lg text-brand-100">{{ $medicine->active_ingredient ?: 'substância não informada' }}</p>
            </div>
        </div>
        @if ($capa !== null)
            <div class="grid gap-2 rounded-2xl bg-white/10 p-4">
                <p class="text-base font-semibold uppercase tracking-wide text-brand-100">{{ $capa['label'] }}</p>
                <ul class="grid gap-2 text-xl leading-relaxed">
                    @foreach (array_slice($capa['claims'], 0, 3) as $claim)<li class="flex gap-3"><x-consulta.icone name="check" plain class="mt-1 size-6 shrink-0 text-accent-300" /><span class="min-w-0 break-words">{{ $claim }}</span></li>@endforeach
                </ul>
            </div>
        @endif
        @if ($secoesCom !== [] && $summary !== null)
            <div x-data="{ falando: false, pode: 'speechSynthesis' in window, texto: @js($falando),
                ouvir() { if (this.falando) { speechSynthesis.cancel(); this.falando = false; return } const u = new SpeechSynthesisUtterance(this.texto); u.lang = 'pt-BR'; u.rate = 0.95; u.onend = () => this.falando = false; u.onerror = () => this.falando = false; this.falando = true; speechSynthesis.speak(u) } }"
                 x-on:livewire:navigating.window="speechSynthesis.cancel()">
                <button type="button" x-show="pode" x-cloak x-on:click="ouvir()" x-bind:aria-pressed="falando" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-white/15 px-4 text-lg font-semibold text-white ring-1 ring-inset ring-white/40 hover:bg-white/25 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/60"><x-consulta.icone name="ouvir" plain class="size-7" /><span x-text="falando ? 'Parar de ouvir' : 'Ouvir a bula simples'">Ouvir a bula simples</span></button>
            </div>
        @endif
    </section>

    <x-consulta.risco :tratamentos="[['context' => $contexto, 'medicine' => $medicine->product_name]]" />

    @if ($estado === Bula::NOT_IDENTIFIED)
        <x-consulta.cartao tone="warning"><p>Não identificamos o princípio ativo (a substância) deste remédio no catálogo, então não conseguimos localizar a bula certa. Consulte a bula da embalagem ou um farmacêutico.</p></x-consulta.cartao>
    @elseif ($estado === Bula::NO_LEAFLET)
        <x-consulta.cartao tone="warning"><p>Ainda não há bula deste remédio cadastrada no BULA-IA. Não montamos resumos “de memória”: consulte a bula na embalagem ou o farmacêutico.</p></x-consulta.cartao>
    @elseif ($estado === Bula::RATE_LIMITED)
        <x-consulta.cartao tone="warning"><p>Você atingiu o limite de bulas geradas por hora. Tente novamente mais tarde; as bulas já preparadas continuam disponíveis.</p></x-consulta.cartao>
    @elseif ($estado === Bula::LOADING)
        <div wire:init="gerar" role="status" aria-live="polite" class="grid gap-3">
            <p class="flex items-center gap-2 rounded-2xl border border-brand-600/30 bg-brand-50 px-5 py-4 text-xl font-semibold text-brand-900"><svg class="size-6 shrink-0 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>Preparando a bula humanizada…</p>
            @foreach ([3, 2, 3] as $linhas)
                <div class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-5" aria-hidden="true">
                    <div class="h-5 w-1/3 animate-pulse rounded bg-slate-200"></div>
                    @for ($i = 0; $i < $linhas; $i++)<div class="h-4 animate-pulse rounded bg-slate-100 {{ $i % 2 ? 'w-5/6' : 'w-full' }}"></div>@endfor
                </div>
            @endforeach
        </div>
    @elseif ($summary !== null)
        @if (in_array($summary->status, [MedicineLeafletSummary::STATUS_UNAVAILABLE, MedicineLeafletSummary::STATUS_INVALID], true))
            <x-consulta.cartao tone="warning">
                <p class="font-semibold">Não foi possível montar a bula humanizada com segurança agora.</p>
                <p>{{ $summary->status === MedicineLeafletSummary::STATUS_UNAVAILABLE ? 'O serviço está indisponível ou a bula não trouxe trechos suficientes.' : 'O resumo não passou na conferência automática e foi descartado.' }} Você ainda pode <a class="font-semibold underline" href="{{ route('consulta.perguntar', ['medicamento' => $medicine->id]) }}" wire:navigate>fazer uma pergunta</a> ou consultar a bula da embalagem.</p>
            </x-consulta.cartao>
        @else
            @foreach ($restantes as $indice => $secao)
                @php([$tom, $icone] = $estilo($secao['key']))
                <x-consulta.secao wire:key="sec-{{ $secao['key'] }}" :titulo="$secao['label']" :icon="$icone" :tone="$tom" :open="$indice < 2" aria-labelledby="sec-{{ $secao['key'] }}">
                    <ul class="grid gap-2 text-xl leading-relaxed text-slate-900">
                        @foreach (array_slice($secao['claims'], 0, 3) as $claim)<li class="flex gap-3"><span class="mt-3 size-2 shrink-0 rounded-full bg-brand-600" aria-hidden="true"></span><span class="min-w-0 break-words">{{ $claim }}</span></li>@endforeach
                    </ul>
                    <details class="text-base text-slate-700">
                        <summary class="min-h-11 cursor-pointer select-none py-2 font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">De onde veio?</summary>
                        <p class="break-words pt-1">{{ implode(' · ', $secao['sources']) }}</p>
                    </details>
                </x-consulta.secao>
            @endforeach
            @if ($capa !== null)
                <details class="px-1 text-base text-slate-700"><summary class="min-h-11 cursor-pointer select-none py-2 font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">De onde veio “{{ $capa['label'] }}”?</summary><p class="break-words pt-1">{{ implode(' · ', $capa['sources']) }}</p></details>
            @endif
            @if ($secoes['sem'] !== [])
                <p class="px-1 text-base text-slate-700">A bula cadastrada não trouxe trechos suficientes para: {{ implode(', ', $secoes['sem']) }}. Consulte a bula completa ou um farmacêutico.</p>
            @endif
            <p class="px-1 text-base text-slate-700">Baseado na bula oficial, conferido automaticamente. Preparado em {{ $summary->created_at->timezone(config('app.timezone'))->format('d/m/Y') }} a partir de {{ count($summary->pipeline['documents'] ?? []) }} bula(s) ({{ ($summary->pipeline['document_type'] ?? '') === 'bula_paciente' ? 'do paciente' : 'do profissional' }}).</p>
        @endif
    @endif

    <x-consulta.secao titulo="No seu tratamento" icon="coracao" tone="brand">
        <x-consulta.tratamento :contexto="$contexto" :medicamento="$medicine->product_name" />
        <x-consulta.botao variant="secondary" size="md" :href="route('consulta.pesquisa')">Verificar interações com outros remédios</x-consulta.botao>
    </x-consulta.secao>

    <x-slot:sticky>
        <x-consulta.botao variant="accent" :href="route('consulta.perguntar', ['medicamento' => $medicine->id])"><x-consulta.icone name="pergunta" plain class="size-7" />Perguntar sobre este remédio</x-consulta.botao>
    </x-slot:sticky>
</x-consulta.shell>
