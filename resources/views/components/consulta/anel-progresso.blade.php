{{-- Anel de progresso (SVG) com "feitos/total" no centro. Cor acompanha o avanço; texto sempre junto da cor. --}}
@props(['feitos' => 0, 'total' => 0, 'rotulo' => 'doses', 'size' => 'md', 'tone' => null])
@php
    $total = max(0, (int) $total);
    $feitos = max(0, min($total, (int) $feitos));
    $pct = $total === 0 ? 0 : (int) round($feitos * 100 / $total);
    $r = 44;
    $c = 2 * M_PI * $r;
    $offset = $c - ($c * $pct / 100);
    $cor = match (true) {
        $tone !== null => $tone,
        $total === 0 => 'text-slate-300',
        $pct >= 100 => 'text-emerald-600',
        $pct >= 50 => 'text-brand-600',
        default => 'text-accent-500',
    };
    $box = match ($size) { 'sm' => 'size-20', 'lg' => 'size-36', default => 'size-28' };
    $numero = match ($size) { 'sm' => 'text-xl', 'lg' => 'text-4xl', default => 'text-3xl' };
@endphp
<div {{ $attributes->class("relative inline-grid shrink-0 place-items-center {$box}") }} role="img" aria-label="{{ $feitos }} de {{ $total }} {{ $rotulo }}">
    <svg class="absolute inset-0 size-full -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="10" class="text-slate-200" />
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" class="{{ $cor }} transition-[stroke-dashoffset] duration-700 ease-out" stroke-dasharray="{{ $c }}" stroke-dashoffset="{{ $offset }}" />
    </svg>
    <span class="relative grid place-items-center leading-none" aria-hidden="true">
        <span class="{{ $numero }} font-bold text-slate-900">{{ $feitos }}<span class="text-base font-semibold text-slate-500">/{{ $total }}</span></span>
        <span class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $rotulo }}</span>
    </span>
</div>
