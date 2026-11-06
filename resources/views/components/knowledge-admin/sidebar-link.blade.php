{{-- Item do menu lateral: ícone + rótulo + descrição curta (segunda linha); no modo recolhido mostra só o ícone com a dica em `title`. --}}
@props(['href', 'active' => false, 'icon', 'hint' => null, 'description' => null, 'collapsed' => 'false'])
<a href="{{ $href }}" wire:navigate title="{{ $hint ?? '' }}" @if ($active) aria-current="page" @endif
   {{ $attributes->class(['relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-1.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400', 'bg-brand-700 text-white' => $active, 'text-brand-100 hover:bg-brand-700/60 hover:text-white' => ! $active]) }}>
    @if ($active)<span class="absolute inset-y-2 left-0 w-1 rounded-full bg-accent-400" aria-hidden="true"></span>@endif
    <svg class="size-5 shrink-0 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ $icon }}" /></svg>
    <span class="flex min-w-0 flex-1 items-center justify-between gap-2" x-show="!({{ $collapsed }})" x-cloak>
        <span class="min-w-0">
            <span class="block truncate leading-5">{{ $slot }}</span>
            @if ($description)<span class="block truncate text-xs font-normal leading-4 {{ $active ? 'text-brand-100' : 'text-brand-300' }}">{{ $description }}</span>@endif
        </span>
        @isset($badge){{ $badge }}@endisset
    </span>
    @isset($badge)<span class="absolute -right-0.5 -top-0.5" x-show="{{ $collapsed }}" x-cloak aria-hidden="true"><span class="block size-2.5 rounded-full bg-amber-400"></span></span>@endisset
</a>
