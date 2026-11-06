{{--
    Cartão "Instalar o aplicativo" (sênior-primeiro). Funciona sozinho: lê o estado de resources/js/pwa.js pelo evento
    `bulai-pode-instalar` e só aparece quando (a) o navegador ofereceu a instalação (Chrome/Edge) ou (b) é o Safari do iPhone/iPad,
    que exige o passo a passo. Não renderiza nada quando o app já está instalado ou quando foi dispensado há menos de 14 dias.
--}}
<section x-data="{ prompt: false, ios: false, installed: true, dispensado: true, passos: false,
        ler(estado) { this.prompt = estado.prompt; this.ios = estado.ios; this.installed = estado.installed; this.dispensado = estado.dismissed },
        get visivel() { return !this.installed && !this.dispensado && (this.prompt || this.ios) } }"
    x-init="if (window.bulaiInstalacao) ler(window.bulaiInstalacao())"
    x-on:bulai-pode-instalar.window="ler($event.detail)"
    x-cloak x-show="visivel" x-transition.opacity
    class="grid gap-3 rounded-2xl border border-brand-100 bg-white p-4 shadow-sm" aria-labelledby="instalar-titulo">
    <div class="flex items-start gap-3">
        <img class="size-14 shrink-0 rounded-xl" src="/brand/bulai-icone-app.svg" alt="" width="56" height="56">
        <div class="min-w-0">
            <h2 id="instalar-titulo" class="text-xl font-semibold leading-tight text-brand-900">Tenha o BULA-IA na tela do seu celular</h2>
            <p class="mt-1 text-base text-slate-700">Abre como um aplicativo, em tela cheia e com um toque.</p>
        </div>
    </div>

    <template x-if="prompt">
        <div class="grid gap-2">
            <button type="button" class="min-h-14 w-full rounded-xl bg-accent-600 px-4 text-lg font-semibold text-white hover:bg-accent-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-accent-500/40" x-on:click="window.bulaiInstalar()">Instalar</button>
            <button type="button" class="min-h-12 w-full rounded-xl px-4 font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="window.bulaiInstalarDepois()">Agora não</button>
        </div>
    </template>

    <template x-if="ios && !prompt">
        <div class="grid gap-2">
            <button type="button" class="min-h-14 w-full rounded-xl bg-accent-600 px-4 text-lg font-semibold text-white hover:bg-accent-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-accent-500/40" x-on:click="passos = !passos" x-bind:aria-expanded="passos">Como instalar no iPhone</button>
            <ol x-cloak x-show="passos" x-transition class="grid gap-3 rounded-xl bg-surface-100 p-4 text-base text-slate-800" aria-label="Passo a passo">
                <li class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-700 text-white"><svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" /></svg></span>
                    <span>Toque em <strong>Compartilhar</strong> na barra do Safari (o quadrado com a seta para cima).</span>
                </li>
                <li class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-700 text-white"><svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></span>
                    <span>Role a lista e toque em <strong>Adicionar à Tela de Início</strong>.</span>
                </li>
                <li class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-700 text-white"><svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></span>
                    <span>Toque em <strong>Adicionar</strong>. O BULA-IA aparece junto dos seus outros aplicativos.</span>
                </li>
            </ol>
            <button type="button" class="min-h-12 w-full rounded-xl px-4 font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="window.bulaiInstalarDepois()">Agora não</button>
        </div>
    </template>
</section>
