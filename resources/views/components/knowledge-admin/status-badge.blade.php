@props(['status'])
@php
    $classes = match ($status) {
        'ASSOCIATED', 'READY', 'COMPLETED' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'PENDING', 'PROCESSING' => 'bg-amber-50 text-amber-900 ring-amber-600/20',
        'DUPLICATE' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'ERROR', 'FAILED' => 'bg-rose-50 text-rose-800 ring-rose-600/20',
        default => 'bg-slate-100 text-slate-700 ring-slate-500/20',
    };
@endphp
<span {{ $attributes->class("inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-bold ring-1 ring-inset {$classes}") }}><span aria-hidden="true">●</span>{{ $status }}</span>
