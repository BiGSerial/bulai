{{--
    Alarmes do aplicativo Android (docs/app-android.md). Só aparece dentro do app (window.bulaiNativo, resources/js/nativo.js):
    mostra o que falta permitir para o alarme tocar na hora certa, o modo despertador, o tempo do "Adiar" e um teste.
--}}
@php
    $permissoes = [
        ['chave' => 'notificacoes', 'rotulo' => 'Mostrar avisos', 'ajuda' => 'Sem isso o alarme não aparece na tela.'],
        ['chave' => 'alarmesExatos', 'tipo' => 'alarmes', 'rotulo' => 'Tocar na hora exata', 'ajuda' => 'Sem isso o Android pode atrasar o alarme alguns minutos.'],
        ['chave' => 'bateriaLivre', 'tipo' => 'bateria', 'rotulo' => 'Funcionar com a economia de bateria', 'ajuda' => 'Alguns celulares desligam os alarmes de aplicativos para poupar bateria.'],
    ];
@endphp
<div wire:ignore x-cloak
    x-data="{ estado: window.bulaiNativo ? window.bulaiNativo.estado() : null, testando: false }"
    x-on:bulai-nativo-estado.window="estado = $event.detail"
    x-show="estado !== null"
    class="grid gap-3 border-t border-slate-200 pt-4"
    aria-labelledby="alarmes-titulo">
    <h3 id="alarmes-titulo" class="flex items-center gap-2 text-lg font-semibold text-brand-900"><x-consulta.icone name="relogio" size="sm" />Alarmes neste celular</h3>
    <p class="text-base text-slate-700">O aplicativo toca um alarme na hora de cada remédio, mesmo sem internet e com o celular bloqueado. Pela notificação você marca “Tomei”, “Pular” ou “Adiar”.</p>

    <template x-if="estado && !estado.conectado">
        <p role="status" class="rounded-lg bg-amber-50 px-3 py-2 text-base font-medium text-amber-900">Ligando este celular à sua conta… Se não terminar, verifique a internet e abra esta tela de novo.</p>
    </template>

    <ul class="grid gap-2">
        @foreach ($permissoes as $permissao)
            <li class="flex min-h-14 flex-wrap items-center justify-between gap-2 rounded-2xl border border-slate-300 px-4 py-2">
                <span class="min-w-0 flex-1 basis-48">
                    <span class="block text-lg font-semibold text-slate-900">{{ $permissao['rotulo'] }}</span>
                    <span class="block text-base text-slate-700" x-show="estado && !estado.{{ $permissao['chave'] }}">{{ $permissao['ajuda'] }}</span>
                </span>
                <span x-show="estado && estado.{{ $permissao['chave'] }}" class="flex items-center gap-1 text-base font-semibold text-emerald-800"><x-consulta.icone name="check" plain class="size-5" />Pronto</span>
                <button type="button" x-show="estado && !estado.{{ $permissao['chave'] }}" class="inline-flex min-h-12 items-center rounded-xl bg-brand-700 px-4 text-base font-semibold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="window.bulaiNativo.pedirPermissao('{{ $permissao['tipo'] ?? $permissao['chave'] }}')">Permitir</button>
            </li>
        @endforeach
    </ul>

    <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="alarme-despertador">
        <span class="min-w-0"><span class="block text-lg font-semibold text-slate-900">Tocar como despertador</span><span class="block text-base text-slate-700">Tela cheia e som contínuo até você responder, como o alarme do relógio.</span></span>
        <input id="alarme-despertador" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" x-bind:checked="estado && estado.modoAlarme" x-on:change="window.bulaiNativo.definirModoAlarme($event.target.checked)">
    </label>
    <p x-show="estado && estado.modoAlarme && !estado.telaCheia" class="flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-base text-amber-900">
        <span class="min-w-0 flex-1">Falta permitir a tela cheia; sem ela o alarme toca, mas só como notificação.</span>
        <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-brand-700 px-3 text-base font-semibold text-white" x-on:click="window.bulaiNativo.pedirPermissao('tela_cheia')">Permitir</button>
    </p>

    <div class="grid gap-2">
        <label class="text-lg font-semibold text-slate-900" for="alarme-adiar">“Adiar” deixa para daqui a</label>
        <select id="alarme-adiar" class="ui-control min-h-14 text-lg" x-on:change="window.bulaiNativo.definirAdiamento($event.target.value)">
            @foreach ([5, 10, 15, 30] as $minutos)
                <option value="{{ $minutos }}" x-bind:selected="estado && estado.adiarMinutos === {{ $minutos }}">{{ $minutos }} minutos</option>
            @endforeach
        </select>
    </div>

    <p class="text-base text-slate-700" x-show="estado && estado.proximoAlarme" x-text="estado && estado.proximoAlarme ? 'Próximo alarme: ' + estado.proximoAlarme : ''"></p>
    <p class="text-base text-slate-700" x-show="estado && estado.conectado && !estado.proximoAlarme">Nenhum alarme marcado para as próximas horas.</p>

    <button type="button" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="window.bulaiNativo.testarAlarme(); testando = true; setTimeout(() => testando = false, 8000)" x-bind:disabled="testando" x-text="testando ? 'O alarme de teste toca em 5 segundos…' : 'Testar o alarme agora'">Testar o alarme agora</button>
</div>
