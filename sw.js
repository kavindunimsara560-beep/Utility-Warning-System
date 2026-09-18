// Balangoda Utility Warning System — Service Worker v2.0
// Paths are root-relative (served from localhost:8000/)
const CACHE_NAME = 'balangoda-uws-v2';
const OFFLINE_URL = '/offline.html';

// Static assets to pre-cache on install
const PRECACHE_ASSETS = [
    '/offline.html',
    '/css/style.css',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
];

// ── Install: pre-cache static shell ──────────────────────────────────────────
self.addEventListener('install', event => {
    console.log('[SW] Installing v2...');
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(PRECACHE_ASSETS).catch(err => {
                console.warn('[SW] Pre-cache partial failure (non-fatal):', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// ── Activate: purge old caches ────────────────────────────────────────────────
self.addEventListener('activate', event => {
    console.log('[SW] Activating v2...');
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => key !== CACHE_NAME)
                    .map(key => {
                        console.log('[SW] Deleting old cache:', key);
                        return caches.delete(key);
                    })
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch: strategy by request type ──────────────────────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle GET requests on same origin
    if (request.method !== 'GET') return;
    if (url.origin !== location.origin && !url.hostname.includes('cdn.jsdelivr.net') && !url.hostname.includes('fonts.g')) return;

    // Static assets (CSS, JS, fonts, images) → Cache-first
    const isStaticAsset = (
        url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|woff2?|ttf|ico)$/i) ||
        url.hostname.includes('cdn.jsdelivr.net') ||
        url.hostname.includes('fonts.googleapis.com') ||
        url.hostname.includes('fonts.gstatic.com')
    );

    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(c => c.put(request, clone));
                    }
                    return response;
                }).catch(() => caches.match(OFFLINE_URL));
            })
        );
        return;
    }

    // PHP pages → Network-first (always fresh data; fallback to cache or offline)
    if (url.pathname.match(/\.php$/i) || url.pathname === '/' || url.pathname.endsWith('/')) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(c => c.put(request, clone));
                    }
                    return response;
                })
                .catch(() =>
                    caches.match(request)
                        .then(cached => cached || caches.match(OFFLINE_URL))
                )
        );
        return;
    }
});

// ── Push Notifications ────────────────────────────────────────────────────────
// Handles push events sent from a Push server (for future Web Push integration)
self.addEventListener('push', event => {
    let data = { title: 'Balangoda Utility Alert', body: 'A new utility update is available.', icon: '/icons/icon-192.png' };
    if (event.data) {
        try { data = { ...data, ...event.data.json() }; } catch (e) { data.body = event.data.text(); }
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body:  data.body,
            icon:  data.icon || '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag:   data.tag || 'balangoda-utility',
            data:  data.url ? { url: data.url } : {},
            vibrate: [200, 100, 200],
        })
    );
});

// ── Notification click: open / focus the app ─────────────────────────────────
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/index.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url.includes(target) && 'focus' in client) return client.focus();
            }
            if (clients.openWindow) return clients.openWindow(target);
        })
    );
});
