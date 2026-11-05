@php use App\Services\Patient\MedicationQuestionService as Q; @endphp
<x-consulta.shell title="Pergunta anterior" :back="route('consulta.historico')">
    <p class="-mt-1 px-1 text-base text-slate-700">Feita em {{ $question->created_at->timezone(config('app.timezone'))->format('d/m/Y') }}. Será apagada em {{ $question->expires_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}.</p>

    @if ($question->status === Q::BLOCKED)
        <section class="grid gap-3 rounded-2xl border-2 p-5 {{ $risco ? 'border-rose-400 bg-rose-50 text-rose-900' : 'border-slate-200 bg-white text-slate-800' }}">
            <p class="italic text-slate-600">“{{ $question->question }}”</p>
            <p class="text-xl font-semibold leading-snug">Esta pergunta não foi enviada à IA. {{ $bloqueio }}</p>
        </section>
    @elseif ($question->status === Q::NO_MEDICINE)
        <section class="grid gap-3 rounded-2xl border-2 border-amber-400 bg-amber-50 p-5 text-amber-900">
            <p class="italic">“{{ $question->question }}”</p>
            <p class="text-xl font-semibold leading-snug">Não encontramos o nome de um medicamento nesta pergunta, por isso ela não foi respondida.</p>
        </section>
    @else
        <x-consulta.resposta :resposta="$resposta" titulo="Resposta" />
        <x-consulta.risco :tratamentos="$tratamentos" />
        @if ($medicamentos !== [] || $tratamentos !== [])
            <details class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="min-h-14 cursor-pointer select-none px-5 py-3 text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Sobre: {{ collect($medicamentos)->pluck('name')->join(', ') }}</summary>
                <div class="grid gap-3 border-t border-slate-100 p-5">
                    @foreach ($medicamentos as $item)
                        <a class="inline-flex min-h-12 items-center justify-between gap-2 rounded-xl bg-brand-50 px-4 font-semibold text-brand-900 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100" href="{{ route('consulta.bula', $item['id']) }}" wire:navigate title="Ver bula humanizada de {{ $item['name'] }}">{{ $item['name'] }}<span class="font-normal">Ver a bula simples ›</span></a>
                    @endforeach
                    @foreach ($tratamentos as $item)
                        <x-consulta.tratamento :contexto="$item['context']" :medicamento="$item['medicine']" :somente-relevante="true" />
                    @endforeach
                </div>
            </details>
        @endif
    @endif

    <x-slot:sticky>
        <x-knowledge-admin.button variant="accent" class="min-h-16 w-full text-xl" :href="route('consulta.perguntar', $question->medicine_id ? ['medicamento' => $question->medicine_id] : [])">Perguntar de novo</x-knowledge-admin.button>
    </x-slot:sticky>
</x-consulta.shell>
