@props(['document', 'compact' => false])
@php
    $states = [
        ['Arquivo PDF', $document->file_status, ['READY' => 'Disponível', 'PROCESSING' => 'Armazenando', 'FAILED' => 'Falhou']],
        ['Texto e trechos', $document->content_status, ['READY' => 'Processado', 'PROCESSING' => 'Processando', 'FAILED' => 'Falhou']],
        ['Índice de busca', $document->embedding_status, ['READY' => 'Gerado', 'PROCESSING' => 'Gerando', 'FAILED' => 'Falhou']],
    ];
@endphp
<dl {{ $attributes->class($compact ? 'grid gap-1.5' : 'flex flex-wrap gap-x-6 gap-y-2') }}>
    @foreach ($states as [$label, $status, $texts])
        <div class="flex items-center justify-between gap-3">
            <dt class="text-sm text-slate-600">{{ $label }}</dt>
            <dd>
                <x-knowledge-admin.badge :tone="match ($status) { 'READY' => 'success', 'PROCESSING' => 'info', 'FAILED' => 'danger', default => 'neutral' }">{{ $texts[$status] ?? 'Aguardando' }}</x-knowledge-admin.badge>
            </dd>
        </div>
    @endforeach
</dl>
