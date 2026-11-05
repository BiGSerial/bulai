<x-knowledge-admin.shell title="Consultas de interação" subtitle="Cada consulta respondida ao paciente, com tudo o que foi usado. Usuários aparecem só pelo número." :back="route('admin.analytics.index')" backLabel="Analytics">
@php
    $statusLabels = ['COMPLETED' => 'Concluída', 'FAILED' => 'Falhou', 'RUNNING' => 'Em análise', 'PENDING' => 'Na fila'];
@endphp
    <x-knowledge-admin.ops-filters :count="$consultations->total().' '.($consultations->total() === 1 ? 'consulta' : 'consultas')">
        <x-knowledge-admin.select label="Situação" wire:model.live="status" wrapper-class="w-full sm:max-w-xs">
            <option value="">Todas</option>
            @foreach ($statuses as $value)<option value="{{ $value }}">{{ $statusLabels[$value] ?? $value }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.ops-filters>

    @if ($consultations->isEmpty())
        <x-knowledge-admin.empty-state>Nenhuma consulta encontrada com este filtro.</x-knowledge-admin.empty-state>
    @else
        <ul class="grid gap-2" wire:loading.class="opacity-60" wire:target="status">
            @foreach ($consultations as $consultation)
                <x-knowledge-admin.ops-row :title="collect($consultation->medicines)->pluck('name')->join(' + ')" :href="route('admin.analytics.consultations.show', $consultation)" :support="$consultation->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i').' · usuário #'.($consultation->user_id ?? '—').' · '.$consultation->pair_count.' '.($consultation->pair_count === 1 ? 'par' : 'pares').' · '.($consultation->pipeline['retrieval_mode'] ?? '—').($consultation->duration_ms !== null ? ' · '.number_format($consultation->duration_ms, 0, ',', '.').' ms' : '').' · '.substr($consultation->uuid, 0, 8)" wire:key="c-{{ $consultation->uuid }}">
                    <x-knowledge-admin.status-badge :status="$consultation->status" />
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.consultations.show', $consultation)">Abrir</x-knowledge-admin.button>
                </x-knowledge-admin.ops-row>
            @endforeach
        </ul>
        {{ $consultations->links() }}
    @endif
</x-knowledge-admin.shell>
