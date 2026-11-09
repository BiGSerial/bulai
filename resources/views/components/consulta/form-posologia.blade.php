{{-- Formulário "Dose e horários" de um remédio (Meus remédios e modo cuidador). Os campos são do componente Livewire que usa o trait EditaPosologia. Sem `receitas`, o vínculo com a receita fica como está. --}}
@props(['item', 'posologia', 'receitas' => null, 'resumo' => 'Datas, receita e observações'])
<form class="grid gap-3 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200" wire:submit="salvarPosologia" aria-label="Posologia de {{ $item->displayName() }}">
    <p class="text-lg font-semibold text-brand-900">Dose e horários</p>
    <div><label class="font-medium text-slate-800" for="dose-{{ $item->id }}">Dose</label><input id="dose-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.dosage" placeholder="Ex.: 1 comprimido de 500 mg" maxlength="120"></div>
    <div><label class="font-medium text-slate-800" for="freq-{{ $item->id }}">Quando tomar</label><input id="freq-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model.blur="posologia.frequency" placeholder="Ex.: de 8 em 8 horas" maxlength="120"><p class="mt-1 text-base text-slate-600">Os horários abaixo são sugeridos a partir disto.</p></div>
    <fieldset class="grid gap-2 rounded-2xl bg-white p-3 ring-1 ring-slate-200">
        <legend class="px-1 font-medium text-slate-800">Horários do dia</legend>
        <label class="flex min-h-12 items-center gap-3 text-lg"><input type="checkbox" class="size-6 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="posologia.as_needed"> Só quando precisar (sem horário fixo)</label>
        @unless ($posologia['as_needed'])
            @php($dinamico = $posologia['interval_hours'] !== null && $posologia['schedule_mode'] === \App\Models\PatientMedication::MODE_INTERVAL)
            @if ($posologia['interval_hours'] !== null)
                {{-- Frequência por intervalo: contar a partir de quando toma (recomendado) ou horários fixos. --}}
                <div class="grid gap-2" role="radiogroup" aria-label="Como contar os horários">
                    <label class="flex min-h-16 cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 {{ $dinamico ? 'border-brand-700 bg-brand-50' : 'border-slate-300 bg-white' }}">
                        <input type="radio" class="mt-1 size-6 shrink-0 border-slate-400 text-brand-700 focus:ring-brand-600" name="modo-{{ $item->id }}" value="{{ \App\Models\PatientMedication::MODE_INTERVAL }}" wire:model.live="posologia.schedule_mode">
                        <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">Contar a partir de quando eu tomar (recomendado)</span><span class="block break-words text-base text-slate-600">A cada {{ $posologia['interval_hours'] }} h depois do horário em que você marcar “Tomei”.</span></span>
                    </label>
                    <label class="flex min-h-16 cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 {{ ! $dinamico ? 'border-brand-700 bg-brand-50' : 'border-slate-300 bg-white' }}">
                        <input type="radio" class="mt-1 size-6 shrink-0 border-slate-400 text-brand-700 focus:ring-brand-600" name="modo-{{ $item->id }}" value="{{ \App\Models\PatientMedication::MODE_FIXED }}" wire:model.live="posologia.schedule_mode">
                        <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">Horários fixos todos os dias</span><span class="block break-words text-base text-slate-600">Sempre nos mesmos horários, mesmo se atrasar uma dose.</span></span>
                    </label>
                </div>
            @endif
            @if ($dinamico)
                <div class="flex min-w-0 items-center gap-2">
                    <label class="min-w-0 flex-1 font-medium text-slate-800" for="hora-{{ $item->id }}-0">Primeiro horário do dia</label>
                    <input id="hora-{{ $item->id }}-0" class="ui-control min-h-14 w-32 max-w-full text-xl" type="time" step="300" wire:model="posologia.times.0">
                </div>
                <p class="text-base text-slate-600">Os outros horários são calculados a cada {{ $posologia['interval_hours'] }} h a partir da última dose que você marcar.</p>
            @else
                @foreach ($posologia['times'] as $pos => $hora)
                    <div class="flex min-w-0 items-center gap-2" wire:key="ph-{{ $item->id }}-{{ $pos }}">
                        <label class="sr-only" for="hora-{{ $item->id }}-{{ $pos }}">Horário {{ $pos + 1 }}</label>
                        <input id="hora-{{ $item->id }}-{{ $pos }}" class="ui-control min-h-14 min-w-0 flex-1 text-xl" type="time" step="300" wire:model="posologia.times.{{ $pos }}">
                        <button type="button" class="min-h-14 shrink-0 rounded-2xl px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="tirarHorario({{ $pos }})" aria-label="Tirar o horário {{ $hora ?: $pos + 1 }}">Tirar</button>
                    </div>
                @endforeach
                @if (count($posologia['times']) < 12)<button type="button" class="min-h-14 rounded-2xl border-2 border-dashed border-brand-600/50 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="adicionarHorario">+ Adicionar horário</button>@endif
            @endif
            <label class="flex min-h-12 items-center gap-3 text-lg"><input type="checkbox" class="size-6 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model="posologia.reminders_enabled"> Lembrar nos horários</label>
        @endunless
    </fieldset>
    <x-consulta.secao titulo="Mais detalhes" :resumo="$resumo" icon="calendario" tone="neutral">
        <div><label class="font-medium text-slate-800" for="ini-{{ $item->id }}">Começou em</label><input id="ini-{{ $item->id }}" class="ui-control min-h-14 text-lg" type="date" wire:model="posologia.started_at"></div>
        <div><label class="font-medium text-slate-800" for="fim-{{ $item->id }}">Termina em (vazio = uso contínuo)</label><input id="fim-{{ $item->id }}" class="ui-control min-h-14 text-lg" type="date" wire:model="posologia.ends_at"></div>
        @if ($receitas !== null)
<div><label class="font-medium text-slate-800" for="rec-{{ $item->id }}">Receita</label>
            <select id="rec-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.prescription_id">
                <option value="">Sem receita vinculada</option>
                @foreach ($receitas as $receita)<option value="{{ $receita->id }}">{{ $receita->prescriber_name }}{{ $receita->issued_at ? ' · '.$receita->issued_at->format('d/m/Y') : '' }}</option>@endforeach
            </select></div>
@endif
        <div><label class="font-medium text-slate-800" for="obs-{{ $item->id }}">Observações</label><input id="obs-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.notes" placeholder="Ex.: tomar após as refeições" maxlength="255"></div>
    </x-consulta.secao>
    <x-consulta.botao type="submit" size="md" loadingTarget="salvarPosologia" loading="Salvando…">Salvar</x-consulta.botao>
    <x-consulta.botao variant="quiet" size="sm" wire:click="cancelarPosologia">Cancelar</x-consulta.botao>
</form>
