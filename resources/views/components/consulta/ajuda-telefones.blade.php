{{--
    Telefones de ajuda de uma resposta (crise, superdose, sintoma grave, pedido recusado por risco), como botões de ligar
    grandes e em destaque. `linhas` vem de QuestionConversation::helpLines (o aplicativo recebe a mesma lista em `help_lines`).
--}}
@props(['linhas' => []])
@if ($linhas !== [])
    <div {{ $attributes->class('grid gap-2 rounded-2xl border-2 border-rose-300 bg-white p-3') }} role="group" aria-label="Telefones de ajuda" data-testid="ajuda-telefones">
        <p class="flex items-center gap-2 text-base font-bold text-rose-900"><x-consulta.icone name="telefone" plain class="size-5 shrink-0" />Precisa de ajuda agora? Toque para ligar.</p>
        @foreach ($linhas as $linha)
            <a href="tel:{{ $linha['tel'] }}" wire:key="aj-{{ $linha['tel'] }}"
                class="flex min-h-16 min-w-0 items-center gap-3 rounded-xl px-3 py-2 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/40 {{ $loop->first ? 'bg-rose-700 text-white hover:bg-rose-800' : 'bg-rose-50 text-rose-950 ring-1 ring-inset ring-rose-300 hover:bg-rose-100' }}">
                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-full {{ $loop->first ? 'bg-white/20' : 'bg-white text-rose-800' }}" aria-hidden="true"><x-consulta.icone name="telefone" plain class="size-6" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block break-words text-xl font-bold leading-tight">Ligar {{ $linha['number'] }} <span class="font-semibold">· {{ $linha['name'] }}</span></span>
                    <span class="block break-words text-base leading-snug {{ $loop->first ? 'text-rose-50' : 'text-rose-900' }}">{{ $linha['detail'] }}</span>
                </span>
            </a>
        @endforeach
    </div>
@endif
