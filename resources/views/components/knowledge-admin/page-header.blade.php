{{--
    Faixa de decisão abaixo do título da tela (o `h1` vem do shell): um selo de situação, até dois metadados,
    UMA ação primária (slot `primary`) e as secundárias escondidas no menu "Mais" (slot `more`, com `menu-item`).
--}}
@props(['badge' => null, 'badgeTone' => 'neutral', 'meta' => []])
<div {{ $attributes->class('flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between') }}>
    <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-700">
        @if ($badge)<x-knowledge-admin.badge :tone="$badgeTone">{{ $badge }}</x-knowledge-admin.badge>@endif
        @foreach ($meta as $value)
            @if (filled($value))<span class="max-w-xs truncate" title="{{ $value }}">{{ $value }}</span>@endif
        @endforeach
        {{ $slot }}
    </div>
    @if (isset($primary) || isset($more))
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @isset($primary){{ $primary }}@endisset
            @isset($more)
                <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                    <x-knowledge-admin.button variant="secondary" x-on:click="open = !open" x-bind:aria-expanded="open" aria-haspopup="menu">
                        Mais
                        <svg class="size-4 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </x-knowledge-admin.button>
                    <div x-cloak x-show="open" x-on:click.outside="open = false" x-on:click="open = false" x-transition.opacity role="menu" aria-label="Mais ações" class="absolute right-0 z-20 mt-1 grid w-64 gap-0.5 rounded-xl border border-slate-300 bg-white p-1.5 shadow-xl">
                        {{ $more }}
                    </div>
                </div>
            @endisset
        </div>
    @endif
</div>
