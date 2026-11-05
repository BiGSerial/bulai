@php
    use App\Services\Treatment\TreatmentScheduleService as T;
    // Estado em uma palavra, sempre com cor E texto.
    $estado = fn (string $status): array => match ($status) {
        T::DOSE_TAKEN => ['Tomei', 'bg-emerald-100 text-emerald-900'],
        T::DOSE_SKIPPED => ['Pulei', 'bg-slate-200 text-slate-800'],
        T::DOSE_LATE => ['Atrasado', 'bg-rose-100 text-rose-900'],
        T::DOSE_DUE => ['Agora', 'bg-accent-100 text-accent-800'],
        default => ['A tomar', 'bg-brand-50 text-brand-900'],
    };
@endphp
<x-consulta.shell :title="$titulo" :back="route('consulta.inicio')">
    @unless ($consentido)
        <section class="grid gap-3 rounded-2xl border-2 border-amber-300 bg-amber-50 p-5 text-amber-900">
            <p class="text-xl font-semibold leading-tight">Para programar seus remédios, autorize guardar seus dados de saúde.</p>
            <x-knowledge-admin.button :href="route('consulta.perfil')" class="min-h-16 w-full text-xl">Abrir Meus remédios</x-knowledge-admin.button>
        </section>
    @else
        <div class="flex items-center justify-between gap-2">
            <button type="button" class="inline-flex min-h-14 min-w-14 items-center justify-center rounded-xl px-3 text-lg font-semibold text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 disabled:opacity-30" wire:click="mudarDia(-1)" @disabled($dia <= -1) aria-label="Dia anterior">‹ Ontem</button>
            <p class="text-center text-lg font-semibold text-slate-800">{{ $data->translatedFormat('l, d/m') }}</p>
            <button type="button" class="inline-flex min-h-14 min-w-14 items-center justify-center rounded-xl px-3 text-lg font-semibold text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 disabled:opacity-30" wire:click="mudarDia(1)" @disabled($dia >= 1) aria-label="Próximo dia">Amanhã ›</button>
        </div>

        {{-- Só a lista se atualiza sozinha (estados "Agora"/"Atrasado" mudam com a hora). --}}
        <div class="grid gap-4" wire:poll.60s aria-live="polite">
            @if ($total > 0)
                <p class="px-1 text-slate-800" role="status">{{ $feitos }} de {{ $total }} {{ $total === 1 ? 'horário marcado' : 'horários marcados' }}.</p>
            @endif
            @forelse ($periodos as $chave => [$rotulo, $doses])
                <section class="grid gap-3" aria-labelledby="per-{{ $chave }}">
                    <h2 id="per-{{ $chave }}" class="px-1 text-xl font-semibold text-brand-900">{{ $rotulo }}</h2>
                    @foreach ($doses as $dose)
                        @php [$texto, $cor] = $estado($dose['status']); $iso = $dose['scheduled_for']->utc()->toIso8601String(); $marcado = in_array($dose['status'], [T::DOSE_TAKEN, T::DOSE_SKIPPED], true); @endphp
                        <article wire:key="d-{{ $dose['medication']->id }}-{{ $dose['time'] }}" class="grid gap-3 rounded-2xl bg-white p-4 ring-1 {{ $dose['status'] === T::DOSE_LATE ? 'ring-rose-300' : ($dose['status'] === T::DOSE_DUE ? 'ring-accent-400 ring-2' : 'ring-slate-200') }}" aria-label="{{ $dose['time'] }}, {{ $dose['medication']->displayName() }}: {{ $texto }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-3xl font-semibold leading-none text-brand-900">{{ $dose['time'] }}</p>
                                    <p class="mt-2 text-xl font-semibold leading-snug text-slate-900">{{ $dose['medication']->displayName() }}</p>
                                    <p class="text-slate-700">{{ $dose['medication']->dosage ?: 'Dose não informada' }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-3 py-1.5 text-base font-semibold {{ $cor }}">{{ $texto }}</span>
                            </div>
                            @if ($marcado)
                                <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="desfazer({{ $dose['medication']->id }}, '{{ $iso }}')" wire:loading.attr="disabled">Desfazer</button>
                            @else
                                <div class="grid gap-2">
                                    <button type="button" class="min-h-16 rounded-xl bg-emerald-700 px-4 text-xl font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/40" wire:click="tomei({{ $dose['medication']->id }}, '{{ $iso }}')" wire:loading.attr="disabled" wire:target="tomei({{ $dose['medication']->id }}, '{{ $iso }}')"><span wire:loading.remove wire:target="tomei({{ $dose['medication']->id }}, '{{ $iso }}')">✓ Tomei</span><span wire:loading wire:target="tomei({{ $dose['medication']->id }}, '{{ $iso }}')">Anotando…</span></button>
                                    <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="pular({{ $dose['medication']->id }}, '{{ $iso }}')" wire:loading.attr="disabled">Pular este horário</button>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </section>
            @empty
                <div class="grid gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700">
                    <p class="text-xl font-semibold text-slate-900">{{ $temRemedios ? 'Nenhum horário programado para este dia.' : 'Você ainda não tem remédios programados.' }}</p>
                    <p>{{ $temRemedios ? 'Toque em “Informar dose e horários” em Meus remédios para definir os horários.' : 'Fotografe sua receita ou cadastre os remédios à mão.' }}</p>
                    <x-knowledge-admin.button variant="accent" :href="route('consulta.receita.nova')" class="min-h-16 w-full text-xl">📷 Fotografar receita</x-knowledge-admin.button>
                    <x-knowledge-admin.button variant="secondary" :href="route('consulta.perfil')" class="min-h-14 w-full text-lg">Meus remédios</x-knowledge-admin.button>
                </div>
            @endforelse
        </div>

        @if ($total > 0)
            <x-knowledge-admin.button variant="secondary" :href="route('consulta.perfil')" class="min-h-14 w-full text-lg">Meus remédios, receitas e alergias</x-knowledge-admin.button>
        @endif
    @endunless
</x-consulta.shell>
