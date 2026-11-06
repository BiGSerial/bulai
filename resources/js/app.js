if ('serviceWorker' in navigator && import.meta.env.PROD) {
    let reloading = false;
    let waitingWorker = null;
    // Na primeira visita a página nasce sem controlador: o `clients.claim()` do worker novo dispara `controllerchange`
    // e NÃO deve recarregar a página (perderia o que o usuário estava digitando). Só recarrega após uma atualização aceita.
    const hadController = navigator.serviceWorker.controller !== null;

    // Atualização controlada: avisa a interface (evento `bulai-sw-update`) e só ativa a nova versão quando o usuário aceitar.
    window.bulaiApplyUpdate = () => waitingWorker?.postMessage('SKIP_WAITING');

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (hadController && !reloading) {
            reloading = true;
            window.location.reload();
        }
    });

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((registration) => {
            const announce = (worker) => {
                waitingWorker = worker;
                window.dispatchEvent(new CustomEvent('bulai-sw-update'));
            };
            if (registration.waiting && navigator.serviceWorker.controller) {
                announce(registration.waiting);
            }
            registration.addEventListener('updatefound', () => {
                const installing = registration.installing;
                installing?.addEventListener('statechange', () => {
                    if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                        announce(installing);
                    }
                });
            });
        });
    });
}

// Avisos do paciente no navegador (lembretes de dose): o sino (Livewire) dispara `bulai-aviso` quando chega um aviso novo.
// Só funciona com o aplicativo aberto e permissão concedida; não há Web Push (exigiria VAPID e biblioteca própria).
window.addEventListener('bulai-aviso', (event) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }
    const detail = event.detail ?? {};
    const title = detail.title ?? 'Aviso do BULA-IA';
    const options = { body: detail.body ?? '', icon: '/brand/bulai-icone-192.png', badge: '/brand/bulai-icone-192.png', tag: detail.tag ?? 'bulai-aviso', data: { url: '/hoje' } };
    const fallback = () => {
        try {
            new Notification(title, options);
        } catch (e) {
            // Alguns navegadores (Android) só mostram notificações pelo service worker.
        }
    };
    if (!('serviceWorker' in navigator)) {
        fallback();

        return;
    }
    navigator.serviceWorker.getRegistration().then((registration) => (registration ? registration.showNotification(title, options) : fallback())).catch(fallback);
});

// Tempo real (SSE): avisa os componentes Livewire para atualizar quando a fila/serviços mudam.
// O polling do Livewire continua como reserva se a conexão cair.
let realtimeSource = null;
let realtimeUrl = null;

function connectRealtime() {
    const url = document.querySelector('[data-realtime-url]')?.dataset.realtimeUrl ?? null;
    if (url === realtimeUrl && realtimeSource !== null) {
        return;
    }
    realtimeSource?.close();
    realtimeSource = null;
    realtimeUrl = url;
    if (url === null || typeof EventSource === 'undefined') {
        return;
    }
    realtimeSource = new EventSource(url, { withCredentials: true });
    realtimeSource.addEventListener('changed', () => window.dispatchEvent(new CustomEvent('bulai-realtime')));
}

document.addEventListener('livewire:navigated', connectRealtime);
document.addEventListener('DOMContentLoaded', connectRealtime);
window.addEventListener('pagehide', () => realtimeSource?.close());

import './voz.js';
import './pwa.js';
