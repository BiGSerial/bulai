{{-- Botão grande do app do paciente (alvo ≥ 56 px, texto quebra em vez de estourar a tela). variant: primary | accent | secondary | danger | quiet | success --}}
@props(['variant' => 'primary', 'href' => null, 'type' => 'button', 'loading' => null, 'loadingTarget' => null, 'size' => 'lg'])
@php
    $classes = 'inline-flex w-full items-center justify-center gap-2 rounded-2xl px-4 text-center font-semibold leading-snug transition active:scale-[0.98] focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40 disabled:cursor-not-allowed disabled:opacity-50 '
        .match ($size) { 'sm' => 'min-h-12 py-2 text-base', 'md' => 'min-h-14 py-2.5 text-lg', default => 'min-h-16 py-3 text-xl' }
        .' '.match ($variant) {
            'accent' => 'bg-accent-600 text-white shadow-sm hover:bg-accent-700',
            'success' => 'bg-emerald-700 text-white shadow-sm hover:bg-emerald-800',
            'danger' => 'bg-rose-700 text-white shadow-sm hover:bg-rose-800',
            'quiet' => 'text-brand-800 hover:bg-brand-50',
            'secondary' => 'border-2 border-brand-700 bg-white text-brand-900 hover:bg-brand-50',
            'soft' => 'bg-brand-50 text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100',
            default => 'bg-brand-700 text-white shadow-sm hover:bg-brand-800',
        };
@endphp
@if ($href)
    <a {{ $attributes->class($classes)->merge(['href' => $href]) }} @unless ($attributes->get('target') === '_blank') wire:navigate @endunless>{{ $slot }}</a>
@else
    <button {{ $attributes->class($classes)->merge(['type' => $type]) }} @if ($loadingTarget) wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}" @endif>
        @if ($loadingTarget && $loading)
            <span wire:loading.remove wire:target="{{ $loadingTarget }}">{{ $slot }}</span><span wire:loading wire:target="{{ $loadingTarget }}">{{ $loading }}</span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
