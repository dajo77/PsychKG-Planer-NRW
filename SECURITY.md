# Security Policy

## Meldeweg für Sicherheitslücken

Bitte melden Sie Sicherheitslücken **vertraulich** per E-Mail an:

```
security@psychkg-planer-nrw.example
```

Bitte verschlüsseln Sie die Nachricht mit PGP (öffentlicher Schlüssel verfügbar auf Anfrage oder unter `https://psychkg-planer-nrw.example/pgp.asc`). Keine öffentlichen Issues für Sicherheitslücken erstellen, bevor ein Fix veröffentlicht wurde.

## Gültigkeits-Scope

Dieses Dokument und die Sicherheitsprozesse gelten für:

- `src/` (PHP-Quellcode, Authentifizierung, Session-Management, Logging)
- `public/` (Eintrittspunkte, Controller, Templates)
- `templates/` (gemeinsame UI-Blöcke)
- Konfigurationsdateien (`config.local.php`, `.htaccess`, CSP-Header)
- Abhängigkeiten in `composer.json` (nur PHP-Standardbibliotheken, kein externer Service)

Ausgeschlossen sind Server-Betriebssystem, Plesk/nginx-Proxy und das Netzwerk des Betreibers, sofern sie nicht direkt zur Anwendung gehören.

## Response-Zeiten

- Bestätigung des Eingangs: innerhalb von 48 Stunden
- Erste Einschätzung und ggf. Behebungsplan: innerhalb von 5 Werktagen
- Veröffentlichung des Fixes: abhängig von Schweregrad, in der Regel innerhalb von 14 Tagen

## Bekannte Sicherheitsarchitektur (Stand Dokumentation)

- **CSP**: Nonce-basierter `script-src` und `style-src 'unsafe-inline'` nur für vorhandene Inline-Attribute; alle externen Skripte tragen `nonce`.
- **Session-Härtung**: `use_strict_mode=1`, `cookie_httponly=1`, `cookie_samesite=Lax`, `session_regenerate_id()` nach Login.
- **Passwortregeln**: mindestens 12 Zeichen, mindestens ein Großbuchstabe, eine Ziffer und ein Sonderzeichen (`[^\p{L}\p{N}\s]`).
- **Login-Throttling**: dateibasierte Zählung (`sys_get_temp_dir()`), `flock()`, probabilistisches `prune()` (1 % der Aufrufe) und Monitoring (`Logger::warning` bei ≥ 5 Fehlversuchen).
- **IDOR-Schutz**: `dashboard.php` und `TradeService::requestTrade()` prüfen `assignedUserId` gegen angemeldeten Benutzer; unbekannte IDs führen zu 403 (kein Informationsleck).
- **Exception-Handler**: ersetzt durch `Logger::error()`; Fehler werden nicht im HTML ausgegeben (`display_errors=0`).
- **Install-Skript**: selbstlöschend (`unlink` + `.disabled`-Fallback); Warnung bei Fehlschlag.
