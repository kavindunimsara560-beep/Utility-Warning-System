/**
 * pwa.js — Balangoda Utility Warning System PWA Handler
 * Handles: SW registration, install prompt, notification permission, beforeunload guard
 * Include this script on every page (before </body>)
 */

(function () {
    'use strict';

    // ── 1. SERVICE WORKER REGISTRATION ───────────────────────────────────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then(reg => {
                    console.log('[PWA] Service Worker registered. Scope:', reg.scope);
                })
                .catch(err => {
                    console.warn('[PWA] Service Worker registration failed:', err);
                });
        });
    }

    // ── 2. PWA INSTALL PROMPT ────────────────────────────────────────────────
    let _deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', e => {
        // Prevent Chrome's mini-infobar from appearing automatically
        e.preventDefault();
        _deferredPrompt = e;
        console.log('[PWA] beforeinstallprompt fired — showing install button.');

        // Show all install buttons on this page
        document.querySelectorAll('.pwa-install-btn').forEach(btn => {
            btn.classList.remove('d-none');
            btn.style.display = '';
        });
    });

    // Wire up install button click(s)
    document.addEventListener('click', async e => {
        if (!e.target.closest('.pwa-install-btn')) return;
        if (!_deferredPrompt) return;

        _deferredPrompt.prompt();
        const { outcome } = await _deferredPrompt.userChoice;
        console.log('[PWA] Install outcome:', outcome);
        _deferredPrompt = null;

        if (outcome === 'accepted') {
            document.querySelectorAll('.pwa-install-btn').forEach(btn => btn.classList.add('d-none'));
        }
    });

    // Hide install button if app is already running as installed PWA
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
        document.querySelectorAll('.pwa-install-btn').forEach(btn => btn.classList.add('d-none'));
    }

    window.addEventListener('appinstalled', () => {
        console.log('[PWA] App installed successfully!');
        _deferredPrompt = null;
        document.querySelectorAll('.pwa-install-btn').forEach(btn => btn.classList.add('d-none'));
    });

    // ── 3. NOTIFICATION PERMISSION ───────────────────────────────────────────
    // Call window.requestNotifPermission() from any button to ask for permission
    window.requestNotifPermission = async function () {
        if (!('Notification' in window)) {
            alert('This browser does not support desktop notifications.');
            return 'denied';
        }
        if (Notification.permission === 'granted') {
            console.log('[PWA] Notifications already granted.');
            return 'granted';
        }
        if (Notification.permission === 'denied') {
            console.warn('[PWA] Notifications are blocked. User must enable them in browser settings.');
            return 'denied';
        }
        const result = await Notification.requestPermission();
        console.log('[PWA] Notification permission result:', result);
        if (result === 'granted') {
            new Notification('🔔 Balangoda Utility Warnings', {
                body: 'Notifications enabled! You\'ll be alerted about utility outages.',
                icon: '/icons/icon-192.png',
            });
        }
        return result;
    };

    // ── 4. BEFOREUNLOAD GUARD (leave-page confirmation) ─────────────────────
    // Set window.enableLeaveGuard = true on any page that has unsaved form data
    // The guard only activates when this flag is true
    window.enableLeaveGuard = false;

    window.addEventListener('beforeunload', e => {
        if (!window.enableLeaveGuard) return;
        const msg = 'You have unsaved changes. Are you sure you want to leave?';
        e.preventDefault();
        e.returnValue = msg; // Required for Chrome
        return msg;
    });

    // Auto-enable guard when a form field is changed, auto-disable on submit
    document.addEventListener('change', e => {
        if (e.target.closest('form')) {
            window.enableLeaveGuard = true;
        }
    });
    document.addEventListener('submit', () => {
        window.enableLeaveGuard = false;
    });

})();
