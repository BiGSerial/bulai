@props(['severity'])
@php
    $value = $severity instanceof \App\Services\Interactions\InteractionSeverity ? $severity : \App\Services\Interactions\InteractionSeverity::tryFrom((string) $severity);
    [$square, $text] = match ($value) {
        \App\Services\Interactions\InteractionSeverity::Major => ['bg-rose-600', 'text-rose-800'],
        \App\Services\Interactions\InteractionSeverity::Moderate => ['bg-amber-500', 'text-amber-900'],
        \App\Services\Interactions\InteractionSeverity::Minor => ['bg-yellow-300', 'text-yellow-900'],
        default => ['bg-slate-400', 'text-slate-700'],
    };
@endphp
@if ($value)
    <span {{ $attributes->class("inline-flex items-center gap-2 text-sm font-semibold {$text}") }}><span class="size-3.5 shrink-0 rounded-sm {{ $square }}" aria-hidden="true"></span>@if ($value === \App\Services\Interactions\InteractionSeverity::Unknown){{ $value->label() }}@else Gravidade: {{ $value->label() }}@endif</span>
@endif
