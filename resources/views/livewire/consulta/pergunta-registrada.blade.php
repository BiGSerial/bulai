@php use App\Services\Patient\MedicationQuestionService as Q; @endphp
<x-consulta.shell title="Pergunta anterior" :back="route('consulta.historico')">
    <div class="-mt-1 flex flex-wrap items-center gap-2 px-1 text-base text-slate-700">
        <x-consulta.icone name="calendario" plain class="size-5 shrink-0 text-slate-500" />
        <span>Feita em {{ $question->created_at->timezone(config('app.timezone'))->format('d/m/Y') }}. Será apagada em {{ $question->expires_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}.</span>
    </div>

    @if ($question->status === Q::BLOCKED)
        <x-consulta.cartao :tone="$risco ? 'danger' : 'neutral'" class="border-2">
            <p class="break-words italic text-slate-600">“{{ $question->question }}”</p>
            <p class="break-words text-xl font-semibold leading-snug">Esta pergunta não foi enviada à IA. {{ $bloqueio }}</p>
        </x-consulta.cartao>
    @elseif ($question->status === Q::NO_MEDICINE)
        <x-consulta.cartao tone="warning" class="border-2">
            <p class="break-words italic">“{{ $question->question }}”</p>
            <p class="break-words text-xl font-semibold leading-snug">Não encontramos o nome de um medicamento nesta pergunta, por isso ela não foi respondida.</p>
        </x-consulta.cartao>
    @else
        <x-consulta.resposta :resposta="$resposta" titulo="Resposta" />
        <x-consulta.risco :tratamentos="$tratamentos" />
        @if ($medicamentos !== [] || $tratamentos !== [])
            <x-consulta.secao titulo="Sobre" :resumo="collect($medicamentos)->pluck('name')->join(', ')" icon="pilula" tone="brand">
                @foreach ($medicamentos as $item)
                    <x-consulta.botao variant="soft" size="md" :href="route('consulta.bula', $item['id'])" title="Ver bula humanizada de {{ $item['name'] }}" class="justify-between"><span class="min-w-0 break-words">{{ $item['name'] }}</span><span class="shrink-0 font-normal">Ver a bula simples ›</span></x-consulta.botao>
                @endforeach
                @foreach ($tratamentos as $item)
                    <x-consulta.tratamento :contexto="$item['context']" :medicamento="$item['medicine']" :somente-relevante="true" />
                @endforeach
            </x-consulta.secao>
        @endif
    @endif

    <x-slot:sticky>
        <x-consulta.botao variant="accent" :href="route('consulta.perguntar', $question->medicine_id ? ['medicamento' => $question->medicine_id] : [])"><x-consulta.icone name="pergunta" plain class="size-7" />Perguntar de novo</x-consulta.botao>
    </x-slot:sticky>
</x-consulta.shell>
