# PsychKG Bereitschaftsplaner

**Einsatzplanung für den Bereitschaftsdienst des Ordnungsamtes nach PsychKG NRW**

Entwickelt und gepflegt von **Dario Jozic** – E-Mail: **dario@jozic.de**

---

## Was ist diese Software?

Der **PsychKG Bereitschaftsplaner** ist eine webbasierte Anwendung für die Planung von Bereitschaftsdiensten von Einsatzgemeinschaften im Sinne des **Gesetzes über Hilfen und Schutzmaßnahmen bei psychischen Krankheiten (PsychKG NRW)**. 

Die Anwendung unterstützt Kommunen und Einsatzvereine dabei, **Bereitschaftspläne** über mehrere Jahre zu erstellen, zu verwalten und dienstlich abzubilden – vollständig dezentral, ohne externe Cloud-Dienste oder Datenbank-Server. Die App ist ein reines PHP-Tool mit lokalen JSON-Dateien als Datenspeicher.

Der Begriff **PsychKG** in diesem Kontext bezieht sich nicht auf die Diagnose psychischer Erkrankungen – die App verwaltet vielmehr die **dienstlichen Abläufe** (Bereitschaftswechsel, Innendienst, Tauschangebote), die im Rahmen der kommunalen Gefahrenabwehr nach PsychKG NRW anfallen (Bereitschaftsdienste des Ordnungsamtes, Unterstützung des Rettungsdienstes, Einsatzplanung bei psychisch-kranken Personen).

---

## Was kann die App?

### Kernfunktionen für die kommunale Einsatzplanung

- **Dienstplan-Generator** (Admin): Automatische Erstellung von Bereitschaftswochen, **Mehrjahresplanung** (aktuelles Jahr bis +5), Halbjahres-Auswahl und -Kombination.
- **Konfigurierbare Einsatzzeiten** (Admin → Tab *Einstellungen*):
  - Bereitschaftswechsels (Wochentag 1–7 + Uhrzeit HH:MM – Beginn des Bereitschaftsdienstes / Dienstwechsels)
  - Innendienst (aktiv/deaktiviert, Beginn/Ende Mo–Do, Beginn/Ende Fr, Innendienst-Tage Default Mo–Fr)
  - **24/7-Modus** möglich (Innendienst komplett deaktiviert → reine Bereitschaft)
- **Dashboard** (für alle angemeldeten Mitarbeiter): eigene Dienstübersicht, Tausch-Historie, Filter in der Übersicht, eigenes Passwort ändern, Live-Widget mit Telefonnummer des aktuell diensthabenden Kollegen.
- **Tauschmanagement (Marktplatz)**: Tauschangebote erstellen, Genehmigungen/ablehnen durch Admins, Historie der abgelehnten/angenommenen Tausche.
- **Abwesenheiten**: Urlaub und Krankheit pro Mitarbeiter erfassen, Berücksichtigung im Dienstplan.
- **Abrechnung / Stunden-Auswertung**: Leistungsfähige Netto-Stunden-Auswertung (jährlich, Halbjahr), Export als **PDF** oder **CSV**.
- **Nachweise / Versiegelung**: Monats- oder Halbjahres-Nachweise mit Hash-Wert (nachweislich unveränderbarer Dienstplan).
- **Export-Funktionen**: `export_pdf.php`, `export_schedule_pdf.php`, `export_ics.php` (iCal für Kalender-Import).
- **Sicherheits-Features**: CSRF-Schutz auf allen POST-Endpunkten, Rate-Limit pro IP, lokale Webfonts (kein Google-CDN, DSGVO-konform), CSP-Header (Content-Security-Policy), Session-Cookie-Sicherheitsflags (Secure, HttpOnly), Logout via POST mit CSRF-Token.

---

## Inhalt dieses öffentlichen Repositories

Dieses Repository stellt **öffentlich zur Verfügung**, damit andere Einsatzgemeinschaften oder Verwaltungen die App prüfen, testen und für eigene Zwecke adaptieren können.

### Enthalten

- **PHP-Code:** `public/`, `src/`, `templates/`, `tests/` – komplette Applikation
- **Beispiel-Daten:** `data/*.example.json` – reine Platzhalter-Dateien (fiktive Nutzernamen, leere Strukturen – **keine echten Personen oder Einsatzdaten**)
- **Konfiguration:** `config.local.example.php` – zeigt nur Beispiel-Pfade, keine Server-Geheimnisse
- **Handover-Text:** `HANDOVER.md` – Anleitung für die Weitergabe an eine neue Gemeinde

### Nicht enthalten

- Keine echten `data/*.json` mit Personen- oder Einsatzdaten (wurden aus `.gitignore` ausgeschlossen)
- Keine Server-Pfade, keine echten Passwörter, keine internen Konfigurationen

> ⚠️ **Hinweis:** Das Repository wurde so aufbereitet, dass **keine personenbezogenen Daten** enthalten sind. Alle `users.example.json`-Einträge sind fiktive Beispiel-Daten (z. B. Name „Max Muster", E-Mail `m.muster@beispiel.de`).

---

## Einsatz nach PsychKG NRW – Was das bedeutet

Die App wurde im Kontext des **PsychKG NRW** entwickelt – dem Gesetz über Hilfen und Schutzmaßnahmen bei psychischen Krankheiten im Land Nordrhein-Westfalen. Kernpunkte, die im Planungskontext relevant sind:

- Der **Bereitschaftsdienst des Ordnungsamtes** ist im Rahmen der Gefahrenabwehr einzurichten (§ 11 PsychKG NRW).
- **Bereitschaftswechsel** und **Innendienst** sind die klassischen Schicht-Organisations-Elemente, die diese App abbildet.
- Die App unterstützt bei der **Planung und Dokumentation** dieser Dienste (Dienstplan, Nachweise, Stundenabrechnung).
- **Feiertagsregelung**: Das integrierte Modell ist an NRW angepasst (11 Feiertage). Für andere Bundesländer müssten ggf. eigene Feiertage nachgerüstet werden.
- Das Gesetz verpflichtet die Kommunen, ** Sozialpsychiatrische Dienste** vorzuhalten – die App kann als organisatorisches Werkzeug hierfür eingesetzt werden.

Die Software löst **keine** rechtlichen Fragestellungen und ersetzt keine Fachberatung – sie dient der reinen **Verwaltung der dienstlichen Abläufe**.

---

## Schnellstart (lokale Einrichtung)

```powershell
# Repo klonen
git clone https://github.com/dajo77/PsychKG-Planer-NRW.git
cd PsychKG-Planer-NRW

# Beispiel-Daten für lokale Tests kopieren
copy data\users.example.json data\users.json
copy data\schedule.example.json data\schedule.json
copy data\trades.example.json data\trades.json
copy data\audit.example.json data\audit.json
copy data\snapshots.example.json data\snapshots.json

# Über Browser installieren (erst mit allow_install = true in config.local.php!)
# public/install.php aufrufen → Demo-Benutzer (1 Admin + 3 Mitarbeiter) erstellt
# Nach erfolgreichem Login: install.php löschen, allow_install auf false setzen
```

Alternativ Online-Installation:
- `public/install.php` aufrufen (nur, wenn `['allow_install' => true]` in `config.local.php` gesetzt ist)
- Script erzeugt Demo-Daten und löscht sich danach selbst (`@unlink`)

---

## Systemvoraussetzungen

- **PHP** 8.4 (oder höher, mindestens 8.1)
- **Webserver** mit PHP-Unterstützung (Apache2 / nginx / IIS)
- **Keine Datenbank** erforderlich (Speicherung via JSON-Dateien)
- **Keine Composer-Abhängigkeiten** (autoload via `bootstrap.php`, Stand-alonefähig)
- **Schriftarten** liegen lokal unter `public/assets/fonts/` (keine externen CDN-Requests → DSGVO-konform)

---

## Einrichtung für eine neue Einsatzgemeinde

1. Repository klonen oder als Zip-Archiv herunterladen.
2. Eigene `config.local.php` im Projekt-Root anlegen mit individuellem `data_dir` (Pfad außerhalb des Document Roots, z. B. `/var/www/vhosts/<gemeinde>.de/private/psychkg-data`).
3. `public/install.php` aufrufen (mit `allow_install = true`) → Demo-Benutzer erstellen.
4. Nach Login: `install.php` löschen, `allow_install` auf `false` setzen.
5. Über Admin-Tab **Einstellungen** die eigenen Einsatzzeiten konfigurieren:
   - Bereitschaftswechsels (z. B. Montag 22:00 statt Mittwoch 16:00)
   - Innendienst (aktiv, Zeiten, Tage – oder deaktiviert → 24/7)
6. Dienstplan für das gewünschte Halbjahr/Jahr generieren.
7. Mitarbeiter einladen (Nutzerverwaltung über Dashboard / Admin).

---

## Kontakt & Weitergabe

Für Fragen, Anpassungen oder Unterstützung bei der Einrichtung für eine neue Einsatzgemeinde:

**Dario Jozic**  
E-Mail: **dario@jozic.de**

Dieses Repository ist als **öffentliche Weitergabe-Version** konzipiert – es enthält den kompletten App-Code und Beispiel-Daten ohne sensible Inhalte. Bei Interesse an einer eigenen produktiven Instanz: Clone → Install → Konfiguration → Einsatzzeiten einstellen.

---

## Rechtliche Hinweise

- Die App dient der **Verwaltung dienstlicher Abläufe** und ersetzt keine Rechtsberatung.
- **PsychKG NRW** ist ein Schutzgesetz des Landes Nordrhein-Westfalen – die Default-Einstellungen in der App orientieren sich an diesem Gesetzeswerk.
- **DSGVO-konform**: Alle Daten bleiben lokal auf dem Server des Auftragsinhabers; keine externen CDNs, keine Cloud-Uploads.
- **Installations-Gate**: `public/install.php` ist standardmäßig deaktiviert und erfordert explizite Freischaltung (`allow_install = true` in `config.local.php`).

---

## Über dieses Projekt

**PsychKG Bereitschaftsplaner NRW** – eine webbasierte Anwendung für die mehrjährige Dienstplanung von Bereitschaftsdiensten nach dem Gesetz über Hilfen und Schutzmaßnahmen bei psychischen Krankheiten (PsychKG NRW) in Nordrhein-Westfalen.

### Kernbotschaft
Die App ermöglicht es Kommunen, Einsatzgemeinschaften und Ordnungsämtern, Bereitschaftspläne über mehrere Jahre hinweg automatisiert zu erstellen, zu verwalten und dienstlich abzubilden – vollständig dezentral, ohne externe Cloud-Dienste oder Datenbank-Server. Alle Daten bleiben lokal auf dem Server des Auftragsinhabers, was die Anwendung DSGVO-konform und unabhängig von Drittanbietern macht.

### Funktionale Schwerpunkte
- **Mehrjahresplanung**: Automatische Erstellung von Bereitschaftswochen von aktuell bis +5 Jahre ahead, inklusive Halbjahres-Auswahl und -Kombination
- **Schichtmodelle**: Konfigurierbare Bereitschaftswechseltage (Mo–So), Innendienst-Regeln, 24/7-Modus
- **Tauschmanagement**: Marktplatz für Tauschangebote mit Admin-Genehmigung und Historie
- **Abwesenheitsmanagement**: Erfassung von Urlaub und Krankheit pro Mitarbeiter, Berücksichtigung im Dienstplan
- **Auswertungen**: Leistungsfähige Netto-Stunden-Auswertung (jährlich, Halbjahr) als PDF oder CSV
- **Versiegelung**: Monats- oder Halbjahres-Nachweise mit Hash-Wert für nachweislich unveränderbare Dokumentation

### Zielgruppen
- Kommunen und Landkreise in NRW gemäß PsychKG NRW
- Einsatzgemeinschaften und Feuerwehrverbände
- Kommunale IT-Abteilungen und Verwaltungsdigitalisierungs-Verantwortliche

### Lizenz & Verbreitung
Die Software wird unter **proprietärer Lizenz** vertrieben (siehe `composer.json`). Der Quellcode ist öffentlich auf GitHub verfügbar, um Evaluierung, Prüfung und individuelle Anpassung zu ermöglichen. Für den produktiven Einsatz und individuelle Erweiterungen steht direkter Kontakt zur Verfügung (dario@jozic.de).

### Nächste Schritte
- Repository klonen und wie in der Schnellstart-Anleitung einrichten
- Eigene `config.local.php` mit individuellem Datenverzeichnis anlegen
- Über Admin-Tab **Einstellungen** die eigenen Einsatzzeiten konfigurieren
- Bei Interesse an einer produktiven Instanz oder kommerziellen Vereinbarung direkten Kontakt aufnehmen

---

---

## Setup / Installation

1. PHP 8.1+ mit `ext-calendar`, `ext-json`, `ext-mbstring`.
2. `composer.json` liegt bei; wenn Composer verfügbar ist:
   ```bash
   composer dump-autoload
   ```
   Auf Hosts ohne Composer arbeitet der manuelle PSR-4-Fallback (`src/bootstrap.php`).
3. `public/install.php` einmalig aufrufen (nur wenn `config.local.php` `allow_install` aktiviert) und danach sofort löschen oder prüfen, dass die Datei nicht mehr erreichbar ist.
4. Datenverzeichnis (`data/`) und `storage/logs/` müssen für den Webserver-Prozess schreibbar sein; `.htaccess` und `index.php` schützen das Log-Verzeichnis.

## Tests

```bash
php tests/run_tests.php
```

Der Runner ist dependency-frei und läuft auf jedem Host (auch ohne Composer). Für Syntax-Prüfung:

```bash
php -l src/Auth/AuthManager.php
php -l src/Logging/Logger.php
php -l src/Security/LoginThrottle.php
```

Wenn Composer lokal verfügbar ist: `composer test` und `composer lint`.

## Sicherheit

- **Passwortregeln**: mindestens 12 Zeichen, mindestens ein Großbuchstabe, eine Ziffer und ein Sonderzeichen (`[^\p{L}\p{N}\s]`).
- **Session-Härtung**: `session.use_strict_mode=1`, `cookie_httponly=1`, `cookie_samesite=Lax`, `session_regenerate_id(true)` nach Login.
- **CSP**: `script-src 'self' 'nonce-...'`; alle `<script>`-Tags tragen den per-Response-Nonce (`CSP_NONCE`).
- **Login-Throttling**: dateibasiert (`sys_get_temp_dir()`), `flock()`, probabilistisches `prune()` (1 %), Monitoring (`Logger::warning` bei ≥ 5 Fehlversuchen).
- **IDOR-Schutz**: `dashboard.php` prüft `assignedUserId` gegen angemeldeten Benutzer; `TradeService::requestTrade()` enthält Defense-in-Depth-Check; unbekannte/fremde Block-IDs führen zu HTTP 403.
- **Exception-Handler**: ersetzt `error_log()` durch `Logger::error()`; `display_errors=0`.
- **Log-Schutz**: `storage/logs/.htaccess` (Deny) und `index.php`-Stub verhindern HTTP-Zugang zu Logs.

Siehe `SECURITY.md` für Meldeweg und Gültigkeits-Scope.

## Passwortregeln

Ab Version 1.0.0 (nach Hardening-Paket):

- Mindestlänge: **12 Zeichen**
- Mindestens ein **Großbuchstabe** (`A–Z`, `ÄÖÜ`)
- Mindestens eine **Ziffer** (`0–9`)
- Mindestens ein **Sonderzeichen** (`[^\p{L}\p{N}\s]`)

Fehlermeldungen in `dashboard.php`, `admin.php`, `tab-profile.php` und `tab-users.php` wurden entsprechend angepasst (`minlength="12"`, Label-Texte aktualisiert).

## Logging und Monitoring

Der Logger (`src/Logging/Logger.php`) schreibt nach `storage/logs/app-YYYY-MM-DD.log` (Tagesrotation, `.htaccess`-Schutz). Level-Steuerung:

- `APP_ENV=dev` (oder `development`/`local`) → `debug` (alles ab debug)
- Sonst (`prod`) → `warning` (nur warning und höher)
- Optional überschreibbar über `APP_LOG_LEVEL`

Monitoring (nur Logging, kein externer Dienst):

- Mehr als `SUSPICIOUS_THRESHOLD` (5) fehlgeschlagene Login-Versuche pro Benutzer/IP in 900 Sekunden erzeugen `Logger::warning('Suspicious login pattern', ...)`.

## Demo-Version

Eine öffentliche Demo-Instanz ist erreichbar unter:

```
https://www.dario-jozic.de/psychkg-planer-nrw/
```

**Admin-Zugang für die Demo (zum Testen):**
- Benutzer: `admin`
- Passwort: `Administrator26!`

Hinweis: Die Demo nutzt Beispiel-Daten (`users.example.json`, `schedule.example.json`). Für einen produktiven Einsatz muss `public/install.php` ausgeführt und diese Datei danach manuell gelöscht werden. Die Zugangsdaten gelten nur für die Demo und sollten nach dem Testen nicht weiterverwendet werden.

---

© **Dario Jozic** / PsychKG Bereitschaftsplaner, 2026  
Erstellt als öffentliche Weitergabe für Einsatzgemeinschaften und Verwaltungen nach PsychKG NRW.