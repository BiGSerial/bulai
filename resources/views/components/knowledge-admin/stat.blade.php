@props(['label', 'value', 'href', 'hint' => null, 'tone' => 'default'])
@php
    $valueClass = match ($tone) {
        'warning' => 'text-amber-700',
        'danger' => 'text-rose-700',
        default => 'text-slate-900',
    };
@endphp
<a {{ $attributes->class('block rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-600 sm:p-5') }} href="{{ $href }}" wire:navigate>
    <p class="text-sm text-slate-600">{{ $label }}</p>
    <p class="mt-1 text-3xl font-semibold {{ $valueClass }}">{{ $value }}</p>
    @if ($hint)<p class="mt-1 text-sm text-slate-500">{{ $hint }}</p>@endif
</a>
