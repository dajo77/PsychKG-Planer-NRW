<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;

$auth = new AuthManager();
if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
