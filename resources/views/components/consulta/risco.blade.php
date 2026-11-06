@props(['tratamentos' => []])
@php
    // UMA frase de risco em linguagem comum, a partir do contexto determinístico (alergia > interação > duplicidade).
    $frase = null;
    $tom = 'amber';
    foreach ($tratamentos as $item) {
        $ctx = $item['context'] ?? [];
        $nome = $item['medicine'] ?? 'este remédio';
        if (! empty($ctx['allergy_alerts'])) {
            $frase = 'Atenção: você registrou alergia a '.$ctx['allergy_alerts'][0]['allergy'].', e '.$nome.' tem relação com ela. Fale com seu médico antes de usar.';
            $tom = 'rose';
            break;
        }
        foreach ($ctx['interactions'] ?? [] as $interacao) {
            if ($interacao['severity'] === 'duplicate') {
                $frase ??= 'Atenção: '.$nome.' tem a mesma substância de '.$interacao['medicine'].', que você já usa. Fale com seu médico.';
                continue;
            }
            $forte = in_array($interacao['severity'], ['major', 'moderate'], true);
            $nova = 'Atenção: '.$nome.' pode interagir com '.$interacao['medicine'].', que você usa. Fale com seu médico.';
            if ($forte) { $frase = $nova; $tom = $interacao['severity'] === 'major' ? 'rose' : 'amber'; break 2; }
            $frase ??= $nova;
        }
    }
@endphp
@if ($frase)
    <div role="alert" class="flex min-w-0 items-start gap-3 rounded-2xl border-2 px-4 py-4 {{ $tom === 'rose' ? 'border-rose-400 bg-rose-50 text-rose-900' : 'border-amber-400 bg-amber-50 text-amber-900' }}">
        <x-consulta.icone name="alerta" :tone="$tom === 'rose' ? 'danger' : 'warning'" />
        <p class="min-w-0 break-words text-xl font-semibold leading-snug">{{ $frase }}</p>
    </div>
@endif
