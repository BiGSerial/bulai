@php
    $normal = fn (string $texto): string => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($texto));
    $letras = collect($disponiveis)->groupBy(fn (array $item): string => mb_strtoupper(mb_substr(\Illuminate\Support\Str::ascii($item['name']), 0, 1)));
@endphp
<x-consulta.shell title="Bula simples" :back="route('consulta.inicio')">
    {{--
        Reativa: a lista de bulas prontas filtra na hora, no aparelho, a cada letra digitada (sem esperar o servidor). Com 3 letras
        ou mais, a busca no catálogo inteiro roda em segundo plano e aparece logo abaixo.
    --}}
    <div class="grid gap-4" x-data="{
            q: '',
            normal: (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(),
            mostra(nome) { return this.normal(this.q) === '' || nome.includes(this.normal(this.q)) },
            get visiveis() { return this.$refs.lista ? [...this.$refs.lista.querySelectorAll('[data-nome]')].filter((el) => this.mostra(el.dataset.nome)).length : 0 },
        }">
        <x-consulta.cartao aria-labelledby="busca-bula-titulo" class="sticky top-[calc(4.6rem+env(safe-area-inset-top,0px))] z-10 !gap-2 !p-3 sm:!p-4">
            <label id="busca-bula-titulo" for="busca-bula" class="text-lg font-semibold leading-snug text-brand-900">Qual remédio você quer entender?</label>
            <div class="relative">
                <x-consulta.icone name="lupa" plain class="pointer-events-none absolute left-3 top-1/2 size-6 -translate-y-1/2 text-slate-400" />
                <input id="busca-bula" class="ui-control min-h-14 pl-11 pr-12 text-xl" type="search" inputmode="search" autocomplete="off" placeholder="Nome do remédio" x-model="q" wire:model.live.debounce.400ms="busca" aria-controls="bulas-prontas bulas-resultados" aria-describedby="busca-bula-ajuda">
                <button type="button" x-cloak x-show="q !== ''" x-on:click="q = ''; $wire.set('busca', ''); $nextTick(() => document.getElementById('busca-bula').focus())" class="absolute right-1.5 top-1/2 inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Limpar a busca"><x-consulta.icone name="fechar" plain class="size-5" /></button>
            </div>
            <p id="busca-bula-ajuda" class="text-sm text-slate-600">Para que serve, como usar, cuidados e efeitos, em palavras simples.</p>
        </x-consulta.cartao>

        @if ($disponiveis !== [])
            <section id="bulas-prontas" aria-labelledby="disponiveis-titulo" class="grid gap-2">
                <div class="flex items-baseline justify-between gap-2 px-1">
                    <h2 id="disponiveis-titulo" class="text-lg font-semibold text-brand-900">Bulas simples prontas</h2>
                    <p class="shrink-0 text-sm text-slate-600" aria-live="polite"><span x-text="visiveis">{{ count($disponiveis) }}</span> de {{ count($disponiveis) }}</p>
                </div>

                {{-- Índice de letras: só com a busca vazia. --}}
                <nav x-show="q === ''" class="flex flex-wrap gap-1.5" aria-label="Ir para a letra">
                    @foreach ($letras->keys() as $letra)
                        <a href="#letra-{{ $letra }}" class="inline-flex size-10 items-center justify-center rounded-lg bg-white text-base font-semibold text-brand-800 ring-1 ring-slate-200 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $letra }}</a>
                    @endforeach
                </nav>

                <div x-ref="lista" class="grid overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
                    @foreach ($letras as $letra => $itens)
                        <p id="letra-{{ $letra }}" x-show="q === ''" class="scroll-mt-48 border-b border-slate-100 bg-slate-50 px-4 py-1 text-sm font-bold text-slate-600">{{ $letra }}</p>
                        @foreach ($itens as $item)
                            <a wire:key="d-{{ $item['id'] }}" data-nome="{{ $normal($item['name']) }}" x-show="mostra($el.dataset.nome)" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="flex min-h-14 min-w-0 items-center gap-3 border-b border-slate-100 px-4 py-2 last:border-b-0 hover:bg-brand-50 focus:outline-none focus-visible:bg-brand-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-600">
                                <x-consulta.icone name="bula" plain class="size-5 shrink-0 text-brand-700" />
                                <span class="min-w-0 flex-1 break-words font-semibold leading-snug text-slate-900">{{ $item['name'] }}</span>
                                <span class="shrink-0 text-xl text-slate-400" aria-hidden="true">›</span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
                <p x-cloak x-show="visiveis === 0" class="rounded-2xl bg-white px-4 py-3 text-base text-slate-700 ring-1 ring-slate-200">Nenhuma bula pronta com “<span x-text="q"></span>”. <span x-show="normal(q).length < 3">Continue digitando para procurar no catálogo.</span></p>
            </section>
        @endif

        <section id="bulas-resultados" aria-live="polite" class="grid gap-2" aria-label="Outros remédios do catálogo">
            <p wire:loading.flex wire:target="busca" class="hidden items-center gap-2 px-1 text-base text-slate-600"><span class="size-4 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" aria-hidden="true"></span>Procurando no catálogo…</p>
            @if ($limitado)
                <p role="alert" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900">Muitas buscas em pouco tempo. Aguarde um instante e continue digitando.</p>
            @elseif ($buscou)
                <h2 class="px-1 pt-1 text-lg font-semibold text-brand-900" wire:loading.remove wire:target="busca">No catálogo de remédios</h2>
                <div class="grid gap-2" wire:loading.class="opacity-60" wire:target="busca">
                    @forelse ($resultados as $item)
                        <a wire:key="b-{{ $item['id'] }}" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate class="flex min-h-16 min-w-0 items-center justify-between gap-3 rounded-2xl bg-white px-4 py-2.5 ring-1 ring-slate-200 transition hover:ring-brand-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40">
                            <span class="min-w-0">
                                <span class="block break-words font-semibold leading-snug text-slate-900">{{ $item['name'] }}</span>
                                <span class="block break-words text-sm text-slate-600">{{ \Illuminate\Support\Str::limit($item['ingredients'], 60) }}</span>
                            </span>
                            @if ($item['tem_bula'])
                                <span class="shrink-0 rounded-lg bg-emerald-50 px-2 py-0.5 text-sm font-semibold text-emerald-900">Bula pronta ›</span>
                            @else
                                <span class="shrink-0 rounded-lg bg-slate-100 px-2 py-0.5 text-sm font-medium text-slate-700">Ainda sem bula</span>
                            @endif
                        </a>
                    @empty
                        <p class="rounded-2xl bg-white px-4 py-3 text-slate-700 ring-1 ring-slate-200">Nenhum remédio encontrado para “{{ $busca }}”. Confira a grafia ou tente o nome da substância.</p>
                    @endforelse
                </div>
            @endif
        </section>
    </div>
</x-consulta.shell>
