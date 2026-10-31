<?php
declare(strict_types=1);
namespace Src\Core;

/**
 * Small output/input helpers shared by the entry points.
 *
 * Exists because two recurring defects needed a single, correct place:
 *  - Interpolating user-controlled data into an inline JS handler via
 *    htmlspecialchars() does NOT protect it. htmlspecialchars produces
 *    HTML entities, and the browser decodes those *before* the JS engine
 *    parses the attribute, so the quote comes back and breaks out of the
 *    string literal. `js()` uses json_encode() instead, which is the
 *    correct encoder for a JS string literal inside an HTML attribute.
 *  - Query parameters are attacker-controlled and `?foo[]=bar` makes
 *    $_GET['foo'] an array. Passing an array to preg_match()/htmlspecialchars()
 *    is a TypeError in PHP 8 and turns a bad URL into a 500. `q()` and
 *    `qi()` normalise the input to a scalar first.
 */
final class Html
{
    /**
     * Encodes a value for insertion INSIDE an existing JavaScript string
     * literal, e.g. `onclick="fn('<?= Html::js($v) ?>')"`.
     *
     * Why htmlspecialchars() is wrong here: it produces HTML entities, and the
     * browser decodes those *before* the JS engine parses the attribute. A
     * name like `x'); alert(1); //` survives as `x&#039;); alert(1); //`,
     * decodes back to `x'); alert(1); //`, and breaks out of the literal.
     *
     * This returns the *inner* content of a JSON string — the surrounding
     * double quotes are stripped, because the caller supplies its own quoting.
     * JSON_HEX_APOS / JSON_HEX_QUOT ensure no quote character survives, so the
     * value is simultaneously safe for the HTML attribute and for the JS
     * parser. JSON_HEX_TAG / JSON_HEX_AMP defuse < > & the same way.
     */
    public static function js(mixed $value): string
    {
        $json = json_encode(
            (string) $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            return '';
        }

        // Strip the JSON string delimiters; the caller provides the literal.
        return substr($json, 1, -1);
    }

    /**
     * Returns a query parameter as a scalar string.
     *
     * Arrays are rejected (returned as '') rather than cast, because
     * $_GET['x[]=1' yields an array and silently casting it to "Array"
     * would hide the malformed request. Missing values return $default.
     */
    public static function q(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * Same as q() for POST body values.
     */
    public static function p(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * POST value without trimming.
     *
     * Passwords must never be trimmed: leading/trailing spaces are part of the
     * secret, and trimming turns a correct login into a silent failure. The
     * scalar guard still rejects `?password[]=x` (array -> TypeError).
     */
    public static function pRaw(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? null;
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Returns a query parameter constrained to an integer range.
     * Out-of-range and malformed values fall back to $default.
     */
    public static function qi(string $key, int $default, int $min, int $max): int
    {
        $raw = self::q($key);
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return $default;
        }
        $value = (int) $raw;
        return ($value < $min || $value > $max) ? $default : $value;
    }

    /**
     * Strict Y-m-d query parameter; returns '' when not exactly that format.
     */
    public static function qDate(string $key): string
    {
        $raw = self::q($key);
        if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return ($dt !== false && $dt->format('Y-m-d') === $raw) ? $raw : '';
    }

    /**
     * Strict H:i query parameter; returns '' when not exactly that format.
     */
    public static function qTime(string $key): string
    {
        $raw = self::q($key);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) === 1 ? $raw : '';
    }

    /**
     * Lowercases a token and returns it only if it is in $allowed.
     * Unknown values return $default, which keeps callers from silently
     * rendering an empty or unintended view.
     *
     * @param string[] $allowed
     */
    public static function token(string $raw, array $allowed, string $default): string
    {
        $value = strtolower(trim($raw));
        return in_array($value, $allowed, true) ? $value : $default;
    }
}