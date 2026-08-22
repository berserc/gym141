<?php

/**
 * Release-Paket bauen: ZIP + manifest.json für den Update-Server.
 *
 *   php bin/release.php --version=1.1.0 --changelog="Neue Funktionen ..."
 *   php bin/release.php --version=1.1.0 --out=../devworld-website/public/updates/gym141
 *
 * Schreibt VERSION, packt den Anwendungscode (ohne Nutzerdaten, Konfiguration,
 * .git und Entwicklungsdateien) nach <out>/gym141-<version>.zip und erzeugt
 * das passende manifest.json mit SHA-256-Prüfsumme.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Nur ueber die Kommandozeile.\n");
}

$options   = getopt('', ['version:', 'changelog::', 'out::']);
$version   = (string) ($options['version'] ?? '');
$changelog = (string) ($options['changelog'] ?? '');
$root      = dirname(__DIR__);
$out       = (string) ($options['out'] ?? $root . '/dist');

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    exit("Bitte --version=X.Y.Z angeben.\n");
}

if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    exit("Ausgabeverzeichnis kann nicht angelegt werden: $out\n");
}

file_put_contents($root . '/VERSION', $version . "\n");
echo "VERSION -> $version\n";

// Was NICHT ins Release gehoert (Nutzerdaten, Secrets, Entwicklung).
$ausschluss = [
    '#^data/(?!schema\.sql|seed/|\.htaccess)#',
    '#^public/uploads/(?!\.htaccess)#',
    '#^app/config\.php$#',
    '#^deploy/deploy\.config\.json$#',
    '#^\.git#',
    '#^dist/#',
    '#^\.claude#',
    '#\.bak$#',
    '#\.log$#',
];

$zipPfad = $out . '/gym141-' . $version . '.zip';
@unlink($zipPfad);

$zip = new ZipArchive();

if ($zip->open($zipPfad, ZipArchive::CREATE) !== true) {
    exit("ZIP kann nicht angelegt werden: $zipPfad\n");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$anzahl = 0;

/** @var SplFileInfo $eintrag */
foreach ($iterator as $eintrag) {
    if (!$eintrag->isFile()) {
        continue;
    }

    $relativ = str_replace('\\', '/', substr((string) $eintrag->getPathname(), strlen($root) + 1));

    foreach ($ausschluss as $muster) {
        if (preg_match($muster, $relativ) === 1) {
            continue 2;
        }
    }

    $zip->addFile((string) $eintrag->getPathname(), $relativ);
    $anzahl++;
}

$zip->close();

$sha = hash_file('sha256', $zipPfad);

printf("Paket: %s (%d Dateien, %.1f MB)\nSHA-256: %s\n", basename($zipPfad), $anzahl, filesize($zipPfad) / 1048576, $sha);

// Manifest: zip_url relativ zum Manifest-Standort (gleicher Ordner).
$manifest = [
    'version'   => $version,
    'zip_url'   => 'https://devworld-llc.com/updates/gym141/gym141-' . $version . '.zip',
    'sha256'    => $sha,
    'min_php'   => '8.1',
    'changelog' => $changelog,
];

file_put_contents(
    $out . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

echo "Manifest: $out/manifest.json\n";
echo "Fertig. Ordner auf den Update-Server stellen (devworld-llc.com/updates/gym141/).\n";
