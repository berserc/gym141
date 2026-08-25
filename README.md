# Gym141

Vereins- und Gym-Verwaltung für Shared Webspace – Website, Mitglieder, Beiträge
und Buchhaltung in **einer** kleinen PHP-Anwendung. Kein Composer, kein MySQL,
kein Build-Schritt: Dateien hochladen, `setup.php` aufrufen, fertig.

Gedacht für Sportvereine, Kampfsport-Gyms und Trainingsgruppen, die keine
eigene IT haben – alles wird nach der Installation im Browser verwaltet.

## Funktionen

**Öffentliche Website**
- Startseite mit Trainingsgruppen-Kacheln und farbigem Wochenplan
  (klickbare Kacheln mit Symbolen, im Backend gepflegt)
- **Inhaltsblöcke** („Paragraphs“): Startseite und Seiten aus konfigurierbaren
  Blöcken zusammensetzen – Text, Hero (Bild/Video), Bild, Galerie mit Lightbox,
  Video (eigenes oder YouTube, erst nach Klick geladen), Wochenplan,
  Trainingsgruppen, CTA/WhatsApp-Button; sortier-, duplizier- und ausblendbar
- Eine Seite je Trainingsgruppe: Texte (Richtext-Editor), Bilder, Trainer-Kontakte,
  Trainingszeiten (automatisch aus dem Wochenplan)
- Frei anlegbare Seiten (Impressum, Datenschutz, …)
- Optionaler WhatsApp-Button („Probetraining vereinbaren“) auf jeder Seite
- Sitemap, robots.txt, responsive, dunkles Design (per CSS-Variablen anpassbar)

**Verwaltung (`/admin`)**
- **Mitglieder**: Stammdaten, mehrere Gruppen je Mitglied, Profilbild und
  Dokumente (mit Ablauf-Erinnerung, z. B. ärztliche Untersuchung), Archiv
  („Ehemalige“), Papierkorb, Duplikat-Erkennung, CSV/XLSX-Export,
  CSV-Import mit Spalten-Zuordnung
- **Beiträge**: Beitragsarten mit Intervall (monatlich/quartalsweise/halbjährlich/
  jährlich), automatische Vorschreibung, rückwirkende Beitragsänderungen,
  Aussetzungen, offene-Posten-Liste, Erinnerungs-E-Mail (Cron oder Knopf)
- **Buchhaltung**: Kassabuch mit Kategorien und Zahlungsarten, Fixkosten mit
  Intervall, Rechnungen an Mitglieder, Einnahmen/Ausgaben-Auswertung mit Prognose
- **Training**: Anwesenheit, Gewichtsverlauf, Leistungsbewertung,
  Leistungstests (Standard + frei definierbar) mit Diagrammen
- **Erfolge**: Kämpfe/Wettkämpfe je Mitglied mit Links, Fotos und Videos
- **Termine**: Kalender mit wiederkehrenden Terminen, Zu-/Absagen der Mitglieder
  (wie eine WhatsApp-Umfrage), Event-Organisation (Aufgaben + To-do-Listen),
  ICS-Export und Kalender-Abo, REST-API
- **Wochenplan**: Einheiten mit Tag, Zeit, Farbe, Symbol und Gruppen-Zuordnung
- **Dateien**: zentrale Dateiablage mit Ordnern (einmal hochladen, überall verwenden)
- **Benutzer & Rollen**: Superuser, Sektionsleiter, Kassier, Trainer – plus
  Mitglieder-Logins für den eigenen Bereich (`/mitglied`)
- **KI-Formularerkennung** (optional): Foto/PDF eines handschriftlich
  ausgefüllten Beitrittsformulars hochladen, Felder werden per Claude API
  erkannt und im Formular vorausgefüllt (eigener API-Schlüssel nötig)

**Mitgliederbereich (`/mitglied`)**
- Eigene Daten, offene Beiträge, Termine mit Zu-/Absage, eigene Entwicklung

## Anforderungen

- PHP 8.1 oder neuer mit `pdo_sqlite`, `sqlite3`, `mbstring`, `gd`
  (Standard bei praktisch jedem Shared Hoster)
- Optional: `fileinfo`, `zip`, `curl` (für Uploads-Erkennung, XLSX-Export,
  KI-Formularerkennung)
- Kein MySQL nötig – die Daten liegen in einer SQLite-Datei unter `data/`

## Installation auf Shared Webspace

1. Alle Dateien per FTP/SFTP hochladen.
2. Den Docroot der Domain auf den Ordner **`public/`** zeigen lassen.
   Geht das beim Hoster nicht, funktioniert auch eine Installation in einem
   Unterordner – dann in `app/config.php` den `base_path` setzen.
3. Im Browser **`https://deine-domain.tld/setup.php`** aufrufen und dem
   Assistenten folgen (Admin-Zugang anlegen).
4. Anmelden unter `/admin`, dann unter **Einstellungen** Vereinsname und
   Kontaktdaten eintragen, unter **Sektionen** die Trainingsgruppen und unter
   **Wochenplan** die Trainingszeiten anlegen.
5. `setup.php` löschen oder in `app/config.php` den `setup_key` leer lassen
   (dann ist der Installer automatisch gesperrt).

Ein eigenes Logo? Einfach als `public/assets/img/logo.png` (oder `.svg`/`.jpg`)
hochladen – es erscheint automatisch in Kopfzeile und Login.

### Installation per Kommandozeile (alternativ)

```bash
php bin/install.php --admin=admin
```

## Updates

Neue Version hochladen (die Ordner `data/` und `public/uploads/` **nicht**
überschreiben) und einmal `setup.php?key=…` aufrufen – Datenbank-Migrationen
laufen automatisch und sind ungefährlich für bestehende Daten.

## Sicherung

`php bin/backup.php --keep=30` sichert die Datenbank (per `VACUUM INTO`) und
spiegelt alle Datei-Ablagen nach `data/backups/` – ideal als täglicher Cronjob.
Ebenfalls Cron-tauglich: `php bin/beitrags-erinnerung.php` (offene Beiträge
per E-Mail).

## Entwicklung

```bash
GYM141_ENV=dev php bin/install.php --admin=admin   # Test-Datenbank anlegen
php -S localhost:8080 -t public public/router.php  # Dev-Server starten
php bin/seed-demo.php                              # optionale Demo-Daten
```

Test- und Produktivbetrieb nutzen getrennte Datenbanken
(`data/gym141-dev.sqlite` / `data/gym141.sqlite`); auf `dev.`-Subdomains wird
automatisch die Testdatenbank verwendet, samt Warnbanner.

Für das Deployment per FTPS liegt unter `deploy/` ein PowerShell-Skript
(`deploy.ps1`) samt Anleitung.

## Technik

- PHP 8.1+, SQLite (WAL), keine externen Abhängigkeiten
- Eigener kleiner Front-Controller/Router (`public/index.php`)
- Struktur: `app/Controllers`, `app/Models`, `app/Views`, `app/Core`
- Richtext über mitgeliefertes TinyMCE (`public/assets/vendor/`)

## Lizenz

MIT – siehe [LICENSE](LICENSE).
