{{-- Gravidade (DDInter) sempre com cor + texto. size: sm | lg --}}
@props(['severity', 'size' => 'sm'])
@php
    $value = $severity instanceof \App\Services\Interactions\InteractionSeverity ? $severity : \App\Services\Interactions\InteractionSeverity::tryFrom((string) $severity);
    [$square, $text, $tone] = match ($value) {
        \App\Services\Interactions\InteractionSeverity::Major => ['bg-rose-600', 'text-rose-800', 'danger'],
        \App\Services\Interactions\InteractionSeverity::Moderate => ['bg-amber-500', 'text-amber-900', 'warning'],
        \App\Services\Interactions\InteractionSeverity::Minor => ['bg-yellow-300', 'text-yellow-900', 'warning'],
        default => ['bg-slate-400', 'text-slate-700', 'neutral'],
    };
    $label = $value === \App\Services\Interactions\InteractionSeverity::Unknown ? $value->label() : 'Gravidade: '.$value->label();
@endphp
@if ($value)
    @if ($size === 'lg')
        <x-consulta.selo :tone="$tone" {{ $attributes->class('justify-self-start text-lg') }}><span class="size-3.5 shrink-0 rounded-sm {{ $square }}" aria-hidden="true"></span>{{ $label }}</x-consulta.selo>
    @else
        <span {{ $attributes->class("inline-flex items-center gap-2 text-sm font-semibold {$text}") }}><span class="size-3.5 shrink-0 rounded-sm {{ $square }}" aria-hidden="true"></span>{{ $label }}</span>
    @endif
@endif
