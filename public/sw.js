const CACHE_NAME = 'bulai-static-v4';
const PRECACHE = ['/offline', '/manifest.webmanifest', '/brand/bulai-icone-192.png', '/brand/bulai-icone-512.png'];
const OFFLINE_URL = '/offline';

// Sem skipWaiting automático: a nova versão só assume quando o usuário aceita atualizar (mensagem SKIP_WAITING).
self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
        )),
    );
    self.clients.claim();
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// Toque no aviso (lembrete de dose): foca a janela do aplicativo já aberta e leva a "Remédios de hoje"; senão, abre uma.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/hoje';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => 'focus' in client);
            if (open) {
                return open.focus().then((client) => ('navigate' in client ? client.navigate(url) : client));
            }

            return self.clients.openWindow(url);
        }),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Páginas (inclusive /admin, /login, /consulta, /historico, /hoje e /avisos) nunca vão para o cache: só a tela offline pública.
    if (request.mode === 'navigate' || url.pathname.startsWith('/admin') || url.pathname.startsWith('/login') || url.pathname.startsWith('/consulta') || url.pathname.startsWith('/historico') || url.pathname.startsWith('/perguntar') || url.pathname.startsWith('/perfil') || url.pathname.startsWith('/hoje') || url.pathname.startsWith('/avisos') || url.pathname.startsWith('/livewire')) {
        event.respondWith(fetch(request).catch(() => (request.mode === 'navigate' ? caches.match(OFFLINE_URL) : Response.error())));

        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/') || url.pathname === '/manifest.webmanifest') {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                const copy = response.clone();

                caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));

                return response;
            })),
        );
    }
});
