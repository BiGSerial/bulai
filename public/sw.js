const CACHE_NAME = 'bulai-static-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
        )),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate' || url.pathname.startsWith('/admin') || url.pathname.startsWith('/login')) {
        event.respondWith(fetch(request));

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
