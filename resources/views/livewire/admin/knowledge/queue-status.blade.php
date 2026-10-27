<x-knowledge-admin.shell title="Fila e serviços" subtitle="Acompanhe em tempo real o que está sendo analisado e reinicie os serviços se algo travar.">
    <div class="grid gap-5" wire:poll.5s x-data x-on:bulai-realtime.window="$wire.$refresh()">
@php
    $worker = $queue['worker'];
    $workerTone = ['running' => 'success', 'idle' => 'success', 'stopped' => 'danger'][$worker['state']];
    $workerLabel = ['running' => 'Processando', 'idle' => 'Aguardando tarefas', 'stopped' => 'Parado'][$worker['state']];
    $names = ['local_ai' => 'IA local', 'ocr' => 'OCR'];
@endphp
        @if ($feedback)<x-knowledge-admin.alert :tone="$feedbackType">{{ $feedback }}</x-knowledge-admin.alert>@endif

        <x-knowledge-admin.section title="Processador da fila" description="É ele quem executa as análises (OCR e IA). Sem ele, os arquivos ficam esperando para sempre.">
            <div class="flex flex-wrap items-center gap-3" data-testid="queue-worker" data-state="{{ $worker['state'] }}">
                <x-knowledge-admin.badge :tone="$workerTone">{{ $workerLabel }}</x-knowledge-admin.badge>
                @if ($worker['last_seen_at'])<span class="text-sm text-slate-500">Último sinal: {{ \Illuminate\Support\Carbon::createFromTimestamp($worker['last_seen_at'])->diffForHumans() }}@if ($worker['host']) · {{ $worker['host'] }}@endif</span>@endif
            </div>
            @if ($worker['state'] === 'stopped')
                <x-knowledge-admin.alert tone="danger">Nenhum processador da fila está ativo. Em desenvolvimento, rode <code class="font-mono text-xs">php artisan queue:work --timeout=600 --tries=1</code>; em produção, verifique o contêiner <code class="font-mono text-xs">worker</code>.</x-knowledge-admin.alert>
            @endif
            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg bg-slate-50 p-3"><p class="text-2xl font-semibold text-slate-900">{{ $queue['jobs']['waiting'] }}</p><p class="text-xs text-slate-600">Na fila</p></div>
                <div class="rounded-lg bg-slate-50 p-3"><p class="text-2xl font-semibold text-slate-900">{{ $queue['jobs']['running'] }}</p><p class="text-xs text-slate-600">Rodando</p></div>
                <div class="rounded-lg bg-slate-50 p-3"><p class="text-2xl font-semibold {{ $queue['jobs']['failed'] > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ $queue['jobs']['failed'] }}</p><p class="text-xs text-slate-600">Com falha</p></div>
            </div>
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Serviços de análise" description="Estado de cada serviço e o que ele está fazendo agora.">
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($names as $key => $name)
                    @php($health = $services[$key])
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4" data-service="{{ $key }}" data-state="{{ $health->state }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-base font-semibold text-slate-900">{{ $name }}</h3>
                            <x-knowledge-admin.badge :tone="$health->tone()">{{ $health->label() }}</x-knowledge-admin.badge>
                        </div>
                        <p class="text-sm text-slate-600">
                            @if ($health->isWorking())
                                <span class="inline-flex items-center gap-1.5 font-medium text-brand-700"><span class="size-2 animate-pulse rounded-full bg-current"></span>Trabalhando agora{{ $health->currentSeconds !== null ? ' há '.$health->currentSeconds.' s' : '' }}</span>
                                @if ($health->activeRequests)<span> · {{ $health->activeRequests }} em andamento</span>@endif
                            @elseif ($health->busy === false)
                                Livre, sem trabalho em andamento.
                            @else
                                Sem informação de atividade.
                            @endif
                            @if ($health->restarts)<span class="block text-xs text-slate-500">Reiniciado {{ $health->restarts }}x desde que subiu.</span>@endif
                        </p>
                        @if ($restartable[$key])
                            <div>
                                <x-knowledge-admin.confirm-modal :title="'Reiniciar '.$name.'?'" :message="'O '.$name.' será reiniciado e ficará indisponível por alguns instantes. Análises em andamento neste serviço serão interrompidas.'" confirm-label="Reiniciar" tone="danger" :action="'restartService(\''.$key.'\')'" loading="Reiniciando…">
                                    <x-knowledge-admin.button variant="secondary">Reiniciar {{ $name }}</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            </div>
                        @else
                            <p class="text-xs text-slate-500">Reinício remoto não configurado para este serviço.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Análises em andamento" :description="count($queue['items']) === 0 ? 'Nada na fila agora.' : count($queue['items']).' arquivo(s) esperando ou sendo analisados.'">
            @if ($queue['stale'] > 0)
                <x-knowledge-admin.alert tone="warning">
                    <span>{{ $queue['stale'] }} item(ns) sem andamento há mais de {{ config('knowledge.processing.progress_stale_minutes', 5) }} minutos.</span>
                    <x-knowledge-admin.button class="mt-2" variant="secondary" wire:click="requeueStale" loading-target="requeueStale" loading="Reenfileirando…">Reenfileirar parados</x-knowledge-admin.button>
                </x-knowledge-admin.alert>
            @endif
            <ul class="grid gap-2">
                @foreach ($queue['items'] as $item)
                    <li class="flex flex-col gap-2 rounded-lg border border-slate-200 p-3 sm:flex-row sm:items-center sm:justify-between" wire:key="queue-item-{{ $item['id'] }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $item['filename'] }}</p>
                            <p class="text-xs text-slate-500">{{ $item['label'] }} · {{ $item['percent'] }}%@if ($item['stale']) · <span class="font-medium text-amber-700">parado</span>@endif @if (! $item['has_job'] && $item['state'] === 'queued') · <span class="font-medium text-rose-700">sem tarefa na fila</span>@endif</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-knowledge-admin.badge :tone="$item['state'] === 'processing' ? 'info' : 'neutral'">{{ $item['state'] === 'processing' ? 'Processando' : 'Na fila' }}</x-knowledge-admin.badge>
                            <x-knowledge-admin.button variant="secondary" wire:click="requeueItem({{ $item['id'] }})" loading-target="requeueItem({{ $item['id'] }})" loading="Enfileirando…">Reenfileirar</x-knowledge-admin.button>
                            <x-knowledge-admin.button variant="quiet" wire:click="cancelItem({{ $item['id'] }})" loading-target="cancelItem({{ $item['id'] }})" loading="Cancelando…">Cancelar</x-knowledge-admin.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-knowledge-admin.section>
    </div>
</x-knowledge-admin.shell>
