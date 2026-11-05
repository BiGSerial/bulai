<x-knowledge-admin.shell :title="$question->medicine_name" :subtitle="'Pergunta '.substr($question->uuid, 0, 8).' · '.$question->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i').' · usuário #'.($question->user_id ?? '—')" :back="route('admin.analytics.questions.index')" backLabel="Perguntas">
@php
    $context = $question->treatment_context ?? [];
    $severityTone = fn (?string $severity): string => match ($severity) { 'major' => 'danger', 'moderate' => 'warning', 'minor' => 'info', 'duplicate' => 'warning', default => 'neutral' };
    $statusLabels = ['answered' => 'Respondida', 'excerpts_only' => 'Só trechos', 'no_evidence' => 'Sem trecho relevante', 'no_leaflet' => 'Sem bula cadastrada', 'not_identified' => 'Princípio ativo não identificado', 'blocked' => 'Recusada pela triagem', 'no_medicine' => 'Sem medicamento identificado'];
@endphp
    <x-slot:actions>
        <x-knowledge-admin.badge :tone="$question->status === 'answered' ? 'success' : ($question->status === 'blocked' ? 'danger' : 'warning')">{{ $statusLabels[$question->status] ?? $question->status }}</x-knowledge-admin.badge>
    </x-slot:actions>

    <x-knowledge-admin.section title="Pergunta e resposta">
        <div class="grid gap-1 rounded-lg bg-slate-50 px-3 py-2"><p class="text-xs font-semibold text-slate-600">Pergunta do usuário</p><p class="text-sm text-slate-900">{{ $question->question }}</p></div>
        <div class="grid gap-1 rounded-lg bg-brand-50 px-3 py-2"><p class="text-xs font-semibold text-brand-800">Resposta exibida</p><p class="text-sm leading-relaxed text-slate-900">{{ $question->answer ?? '— (não houve resposta em linguagem simples)' }}</p></div>
        <x-knowledge-admin.facts class="sm:grid-cols-4">
            <x-knowledge-admin.fact label="Medicamento">{{ $question->medicine_name }}@if ($question->medicine_id) (catálogo #{{ $question->medicine_id }})@endif</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Resultado">{{ $question->status }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Duração">{{ $question->duration_ms !== null ? number_format($question->duration_ms, 0, ',', '.').' ms' : '—' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Expira em">{{ $question->expires_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
        @if (! empty($question->guard['flags']))<p class="text-xs text-slate-600"><span class="font-semibold">Triagem:</span> {{ $question->guard['category'] ?? '—' }} · {{ implode(', ', $question->guard['flags']) }}</p>@endif
    </x-knowledge-admin.section>

    <x-knowledge-admin.ops-disclosure title="Contexto de tratamento usado" :summary="($context['consented'] ?? false) ? ((($context['in_use'] ?? false) ? 'medicamento em uso' : 'medicamento não em uso').' · '.count($context['interactions'] ?? []).' interação(ões) · '.count($context['allergy_alerts'] ?? []).' alergia(s)') : 'Sem consentimento de dados de saúde: nenhum contexto foi usado.'" :open="($context['consented'] ?? false)">
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
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.ops-disclosure title="Geração e recuperação" :summary="'Status '.($question->generation['status'] ?? '—').' · '.($question->generation['claims_accepted'] ?? '—').' afirmações aceitas · '.($question->generation['claims_rejected'] ?? '—').' rejeitadas'">
        <x-knowledge-admin.generation-facts :generation="$question->generation" :retrieval="$question->retrieval" />
        @if ($question->pipeline)<p class="text-xs text-slate-500">Pipeline: {{ collect($question->pipeline)->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'sim' : 'não') : (is_array($v) ? json_encode($v) : ($v ?? '—'))))->join(' · ') }}</p>@endif
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.ops-disclosure :title="'Evidências ('.count($question->evidences ?? []).')'" summary="Trechos completos recuperados e quais afirmações cada um sustenta.">
        <x-knowledge-admin.evidences :evidences="$question->evidences ?? []" />
    </x-knowledge-admin.ops-disclosure>
</x-knowledge-admin.shell>
