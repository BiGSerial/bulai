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
    $t = $overview['timeline'];
    $dayLabels = array_map(fn (string $day): string => \Carbon\Carbon::parse($day)->format('d/m'), $t['days']);
    $aiDaily = collect($ai['daily'])->keyBy('day');
    $aiDays = collect(range(29, 0))->map(fn (int $ago): string => now()->subDays($ago)->format('Y-m-d'));
    $aiSeries = fn (string $field) => $aiDays->map(fn (string $day) => $aiDaily[$day][$field] ?? 0)->all();
    $aiDayLabels = $aiDays->map(fn (string $day): string => \Carbon\Carbon::parse($day)->format('d/m'))->all();
    $verdictTones = ['INTERACTION_FOUND' => 'danger', 'NOT_FOUND_IN_SOURCES' => 'success', 'INSUFFICIENT_EVIDENCE' => 'warning', 'CONTRADICTORY_EVIDENCE' => 'accent', 'MEDICATION_NOT_IDENTIFIED' => 'muted', 'TECHNICAL_ERROR' => 'danger'];
    $generationTones = ['generated' => 'success', 'invalid' => 'danger', 'unavailable' => 'muted', 'desconhecido' => 'muted'];
    $questionTones = ['answered' => 'success', 'excerpts_only' => 'info', 'no_evidence' => 'warning', 'no_leaflet' => 'muted', 'not_identified' => 'muted', 'blocked' => 'danger', 'no_medicine' => 'muted'];
    $leafletTones = ['generated' => 'success', 'partial' => 'warning', 'invalid' => 'danger', 'unavailable' => 'muted'];
    $stages = [
        'Consulta de interação' => $c['duration'], 'Pergunta livre' => $q['duration'], 'Bula humanizada' => ['avg' => $l['duration']['avg'], 'p50' => $l['duration']['p50'], 'p95' => $l['duration']['p95']],
    ];
    $groundingTotals = ['Pares' => $p['grounding'], 'Perguntas' => $q['grounding'], 'Bulas' => $l['grounding']];
    $abas = [['key' => 'visao', 'label' => 'Visão geral', 'href' => route('admin.analytics.index')], ['key' => 'consultas', 'label' => 'Consultas', 'href' => route('admin.analytics.consultations.index')], ['key' => 'perguntas', 'label' => 'Perguntas', 'href' => route('admin.analytics.questions.index')], ['key' => 'bulas', 'label' => 'Bulas humanizadas', 'href' => route('admin.analytics.leaflets.index')], ['key' => 'consumo', 'label' => 'Consumo de IA', 'href' => route('admin.analytics.ai-usage.index')]];
@endphp
    <x-slot:actions>
        <x-knowledge-admin.button variant="secondary" :href="route('admin.reports.index')">Gerar relatório</x-knowledge-admin.button>
    </x-slot:actions>

    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Seções do Analytics">
        @foreach ($abas as $aba)
            <a href="{{ $aba['href'] }}" wire:navigate class="-mb-px inline-flex shrink-0 items-center border-b-2 px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $aba['key'] === 'visao' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" @if ($aba['key'] === 'visao') aria-current="page" @endif>{{ $aba['label'] }}</a>
        @endforeach
    </nav>

    <x-knowledge-admin.toolbar :count="$period > 0 ? 'Desde '.now()->subDays($period)->timezone(config('app.timezone'))->format('d/m/Y') : 'Todo o período'">
        <x-knowledge-admin.select label="Período" compact wire:model.live="period" wrapper-class="w-full sm:w-48">
            @foreach ($periods as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>

    <div class="grid gap-4" wire:loading.class="opacity-60" wire:target="period">
        <section class="grid grid-cols-2 gap-2 lg:grid-cols-3 xl:grid-cols-6" aria-label="Indicadores do período">
            <x-knowledge-admin.kpi label="Consultas de interação" :value="$n($c['total'])" :hint="$n($c['pairs']).' pares · '.($c['by_status']['FAILED'] ?? 0).' falharam'" :href="route('admin.analytics.consultations.index')" />
            <x-knowledge-admin.kpi label="Perguntas" :value="$n($q['total'])" :hint="($q['by_status']['answered'] ?? 0).' respondidas em linguagem simples'" :href="route('admin.analytics.questions.index')" />
            <x-knowledge-admin.kpi label="Bulas humanizadas" :value="$n($l['total'])" :hint="$n($l['views']).' visualizações · '.$n($l['cache_hits']).' via cache'" :href="route('admin.analytics.leaflets.index')" />
            <x-knowledge-admin.kpi label="Usuários ativos" :value="$n(max($c['distinct_users'], $q['distinct_users']))" hint="com consulta ou pergunta no período" />
            <x-knowledge-admin.kpi label="Ancoragem dos pares" :value="$pct($p['grounding']['rate'])" :hint="$n($p['grounding']['accepted']).' aceitas · '.$n($p['grounding']['rejected']).' rejeitadas'" :tone="$p['grounding']['rate'] === null ? 'default' : ($p['grounding']['rate'] >= 0.8 ? 'success' : 'warning')" />
            <x-knowledge-admin.kpi label="Custo estimado de IA" :value="'US$ '.$n($ai['estimated_cost_usd'], 4)" :hint="$n($ai['calls']).' chamadas · '.$n($ai['failed']).' falhas · latência média '.$ms($ai['latency_avg_ms'])" tone="brand" />
        </section>

        @php($cov = $overview['coverage'])
        <x-knowledge-admin.section title="Cobertura" description="Três medidas diferentes: a base de interações (DDInter) tem registro do par, a base tem a bula dos dois remédios, e havia trecho de bula para explicar. Uma não implica a outra.">
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4" aria-label="Cobertura no período">
                <x-knowledge-admin.kpi label="Pares com registro no DDInter" :value="$pct($cov['pairs']['ddinter_rate'])" :hint="$n($cov['pairs']['ddinter_record']).' de '.$n($cov['pairs']['total']).' pares'" />
                <x-knowledge-admin.kpi label="Pares com as duas bulas" :value="$pct($cov['pairs']['leaflets_rate'])" :hint="$n($cov['pairs']['both_leaflets']).' de '.$n($cov['pairs']['total']).' pares'" />
                <x-knowledge-admin.kpi label="Pares com evidência de bula" :value="$pct($cov['pairs']['evidence_rate'])" :hint="$n($cov['pairs']['with_evidence']).' de '.$n($cov['pairs']['total']).' pares'" />
                <x-knowledge-admin.kpi label="Perguntas com evidência de bula" :value="$pct($cov['questions']['evidence_rate'])" :hint="$n($cov['questions']['without_leaflet']).' sem bula · '.$n($cov['questions']['not_identified']).' sem princípio ativo'" />
            </div>
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Atividade diária" :description="$period > 0 ? 'Consultas, perguntas e bulas humanizadas por dia no período.' : 'Consultas, perguntas e bulas humanizadas por dia (últimos 90 dias).'">
            <x-knowledge-admin.chart kind="line" :categories="$dayLabels" :series="[['label' => 'Consultas', 'data' => $t['consultations'], 'tone' => 'brand'], ['label' => 'Perguntas', 'data' => $t['questions'], 'tone' => 'accent'], ['label' => 'Bulas humanizadas', 'data' => $t['leaflets'], 'tone' => 'info']]" height="h-64" aria-label="Atividade diária" empty="Nenhuma atividade no período." />
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Qualidade das respostas" description="Ancoragem = parte das afirmações da IA aceitas pelo validador por citarem um trecho de bula. Quanto maior, melhor.">
            <div class="grid items-center gap-4 lg:grid-cols-2">
                <div class="grid gap-4">
                    <x-knowledge-admin.meter label="Pares de medicamentos" :rate="$p['grounding']['rate']" :hint="$n($p['grounding']['accepted']).' aceitas · '.$n($p['grounding']['rejected']).' rejeitadas'" data-metric="grounding-rate" />
                    <x-knowledge-admin.meter label="Perguntas livres" :rate="$q['grounding']['rate']" :hint="$n($q['with_treatment_context']).' com dados de tratamento · '.$n($q['with_treatment_interaction']).' com interação no tratamento'" />
                    <x-knowledge-admin.meter label="Bulas humanizadas" :rate="$l['grounding']['rate']" :hint="'geração média '.$ms($l['duration']['avg']).' · p95 '.$ms($l['duration']['p95'])" />
                </div>
                <div class="grid gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Afirmações aceitas × rejeitadas</h3>
                    <x-knowledge-admin.chart kind="bar" stacked :categories="array_keys($groundingTotals)" :series="[['label' => 'Aceitas', 'data' => array_column($groundingTotals, 'accepted'), 'tone' => 'success'], ['label' => 'Rejeitadas', 'data' => array_column($groundingTotals, 'rejected'), 'tone' => 'danger']]" height="h-44" aria-label="Afirmações aceitas e rejeitadas por origem" empty="Nenhuma afirmação validada no período." />
                </div>
            </div>
        </x-knowledge-admin.section>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-knowledge-admin.section title="Pares de medicamentos" :description="$p['with_explanation'].' com explicação · '.$p['with_evidence'].' com evidência'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vereditos</h3><x-knowledge-admin.chart kind="doughnut" :items="$p['by_verdict']" :labels="$verdictLabels" :tones="$verdictTones" height="h-56" aria-label="Vereditos" /></div>
                    <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gravidade (DDInter)</h3><x-knowledge-admin.chart kind="bar" :items="$p['by_severity']" :labels="$severityLabels" :tones="$severityTones" height="h-56" aria-label="Gravidade" empty="Nenhum par com interação registrada." /></div>
                    <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status da geração</h3><x-knowledge-admin.chart kind="bar" :items="$p['by_generation_status']" :labels="$generationLabels" :tones="$generationTones" height="h-44" aria-label="Status da geração" /></div>
                    <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Motivos de rejeição</h3><x-knowledge-admin.chart kind="bar" :items="$p['rejection_reasons']" :labels="$rejectionLabels" :tones="array_fill_keys(array_keys($p['rejection_reasons']), 'warning')" height="h-44" aria-label="Motivos de rejeição" empty="Nenhuma afirmação rejeitada no período." /></div>
                </div>
            </x-knowledge-admin.section>

            <x-knowledge-admin.section title="Perguntas livres" :description="$n($q['total']).' no período'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2 sm:col-span-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Resultado de cada pergunta</h3><x-knowledge-admin.chart kind="bar" :items="$q['by_status']" :labels="$questionLabels" :tones="$questionTones" height="h-60" aria-label="Status das perguntas" /></div>
                    <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Motivos de rejeição</h3><x-knowledge-admin.chart kind="bar" :items="$q['rejection_reasons']" :labels="$rejectionLabels" :tones="array_fill_keys(array_keys($q['rejection_reasons']), 'warning')" height="h-44" aria-label="Motivos de rejeição (perguntas)" empty="Nenhuma afirmação rejeitada no período." /></div>
                    <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bulas humanizadas por situação</h3><x-knowledge-admin.chart kind="doughnut" :items="$l['by_status']" :labels="$leafletLabels" :tones="$leafletTones" height="h-44" aria-label="Status das bulas humanizadas" /></div>
                </div>
            </x-knowledge-admin.section>
        </div>

        <x-knowledge-admin.section title="Desempenho" description="Tempos de resposta ao paciente, em milissegundos (p50 = metade das respostas foi mais rápida; p95 = 95% foram mais rápidas).">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tempo total por tipo de resposta</h3>
                    <x-knowledge-admin.chart kind="column" format="ms" :categories="array_keys($stages)" :series="[['label' => 'Média', 'data' => array_map(fn ($s) => $s['avg'] ?? 0, array_values($stages)), 'tone' => 'brand'], ['label' => 'p95', 'data' => array_map(fn ($s) => $s['p95'] ?? 0, array_values($stages)), 'tone' => 'accent']]" height="h-60" aria-label="Tempo total por tipo de resposta" empty="Sem tempos registrados no período." />
                </div>
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Onde o par gasta tempo (média)</h3>
                    <x-knowledge-admin.chart kind="bar" format="ms" :categories="['Base estruturada (DDInter)', 'Recuperação de trechos', 'Geração da explicação']" :series="[['label' => 'Média', 'data' => [$p['timings']['lookup_ms'] ?? 0, $p['timings']['retrieval_ms'] ?? 0, $p['timings']['generation_ms'] ?? 0], 'tones' => ['soft', 'info', 'accent']]]" height="h-60" aria-label="Tempo médio por etapa do par" empty="Sem tempos registrados no período." />
                </div>
            </div>
            <x-knowledge-admin.data-table :columns="[['label' => 'Etapa'], ['label' => 'Média', 'align' => 'right'], ['label' => 'p50', 'align' => 'right'], ['label' => 'p95', 'align' => 'right']]" dense>
                <tr><td>Consulta de interação (total)</td><td class="tabular">{{ $ms($c['duration']['avg']) }}</td><td class="tabular">{{ $ms($c['duration']['p50']) }}</td><td class="tabular">{{ $ms($c['duration']['p95']) }}</td></tr>
                <tr><td>Par · base estruturada (DDInter)</td><td class="tabular">{{ $ms($p['timings']['lookup_ms']) }}</td><td class="tabular">—</td><td class="tabular">—</td></tr>
                <tr><td>Par · recuperação de trechos</td><td class="tabular">{{ $ms($p['timings']['retrieval_ms']) }}</td><td class="tabular">—</td><td class="tabular">—</td></tr>
                <tr><td>Par · geração da explicação</td><td class="tabular">{{ $ms($p['timings']['generation_ms']) }}</td><td class="tabular">—</td><td class="tabular">—</td></tr>
                <tr><td>Pergunta livre (total)</td><td class="tabular">{{ $ms($q['duration']['avg']) }}</td><td class="tabular">{{ $ms($q['duration']['p50']) }}</td><td class="tabular">{{ $ms($q['duration']['p95']) }}</td></tr>
                <tr><td>Bula humanizada (geração)</td><td class="tabular">{{ $ms($l['duration']['avg']) }}</td><td class="tabular">{{ $ms($l['duration']['p50']) }}</td><td class="tabular">{{ $ms($l['duration']['p95']) }}</td></tr>
            </x-knowledge-admin.data-table>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Modo de recuperação</h3><x-knowledge-admin.chart kind="doughnut" :items="$c['by_retrieval_mode']" height="h-44" aria-label="Modo de recuperação" /></div>
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Método das evidências recuperadas</h3><x-knowledge-admin.chart kind="doughnut" :items="$p['evidence_methods']" height="h-44" aria-label="Método de recuperação das evidências" /></div>
            </div>
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Consumo dos provedores de IA" :description="$n($ai['calls']).' chamadas · '.$n($ai['succeeded']).' bem-sucedidas · '.$n($ai['failed']).' falhas · '.$n($ai['input_tokens'] + $ai['output_tokens']).' tokens · latência média '.$ms($ai['latency_avg_ms']).' · p95 '.$ms($ai['latency_p95_ms'])">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Custo estimado por dia (US$) · 30 dias</h3>
                    <x-knowledge-admin.chart kind="line" format="usd" :categories="$aiDayLabels" :series="[['label' => 'Custo (US$)', 'data' => $aiSeries('estimated_cost_usd'), 'tone' => 'brand']]" height="h-56" aria-label="Custo estimado por dia" empty="Nenhuma chamada registrada nos últimos 30 dias." />
                </div>
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Chamadas por dia · 30 dias</h3>
                    <x-knowledge-admin.chart kind="column" stacked :categories="$aiDayLabels" :series="[['label' => 'Sucesso', 'data' => array_map(fn ($calls, $failed) => max(0, $calls - $failed), $aiSeries('calls'), $aiSeries('failed')), 'tone' => 'success'], ['label' => 'Falhas', 'data' => $aiSeries('failed'), 'tone' => 'danger']]" :value-labels="false" height="h-56" aria-label="Chamadas de IA por dia" empty="Nenhuma chamada registrada nos últimos 30 dias." />
                </div>
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Chamadas por modelo</h3>
                    <x-knowledge-admin.chart kind="bar" :categories="array_map(fn ($r) => trim(($r['model'] ?? $r['provider']).' · '.($r['capability'] ?? '—')), $ai['by_model'])" :series="[['label' => 'Chamadas', 'data' => array_column($ai['by_model'], 'calls'), 'tone' => 'brand']]" height="h-52" aria-label="Chamadas por modelo" empty="Nenhuma chamada a provedores de IA no período." />
                </div>
                <div class="grid content-start gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tokens consumidos</h3>
                    <x-knowledge-admin.chart kind="doughnut" :items="['input' => $ai['input_tokens'], 'output' => $ai['output_tokens']]" :labels="['input' => 'Entrada', 'output' => 'Saída']" :tones="['input' => 'brand', 'output' => 'accent']" height="h-52" aria-label="Tokens de entrada e saída" empty="Nenhum token consumido no período." />
                </div>
            </div>
            @if ($ai['by_model'] !== [])
                <x-knowledge-admin.data-table :columns="[['label' => 'Provedor'], ['label' => 'Modelo'], ['label' => 'Capacidade'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Tokens entrada', 'align' => 'right'], ['label' => 'Tokens saída', 'align' => 'right'], ['label' => 'Custo (US$)', 'align' => 'right'], ['label' => 'Latência média', 'align' => 'right']]" dense>
                    @foreach ($ai['by_model'] as $row)
                        <tr><td>{{ $row['provider'] }}</td><td>{{ $row['model'] ?? '—' }}</td><td>{{ $row['capability'] ?? '—' }}</td><td class="tabular">{{ $n($row['calls']) }}</td><td class="tabular">{{ $n($row['input_tokens']) }}</td><td class="tabular">{{ $n($row['output_tokens']) }}</td><td class="tabular">{{ $n($row['estimated_cost_usd'], 4) }}</td><td class="tabular">{{ $ms($row['latency_avg_ms']) }}</td></tr>
                    @endforeach
                </x-knowledge-admin.data-table>
            @endif
            @if ($ai['failures_by_error'] !== [])
                <div class="grid gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Falhas por código</h3><x-knowledge-admin.chart kind="bar" :items="$ai['failures_by_error']" :tones="array_fill_keys(array_keys($ai['failures_by_error']), 'danger')" height="h-40" aria-label="Falhas por código de erro" /></div>
            @endif
            <x-knowledge-admin.ops-disclosure title="Série diária" summary="Últimos 30 dias, independente do período escolhido.">
                @if ($ai['daily'] === [])
                    <p class="text-sm text-slate-500">Nenhuma chamada registrada nos últimos 30 dias.</p>
                @else
                    <x-knowledge-admin.data-table :columns="[['label' => 'Dia'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Falhas', 'align' => 'right'], ['label' => 'Tokens entrada', 'align' => 'right'], ['label' => 'Tokens saída', 'align' => 'right'], ['label' => 'Custo (US$)', 'align' => 'right']]" dense>
                        @foreach ($ai['daily'] as $day)
                            <tr><td>{{ \Carbon\Carbon::parse($day['day'])->format('d/m/Y') }}</td><td class="tabular">{{ $n($day['calls']) }}</td><td class="tabular">{{ $n($day['failed']) }}</td><td class="tabular">{{ $n($day['input_tokens']) }}</td><td class="tabular">{{ $n($day['output_tokens']) }}</td><td class="tabular">{{ $n($day['estimated_cost_usd'], 4) }}</td></tr>
                        @endforeach
                    </x-knowledge-admin.data-table>
                @endif
            </x-knowledge-admin.ops-disclosure>
            <p class="text-xs text-slate-500">Só métricas técnicas: nunca prompts ou respostas. Consumo por usuário e por provedor, com o uso informado por cada API: <a class="text-brand-700 underline" href="{{ route('admin.analytics.ai-usage.index') }}" wire:navigate>Consumo de IA</a>. Exportação para o TCC: <code class="rounded bg-slate-100 px-1">php artisan analytics:export</code> ou a tela Relatórios.</p>
        </x-knowledge-admin.section>
    </div>
</x-knowledge-admin.shell>
