{{--
    Barra de filtros em UMA linha: campos compactos (`search`/`select` com `compact`) à esquerda, contagem e ações à direita.
    Fica logo abaixo do título, sem espaço vazio entre eles. `count` = "17 documentos"; slot `actions` = links/botões discretos.
--}}
@props(['count' => null])
<div {{ $attributes->class('flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-2 shadow-sm ring-1 ring-slate-300') }} role="search">
    {{ $slot }}
    @if ($count !== null || isset($actions))
        <div class="ml-auto flex flex-wrap items-center gap-3 text-xs font-medium text-slate-600">
            @if ($count !== null)<span aria-live="polite">{{ $count }}</span>@endif
            @isset($actions)<span class="flex items-center gap-2">{{ $actions }}</span>@endisset
        </div>
    @endif
</div>
