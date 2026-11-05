@php
    use App\Livewire\Consulta\Bula;
    use App\Models\Consultation\MedicineLeafletSummary;
    $estilo = fn (string $key): array => match ($key) {
        'beneficios' => ['bg-emerald-500', 'text-emerald-900'],
        'riscos', 'interacoes' => ['bg-amber-500', 'text-amber-900'],
        'nao_use_se', 'procure_ajuda' => ['bg-rose-500', 'text-rose-900'],
        'efeitos_colaterais' => ['bg-orange-500', 'text-orange-900'],
        'como_usar' => ['bg-sky-500', 'text-sky-900'],
        default => ['bg-brand-600', 'text-brand-900'],
    };
@endphp
<x-consulta.shell :title="$medicine->product_name" :back="url()->previous() !== url()->current() && str_starts_with(url()->previous(), url('/')) ? url()->previous() : route('consulta.inicio')">
    <p class="-mt-1 px-1 text-base text-slate-700">Bula simples · {{ $medicine->active_ingredient ?: 'substância não informada' }}</p>

    <x-knowledge-admin.button variant="accent" :href="route('consulta.perguntar', ['medicamento' => $medicine->id])" class="min-h-14 w-full text-lg">Perguntar sobre este remédio</x-knowledge-admin.button>

    <x-consulta.risco :tratamentos="[['context' => $contexto, 'medicine' => $medicine->product_name]]" />

    @if ($estado === Bula::NOT_IDENTIFIED)
        <x-knowledge-admin.alert tone="warning">Não identificamos o princípio ativo (a substância) deste remédio no catálogo, então não conseguimos localizar a bula certa. Consulte a bula da embalagem ou um farmacêutico.</x-knowledge-admin.alert>
    @elseif ($estado === Bula::NO_LEAFLET)
        <x-knowledge-admin.alert tone="warning">Ainda não há bula deste remédio cadastrada no BULA-IA. Não montamos resumos “de memória”: consulte a bula na embalagem ou o farmacêutico.</x-knowledge-admin.alert>
    @elseif ($estado === Bula::RATE_LIMITED)
        <x-knowledge-admin.alert tone="warning">Você atingiu o limite de bulas geradas por hora. Tente novamente mais tarde; as bulas já preparadas continuam disponíveis.</x-knowledge-admin.alert>
    @elseif ($estado === Bula::LOADING)
        <div wire:init="gerar" role="status" aria-live="polite" class="grid gap-3">
            <p class="flex items-center gap-2 rounded-2xl border border-brand-600/30 bg-brand-50 px-5 py-4 text-xl font-semibold text-brand-900"><svg class="size-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>Preparando a bula humanizada…</p>
            @foreach ([3, 2, 3] as $linhas)
                <div class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-5" aria-hidden="true">
                    <div class="h-5 w-1/3 animate-pulse rounded bg-slate-200"></div>
                    @for ($i = 0; $i < $linhas; $i++)<div class="h-4 animate-pulse rounded bg-slate-100 {{ $i % 2 ? 'w-5/6' : 'w-full' }}"></div>@endfor
                </div>
            @endforeach
        </div>
    @elseif ($summary !== null)
        @if (in_array($summary->status, [MedicineLeafletSummary::STATUS_UNAVAILABLE, MedicineLeafletSummary::STATUS_INVALID], true))
            <x-knowledge-admin.alert tone="warning">
                <p class="font-semibold">Não foi possível montar a bula humanizada com segurança agora.</p>
                <p class="mt-1">{{ $summary->status === MedicineLeafletSummary::STATUS_UNAVAILABLE ? 'O serviço está indisponível ou a bula não trouxe trechos suficientes.' : 'O resumo não passou na conferência automática e foi descartado.' }} Você ainda pode <a class="font-semibold underline" href="{{ route('consulta.perguntar', ['medicamento' => $medicine->id]) }}" wire:navigate>fazer uma pergunta</a> ou consultar a bula da embalagem.</p>
            </x-knowledge-admin.alert>
        @else
            @foreach ($secoes['com'] as $secao)
                @php([$cor, $texto] = $estilo($secao['key']))
                <section wire:key="sec-{{ $secao['key'] }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="sec-{{ $secao['key'] }}">
                    <div class="h-2 {{ $cor }}" aria-hidden="true"></div>
                    <div class="grid gap-3 p-5">
                        <h2 id="sec-{{ $secao['key'] }}" class="text-2xl font-semibold leading-tight {{ $texto }}">{{ $secao['label'] }}</h2>
                        <ul class="grid gap-2 text-xl leading-relaxed text-slate-900">
                            @foreach (array_slice($secao['claims'], 0, 3) as $claim)<li class="flex gap-3"><span class="mt-3 size-2 shrink-0 rounded-full bg-current opacity-60" aria-hidden="true"></span><span>{{ $claim }}</span></li>@endforeach
                        </ul>
                        <details class="text-base text-slate-700">
                            <summary class="min-h-12 cursor-pointer select-none py-2 font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">De onde veio?</summary>
                            <p class="pt-1">{{ implode(' · ', $secao['sources']) }}</p>
                        </details>
                    </div>
                </section>
            @endforeach
            @if ($secoes['sem'] !== [])
                <p class="px-1 text-base text-slate-700">A bula cadastrada não trouxe trechos suficientes para: {{ implode(', ', $secoes['sem']) }}. Consulte a bula completa ou um farmacêutico.</p>
            @endif
            <p class="px-1 text-base text-slate-700">Baseado na bula oficial, conferido automaticamente. Preparado em {{ $summary->created_at->timezone(config('app.timezone'))->format('d/m/Y') }} a partir de {{ count($summary->pipeline['documents'] ?? []) }} bula(s) ({{ ($summary->pipeline['document_type'] ?? '') === 'bula_paciente' ? 'do paciente' : 'do profissional' }}).</p>
        @endif
    @endif

    <details class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <summary class="min-h-14 cursor-pointer select-none px-5 py-3 text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">No seu tratamento</summary>
        <div class="grid gap-3 border-t border-slate-100 p-5">
            <x-consulta.tratamento :contexto="$contexto" :medicamento="$medicine->product_name" />
            <x-knowledge-admin.button variant="secondary" :href="route('consulta.pesquisa')" class="min-h-14 w-full text-lg">Verificar interações com outros remédios</x-knowledge-admin.button>
        </div>
    </details>
</x-consulta.shell>
