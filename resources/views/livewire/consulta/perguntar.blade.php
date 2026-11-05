@php use App\Services\Patient\MedicationQuestionService as Q; @endphp
<x-consulta.shell title="Perguntar" :back="route('consulta.inicio')">
    <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" wire:submit="perguntar" x-data="{ podeFalar: ('webkitSpeechRecognition' in window) || ('SpeechRecognition' in window), ouvindo: false, rec: null,
        falar() {
            if (this.ouvindo) { this.rec?.stop(); return }
            const R = window.SpeechRecognition || window.webkitSpeechRecognition; this.rec = new R(); this.rec.lang = 'pt-BR'; this.rec.interimResults = false;
            this.rec.onresult = (e) => { const t = Array.from(e.results).map(r => r[0].transcript).join(' ').trim(); $refs.pergunta.value = t; $wire.set('pergunta', t) };
            this.rec.onend = () => { this.ouvindo = false }; this.rec.onerror = () => { this.ouvindo = false }; this.ouvindo = true; this.rec.start();
        } }" aria-labelledby="perg-titulo">
        <label id="perg-titulo" for="pergunta" class="text-xl font-semibold text-brand-900">Qual é a sua dúvida sobre um remédio?</label>
        <textarea id="pergunta" x-ref="pergunta" class="ui-control min-h-32 text-xl leading-relaxed" wire:model="pergunta" maxlength="{{ $maximo }}" autocomplete="off" enterkeyhint="send" placeholder="Ex.: posso tomar omeprazol com café?"></textarea>
        @if ($emUso !== [] || $exemplos !== [])
            <div class="flex flex-wrap gap-2" aria-label="Sugestões rápidas">
                @foreach ($emUso as $nome)
                    <button type="button" wire:key="su-{{ $loop->index }}" wire:click="sugerir(@js($nome))" class="min-h-12 rounded-full bg-brand-50 px-4 text-base font-medium text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $nome }}</button>
                @endforeach
                @if ($resposta === null && trim($pergunta) === '')
                    @foreach ($exemplos as $exemplo)
                        <button type="button" wire:key="ex-{{ $loop->index }}" wire:click="sugerir(@js($exemplo))" class="min-h-12 rounded-full bg-slate-100 px-4 text-base text-slate-800 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $exemplo }}</button>
                    @endforeach
                @endif
            </div>
        @endif
        @if ($aviso)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $aviso }}</p>@endif
        <button type="button" x-cloak x-show="podeFalar" x-on:click="falar()" x-bind:aria-pressed="ouvindo" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-bind:class="ouvindo ? 'bg-brand-50 animate-pulse' : ''">
            <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /></svg>
            <span x-text="ouvindo ? 'Ouvindo… toque para parar' : 'Falar a pergunta'">Falar a pergunta</span>
        </button>
        <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-10 -mx-4 -mb-4 rounded-b-2xl bg-white/95 px-4 pb-4 pt-2 backdrop-blur sm:static sm:m-0 sm:p-0">
            <x-knowledge-admin.button variant="accent" type="submit" class="min-h-16 w-full text-xl" loadingTarget="perguntar,corrigir" loading="Procurando na bula…">Perguntar</x-knowledge-admin.button>
        </div>
    </form>

    @if ($resposta)
        @php($status = $resposta['status'])
        @if ($status === Q::BLOCKED)
            @php($risco = ($resposta['guard']['category'] ?? null) === 'harm_risk')
            <section role="alert" class="grid gap-4 rounded-2xl border-2 p-5 {{ $risco ? 'border-rose-400 bg-rose-50 text-rose-900' : 'border-slate-200 bg-white text-slate-800' }}">
                <p class="text-xl font-semibold leading-snug">{{ $resposta['message'] }}</p>
                @if ($risco)<p class="text-xl">Ligue <a class="text-2xl font-bold underline" href="tel:188">188</a> (CVV, gratuito, 24 h) ou procure a emergência mais próxima. Você não está sozinho.</p>@endif
                <x-knowledge-admin.button class="min-h-14 w-full text-lg" wire:click="novaPergunta">Fazer outra pergunta</x-knowledge-admin.button>
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
            {{-- 1. Resposta grande; 2. ouvir; 3. UMA frase de risco; 4. de onde veio; 5. detalhes recolhidos. --}}
            <x-consulta.resposta :resposta="$resposta" titulo="Resposta" />
            <x-consulta.risco :tratamentos="$resposta['treatments']" />

            <details class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="min-h-14 cursor-pointer select-none px-5 py-3 text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Entendemos que você perguntou sobre: {{ collect($resposta['medicines'])->pluck('name')->join(', ') }}</summary>
                <div class="grid gap-3 border-t border-slate-100 p-5 text-base text-slate-700">
                    <div class="grid gap-2">
                        @foreach ($resposta['medicines'] as $item)
                            <a wire:key="dm-{{ $item['id'] }}" class="inline-flex min-h-12 items-center justify-between gap-2 rounded-xl bg-brand-50 px-4 font-semibold text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate title="Ver bula humanizada de {{ $item['name'] }}">{{ $item['name'] }}<span class="font-normal">Ver a bula simples ›</span></a>
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
                                        <span class="min-w-0"><span class="block font-semibold text-slate-900">{{ $item['name'] }}</span><span class="block text-base text-slate-600">{{ $item['ingredients'] }}</span></span><span class="shrink-0 font-semibold text-brand-800">Usar este</span>
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

            <x-knowledge-admin.button variant="secondary" class="min-h-14 w-full text-lg" wire:click="novaPergunta">Fazer outra pergunta</x-knowledge-admin.button>
        @endif
    @elseif ($previo !== null)
        <x-consulta.tratamento :contexto="$previo['context']" :medicamento="$previo['medicine']" />
    @endif
</x-consulta.shell>
