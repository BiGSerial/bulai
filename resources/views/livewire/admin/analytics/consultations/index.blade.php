<x-knowledge-admin.shell title="Analytics" subtitle="Cada consulta de interação respondida ao paciente, com tudo o que foi usado. Usuários aparecem só pelo número.">
@php
    $statusLabels = ['COMPLETED' => 'Concluída', 'FAILED' => 'Falhou', 'RUNNING' => 'Em análise', 'PENDING' => 'Na fila'];
    $abas = [['key' => 'visao', 'label' => 'Visão geral', 'href' => route('admin.analytics.index')], ['key' => 'consultas', 'label' => 'Consultas', 'href' => route('admin.analytics.consultations.index')], ['key' => 'perguntas', 'label' => 'Perguntas', 'href' => route('admin.analytics.questions.index')], ['key' => 'bulas', 'label' => 'Bulas humanizadas', 'href' => route('admin.analytics.leaflets.index')]];
@endphp
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Seções do Analytics">
        @foreach ($abas as $aba)
            <a href="{{ $aba['href'] }}" wire:navigate class="-mb-px inline-flex shrink-0 items-center border-b-2 px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $aba['key'] === 'consultas' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" @if ($aba['key'] === 'consultas') aria-current="page" @endif>{{ $aba['label'] }}</a>
        @endforeach
    </nav>

    <x-knowledge-admin.toolbar :count="$consultations->total().' '.($consultations->total() === 1 ? 'consulta' : 'consultas')">
        <x-knowledge-admin.select label="Situação" compact wire:model.live="status" wrapper-class="w-full sm:w-48">
            <option value="">Todas as situações</option>
            @foreach ($statuses as $value)<option value="{{ $value }}">{{ $statusLabels[$value] ?? $value }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>

    <div wire:loading.class="opacity-60" wire:target="status">
        <x-knowledge-admin.data-table :columns="[['label' => 'Medicamentos'], ['label' => 'Quando'], ['label' => 'Usuário'], ['label' => 'Pares', 'align' => 'right'], ['label' => 'Recuperação'], ['label' => 'Duração', 'align' => 'right'], ['label' => 'Situação'], ['label' => 'Ações', 'align' => 'right']]">
            @foreach ($consultations as $consultation)
                <tr wire:key="c-{{ $consultation->uuid }}">
                    <td class="max-w-xs"><a class="block truncate rounded hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.analytics.consultations.show', $consultation) }}" wire:navigate title="{{ collect($consultation->medicines)->pluck('name')->join(' + ') }}">{{ collect($consultation->medicines)->pluck('name')->join(' + ') }}</a><span class="block text-xs font-normal text-slate-400">{{ substr($consultation->uuid, 0, 8) }}</span></td>
                    <td class="tabular text-slate-600">{{ $consultation->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                    <td class="text-slate-600">#{{ $consultation->user_id ?? '—' }}</td>
                    <td class="tabular">{{ $consultation->pair_count }}</td>
                    <td class="text-slate-600">{{ $consultation->pipeline['retrieval_mode'] ?? '—' }}</td>
                    <td class="tabular text-slate-600">{{ $consultation->duration_ms !== null ? number_format($consultation->duration_ms, 0, ',', '.').' ms' : '—' }}</td>
                    <td><x-knowledge-admin.status-badge :status="$consultation->status" /></td>
                    <td><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.analytics.consultations.show', $consultation)">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                </tr>
            @endforeach
            @if ($consultations->isEmpty())
                <x-slot:empty>Nenhuma consulta encontrada com este filtro.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>
    </div>
    {{ $consultations->links() }}
</x-knowledge-admin.shell>
