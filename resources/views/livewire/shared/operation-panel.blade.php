@php
    $workerTone = ['running' => 'success', 'idle' => 'success', 'stopped' => 'danger'][$worker['state']] ?? 'neutral';
    $workerLabel = ['running' => 'Processando', 'idle' => 'Aguardando tarefas', 'stopped' => 'Parado'][$worker['state']] ?? $worker['state'];
    $names = ['local_ai' => 'IA local', 'ocr' => 'Leitura de PDF (OCR)', 'embeddings' => 'Índice de busca'];
@endphp
<div wire:poll.30s x-data x-on:bulai-realtime.window="$wire.$refresh()" class="grid gap-3" data-testid="operation-panel">
    <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <x-knowledge-admin.kpi label="Processador da fila" :value="$workerLabel" :tone="$workerTone === 'danger' ? 'danger' : 'success'" :href="route('admin.knowledge.queue')" :hint="$worker['last_seen_at'] ? 'Último sinal '.\Illuminate\Support\Carbon::createFromTimestamp($worker['last_seen_at'])->diffForHumans() : 'Sem sinal'" />
        <x-knowledge-admin.kpi label="Na fila" :value="$jobs['waiting']" :href="route('admin.knowledge.queue')" :hint="$jobs['running'].' rodando agora'" />
        <x-knowledge-admin.kpi label="Com falha" :value="$jobs['failed']" :tone="$jobs['failed'] > 0 ? 'danger' : 'default'" :href="route('admin.knowledge.queue')" :hint="$jobs['failed'] > 0 ? 'Precisam de atenção' : 'Nenhuma'" />
        <x-knowledge-admin.kpi label="Análises em andamento" :value="$inProgress" :tone="$stale > 0 ? 'warning' : 'default'" :href="route('admin.knowledge.queue')" :hint="$stale > 0 ? $stale.' sem progresso recente' : 'Com progresso recente'" />
    </div>
    <ul class="flex flex-wrap gap-2" aria-label="Serviços">
        @foreach ($names as $key => $name)
            @php($health = $services[$key])
            <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-3 pr-1 text-sm text-slate-700 ring-1 ring-slate-200" data-service="{{ $key }}" data-state="{{ $health->state }}" title="{{ $health->model ?? '' }}">
                {{ $name }} <x-knowledge-admin.badge :tone="$health->tone()">{{ $health->label() }}</x-knowledge-admin.badge>
            </li>
        @endforeach
        <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-3 pr-1 text-sm text-slate-700 ring-1 ring-slate-200" title="{{ $textProvider ?? '' }}{{ $visionProvider ? ' · visão: '.$visionProvider : '' }}">
            IA de texto e visão <x-knowledge-admin.badge :tone="$textProvider ? 'success' : 'neutral'">{{ $textProvider ? 'Configurada' : 'Desativada' }}</x-knowledge-admin.badge>
        </li>
    </ul>
</div>
