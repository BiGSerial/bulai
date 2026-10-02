@props(['title' => null, 'description' => null])
<x-knowledge-admin.card {{ $attributes->class('grid gap-4') }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                @if ($title)<h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>@endif
                @if ($description)<p class="mt-1 text-sm text-slate-600">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="flex flex-wrap gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</x-knowledge-admin.card>
