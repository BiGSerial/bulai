<x-knowledge-admin.shell title="Analytics" subtitle="O que a IA respondeu, em que evidências se apoiou, quanto custou e quanto demorou. Dados anonimizados.">
@php
    $c = $overview['consultations'];
    $p = $overview['pairs'];
    $q = $overview['questions'];
    $l = $overview['leaflets'];
    $ai = $overview['ai'];
    $n = fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $ms = fn ($value): string => $value === null ? '—' : number_format((float) $value, 0, ',', '.').' ms';
    $pct = fn ($rate): string => $rate === null ? '—' : number_format((float) $rate * 100, 1, ',', '.').'%';
    $verdictLabels = collect(\App\Services\Interactions\PairVerdict::cases())->mapWithKeys(fn (\App\Services\Interactions\PairVerdict $v) => [$v->value => $v->label()])->all();
    $severityLabels = collect(\App\Services\Interactions\InteractionSeverity::cases())->mapWithKeys(fn (\App\Services\Interactions\InteractionSeverity $s) => [$s->value => $s->label()])->all();
    $severityTones = ['major' => 'danger', 'moderate' => 'warning', 'minor' => 'info', 'unknown' => 'muted'];
    $generationLabels = ['generated' => 'Gerada e validada', 'invalid' => 'Rejeitada na validação', 'unavailable' => 'Não gerada (sem evidência/provedor)', 'desconhecido' => 'Sem registro'];
    $rejectionLabels = ['not_grounded_in_cited_evidence' => 'Não ancorada na evidência citada', 'forbidden_recommendation' => 'Recomendação proibida', 'unknown_evidence_reference' => 'Evidência citada inexistente', 'no_evidence_reference' => 'Sem citação de evidência', 'invalid_length' => 'Tamanho inválido', 'malformed_claim' => 'Afirmação malformada', 'no_verifiable_terms' => 'Sem termos verificáveis'];
    $questionLabels = ['answered' => 'Respondida', 'excerpts_only' => 'Só trechos', 'no_evidence' => 'Sem trecho relevante', 'no_leaflet' => 'Sem bula cadastrada', 'not_identified' => 'Princípio ativo não identificado', 'blocked' => 'Recusada pela triagem', 'no_medicine' => 'Sem medicamento identificado'];
    $leafletLabels = ['generated' => 'Gerada', 'partial' => 'Parcial', 'invalid' => 'Rejeitada na validação', 'unavailable' => 'Indisponível'];
@endphp
    <x-slot:actions>
        <x-knowledge-admin.select label="Período" wire:model.live="period" wrapper-class="w-full sm:w-auto">
            @foreach ($periods as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-slot:actions>

    <div class="grid gap-4" wire:loading.class="opacity-60" wire:target="period">
        <section class="grid grid-cols-2 gap-2 lg:grid-cols-4" aria-label="Indicadores do período">
            <x-knowledge-admin.kpi label="Consultas de interação" :value="$n($c['total'])" :hint="$n($c['pairs']).' pares · '.($c['by_status']['FAILED'] ?? 0).' falharam'" :href="route('admin.analytics.consultations.index')" />
            <x-knowledge-admin.kpi label="Perguntas" :value="$n($q['total'])" :hint="($q['by_status']['answered'] ?? 0).' respondidas em linguagem simples'" :href="route('admin.analytics.questions.index')" />
            <x-knowledge-admin.kpi label="Bulas humanizadas" :value="$n($l['total'])" :hint="$n($l['views']).' visualizações · '.$n($l['cache_hits']).' via cache'" :href="route('admin.analytics.leaflets.index')" />
            <x-knowledge-admin.kpi label="Custo estimado de IA" :value="'US$ '.$n($ai['estimated_cost_usd'], 4)" :hint="$n($ai['calls']).' chamadas · '.$n($ai['failed']).' falhas'" tone="brand" />
        </section>

        <x-knowledge-admin.ops-disclosure title="Pares de medicamentos" :summary="'Ancoragem '.$pct($p['grounding']['rate']).' · '.$p['with_explanation'].' com explicação · '.$p['with_evidence'].' com evidência'" open>
            <x-knowledge-admin.meter label="Taxa de ancoragem (afirmações aceitas pelo validador)" :rate="$p['grounding']['rate']" :hint="$n($p['grounding']['accepted']).' aceitas · '.$n($p['grounding']['rejected']).' rejeitadas'" data-metric="grounding-rate" />
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Vereditos</h3><x-knowledge-admin.bars :items="$p['by_verdict']" :labels="$verdictLabels" aria-label="Vereditos" /></div>
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Gravidade (DDInter)</h3><x-knowledge-admin.bars :items="$p['by_severity']" :labels="$severityLabels" :tones="$severityTones" aria-label="Gravidade" empty="Nenhum par com interação registrada." /></div>
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Status da geração</h3><x-knowledge-admin.bars :items="$p['by_generation_status']" :labels="$generationLabels" aria-label="Status da geração" /></div>
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Motivos de rejeição</h3><x-knowledge-admin.bars :items="$p['rejection_reasons']" :labels="$rejectionLabels" :tones="array_fill_keys(array_keys($p['rejection_reasons']), 'warning')" aria-label="Motivos de rejeição" empty="Nenhuma afirmação rejeitada no período." /></div>
            </div>
        </x-knowledge-admin.ops-disclosure>

        <x-knowledge-admin.ops-disclosure title="Perguntas livres" :summary="'Ancoragem '.$pct($q['grounding']['rate']).' · '.$n($q['with_treatment_context']).' com dados de tratamento · '.$n($q['with_treatment_interaction']).' com interação no tratamento'">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Resultado de cada pergunta</h3><x-knowledge-admin.bars :items="$q['by_status']" :labels="$questionLabels" aria-label="Status das perguntas" /></div>
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Motivos de rejeição</h3><x-knowledge-admin.bars :items="$q['rejection_reasons']" :labels="$rejectionLabels" :tones="array_fill_keys(array_keys($q['rejection_reasons']), 'warning')" aria-label="Motivos de rejeição (perguntas)" empty="Nenhuma afirmação rejeitada no período." /></div>
            </div>
        </x-knowledge-admin.ops-disclosure>

        <x-knowledge-admin.ops-disclosure title="Bulas humanizadas" :summary="'Ancoragem '.$pct($l['grounding']['rate']).' · geração média '.$ms($l['duration']['avg'])">
            <x-knowledge-admin.bars :items="$l['by_status']" :labels="$leafletLabels" aria-label="Status das bulas humanizadas" />
            <x-knowledge-admin.facts class="sm:grid-cols-3">
                <x-knowledge-admin.fact label="Taxa de ancoragem">{{ $pct($l['grounding']['rate']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Geração · média">{{ $ms($l['duration']['avg']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Geração · p95">{{ $ms($l['duration']['p95']) }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
        </x-knowledge-admin.ops-disclosure>

        <x-knowledge-admin.ops-disclosure title="Desempenho" :summary="'Consulta p50 '.$ms($c['duration']['p50']).' · p95 '.$ms($c['duration']['p95']).' · pergunta p50 '.$ms($q['duration']['p50'])">
            <x-knowledge-admin.facts class="sm:grid-cols-3">
                <x-knowledge-admin.fact label="Consulta · média">{{ $ms($c['duration']['avg']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Consulta · p50">{{ $ms($c['duration']['p50']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Consulta · p95">{{ $ms($c['duration']['p95']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Par · base estruturada">{{ $ms($p['timings']['lookup_ms']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Par · recuperação">{{ $ms($p['timings']['retrieval_ms']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Par · geração (LLM)">{{ $ms($p['timings']['generation_ms']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Pergunta · média">{{ $ms($q['duration']['avg']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Pergunta · p50">{{ $ms($q['duration']['p50']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Pergunta · p95">{{ $ms($q['duration']['p95']) }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Modo de recuperação</h3><x-knowledge-admin.bars :items="$c['by_retrieval_mode']" aria-label="Modo de recuperação" /></div>
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Método das evidências recuperadas</h3><x-knowledge-admin.bars :items="$p['evidence_methods']" aria-label="Método de recuperação das evidências" /></div>
            </div>
        </x-knowledge-admin.ops-disclosure>

        <x-knowledge-admin.ops-disclosure title="Consumo dos provedores de IA" :summary="$n($ai['calls']).' chamadas · '.$n($ai['input_tokens'] + $ai['output_tokens']).' tokens · latência média '.$ms($ai['latency_avg_ms'])">
            <x-knowledge-admin.facts class="sm:grid-cols-3 lg:grid-cols-6">
                <x-knowledge-admin.fact label="Chamadas">{{ $n($ai['calls']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Bem-sucedidas / falhas">{{ $n($ai['succeeded']) }} / {{ $n($ai['failed']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Tokens de entrada">{{ $n($ai['input_tokens']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Tokens de saída">{{ $n($ai['output_tokens']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Latência média / p95">{{ $ms($ai['latency_avg_ms']) }} / {{ $ms($ai['latency_p95_ms']) }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Custo estimado">US$ {{ $n($ai['estimated_cost_usd'], 4) }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
            @if ($ai['by_model'] !== [])
                <x-knowledge-admin.table :columns="['Provedor', 'Modelo', 'Capacidade', 'Chamadas', 'Tokens entrada', 'Tokens saída', 'Custo (US$)', 'Latência média']">
                    @foreach ($ai['by_model'] as $row)
                        <tr><td>{{ $row['provider'] }}</td><td>{{ $row['model'] ?? '—' }}</td><td>{{ $row['capability'] ?? '—' }}</td><td class="tabular-nums">{{ $n($row['calls']) }}</td><td class="tabular-nums">{{ $n($row['input_tokens']) }}</td><td class="tabular-nums">{{ $n($row['output_tokens']) }}</td><td class="tabular-nums">{{ $n($row['estimated_cost_usd'], 4) }}</td><td class="tabular-nums">{{ $ms($row['latency_avg_ms']) }}</td></tr>
                    @endforeach
                </x-knowledge-admin.table>
            @endif
            @if ($ai['failures_by_error'] !== [])
                <div class="grid gap-2"><h3 class="text-sm font-semibold text-slate-800">Falhas por código</h3><x-knowledge-admin.bars :items="$ai['failures_by_error']" :tones="array_fill_keys(array_keys($ai['failures_by_error']), 'danger')" aria-label="Falhas por código de erro" /></div>
            @endif
            <div class="grid gap-2">
                <h3 class="text-sm font-semibold text-slate-800">Série diária (últimos 30 dias, independente do período)</h3>
                @if ($ai['daily'] === [])
                    <p class="text-sm text-slate-500">Nenhuma chamada registrada nos últimos 30 dias.</p>
                @else
                    <x-knowledge-admin.table :columns="['Dia', 'Chamadas', 'Falhas', 'Tokens entrada', 'Tokens saída', 'Custo (US$)']">
                        @foreach ($ai['daily'] as $day)
                            <tr><td>{{ \Carbon\Carbon::parse($day['day'])->format('d/m/Y') }}</td><td class="tabular-nums">{{ $n($day['calls']) }}</td><td class="tabular-nums">{{ $n($day['failed']) }}</td><td class="tabular-nums">{{ $n($day['input_tokens']) }}</td><td class="tabular-nums">{{ $n($day['output_tokens']) }}</td><td class="tabular-nums">{{ $n($day['estimated_cost_usd'], 4) }}</td></tr>
                        @endforeach
                    </x-knowledge-admin.table>
                @endif
            </div>
            <p class="text-xs text-slate-500">Registros de `ai_usage_logs`: só métricas técnicas, nunca prompts, respostas ou dados do usuário. Exportação para o TCC: <code class="rounded bg-slate-100 px-1">php artisan analytics:export</code> ou a tela Relatórios.</p>
        </x-knowledge-admin.ops-disclosure>

        <div class="flex flex-wrap gap-2">
            <x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.consultations.index')">Consultas</x-knowledge-admin.button>
            <x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.questions.index')">Perguntas</x-knowledge-admin.button>
            <x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.leaflets.index')">Bulas humanizadas</x-knowledge-admin.button>
            <x-knowledge-admin.button variant="secondary" :href="route('admin.reports.index')">Relatórios</x-knowledge-admin.button>
        </div>
    </div>
</x-knowledge-admin.shell>
