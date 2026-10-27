if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

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
