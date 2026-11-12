// Alerta de reação alérgica no Perguntar: além da caixa vermelha no balão, um aviso em destaque (SweetAlert2) que a pessoa
// precisa confirmar. A biblioteca só é baixada quando há alerta. O texto vem do servidor já escapado (só <strong> de marcação).
const icone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>';

window.bulaiAlertaAlergia = async (texto) => {
    const { default: Swal } = await import('sweetalert2');

    return Swal.fire({
        html: `<p class="bulai-alerta-topo">${icone}<span>Alerta de Reação Alérgica</span></p><p class="bulai-alerta-texto">${texto}</p>`,
        confirmButtonText: 'Entendi',
        buttonsStyling: false,
        focusConfirm: true,
        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
        customClass: { popup: 'bulai-alerta', htmlContainer: 'bulai-alerta-html', actions: 'bulai-alerta-acoes', confirmButton: 'bulai-alerta-ok' },
    });
};
