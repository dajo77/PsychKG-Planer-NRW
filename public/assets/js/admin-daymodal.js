// Tag-Detail-Modal im Admin (Day Detail Modal): Blöcke, Escaping, Focus-Trap.
// Wird von public/admin.php als externes Script geladen (CSP: script-src 'self').
var dayBlocksMap = {};
(function () {
    var el = document.getElementById('day-modal-data');
    if (el) {
        try { dayBlocksMap = JSON.parse(el.textContent) || {}; } catch (e) { dayBlocksMap = {}; }
    }
})();

var germanMonthsFull = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
var dayNames = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

function showDayDetail(dateKey) {
    var blocks = dayBlocksMap[dateKey] || [];
    var parts = dateKey.split('-');
    var d = new Date(parts[0], parts[1] - 1, parts[2]);
    var dayName = dayNames[d.getDay()];
    var monthName = germanMonthsFull[parseInt(parts[1])];
    document.getElementById('day-modal-title').textContent = dayName + ', ' + parts[2] + '. ' + monthName + ' ' + parts[0];

    var html = '';
    if (blocks.length === 0) {
        html = '<div class="text-center py-8"><svg class="w-12 h-12 mx-auto text-slate-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><p class="text-sm text-slate-500">Keine Dienste an diesem Tag</p></div>';
    } else {
        blocks.forEach(function (b) {
            var borderColor = b.status === 'trade_open' ? 'border-amber-300' : b.status === 'trade_pending' ? 'border-violet-300' : 'border-emerald-300';
            var badgeClass = b.status === 'trade_open' ? 'bg-amber-100 text-amber-700' : b.status === 'trade_pending' ? 'bg-violet-100 text-violet-700' : 'bg-emerald-100 text-emerald-700';
            var badgeText = b.status === 'trade_open' ? 'Tausch offen' : b.status === 'trade_pending' ? 'Freigabe ausstehend' : ((b.end_ts && (b.end_ts * 1000) < Date.now()) ? '✓ Erledigt' : 'Geplant');
            html += '<div class="p-3 rounded-xl border-l-4 ' + borderColor + ' bg-white border border-slate-200 shadow-sm"><div class="flex items-start justify-between"><div><div class="text-sm font-bold text-slate-900">' + escHtml(b.user) + '</div><div class="text-xs text-slate-500 mt-0.5">' + escHtml(b.start) + ' – ' + escHtml(b.end) + '</div><div class="text-[10px] text-slate-400 mt-0.5">Netto: ' + escHtml(b.hours) + 'h</div></div><span class="px-2 py-0.5 rounded text-[10px] font-bold ' + badgeClass + '">' + badgeText + '</span></div></div>';
        });
    }
    document.getElementById('day-modal-content').innerHTML = html;
    var modalEl = document.getElementById('day-modal');
    modalEl.classList.remove('hidden');
    modalEl.classList.add('show');
    // Move focus into the dialog and remember the trigger, so the
    // close button can hand focus back instead of dropping the user
    // at the top of the document.
    dayModalTrigger = document.activeElement;
    var closeBtn = document.querySelector('#day-modal-panel button');
    if (closeBtn) { closeBtn.focus(); }
}

var dayModalTrigger = null;

function closeDayModal() {
    var modalEl = document.getElementById('day-modal');
    modalEl.classList.remove('show');
    modalEl.classList.add('hidden');
    if (dayModalTrigger && typeof dayModalTrigger.focus === 'function') {
        dayModalTrigger.focus();
    }
    dayModalTrigger = null;
}

// Focus trap: keep Tab inside the dialog while it is open.
document.addEventListener('keydown', function (e) {
    var modal = document.getElementById('day-modal');
    if (!modal || modal.classList.contains('hidden')) { return; }
    if (e.key === 'Escape') { closeDayModal(); return; }
    if (e.key !== 'Tab') { return; }
    var panel = document.getElementById('day-modal-panel');
    var focusables = panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (focusables.length === 0) { return; }
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
});
