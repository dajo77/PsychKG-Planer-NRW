# PsychKG Bereitschaftsplaner

**Bereitschaftsplanung, Tauschmanagement und Abrechnung für Einsatzgemeinschaften.**

Entwickelt und gepflegt von **Dario Jozic** – E-Mail: **dario@jozic.de**

---

## Was ist diese Software?

Der PsychKG Bereitschaftsplaner ist eine webbasierte Anwendung zur Planung von Bereitschaftsdiensten für gemeinnützige Einsatzgemeinschaften (z. B. Feuerwehr, Rettung, Katastrophenschutz, Pflegebereitschaft). Die App ermöglicht es einer Gemeinschaft, Dienstpläne über mehrere Jahre zu erstellen, zu verwalten und zu dokumentieren – ohne externe Dienste oder Cloud-Abhängigkeit.

Die Anwendung läuft als reine PHP-Anwendung (PHP 8.x) mit lokalen JSON-Daten und benötigt **kein** Composer oder Datenbank-Server.

---

## Was kann die App?

### Kernfunktionen

- **Dienstplan-Generator** (Admin): Automatische Erstellung von Bereitschaftswochen, Mehrjahresplanung (aktuelles Jahr bis +5), Halbjahres-Auswahl.
- **Konfigurierbare Einsatzzeiten** (Admin → Tab *Einstellungen*):
  - Bereitschaftswechsels (Wochentag + Uhrzeit)
  - Innendienst (aktiv/deaktiviert, Beginn/Ende, Freitag-Ende, Innendienst-Tage)
  - 24/7-Modus möglich (Innendienst deaktiviert)
- **Dashboard** (für alle Mitarbeiter): Übersicht der eigenen Dienste, Tausch-Historie, Filter, Live-Widget mit Telefonnummer des aktuellen Diensthabenden.
- **Tauschmanagement (Marktplatz)**: Tauschangebote erstellen, genehmigen/ablehnen, Historie.
- **Abwesenheiten**: Urlaub / Krankheit pro Mitarbeiter, Planungsberücksichtigung.
- **Abrechnung / Stunden**: Netto-Bereitschaftsstunden, Jahres-Fairness, Export als PDF / CSV.
- **Nachweise / Versiegelung**: Monats- oder Halbjahres-Nachweise mit Hash-Verifikation (Unveränderbarkeit nachgewiesen).
- **Sicherheits-Features**: CSRF-Schutz, Rate-Limit, lokale Webfonts (DSGVO), CSP-Header, Session-Cookie-Sicherheitsflags.

---

## Inhalt dieses Repositories

Dieses Repository enthält **nur Demo- und Beispiel-Daten** sowie den vollständigen App-Code zur Weitergabe.

- **PHP-Code:** `public/`, `src/`, `templates/`, `tests/`
- **Demo-Daten:** `data/*.example.json` (Platzhalter für Benutzer, Dienstpläne, Abwesenheiten, Tausche, Nachweise, Protokoll)
- **Installation:** `public/install.php` erzeugt bei erster Nutzung Demo-Benutzer (1 Admin + 3 Mitarbeiter) mit Zufallspasswörtern

> ⚠️ **Hinweis:** Die enthaltenen Daten (`users.example.json` etc.) sind ausschließlich **fiktive Beispiele** mit Platzhalter-Namen und Beispiel-E-Mail-Adressen (z. B. `m.muster@beispiel.de`). Es sind **keine** realen Personen oder Einsatzgemeinschaften enthalten.

---

## Schnellstart (lokale Einrichtung)

```powershell
git clone https://github.com/dajo77/PsychKG-Planer-NRW.git
cd PsychKG-Planer-NRW
# Beispiel-Daten als echte Dateien kopieren (nur für lokale Tests)
copy data\users.example.json data\users.json
copy data\schedule.example.json data\schedule.json
copy data\trades.example.json data\trades.json
copy data\audit.example.json data\audit.json
# Oder einfach über den Browser: public/install.php aufrufen
```

Online-Installation über `public/install.php` (nur mit `allow_install = true` in `config.local.php`). Das Script löscht sich nach erfolgreicher Installation automatisch (`@unlink`).

---

## Systemvoraussetzungen

- PHP 8.4 (oder höher, mindestens 8.1)
- Webserver mit PHP-Unterstützung (nginx / Apache)
- Keine Datenbank erforderlich (JSON-Dateien als Speicher)
- Keine Composer-Abhängigkeiten (reines PHP, autoload via `bootstrap.php`)
- Schriftarten lokal (keine externen CDN-Anfragen)

---

## Einsatzzeiten – Konfigurierbar

Die App ist nicht auf ein einzelnes Bundesland festgelegt – nur die **Default-Einstellungen** folgen dem NRW-Modell:

- Bereitschaftswechsels: **Mittwoch 16:00 Uhr**
- Innendienst: **Mo–Do 08:00–16:00 Uhr**, **Fr 08:00–12:00 Uhr**

Über den Admin-Tab **Einstellungen** können Gemeinden eigene Zeiten einstellen (z. B. Montag 22:00 Uhr, Fr 08:00–14:00 Uhr). Innendienst kann auch komplett deaktiviert werden (24/7-Bereitschaft).

> Hinweis: Das Feiertagsmodell ist aktuell an NRW gebunden. Für andere Regionen müssten ggf. eigene Feiertagsregelungen ergänzt werden.

---

## Sicherheit & Datenschutz

- **Daten bleiben lokal** – JSON-Dateien außerhalb des Document-Roots empfohlen (`config.local.php` mit `data_dir`)
- **Install-Gate** – `public/install.php` ist standardmäßig inaktiv (`allow_install = false`) und löschbar
- **CSRF** – alle POST-Aktionen mit Token (`Csrf::field()` / `requireValid()`)
- **Session** – `Secure`, `HttpOnly`, `SameSite` (via `Config::ASSUME_HTTPS` steuerbar)
- **CSP** – `Content-Security-Policy-Report-Only` mit `frame-ancestors 'none'`
- **Kein CDN** – alle Fonts, CSS und JS lokal (keine Google-Anfragen, DSGVO-konform)

---

## Tests

```powershell
php tests\lint.php          # Syntaxprüfung aller PHP-Dateien
php tests\run_tests.php     # Geschäftslogik-Tests (96/96 bestanden)
php tests\year_planning_test.php
```

---

## Kontakt & Weitergabe

Für Fragen zur Weitergabe der App oder zur Einrichtung für eine neue Einsatzgemeinschaft:

**Dario Jozic**  
E-Mail: **dario@jozic.de**

Dieses Repository ist als **öffentliche Weitergabe-Version** gedacht. Bei Interesse an einer eigenen Instanz: Clone → `install.php` → eigene `data_dir`-Konfiguration → Einsatzzeiten im Admin-Panel einstellen.

---

© Dario Jozic / PsychKG Bereitschaftsplaner, 2026  
Erstellt als Backup- und Weitergabe-Repo für Einsatzgemeinschaften.
