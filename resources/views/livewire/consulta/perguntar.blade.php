@php use App\Services\Patient\MedicationQuestionService as Q; use App\Livewire\Consulta\Perguntar as P; @endphp
{{--
    Chat amigável: a pessoa escreve (ou dita) à direita, o BULA-IA responde à esquerda em linguagem de conversa.
    Quando a bula não responde, o balão explica com palavras simples e sugere o próximo passo (chips); os detalhes
    técnicos (partes da bula, o que foi considerado) ficam recolhidos. A caixa de texto fica fixa embaixo (slot sticky).
--}}
<x-consulta.shell title="Perguntar" :back="route('consulta.inicio')">
    {{-- `pendente`: a pergunta recém-enviada aparece na hora como balão da pessoa, antes de a resposta chegar. --}}
    <div class="grid grid-cols-[minmax(0,1fr)] gap-4" aria-label="Conversa com o BULA-IA" x-data="{ pendente: '' }"
        x-on:pergunta-enviada.window="pendente = $event.detail.texto; setTimeout(() => $refs.pendente?.scrollIntoView({ block: 'end', behavior: 'smooth' }), 60)">
        @if ($resposta === null && $conversa === [])
            <x-consulta.balao>
                <p class="break-words" id="perg-titulo">Oi! Sou o BULA-IA. Qual é a sua dúvida sobre um remédio?</p>
                <p class="break-words text-base text-slate-700">Escreva ou fale, citando o nome do remédio. Depois você pode continuar: “e com café?”, “e à noite?”.</p>
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
            </x-consulta.balao>
            @if ($previo !== null)
                <x-consulta.tratamento :contexto="$previo['context']" :medicamento="$previo['medicine']" />
            @endif
        @endif

        {{-- Trocas anteriores: ficam acima, como num chat; o detalhe completo está no histórico. --}}
        @if ($conversa !== [])
            <p class="sr-only">Perguntas anteriores ({{ count($conversa) }})</p>
            @foreach ($conversa as $troca)
                <x-consulta.balao de="eu" wire:key="tp-{{ $loop->index }}">{{ $troca['pergunta'] }}</x-consulta.balao>
                <x-consulta.balao wire:key="tr-{{ $loop->index }}">
                    <p class="break-words">{{ $troca['resposta'] ?? P::falaCurta($troca['status'], $troca['medicamentos']) }}</p>
                    @if (! empty($troca['uuid']))<a class="justify-self-start text-base font-semibold text-brand-800 underline" href="{{ route('consulta.pergunta', $troca['uuid']) }}" wire:navigate>Ver detalhes</a>@endif
                </x-consulta.balao>
            @endforeach
        @endif

        @if ($aviso)
            <x-consulta.balao tone="warning"><p role="alert" class="break-words">{{ $aviso }}</p></x-consulta.balao>
        @endif

        @if ($resposta)
            @php($status = $resposta['status'])
            <x-consulta.balao de="eu" wire:key="pe-{{ count($conversa) }}">{{ $resposta['pergunta'] }}</x-consulta.balao>

            <x-consulta.balao :tone="$fala['tone']" wire:key="ba-{{ count($conversa) }}-{{ $status }}" class="scroll-mt-24" x-init="$nextTick(() => requestAnimationFrame(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' })))" x-data="{ podeOuvir: 'speechSynthesis' in window, falando: false }" data-testid="resposta-atual">
                {{-- 1. O que o BULA-IA diz, em letras grandes (com uma frase de abertura quando a bula inteira foi consultada ou o assunto são efeitos). --}}
                @if ($fala['intro'])<p class="break-words text-lg text-slate-700" data-testid="fala-intro">{{ $fala['intro'] }}</p>@endif
                @if ($fala['urgencia'])<p role="alert" class="break-words rounded-xl border-2 border-rose-300 bg-rose-50 px-3 py-3 text-lg font-semibold text-rose-900" data-testid="urgencia">{{ $fala['urgencia'] }}</p>@endif
                <p class="whitespace-pre-line break-words {{ $status === Q::BLOCKED ? 'font-semibold' : '' }}" x-ref="texto">{{ $fala['texto'] }}</p>

                @if ($status === Q::BLOCKED && ($resposta['guard']['category'] ?? null) === 'harm_risk')
                    <p class="break-words">Ligue <a class="text-2xl font-bold underline" href="tel:188">188</a> (CVV, gratuito, 24 h) ou procure a emergência mais próxima. Você não está sozinho.</p>
                @endif

                {{-- 2. Partes da bula (só quando não deu para resumir). --}}
                @if ($fala['trechos'] !== [])
                    <ol class="grid gap-2" aria-label="Partes da bula">
                        @foreach ($fala['trechos'] as $trecho)
                            <li wire:key="tc-{{ $loop->index }}" class="grid gap-1 rounded-xl bg-surface-100 px-3 py-2">
                                <p class="break-words text-sm font-semibold text-slate-600">{{ $trecho['titulo'] }}</p>
                                <p class="break-words text-lg leading-relaxed text-slate-800">{{ $trecho['texto'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif

                {{-- 3. De onde veio / o que fazer agora, em letra menor. --}}
                @if ($fala['fonte'])<p class="break-words text-base text-slate-700">{{ $fala['fonte'] }}</p>@endif

                {{-- 4. Ouvir (só se o navegador souber falar). --}}
                @if (in_array($status, [Q::ANSWERED, Q::NEEDS_INFO], true))
                    <button type="button" x-cloak x-show="podeOuvir" class="inline-flex min-h-12 items-center justify-self-start gap-2 rounded-xl border-2 border-brand-700 bg-white px-4 text-base font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40"
                        x-on:click="if (falando) { speechSynthesis.cancel(); falando = false; return } const u = new SpeechSynthesisUtterance($refs.texto.textContent.trim()); u.lang = 'pt-BR'; u.rate = 0.95; u.onend = () => falando = false; speechSynthesis.cancel(); speechSynthesis.speak(u); falando = true">
                        <x-consulta.icone name="ouvir" plain class="size-6" />
                        <span x-text="falando ? 'Parar de ouvir' : 'Ouvir'">Ouvir</span>
                    </button>
                @endif

                {{-- 5. Sugestões do próximo passo (chips). --}}
                @if ($status === Q::NO_MEDICINE && ($resposta['guesses'] ?? []) !== [])
                    <div class="flex flex-wrap gap-2" aria-label="Você quis dizer" data-testid="guesses">
                        @foreach (array_slice($resposta['guesses'], 0, 3) as $palpite)
                            <button type="button" wire:key="gs-{{ $palpite['id'] }}" wire:click="corrigir({{ $palpite['id'] }})" class="min-h-12 max-w-full rounded-full bg-brand-50 px-4 py-2 text-left text-base font-semibold text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"><span class="block truncate">{{ $palpite['name'] }}</span>@if ($palpite['ingredients'] !== '')<span class="block truncate text-sm font-normal text-slate-700">{{ $palpite['ingredients'] }}</span>@endif</button>
                        @endforeach
                    </div>
                @endif
                @if ($status === Q::NO_MEDICINE && $resposta['suggestions'] !== [])
                    <p class="break-words text-base text-slate-700">Ou toque em um dos seus remédios:</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (array_slice($resposta['suggestions'], 0, 3) as $sugestao)
                            <button type="button" wire:key="sg-{{ $sugestao['id'] }}" wire:click="corrigir({{ $sugestao['id'] }})" class="min-h-12 max-w-full truncate rounded-full bg-white px-4 text-base font-semibold text-brand-900 ring-1 ring-brand-600/30 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40">{{ $sugestao['name'] }}</button>
                        @endforeach
                    </div>
                @endif
                @if ($fala['remedios'] !== [])
                    <div class="flex flex-wrap gap-2" aria-label="Outros remédios seus">
                        @foreach ($fala['remedios'] as $nome)
                            <button type="button" wire:key="rm-{{ $loop->index }}" wire:click="sugerir(@js('Sobre '.$nome.':'))" class="min-h-12 max-w-full truncate rounded-full bg-white px-4 text-base font-semibold text-brand-900 ring-1 ring-brand-600/30 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40">{{ $nome }}</button>
                        @endforeach
                    </div>
                @endif
                @if ($fala['sugestoes'] !== [])
                    <div class="flex flex-wrap gap-2" aria-label="Sugestões de pergunta">
                        @foreach ($fala['sugestoes'] as $sugestao)
                            <button type="button" wire:key="rf-{{ $loop->index }}" wire:click="sugerir(@js($sugestao))" class="min-h-12 max-w-full rounded-full bg-brand-50 px-4 py-2 text-left text-base font-medium text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $sugestao }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- 6. Correção de digitação, em uma linha. --}}
                @foreach ($corrigidos as $item)
                    <div wire:key="cz-{{ $item['id'] }}" class="grid gap-2 rounded-xl bg-brand-50 px-3 py-2 text-base text-brand-900">
                        <p class="break-words"><span class="font-semibold">Entendemos “{{ $item['matched_text'] }}” como {{ $item['name'] }}.</span> Não é isso?</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="min-h-12 rounded-full bg-white px-4 font-semibold text-brand-900 ring-1 ring-brand-600/30 hover:bg-brand-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" wire:click="$set('corrigindo', true)">Trocar</button>
                            <button type="button" class="min-h-12 rounded-full bg-white px-4 font-semibold text-slate-800 ring-1 ring-slate-300 hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" wire:click="remover({{ $item['id'] }})" wire:loading.attr="disabled">Não era esse remédio</button>
                        </div>
                    </div>
                @endforeach

                {{-- 7. Sobre quem estamos falando + trocar remédio + detalhes recolhidos. --}}
                @if (! in_array($status, [Q::BLOCKED, Q::NO_MEDICINE], true) && $resposta['medicines'] !== [])
                    <div class="grid gap-2 border-t border-slate-200/70 pt-3 text-base text-slate-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="shrink-0">Falando de:</span>
                            @foreach ($resposta['medicines'] as $item)
                                <span wire:key="dm-{{ $item['id'] }}" class="inline-flex max-w-full items-center gap-1 rounded-full bg-brand-50 pl-3 ring-1 ring-inset ring-brand-600/20 {{ count($resposta['medicines']) > 1 ? 'pr-1' : 'pr-3' }}">
                                    <a class="min-h-10 truncate py-2 font-semibold text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate title="Ver bula simples de {{ $item['name'] }}">{{ $item['name'] }}</a>
                                    @if (count($resposta['medicines']) > 1)
                                        <button type="button" class="inline-flex size-9 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="remover({{ $item['id'] }})" aria-label="Tirar {{ $item['name'] }} da pergunta" title="Tirar"><x-consulta.icone name="fechar" plain class="size-4" /><span class="sr-only">Tirar</span></button>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                        @unless ($corrigindo)
                            <button type="button" class="min-h-10 justify-self-start font-semibold text-brand-800 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="$set('corrigindo', true)">Não é esse remédio?</button>
                        @else
                            <div class="grid gap-2">
                                <label for="busca-correcao" class="font-semibold text-slate-800">Qual remédio você quis dizer?</label>
                                <input id="busca-correcao" class="ui-control min-h-14 text-lg" type="search" autocomplete="off" placeholder="Digite o nome (mín. 3 letras)" wire:model.live.debounce.300ms="busca" autofocus>
                                @if ($buscou)
                                    @forelse ($resultados as $item)
                                        <button type="button" wire:key="rc-{{ $item['id'] }}" wire:click="corrigir({{ $item['id'] }})" class="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-4 py-2 text-left hover:border-brand-600 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                                            <span class="min-w-0"><span class="block break-words font-semibold text-slate-900">{{ $item['name'] }}</span><span class="block break-words text-base text-slate-600">{{ $item['ingredients'] }}@if (! empty($item['fuzzy'])) · parecido com “{{ $item['matched'] }}”@endif</span></span><span class="shrink-0 font-semibold text-brand-800">Usar este</span>
                                        </button>
                                    @empty
                                        <p>Nenhum remédio encontrado para “{{ $busca }}”.</p>
                                    @endforelse
                                @endif
                                <button type="button" class="min-h-10 justify-self-start underline" wire:click="$set('corrigindo', false)">Cancelar</button>
                            </div>
                        @endunless
                        @if ($fontes !== [] || $resposta['treatments'] !== [] || ! empty($resposta['uuid']))
                            <details class="rounded-xl bg-surface-100">
                                <summary class="min-h-12 cursor-pointer select-none px-3 py-2 font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ in_array($status, [Q::ANSWERED, Q::NEEDS_INFO], true) ? 'Fontes utilizadas' : 'Mais detalhes' }}</summary>
                                <div class="grid gap-3 px-3 pb-3">
                                    @foreach ($fontes as $fonte)
                                        <blockquote wire:key="ft-{{ $loop->index }}" class="grid gap-1 border-l-4 border-brand-200 pl-3">
                                            <p class="break-words text-sm font-semibold text-slate-600">{{ $fonte['document_title'] }}@if (! empty($fonte['section'])) · {{ $fonte['section'] }}@endif@if (! empty($fonte['page_start'])) · página {{ $fonte['page_start'] }}@endif@if (! empty($fonte['processed'])) · bula de {{ $fonte['processed'] }}@endif</p>
                                            <p class="max-h-48 overflow-y-auto whitespace-pre-line break-words text-base leading-relaxed text-slate-800">{{ $fonte['excerpt'] }}</p>
                                        </blockquote>
                                    @endforeach
                                    @foreach ($resposta['treatments'] as $item)
                                        <x-consulta.tratamento wire:key="tr-{{ $loop->index }}" :contexto="$item['context']" :medicamento="$item['medicine']" :somente-relevante="true" />
                                    @endforeach
                                    @if (! empty($resposta['uuid']))<p class="break-words">Guardei esta conversa no seu <a class="font-semibold text-brand-800 underline" href="{{ route('consulta.pergunta', $resposta['uuid']) }}" wire:navigate>histórico</a>.</p>@endif
                                </div>
                            </details>
                        @endif
                    </div>
                @endif
            </x-consulta.balao>

            {{-- Uma frase de risco do seu tratamento, só quando houver. --}}
            @if (! in_array($status, [Q::BLOCKED, Q::NO_MEDICINE], true))
                <x-consulta.risco :tratamentos="$resposta['treatments']" />
            @endif
        @endif

        {{-- A pergunta enviada aparece na hora; some quando a resposta chega (ela volta junto com a resposta). --}}
        <div wire:loading.flex wire:target="perguntar" class="hidden justify-end pl-6 sm:pl-16" x-ref="pendente" data-testid="pergunta-pendente">
            <p class="min-w-0 max-w-full break-words [overflow-wrap:anywhere] rounded-2xl rounded-br-md bg-brand-700 px-4 py-3 text-lg leading-relaxed text-white shadow-sm" aria-label="Sua pergunta" x-text="pendente"></p>
        </div>

        {{-- "Digitando…" enquanto procura na bula. --}}
        <div wire:loading.flex wire:target="perguntar,corrigir,remover" class="hidden items-end gap-2 pr-12" aria-live="polite" aria-label="O BULA-IA está procurando na bula">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-brand-600/20" aria-hidden="true"><img class="size-7" src="/brand/bulai-simbolo.svg" alt=""></span>
            <div class="flex items-center gap-1.5 rounded-2xl rounded-bl-md border border-slate-200 bg-white px-4 py-4 shadow-sm" aria-hidden="true">
                <span class="size-2.5 animate-bounce rounded-full bg-brand-400"></span>
                <span class="size-2.5 animate-bounce rounded-full bg-brand-400 [animation-delay:150ms]"></span>
                <span class="size-2.5 animate-bounce rounded-full bg-brand-400 [animation-delay:300ms]"></span>
            </div>
        </div>

        @if ($resposta !== null || $conversa !== [])
            <button type="button" class="min-h-12 justify-self-start px-1 font-semibold text-brand-800 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="novaPergunta">Começar outra conversa</button>
        @endif
    </div>

    <x-slot:sticky>
        <form class="grid gap-2" wire:submit="perguntar" x-on:submit="$refs.pergunta.value.trim() !== '' && $dispatch('pergunta-enviada', { texto: $refs.pergunta.value.trim() })" x-data="ditado({ aplicar: (t) => { $refs.pergunta.value = t }, confirmar: (t) => $wire.set('pergunta', t, false), texto: () => $refs.pergunta.value })" aria-label="Fazer uma pergunta">
            <label for="pergunta" class="sr-only">Sua pergunta sobre um remédio</label>
            <textarea id="pergunta" x-ref="pergunta" rows="1" class="ui-control max-h-36 min-h-12 resize-none text-lg leading-snug [field-sizing:content]" wire:model="pergunta" maxlength="{{ $maximo }}" autocomplete="off" enterkeyhint="send" placeholder="{{ $resposta || $conversa !== [] ? 'Continue a conversa…' : 'Ex.: posso tomar omeprazol com café?' }}" x-on:keydown.enter.prevent="$refs.pergunta.value.trim() !== '' && $dispatch('pergunta-enviada', { texto: $refs.pergunta.value.trim() }); $wire.set('pergunta', $refs.pergunta.value, false); $wire.perguntar()"></textarea>
            <p x-cloak x-show="parcial !== ''" class="px-1 text-base italic text-slate-500" aria-live="polite" x-text="parcial"></p>
            <p x-cloak x-show="erro !== ''" role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-base font-medium text-amber-900" x-text="erro"></p>
            <p x-cloak x-show="! suportado" class="px-1 text-base text-slate-600">Para falar a pergunta, use o microfone do teclado do celular.</p>
            <div class="flex gap-2">
                <button type="button" x-cloak x-show="suportado" x-on:click="alternar()" x-bind:aria-pressed="ouvindo" class="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl border-2 border-brand-700 bg-white px-3 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-bind:class="ouvindo ? 'animate-pulse bg-rose-50 border-rose-500 text-rose-900' : ''">
                    <svg class="size-7 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /></svg>
                    <span class="truncate" x-text="ouvindo ? 'Parar' : 'Falar'">Falar</span>
                </button>
                <x-knowledge-admin.button variant="accent" type="submit" class="min-h-12 flex-1 text-lg" loadingTarget="perguntar,corrigir,remover" loading="Procurando na bula…">Perguntar</x-knowledge-admin.button>
            </div>
        </form>
    </x-slot:sticky>
</x-consulta.shell>
