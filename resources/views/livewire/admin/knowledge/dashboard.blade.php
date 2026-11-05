<x-knowledge-admin.shell title="Visão geral" subtitle="O que pede uma decisão, como o aplicativo está sendo usado e se a operação está saudável.">
    <x-slot:actions>
        <x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>
    </x-slot:actions>
    @php
        $k = $summary['knowledge'];
        $u = $summary['usage'];
        $ai = $summary['ai'];
        $p = $summary['people'];
        $n = fn ($value): string => number_format((int) $value, 0, ',', '.');
        $pct = fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.').'%';
        $typeLabels = \App\Models\Knowledge\KnowledgeDocument::documentTypeOptions();
    @endphp

    @if ($summary['attention'] !== [])
        <x-knowledge-admin.section title="Precisa de atenção" description="Cada item leva direto à tela onde a decisão é tomada.">
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" aria-label="Pontos de atenção">
                @foreach ($summary['attention'] as $item)
                    <li><a class="flex min-h-12 items-center gap-3 rounded-lg px-3 py-2 ring-1 transition hover:ring-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ match ($item['tone']) { 'danger' => 'bg-rose-50 ring-rose-200', 'warning' => 'bg-amber-50 ring-amber-200', default => 'bg-sky-50 ring-sky-200' } }}" href="{{ route($item['route']) }}" wire:navigate><span class="text-xl font-semibold tabular-nums {{ match ($item['tone']) { 'danger' => 'text-rose-700', 'warning' => 'text-amber-700', default => 'text-sky-700' } }}">{{ $n($item['count']) }}</span><span class="text-sm text-slate-700">{{ $item['label'] }}</span></a></li>
                @endforeach
            </ul>
        </x-knowledge-admin.section>
    @else
        <x-knowledge-admin.alert tone="success">Nada pendente: sem revisões, erros ou falhas recentes.</x-knowledge-admin.alert>
    @endif

    <section class="grid gap-2" aria-labelledby="uso-titulo">
        <div class="flex items-baseline justify-between gap-3"><h2 id="uso-titulo" class="text-base font-semibold text-slate-900">Uso do aplicativo nos últimos 7 dias</h2><a class="text-sm font-medium text-brand-700 hover:underline" href="{{ route('admin.analytics.index') }}" wire:navigate>Ver Analytics</a></div>
        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <x-knowledge-admin.kpi label="Consultas de interação" :value="$n($u['consultations_week'])" :href="route('admin.analytics.consultations.index')" :hint="$n($u['consultations_total']).' no total'" />
            <x-knowledge-admin.kpi label="Perguntas" :value="$n($u['questions_week'])" :href="route('admin.analytics.questions.index')" :hint="$n($u['questions_blocked_week']).' recusadas pela triagem'" />
            <x-knowledge-admin.kpi label="Bulas humanizadas" :value="$n($u['leaflets_week'])" :href="route('admin.analytics.leaflets.index')" :hint="$n($u['leaflets_total']).' no total'" />
            <x-knowledge-admin.kpi label="Ancoragem das respostas" :value="$pct($u['grounding_rate_week'])" :href="route('admin.analytics.index')" :hint="$n($u['distinct_users_week']).' pessoas consultaram'" tone="brand" />
        </div>
    </section>

    <x-knowledge-admin.section title="Operação" description="Processador da fila e serviços de análise; atualiza sozinho a cada 30 segundos.">
        <x-slot:actions><x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.queue')">Fila e serviços</x-knowledge-admin.button></x-slot:actions>
        <livewire:admin.knowledge.operation-panel />
    </x-knowledge-admin.section>

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
        <div><x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.documents.index')">Ver documentos</x-knowledge-admin.button></div>
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.ops-disclosure title="Inteligência artificial" :summary="$n($ai['calls_month']).' chamadas no mês · US$ '.number_format($ai['cost_month_usd'], 2, ',', '.').($ai['failed_month'] > 0 ? ' · '.$n($ai['failed_month']).' com falha' : '')">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <x-knowledge-admin.kpi label="Chamadas no mês" :value="$n($ai['calls_month'])" :href="route('admin.analytics.index')" />
            <x-knowledge-admin.kpi label="Com falha" :value="$n($ai['failed_month'])" :tone="$ai['failed_month'] > 0 ? 'danger' : 'default'" :href="route('admin.analytics.index')" />
            <x-knowledge-admin.kpi label="Custo estimado" :value="'US$ '.number_format($ai['cost_month_usd'], 2, ',', '.')" :href="route('admin.analytics.index')" hint="Tabela de preços interna" />
            <x-knowledge-admin.kpi label="Latência média" :value="$ai['latency_avg_ms'] !== null ? number_format($ai['latency_avg_ms'], 0, ',', '.').' ms' : '—'" />
        </div>
        <x-knowledge-admin.facts class="sm:grid-cols-3">
            <x-knowledge-admin.fact label="Texto">{{ $ai['text_provider'] ?? 'não configurado' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Visão (receitas)">{{ $ai['vision_provider'] ?? 'não configurada' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Índice de busca">{{ $ai['embedding_provider'] ?? 'não configurado' }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
        <div><x-knowledge-admin.button variant="secondary" :href="route('admin.analytics.index')">Custos e falhas</x-knowledge-admin.button></div>
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.ops-disclosure title="Pessoas" :summary="$n($p['active']).' com acesso ativo · '.$n($p['logged_in_week']).' entraram na semana'.($p['never_logged_in'] > 0 ? ' · '.$n($p['never_logged_in']).' nunca entraram' : '')">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <x-knowledge-admin.kpi label="Usuários" :value="$n($p['users'])" :href="route('admin.users.index')" :hint="$n($p['admins']).' administradores'" />
            <x-knowledge-admin.kpi label="Com acesso ativo" :value="$n($p['active'])" :href="route('admin.users.index', ['status' => 'active'])" />
            <x-knowledge-admin.kpi label="Entraram na semana" :value="$n($p['logged_in_week'])" :href="route('admin.users.index', ['ordem' => 'recent_login'])" />
            <x-knowledge-admin.kpi label="Nunca entraram" :value="$n($p['never_logged_in'])" :tone="$p['never_logged_in'] > 0 ? 'warning' : 'default'" :href="route('admin.users.index')" />
        </div>
        <div><x-knowledge-admin.button variant="secondary" :href="route('admin.users.index')">Ver usuários</x-knowledge-admin.button></div>
    </x-knowledge-admin.ops-disclosure>
</x-knowledge-admin.shell>
