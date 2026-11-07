{{--
    Balão de conversa da tela Perguntar. `de="eu"` é a pessoa (à direita); `de="bulai"` é o assistente (à esquerda, com
    avatar e nome). `tone` muda a cor do balão do assistente: neutral | warning | danger.
--}}
@props(['de' => 'bulai', 'tone' => 'neutral', 'rotulo' => null])
@php
    $cores = match ($tone) {
        'danger' => 'border-rose-300 bg-rose-50 text-rose-900',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-900',
        default => 'border-slate-200 bg-white text-slate-900',
    };
@endphp
@if ($de === 'eu')
    <div {{ $attributes->class('flex justify-end pl-6 sm:pl-16') }}>
        <p class="min-w-0 max-w-full break-words [overflow-wrap:anywhere] rounded-2xl rounded-br-md bg-brand-700 px-4 py-3 text-lg leading-relaxed text-white shadow-sm" aria-label="{{ $rotulo ?? 'Sua pergunta' }}">{{ $slot }}</p>
    </div>
@else
    <div {{ $attributes->class('flex items-end gap-2 sm:pr-12') }}>
        <span class="hidden size-10 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-brand-600/20 sm:inline-flex" aria-hidden="true"><img class="size-7" src="/brand/bulai-simbolo.svg" alt=""></span>
        <div class="grid min-w-0 flex-1 gap-1">
            <p class="px-1 text-sm font-semibold text-brand-800">{{ $rotulo ?? 'BULA-IA' }}</p>
            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 [overflow-wrap:anywhere] rounded-2xl rounded-bl-md border px-3 py-3 text-xl sm:px-4 leading-relaxed shadow-sm {{ $cores }}" aria-live="polite">{{ $slot }}</div>
        </div>
    </div>
@endif
