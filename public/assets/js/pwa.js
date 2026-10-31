/**
 * PsychKG PWA: registriert den Service Worker und bietet Nutzern eine
 * Opt-in-Möglichkeit für Browser-Benachrichtigungen.
 */
(function () {
    'use strict';

    // Service Worker registrieren (nur über https/localhost)
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        });
    }

    const toast = window.showToast || function (m) { console.log(m); };

    function getNotifState() {
        if (!('Notification' in window)) return 'unsupported';
        return Notification.permission; // 'default' | 'granted' | 'denied'
    }

    // Expose an opt-in toggle on elements with data-pwa-notify.
    function updateBell(btn) {
        const state = getNotifState();
        btn.setAttribute('data-state', state);
        const icon = btn.querySelector('svg');
        if (icon) { icon.style.opacity = state === 'granted' ? '1' : '0.6'; }
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-pwa-notify]');
        if (!btn) return;
        const state = getNotifState();
        if (state === 'unsupported') { toast('Benachrichtigungen werden von diesem Browser nicht unterstützt.'); return; }
        if (state === 'denied') { toast('Benachrichtigungen sind blockiert. Bitte in den Browser-Einstellungen freigeben.'); return; }
        if (state === 'default') {
            const perm = await Notification.requestPermission();
            updateBell(btn);
            if (perm === 'granted') {
                toast('Benachrichtigungen aktiviert.');
                showDemoNotification();
            } else {
                toast('Benachrichtigungen abgelehnt.');
            }
            return;
        }
        if (state === 'granted') {
            // toggle: im Moment reine Info, da Backend-Push folgt.
            toast('Benachrichtigungen sind bereits aktiv (Push-Server folgt im nächsten Paket).');
        }
    });

    function showDemoNotification() {
        try {
            new Notification('PsychKG Bereitschaftsplaner', {
                body: 'Du erhältst Test-Benachrichtigungen. Push-Server folgt.',
                icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="%234f46e5"/><text x="32" y="44" font-size="40" text-anchor="middle" fill="white" font-family="system-ui" font-weight="700">P</text></svg>'
            });
        } catch (e) { /* ignore */ }
    }

    // Init all bells on DOM ready + after dynamic inserts.
    function initBells() {
        document.querySelectorAll('[data-pwa-notify]').forEach(updateBell);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBells);
    } else {
        initBells();
    }
})();
