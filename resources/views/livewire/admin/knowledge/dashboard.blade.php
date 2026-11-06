<x-knowledge-admin.shell title="Visão geral" subtitle="O que pede uma decisão, como o aplicativo está sendo usado e se a operação está saudável.">
    <x-slot:actions>
        <x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
    </x-slot:actions>
    @php
        $k = $summary['knowledge'];
        $u = $summary['usage'];
        $ai = $summary['ai'];
        $p = $summary['people'];
        $w = $summary['workflow'];
        $n = fn ($value): string => number_format((int) $value, 0, ',', '.');
        $pct = fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.').'%';
        $typeLabels = \App\Models\Knowledge\KnowledgeDocument::documentTypeOptions();
        $unmatched = collect($summary['attention'])->firstWhere('route', 'admin.knowledge.unmatched.index')['count'] ?? 0;
        $steps = \App\AdminHelp::workflow();
        $counts = [null, $w['batches_in_progress'], $w['pending_items'], $w['documents_ready']];
        $states = ['todo', $w['batches_in_progress'] > 0 ? 'current' : 'done', $w['pending_items'] > 0 ? 'current' : 'done', $w['documents_ready'] > 0 ? 'done' : 'todo'];
        $workflow = array_map(fn (array $step, int $i): array => ['label' => $step['label'], 'hint' => $step['hint'], 'href' => route($step['route']), 'count' => $counts[$i] !== null ? $n($counts[$i]) : null, 'state' => $states[$i]], $steps, array_keys($steps));
        $questionStatus = fn (string $status): array => match ($status) {
            'answered' => ['Respondida', 'success'],
            'blocked' => ['Recusada', 'danger'],
            'excerpts_only' => ['Só trechos', 'info'],
            default => [ucfirst(str_replace('_', ' ', $status)), 'neutral'],
        };
    @endphp

    {{-- 1. Indicadores do dia: o que mais importa, em uma linha. --}}
    <x-knowledge-admin.kpis>
        <x-knowledge-admin.kpi label="Bulas prontas" :value="$n($w['documents_ready'])" :href="route('admin.knowledge.documents.index')" :hint="$n($k['documents']).' documentos · '.$k['documents_with_file'].' com PDF · '.$k['documents_without_file'].' sem PDF'" tone="success" />
        <x-knowledge-admin.kpi label="Pendências" :value="$n($w['pending_items'])" :href="route('admin.knowledge.pending.index')" :hint="$w['pending_items'] > 0 ? 'Arquivos esperando sua decisão' : 'Nada a revisar'" :tone="$w['pending_items'] > 0 ? 'warning' : 'default'" />
        <x-knowledge-admin.kpi label="Remédios não encontrados" :value="$n($unmatched)" :href="route('admin.knowledge.unmatched.index')" :hint="$unmatched > 0 ? 'Pacientes esperando a ligação ao catálogo' : 'Nenhum pendente'" :tone="$unmatched > 0 ? 'warning' : 'default'" />
        <x-knowledge-admin.kpi label="Perguntas em 7 dias" :value="$n($u['questions_week'])" :href="route('admin.analytics.questions.index')" :hint="'Custo da IA no mês: US$ '.number_format($ai['cost_month_usd'], 2, ',', '.')" tone="brand" />
    </x-knowledge-admin.kpis>

    {{-- 2. Fluxo das bulas, com as contagens de cada etapa. --}}
    <section class="grid gap-2" aria-labelledby="fluxo-titulo">
        <h2 id="fluxo-titulo" class="text-sm font-semibold text-slate-900">Fluxo das bulas</h2>
        <x-knowledge-admin.workflow :steps="$workflow" />
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 3. Precisa de atenção: uma linha por item, cada uma leva à tela onde a decisão é tomada. --}}
        <x-knowledge-admin.section title="Precisa de atenção" description="Cada item leva direto à tela onde a decisão é tomada.">
            @if ($summary['attention'] !== [])
                <ul class="grid gap-1" aria-label="Pontos de atenção">
                    @foreach ($summary['attention'] as $item)
                        <li><a class="flex min-h-10 items-center gap-3 rounded-lg px-2 py-1.5 text-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route($item['route']) }}" wire:navigate>
                            <span class="w-10 shrink-0 text-right text-base font-semibold tabular-nums {{ match ($item['tone']) { 'danger' => 'text-rose-700', 'warning' => 'text-amber-700', default => 'text-sky-700' } }}">{{ $n($item['count']) }}</span>
                            <span class="min-w-0 flex-1 truncate text-slate-700">{{ $item['label'] }}</span>
                            <span class="shrink-0 text-xs font-semibold text-brand-700">Abrir</span>
                        </a></li>
                    @endforeach
                </ul>
            @else
                <x-knowledge-admin.callout tone="success" title="Nada pendente.">Sem revisões, erros ou falhas recentes.</x-knowledge-admin.callout>
            @endif
        </x-knowledge-admin.section>

        {{-- 4. Atividade recente: últimas importações e perguntas. --}}
        <x-knowledge-admin.section title="Atividade recente" description="Últimas importações e perguntas do aplicativo.">
            <div class="grid gap-3">
                <div class="grid gap-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Importações</p>
                    @forelse ($summary['recent']['batches'] as $batch)
                        <a wire:key="rb-{{ $batch['id'] }}" class="flex min-h-9 items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.batches.show', $batch['id']) }}" wire:navigate>
                            <span class="min-w-0 flex-1 truncate font-medium text-slate-900">{{ $batch['name'] }}</span>
                            <span class="shrink-0 text-xs text-slate-500">{{ $n($batch['total']) }} {{ $batch['total'] === 1 ? 'arquivo' : 'arquivos' }}{{ $batch['pending'] > 0 ? ' · '.$n($batch['pending']).' pendentes' : '' }}{{ $batch['errors'] > 0 ? ' · '.$n($batch['errors']).' com erro' : '' }}</span>
                            <x-knowledge-admin.status-badge :status="$batch['status']" />
                            <span class="w-24 shrink-0 text-right text-xs text-slate-500">{{ $batch['at']?->diffForHumans(short: true) }}</span>
                        </a>
                    @empty
                        <p class="px-2 text-sm text-slate-500">Nenhuma importação ainda. <a class="font-medium text-brand-700 hover:underline" href="{{ route('admin.knowledge.upload') }}" wire:navigate>Adicionar documentos</a></p>
                    @endforelse
                </div>
                <div class="grid gap-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Perguntas</p>
                    @forelse ($summary['recent']['questions'] as $question)
                        @php([$qLabel, $qTone] = $questionStatus($question['status']))
                        <a wire:key="rq-{{ $question['uuid'] }}" class="flex min-h-9 items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.analytics.questions.show', $question['uuid']) }}" wire:navigate>
                            <span class="min-w-0 flex-1 truncate text-slate-800">{{ $question['question'] }}</span>
                            <x-knowledge-admin.badge :tone="$qTone">{{ $qLabel }}</x-knowledge-admin.badge>
                            <span class="w-24 shrink-0 text-right text-xs text-slate-500">{{ $question['at']?->diffForHumans(short: true) }}</span>
                        </a>
                    @empty
                        <p class="px-2 text-sm text-slate-500">Nenhuma pergunta registrada ainda.</p>
                    @endforelse
                </div>
            </div>
        </x-knowledge-admin.section>
    </div>

    {{-- 5. Operação: processador da fila e serviços (polling só neste bloco). --}}
    <x-knowledge-admin.section title="Operação" description="Processador da fila e serviços de análise; atualiza sozinho a cada 30 segundos.">
        <x-slot:actions><x-knowledge-admin.button variant="secondary" size="sm" :href="route('admin.knowledge.queue')">Fila e serviços</x-knowledge-admin.button></x-slot:actions>
        <livewire:admin.knowledge.operation-panel />
    </x-knowledge-admin.section>

    <section class="grid gap-2" aria-labelledby="uso-titulo">
        <div class="flex items-baseline justify-between gap-3"><h2 id="uso-titulo" class="text-sm font-semibold text-slate-900">Uso do aplicativo nos últimos 7 dias</h2><a class="text-xs font-medium text-brand-700 hover:underline" href="{{ route('admin.analytics.index') }}" wire:navigate>Ver Analytics</a></div>
        <x-knowledge-admin.kpis>
            <x-knowledge-admin.kpi label="Consultas de interação" :value="$n($u['consultations_week'])" :href="route('admin.analytics.consultations.index')" :hint="$n($u['consultations_total']).' no total'" />
            <x-knowledge-admin.kpi label="Perguntas recusadas pela triagem" :value="$n($u['questions_blocked_week'])" :href="route('admin.analytics.questions.index')" :hint="$n($u['questions_total']).' perguntas no total'" :tone="$u['questions_blocked_week'] > 0 ? 'warning' : 'default'" />
            <x-knowledge-admin.kpi label="Bulas humanizadas" :value="$n($u['leaflets_week'])" :href="route('admin.analytics.leaflets.index')" :hint="$n($u['leaflets_total']).' no total'" />
            <x-knowledge-admin.kpi label="Ancoragem das respostas" :value="$pct($u['grounding_rate_week'])" :href="route('admin.analytics.index')" :hint="$n($u['distinct_users_week']).' pessoas consultaram'" tone="brand" />
        </x-knowledge-admin.kpis>
    </section>

    <x-knowledge-admin.ops-disclosure title="Base de conhecimento" :summary="$n($k['documents']).' documentos · '.$n($k['chunks']).' trechos · vetores em '.$pct($k['embedding_coverage'])">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <x-knowledge-admin.kpi label="Documentos" :value="$n($k['documents'])" :href="route('admin.knowledge.documents.index')" :hint="$k['documents_with_file'].' com PDF · '.$k['documents_without_file'].' sem PDF'" />
            <x-knowledge-admin.kpi label="Trechos indexados" :value="$n($k['chunks'])" :hint="'Vetores: '.$pct($k['embedding_coverage'])" :tone="$k['embedding_coverage'] !== null && $k['embedding_coverage'] < 1 ? 'warning' : 'default'" />
            <x-knowledge-admin.kpi label="Bulas ligadas a princípios ativos" :value="$n($k['documents_with_ingredients'])" :href="route('admin.knowledge.documents.index')" hint="Usadas nas respostas" />
            <x-knowledge-admin.kpi label="Medicamentos ANVISA" :value="$n($k['medicines'])" :href="route('admin.knowledge.catalog.index')" :hint="$n($k['medicines_identified']).' com princípio ativo identificado'" />
            <x-knowledge-admin.kpi label="Princípios ativos" :value="$n($k['ingredients'])" :href="route('admin.knowledge.catalog.index')" hint="Ponte ANVISA ↔ DDInter" />
            <x-knowledge-admin.kpi label="Pares de interação" :value="$n($k['interactions'])" :href="route('admin.knowledge.interactions.index')" hint="DDInter" />
        </div>
        <ul class="flex flex-wrap gap-2" aria-label="Documentos por tipo e situação">
            @foreach ($k['documents_by_type'] as $type => $count)<li><x-knowledge-admin.badge>{{ $typeLabels[$type] ?? $type }}: {{ $n($count) }}</x-knowledge-admin.badge></li>@endforeach
            @foreach ($k['documents_by_status'] as $status => $count)@if ($status !== 'READY')<li><x-knowledge-admin.badge :tone="$status === 'FAILED' ? 'danger' : 'info'">{{ match ($status) { 'FAILED' => 'Com falha', 'PROCESSING' => 'Processando', 'PENDING' => 'Aguardando', default => $status } }}: {{ $n($count) }}</x-knowledge-admin.badge></li>@endif@endforeach
        </ul>
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.ops-disclosure title="Inteligência artificial" :summary="$n($ai['calls_month']).' chamadas no mês · US$ '.number_format($ai['cost_month_usd'], 2, ',', '.').($ai['failed_month'] > 0 ? ' · '.$n($ai['failed_month']).' com falha' : '')">
        <x-knowledge-admin.kpis>
            <x-knowledge-admin.kpi label="Chamadas no mês" :value="$n($ai['calls_month'])" :href="route('admin.analytics.index')" />
            <x-knowledge-admin.kpi label="Com falha" :value="$n($ai['failed_month'])" :tone="$ai['failed_month'] > 0 ? 'danger' : 'default'" :href="route('admin.analytics.index')" />
            <x-knowledge-admin.kpi label="Custo estimado" :value="'US$ '.number_format($ai['cost_month_usd'], 2, ',', '.')" :href="route('admin.analytics.index')" hint="Tabela de preços interna" />
            <x-knowledge-admin.kpi label="Latência média" :value="$ai['latency_avg_ms'] !== null ? number_format($ai['latency_avg_ms'], 0, ',', '.').' ms' : '—'" />
        </x-knowledge-admin.kpis>
        <x-knowledge-admin.facts class="sm:grid-cols-3">
            <x-knowledge-admin.fact label="Texto">{{ $ai['text_provider'] ?? 'não configurado' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Visão (receitas)">{{ $ai['vision_provider'] ?? 'não configurada' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Índice de busca">{{ $ai['embedding_provider'] ?? 'não configurado' }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.ops-disclosure title="Pessoas" :summary="$n($p['active']).' com acesso ativo · '.$n($p['logged_in_week']).' entraram na semana'.($p['never_logged_in'] > 0 ? ' · '.$n($p['never_logged_in']).' nunca entraram' : '')">
        <x-knowledge-admin.kpis>
            <x-knowledge-admin.kpi label="Usuários" :value="$n($p['users'])" :href="route('admin.users.index')" :hint="$n($p['admins']).' administradores'" />
            <x-knowledge-admin.kpi label="Com acesso ativo" :value="$n($p['active'])" :href="route('admin.users.index', ['status' => 'active'])" />
            <x-knowledge-admin.kpi label="Entraram na semana" :value="$n($p['logged_in_week'])" :href="route('admin.users.index', ['ordem' => 'recent_login'])" />
            <x-knowledge-admin.kpi label="Nunca entraram" :value="$n($p['never_logged_in'])" :tone="$p['never_logged_in'] > 0 ? 'warning' : 'default'" :href="route('admin.users.index')" />
        </x-knowledge-admin.kpis>
    </x-knowledge-admin.ops-disclosure>
</x-knowledge-admin.shell>
