{{-- Selo de situação (sempre cor + texto). tone: neutral | brand | accent | success | warning | danger --}}
@props(['tone' => 'neutral'])
@php
    $classes = match ($tone) {
        'brand' => 'bg-brand-50 text-brand-900 ring-brand-600/20',
        'accent' => 'bg-accent-100 text-accent-800 ring-accent-300',
        'success' => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
        'warning' => 'bg-amber-100 text-amber-900 ring-amber-300',
        'danger' => 'bg-rose-100 text-rose-900 ring-rose-300',
        default => 'bg-slate-100 text-slate-800 ring-slate-300',
    };
@endphp
<span {{ $attributes->class("inline-flex max-w-full items-center gap-1 rounded-full px-3 py-1 text-base font-semibold leading-tight ring-1 ring-inset break-words {$classes}") }}>{{ $slot }}</span>
