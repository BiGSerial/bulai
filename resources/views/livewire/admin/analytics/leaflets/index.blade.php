<x-knowledge-admin.shell title="Bulas humanizadas" subtitle="Resumos em linguagem simples gerados por conjunto de princípios ativos, com as evidências de cada afirmação." :back="route('admin.analytics.index')" backLabel="Analytics">
@php
    $statusLabels = ['generated' => 'Gerada', 'partial' => 'Parcial', 'invalid' => 'Rejeitada na validação', 'unavailable' => 'Indisponível'];
    $tone = fn (string $status): string => match ($status) { 'generated' => 'success', 'partial' => 'info', default => 'warning' };
@endphp
    <x-knowledge-admin.ops-filters :count="$summaries->total().' '.($summaries->total() === 1 ? 'bula humanizada' : 'bulas humanizadas')">
        <x-knowledge-admin.select label="Situação" wire:model.live="status" wrapper-class="w-full sm:max-w-xs">
            <option value="">Todas</option>
            @foreach ($statuses as $value)<option value="{{ $value }}">{{ $statusLabels[$value] ?? $value }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.ops-filters>

    @if ($summaries->isEmpty())
        <x-knowledge-admin.empty-state>Nenhuma bula humanizada gerada ainda.</x-knowledge-admin.empty-state>
    @else
        <ul class="grid gap-2" wire:loading.class="opacity-60" wire:target="status">
            @foreach ($summaries as $summary)
                <x-knowledge-admin.ops-row :title="'Princípios ativos '.$summary->ingredient_key" :href="route('admin.analytics.leaflets.show', $summary)" :support="$summary->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i').' · '.count($summary->sections ?? []).' seções · '.count($summary->evidences ?? []).' evidências · '.$summary->views.' visualizações'.($summary->duration_ms !== null ? ' · '.number_format($summary->duration_ms, 0, ',', '.').' ms' : '').' · '.substr($summary->uuid, 0, 8)" wire:key="l-{{ $summary->uuid }}">
                    <x-knowledge-admin.badge :tone="$tone($summary->status)">{{ $statusLabels[$summary->status] ?? $summary->status }}</x-knowledge-admin.badge>
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.leaflets.show', $summary)">Abrir</x-knowledge-admin.button>
                </x-knowledge-admin.ops-row>
            @endforeach
        </ul>
        {{ $summaries->links() }}
    @endif
</x-knowledge-admin.shell>
