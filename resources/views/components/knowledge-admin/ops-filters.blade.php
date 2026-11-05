{{-- Barra de filtros em uma linha (busca + até dois seletores) com a contagem de resultados ao lado. --}}
@props(['count' => null])
<div {{ $attributes->class('grid gap-2') }}>
    <div class="flex flex-wrap items-end gap-3">{{ $slot }}</div>
    @isset($count)<p class="text-xs text-slate-500" aria-live="polite">{{ $count }}</p>@endisset
</div>
