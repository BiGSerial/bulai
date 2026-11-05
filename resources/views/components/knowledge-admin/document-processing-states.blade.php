@props(['document', 'compact' => false, 'inline' => false])
@php
    $states = [
        ['Arquivo PDF', 'PDF', $document->file_status, ['READY' => 'Disponível', 'PROCESSING' => 'Armazenando', 'FAILED' => 'Falhou']],
        ['Texto e trechos', 'Texto', $document->content_status, ['READY' => 'Processado', 'PROCESSING' => 'Processando', 'FAILED' => 'Falhou']],
        ['Índice de busca', 'Índice', $document->embedding_status, ['READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou']],
    ];
    $tone = fn (?string $status): string => match ($status) { 'READY' => 'success', 'PROCESSING' => 'info', 'FAILED' => 'danger', default => 'neutral' };
@endphp
@if ($inline)
    {{-- Uma linha com três selos curtos; o estado completo fica no `title` (listas densas). --}}
    <ul {{ $attributes->class('flex flex-wrap gap-1') }} aria-label="Situação do documento">
        @foreach ($states as [$label, $short, $status, $texts])
            <li><x-knowledge-admin.badge :tone="$tone($status)" title="{{ $label }}: {{ $texts[$status] ?? 'Aguardando' }}">{{ $short }} {{ match ($status) { 'READY' => '✓', 'PROCESSING' => '…', 'FAILED' => '✕', default => '·' } }}</x-knowledge-admin.badge></li>
        @endforeach
    </ul>
@else
    <dl {{ $attributes->class($compact ? 'grid gap-1.5' : 'flex flex-wrap gap-x-6 gap-y-2') }}>
        @foreach ($states as [$label, $short, $status, $texts])
            <div class="flex items-center justify-between gap-3">
                <dt class="text-sm text-slate-600">{{ $label }}</dt>
                <dd><x-knowledge-admin.badge :tone="$tone($status)">{{ $texts[$status] ?? 'Aguardando' }}</x-knowledge-admin.badge></dd>
            </div>
        @endforeach
    </dl>
@endif
