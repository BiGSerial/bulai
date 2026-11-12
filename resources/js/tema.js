// Tema do app do paciente: "claro" (padrão), "escuro" ou "sistema" (segue o celular). A escolha fica neste aparelho
// (localStorage) e a classe `dark` no <html> é aplicada antes da pintura por um script curto no <head> (layouts/app).
const CHAVE = 'bulai-tema';
const escuroNoSistema = window.matchMedia('(prefers-color-scheme: dark)');

function escolhido() {
    try {
        return localStorage.getItem(CHAVE) ?? 'claro';
    } catch (e) {
        return 'claro';
    }
}

function aplicar(tema = escolhido()) {
    document.documentElement.classList.toggle('dark', tema === 'escuro' || (tema === 'sistema' && escuroNoSistema.matches));
}

window.bulaiTema = {
    atual: escolhido,
    definir(tema) {
        try {
            localStorage.setItem(CHAVE, tema);
        } catch (e) {
            // Sem armazenamento (navegação privada): vale só nesta página.
        }
        aplicar(tema);
        window.dispatchEvent(new CustomEvent('bulai-tema', { detail: { tema } }));
    },
};

escuroNoSistema.addEventListener('change', () => aplicar());
document.addEventListener('livewire:navigated', () => aplicar());
