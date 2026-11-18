// Instalação do PWA e sensação nativa.
//
// O Chrome/Edge (Android e desktop) só mostram o botão "Instalar" depois de dispararem `beforeinstallprompt`, o que
// exige HTTPS, manifesto válido, service worker com `fetch` e algum engajamento do usuário. Guardamos o evento e
// avisamos a interface (`bulai-pode-instalar`); o cartão chama `window.bulaiInstalar()`. O Safari/iOS nunca dispara o
// evento: lá mostramos o passo a passo "Compartilhar → Adicionar à Tela de Início".

const DISMISS_KEY = 'bulai-instalar-dispensado-em';
const DISMISS_DAYS = 14;

let deferredPrompt = null;

const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

const ios = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent) && !window.MSStream;

// Safari de verdade no iOS (o Chrome/Firefox para iOS usam o motor do Safari, mas não sabem instalar a partir do Compartilhar).
const iosSafari = () => ios() && /safari/i.test(window.navigator.userAgent) && !/crios|fxios|edgios|opt\//i.test(window.navigator.userAgent);

const dismissed = () => {
    try {
        const at = Number(window.localStorage.getItem(DISMISS_KEY) ?? 0);

        return at > 0 && Date.now() - at < DISMISS_DAYS * 24 * 60 * 60 * 1000;
    } catch (e) {
        return false;
    }
};

const dismiss = () => {
    try {
        window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
    } catch (e) {
        // Armazenamento indisponível (modo privado): o cartão volta na próxima visita.
    }
    announce();
};

// Estado único consultado pelo cartão e pelo menu: `prompt` = dá para instalar com um toque; `ios` = mostrar o passo a passo.
const state = () => ({
    installed: standalone(),
    prompt: deferredPrompt !== null && !standalone(),
    ios: iosSafari() && !standalone(),
    dismissed: dismissed(),
});

const announce = () => window.dispatchEvent(new CustomEvent('bulai-pode-instalar', { detail: state() }));

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    announce();
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    announce();
    window.dispatchEvent(new CustomEvent('notify', { detail: { message: 'Pronto! O BULA-IA está na tela do seu celular.' } }));
});

window.bulaiInstalar = async () => {
    if (deferredPrompt === null) {
        return 'unavailable';
    }
    const prompt = deferredPrompt;
    deferredPrompt = null;
    try {
        prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice.outcome !== 'accepted') {
            dismiss();
        }
        announce();

        return choice.outcome;
    } catch (e) {
        announce();

        return 'error';
    }
};

window.bulaiInstalarDepois = dismiss;
window.bulaiInstalacao = state;

// Item "Instalar o aplicativo" do menu: instala na hora (Chrome/Edge) ou reabre o passo a passo na Início (iPhone).
window.bulaiInstalarAgora = () => {
    if (deferredPrompt !== null) {
        return window.bulaiInstalar();
    }
    try {
        window.localStorage.removeItem(DISMISS_KEY);
    } catch (e) {
        // Sem armazenamento não há dispensa guardada.
    }
    announce();
    if (window.location.pathname !== '/inicio') {
        window.Livewire?.navigate ? window.Livewire.navigate('/inicio') : window.location.assign('/inicio');
    }

    return Promise.resolve('steps');
};

// Cada tela (wire:navigate) consulta o estado ao montar; o evento inicial cobre o primeiro carregamento.
document.addEventListener('livewire:navigated', announce);
document.addEventListener('DOMContentLoaded', announce);

// Sensação nativa: fecha menus abertos ao navegar e volta ao topo da nova tela (como um app faria).
document.addEventListener('livewire:navigate', () => {
    window.dispatchEvent(new CustomEvent('bulai-navegando'));
});
document.addEventListener('livewire:navigated', () => {
    if (!window.location.hash) {
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    }
});
