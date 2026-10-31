<?php
/**
 * Logout is a state-changing action, so it requires POST + a CSRF token.
 * As a bare GET it could be triggered from anywhere via an <img> tag, which
 * lets a third-party site force-logout any user whose browser is pointed at
 * the URL. The button in templates/nav.php posts to this endpoint.
 */
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Csrf;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $auth = new AuthManager();
    $auth->logout();

    header('Location: login.php?msg=logged_out');
    exit;
}

// GET is no longer supported; send the user back to the app instead of
// silently doing nothing.
header('Location: dashboard.php');
exit;