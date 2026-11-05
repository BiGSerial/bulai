{{-- Métricas de geração/recuperação de uma resposta (status, modelo, tokens, afirmações aceitas/rejeitadas). --}}
@props(['generation' => [], 'retrieval' => [], 'timings' => []])
@php
    $generation ??= [];
    $retrieval ??= [];
    $timings ??= [];
@endphp
<x-knowledge-admin.facts {{ $attributes->class('sm:grid-cols-3') }}>
    <x-knowledge-admin.fact label="Status da geração">{{ $generation['status'] ?? '—' }}@if (! empty($generation['reason'])) ({{ $generation['reason'] }})@endif @if (! empty($generation['error_code'])) ({{ $generation['error_code'] }})@endif</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Provedor / modelo">{{ $generation['provider'] ?? '—' }} / {{ $generation['model'] ?? '—' }}</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Versão do prompt">{{ $generation['prompt_version'] ?? '—' }}</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Tokens (entrada / saída)">{{ $generation['input_tokens'] ?? '—' }} / {{ $generation['output_tokens'] ?? '—' }}@if (! empty($generation['truncated'])) · truncada @endif</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Afirmações aceitas / rejeitadas">{{ $generation['claims_accepted'] ?? '—' }} / {{ $generation['claims_rejected'] ?? '—' }}@if (! empty($generation['rejection_reasons'])) · {{ collect($generation['rejection_reasons'])->map(fn ($n, $reason) => "$reason: $n")->join(', ') }}@endif</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Identificador da execução">{{ $generation['run_id'] ?? '—' }}</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Recuperação">{{ $retrieval['mode'] ?? '—' }}@if (isset($retrieval['duration_ms'])) · {{ $retrieval['duration_ms'] }} ms @endif @if (! empty($retrieval['configuration']['embedding_model'])) · modelo vetorial {{ $retrieval['configuration']['embedding_model'] }} @endif</x-knowledge-admin.fact>
    <x-knowledge-admin.fact label="Tempos por etapa (ms)">@if ($timings === []) — @else {{ collect($timings)->map(fn ($v, $k) => "$k: $v")->join(' · ') }} @endif</x-knowledge-admin.fact>
</x-knowledge-admin.facts>
