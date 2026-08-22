# Deployment

## Einrichten

1. `deploy.config.example.json` kopieren nach **`deploy.config.json`**
2. Zugangsdaten eintragen — für `dev` und `live` getrennt

```json
"dev": {
  "host": "wXXXXXX.kasserver.com",
  "port": 21,
  "username": "ftp-benutzer",
  "password": "...",
  "ftps": true,
  "remoteRoot": "/dev.example.org",
  "remoteDbFile": "data/gym141-dev.sqlite"
}
```

`deploy.config.json` ist in `.gitignore` und in den Ausschlussregeln des Uploads —
die Datei landet niemals auf dem Server und niemals in einem Repository.

> **FTPS lassen.** Bei `"ftps": false` gehen Benutzername und Passwort im Klartext
> durchs Netz. Hetzner Managed Server kann FTPS (explizites TLS).

## Verwenden

```powershell
cd D:\__Cloud\Projekte\Claude\atus-weiz\deploy

.\deploy.ps1 -Target live -Action test              # Verbindung prüfen
.\deploy.ps1 -Target live -Action upload -DryRun -Confirm   # zeigt nur, was ginge
.\deploy.ps1 -Target live -Action upload -Confirm   # hochladen (-Confirm ist live Pflicht)
.\deploy.ps1 -Target live -Action download-db       # Datenbank holen
.\deploy.ps1 -Target live -Action delete-setup      # Web-Installer vom Server entfernen
```

Nur einzelne Dateien übertragen – spart bei kleinen Änderungen den kompletten
Durchlauf über alle 136 Dateien:

```powershell
.\deploy.ps1 -Target live -Action upload -Only 'Views/layouts' -Confirm
.\deploy.ps1 -Target live -Action upload -Only '\.css$' -Confirm
```

> **Nach jedem vollständigen Upload:** `public/setup.php` wird dabei wieder
> mit hochgeladen. Die Datei sperrt sich zwar selbst, sobald eine Datenbank
> existiert – trotzdem danach `-Action delete-setup` ausführen.

## Was übertragen wird

**Ja:** `app/`, `bin/`, `public/`, `data/schema.sql`, `data/seed/`, `docs/`,
`.htaccess`-Dateien, die Sektionsbilder unter `public/uploads/`.

**Nie:** `app/config.php`, `data/*.sqlite`, Sicherungen, `deploy/`,
`public/router.php` (nur für den lokalen Entwicklungsserver), `.git`.

Das heißt: Ein Upload aktualisiert ausschließlich den Programmcode.
Datenbank, serverseitige Konfiguration und später hochgeladene Bilder bleiben unberührt.

## Erste Installation auf dem Server

1. `-Action upload` ausführen
2. Im Browser `https://dev.example.org/setup.php` aufrufen
3. Superuser anlegen
4. **`public/setup.php` danach vom Server löschen** (oder `setup_key` in `app/config.php` leer lassen)

## Ablauf im Alltag

```
lokal entwickeln  →  upload nach dev  →  auf dev.example.org prüfen  →  upload nach live
```

Zum Nachstellen eines Fehlers mit echten Daten:

```powershell
.\deploy.ps1 -Target live -Action download-db
copy ..\data\download\live-<stempel>.sqlite ..\data\gym141-dev.sqlite
```

Die heruntergeladene Datei enthält personenbezogene Mitgliederdaten —
bitte lokal nicht länger aufheben als nötig und nicht weitergeben.
