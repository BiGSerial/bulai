{{-- Indicador compacto para a Visão geral: rótulo, valor, complemento e link opcional. --}}
@props(['label', 'value', 'href' => null, 'hint' => null, 'tone' => 'default'])
@php
    $valueClass = match ($tone) {
        'warning' => 'text-amber-700',
        'danger' => 'text-rose-700',
        'success' => 'text-emerald-700',
        'brand' => 'text-brand-700',
        default => 'text-slate-900',
    };
    $classes = 'grid min-w-0 content-start gap-0.5 rounded-lg bg-surface-50 px-3 py-2.5 ring-1 ring-slate-200';
@endphp
@if ($href)
    <a {{ $attributes->class($classes.' transition hover:ring-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600') }} href="{{ $href }}" wire:navigate>
        <span class="truncate text-xs text-slate-600">{{ $label }}</span>
        <span class="text-xl font-semibold tabular-nums {{ $valueClass }}">{{ $value }}</span>
        @if ($hint)<span class="truncate text-xs text-slate-500">{{ $hint }}</span>@endif
    </a>
@else
    <div {{ $attributes->class($classes) }}>
        <span class="truncate text-xs text-slate-600">{{ $label }}</span>
        <span class="text-xl font-semibold tabular-nums {{ $valueClass }}">{{ $value }}</span>
        @if ($hint)<span class="truncate text-xs text-slate-500">{{ $hint }}</span>@endif
    </div>
@endif
