@php
    $widthClass = match (true) {
        $percent >= 100 => 'w-full', $percent >= 90 => 'w-11/12', $percent >= 80 => 'w-4/5', $percent >= 65 => 'w-2/3',
        $percent >= 50 => 'w-1/2', $percent >= 35 => 'w-1/3', $percent >= 15 => 'w-1/6', $percent > 0 => 'w-1/12', default => 'w-0',
    };
@endphp
<section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-live="polite">
<div class="grid gap-3" @if ($active) wire:poll.4s @endif x-data x-on:bulai-realtime.window="$wire.$refresh()">
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <p class="font-medium text-slate-800">{{ $active ? 'Processando os arquivos… '.$percent.'%' : 'Processamento concluído' }}</p>
        <p class="text-slate-500">{{ $batch->total_items }} {{ $batch->total_items === 1 ? 'arquivo' : 'arquivos' }}</p>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Andamento da importação"><div class="h-full rounded-full {{ $active ? 'bg-brand-600' : 'bg-emerald-500' }} transition-all {{ $widthClass }}"></div></div>
    <dl class="grid grid-cols-2 gap-2 sm:grid-cols-5">
        @foreach ($counters as [$label, $value, $tone])
            <div class="rounded-lg px-3 py-2 {{ match ($tone) { 'success' => 'bg-emerald-50', 'warning' => 'bg-amber-50', 'danger' => 'bg-rose-50', default => 'bg-slate-50' } }}">
                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                <dd class="text-xl font-semibold {{ match ($tone) { 'success' => 'text-emerald-800', 'warning' => 'text-amber-900', 'danger' => 'text-rose-800', default => 'text-slate-900' } }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</div>
</section>
