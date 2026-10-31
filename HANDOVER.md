# Handover – Bereitschaftsplaner „PsychKG"

> Vorlage für die Übergabe der funktionsfähigen App an eine weitere Einsatzgemeinschaft.
> Enthält KEINE persönlichen Daten, Einsatzzeiten oder Abrechnungsdaten – nur die Übergabe-Anleitung.

## Was übergeben wird

Eine funktionierende **Bereitschaftsplanungs-App** für die Einsatzgemeinschaft, inklusive:

- Bereitschaftsplanung (Dienstplan, automatisches Zuweisen, Jahresplanung)
- Tauschmanagement (Marktplatz, Genehmigungen)
- Abrechnung / Stunden-Auswertung
- Einsatz-Nachweise (Monatsversiegelung)

## Wichtiger Hinweis zu den Daten

> ⚠️ **Es werden ausschließlich Demo-/Beispieldaten angelegt.**
> Echte Mitarbeiterdaten und eigene Einsatz-/Abrechnungszeiten werden **nicht** übergeben oder übernommen.
> Die App ist für eine Einsatzgemeinschaft gedacht, die ihre eigenen Strukturen und Zeiten hinterlegen möchte.

## Schritt-für-Schritt-Einrichtung (auf der Zielinstanz)

1. **Code deployen** – nur die App-Dateien (`public/`, `src/`, `templates/`, `data/.htaccess`, `data/web.config`, `data/*.example.json`, `README.md`, `.gitignore`).
2. **`config.local.php` anlegen** mit eigener Datenpfad:
   ```php
   return [
       'data_dir' => '/var/www/vhosts/<ziel-domain>/private/psychkg-data',
       'allow_install' => true, // nur für die Installationsrunde
   ];
   ```
3. **`public/install.php` aufrufen** → legt Demo-Daten an (1 Admin + 3 Mitarbeiter, Zufallspasswörter).
4. Nach erfolgreicher Installation: **`install.php` löschen** und `allow_install` wieder auf `false`.

## Zugang

- **URL:** <ziel-url-eintragen>
- **Login:** `admin` / [Zufallspasswort aus der Installationsausgabe – notieren & weitergeben]

## Einsatzzeiten / Dienstzeiten

Die Einsatzzeiten (Bereitschaftswechsels, Innendienst) werden **nicht** übernommen, sondern vom jeweiligen Admin **selbst** eingegeben – über den Admin-Tab **„Einstellungen"**.

- **Standard (NRW):**
  - Bereitschaftswechsels: **Mittwoch, 16:00 Uhr**
  - Innendienst: **Mo–Do 08:00–16:00 Uhr**, **Freitag 08:00–12:00 Uhr**
- Alternativ: Innendienst **deaktivieren** → reine Bereitschaft (24/7).

## Support / Kontaktaufnahme

- GitHub-Repo: `https://github.com/dajo77/psychkg`
- Server-Umgebung: Debian + Plesk, PHP 8.x, lokale Tailwind/CSS, lokale Webfonts (DSGVO-konform).

## Hinweis

Das **Feiertagsmodell ist aktuell auf NRW bezogen** – für andere Regionen müssten ggf. eigene Feiertage ergänzt werden.

---
