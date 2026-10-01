@props(['label', 'value', 'href'])
<a class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-300 hover:shadow" href="{{ $href }}" wire:navigate><p class="text-sm font-medium text-slate-600">{{ $label }}</p><p class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ $value }}</p></a>
