{{-- Cartão padrão do app do paciente. tone: neutral | brand | accent | warning | danger | success --}}
@props(['tone' => 'neutral', 'as' => 'section'])
@php
    $toneClasses = match ($tone) {
        'brand' => 'border-brand-600/30 bg-brand-50',
        'accent' => 'border-accent-300 bg-accent-50',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-900',
        'danger' => 'border-rose-300 bg-rose-50 text-rose-900',
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-900',
        default => 'border-slate-200 bg-white shadow-sm',
    };
@endphp
<{{ $as }} {{ $attributes->class("grid min-w-0 gap-3 rounded-2xl border p-4 sm:p-5 {$toneClasses}") }}>{{ $slot }}</{{ $as }}>
