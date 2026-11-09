<x-knowledge-admin.shell title="Analytics" subtitle="O que foi perguntado, o que a IA respondeu e em que evidências se apoiou. Usuários aparecem só pelo número.">
@php
    $statusLabels = ['answered' => 'Respondida', 'excerpts_only' => 'Só trechos', 'no_evidence' => 'Sem trecho relevante', 'no_leaflet' => 'Sem bula cadastrada', 'not_identified' => 'Princípio ativo não identificado', 'blocked' => 'Recusada pela triagem', 'no_medicine' => 'Sem medicamento identificado'];
    $tone = fn (string $status): string => match ($status) { 'answered' => 'success', 'excerpts_only' => 'info', 'blocked' => 'danger', default => 'warning' };
    $abas = [['key' => 'visao', 'label' => 'Visão geral', 'href' => route('admin.analytics.index')], ['key' => 'consultas', 'label' => 'Consultas', 'href' => route('admin.analytics.consultations.index')], ['key' => 'perguntas', 'label' => 'Perguntas', 'href' => route('admin.analytics.questions.index')], ['key' => 'bulas', 'label' => 'Bulas humanizadas', 'href' => route('admin.analytics.leaflets.index')], ['key' => 'consumo', 'label' => 'Consumo de IA', 'href' => route('admin.analytics.ai-usage.index')]];
@endphp
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Seções do Analytics">
        @foreach ($abas as $aba)
            <a href="{{ $aba['href'] }}" wire:navigate class="-mb-px inline-flex shrink-0 items-center border-b-2 px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $aba['key'] === 'perguntas' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" @if ($aba['key'] === 'perguntas') aria-current="page" @endif>{{ $aba['label'] }}</a>
        @endforeach
    </nav>

    <x-knowledge-admin.toolbar :count="$questions->total().' '.($questions->total() === 1 ? 'pergunta' : 'perguntas')">
        <x-knowledge-admin.select label="Resultado" compact wire:model.live="status" wrapper-class="w-full sm:w-64">
            <option value="">Todos os resultados</option>
            @foreach ($statuses as $value)<option value="{{ $value }}">{{ $statusLabels[$value] ?? $value }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>

    <div wire:loading.class="opacity-60" wire:target="status">
        <x-knowledge-admin.data-table :columns="[['label' => 'Medicamento'], ['label' => 'Pergunta'], ['label' => 'Quando'], ['label' => 'Usuário'], ['label' => 'Evidências', 'align' => 'right'], ['label' => 'Tratamento'], ['label' => 'Duração', 'align' => 'right'], ['label' => 'Resultado'], ['label' => 'Ações', 'align' => 'right']]">
            @foreach ($questions as $question)
                @php
                    $context = $question->treatment_context ?? [];
                    $treatment = ($context['consented'] ?? false) ? (($context['in_use'] ?? false) ? 'em uso' : 'não em uso').(! empty($context['interactions']) ? ' · '.count($context['interactions']).' interação(ões)' : '') : 'sem dados';
                @endphp
                <tr wire:key="q-{{ $question->uuid }}">
                    <td><a class="rounded hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.analytics.questions.show', $question) }}" wire:navigate>{{ $question->medicine_name }}</a></td>
                    <td class="max-w-md !whitespace-normal text-slate-700" title="{{ $question->question }}">“{{ \Illuminate\Support\Str::limit($question->question, 90) }}”</td>
                    <td class="tabular text-slate-600">{{ $question->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                    <td class="text-slate-600">#{{ $question->user_id ?? '—' }}</td>
                    <td class="tabular">{{ count($question->evidences ?? []) }}</td>
                    <td class="text-slate-600">{{ $treatment }}</td>
                    <td class="tabular text-slate-600">{{ $question->duration_ms !== null ? number_format($question->duration_ms, 0, ',', '.').' ms' : '—' }}</td>
                    <td><x-knowledge-admin.badge :tone="$tone($question->status)">{{ $statusLabels[$question->status] ?? $question->status }}</x-knowledge-admin.badge></td>
                    <td><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.analytics.questions.show', $question)">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                </tr>
            @endforeach
            @if ($questions->isEmpty())
                <x-slot:empty>Nenhuma pergunta registrada com este filtro.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>
    </div>
    {{ $questions->links() }}
</x-knowledge-admin.shell>
