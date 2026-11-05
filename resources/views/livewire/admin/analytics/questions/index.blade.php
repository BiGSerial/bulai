<x-knowledge-admin.shell title="Perguntas respondidas" subtitle="O que foi perguntado, o que a IA respondeu e em que evidências se apoiou. Usuários aparecem só pelo número." :back="route('admin.analytics.index')" backLabel="Analytics">
@php
    $statusLabels = ['answered' => 'Respondida', 'excerpts_only' => 'Só trechos', 'no_evidence' => 'Sem trecho relevante', 'no_leaflet' => 'Sem bula cadastrada', 'not_identified' => 'Princípio ativo não identificado', 'blocked' => 'Recusada pela triagem', 'no_medicine' => 'Sem medicamento identificado'];
    $tone = fn (string $status): string => match ($status) { 'answered' => 'success', 'excerpts_only' => 'info', 'blocked' => 'danger', default => 'warning' };
@endphp
    <x-knowledge-admin.ops-filters :count="$questions->total().' '.($questions->total() === 1 ? 'pergunta' : 'perguntas')">
        <x-knowledge-admin.select label="Resultado" wire:model.live="status" wrapper-class="w-full sm:max-w-xs">
            <option value="">Todos</option>
            @foreach ($statuses as $value)<option value="{{ $value }}">{{ $statusLabels[$value] ?? $value }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.ops-filters>

    @if ($questions->isEmpty())
        <x-knowledge-admin.empty-state>Nenhuma pergunta registrada com este filtro.</x-knowledge-admin.empty-state>
    @else
        <ul class="grid gap-2" wire:loading.class="opacity-60" wire:target="status">
            @foreach ($questions as $question)
                @php
                    $context = $question->treatment_context ?? [];
                    $treatment = ($context['consented'] ?? false) ? (($context['in_use'] ?? false) ? 'em uso' : 'não em uso').(! empty($context['interactions']) ? ' · '.count($context['interactions']).' interação(ões)' : '') : 'sem dados de tratamento';
                @endphp
                <x-knowledge-admin.ops-row :title="$question->medicine_name.': “'.\Illuminate\Support\Str::limit($question->question, 90).'”'" :href="route('admin.analytics.questions.show', $question)" :support="$question->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i').' · usuário #'.($question->user_id ?? '—').' · '.count($question->evidences ?? []).' evidências · '.$treatment.($question->duration_ms !== null ? ' · '.number_format($question->duration_ms, 0, ',', '.').' ms' : '')" wire:key="q-{{ $question->uuid }}">
                    <x-knowledge-admin.badge :tone="$tone($question->status)">{{ $statusLabels[$question->status] ?? $question->status }}</x-knowledge-admin.badge>
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.questions.show', $question)">Abrir</x-knowledge-admin.button>
                </x-knowledge-admin.ops-row>
            @endforeach
        </ul>
        {{ $questions->links() }}
    @endif
</x-knowledge-admin.shell>
