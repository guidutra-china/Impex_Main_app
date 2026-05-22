// Impex Fair — Service Worker (Phase 3)
// Caches the app shell + bridges Background Sync to open SPA clients so the
// queue gets drained as soon as the device is back online.
//
// The actual sync logic lives in the SPA (resources/js/fair-mobile/sync.js)
// because the queued items reference an auth token that lives in the SPA's
// localStorage. When a sync event fires, we nudge any open client to drain.

const VERSION = 'fair-mobile-v2';
const SHELL_CACHE = `${VERSION}-shell`;
const SYNC_TAG = 'fair-mobile-sync';

const SHELL_URLS = [
    '/fair-mobile',
    '/fair-mobile/manifest.json',
    '/fair-mobile/icons/icon-192.png',
    '/fair-mobile/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_URLS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Never cache API responses.
    if (url.pathname.startsWith('/api/')) return;

    if (
        url.pathname.startsWith('/fair-mobile') ||
        url.pathname.startsWith('/build/')
    ) {
        event.respondWith(staleWhileRevalidate(request));
    }
});

async function staleWhileRevalidate(request) {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);
    const networkFetch = fetch(request)
        .then((response) => {
            if (response && response.ok) {
                cache.put(request, response.clone()).catch(() => {});
            }
            return response;
        })
        .catch(() => null);

    if (cached) {
        networkFetch;
        return cached;
    }
    const network = await networkFetch;
    if (network) return network;

    if (request.mode === 'navigate') {
        const shell = await cache.match('/fair-mobile');
        if (shell) return shell;
    }
    return new Response('Offline', { status: 503, statusText: 'Offline' });
}

// ─── Background Sync bridge ────────────────────────────────────────
// Chromium fires this once the device regains connectivity. We forward the
// nudge to any open SPA client so it can call drainQueue() with full access
// to localStorage (auth token).

self.addEventListener('sync', (event) => {
    if (event.tag !== SYNC_TAG) return;
    event.waitUntil(nudgeClientsToSync());
});

async function nudgeClientsToSync() {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clients) {
        client.postMessage({ type: 'sync-now' });
    }
}
