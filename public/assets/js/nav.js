// Toast-System, Dark Mode, Live-Ticker (Clock/Countdown) und DOM-Init.
// Wird von templates/nav.php als externes Script geladen (CSP: script-src 'self').
(function () {
    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function updateDarkIcons() {
        var isDark = document.documentElement.classList.contains('dark');
        var sun = document.getElementById('dark-icon-sun');
        var moon = document.getElementById('dark-icon-moon');
        if (sun) sun.classList.toggle('hidden', !isDark);
        if (moon) moon.classList.toggle('hidden', isDark);
    }

    window.toggleDarkMode = function () {
        // Sanfter Farbübergang (CSS-Klasse .theming, wird nach 400ms entfernt)
        document.documentElement.classList.add('theming');
        document.documentElement.classList.toggle('dark');
        var isDark = document.documentElement.classList.contains('dark');
        try {
            localStorage.setItem('darkMode', isDark ? '1' : '0');
        } catch (e) {}
        updateDarkIcons();
        setTimeout(function () {
            document.documentElement.classList.remove('theming');
        }, 400);
    };

    // Init dark mode from localStorage (sofort, ohne Flackern)
    var darkInit = null;
    try {
        darkInit = localStorage.getItem('darkMode');
    } catch (e) {}
    if (darkInit === '1' || (!darkInit && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    }
    updateDarkIcons();

    window.showToast = function (message, type, options) {
        options = options || {};
        // Critical messages (e.g. "Dienstplan zurückgesetzt") stay until dismissed; they must not disappear while the user is reading them.
        var sticky = options.sticky === true;
        var container = document.getElementById('toast-container');
        if (!container) return;
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        if (sticky) { toast.classList.add('toast-sticky'); }
        var icon = type === 'success'
            ? '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            : type === 'error'
            ? '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>'
            : '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        toast.innerHTML = icon + '<span>' + String(message).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span><span class="toast-close" role="button" aria-label="Meldung schliessen">&times;</span>';
        container.appendChild(toast);
        requestAnimationFrame(function () { requestAnimationFrame(function () { toast.classList.add('show'); }); });
        if (!sticky) {
            setTimeout(function () { toast.classList.add('hide'); setTimeout(function () { toast.remove(); }, 350); }, 3500);
        }
    };

    // Live-Ticker: Uhr ([data-clock]) + Countdowns ([data-countdown] als Unix-TS).
    function tickAll() {
        var now = Date.now();
        document.querySelectorAll('[data-clock]').forEach(function (el) {
            var d = new Date();
            el.textContent = pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
        });
        document.querySelectorAll('[data-countdown]').forEach(function (el) {
            var target = parseInt(el.getAttribute('data-countdown'), 10);
            if (isNaN(target)) { return; }
            var diff = Math.floor(target - now / 1000);
            if (diff <= 0) {
                el.textContent = 'jetzt';
                el.classList.add('text-amber-600', 'font-semibold');
                el.setAttribute('aria-live', 'polite');
                if (!document.documentElement.hasAttribute('data-pending-refresh')) {
                    document.documentElement.setAttribute('data-pending-refresh', '1');
                    var refresh = function () {
                        if (document.visibilityState === 'visible') { location.reload(); }
                    };
                    document.addEventListener('visibilitychange', function () {
                        if (document.visibilityState === 'visible') { refresh(); }
                    });
                    setTimeout(refresh, 60000);
                }
                return;
            }
            el.textContent = pad2(Math.floor(diff / 3600)) + ':' + pad2(Math.floor((diff % 3600) / 60)) + ':' + pad2(diff % 60);
        });
    }
    setInterval(tickAll, 1000);

    function initDom() {
        // Welcome-Overlay nach Login (einmalig, serverseitig per Session-Flag gesetzt)
        var welcome = document.getElementById('welcome-overlay');
        if (welcome) {
            var dismissWelcome = function () {
                welcome.classList.add('welcome-hide');
                setTimeout(function () { welcome.remove(); }, 350);
            };
            welcome.addEventListener('click', dismissWelcome);
            setTimeout(dismissWelcome, 1700);
        }

        // Auto-dismiss PHP alerts as toasts
        document.querySelectorAll('.php-alert').forEach(function (el) {
            var type = el.classList.contains('php-alert-success') ? 'success' : el.classList.contains('php-alert-error') ? 'error' : 'info';
            var text = el.textContent.trim();
            // Errors that report a refused action stay until dismissed.
            var sticky = el.hasAttribute('data-sticky') || el.classList.contains('php-alert-error');
            el.remove();
            if (text) { window.showToast(text, type, { sticky: sticky }); }
        });

        // Loading states on all forms
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('[type="submit"]');
                if (btn && !btn.classList.contains('btn-loading')) {
                    btn.classList.add('btn-loading');
                    var origText = btn.innerHTML;
                    btn.dataset.origText = origText;
                    setTimeout(function () { btn.classList.remove('btn-loading'); btn.innerHTML = origText; }, 8000);
                }
            });
        });

        // Hochzählende Kennzahlen: data-countup="123.4" data-decimals="1"
        document.querySelectorAll('[data-countup]').forEach(function (el) {
            var target = parseFloat((el.getAttribute('data-countup') || '0').replace(',', '.'));
            var dec = parseInt(el.getAttribute('data-decimals') || '0', 10);
            if (isNaN(target) || isNaN(dec)) { return; }
            var t0 = null;
            var dur = 900;
            function step(ts) {
                if (!t0) { t0 = ts; }
                var p = Math.min(1, (ts - t0) / dur);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = (target * eased).toLocaleString('de-DE', { minimumFractionDigits: dec, maximumFractionDigits: dec });
                if (p < 1) { requestAnimationFrame(step); }
            }
            requestAnimationFrame(step);
        });

        tickAll();
    }

    function boot() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initDom);
        } else {
            initDom();
        }
    }
    boot();

    // Toast-Schließen per Delegation (CSP: keine Inline-Handler mehr)
    var toastContainer = document.getElementById('toast-container');
    if (toastContainer) {
        toastContainer.addEventListener('click', function (e) {
            var closeEl = e.target && e.target.closest ? e.target.closest('.toast-close') : null;
            if (!closeEl) { return; }
            var toastEl = closeEl.parentElement;
            if (!toastEl) { return; }
            toastEl.classList.add('hide');
            setTimeout(function () { toastEl.remove(); }, 350);
        });
    }

    // Dark-Mode-Umschalten per Delegation (CSP: keine Inline-Handler mehr)
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-dark-toggle]') : null;
        if (t) { window.toggleDarkMode(); }
    });

    // Mobile Menü per Delegation (CSP: keine Inline-Handler mehr)
    var mobileBtn = document.getElementById('mobile-menu-btn');
    if (mobileBtn) {
        mobileBtn.addEventListener('click', function () {
            var menu = document.getElementById('mobile-menu');
            menu.classList.toggle('hidden');
            var expanded = !menu.classList.contains('hidden');
            this.setAttribute('aria-expanded', expanded);
        });
    }
})();
