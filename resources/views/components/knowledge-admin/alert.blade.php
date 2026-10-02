@props(['tone' => 'info'])
@php
    $classes = match ($tone) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-900',
        default => 'border-sky-200 bg-sky-50 text-sky-900',
    };
@endphp
<div {{ $attributes->class("rounded-lg border px-4 py-3 text-sm {$classes}")->merge(['role' => $tone === 'danger' ? 'alert' : 'status']) }}>{{ $slot }}</div>
