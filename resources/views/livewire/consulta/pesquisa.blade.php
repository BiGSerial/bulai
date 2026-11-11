@php $sugestao = $resultados !== [] && ($resultados[0]['fuzzy'] ?? false); @endphp
<x-consulta.shell title="Posso tomar junto?" :back="route('consulta.perfil')">
    <x-consulta.cartao aria-labelledby="busca-titulo">
        <label id="busca-titulo" for="busca" class="text-xl font-semibold leading-snug text-brand-900">@if ($meus !== [])Qual remédio você quer tomar junto com os seus? <span class="block text-base font-normal text-slate-700">Os remédios que você usa já estão na lista. Digite o nome do outro.</span>@else Quais remédios você vai tomar juntos? <span class="block text-base font-normal text-slate-700">Digite o nome de cada um (de 2 a {{ $maximo }}).</span>@endif</label>
        <div class="relative">
            <x-consulta.icone name="lupa" plain class="pointer-events-none absolute left-3 top-1/2 size-6 -translate-y-1/2 text-slate-400" />
            <input id="busca" class="ui-control min-h-14 pl-11 text-xl" type="search" inputmode="search" autocomplete="off" placeholder="Nome do remédio" wire:model.live.debounce.300ms="busca" aria-controls="resultados">
        </div>

        {{-- Selecionados como chips removíveis. --}}
        <ul class="flex flex-wrap gap-2" aria-live="polite" aria-label="Lista para análise">
            @forelse ($selecionados as $item)
                <li wire:key="s-{{ $item['id'] }}" class="grid min-w-0 max-w-full gap-1 rounded-2xl px-3 py-2 ring-1 ring-inset {{ ($item['alerts'] ?? []) !== [] ? 'bg-rose-50 ring-rose-300' : 'bg-brand-50 ring-brand-600/20' }}">
                    <div class="flex min-w-0 items-center gap-2">
                        <a href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="min-w-0 break-words text-lg font-semibold text-brand-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Ver bula humanizada de {{ $item['name'] }}">{{ $item['name'] }}</a>
                        @if (in_array($item['id'], $meus, true))<span class="shrink-0 text-base text-slate-600">em uso</span>@endif
                        <button type="button" wire:click="remover({{ $item['id'] }})" wire:loading.attr="disabled" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-rose-800 hover:bg-white/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" aria-label="Remover {{ $item['name'] }}"><x-consulta.icone name="fechar" plain class="size-6" /></button>
                    </div>
                    @foreach ($item['alerts'] ?? [] as $alerta)<p role="alert" class="break-words font-semibold text-rose-900">⚠ {{ $alerta }}</p>@endforeach
                </li>
            @empty
                <li class="text-base text-slate-700">Nenhum remédio adicionado ainda.</li>
            @endforelse
        </ul>
        <p class="text-base text-slate-600">{{ count($selecionados) }} de {{ $maximo }}</p>
        @if ($mensagem)<p role="alert" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $mensagem }}</p>@endif

        <div id="resultados" aria-live="polite" class="grid gap-2">
            <div wire:loading wire:target="busca" class="text-base text-slate-600">Buscando…</div>
            @if ($limitado)
                <p role="alert" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900">Muitas buscas em pouco tempo. Aguarde um instante e continue digitando.</p>
            @elseif ($buscou)
                @if ($sugestao)<p class="rounded-xl bg-brand-50 px-3 py-2 text-base font-semibold text-brand-900">Não achamos “{{ $busca }}”. Você quis dizer…?</p>@endif
                @forelse ($resultados as $item)
                    <div wire:key="r-{{ $item['id'] }}" class="grid min-w-0 gap-1 rounded-2xl border border-slate-300 bg-white p-3 transition hover:border-brand-600">
                        <button type="button" wire:click="adicionar({{ $item['id'] }})" wire:loading.attr="disabled" class="flex min-h-14 w-full min-w-0 items-center justify-between gap-3 rounded-xl text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                            <span class="min-w-0">
                                <span class="block break-words text-lg font-semibold text-slate-900">{{ $item['name'] }}</span>
                                <span class="block break-words text-base text-slate-700">{{ \Illuminate\Support\Str::limit($item['ingredients'], 60) }}@if ($item['inactive']) · registro inativo @endif</span>
                                @unless ($item['identified'])<span class="mt-1 inline-block rounded-lg bg-amber-50 px-2 py-0.5 text-base font-medium text-amber-900">Substância não identificada: a análise será inconclusiva</span>@endunless
                            </span>
                            <span class="inline-flex min-h-12 shrink-0 items-center gap-1 rounded-xl bg-brand-700 px-4 text-base font-semibold text-white">+ Adicionar</span>
                        </button>
                        <a href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="inline-flex min-h-11 items-center justify-end px-1 text-base font-semibold text-brand-800 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Ver bula humanizada de {{ $item['name'] }}">Ver a bula simples de {{ $item['name'] }} ›</a>
                    </div>
                @empty
                    <p class="rounded-2xl bg-slate-50 px-4 py-3 text-slate-700">Nenhum medicamento encontrado para “{{ $busca }}”. Confira a grafia ou tente o nome da substância.</p>
                @endforelse
            @endif
        </div>
    </x-consulta.cartao>

    <x-slot:sticky>
        <x-consulta.botao wire:click="verificar" :disabled="count($selecionados) < 2" loadingTarget="verificar" loading="Iniciando análise…">{{ count($selecionados) >= 2 ? 'Verificar se combinam ('.count($selecionados).')' : ($meus !== [] ? 'Adicione o remédio que vai tomar junto' : 'Adicione pelo menos dois remédios') }}</x-consulta.botao>
    </x-slot:sticky>
</x-consulta.shell>
