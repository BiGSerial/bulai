@php use App\Models\PatientPrescription as P; @endphp
<x-consulta.shell title="Conferir a receita" :back="route('consulta.perfil', ['aba' => 'receitas'])">
    @if ($lendo)
        {{-- Só este bloco se atualiza sozinho enquanto a leitura roda. --}}
        <div wire:poll.2s role="status" aria-live="polite" class="grid gap-2 rounded-2xl border border-brand-600/30 bg-brand-50 px-5 py-5 text-brand-900">
            <p class="flex items-center gap-2 text-xl font-semibold"><svg class="size-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>Lendo sua receita…</p>
            <p>Leva alguns segundos. A foto é apagada quando terminar.</p>
        </div>
    @elseif ($status === P::STATUS_FAILED)
        <div role="alert" class="grid gap-3 rounded-2xl border-2 border-rose-400 bg-rose-50 px-5 py-4 text-rose-900">
            <p class="text-xl font-semibold leading-snug">Não conseguimos ler esta receita.</p>
            <p>{{ match ($prescription->error_code) {
                'PRESCRIPTION_IMAGE_INVALID' => 'A imagem não pôde ser lida (formato ou tamanho). Tente outra foto em JPG ou PNG.',
                'PRESCRIPTION_OUTPUT_INVALID' => 'A transcrição veio incompleta e foi descartada por segurança.',
                'PRESCRIPTION_TIMEOUT' => 'A leitura demorou mais que o esperado e foi interrompida.',
                'AI_NOT_CONFIGURED', 'AI_UNAVAILABLE', 'AI_BUDGET_EXCEEDED', 'AI_RATE_LIMITED', 'AI_TIMEOUT' => 'O serviço de leitura está indisponível no momento.',
                default => 'Ocorreu um erro técnico.' } }} Nada foi salvo.</p>
            @if ($prescription->error_code)<p class="text-base text-rose-800/80">Código técnico: {{ $prescription->error_code }}.</p>@endif
            <x-knowledge-admin.button :href="route('consulta.receita.nova')" class="min-h-16 w-full text-xl">Tentar outra foto</x-knowledge-admin.button>
            <x-knowledge-admin.button variant="secondary" :href="route('consulta.perfil', ['aba' => 'receitas'])" class="min-h-14 w-full text-lg">Cadastrar manualmente</x-knowledge-admin.button>
        </div>
    @elseif ($status === P::STATUS_CONFIRMED)
        <x-knowledge-admin.alert tone="success">Esta receita já foi confirmada e seus remédios estão na sua lista. <a class="font-semibold underline" href="{{ route('consulta.hoje') }}" wire:navigate>Ver remédios de hoje</a></x-knowledge-admin.alert>
    @else
        @if ($legibilidade === 'poor')
            <div role="alert" class="rounded-2xl border-2 border-amber-400 bg-amber-50 px-5 py-4 text-amber-900"><p class="text-xl font-semibold leading-snug">A foto ficou pouco legível.</p><p class="mt-1">Confira cada item com atenção ou <a class="font-semibold underline" href="{{ route('consulta.receita.nova') }}" wire:navigate>tente outra foto</a>.</p></div>
        @else
            <p class="-mt-1 px-1 text-xl font-semibold leading-snug text-brand-900">Está tudo certo? Confira cada remédio e toque em Confirmar.</p>
            <p class="px-1 text-base text-slate-700">Remédio que não achamos no catálogo também entra no seu tratamento; só fica marcado para a nossa equipe conferir.</p>
            @if ($legibilidade === 'partial')<p class="px-1 text-base text-slate-700">Itens marcados com <strong>confira</strong> ficaram difíceis de ler.</p>@endif
        @endif

        <form id="rx-form" class="grid gap-3" wire:submit="confirmar">
            <details class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex min-h-14 cursor-pointer select-none items-center justify-between gap-2 px-5 text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Quem receitou <span class="truncate text-base font-normal text-slate-600">{{ $cabecalho['prescritor'] ?: 'não identificado' }}</span></summary>
                <div class="grid gap-3 border-t border-slate-100 p-5">
                    <div><label class="font-medium text-slate-800" for="rx-prescritor">Médico(a)</label><input id="rx-prescritor" class="ui-control min-h-14 text-lg" wire:model="cabecalho.prescritor" maxlength="120" required placeholder="Nome como está na receita"></div>
                    <div><label class="font-medium text-slate-800" for="rx-registro">CRM (opcional)</label><input id="rx-registro" class="ui-control min-h-14 text-lg" wire:model="cabecalho.registro" maxlength="40"></div>
                    <div><label class="font-medium text-slate-800" for="rx-data">Data da receita</label><input id="rx-data" class="ui-control min-h-14 text-lg" type="date" wire:model="cabecalho.data" max="{{ now()->format('Y-m-d') }}"></div>
                </div>
            </details>

            @forelse ($itens as $index => $item)
                <section wire:key="rx-item-{{ $index }}" x-data="{ corrigir: {{ $item['medicine_id'] === null && ! $item['skip'] ? 'true' : 'false' }}, editar: false }" class="grid gap-3 rounded-2xl border-2 bg-white p-5 shadow-sm {{ $item['skip'] ? 'border-slate-200 opacity-60' : ($item['confidence'] < 0.6 ? 'border-amber-400' : 'border-slate-200') }}" aria-labelledby="rx-item-t-{{ $index }}">
                    <h2 id="rx-item-t-{{ $index }}" class="text-2xl font-semibold leading-tight text-brand-900">{{ $index + 1 }}. {{ $item['name_as_written'] }}</h2>
                    @if ($item['confidence'] < 0.6)<p class="font-semibold text-amber-900">Leitura incerta: confira</p>@endif

                    @unless ($item['skip'])
                        <p class="text-slate-800">{{ collect([$item['dosage'], $item['frequency'], $item['duration']])->filter()->join(' · ') ?: 'Dose e horário não lidos' }}</p>
                        <div class="rounded-xl px-4 py-3 {{ $item['medicine_id'] ? 'bg-brand-50' : 'bg-amber-50' }}">
                            @if ($item['medicine_id'])
                                <p class="text-base text-slate-700">Remédio do catálogo</p><p class="text-lg font-semibold text-brand-900">{{ $item['medicine_name'] }}</p>
                            @else
                                <p class="font-semibold text-amber-900">Não encontrado no catálogo: vamos conferir</p>
                                <p class="text-base text-amber-900/90">Ele entra no seu tratamento com o nome escrito na receita. Nossa equipe vai ligá-lo à bula oficial.</p>
                            @endif
                        </div>
                        {{-- Horários do dia, sugeridos a partir de "quando tomar"; o paciente pode ajustar antes de salvar. --}}
                        <div class="grid gap-2 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200" role="group" aria-label="Horários de {{ $item['name_as_written'] }}">
                            <p class="text-lg font-semibold text-slate-900">Horários de hoje em diante</p>
                            @if ($item['as_needed'])
                                <p class="text-slate-800">Só quando precisar: sem horário fixo.</p>
                            @elseif ($item['times'] === [])
                                <p class="text-slate-800">Sem horário definido. Toque em “Adicionar horário” se quiser lembretes.</p>
                            @endif
                            @foreach ($item['times'] as $pos => $hora)
                                <div class="flex items-center gap-2" wire:key="rx-h-{{ $index }}-{{ $pos }}">
                                    <label class="sr-only" for="rx-hora-{{ $index }}-{{ $pos }}">Horário {{ $pos + 1 }}</label>
                                    <input id="rx-hora-{{ $index }}-{{ $pos }}" class="ui-control min-h-14 flex-1 text-xl" type="time" wire:model="itens.{{ $index }}.times.{{ $pos }}" step="300">
                                    <button type="button" class="min-h-14 shrink-0 rounded-xl px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="tirarHorario({{ $index }}, {{ $pos }})" aria-label="Tirar o horário {{ $hora ?: $pos + 1 }}">Tirar</button>
                                </div>
                            @endforeach
                            @if (count($item['times']) < 12)
                                <button type="button" class="min-h-14 rounded-xl border-2 border-dashed border-brand-600/50 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="adicionarHorario({{ $index }})">+ Adicionar horário</button>
                            @endif
                        </div>
                        <p class="text-lg font-semibold text-slate-900">Está certo?</p>
                        <div class="grid gap-2">
                            <button type="button" class="min-h-14 rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="corrigir = !corrigir" x-bind:aria-expanded="corrigir" x-text="corrigir ? 'Fechar' : '{{ $item['medicine_id'] ? 'Não, trocar o remédio' : 'Buscar o remédio' }}'">{{ $item['medicine_id'] ? 'Não, trocar o remédio' : 'Buscar o remédio' }}</button>
                            <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="editar = !editar" x-bind:aria-expanded="editar" x-text="editar ? 'Fechar' : 'Corrigir dose ou horário'">Corrigir dose ou horário</button>
                            <button type="button" class="min-h-12 rounded-xl px-3 text-base font-semibold text-slate-700 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="pular({{ $index }})">Não cadastrar este</button>
                        </div>
                        <div x-show="corrigir" x-cloak class="grid gap-2">
                            @if (count($item['candidates']) > 1 || ($item['medicine_id'] === null && $item['candidates'] !== []))
                                <div class="grid gap-2" role="group" aria-label="Opções do catálogo para {{ $item['name_as_written'] }}">
                                    @foreach ($item['candidates'] as $candidate)
                                        <button type="button" wire:key="rx-c-{{ $index }}-{{ $candidate['id'] }}" wire:click="escolher({{ $index }}, {{ $candidate['id'] }})" class="min-h-14 rounded-xl px-4 text-left text-lg font-medium ring-1 {{ $item['medicine_id'] === $candidate['id'] ? 'bg-brand-700 text-white ring-brand-700' : 'bg-white text-slate-800 ring-slate-300 hover:bg-brand-50' }}">{{ $candidate['name'] }} <span class="block text-base opacity-80">{{ \Illuminate\Support\Str::limit($candidate['holder'] ?? $candidate['ingredients'], 40) }}</span></button>
                                    @endforeach
                                </div>
                            @endif
                            @if ($item['medicine_id'])
                                <button type="button" class="min-h-14 rounded-xl border border-amber-400 bg-amber-50 px-4 text-lg font-semibold text-amber-900 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="manterPeloNome({{ $index }})">Nenhum destes: manter “{{ \Illuminate\Support\Str::limit($item['name_as_written'], 30) }}”</button>
                            @endif
                            <label class="font-medium text-slate-800" for="rx-busca-{{ $index }}">Buscar outro remédio</label>
                            <input id="rx-busca-{{ $index }}" class="ui-control min-h-14 text-lg" type="search" autocomplete="off" placeholder="Pelo menos 3 letras" wire:model.live.debounce.300ms="itens.{{ $index }}.busca">
                            @foreach ($buscas[$index] ?? [] as $found)
                                <button type="button" wire:key="rx-b-{{ $index }}-{{ $found['id'] }}" wire:click="escolher({{ $index }}, {{ $found['id'] }})" class="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 px-4 py-2 text-left hover:border-brand-600 hover:bg-brand-50"><span class="min-w-0"><span class="block text-lg font-semibold">{{ $found['name'] }}</span><span class="block text-base text-slate-700">{{ $found['ingredients'] }}@if (! empty($found['holder'])) · {{ $found['holder'] }}@endif</span></span><span class="shrink-0 font-semibold text-brand-800">Usar</span></button>
                            @endforeach
                        </div>
                        <div x-show="editar" x-cloak class="grid gap-3 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div><label class="font-medium text-slate-800" for="rx-dose-{{ $index }}">Dose</label><input id="rx-dose-{{ $index }}" class="ui-control min-h-14 text-lg" wire:model="itens.{{ $index }}.dosage" maxlength="120" placeholder="Ex.: 1 comprimido de 500 mg"></div>
                            <div><label class="font-medium text-slate-800" for="rx-freq-{{ $index }}">Quando tomar</label><input id="rx-freq-{{ $index }}" class="ui-control min-h-14 text-lg" wire:model="itens.{{ $index }}.frequency" maxlength="120" placeholder="Ex.: de 8 em 8 horas"></div>
                            <div><label class="font-medium text-slate-800" for="rx-dur-{{ $index }}">Por quanto tempo</label><input id="rx-dur-{{ $index }}" class="ui-control min-h-14 text-lg" wire:model="itens.{{ $index }}.duration" maxlength="80" placeholder="Ex.: 7 dias ou uso contínuo"></div>
                            <div><label class="font-medium text-slate-800" for="rx-obs-{{ $index }}">Observações</label><input id="rx-obs-{{ $index }}" class="ui-control min-h-14 text-lg" wire:model="itens.{{ $index }}.notes" maxlength="255" placeholder="Ex.: após as refeições"></div>
                        </div>
                    @else
                        <button type="button" class="min-h-14 rounded-xl bg-brand-50 px-4 text-lg font-semibold text-brand-900" wire:click="pular({{ $index }})">Incluir este item de novo</button>
                    @endunless
                </section>
            @empty
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700">Nenhum remédio foi identificado na foto. Tente outra foto ou cadastre à mão em Meus remédios.</p>
            @endforelse

            @if ($aviso)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $aviso }}</p>@endif
            <button type="button" class="min-h-14 rounded-xl border border-rose-300 bg-white px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="descartar" wire:loading.attr="disabled">Descartar esta receita</button>
        </form>

        <x-slot:sticky>
            <x-knowledge-admin.button type="submit" form="rx-form" variant="accent" class="min-h-16 w-full text-xl" loadingTarget="confirmar" loading="Salvando…">Confirmar e salvar</x-knowledge-admin.button>
        </x-slot:sticky>
    @endif
</x-consulta.shell>
