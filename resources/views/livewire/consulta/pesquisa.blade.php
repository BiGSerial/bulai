<x-consulta.shell title="Verificar interações" :back="route('consulta.perfil')">
    <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="busca-titulo">
        <label id="busca-titulo" for="busca" class="text-xl font-semibold text-brand-900">Quais remédios você vai usar juntos? <span class="block text-base font-normal text-slate-700">Adicione de 2 a {{ $maximo }}. Digite pelo menos 3 letras.</span></label>
        <input id="busca" class="ui-control min-h-14 text-xl" type="search" inputmode="search" autocomplete="off" placeholder="Nome do remédio" wire:model.live.debounce.300ms="busca" aria-controls="resultados">

        <ul class="grid gap-2" aria-live="polite" aria-label="Lista para análise">
            @forelse ($selecionados as $item)
                <li wire:key="s-{{ $item['id'] }}" class="grid gap-2 rounded-xl px-4 py-3 ring-1 ring-inset {{ ($item['alerts'] ?? []) !== [] ? 'bg-rose-50 ring-rose-300' : 'bg-brand-50 ring-brand-600/20' }}">
                    <div class="flex items-center justify-between gap-3">
                        <span class="min-w-0 text-lg font-semibold text-brand-900">{{ $item['name'] }}</span>
                        <button type="button" wire:click="remover({{ $item['id'] }})" wire:loading.attr="disabled" class="inline-flex min-h-12 shrink-0 items-center gap-1 rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-white/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" aria-label="Remover {{ $item['name'] }}"><svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>Tirar</button>
                    </div>
                    @foreach ($item['alerts'] ?? [] as $alerta)<p role="alert" class="font-semibold text-rose-900">⚠ {{ $alerta }}</p>@endforeach
                    <a href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="inline-flex min-h-12 items-center text-base font-semibold text-brand-800 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Ver bula humanizada de {{ $item['name'] }}">Ver a bula simples ›</a>
                </li>
            @empty
                <li class="text-base text-slate-700">Nenhum remédio adicionado ainda.</li>
                @if ($meusRemedios >= 2)
                    <li><a href="{{ route('consulta.pesquisa', ['usar' => 1]) }}" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl bg-brand-50 px-4 text-lg font-semibold text-brand-900 ring-1 ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Usar os meus {{ $meusRemedios }} remédios</a></li>
                @endif
            @endforelse
        </ul>
        <p class="text-base text-slate-600">{{ count($selecionados) }} de {{ $maximo }}</p>
        @if ($mensagem)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $mensagem }}</p>@endif

        <div id="resultados" aria-live="polite" class="grid gap-2">
            <div wire:loading wire:target="busca" class="text-base text-slate-600">Buscando…</div>
            @if ($limitado)
                <p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">Muitas buscas em pouco tempo. Aguarde um instante e continue digitando.</p>
            @elseif ($buscou)
                @forelse ($resultados as $item)
                    <button type="button" wire:key="r-{{ $item['id'] }}" wire:click="adicionar({{ $item['id'] }})" wire:loading.attr="disabled" class="flex min-h-16 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-4 py-3 text-left transition hover:border-brand-600 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        <span class="min-w-0">
                            <span class="block text-lg font-semibold text-slate-900">{{ $item['name'] }}</span>
                            <span class="block text-base text-slate-700">{{ \Illuminate\Support\Str::limit($item['ingredients'], 60) }}@if ($item['inactive']) · registro inativo @endif</span>
                            @unless ($item['identified'])<span class="mt-1 inline-block rounded-lg bg-amber-50 px-2 py-0.5 text-base font-medium text-amber-900">Substância não identificada: a análise será inconclusiva</span>@endunless
                        </span>
                        <span class="inline-flex min-h-12 shrink-0 items-center gap-1 rounded-xl bg-brand-700 px-4 text-base font-semibold text-white">+ Adicionar</span>
                    </button>
                    <a wire:key="rb-{{ $item['id'] }}" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="-mt-1 inline-flex min-h-12 items-center justify-end px-2 text-base font-semibold text-brand-800 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Ver bula humanizada de {{ $item['name'] }}">Ver a bula simples de {{ $item['name'] }} ›</a>
                @empty
                    <p class="rounded-xl bg-slate-50 px-4 py-3 text-slate-700">Nenhum medicamento encontrado para “{{ $busca }}”. Confira a grafia ou tente o nome da substância.</p>
                @endforelse
            @endif
        </div>
    </section>

    <x-slot:sticky>
        <x-knowledge-admin.button class="min-h-16 w-full text-xl" wire:click="verificar" :disabled="count($selecionados) < 2" loadingTarget="verificar" loading="Iniciando análise…">{{ count($selecionados) >= 2 ? 'Verificar interações ('.count($selecionados).')' : 'Adicione pelo menos dois remédios' }}</x-knowledge-admin.button>
    </x-slot:sticky>
</x-consulta.shell>
