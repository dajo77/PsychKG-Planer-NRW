# Contributing

Vielen Dank für Ihr Interesse an der Mitentwicklung des PsychKG Bereitschaftsplaners NRW.

## Branch-Modell

- `main` ist der stabile Veröffentlichungsstand.
- Feature- und Fix-Zweige werden kurzlebig angelegt und über Pull Requests zurückgeführt.
- Keine direkten Pushes auf `main` außer bei kritischen Hotfixes (dann dokumentieren und nachträglich prüfen).

## Commit-Konventionen

Wir folgen **Conventional Commits** (`<type>: <description>`).

- `fix:` Fehlerbehebung (mit Breaking-Change-Hinweis, falls nötig)
- `feat:` neue Funktion (nicht rückwärtskompatibel = Breaking Change)
- `security:` sicherheitsrelevante Änderung
- `docs:` Dokumentation
- `test:` Tests
- `refactor:` reine Strukturverbesserung ohne Funktionsänderung
- `style:` Formatierung, Leerzeichen, keine Logikänderung

Jeder Commit muss nachvollziehbar und commit-fähig sein; große Pakete werden in kleine, einzeln nachvollziehbare Schritte aufgeteilt.

## Coding-Style

- **PSR-12** (PHP Coding Standards)
- `declare(strict_types=1);` in neuen Dateien
- Sicherheitsrelevante Stellen mit kurzem Kommentar (`// IDOR-Guard`, `// CSP-Nonce`, `// Race-Condition`)
- Keine Inline-Event-Handler (`onclick=`); externe Dateien mit `addEventListener`
- Keine Passwörter, Session-IDs oder vollständige personenbezogene Daten in Logdateien

## Test-Anleitung

Der Runner ist dependency-frei und läuft sowohl lokal als auch auf dem Produktionshost:

```bash
php tests/run_tests.php
```

Für eine erweiterte Prüfung (Syntax, Autoload, PHP-Standard):

```bash
php -l src/Auth/AuthManager.php
php -l src/Logging/Logger.php
php -l src/Security/LoginThrottle.php
```

Wenn Composer lokal verfügbar ist:

```bash
composer dump-autoload
composer test
```

Tests müssen für neue Funktionen ergänzt oder aktualisiert werden. Bestehende Tests, die durch Regeländerungen ungültig werden (z. B. Passwortlänge), müssen angepasst werden.

## Dokumentation

- Neue Funktionen erhalten mindestens einen Satz in `README.md` (Setup/Tests/Sicherheit) und einen Eintrag in `SECURITY.md`, wenn sicherheitsrelevant.
- Keine Marketing-Texte in technischen Dateien; `README.md` darf kurze, sachliche Einleitungen enthalten.
