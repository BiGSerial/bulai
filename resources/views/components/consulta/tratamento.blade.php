@props(['contexto', 'medicamento', 'somenteRelevante' => false])
@php
    $interacoes = $contexto['interactions'] ?? [];
    $alergias = $contexto['allergy_alerts'] ?? [];
    $consentido = (bool) ($contexto['consented'] ?? false);
    $uid = 'trat-'.substr(md5((string) $medicamento), 0, 6);
    $relevante = ! $consentido || ($contexto['in_use'] ?? false) || $alergias !== [] || $interacoes !== [] || ! empty($contexto['unresolved']);
    $tone = match (true) {
        $alergias !== [] || ($contexto['highest_severity'] ?? null) === 'major' => 'border-rose-300 bg-rose-50/60',
        ($contexto['highest_severity'] ?? null) === 'moderate' || collect($interacoes)->contains('severity', 'duplicate') => 'border-amber-300 bg-amber-50/60',
        default => 'border-slate-200 bg-white',
    };
@endphp
@if (! $somenteRelevante || $relevante)
<section {{ $attributes->class("grid gap-3 rounded-2xl border p-4 text-base shadow-sm {$tone}") }} aria-labelledby="{{ $uid }}">
    <h2 id="{{ $uid }}" class="text-lg font-semibold text-brand-900">No seu tratamento</h2>
    @if (! $consentido)
        <p class="text-slate-800">Para cruzar com o que você já usa e com suas alergias, registre seu perfil de saúde.</p>
        <a class="inline-flex min-h-14 items-center justify-center rounded-xl bg-brand-700 px-4 text-lg font-semibold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" href="{{ route('consulta.perfil') }}" wire:navigate>Abrir meus remédios</a>
    @else
        @if ($contexto['in_use'])
            @php
                $posologia = collect([$contexto['posology']['dosage'] ?? null, $contexto['posology']['frequency'] ?? null])->filter()->join(', ');
                $prescritor = $contexto['posology']['prescriber'] ?? null;
            @endphp
            <p class="font-medium text-brand-900">✓ {{ $medicamento }} está entre os medicamentos que você usa{{ $posologia !== '' ? ' ('.$posologia.')' : '' }}{{ $prescritor ? ' · receita de '.$prescritor : '' }}.</p>
        @elseif (($contexto['medications_count'] ?? 0) === 0)
            <p class="text-slate-800">Você ainda não registrou remédios em uso; não há o que cruzar.</p>
        @elseif ($interacoes === [] && $alergias === [])
            <p class="text-slate-800">{{ $medicamento }} não está na sua lista. Nenhuma interação registrada com o que você usa. <strong>Isso não garante que seja seguro.</strong></p>
        @endif

        @foreach ($alergias as $alerta)
            <p role="alert" class="rounded-xl bg-rose-100 px-4 py-3 font-semibold text-rose-900">⚠ Você registrou alergia a {{ $alerta['allergy'] }}: este remédio {{ $alerta['via'] === 'classe' ? 'pertence à mesma classe de' : 'contém' }} {{ $alerta['ingredient'] }}. Fale com seu médico ou farmacêutico antes de usar.</p>
        @endforeach

        @if ($interacoes !== [])
            <ul class="grid gap-2" aria-label="Interações com remédios em uso">
                @foreach ($interacoes as $interacao)
                    <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-xl bg-white/80 px-4 py-3 ring-1 ring-slate-200">
                        <span class="font-semibold text-slate-900">Com {{ $interacao['medicine'] }}</span>
                        @if ($interacao['severity'] === 'duplicate')
                            <span class="font-semibold text-amber-900">Mesma substância (possível duplicidade)</span>
                        @else
                            <x-consulta.severity :severity="$interacao['severity']" class="text-base" />
                        @endif
                        @if ($interacao['divergent'] ?? false)<span class="w-full text-base text-slate-600">A base registra gravidades diferentes; mostramos a mais alta.</span>@endif
                    </li>
                @endforeach
            </ul>
            <p class="text-base text-slate-600">Gravidade conforme a base DDInter; não é uma avaliação do seu caso.</p>
        @elseif ($contexto['in_use'] && ($contexto['medications_count'] ?? 0) > 1)
            <p class="text-slate-800">Nenhuma interação registrada com os seus outros remédios. <strong>Isso não garante que seja seguro.</strong></p>
        @endif

        @if (! empty($contexto['unresolved']))
            <p class="text-base text-slate-600">Não foi possível verificar: {{ implode(', ', $contexto['unresolved']) }}. Remédio sem bula cadastrada ou substância não identificada: não há o que cruzar.</p>
        @endif
    @endif
</section>
@endif
