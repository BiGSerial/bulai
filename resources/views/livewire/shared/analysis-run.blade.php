@php
    $relationBadge = ['equal' => ['success', 'Concordam'], 'different' => ['warning', 'Divergem'], 'only_ai' => ['info', 'Só a IA'], 'only_heuristic' => ['neutral', 'Só a heurística'], 'only_catalog' => ['info', 'Só o catálogo']];
    $interactive = $interactive ?? false;
    $rows = $classification['comparison'] ?? [];
@endphp
<div class="grid gap-4">
    <ol class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <li class="rounded-lg border border-slate-200 p-3"><p class="text-xs text-slate-500">1. Leitura do texto</p><p class="mt-1 text-sm font-semibold text-slate-900">{{ $extraction['method_label'] ?? 'Sem registro' }}</p></li>
        <li class="rounded-lg border border-slate-200 p-3"><p class="text-xs text-slate-500">2. Heurística</p><p class="mt-1 text-sm font-semibold text-slate-900">{{ $classification ? $classification['heuristic_score'].'/100' : 'Sem registro' }}</p></li>
        <li class="rounded-lg border border-slate-200 p-3"><p class="text-xs text-slate-500">3. IA local</p><p class="mt-1 text-sm font-semibold text-slate-900">@if ($classification && $classification['ai_used'])Consultada{{ $classification['forced'] ? ' a pedido' : '' }}@if ($classification['confidence'] !== null) · {{ number_format($classification['confidence'] * 100, 0) }}%@endif @else Não consultada @endif</p></li>
        <li class="rounded-lg border p-3 {{ match ($classification['tone'] ?? 'neutral') { 'success' => 'border-emerald-200 bg-emerald-50', 'danger' => 'border-rose-200 bg-rose-50', 'warning' => 'border-amber-200 bg-amber-50', default => 'border-slate-200' } }}"><p class="text-xs text-slate-500">4. Decisão automática</p><p class="mt-1 text-sm font-semibold text-slate-900">{{ $classification['label'] ?? 'Sem registro' }}</p></li>
    </ol>

    @if ($classification && $rows !== [])
        <div class="grid gap-2">
            <h3 class="text-sm font-semibold text-slate-900">Quadro comparativo: heurística × catálogo ANVISA × IA local</h3>
            <x-knowledge-admin.table :columns="['Campo', 'Heurística (PDF)', 'Catálogo ANVISA', 'IA local', 'Situação']">
                @foreach ($rows as $row)
                    <tr wire:key="cmp-{{ $row['field'] }}" class="{{ $row['relation'] === 'different' ? 'bg-amber-50' : '' }}">
                        <td class="font-medium text-slate-900">{{ $row['label'] }}</td>
                        <td class="{{ $row['heuristic'] ? 'text-slate-800' : 'text-slate-400' }}">{{ $row['heuristic'] ?? 'não encontrado' }}</td>
                        <td>
                            <label class="flex items-start gap-2">
                                @if ($interactive && $row['catalog_selectable'])<input type="checkbox" class="mt-0.5 size-4 rounded border-slate-300 text-brand-700" wire:model.live="selectedAiFields" value="catalog:{{ $row['field'] }}" aria-label="Aplicar {{ $row['label'] }} do catálogo">@endif
                                <span class="{{ $row['catalog'] ? 'text-slate-900' : 'text-slate-400' }}">{{ $row['catalog'] ?? '—' }}</span>
                            </label>
                        </td>
                        <td>
                            <label class="flex items-start gap-2">
                                @if ($interactive && $row['ai_selectable'])<input type="checkbox" class="mt-0.5 size-4 rounded border-slate-300 text-brand-700" wire:model.live="selectedAiFields" value="ai:{{ $row['field'] }}" aria-label="Aplicar {{ $row['label'] }} da IA">@endif
                                <span>
                                    <span class="{{ $row['ai'] ? 'font-medium text-slate-900' : 'text-slate-400' }}">{{ $row['ai'] ?? ($classification['ai_used'] ? 'não encontrado' : 'não consultada') }}</span>
                                    @if ($row['ai'] !== null && $row['grounded'] !== null)<span class="mt-0.5 block text-xs {{ $row['grounded'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $row['grounded'] ? 'Aparece no texto enviado à IA' : 'Não aparece no texto enviado à IA' }}</span>@endif
                                </span>
                            </label>
                        </td>
                        <td><x-knowledge-admin.badge :tone="$relationBadge[$row['relation']][0]">{{ $relationBadge[$row['relation']][1] }}</x-knowledge-admin.badge></td>
                    </tr>
                @endforeach
            </x-knowledge-admin.table>
            @if ($classification['evidence'])
                <details class="rounded-lg border border-slate-200 p-3"><summary class="cursor-pointer text-sm font-medium text-slate-800">Trechos citados pela IA ({{ count($classification['evidence']) }})</summary>
                    <div class="mt-2 grid gap-2">@foreach ($classification['evidence'] as $quote)<p class="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">“{{ $quote }}”</p>@endforeach</div>
                </details>
            @endif
        </div>
    @endif

    @if ($classification)
        <x-knowledge-admin.alert :tone="match ($classification['tone']) { 'success' => 'success', 'danger' => 'danger', 'neutral' => 'info', default => 'warning' }">
            <p class="font-medium">Decisão automática: {{ $classification['label'] }}</p>
            <p class="mt-1">{{ $classification['explanation'] }}@if ($classification['reason']) {{ $classification['reason'] }}@endif @if ($classification['error']) {{ $classification['error'] }}@endif
            @if ($classification['gap_fill'])<p class="mt-1">A IA foi consultada só para completar o princípio ativo, que faltava; isso não altera a decisão pela pontuação.</p>@endif</p>
            @if (in_array($classification['decision'], ['AI_REJECTED', 'AI_SUGGESTED', 'AI_LOW_CONFIDENCE'], true) && $interactive)<p class="mt-1">A regra automática nunca deixa a IA contradizer a heurística. A decisão final é sua: use o quadro acima.</p>@endif
        </x-knowledge-admin.alert>
    @endif

    @if ($classification && $classification['ai_used'])
        <details class="rounded-lg border border-slate-200 p-3">
            <summary class="cursor-pointer text-sm font-medium text-slate-800">Prompt enviado à IA e resposta bruta</summary>
            @if ($classification['prompt'])
                <div class="mt-3 grid gap-3 text-sm">
                    <p class="text-xs text-slate-500">Somente trechos curtos já extraídos do PDF; o arquivo inteiro nunca é enviado.@if ($classification['prompt']['sha256']) Assinatura (SHA-256): <span class="font-mono">{{ $classification['prompt']['sha256'] }}</span>@endif</p>
                    @if ($classification['prompt']['params'])<p class="text-xs text-slate-500">Parâmetros: @foreach ($classification['prompt']['params'] as $name => $value){{ $name }} = {{ $value }}@if (! $loop->last) · @endif @endforeach</p>@endif
                    @foreach (['system' => 'Instrução do sistema', 'user' => 'Dados enviados (usuário)', 'raw_response' => 'Resposta bruta da IA'] as $part => $title)
                        @if ($classification['prompt'][$part])
                            <div x-data="{ copied: false }">
                                <div class="mb-1 flex items-center justify-between gap-2"><p class="font-medium text-slate-800">{{ $title }}</p>
                                    <x-knowledge-admin.button variant="quiet" x-on:click="navigator.clipboard.writeText(@js($classification['prompt'][$part])); copied = true; setTimeout(() => copied = false, 1500)"><span x-show="! copied">Copiar</span><span x-show="copied" x-cloak>Copiado</span></x-knowledge-admin.button>
                                </div>
                                <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{{ $classification['prompt'][$part] }}</pre>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                <p class="mt-3 text-sm text-slate-500">O prompt não foi gravado nesta análise (execução anterior ao recurso ou gravação desativada).</p>
            @endif
        </details>
    @endif

    <details class="rounded-lg border border-slate-200 p-3">
        <summary class="cursor-pointer text-sm font-medium text-slate-800">Detalhes técnicos de cada etapa</summary>
        <div class="mt-3">
<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-2 rounded-lg border border-slate-200 p-4">
        <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-slate-900">1. Leitura do texto</h3>
            @if ($extraction)<x-knowledge-admin.badge :tone="$extraction['tone']">{{ $extraction['method_label'] }}</x-knowledge-admin.badge>@endif
        </div>
        @if ($extraction)
            <p class="text-sm text-slate-600">{{ $extraction['note'] }}</p>
            <dl class="grid gap-1 text-sm">
                @if ($extraction['quality'] !== null)<div class="flex justify-between gap-2"><dt class="text-slate-500">Qualidade do texto nativo</dt><dd class="font-medium text-slate-900">{{ $extraction['quality'] }}/100</dd></div>@endif
                @if ($extraction['quality_after_ocr'] !== null)<div class="flex justify-between gap-2"><dt class="text-slate-500">Qualidade após OCR</dt><dd class="font-medium text-slate-900">{{ $extraction['quality_after_ocr'] }}/100</dd></div>@endif
                @if ($extraction['engine'])<div class="flex justify-between gap-2"><dt class="text-slate-500">Motor de OCR</dt><dd class="font-medium text-slate-900">{{ $extraction['engine'] }}</dd></div>@endif
                @if ($extraction['pages'] !== null)<div class="flex justify-between gap-2"><dt class="text-slate-500">Páginas lidas</dt><dd class="font-medium text-slate-900">{{ $extraction['pages'] }}</dd></div>@endif
                @if ($extraction['duration_ms'] !== null)<div class="flex justify-between gap-2"><dt class="text-slate-500">Tempo</dt><dd class="font-medium text-slate-900">{{ number_format($extraction['duration_ms'] / 1000, 1, ',', '.') }} s</dd></div>@endif
            </dl>
            @if ($extraction['reasons'])<p class="text-xs text-slate-500">Sinais de qualidade: {{ implode(', ', $extraction['reasons']) }}.</p>@endif
        @else
            <p class="text-sm text-slate-500">Sem registro desta etapa.</p>
        @endif
    </div>

    <div class="grid content-start gap-2 rounded-lg border border-slate-200 p-4">
        <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-slate-900">2. Heurística</h3>
            @if ($classification)<x-knowledge-admin.badge>{{ $classification['heuristic_score'] }}/100</x-knowledge-admin.badge>@endif
        </div>
        @if ($classification)
            <p class="text-sm text-slate-600">
                @if ($classification['thresholds'])
                    Aceita com {{ $classification['thresholds']['auto_accept_score'] }} pontos ou mais; consulta a IA entre {{ $classification['thresholds']['ai_min_score'] }} e {{ $classification['thresholds']['auto_accept_score'] - 1 }}; abaixo disso vai para revisão manual.
                @else
                    Pontuação calculada a partir de nome, registro ANVISA e tipo encontrados no texto.
                @endif
            </p>
        @else
            <p class="text-sm text-slate-500">Sem registro desta etapa.</p>
        @endif
    </div>

    <div class="grid content-start gap-2 rounded-lg border border-slate-200 p-4">
        <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-slate-900">3. IA local</h3>
            @if ($classification)<x-knowledge-admin.badge :tone="$classification['ai_used'] ? 'info' : 'neutral'">{{ $classification['ai_used'] ? 'Consultada' : 'Não consultada' }}</x-knowledge-admin.badge>@endif
        </div>
        @if ($classification && $classification['ai_used'])
            <dl class="grid gap-1 text-sm">
                @if ($classification['model'])<div class="flex justify-between gap-2"><dt class="text-slate-500">Modelo</dt><dd class="font-medium text-slate-900">{{ $classification['model'] }}</dd></div>@endif
                @if ($classification['confidence'] !== null)<div class="flex justify-between gap-2"><dt class="text-slate-500">Confiança</dt><dd class="font-medium text-slate-900">{{ number_format($classification['confidence'] * 100, 0) }}%</dd></div>@endif
                @if ($classification['duration_ms'] !== null)<div class="flex justify-between gap-2"><dt class="text-slate-500">Tempo</dt><dd class="font-medium text-slate-900">{{ number_format($classification['duration_ms'] / 1000, 1, ',', '.') }} s</dd></div>@endif
            </dl>
            @if ($classification['error'])<p class="text-sm text-rose-700">{{ $classification['error'] }}</p>@endif
            @if ($classification['suggestion'])
                <div class="grid gap-1 rounded-lg bg-slate-50 p-3 text-sm">
                    <p class="font-medium text-slate-800">O que a IA respondeu</p>
                    @foreach ($classification['suggestion'] as $label => $value)<p><span class="text-slate-500">{{ $label }}:</span> {{ $value }}</p>@endforeach
                    @foreach ($classification['evidence'] as $quote)<p class="text-xs text-slate-500">“{{ $quote }}”</p>@endforeach
                </div>
            @endif
        @elseif ($classification)
            <p class="text-sm text-slate-600">{{ $classification['explanation'] }}</p>
        @else
            <p class="text-sm text-slate-500">Sem registro desta etapa.</p>
        @endif
    </div>
</div>

        </div>
    </details>
</div>
