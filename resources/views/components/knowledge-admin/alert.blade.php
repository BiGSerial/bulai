@props(['tone' => 'info'])
@php
    $classes = match ($tone) {
        'success' => 'border-emerald-300 bg-emerald-100 text-emerald-900',
        'warning' => 'border-amber-300 bg-amber-100 text-amber-900',
        'danger' => 'border-rose-300 bg-rose-100 text-rose-900',
        default => 'border-sky-300 bg-sky-100 text-sky-900',
    };
@endphp
<div {{ $attributes->class("rounded-lg border px-4 py-3 text-sm {$classes}")->merge(['role' => $tone === 'danger' ? 'alert' : 'status']) }}>{{ $slot }}</div>
