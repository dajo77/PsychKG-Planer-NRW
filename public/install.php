<?php
/**
 * Installation script for PsychKG Bereitschaftsplaner.
 * Run this once after deploying to initialize demo data.
 * Delete this file after successful installation.
 */
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Core\Config;
use Src\Core\Csrf;
use Src\Core\Seeder;
use Src\Storage\JsonStorage;

// Security gate: this endpoint creates accounts without authentication, so it
// is opt-in only. Enable it locally with ['allow_install' => true] in
// config.local.php. Production has no such flag and therefore gets a 404.
if (!Config::isInstallerEnabled()) {
    http_response_code(404);
    exit;
}

$usersStorage = new JsonStorage(Config::getUsersFilePath());
$usersData = $usersStorage->read();

$isInstalled = !empty($usersData['users']);

$messages = [];
$generatedPasswords = [];

if ($isInstalled) {
    $messages[] = ['type' => 'info', 'text' => 'System ist bereits installiert. Dieses Script kann gelöscht werden.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_install'])) {
    // CSRF-Guard: dieser Endpoint schreibt Benutzerkonten und ist ansonsten
    // unauthentifiziert erreichbar. Ohne Token könnte eine fremde Seite die
    // Installation per Cross-Site-Request auslösen.
    Csrf::requireValid();

    // Generate random passwords and seed. Errors are reported instead of
    // claiming success: the previous version printed "Installation
    // erfolgreich" even when the write failed.
    try {
        $generatedPasswords = Seeder::seedWithRandomPasswords();
    } catch (\RuntimeException $e) {
        $messages[] = ['type' => 'error', 'text' => 'Installation fehlgeschlagen: ' . $e->getMessage()];
    }

    if (!empty($generatedPasswords)) {
        $messages[] = ['type' => 'success', 'text' => 'Installation erfolgreich abgeschlossen!'];
        $isInstalled = true;

        // Self-destruct only after a verified successful write: this file must
        // never survive the installation. If unlink() fails (read-only file,
        // Windows lock, restrictive permissions), rename it to *.disabled:
        // PHP only executes *.php, so the dangerous endpoint is gone either way.
        $deleted = @unlink(__FILE__);
        $renamed = false;
        if (!$deleted) {
            $renamed = @rename(__FILE__, __FILE__ . '.disabled');
        }

        if ($deleted) {
            $messages[] = [
                'type' => 'warning',
                'text' => 'Dieses Script hat sich selbst entfernt. Bitte prüfen, dass install.php wirklich nicht mehr erreichbar ist.',
            ];
        } elseif ($renamed) {
            $messages[] = [
                'type' => 'warning',
                'text' => 'WARNUNG: install.php konnte nicht gelöscht werden und wurde in install.php.disabled umbenannt. '
                    . 'Bitte löschen Sie die Datei public/install.php.disabled jetzt manuell vom Server.',
            ];
        } else {
            $messages[] = [
                'type' => 'error',
                'text' => 'WARNUNG: install.php konnte NICHT automatisch entfernt werden. '
                    . 'Löschen Sie die Datei public/install.php sofort manuell vom Server und prüfen Sie, dass sie nicht mehr erreichbar ist.',
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" class="h-full bg-slate-50 text-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PsychKG Planer - Installation</title>
    <!-- Lokales Tailwind CSS (CSP-konform, kein externes CDN) -->
    <link rel="stylesheet" href="assets/app.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📅</text></svg>">
    <!-- Lokale Webfonts (kein CDN, keine IP-Uebermittlung an Dritte) -->
    <link rel="stylesheet" href="assets/fonts.css">
    <meta name="theme-color" content="#4f46e5">
    <script src="assets/js/dark-mode.js" nonce="<?= CSP_NONCE ?>"></script>
</head>
<body class="min-h-full flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex p-3 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-600 shadow-lg mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">PsychKG Planer</h1>
            <p class="text-slate-500 text-sm mt-1">Installation</p>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
            <?php foreach ($messages as $msg): ?>
                <div class="p-4 rounded-xl text-sm flex items-start space-x-2
                    <?php
                    echo match($msg['type']) {
                        'success' => 'bg-emerald-50 border border-emerald-200 text-emerald-800',
                        'error' => 'bg-rose-50 border border-rose-200 text-rose-800',
                        'warning' => 'bg-amber-50 border border-amber-200 text-amber-800',
                        default => 'bg-slate-50 border border-slate-200 text-slate-700',
                    };
                    ?>">
                    <?php if ($msg['type'] === 'success'): ?>
                        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php elseif ($msg['type'] === 'error'): ?>
                        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($msg['text']) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($generatedPasswords)): ?>
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 text-sm">
                    <p class="font-semibold mb-2">Zugangsdaten (bitte notieren!):</p>
                    <table class="w-full text-xs">
                        <tr class="font-bold"><td class="py-1">Benutzer</td><td>Passwort</td></tr>
                        <?php foreach ($generatedPasswords as $entry): ?>
                            <tr><td class="py-1"><?= htmlspecialchars($entry['username']) ?> (<?= htmlspecialchars($entry['role']) ?>)</td><td class="font-mono bg-white px-1.5 py-0.5 rounded border border-blue-100"><?= htmlspecialchars($entry['password']) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!$isInstalled && empty($generatedPasswords)): ?>
                <div class="text-center">
                    <p class="text-sm text-slate-600 mb-4">Demo-Daten mit 1 Administrator und 3 Mitarbeitern erstellen?</p>
                    <form method="POST">
                        <?= Csrf::field() ?>
                        <button type="submit" name="confirm_install" value="1"
                                class="w-full py-3 text-center bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition-all shadow-sm">
                            Installation starten
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($isInstalled): ?>
                <a href="login.php" class="block w-full py-3 text-center bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition-all shadow-sm">
                    Zum Login
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
