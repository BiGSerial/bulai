@props(['tone' => 'neutral'])
@php
    $classes = match ($tone) {
        'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'warning' => 'bg-amber-50 text-amber-900 ring-amber-600/20',
        'danger' => 'bg-rose-50 text-rose-800 ring-rose-600/20',
        'info' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        default => 'bg-slate-100 text-slate-700 ring-slate-500/20',
    };
@endphp
<span {{ $attributes->class("inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}") }}>{{ $slot }}</span>
