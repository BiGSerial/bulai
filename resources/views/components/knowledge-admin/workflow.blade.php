{{--
    Fluxo de trabalho em etapas numeradas (1 Adicionar → 2 Importações → 3 Pendências → 4 Documentos).
    `steps`: lista de ['label', 'href', 'hint' => null, 'count' => null, 'state' => 'done'|'current'|'todo'].
--}}
@props(['steps' => []])
<ol {{ $attributes->class('grid gap-2 sm:grid-cols-2 lg:grid-cols-4') }} aria-label="Fluxo de trabalho">
    @foreach ($steps as $index => $step)
        @php
            $state = $step['state'] ?? 'todo';
            [$circle, $ring] = match ($state) {
                'done' => ['bg-emerald-600 text-white', 'ring-emerald-200'],
                'current' => ['bg-brand-700 text-white', 'ring-brand-600'],
                default => ['bg-slate-200 text-slate-700', 'ring-slate-200'],
            };
        @endphp
        <li class="min-w-0">
            <a class="flex min-h-14 items-center gap-3 rounded-lg bg-white px-3 py-2 ring-1 transition hover:ring-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $ring }}" href="{{ $step['href'] }}" wire:navigate @if ($state === 'current') aria-current="step" @endif>
                <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums {{ $circle }}" aria-hidden="true">{{ $index + 1 }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-slate-900">{{ $step['label'] }}</span>
                    @if (! empty($step['hint']))<span class="block truncate text-xs text-slate-500">{{ $step['hint'] }}</span>@endif
                </span>
                @if (($step['count'] ?? null) !== null)<span class="shrink-0 text-base font-semibold tabular-nums {{ $state === 'current' ? 'text-brand-800' : 'text-slate-700' }}">{{ $step['count'] }}</span>@endif
            </a>
        </li>
    @endforeach
</ol>
