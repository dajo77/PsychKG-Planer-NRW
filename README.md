# PsychKG Bereitschaftsplaner

Privates Backup / Arbeits-Repo für `www.dario-jozic.de/psychkg/`.

## Struktur

- `public/` – Web-Einstieg (`index.php`, `login.php`, `dashboard.php`, `admin.php`, `marketplace.php`, Exporte, `install.php`)
- `src/` – PHP-Code (`Auth/`, `Core/`, `Model/`, `Service/` inkl. `AbsenceService` (Urlaub/Krank), `SnapshotService` (versiegelte Nachweise), `BillingService` (Abrechnung + Jahres-Fairness), `Storage/JsonStorage.php`, `bootstrap.php`)
- `templates/nav.php` – Navigation
- Admin-Tabs: Dienstplan, Tauschanträge, Mitarbeiter (+ Abwesenheiten), Abrechnung (+ Jahres-Fairness), Nachweise (Versiegelung), Protokoll, Mein Konto, System (Gesundheitscheck)
- `data/` – JSON-Daten **(Echtdaten, nicht in Git!)**
  - Lokal: `data/*.json` direkt im Projekt (via `.gitignore` ausgeschlossen)
  - **Server: `/var/www/vhosts/dario-jozic.de/private/psychkg-data/*.json` (AUẞERHALB des Document-Roots!)** – umgestellt 15.09.2026, weil nginx `data/.htaccess`/`web.config` ignoriert und die Dateien sonst per URL abrufbar waren. Aktiviert per `psychkg/config.local.php` (nicht in Git, siehe `config.local.example.php`); Fallback ohne die Datei ist `<projekt>/data`.
  - Dateien: `users.json` (inkl. Telefon/E-Mail), `schedule.json`, `trades.json`, `audit.json`, `absences.json` (Urlaub/Krank), `snapshots.json` (versiegelte Monats-Nachweise), `settings.json` (Einsatzzeiten/Bereitschaftswechsels)
  - `*.example.json` → Struktur-Vorlagen in Git
- Genau **ein** Administrator (Policy im Code erzwungen: kein 2. Admin, kein Löschen/Degradieren des Admins).
- **Mehrjährige Planung**: Der Generator (Admin → Dienstplan) hat ein Feld
  **Planungsjahr** (aktuelles Jahr bis +5). Halbjahr und Jahr sind frei
  kombinierbar, z. B. „2027 / 1. Halbjahr". Bestehende Blöcke werden nur im
  gewählten Zeitraum überschrieben – andere Jahre bleiben unberührt.
  `ScheduleService::generateWeeks()` weist Startdaten in der Vergangenheit ab,
  statt sie stillschweigend vorwärtszurollen.
- Dashboard für alle: eigene Tausch-Historie (Marktplatz), Dienstauskunft („Wer hatte am X Dienst?"), Filter in der Dienstplanübersicht, eigenes Passwort änderbar, Telefon des aktuell Diensthabenden im Live-Widget.

## Einsatzzeiten / Bereitschaftswechsels einstellen

Über den Admin-Tab **Einstellungen** lassen sich die Einsatzzeiten der
Einsatzgemeinschaft konfigurieren (Backend: `src/Service/ShiftService.php`,
Persistenz: `<dataDir>/settings.json`, Key `shift`; Default =
NRW-Werte: Bereitschaftswechsels Mittwoch 16:00, Innendienst Mo–Do
08:00–16:00 Uhr, Freitag 08:00–12:00 Uhr).

- **Bereitschaftswechsels**: Wochentag (1=Mo … 7=So) + Uhrzeit (HH:MM) – Beginn
  der Bereitschaft / des Dienstwechsels.
- **Innendienst**: aktiv/deaktiviert, Beginn/Ende (Mo–Do bzw. Freitag),
  Innendienst-Tage (Default: Mo–Fr).
- **Innendienst deaktiviert** → reine Bereitschaftsdienste (24/7).

Die konfigurierten Werte fließen in `ScheduleService::calculateIntervals()`,
`isValidBlockRange()`, `generateWeeks()` und `ScheduleBlock::getFormattedEnd()`
ein (alle Duty-Perioden werden auf Basis des konfigurierten Wechsels erzeugt).

Hinweis: Für andere Gemeinden können ggf. auch eigene Feiertagsregelungen
nachgerüstet werden – das Feiertagsmodell ist aktuell an NRW gebunden.

## Lokal einrichten (frisch)

```powershell
git clone https://github.com/dajo77/psychkg.git
cd psychkg
copy data\users.example.json data\users.json
copy data\schedule.example.json data\schedule.json
copy data\trades.example.json data\trades.json
copy data\audit.example.json data\audit.json
# Oder: public/install.php im Browser aufrufen – erzeugt Demo-Daten mit Zufallspasswörtern
```

`install.php` nach erfolgreicher Installation vom Server löschen!

## Alltag

```powershell
git status
git add -A
git commit -m "Beschreibung"
git push
```

## Tests

Ohne Composer-Abhängigkeit, direkt mit PHP lauffähig:

```powershell
php tests\lint.php          # Syntaxprüfung aller PHP-Dateien
php tests\run_tests.php     # Geschäftslogik (Dienstplan, Stunden, Feiertage, Escaping)
php tests\year_planning_test.php  # Mehrjährige Planung (kommendes Jahr, clearRange, Grenzen)
```

Gegen eine laufende Instanz (erfordert Zugangsdaten):

```powershell
cd public; php -S 127.0.0.1:8899      # in einem Terminal
php tests\smoke.php                     # öffentliche Seiten, Header, Auth-Walls
php tests\smoke_auth.php http://127.0.0.1:8899 admin <passwort>   # angemeldet
php tests\smoke_year_select.php http://127.0.0.1:8899 admin <pw>  # Jahresauswahl
```

`tests\seed_dev.php` legt lokale Testdaten mit Zufallspasswörtern an und
verweigert den Zugriff auf ein Verzeichnis, das nach Produktivdaten aussieht.

Abgedeckt sind insbesondere die Regeln, die bei einer Änderung leicht
stillschweigend brechen: Mittwoch-16:00-Fenster inkl. Sommerzeit-Übergang,
Netto-Bereitschaftsstunden, die elf NRW-Feiertage, das Escaping im
JavaScript-Kontext und der Nachweis-Hash.

## Deploy auf Server (dario-jozic.de / psychkg)

Standard-Weg (eingerichtet 15.09.2026, wie `immo3`/`anwaltssoftware`):

- Bare-Mirror auf Server: `/var/www/vhosts/dario-jozic.de/git/psychkg.git` (Mirror von `github.com/dajo77/psychkg`, Owner `dario-jozic.de`)
- Work-Tree: `/var/www/vhosts/dario-jozic.de/httpdocs/psychkg/` (Owner `dario-jozic.de:psacln`)
- Deploy-Script: `~/bin/deploy-psychkg.sh` – 3-facher Schutz der Planungsdaten:
  1. **GUARD**: bricht ab, falls das Git-Archiv je `data/users|schedule|audit|trades.json` enthalten sollte
  2. **Backup**: `~/private/psychkg-data/*.json` (ohne Examples) nach `/tmp/psychkg-data-backup-*` (700)
  3. **Verify**: SHA256-Vergleich vor/nach Deploy, bei Abweichung automatischer Restore + Abbruch
- Backup-Script: `~/bin/backup-psychkg.sh` – täglicher Snapshot nach `~/private/backups/psychkg/` (Rotation: 14 Stück, 600). Läuft als Plesk-Task (ID 310, täglich 03:00, aktiv seit 18.09.2026). `/tmp`-Deploy-Backups sind flüchtig und kein Ersatz.

```bash
~/bin/deploy-psychkg.sh
```

Ablauf für Code-Änderungen:
1. Lokal ändern, testen (`copy data\*.example.json data\*.json` für frischen Stand oder `public/install.php`)
2. `git add -A; git commit -m "..."; git push`
3. Auf Server (als `dario-jozic.de` via Plesk-Tool/SSH): `~/bin/deploy-psychkg.sh`

Niemals `data/*.json` vom lokalen Dev-Stand auf den Server kopieren (würde echte Dienstpläne in `~/private/psychkg-data/` überschreiben)! Nur Code deployen:
- `public/`, `src/`, `templates/`, `data/.htaccess`, `data/web.config`, `data/*.example.json`, `README.md`, `.gitignore`

## Sicherheit

- Erster Commit `c102bff` enthielt noch Echtdaten (Backup). Ab jetzt sind `data/*.json` ignoriert.
- Doppelter lokaler Schutz: `.gitignore` (blockt `git add`) + `hooks/pre-commit` (blockt `git commit`, auch mit `-f`). Nach Klonen aktivieren: `copy hooks\pre-commit .git\hooks\pre-commit`
- Falls Historie bereinigt werden soll (empfohlen): History-Rewrite oder Repo neu aufsetzen – vorher Bescheid geben.
- Passwörter nach dem ersten Push rotieren.

### Hardening (Stand 18.10.2026)

- **Header**: `X-Frame-Options: DENY` (Clickjacking), `X-Content-Type-Options`,
  `Referrer-Policy`, `Permissions-Policy` – gesetzt in `src/bootstrap.php` für alle Seiten.
- **CSP**: `Content-Security-Policy-Report-Only` inkl. `frame-ancestors 'none'`.
  Bewusst *report-only*, weil die Seiten noch Inline-Scripts und `onclick`
  verwenden. Ein erzwingendes Policy blockiert die App, bis diese Handler
  entfernt sind. Der Header ist die Checkliste dafür.
- **Session-Cookie**: `Secure` leitet sich aus `Config::ASSUME_HTTPS` ab, nicht
  aus `X-Forwarded-Proto` – der Header ist client-setzbar. Für lokale
  HTTP-Entwicklung die Konstante auf `false` stellen.
- **Logout** ist POST mit CSRF-Token (vorher GET, per `<img>` auslösbar).
- **install.php** löscht sich nach erfolgreicher Installation selbst und prüft
  ein CSRF-Token. Trotzdem nach dem ersten Deploy manuell entfernen.
- **Webfonts lokal** unter `public/assets/fonts/` – keine Anfrage mehr an
  Google (DSGVO + render-blocking Request).
- **Eingabevalidierung**: `Src\Core\Html` (`q()`, `qi()`, `qDate()`, `qTime()`,
  `token()`, `js()`). Array-Parameter (`?x[]=1`) und unparsebare Datumswerte
  führten vorher zu `TypeError`/HTTP 500.
- **JS-Escaping**: Mitarbeiternamen in `onclick`-Attributen laufen durch
  `Html::js()`. `htmlspecialchars()` erzeugt HTML-Entities, die der Browser
  *vor* der JS-Auswertung dekodiert – ein Name wie `x'); alert(1); //` durchbrach
  so das String-Literal.

### Noch offen

- Git-Historie enthält in `c102bff` echte Namen + bcrypt-Hashes. Repository ist
  privat, die Daten bleiben aber im Verlauf. Passwörter rotieren, Historie
  bereinigen wenn möglich.
- `authorizeTrade`/`rejectTrade` prüfen die Admin-Rolle jetzt in der
  Service-Schicht; die übrigen `AuthManager`-Schreibmethoden vertrauen weiterhin
  darauf, dass nur `admin.php` sie aufruft.
- Login-Rate-Limiting nur pro IP in `sys_get_temp_dir()`; hinter NAT oder Proxy
  keine Wirkung. Konto-basiertes Limit wäre die belastbare Lösung.
- `admin.php` ist ein Monolith (~1.650 Zeilen, alle Tabs werden immer gerendert).
  Aufteilung in Partials mit Lazy-Rendering ist der nächste strukturelle Schritt.
