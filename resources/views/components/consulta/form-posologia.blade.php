{{--
    Formulário "Dose e horários" de um remédio (Meus remédios e modo cuidador), pensado para o celular e igual ao do
    aplicativo: 1. Quanto tomar (atalhos de dose), 2. Quando tomar (atalhos grandes que sugerem os horários),
    3. Horários do dia (cada um com Manhã/Tarde/Noite) e, recolhido, "Mais detalhes". Os campos são do componente
    Livewire que usa o trait EditaPosologia. Sem `receitas`, o vínculo com a receita fica como está.
--}}
@props(['item', 'posologia', 'receitas' => null, 'resumo' => 'Datas, receita e observações'])
@php
    use App\Models\PatientMedication;
    use App\Services\Patient\HealthProfileService;

    $mesmoTexto = fn (?string $a, ?string $b): bool => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    $atalhoEscolhido = collect(HealthProfileService::FREQUENCY_PRESETS)->first(fn (string $atalho): bool => $mesmoTexto($atalho, $posologia['frequency']));
    $outroJeito = $atalhoEscolhido === null && trim((string) $posologia['frequency']) !== '';
    // Período do dia de um horário "HH:MM" (o aplicativo usa a mesma divisão).
    $periodo = function (?string $hora): array {
        $h = preg_match('/^(\d{2}):/', (string) $hora, $m) === 1 ? (int) $m[1] : null;

        return match (true) {
            $h === null => ['Escolha a hora', 'relogio'],
            $h >= 5 && $h < 12 => ['Manhã', 'sol'],
            $h >= 12 && $h < 18 => ['Tarde', 'sol'],
            $h >= 18 => ['Noite', 'lua'],
            default => ['Madrugada', 'lua'],
        };
    };
    $passo = 'inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-700 text-base font-bold text-white';
    $chip = 'min-h-12 max-w-full rounded-full px-4 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600';
@endphp
<form class="grid gap-5 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200" wire:submit="salvarPosologia" aria-label="Posologia de {{ $item->displayName() }}">
    <div>
        <p class="text-lg font-semibold text-brand-900">Dose e horários</p>
        <p class="break-words text-base text-slate-600">{{ $item->displayName() }}</p>
    </div>

    {{-- 1. Quanto tomar --}}
    <section class="grid gap-2" aria-labelledby="pq-{{ $item->id }}">
        <p id="pq-{{ $item->id }}" class="flex items-center gap-2 text-lg font-semibold text-slate-900"><span class="{{ $passo }}" aria-hidden="true">1</span>Quanto tomar</p>
        <div class="flex flex-wrap gap-2" aria-label="Doses comuns">
            @foreach (HealthProfileService::DOSE_PRESETS as $dose)
                @php($escolhida = $mesmoTexto($dose, $posologia['dosage']))
                <button type="button" wire:key="ds-{{ $item->id }}-{{ $loop->index }}" wire:click="escolherDose(@js($dose))" aria-pressed="{{ $escolhida ? 'true' : 'false' }}"
                    class="{{ $chip }} {{ $escolhida ? 'bg-brand-700 text-white' : 'bg-white text-brand-900 ring-1 ring-inset ring-brand-600/30 hover:bg-brand-50' }}">{{ $dose }}</button>
            @endforeach
        </div>
        <label class="sr-only" for="dose-{{ $item->id }}">Dose</label>
        <input id="dose-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model.blur="posologia.dosage" placeholder="Ou escreva: 1 comprimido de 500 mg" maxlength="120">
    </section>

    {{-- 2. Quando tomar --}}
    <section class="grid gap-2" aria-labelledby="pw-{{ $item->id }}" x-data="{ outro: @js($outroJeito) }">
        <p id="pw-{{ $item->id }}" class="flex items-center gap-2 text-lg font-semibold text-slate-900"><span class="{{ $passo }}" aria-hidden="true">2</span>Quando tomar</p>
        <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby="pw-{{ $item->id }}">
            @foreach (HealthProfileService::FREQUENCY_PRESETS as $atalho)
                @php($escolhido = $atalho === $atalhoEscolhido)
                <button type="button" role="radio" aria-checked="{{ $escolhido ? 'true' : 'false' }}" wire:key="fq-{{ $item->id }}-{{ $loop->index }}" wire:click="escolherFrequencia(@js($atalho))" x-on:click="outro = false"
                    class="flex min-h-14 min-w-0 items-center justify-center gap-1.5 rounded-2xl border-2 px-2 py-2 text-center text-base font-semibold leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $escolhido ? 'border-brand-700 bg-brand-50 text-brand-900' : 'border-slate-300 bg-white text-slate-800 hover:border-brand-600' }}">
                    @if ($escolhido)<x-consulta.icone name="check" plain class="size-5 shrink-0" />@endif<span class="min-w-0 break-words">{{ $atalho }}</span>
                </button>
            @endforeach
        </div>
        <button type="button" class="min-h-12 justify-self-start px-1 text-base font-semibold text-brand-800 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-show="! outro" x-on:click="outro = true; $nextTick(() => $refs.freq.focus())">Outro jeito? Escreva como está na receita</button>
        <div x-show="outro" x-cloak>
            <label class="font-medium text-slate-800" for="freq-{{ $item->id }}">Como está na receita</label>
            <input id="freq-{{ $item->id }}" x-ref="freq" class="ui-control min-h-14 text-lg" wire:model.blur="posologia.frequency" placeholder="Ex.: pela manhã e à noite" maxlength="120">
            <p class="mt-1 text-base text-slate-600">Os horários são sugeridos a partir do que você escrever.</p>
        </div>
    </section>

    {{-- 3. Horários do dia --}}
    <section class="grid gap-2" aria-labelledby="ph-{{ $item->id }}">
        <p id="ph-{{ $item->id }}" class="flex items-center gap-2 text-lg font-semibold text-slate-900"><span class="{{ $passo }}" aria-hidden="true">3</span>Horários do dia</p>
        @if ($posologia['as_needed'])
            <p class="rounded-2xl bg-white p-3 text-base text-slate-700 ring-1 ring-slate-200">Sem horário fixo: toma só quando precisar. Para ter horários, escolha outra opção em “Quando tomar”.</p>
        @else
            @php($dinamico = $posologia['interval_hours'] !== null && $posologia['schedule_mode'] === PatientMedication::MODE_INTERVAL)
            @if ($posologia['interval_hours'] !== null)
                {{-- Frequência por intervalo: contar a partir de quando toma (recomendado) ou horários fixos. --}}
                <div class="grid gap-2" role="radiogroup" aria-label="Como contar os horários">
                    <label class="flex min-h-16 cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 {{ $dinamico ? 'border-brand-700 bg-brand-50' : 'border-slate-300 bg-white' }}">
                        <input type="radio" class="mt-1 size-6 shrink-0 border-slate-400 text-brand-700 focus:ring-brand-600" name="modo-{{ $item->id }}" value="{{ PatientMedication::MODE_INTERVAL }}" wire:model.live="posologia.schedule_mode">
                        <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">Contar a partir de quando eu tomar (recomendado)</span><span class="block break-words text-base text-slate-600">A cada {{ $posologia['interval_hours'] }} h depois do horário em que você marcar “Tomei”.</span></span>
                    </label>
                    <label class="flex min-h-16 cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 {{ ! $dinamico ? 'border-brand-700 bg-brand-50' : 'border-slate-300 bg-white' }}">
                        <input type="radio" class="mt-1 size-6 shrink-0 border-slate-400 text-brand-700 focus:ring-brand-600" name="modo-{{ $item->id }}" value="{{ PatientMedication::MODE_FIXED }}" wire:model.live="posologia.schedule_mode">
                        <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">Horários fixos todos os dias</span><span class="block break-words text-base text-slate-600">Sempre nos mesmos horários, mesmo se atrasar uma dose.</span></span>
                    </label>
                </div>
            @endif
            @if ($dinamico)
                @php([$nomePeriodo, $iconePeriodo] = $periodo($posologia['times'][0] ?? null))
                <div class="flex min-w-0 items-center gap-3 rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                    <x-consulta.icone :name="$iconePeriodo" tone="brand" />
                    <div class="grid min-w-0 flex-1 gap-0.5">
                        <label class="text-base font-semibold text-slate-900" for="hora-{{ $item->id }}-0">Primeira dose do dia <span class="font-normal text-slate-600">· {{ $nomePeriodo }}</span></label>
                        <input id="hora-{{ $item->id }}-0" class="ui-control min-h-12 w-full max-w-48 px-2 text-xl font-semibold tabular-nums" type="time" step="300" wire:model.blur="posologia.times.0">
                    </div>
                </div>
                <p class="text-base text-slate-600">As próximas doses vêm a cada {{ $posologia['interval_hours'] }} h a partir da última que você marcar.</p>
            @else
                <ul class="grid gap-2" aria-label="Horários">
                    @foreach ($posologia['times'] as $pos => $hora)
                        @php([$nomePeriodo, $iconePeriodo] = $periodo($hora))
                        <li class="flex min-w-0 items-center gap-3 rounded-2xl bg-white p-2 pl-3 ring-1 ring-slate-200" wire:key="ph-{{ $item->id }}-{{ $pos }}">
                            <x-consulta.icone :name="$iconePeriodo" tone="brand" />
                            <div class="grid min-w-0 flex-1 gap-0.5">
                                <label class="text-base font-semibold text-slate-700" for="hora-{{ $item->id }}-{{ $pos }}">{{ $nomePeriodo }}<span class="sr-only">, horário {{ $pos + 1 }}</span></label>
                                <input id="hora-{{ $item->id }}-{{ $pos }}" class="ui-control min-h-12 w-full max-w-48 px-2 text-xl font-semibold tabular-nums" type="time" step="300" wire:model.blur="posologia.times.{{ $pos }}">
                            </div>
                            <button type="button" class="inline-flex size-12 shrink-0 items-center justify-center rounded-full text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="tirarHorario({{ $pos }})" aria-label="Tirar o horário {{ $hora ?: $pos + 1 }}" title="Tirar"><x-consulta.icone name="fechar" plain class="size-6" /></button>
                        </li>
                    @endforeach
                </ul>
                @if ($posologia['times'] === [])<p class="text-base text-slate-600">Nenhum horário ainda. Escolha em “Quando tomar” ou adicione.</p>@endif
                @if (count($posologia['times']) < 12)<button type="button" class="min-h-14 rounded-2xl border-2 border-dashed border-brand-600/50 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="adicionarHorario">+ Adicionar horário</button>@endif
            @endif
            <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl bg-white px-3 ring-1 ring-slate-200">
                <x-consulta.icone name="sino" plain class="size-6 shrink-0 text-brand-800" />
                <span class="min-w-0 flex-1"><span class="block text-lg font-semibold text-slate-900">Lembrar nos horários</span><span class="block text-base text-slate-600">Aviso no celular na hora da dose</span></span>
                <input type="checkbox" class="size-6 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model="posologia.reminders_enabled">
            </label>
        @endif
    </section>

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
    <div class="grid gap-1">
        <x-consulta.botao type="submit" size="md" loadingTarget="salvarPosologia" loading="Salvando…">Salvar</x-consulta.botao>
        <x-consulta.botao variant="quiet" size="sm" wire:click="cancelarPosologia">Cancelar</x-consulta.botao>
    </div>
</form>
