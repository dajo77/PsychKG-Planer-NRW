// Drucken-Funktion für die PDF-Exporte (window.print).
// Wird von export_pdf.php / export_schedule_pdf.php als externes Script geladen.
(function () {
    var btn = document.getElementById('print-btn');
    if (btn) {
        btn.addEventListener('click', function () { window.print(); });
    }
})();
