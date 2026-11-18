{{--
    Conta (4º destino da barra, igual ao aplicativo Android): quem está conectado, nome de chamada, aparência, lembretes,
    avisos no aparelho, senha, atalhos, sair e apagar a conta.
--}}
<x-consulta.shell title="Conta">
    @if ($mensagem)<p role="status" class="rounded-2xl bg-brand-50 px-4 py-3 font-medium text-brand-900 ring-1 ring-brand-600/20">{{ $mensagem }}</p>@endif

    {{-- Quem está conectado. --}}
    <section class="flex min-w-0 items-center gap-4 px-1" aria-labelledby="conta-ola">
        <span class="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-brand-100 text-2xl font-bold text-brand-900" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->callName(), 0, 1)) ?: '?' }}</span>
        <div class="min-w-0">
            <h2 id="conta-ola" class="break-words text-2xl font-semibold leading-tight text-brand-900">Olá, {{ $user->callName() }}!</h2>
            <p class="break-words text-lg text-slate-700">{{ $user->name }}</p>
            <p class="break-words text-base text-slate-600">{{ $user->email }}</p>
        </div>
    </section>

    {{-- Como quer ser chamado(a). --}}
    <x-consulta.cartao aria-labelledby="conta-nome">
        <h2 id="conta-nome" class="text-xl font-semibold text-brand-900">Como quer ser chamado(a)?</h2>
        <p class="text-base text-slate-700">É assim que o BULA-IA cumprimenta você no Início e nos avisos.</p>
        <form class="grid gap-3" wire:submit="salvarNome">
            <div class="grid gap-1">
                <label for="conta-nome-campo" class="font-medium text-slate-800">Nome de chamada</label>
                <input id="conta-nome-campo" class="ui-control min-h-14 text-lg" wire:model="nomeDeChamada" maxlength="40" autocomplete="given-name" aria-describedby="conta-nome-ajuda">
                @error('nomeDeChamada')<p role="alert" class="text-base font-medium text-rose-800">{{ $message }}</p>@enderror
                <p id="conta-nome-ajuda" class="text-base text-slate-600">Deixe vazio para usar “{{ trim(explode(' ', trim($user->name))[0] ?? '') }}”.</p>
            </div>
            <x-consulta.botao type="submit" size="md" loadingTarget="salvarNome" loading="Salvando…">Salvar nome</x-consulta.botao>
        </form>
    </x-consulta.cartao>

    {{-- Aparência (claro, escuro ou igual ao celular). --}}
    <x-consulta.cartao>
        <x-consulta.tema />
    </x-consulta.cartao>

    {{-- Lembretes e avisos neste aparelho (vieram da tela de Avisos, que agora só lista os avisos). --}}
    <x-consulta.cartao aria-labelledby="conta-lembretes">
        <h2 id="conta-lembretes" class="flex items-center gap-2 text-xl font-semibold text-brand-900"><x-consulta.icone name="sino" size="sm" />Lembretes</h2>
        @if ($consentido)
            <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="pref-lembretes">
                <span class="min-w-0 text-lg font-semibold text-slate-900">Receber lembretes de horário</span>
                <input id="pref-lembretes" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="lembretes">
            </label>
            <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="pref-email">
                <span class="min-w-0"><span class="block text-lg font-semibold text-slate-900">Também por e-mail</span><span class="block break-words text-base text-slate-700">Para {{ $user->email }}</span></span>
                <input id="pref-email" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="email">
            </label>
            <div class="grid gap-2">
                <label class="text-lg font-semibold text-slate-900" for="pref-fuso">Horário da sua região</label>
                <select id="pref-fuso" class="ui-control min-h-14 text-lg" wire:model.live="fuso">
                    @foreach ($fusos as $valor => $rotulo)<option value="{{ $valor }}">{{ $rotulo }}</option>@endforeach
                </select>
            </div>
        @else
            <p class="text-slate-800">Para receber lembretes de horário, aceite guardar seus dados de saúde em “Meus remédios”.</p>
            <x-consulta.botao size="md" :href="route('consulta.perfil')">Abrir meus remédios</x-consulta.botao>
        @endif

        {{-- Avisos no aparelho: com Web Push (chave VAPID configurada) chegam até com o aplicativo fechado; sem ele, só com o aplicativo aberto. --}}
        @php
            $chavePush = filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key')) ? config('services.webpush.public_key') : null;
        @endphp
        <div x-data="{
                suporte: 'Notification' in window && 'serviceWorker' in navigator,
                permissao: 'Notification' in window ? Notification.permission : 'denied',
                chave: @js($chavePush),
                push: false,
                erro: '',
                async init() { if (this.chave && window.bulaiPush && window.bulaiPush.suportado()) { this.push = await window.bulaiPush.ativo().catch(() => false) } },
                async ativar() {
                    this.erro = '';
                    this.permissao = this.permissao === 'granted' ? 'granted' : await Notification.requestPermission();
                    if (this.permissao === 'granted' && this.chave && window.bulaiPush && window.bulaiPush.suportado()) {
                        try { await window.bulaiPush.ativar(this.chave); this.push = true } catch (e) { this.erro = 'Não consegui ativar os avisos com o aplicativo fechado neste aparelho. Tente de novo.' }
                    }
                },
            }" class="grid gap-2">
            <template x-if="suporte && (permissao === 'default' || (permissao === 'granted' && chave && !push))">
                <button type="button" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="ativar()" x-text="permissao === 'default' ? 'Ativar avisos neste aparelho' : 'Receber avisos também com o aplicativo fechado'">Ativar avisos neste aparelho</button>
            </template>
            <p x-cloak x-show="suporte && permissao === 'granted' && push" class="flex items-center gap-2 text-base text-slate-700"><x-consulta.icone name="check" plain class="size-5 shrink-0 text-emerald-700" />Avisos ativados neste aparelho, inclusive com o aplicativo fechado.</p>
            <p x-cloak x-show="suporte && permissao === 'granted' && !chave" class="flex items-center gap-2 text-base text-slate-700"><x-consulta.icone name="check" plain class="size-5 shrink-0 text-emerald-700" />Avisos neste aparelho ativados. Eles aparecem enquanto o aplicativo estiver aberto.</p>
            <p x-cloak x-show="erro !== ''" role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-base font-medium text-amber-900" x-text="erro"></p>
            <p x-cloak x-show="suporte && permissao === 'denied'" class="text-base text-slate-700">Os avisos deste aparelho estão bloqueados nas configurações do navegador.</p>
        </div>
    </x-consulta.cartao>

    {{-- Senha (recolhida): mesmas regras de "Minha conta". --}}
    <x-consulta.secao titulo="Senha" resumo="Trocar a senha de acesso" icon="escudo" :open="$errors->hasAny(['senhaAtual', 'novaSenha'])">
        <form class="grid gap-3" wire:submit="trocarSenha">
            <div class="grid gap-1">
                <label for="conta-senha-atual" class="font-medium text-slate-800">Senha atual</label>
                <input id="conta-senha-atual" type="password" class="ui-control min-h-14 text-lg" wire:model="senhaAtual" autocomplete="current-password">
                @error('senhaAtual')<p role="alert" class="text-base font-medium text-rose-800">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-1">
                <label for="conta-senha-nova" class="font-medium text-slate-800">Nova senha</label>
                <input id="conta-senha-nova" type="password" class="ui-control min-h-14 text-lg" wire:model="novaSenha" autocomplete="new-password">
                @error('novaSenha')<p role="alert" class="text-base font-medium text-rose-800">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-1">
                <label for="conta-senha-repetir" class="font-medium text-slate-800">Repita a nova senha</label>
                <input id="conta-senha-repetir" type="password" class="ui-control min-h-14 text-lg" wire:model="novaSenha_confirmation" autocomplete="new-password">
            </div>
            <p class="text-base text-slate-600">Ao trocar, as outras sessões abertas com a sua conta são encerradas; esta continua conectada.</p>
            <x-consulta.botao type="submit" size="md" loadingTarget="trocarSenha" loading="Trocando…">Trocar senha</x-consulta.botao>
        </form>
    </x-consulta.secao>

    {{-- Atalhos (o que antes ficava no menu da conta do cabeçalho). --}}
    <x-consulta.cartao aria-label="Atalhos da conta">
        <nav class="grid gap-1" aria-label="Atalhos da conta">
            @if ($cuidados->isNotEmpty())
                <p class="px-3 pt-1 text-sm font-semibold uppercase tracking-wide text-slate-500">Ver os remédios de</p>
                <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.hoje') }}" wire:navigate><x-consulta.icone name="usuario" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Meus remédios</span></a>
                @foreach ($cuidados as $cuidado)
                    <a wire:key="cuid-{{ $cuidado->id }}" class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.cuidando.hoje', $cuidado) }}" wire:navigate><x-consulta.icone name="coracao" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Cuidando de {{ $cuidado->patient->name }}</span></a>
                @endforeach
                <hr class="my-1 border-slate-200">
            @endif
            <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.historico') }}" wire:navigate><x-consulta.icone name="historico" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Minhas perguntas</span></a>
            @if ($consentido)
                <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.resumo') }}" download><x-consulta.icone name="baixar" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Resumo para o médico (PDF)</span></a>
            @endif
            <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.cuidadores') }}" wire:navigate><x-consulta.icone name="grupo" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Quem cuida de mim</span></a>
            <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.cuidar') }}" wire:navigate><x-consulta.icone name="coracao" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Cuidar de alguém</span></a>
            {{-- Só aparece quando o navegador pode instalar (Chrome/Edge) ou é o Safari do iPhone, e o app ainda não está instalado. --}}
            <button x-cloak x-show="instalar" type="button" class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="window.bulaiInstalarAgora()"><x-consulta.icone name="baixar" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Instalar o aplicativo</span></button>
            <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.sobre') }}" wire:navigate><x-consulta.icone name="info" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Sobre o BULA-IA</span></a>
            @if ($user->isAdmin())
                <a class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-medium text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.dashboard') }}"><x-consulta.icone name="painel" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Área administrativa</span></a>
            @endif
            <hr class="my-1 border-slate-200">
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-lg font-semibold text-slate-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" type="submit"><x-consulta.icone name="sair" plain class="size-6 shrink-0 text-brand-700" /><span class="min-w-0 flex-1 break-words">Sair da conta</span></button></form>
        </nav>
    </x-consulta.cartao>

    {{-- Apagar minha conta: aviso com tudo o que será apagado, senha e botão vermelho (mesmo texto do aplicativo). --}}
    <div x-data="{ apagar: @js($errors->has('senhaParaApagar')) }" class="grid gap-3">
        <button type="button" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="apagar = true" x-bind:aria-expanded="apagar"><x-consulta.icone name="apagar" plain class="size-6" />Apagar minha conta</button>
        <section x-cloak x-show="apagar" x-transition.opacity class="grid gap-4 rounded-2xl border-2 border-rose-300 bg-white p-4 shadow-sm sm:p-5" role="alertdialog" aria-labelledby="apagar-titulo">
            <h2 id="apagar-titulo" class="text-2xl font-semibold leading-tight text-slate-900">Apagar sua conta?</h2>
            <p class="text-lg text-slate-800">Sua conta e tudo o que você guardou no BULA-IA serão apagados deste aparelho e dos nossos servidores.</p>
            <div class="grid gap-2 rounded-2xl bg-rose-50 p-4">
                <p class="font-bold text-slate-900">O que será apagado</p>
                <ul class="grid gap-2 text-base text-slate-800">
                    @foreach ([['remedios', 'Remédios, horários e doses anotadas'], ['receita', 'Receitas, alergias e perfil de saúde'], ['pergunta', 'Perguntas e consultas de interação'], ['grupo', 'Avisos, cuidadores e aparelhos conectados'], ['usuario', 'Nome, nome de chamada e e-mail']] as [$icone, $texto])
                        <li class="flex items-start gap-3"><x-consulta.icone :name="$icone" plain class="mt-0.5 size-5 shrink-0 text-rose-700" /><span class="min-w-0 break-words">{{ $texto }}</span></li>
                    @endforeach
                </ul>
            </div>
            <p class="text-base text-slate-700">Fica apenas o registro de que uma conta foi apagada, sem nome, e-mail ou dados de saúde. Os lembretes param e, para voltar, basta fazer um novo cadastro.</p>
            <p class="flex items-center gap-2 font-bold text-rose-800"><x-consulta.icone name="alerta" plain class="size-6 shrink-0" />Não dá para desfazer.</p>
            <form class="grid gap-3" wire:submit="apagarConta">
                <div class="grid gap-1">
                    <label for="apagar-senha" class="font-medium text-slate-800">Confirme com sua senha</label>
                    <input id="apagar-senha" type="password" class="ui-control min-h-14 text-lg" wire:model="senhaParaApagar" autocomplete="current-password">
                    @error('senhaParaApagar')<p role="alert" class="text-base font-medium text-rose-800">{{ $message }}</p>@enderror
                </div>
                <x-consulta.botao type="submit" variant="danger" size="md" loadingTarget="apagarConta" loading="Apagando…">Apagar minha conta</x-consulta.botao>
                <x-consulta.botao variant="quiet" size="md" x-on:click="apagar = false">Cancelar</x-consulta.botao>
            </form>
        </section>
    </div>
</x-consulta.shell>
