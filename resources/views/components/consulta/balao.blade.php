{{--
    Balão de conversa da tela Perguntar. `de="eu"` é a pessoa (à direita); `de="bulai"` é o assistente (à esquerda, com
    avatar e nome). `tone` muda a cor do balão do assistente: neutral | warning (situação: sem bula, não entendi) | danger.
    `alerta` = interacao | alergia transforma o balão no alerta: caixa amarela (interação) ou vermelha (reação alérgica),
    borda um tom mais escura e, na borda superior, um rótulo pequeno com ícone, separado do texto da mensagem.
--}}
@props(['de' => 'bulai', 'tone' => 'neutral', 'rotulo' => null, 'alerta' => null])
@php
    $cores = match (true) {
        $alerta === 'alergia' => 'border-rose-400 bg-rose-50 text-slate-900',
        $alerta === 'interacao' => 'border-amber-400 bg-amber-50 text-slate-900',
        $tone === 'danger' => 'border-rose-300 bg-rose-50 text-rose-900',
        $tone === 'warning' => 'border-slate-300 bg-surface-100 text-slate-900',
        default => 'border-slate-200 bg-white text-slate-900',
    };
    [$tituloAlerta, $corAlerta] = match ($alerta) {
        'alergia' => ['Alerta de Reação Alérgica', 'border-rose-300 text-rose-800'],
        'interacao' => ['Alerta de Interação', 'border-amber-300 text-amber-900'],
        default => [null, ''],
    };
@endphp
@if ($de === 'eu')
    <div {{ $attributes->class('flex justify-end pl-8 sm:pl-16') }}>
        <p class="balao-eu min-w-0 max-w-full break-words [overflow-wrap:anywhere] rounded-2xl rounded-br-md bg-brand-700 px-4 py-2.5 text-lg leading-relaxed text-white shadow-sm" aria-label="{{ $rotulo ?? 'Sua pergunta' }}">{{ $slot }}</p>
    </div>
@else
    <div {{ $attributes->class('flex items-end gap-2 sm:pr-12') }}>
        <span class="hidden size-10 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-brand-600/20 sm:inline-flex" aria-hidden="true"><img class="size-7" src="/brand/bulai-simbolo.svg" alt=""></span>
        <div class="grid min-w-0 flex-1 gap-1">
            <p class="px-1 text-sm font-semibold text-brand-800">{{ $rotulo ?? 'BULA-IA' }}</p>
            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] overflow-hidden rounded-2xl rounded-bl-md border shadow-sm {{ $alerta ? 'border-2' : '' }} {{ $cores }}" aria-live="polite" @if ($alerta) role="group" aria-label="{{ $tituloAlerta }}" data-alerta="{{ $alerta }}" @endif>
                @if ($tituloAlerta)
                    <p class="flex items-center gap-1.5 border-b px-3 py-1.5 text-sm font-bold uppercase tracking-wide sm:px-4 {{ $corAlerta }}"><x-consulta.icone name="alerta" plain class="size-4 shrink-0" />{{ $tituloAlerta }}</p>
                @endif
                <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 px-3 py-3 text-lg leading-relaxed [overflow-wrap:anywhere] sm:px-4 sm:text-xl">{{ $slot }}</div>
            </div>
        </div>
    </div>
@endif
