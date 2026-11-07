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
    $edge = match ($tone) {
        'warning' => 'border-amber-500',
        'danger' => 'border-rose-600',
        'success' => 'border-emerald-600',
        'brand' => 'border-brand-600',
        default => 'border-slate-400',
    };
    $classes = 'grid min-w-0 content-start gap-0.5 rounded-lg border-l-4 bg-white px-3 py-2.5 shadow-sm ring-1 ring-slate-300 '.$edge;
@endphp
@if ($href)
    <a {{ $attributes->class($classes.' transition hover:bg-brand-50 hover:ring-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600') }} href="{{ $href }}" wire:navigate>
        <span class="text-xs font-medium text-slate-700">{{ $label }}</span>
        <span class="text-xl font-semibold tabular-nums {{ $valueClass }}">{{ $value }}</span>
        @if ($hint)<span class="text-xs text-slate-600">{{ $hint }}</span>@endif
    </a>
@else
    <div {{ $attributes->class($classes) }}>
        <span class="text-xs font-medium text-slate-700">{{ $label }}</span>
        <span class="text-xl font-semibold tabular-nums {{ $valueClass }}">{{ $value }}</span>
        @if ($hint)<span class="text-xs text-slate-600">{{ $hint }}</span>@endif
    </div>
@endif
