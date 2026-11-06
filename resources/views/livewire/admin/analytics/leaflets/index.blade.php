<x-knowledge-admin.shell title="Analytics" subtitle="Resumos em linguagem simples gerados por conjunto de princípios ativos, com as evidências de cada afirmação.">
@php
    $statusLabels = ['generated' => 'Gerada', 'partial' => 'Parcial', 'invalid' => 'Rejeitada na validação', 'unavailable' => 'Indisponível'];
    $tone = fn (string $status): string => match ($status) { 'generated' => 'success', 'partial' => 'info', default => 'warning' };
    $abas = [['key' => 'visao', 'label' => 'Visão geral', 'href' => route('admin.analytics.index')], ['key' => 'consultas', 'label' => 'Consultas', 'href' => route('admin.analytics.consultations.index')], ['key' => 'perguntas', 'label' => 'Perguntas', 'href' => route('admin.analytics.questions.index')], ['key' => 'bulas', 'label' => 'Bulas humanizadas', 'href' => route('admin.analytics.leaflets.index')]];
@endphp
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Seções do Analytics">
        @foreach ($abas as $aba)
            <a href="{{ $aba['href'] }}" wire:navigate class="-mb-px inline-flex shrink-0 items-center border-b-2 px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $aba['key'] === 'bulas' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" @if ($aba['key'] === 'bulas') aria-current="page" @endif>{{ $aba['label'] }}</a>
        @endforeach
    </nav>

    <x-knowledge-admin.toolbar :count="$summaries->total().' '.($summaries->total() === 1 ? 'bula humanizada' : 'bulas humanizadas')">
        <x-knowledge-admin.select label="Situação" compact wire:model.live="status" wrapper-class="w-full sm:w-56">
            <option value="">Todas as situações</option>
            @foreach ($statuses as $value)<option value="{{ $value }}">{{ $statusLabels[$value] ?? $value }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>

    <div wire:loading.class="opacity-60" wire:target="status">
        <x-knowledge-admin.data-table :columns="[['label' => 'Princípios ativos'], ['label' => 'Gerada em'], ['label' => 'Seções', 'align' => 'right'], ['label' => 'Evidências', 'align' => 'right'], ['label' => 'Visualizações', 'align' => 'right'], ['label' => 'Duração', 'align' => 'right'], ['label' => 'Situação'], ['label' => 'Ações', 'align' => 'right']]">
            @foreach ($summaries as $summary)
                <tr wire:key="l-{{ $summary->uuid }}">
                    <td><a class="rounded hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.analytics.leaflets.show', $summary) }}" wire:navigate>Princípios ativos {{ $summary->ingredient_key }}</a><span class="block text-xs font-normal text-slate-400">{{ substr($summary->uuid, 0, 8) }}</span></td>
                    <td class="tabular text-slate-600">{{ $summary->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                    <td class="tabular">{{ count($summary->sections ?? []) }}</td>
                    <td class="tabular">{{ count($summary->evidences ?? []) }}</td>
                    <td class="tabular">{{ $summary->views }}</td>
                    <td class="tabular text-slate-600">{{ $summary->duration_ms !== null ? number_format($summary->duration_ms, 0, ',', '.').' ms' : '—' }}</td>
                    <td><x-knowledge-admin.badge :tone="$tone($summary->status)">{{ $statusLabels[$summary->status] ?? $summary->status }}</x-knowledge-admin.badge></td>
                    <td><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.analytics.leaflets.show', $summary)">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                </tr>
            @endforeach
            @if ($summaries->isEmpty())
                <x-slot:empty>Nenhuma bula humanizada gerada ainda.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>
    </div>
    {{ $summaries->links() }}
</x-knowledge-admin.shell>
