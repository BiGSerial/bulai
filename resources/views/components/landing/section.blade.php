@props(['id', 'eyebrow' => null, 'title', 'lead' => null, 'tone' => 'white', 'align' => 'center'])
@php
    $background = match ($tone) {
        'surface' => 'bg-surface-100',
        'brand' => 'bg-brand-800 text-white',
        default => 'bg-white',
    };
    $muted = $tone === 'brand' ? 'text-brand-100' : 'text-slate-700';
    $heading = $tone === 'brand' ? 'text-white' : 'text-brand-900';
    $eyebrowTone = $tone === 'brand' ? 'text-accent-200' : 'text-accent-700';
@endphp
<section id="{{ $id }}" {{ $attributes->class(['scroll-mt-20 py-16 sm:py-24', $background]) }} aria-labelledby="{{ $id }}-titulo">
    <div class="mx-auto max-w-6xl px-4">
        <div class="{{ $align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-3xl' }}">
            @if ($eyebrow)<p class="text-sm font-bold uppercase tracking-wider {{ $eyebrowTone }}">{{ $eyebrow }}</p>@endif
            <h2 id="{{ $id }}-titulo" class="mt-2 text-3xl font-semibold leading-tight sm:text-4xl {{ $heading }}">{{ $title }}</h2>
            @if ($lead)<p class="mt-4 text-lg leading-relaxed {{ $muted }}">{{ $lead }}</p>@endif
        </div>
        <div class="mt-10 sm:mt-14">{{ $slot }}</div>
    </div>
</section>
