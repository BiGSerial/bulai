@props(['status'])
@php
    [$label, $tone] = match ($status) {
        'ASSOCIATED' => ['Associado', 'success'],
        'READY' => ['Pronto', 'success'],
        'COMPLETED' => ['Concluída', 'success'],
        'PENDING' => ['Pendente', 'warning'],
        'QUEUED' => ['Na fila', 'info'],
        'PROCESSING' => ['Processando', 'info'],
        'DUPLICATE' => ['Duplicado', 'info'],
        'ERROR' => ['Erro', 'danger'],
        'FAILED' => ['Falhou', 'danger'],
        'IGNORED' => ['Não incorporado', 'neutral'],
        default => [(string) $status, 'neutral'],
    };
@endphp
<x-knowledge-admin.badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-knowledge-admin.badge>
