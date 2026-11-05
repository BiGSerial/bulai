{{-- Distribuição em barras horizontais: uma série, um tom; a identidade vem do rótulo, nunca só da cor. --}}
@props(['items' => [], 'labels' => [], 'tones' => [], 'empty' => 'Sem dados no período.', 'ariaLabel' => 'Distribuição'])
@php
    $rows = collect($items)->filter(fn ($value) => (int) $value > 0);
    $max = max(1, (int) $rows->max());
    $sum = max(1, (int) $rows->sum());
    $barClass = fn (string $key): string => match ($tones[$key] ?? 'default') {
        'danger' => 'bg-rose-500',
        'warning' => 'bg-amber-500',
        'info' => 'bg-sky-500',
        'success' => 'bg-emerald-500',
        'muted' => 'bg-slate-400',
        default => 'bg-brand-600',
    };
    // Largura em doze faixas (classes fixas, sem style inline): proporção em relação ao maior valor.
    $widthClass = fn (int $value): string => match ((int) ceil($value / $max * 12)) {
        1 => 'w-1/12', 2 => 'w-2/12', 3 => 'w-3/12', 4 => 'w-4/12', 5 => 'w-5/12', 6 => 'w-6/12',
        7 => 'w-7/12', 8 => 'w-8/12', 9 => 'w-9/12', 10 => 'w-10/12', 11 => 'w-11/12', default => 'w-full',
    };
@endphp
@if ($rows->isEmpty())
    <p class="text-sm text-slate-500">{{ $empty }}</p>
@else
    <ul {{ $attributes->class('grid gap-2') }} aria-label="{{ $ariaLabel }}">
        @foreach ($rows as $key => $value)
            <li class="grid gap-1">
                <div class="flex items-baseline justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate text-slate-700">{{ $labels[$key] ?? $key }}</span>
                    <span class="shrink-0 tabular-nums text-slate-900"><span class="font-medium">{{ number_format((int) $value, 0, ',', '.') }}</span> <span class="text-slate-500">({{ number_format((int) $value / $sum * 100, 1, ',', '.') }}%)</span></span>
                </div>
                <div class="h-2.5 w-full rounded-full bg-slate-100" role="img" aria-label="{{ $labels[$key] ?? $key }}: {{ (int) $value }}">
                    <div class="h-full rounded-full {{ $barClass((string) $key) }} {{ $widthClass((int) $value) }}"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
