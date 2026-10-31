// Admin-Konsole: Tab-Navigation, Reset, Hash-Handling, Arrow-Keys, Form-Confirmations.
// Wird von public/admin.php als externes Script geladen (CSP: script-src 'self').
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(function (el) {
        el.classList.add('hidden');
        el.setAttribute('aria-hidden', 'true');
    });
    document.querySelectorAll('.tab-btn').forEach(function (el) {
        el.classList.remove('text-indigo-700', 'border-indigo-600', 'bg-indigo-50/70');
        el.classList.add('text-slate-500', 'border-transparent');
        el.setAttribute('aria-selected', 'false');
        el.setAttribute('tabindex', '-1');
    });
    var activePanel = document.getElementById(tabId);
    if (!activePanel) {
        return;
    }
    activePanel.classList.remove('hidden');
    activePanel.setAttribute('aria-hidden', 'false');
    var btn = document.querySelector('[data-tab="' + tabId + '"]');
    if (btn) {
        btn.classList.remove('text-slate-500', 'border-transparent');
        btn.classList.add('text-indigo-700', 'border-indigo-600', 'bg-indigo-50/70');
        btn.setAttribute('aria-selected', 'true');
        btn.setAttribute('tabindex', '0');
    }
    document.getElementById('mobile-menu')?.classList.add('hidden');
    history.replaceState(null, '', '#' + tabId);
}

document.addEventListener('click', function (e) {
    var target = e.target;
    if (!target || typeof target.closest !== 'function') { return; }
    var tabBtn = target.closest('[data-tab]');
    if (tabBtn) { switchTab(tabBtn.getAttribute('data-tab')); return; }
    var resetBtn = target.closest('[data-confirm-reset]');
    if (resetBtn) { confirmReset(); return; }
    var dayCell = target.closest('[data-day-detail]');
    if (dayCell) { showDayDetail(dayCell.getAttribute('data-day-detail')); return; }
    var closeBtn = target.closest('[data-day-modal-close]');
    if (closeBtn) { closeDayModal(); return; }
});

function confirmReset() {
    if (confirm('Möchten Sie wirklich den gesamten Dienstplan unwiderruflich löschen und zurücksetzen?')) {
        var resetForm = document.getElementById('reset-form');
        if (resetForm) { resetForm.submit(); }
    }
}

// Form-Confirmations pro Form (data-confirm="...") statt Inline-Handler.
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute) { return; }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});

// Arrow-key navigation between tabs (WAI-ARIA authoring practices).
document.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'Home' && e.key !== 'End') { return; }
    var btn = e.target.closest ? e.target.closest('.tab-btn') : null;
    if (!btn) { return; }
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab-btn'));
    var idx = tabs.indexOf(btn);
    if (idx === -1) { return; }
    var next = idx;
    if (e.key === 'ArrowRight') { next = (idx + 1) % tabs.length; }
    else if (e.key === 'ArrowLeft') { next = (idx - 1 + tabs.length) % tabs.length; }
    else if (e.key === 'Home') { next = 0; }
    else if (e.key === 'End') { next = tabs.length - 1; }
    e.preventDefault();
    switchTab(tabs[next].getAttribute('data-tab'));
    tabs[next].focus();
});

function initHashTab() {
    var hash = location.hash.replace('#', '');
    var validTabs = ['tab-plan', 'tab-trades', 'tab-users', 'tab-billing', 'tab-profile', 'tab-audit', 'tab-nachweise', 'tab-system'];
    if (hash && validTabs.indexOf(hash) !== -1) {
        switchTab(hash);
    }
}
document.addEventListener('DOMContentLoaded', initHashTab);
document.addEventListener('hashchange', initHashTab);

// Planungsjahr-Select: nur die Anzeige aktualisieren (Jahr fuer PDF-Export
// und Planung), KEIN automatisches Generieren. Frueher wurde das Formular
// direkt abgeschickt, was bei einem Wechsel auf ein vergangenes Jahr die
// Meldung "Zeitraum liegt in der Vergangenheit" ausloeste.
var planYearSelect = document.getElementById('plan_year');
if (planYearSelect) {
    var syncPlanYearLabels = function () {
        var labels = document.querySelectorAll('[data-plan-year-label]');
        for (var i = 0; i < labels.length; i++) {
            labels[i].textContent = planYearSelect.value;
        }
    };
    planYearSelect.addEventListener('change', function () {
        syncPlanYearLabels();
        var url = new URL(window.location.href);
        url.searchParams.set('tab', 'plan');
        url.searchParams.set('plan_year', this.value);
        window.location.href = url.toString();
    });
    syncPlanYearLabels();
}
