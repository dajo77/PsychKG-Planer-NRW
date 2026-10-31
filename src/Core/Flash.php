<?php
namespace Src\Core;

/**
 * Flash messages for the Post/Redirect/Get pattern.
 * After a POST action, redirect with a message KEY (never free text in
 * the URL); on the following GET, consume() resolves whitelisted keys
 * to the display text. Prevents duplicate submissions on F5.
 */
class Flash
{
    /**
     * Redirects to $baseUrl with ?ok=KEY and/or ?err=KEY appended.
     */
    public static function redirect(string $baseUrl, ?string $ok = null, ?string $err = null): void
    {
        $sep = str_contains($baseUrl, '?') ? '&' : '?';
        if ($ok !== null) {
            $baseUrl .= $sep . 'ok=' . rawurlencode($ok);
            $sep = '&';
        }
        if ($err !== null) {
            $baseUrl .= $sep . 'err=' . rawurlencode($err);
        }
        header('Location: ' . $baseUrl);
        exit;
    }

    /**
     * Resolves whitelisted ?ok= / ?err= keys to display texts.
     * @param array<string,string> $okMap
     * @param array<string,string> $errMap
     */
    public static function consume(?string &$success, ?string &$error, array $okMap, array $errMap): void
    {
        $ok = $_GET['ok'] ?? null;
        if (is_string($ok) && isset($okMap[$ok])) {
            $success = $okMap[$ok];
        }
        $err = $_GET['err'] ?? null;
        if (is_string($err) && isset($errMap[$err])) {
            $error = $errMap[$err];
        }
    }
}
