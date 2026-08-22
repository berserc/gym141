<#
.SYNOPSIS
    Deployment der ATUS-Weiz-Anwendung per FTP/FTPS.

.DESCRIPTION
    Liest die Zugangsdaten aus deploy.config.json (nicht versioniert) und
    überträgt den Anwendungscode auf den Zielserver. Nutzerdaten auf dem
    Server – Datenbank, Konfiguration, hochgeladene Bilder – werden dabei
    grundsätzlich nicht angerührt.

    Benötigt nur Windows PowerShell, keine Zusatzsoftware.

.PARAMETER Target
    dev (Standard) oder live.

.PARAMETER Action
    test         Verbindung prüfen und Remote-Verzeichnis auflisten
    upload       Anwendungscode hochladen
    download-db  SQLite-Datenbank vom Server holen (nach data/download/)
    upload-db    Lokale Datenbank auf den Server schieben (VORSICHT)
    list         Ein Remote-Verzeichnis auflisten

.EXAMPLE
    .\deploy.ps1 -Target dev -Action test
    .\deploy.ps1 -Target dev -Action upload
    .\deploy.ps1 -Target live -Action upload -Confirm
    .\deploy.ps1 -Target live -Action download-db
#>

[CmdletBinding()]
param(
    [ValidateSet('dev', 'live')]
    [string] $Target = 'dev',

    [ValidateSet('test', 'upload', 'download-db', 'upload-db', 'list', 'delete-setup')]
    [string] $Action = 'test',

    [string] $Path = '',

    # Nur Dateien uebertragen, deren Pfad zu diesem Muster passt.
    # Beispiel: -Only 'Views/layouts'  oder  -Only '\.css$'
    [string] $Only = '',

    # Sicherheitsabfrage für Aktionen auf dem Produktivsystem übergehen
    [switch] $Confirm,

    # Zeigt nur, was passieren würde
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

# ---------------------------------------------------------------- Konfiguration

$configFile = Join-Path $PSScriptRoot 'deploy.config.json'

if (-not (Test-Path $configFile)) {
    Write-Host "Es fehlt die Datei deploy\deploy.config.json." -ForegroundColor Red
    Write-Host "Bitte deploy.config.example.json kopieren, umbenennen und ausfuellen."
    exit 1
}

$config = Get-Content $configFile -Raw -Encoding UTF8 | ConvertFrom-Json
$cfg = $config.targets.$Target

if (-not $cfg -or [string]::IsNullOrWhiteSpace($cfg.host)) {
    Write-Host "Fuer das Ziel '$Target' sind keine Zugangsdaten hinterlegt." -ForegroundColor Red
    exit 1
}

$scheme = "ftp://$($cfg.host):$($cfg.port)"
$remoteRoot = '/' + $cfg.remoteRoot.Trim('/')
if ($remoteRoot -eq '/') { $remoteRoot = '' }

$credential = New-Object System.Net.NetworkCredential($cfg.username, $cfg.password)

# ------------------------------------------------------------------- FTP-Basis

function New-FtpRequest {
    param([string] $RemotePath, [string] $Method)

    # Nur den Pfadteil normalisieren - das Schema "ftp://" darf dabei
    # unter keinen Umstaenden angetastet werden.
    $path = $remoteRoot + '/' + $RemotePath.TrimStart('/')
    $path = $path -replace '/{2,}', '/'
    if (-not $path.StartsWith('/')) { $path = '/' + $path }

    $uri = $scheme + $path

    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Credentials = $credential
    $req.Method = $Method
    $req.EnableSsl = [bool] $cfg.ftps
    $req.UsePassive = [bool] $cfg.passive
    $req.UseBinary = $true
    $req.KeepAlive = $false
    $req.Timeout = 60000
    $req.ReadWriteTimeout = 120000
    return $req
}

function Invoke-FtpText {
    param([string] $RemotePath, [string] $Method)

    $req = New-FtpRequest -RemotePath $RemotePath -Method $Method
    $res = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($res.GetResponseStream())
    $text = $reader.ReadToEnd()
    $reader.Close(); $res.Close()
    return $text
}

function Test-FtpDirectory {
    param([string] $RemotePath)

    try {
        Invoke-FtpText -RemotePath $RemotePath -Method ([System.Net.WebRequestMethods+Ftp]::ListDirectory) | Out-Null
        return $true
    } catch {
        return $false
    }
}

$script:createdDirs = @{}

function Confirm-FtpDirectory {
    param([string] $RemotePath)

    $RemotePath = $RemotePath.Trim('/')
    if ($RemotePath -eq '' -or $script:createdDirs.ContainsKey($RemotePath)) { return }

    # Uebergeordnete Verzeichnisse zuerst
    $parent = Split-Path $RemotePath -Parent
    if ($parent) { Confirm-FtpDirectory -RemotePath ($parent -replace '\\', '/') }

    try {
        $req = New-FtpRequest -RemotePath $RemotePath -Method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
        $res = $req.GetResponse(); $res.Close()
    } catch {
        # 550 = existiert bereits, das ist der Normalfall
    }

    $script:createdDirs[$RemotePath] = $true
}

function Send-FtpFile {
    param([string] $LocalFile, [string] $RemotePath)

    $dir = ($RemotePath -replace '[^/]+$', '').Trim('/')
    if ($dir) { Confirm-FtpDirectory -RemotePath $dir }

    $req = New-FtpRequest -RemotePath $RemotePath -Method ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [System.IO.File]::ReadAllBytes($LocalFile)
    $req.ContentLength = $bytes.Length

    $stream = $req.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()

    $res = $req.GetResponse(); $res.Close()
    return $bytes.Length
}

function Remove-FtpFile {
    param([string] $RemotePath)

    $req = New-FtpRequest -RemotePath $RemotePath -Method ([System.Net.WebRequestMethods+Ftp]::DeleteFile)
    $res = $req.GetResponse()
    $res.Close()
}

function Receive-FtpFile {
    param([string] $RemotePath, [string] $LocalFile)

    $req = New-FtpRequest -RemotePath $RemotePath -Method ([System.Net.WebRequestMethods+Ftp]::DownloadFile)
    $res = $req.GetResponse()
    $in = $res.GetResponseStream()

    $dir = Split-Path $LocalFile -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }

    $out = [System.IO.File]::Create($LocalFile)
    $in.CopyTo($out)
    $out.Close(); $in.Close(); $res.Close()

    return (Get-Item $LocalFile).Length
}

# ------------------------------------------------------- Welche Dateien hoch?

# Verzeichnisse, deren Inhalt uebertragen wird
$includeDirs = @('app', 'bin', 'public', 'data/seed', 'docs')

# Einzeldateien im Projektstamm
$includeFiles = @('.htaccess', 'README.md', 'data/.htaccess', 'data/schema.sql')

# Niemals uebertragen: Nutzerdaten, Zugangsdaten, lokale Artefakte
$excludePatterns = @(
    '\\app\\config\.php$',
    '\\data\\.*\.sqlite',
    '\\data\\.*\.sqlite-(wal|shm)$',
    '\\data\\backups\\',
    '\\data\\download\\',
    '\\data\\tmp\\',
    '\\deploy\\',
    '\\\.git',
    '\\\.claude\\',
    '\\public\\router\.php$',
    '\.bak$',
    '\.log$',
    'Thumbs\.db$',
    '\.DS_Store$'
)

function Get-FilesToUpload {
    $files = New-Object System.Collections.Generic.List[object]

    foreach ($d in $includeDirs) {
        $full = Join-Path $root ($d -replace '/', '\')
        if (-not (Test-Path $full)) { continue }
        Get-ChildItem $full -Recurse -File -Force | ForEach-Object { $files.Add($_) }
    }

    foreach ($f in $includeFiles) {
        $full = Join-Path $root ($f -replace '/', '\')
        if (Test-Path $full) { $files.Add((Get-Item $full -Force)) }
    }

    # Bilder der Sektionen gehoeren dazu, spaeter hochgeladene bleiben unberuehrt
    $uploads = Join-Path $root 'public\uploads'
    if (Test-Path $uploads) {
        Get-ChildItem $uploads -Recurse -File -Force | ForEach-Object { $files.Add($_) }
    }

    $result = @()
    foreach ($f in ($files | Sort-Object FullName -Unique)) {
        $skip = $false
        foreach ($p in $excludePatterns) {
            if ($f.FullName -match $p) { $skip = $true; break }
        }

        # Optionale Einschraenkung auf einzelne Dateien
        if (-not $skip -and $Only -ne '') {
            $relativ = ($f.FullName.Substring($root.Length).TrimStart('\') -replace '\\', '/')
            if ($relativ -notmatch $Only) { $skip = $true }
        }

        if (-not $skip) { $result += $f }
    }

    return $result
}

function Get-RemotePath {
    param([System.IO.FileInfo] $File)
    return ($File.FullName.Substring($root.Length).TrimStart('\') -replace '\\', '/')
}

# ------------------------------------------------------------------- Aktionen

Write-Host ""
Write-Host "Gym141 - Deployment" -ForegroundColor Cyan
Write-Host "Ziel:      $Target  ($($cfg.url))"
Write-Host "Server:    $($cfg.host)  Benutzer: $($cfg.username)  FTPS: $(if ($cfg.ftps) {'ja'} else {'NEIN - unverschluesselt!'})"
Write-Host "Remote:    $(if ($remoteRoot -eq '') {'/'} else {$remoteRoot})"
Write-Host ""

if ($Target -eq 'live' -and $Action -in @('upload', 'upload-db') -and -not $Confirm) {
    Write-Host "Das ist das PRODUKTIVSYSTEM. Zum Ausfuehren bitte -Confirm anhaengen:" -ForegroundColor Yellow
    Write-Host "  .\deploy.ps1 -Target live -Action $Action -Confirm"
    exit 2
}

switch ($Action) {

    'test' {
        try {
            $listing = Invoke-FtpText -RemotePath '' -Method ([System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails)
            Write-Host "Verbindung erfolgreich. Inhalt von $remoteRoot :" -ForegroundColor Green
            $listing -split "`r?`n" | Where-Object { $_ } | ForEach-Object { "   $_" }
        } catch {
            Write-Host "Verbindung fehlgeschlagen: $($_.Exception.Message)" -ForegroundColor Red
            exit 1
        }
    }

    'list' {
        $p = if ($Path) { $Path } else { '' }
        try {
            (Invoke-FtpText -RemotePath $p -Method ([System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails)) -split "`r?`n" |
                Where-Object { $_ } | ForEach-Object { "   $_" }
        } catch {
            Write-Host "Nicht lesbar: $($_.Exception.Message)" -ForegroundColor Red
            exit 1
        }
    }

    'upload' {
        $files = Get-FilesToUpload
        $totalBytes = ($files | Measure-Object Length -Sum).Sum

        Write-Host ("{0} Dateien, {1:N1} MB" -f $files.Count, ($totalBytes / 1MB))
        Write-Host ""

        if ($DryRun) {
            $files | ForEach-Object { "   " + (Get-RemotePath $_) }
            Write-Host "`n(Probelauf - nichts uebertragen)" -ForegroundColor Yellow
            break
        }

        $i = 0; $sent = 0; $failed = @()

        foreach ($f in $files) {
            $i++
            $remote = Get-RemotePath $f
            Write-Progress -Activity "Upload nach $Target" -Status $remote -PercentComplete ($i / $files.Count * 100)

            try {
                $sent += Send-FtpFile -LocalFile $f.FullName -RemotePath $remote
            } catch {
                $failed += "$remote : $($_.Exception.Message)"
            }
        }

        Write-Progress -Activity "Upload" -Completed
        Write-Host ("Uebertragen: {0} Dateien, {1:N1} MB" -f ($files.Count - $failed.Count), ($sent / 1MB)) -ForegroundColor Green

        if ($failed.Count -gt 0) {
            Write-Host "`nFehlgeschlagen ($($failed.Count)):" -ForegroundColor Red
            $failed | ForEach-Object { "   $_" }
            exit 1
        }

        Write-Host ""
        Write-Host "Naechster Schritt: $($cfg.url)/setup.php im Browser aufrufen." -ForegroundColor Cyan
    }

    'download-db' {
        $localDir = Join-Path $root 'data\download'
        $stamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
        $localFile = Join-Path $localDir "$Target-$stamp.sqlite"

        try {
            $size = Receive-FtpFile -RemotePath $cfg.remoteDbFile -LocalFile $localFile
            Write-Host ("Heruntergeladen: {0} ({1:N1} MB)" -f $localFile, ($size / 1MB)) -ForegroundColor Green
            Write-Host ""
            Write-Host "Lokal testen:" -ForegroundColor Cyan
            Write-Host "   copy `"$localFile`" `"$root\data\gym141-dev.sqlite`""
        } catch {
            Write-Host "Download fehlgeschlagen: $($_.Exception.Message)" -ForegroundColor Red
            Write-Host "Pfad geprueft: $($cfg.remoteDbFile)"
            exit 1
        }
    }

    'delete-setup' {
        # Der Web-Installer sperrt sich nach der Einrichtung zwar selbst,
        # gehoert danach aber trotzdem vom Server entfernt.
        try {
            Remove-FtpFile -RemotePath 'public/setup.php'
            Write-Host "public/setup.php geloescht." -ForegroundColor Green
        } catch {
            Write-Host "Nicht geloescht: $($_.Exception.Message)" -ForegroundColor Yellow
            Write-Host "(Wenn die Datei schon weg ist, ist das in Ordnung.)"
        }
    }

    'upload-db' {
        $localFile = if ($Path) { $Path } else { Join-Path $root 'data\gym141-dev.sqlite' }

        if (-not (Test-Path $localFile)) {
            Write-Host "Datei nicht gefunden: $localFile" -ForegroundColor Red
            exit 1
        }

        Write-Host "UEBERSCHREIBT die Datenbank auf dem Server ($Target)." -ForegroundColor Yellow
        $size = Send-FtpFile -LocalFile $localFile -RemotePath $cfg.remoteDbFile
        Write-Host ("Hochgeladen: {0:N1} MB" -f ($size / 1MB)) -ForegroundColor Green
    }
}

Write-Host ""
