// Dark-Mode-Vorwahl vor dem Rendern (aus localStorage / prefers-color-scheme).
// Wird von login.php, install.php und nav.php-Seiten als externes Script geladen.
(function () {
    try {
        var stored = localStorage.getItem('darkMode');
        var prefersDark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (stored === '1' || (!stored && prefersDark)) {
            document.documentElement.classList.add('dark');
        }
    } catch (e) {}
})();
