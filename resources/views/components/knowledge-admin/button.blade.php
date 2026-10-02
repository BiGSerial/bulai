@props(['variant' => 'primary', 'href' => null, 'type' => 'button', 'loading' => null, 'loadingTarget' => null])
@php
    $classes = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 '.match ($variant) {
        'primary' => 'bg-brand-700 text-white shadow-sm hover:bg-brand-800',
        'danger' => 'bg-rose-700 text-white shadow-sm hover:bg-rose-800',
        'quiet' => 'text-slate-700 hover:bg-slate-100',
        default => 'border border-slate-300 bg-white text-slate-800 shadow-sm hover:bg-slate-50',
    };
    $opensNewTab = $attributes->get('target') === '_blank';
@endphp
@if ($href)
    <a {{ $attributes->class($classes)->merge(['href' => $href]) }} @unless ($opensNewTab || $attributes->has('download')) wire:navigate @endunless>{{ $slot }}</a>
@else
    <button {{ $attributes->class($classes)->merge(['type' => $type]) }} @if ($loadingTarget) wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}" @endif>
        @if ($loadingTarget && $loading)
            <span wire:loading.remove wire:target="{{ $loadingTarget }}">{{ $slot }}</span><span wire:loading wire:target="{{ $loadingTarget }}">{{ $loading }}</span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
