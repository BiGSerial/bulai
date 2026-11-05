<x-knowledge-admin.shell :title="collect($consultation->medicines)->pluck('name')->join(' + ')" :subtitle="'Consulta '.substr($consultation->uuid, 0, 8).' · '.$consultation->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i').' · usuário #'.($consultation->user_id ?? '—')" :back="route('admin.analytics.consultations.index')" backLabel="Consultas">
@php
    $pipeline = $consultation->pipeline ?? [];
    $severityTone = fn (?string $severity): string => match ($severity) { 'major' => 'danger', 'moderate' => 'warning', 'minor' => 'info', default => 'neutral' };
@endphp
    <x-slot:actions>
        <x-knowledge-admin.status-badge :status="$consultation->status" />
        @if ($consultation->error_code)<x-knowledge-admin.badge tone="danger">{{ $consultation->error_code }}</x-knowledge-admin.badge>@endif
    </x-slot:actions>

    <x-knowledge-admin.facts class="sm:grid-cols-4">
        <x-knowledge-admin.fact label="Duração total">{{ $consultation->duration_ms !== null ? number_format($consultation->duration_ms, 0, ',', '.').' ms' : '—' }}</x-knowledge-admin.fact>
        <x-knowledge-admin.fact label="Recuperação">{{ $pipeline['retrieval_mode'] ?? '—' }}</x-knowledge-admin.fact>
        <x-knowledge-admin.fact label="Provedor de texto / vetorial">{{ $pipeline['text_provider'] ?? 'nenhum' }} / {{ $pipeline['embedding_provider'] ?? 'nenhum' }}</x-knowledge-admin.fact>
        <x-knowledge-admin.fact label="Pares analisados">{{ $pairs->count() }}</x-knowledge-admin.fact>
    </x-knowledge-admin.facts>

    @forelse ($pairs as $pair)
        <x-knowledge-admin.ops-disclosure :title="$pair->label_a.' + '.$pair->label_b" :summary="($pair->verdict?->label() ?? '—').($pair->severity ? ' · gravidade '.$pair->severity->label() : '').($pair->explanation ? ' · com explicação' : ' · sem explicação')" :open="$loop->first" wire:key="pair-{{ $pair->id }}">
            <div class="flex flex-wrap gap-2">
                <x-knowledge-admin.badge :tone="$pair->verdict?->isConclusive() ? 'success' : 'warning'">{{ $pair->verdict?->value }}</x-knowledge-admin.badge>
                @if ($pair->severity)<x-knowledge-admin.badge :tone="$severityTone($pair->severity->value)">Gravidade: {{ $pair->severity->label() }} ({{ $pair->severity_source }})</x-knowledge-admin.badge>@endif
                @if ($pair->error_code)<x-knowledge-admin.badge tone="danger">{{ $pair->error_code }}</x-knowledge-admin.badge>@endif
            </div>

            @if ($pair->explanation)
                <div class="grid gap-1 rounded-lg bg-brand-50 px-3 py-2">
                    <h3 class="text-sm font-semibold text-brand-800">Explicação exibida ao paciente</h3>
                    <p class="text-sm leading-relaxed text-slate-800">{{ $pair->explanation }}</p>
                </div>
            @endif

            @if ($pair->structured)
                <p class="text-xs text-slate-600"><span class="font-semibold">Base estruturada:</span> {{ collect($pair->structured['variants'] ?? [])->map(fn ($v) => $v['drug_a'].' × '.$v['drug_b'].' ('.$v['severity'].')')->join('; ') ?: '—' }}@if (! empty($pair->structured['divergent_severities'])) · gravidades divergentes @endif · Proveniência: {{ collect($pair->structured['provenance'] ?? [])->map(fn ($p) => $p['source'].($p['file'] ? ' ('.$p['file'].')' : ''))->unique()->join('; ') ?: '—' }}</p>
            @endif

            @if (! empty($pair->limitations))
                <ul class="grid gap-1 text-xs text-slate-600" aria-label="Limitações">
                    @foreach ($pair->limitations as $limitation)<li>• {{ $limitation }}</li>@endforeach
                </ul>
            @endif

            <x-knowledge-admin.ops-disclosure title="Geração, recuperação e tempos" :summary="'Status '.($pair->generation['status'] ?? '—').' · '.($pair->generation['claims_accepted'] ?? '—').' afirmações aceitas · '.($pair->generation['claims_rejected'] ?? '—').' rejeitadas'">
                <x-knowledge-admin.generation-facts :generation="$pair->generation" :retrieval="$pair->retrieval" :timings="$pair->timings" />
            </x-knowledge-admin.ops-disclosure>

            <x-knowledge-admin.ops-disclosure :title="'Evidências ('.count($pair->evidences ?? []).')'" summary="Trechos completos recuperados das bulas e quais afirmações cada um sustenta.">
                <x-knowledge-admin.evidences :evidences="$pair->evidences ?? []" />
            </x-knowledge-admin.ops-disclosure>
        </x-knowledge-admin.ops-disclosure>
    @empty
        <x-knowledge-admin.empty-state>Nenhum par analisado ainda.</x-knowledge-admin.empty-state>
    @endforelse

    <x-knowledge-admin.ops-disclosure title="Pipeline" summary="Configuração vigente no momento da análise.">
        <x-knowledge-admin.facts class="sm:grid-cols-3">
            <x-knowledge-admin.fact label="Identificador da execução">{{ $consultation->run_id ?? '—' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Geração habilitada">{{ ($pipeline['generation_enabled'] ?? false) ? 'sim' : 'não' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Versão do prompt">{{ $pipeline['prompt_version'] ?? '—' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Versão do código">{{ $pipeline['code_version'] ?? '—' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Expira em">{{ $consultation->expires_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Medicamentos">{{ collect($consultation->medicines)->map(fn ($m) => $m['name'].' ('.(collect($m['ingredients'] ?? [])->pluck('name')->join(', ') ?: 'princípio ativo não identificado').')')->join('; ') }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.ops-disclosure>
</x-knowledge-admin.shell>
