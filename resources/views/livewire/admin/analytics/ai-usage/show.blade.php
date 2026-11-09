<x-knowledge-admin.shell :title="'Consumo de IA · '.$user->name" :subtitle="$user->email" :back="route('admin.analytics.ai-usage.index', ['periodo' => $period, 'provedor' => $provider ?: null])" backLabel="Consumo de IA">
@php
    $n = fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $usd = fn ($value): string => $value === null ? '—' : 'US$ '.number_format((float) $value, 6, ',', '.');
    $providerLabels = ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'openai_compatible' => 'Compatível com OpenAI', 'llamafile' => 'IA local (llamafile)'];
    $label = fn (string $provider): string => $providerLabels[$provider] ?? $provider;
    $t = $usage['totals'];
@endphp
    <x-knowledge-admin.toolbar :count="$n($t['calls']).' '.($t['calls'] === 1 ? 'chamada' : 'chamadas')">
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
            <x-knowledge-admin.kpi label="Custo estimado" :value="$usd($t['cost'])" :hint="$t['unpriced'] > 0 ? $n($t['unpriced']).' chamadas sem preço' : null" tone="brand" />
            <x-knowledge-admin.kpi label="Tokens totais" :value="$n($t['total_tokens'])" :hint="$t['calls'] > 0 ? $n($t['total_tokens'] / $t['calls']).' por chamada' : null" />
            <x-knowledge-admin.kpi label="Entrada" :value="$n($t['input_tokens'])" :hint="$n($t['cache_read_tokens']).' lidos do cache · '.$n($t['cache_write_tokens']).' gravados'" />
            <x-knowledge-admin.kpi label="Saída" :value="$n($t['output_tokens'])" :hint="$t['reasoning_tokens'] > 0 ? $n($t['reasoning_tokens']).' de raciocínio' : 'inclui o raciocínio'" />
            <x-knowledge-admin.kpi label="Chamadas" :value="$n($t['calls'])" :hint="$n($t['failed']).' falhas'" :tone="$t['failed'] > 0 ? 'warning' : 'default'" />
            <x-knowledge-admin.kpi label="Custo por chamada" :value="$t['calls'] > 0 ? $usd($t['cost'] / $t['calls']) : '—'" />
        </x-knowledge-admin.kpis>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-knowledge-admin.section title="Por provedor e modelo">
                <x-knowledge-admin.data-table :columns="[['label' => 'Provedor'], ['label' => 'Modelo'], ['label' => 'Capacidade'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Tokens', 'align' => 'right'], ['label' => 'Custo', 'align' => 'right']]" dense>
                    @foreach ($usage['models'] as $row)
                        <tr><td>{{ $label($row['provider']) }}</td><td>{{ $row['model'] }}</td><td>{{ $row['capability'] }}</td><td class="tabular">{{ $n($row['calls']) }}</td><td class="tabular">{{ $n($row['total_tokens']) }}</td><td class="tabular">{{ $usd($row['cost']) }}</td></tr>
                    @endforeach
                    @if ($usage['models'] === [])
                        <x-slot:empty>Sem chamadas no período.</x-slot:empty>
                    @endif
                </x-knowledge-admin.data-table>
            </x-knowledge-admin.section>
            <x-knowledge-admin.section title="Por origem" description="Tela ou rotina que gerou a chamada.">
                <x-knowledge-admin.data-table :columns="[['label' => 'Origem'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Tokens', 'align' => 'right'], ['label' => 'Custo', 'align' => 'right']]" dense>
                    @foreach ($usage['origins'] as $row)
                        <tr><td><code class="text-xs">{{ $row['origin'] ?? 'não registrada' }}</code></td><td class="tabular">{{ $n($row['calls']) }}</td><td class="tabular">{{ $n($row['total_tokens']) }}</td><td class="tabular">{{ $usd($row['cost']) }}</td></tr>
                    @endforeach
                    @if ($usage['origins'] === [])
                        <x-slot:empty>Sem chamadas no período.</x-slot:empty>
                    @endif
                </x-knowledge-admin.data-table>
            </x-knowledge-admin.section>
        </div>

        <x-knowledge-admin.ops-disclosure title="Por dia" summary="Até 31 dias mais recentes do período.">
            <x-knowledge-admin.data-table :columns="[['label' => 'Dia'], ['label' => 'Chamadas', 'align' => 'right'], ['label' => 'Entrada', 'align' => 'right'], ['label' => 'Saída', 'align' => 'right'], ['label' => 'Custo', 'align' => 'right']]" dense>
                @foreach ($usage['daily'] as $row)
                    <tr><td class="tabular">{{ \Carbon\Carbon::parse($row['day'])->format('d/m/Y') }}</td><td class="tabular">{{ $n($row['calls']) }}</td><td class="tabular">{{ $n($row['input_tokens']) }}</td><td class="tabular">{{ $n($row['output_tokens']) }}</td><td class="tabular">{{ $usd($row['cost']) }}</td></tr>
                @endforeach
                @if ($usage['daily'] === [])
                    <x-slot:empty>Sem chamadas no período.</x-slot:empty>
                @endif
            </x-knowledge-admin.data-table>
        </x-knowledge-admin.ops-disclosure>

        <x-knowledge-admin.section title="Chamadas" description="Cada requisição com o uso exatamente como a API do provedor devolveu.">
            <x-knowledge-admin.data-table :columns="[['label' => 'Quando'], ['label' => 'Provedor / modelo'], ['label' => 'Origem'], ['label' => 'Entrada', 'align' => 'right'], ['label' => 'Cache lido / gravado', 'align' => 'right'], ['label' => 'Saída', 'align' => 'right'], ['label' => 'Custo', 'align' => 'right'], ['label' => 'Situação'], ['label' => 'Uso informado pela API']]" dense>
                @foreach ($calls as $call)
                    <tr wire:key="call-{{ $call->id }}">
                        <td class="tabular whitespace-nowrap text-slate-600">{{ $call->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $label($call->provider) }}<div class="text-xs text-slate-500">{{ $call->response_model ?? $call->model }} · {{ $call->capability }}</div></td>
                        <td><code class="text-xs">{{ $call->origin ?? '—' }}</code></td>
                        <td class="tabular">{{ $n($call->input_tokens) }}</td>
                        <td class="tabular">{{ $n($call->cache_read_tokens) }} / {{ $n($call->cache_write_tokens) }}</td>
                        <td class="tabular">{{ $n($call->output_tokens) }}</td>
                        <td class="tabular">{{ $usd($call->estimated_cost_usd) }}</td>
                        <td>
                            @if ($call->status === \App\Models\AiUsageLog::STATUS_SUCCESS)
                                <x-knowledge-admin.badge tone="success">ok</x-knowledge-admin.badge>
                            @else
                                <x-knowledge-admin.badge tone="danger">{{ $call->error_code ?? 'falha' }}</x-knowledge-admin.badge>
                            @endif
                        </td>
                        <td class="max-w-sm">
                            @if ($call->provider_usage)
                                <details><summary class="cursor-pointer text-xs text-brand-700">ver JSON</summary><pre class="mt-1 overflow-x-auto rounded bg-slate-100 p-2 text-xs">{{ json_encode($call->provider_usage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@if ($call->provider_request_id)<div class="mt-1 text-xs text-slate-500">id da requisição: {{ $call->provider_request_id }}</div>@endif</details>
                            @else
                                <span class="text-xs text-slate-500">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if ($calls->isEmpty())
                    <x-slot:empty>Sem chamadas no período.</x-slot:empty>
                @endif
            </x-knowledge-admin.data-table>
            {{ $calls->links() }}
        </x-knowledge-admin.section>
    </div>
</x-knowledge-admin.shell>
