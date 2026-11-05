{{-- Linha de lista enxuta (princípios de UX, §3): título, uma linha de apoio, um selo e uma ação. Use dentro de <ul class="grid gap-2">. --}}
@props(['title', 'href' => null, 'support' => null])
<li {{ $attributes->class('flex flex-col gap-2 rounded-lg bg-white px-3 py-2.5 ring-1 ring-slate-200 sm:flex-row sm:items-center sm:justify-between sm:gap-4') }}>
    <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-slate-900">
            @if ($href)<a class="rounded hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ $href }}" wire:navigate>{{ $title }}</a>@else{{ $title }}@endif
        </p>
        @isset($support)<p class="truncate text-xs text-slate-500">{{ $support }}</p>@endisset
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $slot }}</div>
</li>
