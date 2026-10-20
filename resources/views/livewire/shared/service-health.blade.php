<div wire:poll.10s class="flex flex-wrap items-center gap-2" role="status" aria-live="polite" aria-label="Saúde dos serviços de IA" data-testid="service-health">
    @foreach (['local_ai' => 'IA local', 'ocr' => 'OCR'] as $key => $name)
        @php($health = $services[$key])
        <span class="inline-flex items-center gap-1.5 text-xs text-slate-600" data-service="{{ $key }}" data-state="{{ $health->state }}"
              title="{{ $name }}: {{ $health->label() }}{{ $health->model ? ' · '.$health->model : '' }}{{ $health->latencyMs !== null ? ' · '.$health->latencyMs.' ms' : '' }}">
            <span class="h-2 w-2 rounded-full {{ match ($health->tone()) { 'success' => 'bg-emerald-500', 'info' => 'bg-sky-500 animate-pulse', 'warning' => 'bg-amber-500', 'danger' => 'bg-rose-500', default => 'bg-slate-300' } }}" aria-hidden="true"></span>
            <span class="font-medium">{{ $name }}</span>
            <x-knowledge-admin.badge :tone="$health->tone()">{{ $health->label() }}</x-knowledge-admin.badge>
        </span>
    @endforeach
</div>
