<x-knowledge-admin.shell title="Analytics" subtitle="Tokens e custo de cada provedor de IA, separados, e de cada usuário. Os números vêm do uso informado pela própria API em cada chamada.">
@php
    $n = fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $usd = fn ($value): string => 'US$ '.number_format((float) $value, 4, ',', '.');
    $providerLabels = ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'openai_compatible' => 'Compatível com OpenAI', 'llamafile' => 'IA local (llamafile)'];
    $label = fn (string $provider): string => $providerLabels[$provider] ?? $provider;
    $totalCost = array_sum(array_map(fn (array $row): float => $row['totals']['cost'], $providers));
    $totalTokens = array_sum(array_map(fn (array $row): int => $row['totals']['total_tokens'], $providers));
    $totalCalls = array_sum(array_map(fn (array $row): int => $row['totals']['calls'], $providers));
    $abas = [['key' => 'visao', 'label' => 'Visão geral', 'href' => route('admin.analytics.index')], ['key' => 'consultas', 'label' => 'Consultas', 'href' => route('admin.analytics.consultations.index')], ['key' => 'perguntas', 'label' => 'Perguntas', 'href' => route('admin.analytics.questions.index')], ['key' => 'bulas', 'label' => 'Bulas humanizadas', 'href' => route('admin.analytics.leaflets.index')], ['key' => 'consumo', 'label' => 'Consumo de IA', 'href' => route('admin.analytics.ai-usage.index')]];
@endphp
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Seções do Analytics">
        @foreach ($abas as $aba)
            <a href="{{ $aba['href'] }}" wire:navigate class="-mb-px inline-flex shrink-0 items-center border-b-2 px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $aba['key'] === 'consumo' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" @if ($aba['key'] === 'consumo') aria-current="page" @endif>{{ $aba['label'] }}</a>
        @endforeach
    </nav>

    <x-knowledge-admin.toolbar :count="$n($totalCalls).' '.($totalCalls === 1 ? 'chamada' : 'chamadas')">
        <x-knowledge-admin.select label="Período" compact wire:model.live="period" wrapper-class="w-full sm:w-52">
            @foreach ($periods as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
        </x-knowledge-admin.select>
        <x-knowledge-admin.select label="Provedor" compact wire:model.live="provider" wrapper-class="w-full sm:w-60">
            <option value="">Todos os provedores</option>
            @foreach ($providerOptions as $value)<option value="{{ $value }}">{{ $label($value) }}</option>@endforeach
        </x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>

    <div class="grid gap-6" wire:loading.class="opacity-60" wire:target="period,provider">
        <x-knowledge-admin.kpis>
            <x-knowledge-admin.kpi label="Custo estimado" :value="$usd($totalCost)" :hint="$n($totalTokens).' tokens'" tone="brand" />
            <x-knowledge-admin.kpi label="Usuários com uso" :value="$n($averages['users'])" :hint="$n($averages['calls_avg'], 1).' chamadas por usuário, em média'" />
            <x-knowledge-admin.kpi label="Custo médio por usuário" :value="$usd($averages['cost_avg'])" :hint="'mediana '.$usd($averages['cost_median']).' · maior '.$usd($averages['cost_max'])" />
            <x-knowledge-admin.kpi label="Tokens por usuário" :value="$n($averages['tokens_avg'])" :hint="'mediana '.$n($averages['tokens_median'])" />
            <x-knowledge-admin.kpi label="Custo médio por chamada" :value="$averages['cost_per_call'] !== null ? $usd($averages['cost_per_call']) : '—'" :hint="$averages['tokens_per_call'] !== null ? $n($averages['tokens_per_call']).' tokens por chamada' : 'sem chamadas de usuários'" />
            <x-knowledge-admin.kpi label="Sem usuário" :value="$usd($averages['unattributed']['cost'])" :hint="$n($averages['unattributed']['calls']).' chamadas de rotinas, avaliações e comandos'" />
        </x-knowledge-admin.kpis>

        @forelse ($providers as $row)
            @php $t = $row['totals']; @endphp
            <x-knowledge-admin.section wire:key="provider-{{ $row['provider'] }}" :title="$label($row['provider'])" :description="$n($t['calls']).' chamadas · '.$n($t['failed']).' falhas · '.$n($row['users']).' usuários · '.$usd($t['cost'])">
                <x-knowledge-admin.facts class="sm:grid-cols-3 lg:grid-cols-6">
                    <x-knowledge-admin.fact label="Entrada (total)">{{ $n($t['input_tokens']) }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Lida do cache">{{ $n($t['cache_read_tokens']) }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Gravada no cache">{{ $n($t['cache_write_tokens']) }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Saída">{{ $n($t['output_tokens']) }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Raciocínio (informado)">{{ $t['reasoning_tokens'] > 0 ? $n($t['reasoning_tokens']) : 'não informado' }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Sem preço configurado">{{ $n($t['unpriced']) }} {{ $t['unpriced'] === 1 ? 'chamada' : 'chamadas' }}</x-knowledge-admin.fact>
                </x-knowledge-admin.facts>
                <x-knowledge-admin.data-table :columns="[['label' => 'Modelo'], ['label' => 'Capacidade'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Entrada', 'align' => 'right'], ['label' => 'Cache lido', 'align' => 'right'], ['label' => 'Cache gravado', 'align' => 'right'], ['label' => 'Saída', 'align' => 'right'], ['label' => 'Custo', 'align' => 'right']]" dense>
                    @foreach ($row['models'] as $model)
                        <tr wire:key="model-{{ $row['provider'] }}-{{ $model['model'] }}-{{ $model['capability'] }}"><td>{{ $model['model'] }}</td><td>{{ $model['capability'] }}</td><td class="tabular">{{ $n($model['calls']) }}</td><td class="tabular">{{ $n($model['input_tokens']) }}</td><td class="tabular">{{ $n($model['cache_read_tokens']) }}</td><td class="tabular">{{ $n($model['cache_write_tokens']) }}</td><td class="tabular">{{ $n($model['output_tokens']) }}</td><td class="tabular">{{ $usd($model['cost']) }}</td></tr>
                    @endforeach
                </x-knowledge-admin.data-table>
            </x-knowledge-admin.section>
        @empty
            <x-knowledge-admin.empty-state>Nenhuma chamada de IA no período. Escolha outro período ou provedor.</x-knowledge-admin.empty-state>
        @endforelse

        <x-knowledge-admin.section title="Consumo por usuário" description="Do maior custo para o menor. Tokens totais = entrada (inclusive cache) + saída.">
            <x-knowledge-admin.data-table :columns="[['label' => 'Usuário'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Dias com uso', 'align' => 'right'], ['label' => 'Entrada', 'align' => 'right'], ['label' => 'Cache lido', 'align' => 'right'], ['label' => 'Saída', 'align' => 'right'], ['label' => 'Tokens totais', 'align' => 'right'], ['label' => 'Custo', 'align' => 'right'], ['label' => 'Por chamada', 'align' => 'right'], ['label' => 'Último uso'], ['label' => 'Ações', 'align' => 'right']]">
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->user_id }}">
                        <td><a class="rounded font-medium hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.analytics.ai-usage.show', ['user' => $user->user_id, 'periodo' => $period, 'provedor' => $provider ?: null]) }}" wire:navigate>{{ $user->name }}</a><div class="text-xs text-slate-500">{{ $user->email }}</div></td>
                        <td class="tabular">{{ $n($user->calls) }}@if ($user->failed > 0)<span class="text-xs text-rose-700"> ({{ $n($user->failed) }} falhas)</span>@endif</td>
                        <td class="tabular">{{ $n($user->active_days) }}</td>
                        <td class="tabular">{{ $n($user->input_tokens) }}</td>
                        <td class="tabular">{{ $n($user->cache_read_tokens) }}</td>
                        <td class="tabular">{{ $n($user->output_tokens) }}</td>
                        <td class="tabular">{{ $n($user->total_tokens) }}</td>
                        <td class="tabular">{{ $usd($user->cost) }}@if ($user->unpriced > 0)<span class="block text-xs text-amber-700">{{ $n($user->unpriced) }} sem preço</span>@endif</td>
                        <td class="tabular">{{ $user->calls > 0 ? $usd($user->cost / $user->calls) : '—' }}</td>
                        <td class="tabular whitespace-nowrap text-slate-600">{{ $user->last_used_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                        <td><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.analytics.ai-usage.show', ['user' => $user->user_id, 'periodo' => $period, 'provedor' => $provider ?: null])">Detalhar</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                    </tr>
                @endforeach
                @if ($users->isEmpty())
                    <x-slot:empty>Nenhum consumo atribuído a usuários neste período. Chamadas anteriores a 08/11/2026 não têm usuário registrado.</x-slot:empty>
                @endif
            </x-knowledge-admin.data-table>
            {{ $users->links() }}
        </x-knowledge-admin.section>

        <p class="text-xs text-slate-500">Custo estimado na hora de cada chamada pela tabela de preços do provedor em <code class="rounded bg-slate-100 px-1">config/ai.php</code> (entrada, cache e saída com preços próprios). Chamadas sem preço configurado entram nos tokens, não no custo. Exportação detalhada por usuário: tela Relatórios, "Consumo e custo de IA".</p>
    </div>
</x-knowledge-admin.shell>
