@props(['tratamentos' => []])
@php
    // UMA frase de risco em linguagem comum (regra em TreatmentContextService::riskSentence, a mesma do aplicativo).
    $risco = \App\Services\Patient\TreatmentContextService::riskSentence($tratamentos);
    $frase = $risco['text'] ?? null;
    $tom = ($risco['tone'] ?? null) === 'danger' ? 'rose' : 'amber';
@endphp
@if ($frase)
    <div role="alert" class="flex min-w-0 items-start gap-3 rounded-2xl border-2 px-4 py-4 {{ $tom === 'rose' ? 'border-rose-400 bg-rose-50 text-rose-900' : 'border-amber-400 bg-amber-50 text-amber-900' }}">
        <x-consulta.icone name="alerta" :tone="$tom === 'rose' ? 'danger' : 'warning'" />
        <p class="min-w-0 break-words text-xl font-semibold leading-snug">{{ $frase }}</p>
    </div>
@endif
