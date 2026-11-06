@php use App\Services\Patient\MedicationQuestionService as Q; @endphp
{{--
    Conversa: as trocas anteriores ficam recolhidas, a pergunta atual aparece como balão à direita e a resposta como
    cartão à esquerda. A caixa de texto, o ditado por voz e o botão "Perguntar" ficam fixos embaixo (slot sticky do shell).
--}}
<x-consulta.shell title="Perguntar" :back="route('consulta.inicio')">
    @if ($resposta === null && $conversa === [])
        <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="perg-titulo">
            <h2 id="perg-titulo" class="text-xl font-semibold text-brand-900">Qual é a sua dúvida sobre um remédio?</h2>
            <p class="text-base text-slate-700">Escreva ou fale, citando o nome do remédio. Depois você pode continuar perguntando: “e com café?”, “e à noite?”.</p>
            @if ($emUso !== [] || $exemplos !== [])
                <div class="flex flex-wrap gap-2" aria-label="Sugestões rápidas">
                    @foreach ($emUso as $nome)
                        <button type="button" wire:key="su-{{ $loop->index }}" wire:click="sugerir(@js($nome))" class="min-h-12 max-w-full truncate rounded-full bg-brand-50 px-4 text-base font-medium text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $nome }}</button>
                    @endforeach
                    @if (trim($pergunta) === '')
                        @foreach ($exemplos as $exemplo)
                            <button type="button" wire:key="ex-{{ $loop->index }}" wire:click="sugerir(@js($exemplo))" class="min-h-12 max-w-full rounded-full bg-slate-100 px-4 py-2 text-left text-base text-slate-800 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $exemplo }}</button>
                        @endforeach
                    @endif
                </div>
            @endif
        </section>
        @if ($previo !== null)
            <x-consulta.tratamento :contexto="$previo['context']" :medicamento="$previo['medicine']" />
        @endif
    @endif

    @if ($conversa !== [])
        <details class="rounded-2xl border border-slate-200 bg-white shadow-sm" @if ($resposta === null) open @endif>
            <summary class="min-h-14 cursor-pointer select-none px-5 py-3 text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Perguntas anteriores ({{ count($conversa) }})</summary>
            <ol class="grid gap-4 border-t border-slate-100 p-4" aria-label="Trocas anteriores desta conversa">
                @foreach ($conversa as $troca)
                    <li wire:key="troca-{{ $loop->index }}" class="grid gap-2">
                        <p class="ml-8 justify-self-end rounded-2xl rounded-br-md bg-brand-50 px-4 py-3 text-lg text-brand-900">{{ $troca['pergunta'] }}</p>
                        <div class="mr-8 grid gap-1 rounded-2xl rounded-bl-md border border-slate-200 bg-surface-50 px-4 py-3 text-lg text-slate-800">
                            <p>{{ $troca['resposta'] ?? 'Não foi possível responder com base na bula.' }}</p>
                            @if ($troca['medicamentos'] !== [])<p class="text-base text-slate-600">Sobre: {{ implode(', ', array_column($troca['medicamentos'], 'name')) }}</p>@endif
                            @if (! empty($troca['uuid']))<a class="text-base font-semibold text-brand-800 underline" href="{{ route('consulta.pergunta', $troca['uuid']) }}" wire:navigate>Ver detalhes</a>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </details>
    @endif

    @if ($aviso)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $aviso }}</p>@endif

    @if ($resposta)
        @php($status = $resposta['status'])
        <p class="ml-8 justify-self-end rounded-2xl rounded-br-md bg-brand-50 px-4 py-3 text-lg text-brand-900" aria-label="Sua pergunta">{{ $resposta['pergunta'] }}</p>

        @if ($status === Q::BLOCKED)
            @php($risco = ($resposta['guard']['category'] ?? null) === 'harm_risk')
            <section role="alert" class="grid gap-4 rounded-2xl border-2 p-5 {{ $risco ? 'border-rose-400 bg-rose-50 text-rose-900' : 'border-slate-200 bg-white text-slate-800' }}">
                <p class="text-xl font-semibold leading-snug">{{ $resposta['message'] }}</p>
                @if ($risco)<p class="text-xl">Ligue <a class="text-2xl font-bold underline" href="tel:188">188</a> (CVV, gratuito, 24 h) ou procure a emergência mais próxima. Você não está sozinho.</p>@endif
            </section>
        @elseif ($status === Q::NO_MEDICINE)
            <section class="grid gap-4 rounded-2xl border-2 border-amber-400 bg-amber-50 p-5 text-amber-900" aria-live="polite">
                <p class="text-xl font-semibold leading-snug">Não encontramos o nome de um medicamento na sua pergunta.</p>
                <p>Escreva o nome do remédio como está na caixa (ex.: “Losartana”, “Marevan”).@if ($resposta['suggestions'] !== []) Ou toque em um dos seus remédios:@endif</p>
                @if ($resposta['suggestions'] !== [])
                    <div class="grid gap-2">
                        @foreach ($resposta['suggestions'] as $sugestao)
                            <button type="button" wire:key="sg-{{ $sugestao['id'] }}" wire:click="corrigir({{ $sugestao['id'] }})" class="min-h-14 rounded-xl bg-white px-4 text-lg font-semibold text-brand-900 ring-1 ring-brand-600/30 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40">{{ $sugestao['name'] }}</button>
                        @endforeach
                    </div>
                @endif
            </section>
        @else
            {{-- 1. Resposta grande; 2. ouvir; 3. UMA frase de risco; 4. correção de digitação; 5. detalhes recolhidos. --}}
            <x-consulta.resposta :resposta="$resposta" titulo="Resposta" :mostrar-pergunta="false" class="mr-4 sm:mr-8" />
            <x-consulta.risco :tratamentos="$resposta['treatments']" />

            @foreach ($corrigidos as $item)
                <section wire:key="cz-{{ $item['id'] }}" class="grid gap-3 rounded-2xl border border-brand-200 bg-brand-50 p-4 text-brand-900" aria-live="polite">
                    <p class="text-lg"><span class="font-semibold">Entendemos “{{ $item['matched_text'] }}” como {{ $item['name'] }}.</span> Não é isso?</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button type="button" class="min-h-14 rounded-xl bg-white px-4 text-lg font-semibold text-brand-900 ring-1 ring-brand-600/30 hover:bg-brand-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" wire:click="$set('corrigindo', true)">Trocar o remédio</button>
                        <button type="button" class="min-h-14 rounded-xl bg-white px-4 text-lg font-semibold text-slate-800 ring-1 ring-slate-300 hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" wire:click="remover({{ $item['id'] }})" wire:loading.attr="disabled">Não era esse remédio</button>
                    </div>
                </section>
            @endforeach

            <details class="rounded-2xl border border-slate-200 bg-white shadow-sm" @if ($corrigindo) open @endif>
                <summary class="min-h-14 cursor-pointer select-none px-5 py-3 text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Entendemos que você perguntou sobre: {{ collect($resposta['medicines'])->pluck('name')->join(', ') }}</summary>
                <div class="grid gap-3 border-t border-slate-100 p-5 text-base text-slate-700">
                    <div class="grid gap-2">
                        @foreach ($resposta['medicines'] as $item)
                            <div wire:key="dm-{{ $item['id'] }}" class="flex min-w-0 items-stretch gap-2">
                                <a class="inline-flex min-h-12 min-w-0 flex-1 items-center justify-between gap-2 rounded-xl bg-brand-50 px-4 font-semibold text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate title="Ver bula humanizada de {{ $item['name'] }}"><span class="truncate">{{ $item['name'] }}</span><span class="shrink-0 font-normal">Bula simples ›</span></a>
                                @if (count($resposta['medicines']) > 1)
                                    <button type="button" class="min-h-12 shrink-0 rounded-xl px-3 font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="remover({{ $item['id'] }})" aria-label="Tirar {{ $item['name'] }} da pergunta">Tirar</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @unless ($corrigindo)
                        <button type="button" class="min-h-12 justify-self-start font-semibold text-brand-800 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="$set('corrigindo', true)">Não é esse remédio?</button>
                    @else
                        <div class="grid gap-2">
                            <label for="busca-correcao" class="font-semibold text-slate-800">Qual remédio você quis dizer?</label>
                            <input id="busca-correcao" class="ui-control min-h-14 text-lg" type="search" autocomplete="off" placeholder="Digite o nome (mín. 3 letras)" wire:model.live.debounce.300ms="busca" autofocus>
                            @if ($buscou)
                                @forelse ($resultados as $item)
                                    <button type="button" wire:key="rc-{{ $item['id'] }}" wire:click="corrigir({{ $item['id'] }})" class="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 px-4 py-2 text-left hover:border-brand-600 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                                        <span class="min-w-0"><span class="block font-semibold text-slate-900">{{ $item['name'] }}</span><span class="block text-base text-slate-600">{{ $item['ingredients'] }}@if (! empty($item['fuzzy'])) · parecido com “{{ $item['matched'] }}”@endif</span></span><span class="shrink-0 font-semibold text-brand-800">Usar este</span>
                                    </button>
                                @empty
                                    <p>Nenhum medicamento encontrado para “{{ $busca }}”.</p>
                                @endforelse
                            @endif
                            <button type="button" class="min-h-12 justify-self-start underline" wire:click="$set('corrigindo', false)">Cancelar</button>
                        </div>
                    @endunless
                    @foreach ($resposta['treatments'] as $item)
                        <x-consulta.tratamento wire:key="tr-{{ $loop->index }}" :contexto="$item['context']" :medicamento="$item['medicine']" :somente-relevante="true" />
                    @endforeach
                    @if (! empty($resposta['limitations']))
                        <ul class="grid gap-1" aria-label="Limitações">@foreach ($resposta['limitations'] as $limitation)<li>• {{ $limitation }}</li>@endforeach</ul>
                    @endif
                    @if (! empty($resposta['uuid']))<p>Guardada no seu <a class="font-semibold text-brand-800 underline" href="{{ route('consulta.pergunta', $resposta['uuid']) }}" wire:navigate>histórico</a>.</p>@endif
                </div>
            </details>
        @endif

        <p class="px-1 text-base text-slate-700">Continue perguntando aqui embaixo, por exemplo: “e com café?”, “e à noite?”.</p>
    @endif

    @if ($resposta !== null || $conversa !== [])
        <button type="button" class="min-h-12 justify-self-start px-1 font-semibold text-brand-800 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="novaPergunta">Começar outra conversa</button>
    @endif

    <x-slot:sticky>
        <form class="grid gap-2" wire:submit="perguntar" x-data="ditado({ aplicar: (t) => { $refs.pergunta.value = t }, confirmar: (t) => $wire.set('pergunta', t, false), texto: () => $refs.pergunta.value })" aria-label="Fazer uma pergunta">
            <label for="pergunta" class="sr-only">Sua pergunta sobre um remédio</label>
            <textarea id="pergunta" x-ref="pergunta" rows="2" class="ui-control min-h-14 resize-none text-xl leading-relaxed" wire:model="pergunta" maxlength="{{ $maximo }}" autocomplete="off" enterkeyhint="send" placeholder="{{ $resposta || $conversa !== [] ? 'Continue a conversa…' : 'Ex.: posso tomar omeprazol com café?' }}" x-on:keydown.enter.prevent="$wire.set('pergunta', $refs.pergunta.value, false); $wire.perguntar()"></textarea>
            <p x-cloak x-show="parcial !== ''" class="px-1 text-base italic text-slate-500" aria-live="polite" x-text="parcial"></p>
            <p x-cloak x-show="erro !== ''" role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-base font-medium text-amber-900" x-text="erro"></p>
            <p x-cloak x-show="! suportado" class="px-1 text-base text-slate-600">Para falar a pergunta, use o microfone do teclado do celular.</p>
            <div class="flex gap-2">
                <button type="button" x-cloak x-show="suportado" x-on:click="alternar()" x-bind:aria-pressed="ouvindo" class="inline-flex min-h-14 flex-1 items-center justify-center gap-2 rounded-xl border-2 border-brand-700 bg-white px-3 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-bind:class="ouvindo ? 'animate-pulse bg-rose-50 border-rose-500 text-rose-900' : ''">
                    <svg class="size-7 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /></svg>
                    <span class="truncate" x-text="ouvindo ? 'Parar' : 'Falar'">Falar</span>
                </button>
                <x-knowledge-admin.button variant="accent" type="submit" class="min-h-14 flex-1 text-lg" loadingTarget="perguntar,corrigir,remover" loading="Procurando na bula…">Perguntar</x-knowledge-admin.button>
            </div>
        </form>
    </x-slot:sticky>
</x-consulta.shell>
