/**
 * pwa.js — Balangoda Utility Warning System PWA & Background Notification Handler
 * Handles:
 *  - Dynamic Service Worker registration with scope
 *  - Automatic browser notification permission requests
 *  - Real-time & background outage checks (while running or in background)
 *  - PWA install prompt
 */

(function () {
    'use strict';

    // ── 1. BASE PATH RESOLUTION ──────────────────────────────────────────────
    const getBasePath = () => {
        const p = window.location.pathname;
        const idx = p.indexOf('/Web_base_project/');
        if (idx !== -1) return '/Web_base_project/';
        return '/';
    };
    const BASE_PATH = getBasePath();

    // ── 2. SERVICE WORKER REGISTRATION ───────────────────────────────────────
    let _swReg = null;

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(BASE_PATH + 'sw.js', { scope: BASE_PATH })
                .then(reg => {
                    _swReg = reg;
                    console.log('[PWA] Service Worker registered. Scope:', reg.scope);

                    // If permissions are already granted, register periodic background sync
                    if (Notification.permission === 'granted') {
                        registerBackgroundSync(reg);
                    }
                })
                .catch(err => {
                    console.warn('[PWA] Service Worker registration failed:', err);
                });
        });
    }

    function registerBackgroundSync(reg) {
        if ('periodicSync' in reg) {
            reg.periodicSync.register('check-outage-warnings', {
                minInterval: 60 * 1000 // Check every minute in background
            }).catch(() => {});
        }
        if ('sync' in reg) {
            reg.sync.register('check-outage-warnings').catch(() => {});
        }
    }

    // ── 3. NOTIFICATION PERMISSION & BACKGROUND MONITOR ───────────────────────
    let _lastWarningId = parseInt(localStorage.getItem('balangoda_last_warn_id') || '0', 10);

    async function initBaselineWarningId() {
        try {
            const res = await fetch(BASE_PATH + 'api/check_new_warnings.php', { cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();
            if (data.status === 'ok' && data.latest_id) {
                if (!_lastWarningId) {
                    _lastWarningId = data.latest_id;
                    localStorage.setItem('balangoda_last_warn_id', String(_lastWarningId));
                }
                if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.controller.postMessage({
                        type: 'SET_LAST_ID',
                        lastId: _lastWarningId
                    });
                }
            }
        } catch (e) {
            console.warn('[PWA] Baseline init error:', e);
        }
    }

    // Send a rich notification using Service Worker registration (native system notification)
    async function triggerSystemNotification(warning) {
        const title = `🚨 Balangoda Outage: ${warning.utility_type}`;
        const body = `${warning.title}\n${warning.description}`;
        const options = {
            body: body,
            icon: BASE_PATH + 'icons/icon-192.png',
            badge: BASE_PATH + 'icons/icon-192.png',
            tag: 'warning-' + warning.warning_id,
            renotify: true,
            requireInteraction: true,
            vibrate: [200, 100, 200, 100, 200],
            data: { url: BASE_PATH + 'customer/dashboard.php' }
        };

        if (navigator.serviceWorker && navigator.serviceWorker.ready) {
            const reg = await navigator.serviceWorker.ready;
            reg.showNotification(title, options);
        } else if ('Notification' in window) {
            new Notification(title, options);
        }
    }

    // Check for newly published warnings
    async function checkForNewWarnings() {
        if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
            return;
        }

        const since = _lastWarningId || parseInt(localStorage.getItem('balangoda_last_warn_id') || '0', 10);
        if (since === 0) {
            await initBaselineWarningId();
            return;
        }

        try {
            const res = await fetch(BASE_PATH + 'api/check_new_warnings.php?since_id=' + since, { cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();

            if (data.status === 'ok' && data.has_new && Array.isArray(data.new_warnings) && data.new_warnings.length > 0) {
                for (const w of data.new_warnings) {
                    await triggerSystemNotification(w);
                }
                _lastWarningId = data.latest_id;
                localStorage.setItem('balangoda_last_warn_id', String(_lastWarningId));

                if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.controller.postMessage({
                        type: 'SET_LAST_ID',
                        lastId: _lastWarningId
                    });
                }
            }
        } catch (e) {
            // Ignore temporary network errors
        }
    }

    // Auto-request browser notification permission
    async function requestBrowserPermission() {
        if (!('Notification' in window)) return 'unsupported';

        if (Notification.permission === 'default') {
            try {
                const res = await Notification.requestPermission();
                console.log('[PWA] Notification permission prompt result:', res);
                if (res === 'granted') {
                    await initBaselineWarningId();
                    if (_swReg) registerBackgroundSync(_swReg);
                    
                    // Trigger confirmation notice
                    if (navigator.serviceWorker && navigator.serviceWorker.ready) {
                        navigator.serviceWorker.ready.then(reg => {
                            reg.showNotification('⚡ Balangoda Utility Alerts Active', {
                                body: 'Notifications enabled! You will be alerted when new municipal outages are published.',
                                icon: BASE_PATH + 'icons/icon-192.png',
                                badge: BASE_PATH + 'icons/icon-192.png',
                                tag: 'balangoda-welcome',
                                vibrate: [100, 50, 100],
                            });
                        });
                    }
                }
                return res;
            } catch (err) {
                console.warn('[PWA] requestPermission error:', err);
            }
        } else if (Notification.permission === 'granted') {
            await initBaselineWarningId();
            if (_swReg) registerBackgroundSync(_swReg);
            return 'granted';
        }
        return Notification.permission;
    }

    // Expose for external calls
    window.requestNotifPermission = requestBrowserPermission;

    // Trigger permission request on load AND on first user gesture
    if ('Notification' in window) {
        if (Notification.permission === 'default') {
            window.addEventListener('load', () => {
                setTimeout(requestBrowserPermission, 800);
            });
            const handleFirstGesture = () => {
                requestBrowserPermission();
                document.removeEventListener('click', handleFirstGesture);
                document.removeEventListener('touchstart', handleFirstGesture);
            };
            document.addEventListener('click', handleFirstGesture, { once: true });
            document.addEventListener('touchstart', handleFirstGesture, { once: true });
        } else if (Notification.permission === 'granted') {
            initBaselineWarningId();
        }
    }

    // Continuous polling check every 12 seconds
    setInterval(checkForNewWarnings, 12000);

    // When tab visibility changes (user switches apps, minimizes browser, or prepares to close app)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            // Register background sync so service worker can awaken
            if (navigator.serviceWorker && navigator.serviceWorker.ready) {
                navigator.serviceWorker.ready.then(reg => {
                    registerBackgroundSync(reg);
                    if (reg.active) {
                        reg.active.postMessage({ type: 'CHECK_NOW' });
                    }
                });
            }
        } else if (document.visibilityState === 'visible') {
            // Check immediately upon return
            checkForNewWarnings();
        }
    });

    // ── 4. PWA INSTALL PROMPT ────────────────────────────────────────────────
    let _deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        _deferredPrompt = e;
        document.querySelectorAll('.pwa-install-btn').forEach(btn => {
            btn.classList.remove('d-none');
            btn.style.display = '';
        });
    });

    document.addEventListener('click', async e => {
        if (!e.target.closest('.pwa-install-btn')) return;
        if (!_deferredPrompt) return;

        _deferredPrompt.prompt();
        const { outcome } = await _deferredPrompt.userChoice;
        _deferredPrompt = null;

        if (outcome === 'accepted') {
            document.querySelectorAll('.pwa-install-btn').forEach(btn => btn.classList.add('d-none'));
        }
    });

    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
        document.querySelectorAll('.pwa-install-btn').forEach(btn => btn.classList.add('d-none'));
    }

    window.addEventListener('appinstalled', () => {
        _deferredPrompt = null;
        document.querySelectorAll('.pwa-install-btn').forEach(btn => btn.classList.add('d-none'));
    });

})();
