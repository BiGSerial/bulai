{{--
    Linha do tempo do processamento de uma bula: uma linha densa por etapa (nome, estado, detalhes curtos, duração).
    `events`: coleção de App\Models\Knowledge\KnowledgeProcessingEvent em ordem cronológica (ProcessingTimeline::for()).
--}}
@props(['events'])
@if ($events->isEmpty())
    <p {{ $attributes->class('text-sm text-slate-500') }}>Ainda não há etapas registradas. Elas aparecem aqui assim que o processamento começa.</p>
@else
    <ol {{ $attributes->class('grid gap-1.5') }} aria-label="Linha do tempo do processamento">
        @foreach ($events as $event)
            <li wire:key="timeline-{{ $event->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200" data-step="{{ $event->step }}" data-state="{{ $event->state }}">
                <x-knowledge-admin.badge :tone="$event->tone()" class="w-28 justify-center">{{ $event->stateLabel() }}</x-knowledge-admin.badge>
                <span class="min-w-0 flex-1 text-sm font-semibold text-slate-900">{{ $event->stepLabel() }}@if ($event->summary() !== '') <span class="font-normal text-slate-500">· {{ $event->summary() }}</span>@endif</span>
                <span class="shrink-0 text-xs tabular-nums text-slate-500" title="{{ $event->started_at->format('d/m/Y H:i:s') }}">{{ $event->durationLabel() ?? '—' }}</span>
            </li>
        @endforeach
    </ol>
@endif
