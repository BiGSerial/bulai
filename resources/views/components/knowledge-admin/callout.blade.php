{{-- Aviso compacto de uma linha (título em negrito + texto), com ícone por tom. Para mensagens maiores use `alert`. --}}
@props(['tone' => 'info', 'title' => null])
@php
    [$classes, $iconClass] = match ($tone) {
        'success' => ['bg-emerald-50 text-emerald-900 ring-emerald-200', 'text-emerald-600'],
        'warning' => ['bg-amber-50 text-amber-900 ring-amber-200', 'text-amber-600'],
        'danger' => ['bg-rose-50 text-rose-900 ring-rose-200', 'text-rose-600'],
        default => ['bg-sky-50 text-sky-900 ring-sky-200', 'text-sky-600'],
    };
@endphp
<div {{ $attributes->class("flex items-start gap-2 rounded-lg px-3 py-2 text-sm ring-1 {$classes}")->merge(['role' => $tone === 'danger' ? 'alert' : 'status']) }}>
    <svg class="mt-0.5 size-4 shrink-0 stroke-2 {{ $iconClass }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
    <p class="min-w-0">@if ($title)<span class="font-semibold">{{ $title }}</span> @endif{{ $slot }}</p>
</div>
