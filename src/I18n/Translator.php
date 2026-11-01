<?php
declare(strict_types=1);

namespace Src\I18n;

use Src\Core\Config;

/**
 * Minimaler Translator für die schrittweise Ablösung hartkodierter UI-Texte.
 *
 * Noch keine vollständige Lokalisierung: Es gibt genau eine Sprachdatei
 * (resources/lang/de.php). Die Infrastruktur erlaubt später weitere Sprachen,
 * ohne die Templates erneut anzufassen.
 *
 * Platzhalter im Format {name} werden aus $replace ersetzt. Der Rückgabewert
 * ist immer ein String; die Ausgabe muss im Template weiterhin escaped werden.
 */
final class Translator
{
    /** @var array<string,array<string,string>> */
    private static array $messages = [];

    private static string $locale = 'de';

    public static function setLocale(string $locale): void
    {
        $normalized = strtolower(trim($locale));
        if (preg_match('/^[a-z]{2}([_-][a-z]{2})?$/', $normalized) === 1) {
            self::$locale = str_replace('-', '_', $normalized);
        }
    }

    public static function getLocale(): string
    {
        $env = getenv('PSYCHKG_LOCALE');
        if (is_string($env) && $env !== '') {
            self::setLocale($env);
        }

        return self::$locale;
    }

    /**
     * Übersetzt einen Schlüssel. Fehlt er, wird der Schlüssel selbst
     * zurückgegeben (fail-open, damit die UI nie leer bleibt).
     *
     * @param array<string,scalar|null> $replace
     */
    public static function t(string $key, array $replace = []): string
    {
        $messages = self::load(self::getLocale());
        $text = $messages[$key] ?? null;

        if ($text === null && self::getLocale() !== 'de') {
            $text = self::load('de')[$key] ?? null;
        }

        if ($text === null) {
            return $key;
        }

        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }

        return $pairs === [] ? $text : strtr($text, $pairs);
    }

    /**
     * @return array<string,string>
     */
    private static function load(string $locale): array
    {
        if (isset(self::$messages[$locale])) {
            return self::$messages[$locale];
        }

        $path = Config::getBaseDir() . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $locale . '.php';

        if (!is_file($path)) {
            return self::$messages[$locale] = [];
        }

        $messages = include $path;
        if (!is_array($messages)) {
            return self::$messages[$locale] = [];
        }

        $normalized = [];
        foreach ($messages as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return self::$messages[$locale] = $normalized;
    }
}
