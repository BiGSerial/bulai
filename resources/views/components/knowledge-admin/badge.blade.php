@props(['tone' => 'neutral'])
@php
    $classes = match ($tone) {
        'success' => 'bg-emerald-100 text-emerald-900 ring-emerald-600/30',
        'warning' => 'bg-amber-100 text-amber-900 ring-amber-600/30',
        'danger' => 'bg-rose-100 text-rose-900 ring-rose-600/30',
        'info' => 'bg-sky-100 text-sky-900 ring-sky-600/30',
        'brand' => 'bg-brand-100 text-brand-900 ring-brand-600/30',
        default => 'bg-slate-200 text-slate-800 ring-slate-500/30',
    };
@endphp
<span {{ $attributes->class("inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium leading-5 ring-1 ring-inset {$classes}") }}>{{ $slot }}</span>
