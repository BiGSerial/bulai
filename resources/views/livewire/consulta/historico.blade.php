@php
    use App\Livewire\Consulta\Avisos as A;
    use App\Services\Patient\MedicationQuestionService as Q;
    $statusPergunta = fn (string $status): array => match ($status) {
        Q::ANSWERED => ['Respondida', 'success'], Q::EXCERPTS_ONLY => ['Só trechos da bula', 'brand'], Q::NO_EVIDENCE => ['Sem trecho na bula', 'neutral'],
        Q::NO_LEAFLET => ['Sem bula cadastrada', 'neutral'], Q::NOT_IDENTIFIED => ['Substância não identificada', 'warning'], 'blocked' => ['Recusada pela triagem', 'danger'], 'no_medicine' => ['Sem medicamento identificado', 'warning'], default => [$status, 'neutral'],
    };
    $statusConsulta = fn (string $status): array => match ($status) { 'COMPLETED' => ['Concluída', 'success'], 'FAILED' => ['Falhou', 'danger'], 'RUNNING' => ['Em análise', 'brand'], default => ['Na fila', 'neutral'] };
    $fuso = auth()->user()->treatmentTimezone();
@endphp
<x-consulta.shell title="O que já perguntei" :back="route('consulta.inicio')">
    <x-consulta.segmentos :itens="['perguntas' => ['Perguntas', $questions->total()], 'consultas' => ['Interações', $consultations->total()]]" acao="mostrar" :ativo="$aba" label="Tipo de registro" prefixo="tab" />

    @if ($aba === 'perguntas')
        <section id="painel-perguntas" role="tabpanel" aria-labelledby="tab-perguntas" class="grid gap-3">
            @forelse ($questions as $question)
                @php [$rotulo, $tom] = $statusPergunta($question->status); @endphp
                <article wire:key="q-{{ $question->uuid }}" class="grid min-w-0 gap-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200" x-data="{ confirmar: false }">
                    <a href="{{ route('consulta.pergunta', $question) }}" wire:navigate class="flex min-h-14 min-w-0 items-start gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        <x-consulta.icone name="pergunta" :tone="$tom === 'success' ? 'brand' : $tom" />
                        <span class="min-w-0 flex-1">
                            <span class="block break-words text-xl font-semibold leading-snug text-brand-900">“{{ \Illuminate\Support\Str::limit($question->question, 90) }}”</span>
                            <span class="block break-words text-base text-slate-700">{{ $question->medicine_name }} · {{ A::quando($question->created_at, $fuso) }}</span>
                        </span>
                        <x-consulta.icone name="seta" plain class="mt-3 size-6 shrink-0 text-slate-400" />
                    </a>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-consulta.selo :tone="$tom">{{ $rotulo }}</x-consulta.selo>
                        <button type="button" x-show="!confirmar" class="min-h-11 rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true">Apagar</button>
                    </div>
                    <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl bg-rose-50 p-3 ring-1 ring-rose-200">
                        <p class="font-semibold text-rose-900">Apagar esta pergunta?</p>
                        <x-consulta.botao variant="danger" size="md" wire:click="apagarPergunta('{{ $question->uuid }}')" wire:loading.attr="disabled">Sim, apagar</x-consulta.botao>
                        <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
                    </div>
                </article>
            @empty
                <x-consulta.vazio icon="pergunta" titulo="Você ainda não fez nenhuma pergunta." texto="Escreva ou fale sua dúvida sobre um remédio.">
                    <x-consulta.botao variant="accent" :href="route('consulta.perguntar')">Perguntar agora</x-consulta.botao>
                </x-consulta.vazio>
            @endforelse
            {{ $questions->links() }}
        </section>
    @else
        <section id="painel-consultas" role="tabpanel" aria-labelledby="tab-consultas" class="grid gap-3">
            @forelse ($consultations as $item)
                @php [$rotulo, $tom] = $statusConsulta($item->status); @endphp
                <article wire:key="h-{{ $item->uuid }}" class="grid min-w-0 gap-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200" x-data="{ confirmar: false }">
                    <a href="{{ route('consulta.resultado', $item) }}" wire:navigate class="flex min-h-14 min-w-0 items-start gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        <x-consulta.icone name="lupa" :tone="$tom === 'success' ? 'brand' : $tom" />
                        <span class="min-w-0 flex-1">
                            <span class="block break-words text-xl font-semibold leading-snug text-brand-900">{{ collect($item->medicines)->pluck('name')->join(' + ') }}</span>
                            <span class="block break-words text-base text-slate-700">{{ A::quando($item->created_at, $fuso) }} · {{ $item->pair_count }} {{ $item->pair_count === 1 ? 'combinação' : 'combinações' }}</span>
                        </span>
                        <x-consulta.icone name="seta" plain class="mt-3 size-6 shrink-0 text-slate-400" />
                    </a>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-consulta.selo :tone="$tom">{{ $rotulo }}</x-consulta.selo>
                        <button type="button" x-show="!confirmar" class="min-h-11 rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true">Apagar</button>
                    </div>
                    <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl bg-rose-50 p-3 ring-1 ring-rose-200">
                        <p class="font-semibold text-rose-900">Apagar esta consulta?</p>
                        <x-consulta.botao variant="danger" size="md" wire:click="apagar('{{ $item->uuid }}')" wire:loading.attr="disabled">Sim, apagar</x-consulta.botao>
                        <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
                    </div>
                </article>
            @empty
                <x-consulta.vazio icon="lupa" titulo="Você ainda não fez nenhuma consulta de interações." texto="Escolha dois ou mais remédios e veja se eles combinam.">
                    <x-consulta.botao :href="route('consulta.pesquisa')">Verificar interações</x-consulta.botao>
                </x-consulta.vazio>
            @endforelse
            {{ $consultations->links() }}
        </section>
    @endif

    <p class="px-1 text-base text-slate-700">Só você vê isto. Perguntas ficam por {{ $questionRetentionDays }} dias e consultas por {{ $retentionDays }} dias; depois são apagadas.</p>
    @if ($consultations->total() + $questions->total() > 0)
        <div x-data="{ confirmar: false }" class="grid gap-2">
            <button type="button" x-show="!confirmar" class="min-h-12 text-base font-semibold text-rose-800 underline" x-on:click="confirmar = true">Apagar todo o meu histórico</button>
            <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl border border-rose-300 bg-rose-50 p-4">
                <p class="font-semibold text-rose-900">Apagar tudo? Isto não pode ser desfeito.</p>
                <x-consulta.botao variant="danger" size="md" wire:click="apagarTudo" wire:loading.attr="disabled">Sim, apagar tudo</x-consulta.botao>
                <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
            </div>
        </div>
    @endif
</x-consulta.shell>
