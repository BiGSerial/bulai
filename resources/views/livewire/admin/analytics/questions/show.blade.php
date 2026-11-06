<x-knowledge-admin.shell :title="$question->medicine_name" :back="route('admin.analytics.questions.index')" backLabel="Perguntas">
@php
    $context = $question->treatment_context ?? [];
    $severityTone = fn (?string $severity): string => match ($severity) { 'major' => 'danger', 'moderate' => 'warning', 'minor' => 'info', 'duplicate' => 'warning', default => 'neutral' };
    $statusLabels = ['answered' => 'Respondida', 'excerpts_only' => 'Só trechos', 'no_evidence' => 'Sem trecho relevante', 'no_leaflet' => 'Sem bula cadastrada', 'not_identified' => 'Princípio ativo não identificado', 'blocked' => 'Recusada pela triagem', 'no_medicine' => 'Sem medicamento identificado'];
@endphp
    <x-knowledge-admin.page-header :badge="$statusLabels[$question->status] ?? $question->status" :badge-tone="$question->status === 'answered' ? 'success' : ($question->status === 'blocked' ? 'danger' : 'warning')" :meta="['Pergunta '.substr($question->uuid, 0, 8), $question->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i'), 'usuário #'.($question->user_id ?? '—'), $question->duration_ms !== null ? number_format($question->duration_ms, 0, ',', '.').' ms' : null]" />

    <x-knowledge-admin.section title="Pergunta e resposta">
        <div class="grid gap-2 lg:grid-cols-2">
            <div class="grid content-start gap-1 rounded-lg bg-slate-50 px-3 py-2"><p class="text-xs font-semibold text-slate-600">Pergunta do usuário</p><p class="text-sm text-slate-900">{{ $question->question }}</p></div>
            <div class="grid content-start gap-1 rounded-lg bg-brand-50 px-3 py-2"><p class="text-xs font-semibold text-brand-800">Resposta exibida</p><p class="text-sm leading-relaxed text-slate-900">{{ $question->answer ?? '— (não houve resposta em linguagem simples)' }}</p></div>
        </div>
        <x-knowledge-admin.facts class="sm:grid-cols-4">
            <x-knowledge-admin.fact label="Medicamento">{{ $question->medicine_name }}@if ($question->medicine_id) (catálogo #{{ $question->medicine_id }})@endif</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Resultado">{{ $question->status }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Triagem">{{ ! empty($question->guard['flags']) ? ($question->guard['category'] ?? '—').' · '.implode(', ', $question->guard['flags']) : 'Sem sinalização' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Expira em">{{ $question->expires_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.section>

    <x-knowledge-admin.card class="grid gap-4 !p-0">
        <x-knowledge-admin.tabs class="px-2" :tabs="[['key' => 'contexto', 'label' => 'Contexto de tratamento', 'count' => ($context['consented'] ?? false) ? count($context['interactions'] ?? []) : null], ['key' => 'geracao', 'label' => 'Geração e recuperação'], ['key' => 'evidencias', 'label' => 'Evidências', 'count' => count($question->evidences ?? [])]]" :active="$tab" model="tab" />

        <div id="painel-contexto" role="tabpanel" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'contexto') hidden @endif>
            @if (! ($context['consented'] ?? false))
                <p class="text-sm text-slate-500">Sem consentimento de dados de saúde: nenhum contexto de tratamento foi usado.</p>
            @else
                <x-knowledge-admin.facts class="sm:grid-cols-3">
                    <x-knowledge-admin.fact label="Medicamento em uso">{{ ($context['in_use'] ?? false) ? 'sim' : 'não' }}@if (! empty($context['posology']['dosage']) || ! empty($context['posology']['frequency'])) · {{ trim(($context['posology']['dosage'] ?? '').' '.($context['posology']['frequency'] ?? '')) }}@endif</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Medicamentos em uso">{{ $context['medications_count'] ?? 0 }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Maior gravidade">{{ $context['highest_severity'] ?? '—' }}</x-knowledge-admin.fact>
                </x-knowledge-admin.facts>
                <div class="flex flex-wrap gap-2">
                    @foreach ($context['interactions'] ?? [] as $interaction)
                        <x-knowledge-admin.badge :tone="$severityTone($interaction['severity'] ?? null)">{{ $interaction['medicine'] }}: {{ $interaction['severity_label'] ?? $interaction['severity'] }}</x-knowledge-admin.badge>
                    @endforeach
                    @foreach ($context['allergy_alerts'] ?? [] as $alert)
                        <x-knowledge-admin.badge tone="danger">Alergia a {{ $alert['allergy'] }} ({{ $alert['via'] }}: {{ $alert['ingredient'] }})</x-knowledge-admin.badge>
                    @endforeach
                </div>
                @if (! empty($context['unresolved']))<p class="text-xs text-slate-600">Não verificados (princípio ativo não identificado): {{ implode(', ', $context['unresolved']) }}</p>@endif
                <p class="text-xs text-slate-500">Fatos determinísticos do motor estruturado (medicamentos em uso, alergias e interações DDInter), entregues ao modelo como evidência citável.</p>
            @endif
        </div>

        <div id="painel-geracao" role="tabpanel" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'geracao') hidden @endif>
            <x-knowledge-admin.generation-facts :generation="$question->generation" :retrieval="$question->retrieval" />
            @if ($question->pipeline)<p class="text-xs text-slate-500">Pipeline: {{ collect($question->pipeline)->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'sim' : 'não') : (is_array($v) ? json_encode($v) : ($v ?? '—'))))->join(' · ') }}</p>@endif
        </div>

        <div id="painel-evidencias" role="tabpanel" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'evidencias') hidden @endif>
            <p class="text-xs text-slate-500">Trechos completos recuperados e quais afirmações cada um sustenta.</p>
            <x-knowledge-admin.evidences :evidences="$question->evidences ?? []" />
        </div>
    </x-knowledge-admin.card>
</x-knowledge-admin.shell>
