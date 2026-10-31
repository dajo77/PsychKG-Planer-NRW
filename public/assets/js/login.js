// Login-Seite: Demo-Credential-Fill, Passwort-Visibility, Submit-Loading, Parallax.
// Wird von public/login.php als externes Script geladen (CSP: script-src 'self').
function setCredentials(username, password) {
    var userEl = document.getElementById('username');
    var pwEl = document.getElementById('password');
    if (userEl) { userEl.value = username; }
    if (pwEl) { pwEl.value = password; }
}

(function () {
    // Passwort anzeigen/verstecken
    var pw = document.getElementById('password');
    var toggle = document.getElementById('pw-toggle');
    if (pw && toggle) {
        toggle.addEventListener('click', function () {
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Passwort verbergen' : 'Passwort anzeigen');
            // Text-Label fuer Klarheit (Screenreader + visuell)
            var label = toggle.querySelector('.pw-toggle-label');
            if (label) { label.textContent = show ? 'Verbergen' : 'Anzeigen'; }
        });
        // Feststelltasten-Warnung
        var hint = document.getElementById('caps-hint');
        var capsTimer = null;
        function checkCaps(e) {
            var on = false;
            try { on = e.getModifierState && e.getModifierState('CapsLock'); } catch (err) {}
            if (hint) {
                hint.classList.toggle('hidden', !on);
                if (on) {
                    if (capsTimer) { clearTimeout(capsTimer); }
                    capsTimer = setTimeout(function () { hint.classList.add('hidden'); }, 3000);
                }
            }
        }
        pw.addEventListener('keyup', checkCaps);
        pw.addEventListener('keydown', checkCaps);
    }
})();

(function () {
    // Submit-Ladezustand (Spinner kommt aus .btn-loading in app.css)
    var form = document.querySelector('form[action="login.php"]');
    if (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[type="submit"]');
            if (btn) {
                btn.classList.add('btn-loading');
                btn.disabled = true;
                var origText = btn.textContent;
                btn.dataset.origText = origText;
                btn.textContent = 'Wird angemeldet...';
            }
        });
    }
    // 3D-Kippung + Blob-Parallax (nur Feinzeiger, kein Reduced-Motion)
    var card = document.getElementById('login-card');
    var fine = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!card || !fine || calm) { return; }
    var blobs = document.querySelectorAll('[data-parallax]');
    var raf = null;
    function render(tx, ty) {
        raf = null;
        card.style.transform = 'perspective(900px) rotateX(' + ty.toFixed(2) + 'deg) rotateY(' + tx.toFixed(2) + 'deg)';
        blobs.forEach(function (b) {
            var depth = parseFloat(b.getAttribute('data-parallax')) || 0;
            b.style.translate = (tx * depth / 5).toFixed(1) + 'px ' + (ty * depth / 5).toFixed(1) + 'px';
        });
    }
    document.addEventListener('mousemove', function (e) {
        if (raf) { return; }
        var nx = (e.clientX / window.innerWidth - 0.5) * 2;
        var ny = (e.clientY / window.innerHeight - 0.5) * 2;
        var tx = nx * 5;
        var ty = ny * -4;
        raf = requestAnimationFrame(function () { render(tx, ty); });
    });
    document.addEventListener('mouseleave', function () {
        card.style.transform = '';
        blobs.forEach(function (b) { b.style.translate = ''; });
    });
})();
