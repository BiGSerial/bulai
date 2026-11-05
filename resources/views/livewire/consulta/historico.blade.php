@php
    use App\Services\Patient\MedicationQuestionService as Q;
    $statusPergunta = fn (string $status): string => match ($status) {
        Q::ANSWERED => 'Respondida', Q::EXCERPTS_ONLY => 'Só trechos da bula', Q::NO_EVIDENCE => 'Sem trecho na bula',
        Q::NO_LEAFLET => 'Sem bula cadastrada', Q::NOT_IDENTIFIED => 'Substância não identificada', 'blocked' => 'Recusada pela triagem', 'no_medicine' => 'Sem medicamento identificado', default => $status,
    };
    $tab = fn (string $key): string => 'min-h-14 flex-1 rounded-xl px-3 text-lg font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 '.($aba === $key ? 'bg-white text-brand-900 shadow-sm' : 'text-slate-700 hover:text-slate-900');
    $data = fn ($at): string => $at->timezone(config('app.timezone'))->format('d/m/Y');
@endphp
<x-consulta.shell title="O que já perguntei" :back="route('consulta.inicio')">
    <div class="flex gap-1 rounded-2xl bg-slate-200/70 p-1" role="tablist" aria-label="Tipo de registro">
        <button type="button" role="tab" id="tab-perguntas" aria-controls="painel-perguntas" aria-selected="{{ $aba === 'perguntas' ? 'true' : 'false' }}" class="{{ $tab('perguntas') }}" wire:click="mostrar('perguntas')">Perguntas ({{ $questions->total() }})</button>
        <button type="button" role="tab" id="tab-consultas" aria-controls="painel-consultas" aria-selected="{{ $aba === 'consultas' ? 'true' : 'false' }}" class="{{ $tab('consultas') }}" wire:click="mostrar('consultas')">Interações ({{ $consultations->total() }})</button>
    </div>

    @if ($aba === 'perguntas')
        <section id="painel-perguntas" role="tabpanel" aria-labelledby="tab-perguntas" class="grid gap-3">
            @forelse ($questions as $question)
                <article wire:key="q-{{ $question->uuid }}" class="grid gap-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200" x-data="{ confirmar: false }">
                    <a href="{{ route('consulta.pergunta', $question) }}" wire:navigate class="grid min-h-14 gap-1 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        <span class="text-xl font-semibold leading-snug text-brand-900">“{{ \Illuminate\Support\Str::limit($question->question, 90) }}”</span>
                        <span class="text-base text-slate-700">{{ $question->medicine_name }} · {{ $data($question->created_at) }} · {{ $statusPergunta($question->status) }}</span>
                    </a>
                    <button type="button" x-show="!confirmar" class="min-h-12 justify-self-start rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true">Apagar</button>
                    <div x-cloak x-show="confirmar" class="grid gap-2">
                        <p class="font-semibold">Apagar esta pergunta?</p>
                        <button type="button" class="min-h-14 rounded-xl bg-rose-700 px-4 text-lg font-semibold text-white" wire:click="apagarPergunta('{{ $question->uuid }}')" wire:loading.attr="disabled">Sim, apagar</button>
                        <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800" x-on:click="confirmar = false">Não</button>
                    </div>
                </article>
            @empty
                <div class="grid gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700"><p>Você ainda não fez nenhuma pergunta.</p><x-knowledge-admin.button :href="route('consulta.perguntar')" class="min-h-14 w-full text-lg">Perguntar agora</x-knowledge-admin.button></div>
            @endforelse
            {{ $questions->links() }}
        </section>
    @else
        <section id="painel-consultas" role="tabpanel" aria-labelledby="tab-consultas" class="grid gap-3">
            @forelse ($consultations as $item)
                <article wire:key="h-{{ $item->uuid }}" class="grid gap-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200" x-data="{ confirmar: false }">
                    <a href="{{ route('consulta.resultado', $item) }}" wire:navigate class="grid min-h-14 gap-1 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        <span class="text-xl font-semibold leading-snug text-brand-900">{{ collect($item->medicines)->pluck('name')->join(' + ') }}</span>
                        <span class="text-base text-slate-700">{{ $data($item->created_at) }} · {{ match ($item->status) { 'COMPLETED' => 'Concluída', 'FAILED' => 'Falhou', 'RUNNING' => 'Em análise', default => 'Na fila' } }} · {{ $item->pair_count }} combinação(ões)</span>
                    </a>
                    <button type="button" x-show="!confirmar" class="min-h-12 justify-self-start rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true">Apagar</button>
                    <div x-cloak x-show="confirmar" class="grid gap-2">
                        <p class="font-semibold">Apagar esta consulta?</p>
                        <button type="button" class="min-h-14 rounded-xl bg-rose-700 px-4 text-lg font-semibold text-white" wire:click="apagar('{{ $item->uuid }}')" wire:loading.attr="disabled">Sim, apagar</button>
                        <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800" x-on:click="confirmar = false">Não</button>
                    </div>
                </article>
            @empty
                <div class="grid gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700"><p>Você ainda não fez nenhuma consulta de interações.</p><x-knowledge-admin.button :href="route('consulta.pesquisa')" class="min-h-14 w-full text-lg">Verificar interações</x-knowledge-admin.button></div>
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
                <button type="button" class="min-h-14 rounded-xl bg-rose-700 px-4 text-lg font-semibold text-white" wire:click="apagarTudo" wire:loading.attr="disabled">Sim, apagar tudo</button>
                <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800" x-on:click="confirmar = false">Não</button>
            </div>
        </div>
    @endif
</x-consulta.shell>
