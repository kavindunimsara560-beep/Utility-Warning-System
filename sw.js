// Balangoda Utility Warning System — Service Worker v3.0
// Dynamically compute base path from service worker location
const SW_URL = new URL(self.location.href);
const BASE_PATH = SW_URL.pathname.replace(/sw\.js$/, '');
const CACHE_NAME = 'balangoda-uws-v3';
const OFFLINE_URL = BASE_PATH + 'offline.html';

// Static assets to pre-cache on install
const PRECACHE_ASSETS = [
    BASE_PATH + 'offline.html',
    BASE_PATH + 'css/style.css',
    BASE_PATH + 'icons/icon-192.png',
    BASE_PATH + 'icons/icon-512.png',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
];

// ── Install: pre-cache static shell ──────────────────────────────────────────
self.addEventListener('install', event => {
    console.log('[SW] Installing v3 (Base path: ' + BASE_PATH + ')...');
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(PRECACHE_ASSETS).catch(err => {
                console.warn('[SW] Pre-cache partial failure (non-fatal):', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// ── Activate: purge old caches & claim clients ────────────────────────────────
self.addEventListener('activate', event => {
    console.log('[SW] Activating v3...');
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => key !== CACHE_NAME && key !== 'balangoda-warn-cache')
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

    // Only handle GET requests on same origin or allowed CDNs
    if (request.method !== 'GET') return;
    if (url.origin !== location.origin && !url.hostname.includes('cdn.jsdelivr.net') && !url.hostname.includes('fonts.g')) return;

    // Never cache dynamic API checks
    if (url.pathname.includes('/api/')) {
        return;
    }

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

    // PHP pages → Network-first (always fresh data; fallback to offline)
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

// ── BACKGROUND OUTAGE CHECK & DESKTOP / MOBILE NOTIFICATIONS ─────────────────
async function checkNewWarnings() {
    try {
        const cache = await caches.open('balangoda-warn-cache');
        const cachedResp = await cache.match(BASE_PATH + 'last-warning-id');
        let lastId = 0;
        if (cachedResp) {
            lastId = parseInt(await cachedResp.text(), 10) || 0;
        }

        const apiUrl = BASE_PATH + 'api/check_new_warnings.php?since_id=' + lastId;
        const res = await fetch(apiUrl, { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();

        if (data.status === 'ok' && data.has_new && Array.isArray(data.new_warnings) && data.new_warnings.length > 0) {
            for (const w of data.new_warnings) {
                await self.registration.showNotification(`🚨 Balangoda Outage Alert: ${w.utility_type}`, {
                    body: `${w.title}\n${w.description}`,
                    icon: BASE_PATH + 'icons/icon-192.png',
                    badge: BASE_PATH + 'icons/icon-192.png',
                    tag: 'warning-' + w.warning_id,
                    renotify: true,
                    requireInteraction: true,
                    vibrate: [200, 100, 200, 100, 200],
                    data: {
                        url: BASE_PATH + 'customer/dashboard.php',
                        warning_id: w.warning_id
                    }
                });
            }
            if (data.latest_id) {
                await cache.put(BASE_PATH + 'last-warning-id', new Response(String(data.latest_id)));
            }
        } else if (data.status === 'ok' && data.latest_id && lastId === 0) {
            // Store current baseline ID on first run
            await cache.put(BASE_PATH + 'last-warning-id', new Response(String(data.latest_id)));
        }
    } catch (err) {
        console.warn('[SW] checkNewWarnings failed:', err);
    }
}

// Periodic Background Sync (fires in background even if app is closed on supported browsers)
self.addEventListener('periodicsync', event => {
    if (event.tag === 'check-outage-warnings') {
        event.waitUntil(checkNewWarnings());
    }
});

// One-off Background Sync
self.addEventListener('sync', event => {
    if (event.tag === 'check-outage-warnings') {
        event.waitUntil(checkNewWarnings());
    }
});

// Client postMessage interface
self.addEventListener('message', event => {
    if (!event.data) return;
    if (event.data.type === 'CHECK_NOW') {
        event.waitUntil(checkNewWarnings());
    }
    if (event.data.type === 'SET_LAST_ID' && event.data.lastId) {
        caches.open('balangoda-warn-cache').then(c => {
            c.put(BASE_PATH + 'last-warning-id', new Response(String(event.data.lastId)));
        });
    }
});

// ── Push Notifications ────────────────────────────────────────────────────────
self.addEventListener('push', event => {
    let data = {
        title: 'Balangoda Utility Alert',
        body: 'A new municipal utility warning notice was published.',
        icon: BASE_PATH + 'icons/icon-192.png'
    };
    if (event.data) {
        try {
            data = { ...data, ...event.data.json() };
        } catch (e) {
            data.body = event.data.text();
        }
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body:  data.body,
            icon:  data.icon || (BASE_PATH + 'icons/icon-192.png'),
            badge: BASE_PATH + 'icons/icon-192.png',
            tag:   data.tag || ('balangoda-warn-' + Date.now()),
            data:  data.url ? { url: data.url } : { url: BASE_PATH + 'customer/dashboard.php' },
            vibrate: [200, 100, 200, 100, 200],
            requireInteraction: true,
        })
    );
});

// ── Notification click: open / focus customer dashboard ──────────────────────
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || (BASE_PATH + 'customer/dashboard.php');
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url.includes('customer/dashboard.php') && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(target);
            }
        })
    );
});
