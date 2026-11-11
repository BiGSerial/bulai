<x-consulta.shell title="Bula simples" :back="route('consulta.inicio')">
    <x-consulta.cartao aria-labelledby="busca-bula-titulo">
        <label id="busca-bula-titulo" for="busca-bula" class="text-xl font-semibold leading-snug text-brand-900">Qual remédio você quer entender? <span class="block text-base font-normal text-slate-700">A bula explicada em palavras simples: para que serve, como usar, cuidados e efeitos.</span></label>
        <div class="relative">
            <x-consulta.icone name="lupa" plain class="pointer-events-none absolute left-3 top-1/2 size-6 -translate-y-1/2 text-slate-400" />
            <input id="busca-bula" class="ui-control min-h-14 pl-11 text-xl" type="search" inputmode="search" autocomplete="off" placeholder="Nome do remédio" wire:model.live.debounce.300ms="busca" aria-controls="bulas-resultados">
        </div>

        <div id="bulas-resultados" aria-live="polite" class="grid gap-2">
            <div wire:loading wire:target="busca" class="text-base text-slate-600">Buscando…</div>
            @if ($limitado)
                <p role="alert" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900">Muitas buscas em pouco tempo. Aguarde um instante e continue digitando.</p>
            @elseif ($buscou)
                @forelse ($resultados as $item)
                    <a wire:key="b-{{ $item['id'] }}" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="flex min-h-16 min-w-0 items-center justify-between gap-3 rounded-2xl border border-slate-300 bg-white p-3 transition hover:border-brand-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40">
                        <span class="min-w-0">
                            <span class="block break-words text-lg font-semibold text-slate-900">{{ $item['name'] }}</span>
                            <span class="block break-words text-base text-slate-700">{{ \Illuminate\Support\Str::limit($item['ingredients'], 60) }}</span>
                            @if ($item['tem_bula'])
                                <span class="mt-1 inline-block rounded-lg bg-emerald-50 px-2 py-0.5 text-base font-medium text-emerald-900">Bula simples disponível</span>
                            @else
                                <span class="mt-1 inline-block rounded-lg bg-slate-100 px-2 py-0.5 text-base font-medium text-slate-700">Ainda sem bula no BULAI</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-2xl text-brand-800" aria-hidden="true">›</span>
                    </a>
                @empty
                    <p class="rounded-2xl bg-slate-50 px-4 py-3 text-slate-700">Nenhum remédio encontrado para “{{ $busca }}”. Confira a grafia ou tente o nome da substância.</p>
                @endforelse
            @endif
        </div>
    </x-consulta.cartao>

    @if (! $buscou && $disponiveis !== [])
        <section aria-labelledby="disponiveis-titulo" class="grid gap-3">
            <h2 id="disponiveis-titulo" class="px-1 text-xl font-semibold text-brand-900">Bulas simples prontas</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($disponiveis as $item)
                    <x-consulta.botao wire:key="d-{{ $item['id'] }}" variant="soft" size="md" :href="route('consulta.bula', $item['id'])" class="justify-between"><span class="inline-flex min-w-0 items-center gap-2"><x-consulta.icone name="bula" plain class="size-6 shrink-0" /><span class="min-w-0 break-words">{{ $item['name'] }}</span></span><span class="shrink-0" aria-hidden="true">›</span></x-consulta.botao>
                @endforeach
            </div>
        </section>
    @endif
</x-consulta.shell>
