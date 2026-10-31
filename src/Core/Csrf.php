<?php
namespace Src\Core;

class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token = null): bool
    {
        self::ensureSession();
        $token = $token ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($token) || $token === '') {
            return false;
        }
        return isset($_SESSION[self::SESSION_KEY]) && hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function requireValid(): void
    {
        if (!self::validate()) {
            http_response_code(403);
            exit('Ungültige Anfrage (CSRF). Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
        }
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
