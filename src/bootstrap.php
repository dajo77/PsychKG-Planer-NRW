<?php
declare(strict_types=1);
// PSR-4 Autoloader for Src namespace
spl_autoload_register(function ($class) {
    $prefix = 'Src\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

date_default_timezone_set(\Src\Core\Config::getTimezone());

// Fehlerbehandlung: niemals Fehler im Produktionsbetrieb direkt im HTML ausgeben,
// aber sauber loggen. Ein ungezähmter Fatal Error (z. B. fehlender Class-Import)
// führte bisher zu blanken Panels/Seiten – daher zentraler Handler.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    error_log('PsychKG [' . date('c') . '] PHP-' . $severity . ': ' . $message . ' in ' . $file . ':' . $line);
    // false => kein Handler übernimmt, PHP's Standardbehandlung greift.
    return false;
});

set_exception_handler(static function (\Throwable $e): void {
    error_log(
        'PsychKG EXCEPTION [' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    if (PHP_SAPI === 'cli') {
        // Im CLI (Tests/Smoke-Checks) nicht in eine HTML-Seite pressen.
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (ob_get_level() > 0) {
        while (ob_get_level() > 0) { ob_end_clean(); }
    }
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>PsychKG – Fehler</title></head><body style="font-family:system-ui;background:#f8fafc;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;"><div style="max-width:32rem;padding:2rem;text-align:center;"><h1 style="font-size:1.5rem;font-weight:700;margin:0 0 .5rem;">Ein Fehler ist aufgetreten</h1><p style="color:#475569;margin:0 0 1.5rem;">Die Anfrage konnte nicht abgeschlossen werden. Bitte wiederholen Sie die Aktion oder wenden Sie sich an die Bereitschaftsdienst-Administration.</p><a href="login.php" style="color:#4f46e5;font-weight:600;">Zur Anmeldung</a></div></body></html>';
    exit(1);
});

// Harden the session cookie before any session_start() happens.
// Secure is set only when THIS request is actually served over TLS, so plain
// HTTP deployments behind a non-TLS proxy keep working. Config::isHttps()
// accepts X-Forwarded-Proto only from a trusted proxy (REMOTE_ADDR).
// A dedicated session name isolates PsychKG from other apps on the same
// domain (one-time logout for all users after deploy).
if (session_status() === PHP_SESSION_NONE) {
    session_name('PSYCHKGSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => \Src\Core\Config::isHttps(),
        'samesite' => 'Lax',
    ]);
}

// Authenticated pages must never sit in the browser cache (shared duty PCs:
// back button after logout must not reveal the dashboard).
if (!headers_sent()) {
    header('Cache-Control: no-store, must-revalidate');
    header('Pragma: no-cache');
}

// Hardening headers. Clickjacking is the relevant one here: admin.php exposes
// destructive one-click actions (reset schedule, delete user), and SameSite=Lax
// does NOT stop the session cookie from riding along inside a cross-site iframe.
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // HSTS is only meaningful (and only valid) over an actual TLS connection.
    // Sending it on plain HTTP is ignored by browsers, but it would also
    // advertise TLS support this deployment may not have.
    if (\Src\Core\Config::isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Content-Security-Policy is ENFORCED (not Report-Only).
// Inline <script> blocks were moved to external files so 'unsafe-inline' is
// no longer needed for scripts; inline event handlers (onclick="switchTab(...)")
// are attached via addEventListener in the external JS files, so the policy is
// fully strict for scripts. Inline style="" attributes are still present, so
// style-src keeps 'unsafe-inline' for now (low risk: styles cannot execute JS).
// Webfonts are self-hosted (assets/fonts.css), so no third-party origin is
// allowed at all.
if (!headers_sent()) {
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self'; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'"
    );
}
