<?php

/**
 * Web-Installer.
 *
 * Gedacht für Hosting-Pakete ohne SSH-Zugang: dieselbe Einrichtung wie
 * "php bin/install.php", nur im Browser.
 *
 * Zugriffsregeln:
 *   - Solange es noch keine Datenbank gibt, ist die Seite offen (Erstinstallation).
 *   - Sobald eine Datenbank existiert, ist ein Schlüssel nötig: in app/config.php
 *     unter 'setup_key' eintragen und als ?key=... aufrufen.
 *
 * Nach der Einrichtung diese Datei löschen oder 'setup_key' leer lassen.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Installer;

require dirname(__DIR__) . '/app/bootstrap.php';

$dbPath    = (string) Config::get('db_path');
$dbExists  = is_file($dbPath);
$setupKey  = (string) Config::get('setup_key', '');
$lockFile  = dirname($dbPath) . '/.setup-done';
$isLocked  = is_file($lockFile);

$providedKey = (string) ($_REQUEST['key'] ?? '');

// Nach der Erstinstallation nur noch mit gültigem Schlüssel weiterarbeiten.
$needsKey = $dbExists || $isLocked;
$keyOk    = $setupKey !== '' && hash_equals($setupKey, $providedKey);

if ($needsKey && !$keyOk) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Einrichtung gesperrt</title>
        <link rel="stylesheet" href="assets/css/site.css">
    </head>
    <body class="page">
    <main class="site-main">
        <article class="wrap text-page">
            <h1>Einrichtung gesperrt</h1>
            <p>Die Anwendung ist bereits eingerichtet.</p>
            <p>
                Um die Einrichtung erneut auszuführen, tragen Sie in <code>app/config.php</code>
                bei <code>setup_key</code> einen frei gewählten Wert ein und rufen diese Seite mit
                <code>?key=IHR_WERT</code> auf.
            </p>
            <p><a class="btn" href="admin">Zur Anmeldung</a></p>
        </article>
    </main>
    </body>
    </html>
    <?php
    exit;
}

Auth::startSession();

$requirements = Installer::requirements();
$result       = null;
$error        = null;
$adminUser    = 'office@berserc.com';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
        $error = 'Die Sitzung ist abgelaufen. Bitte das Formular erneut absenden.';
    } else {
        $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
        $password  = (string) ($_POST['admin_password'] ?? '');
        $confirm   = (string) ($_POST['admin_password_confirm'] ?? '');
        $force     = ($_POST['force'] ?? '') === '1';

        if ($adminUser === '') {
            $error = 'Bitte einen Benutzernamen angeben.';
        } elseif (mb_strlen($password) < Auth::MIN_PASSWORD_LENGTH) {
            $error = 'Das Passwort muss mindestens ' . Auth::MIN_PASSWORD_LENGTH . ' Zeichen haben.';
        } elseif ($password !== $confirm) {
            $error = 'Die Wiederholung des Passworts stimmt nicht überein.';
        } elseif (!$requirements['ok']) {
            $error = 'Die Voraussetzungen sind nicht erfüllt – bitte die rot markierten Punkte beheben.';
        } else {
            try {
                $installer = new Installer();
                $result    = $installer->run($adminUser, $password, $force);
                @file_put_contents($lockFile, date('c') . "\n");
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Gym141 – Einrichtung</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="admin admin--blank">
<main class="login" style="max-width: 720px">
    <div class="login__box">
        <h1 class="login__title">Gym141 einrichten</h1>
        <p class="login__sub">
            Umgebung: <strong><?= e(Config::get('env')) ?></strong> ·
            Datenbank: <code><?= e(basename($dbPath)) ?></code>
        </p>

        <?php if ($result !== null): ?>
            <div class="flash flash--success">Einrichtung erfolgreich abgeschlossen.</div>

            <ul class="error-list">
                <?php foreach ($result as $line): ?>
                    <li><?= e($line) ?></li>
                <?php endforeach; ?>
            </ul>

            <div class="notice notice--warn">
                <strong>Jetzt bitte aufräumen:</strong> Löschen Sie die Datei
                <code>public/setup.php</code> vom Server oder lassen Sie
                <code>setup_key</code> in <code>app/config.php</code> leer.
            </div>

            <p><a class="btn btn--primary btn--block" href="admin">Zur Anmeldung</a></p>
        <?php else: ?>

            <?php if ($error !== null): ?>
                <div class="flash flash--error"><?= e($error) ?></div>
            <?php endif; ?>

            <h2>Voraussetzungen</h2>
            <ul class="setup-checks">
                <?php foreach ($requirements['checks'] as $check): ?>
                    <li class="<?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
                        <?= $check['ok'] ? '✓' : '✗' ?> <?= e($check['name']) ?>
                        <?php if (!$check['ok']): ?>
                            <small><?= e($check['hint']) ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post" class="form">
                <?= Csrf::field() ?>
                <?php if ($providedKey !== ''): ?>
                    <input type="hidden" name="key" value="<?= e($providedKey) ?>">
                <?php endif; ?>

                <h2>Superuser</h2>

                <div class="field">
                    <label for="admin_user">Benutzername</label>
                    <input id="admin_user" name="admin_user" required value="<?= e($adminUser) ?>">
                    <p class="field__hint">E-Mail-Adressen sind als Benutzername erlaubt.</p>
                </div>

                <div class="field">
                    <label for="admin_password">Passwort</label>
                    <input id="admin_password" name="admin_password" type="password" required
                           minlength="<?= Auth::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                </div>

                <div class="field">
                    <label for="admin_password_confirm">Passwort wiederholen</label>
                    <input id="admin_password_confirm" name="admin_password_confirm" type="password" required
                           autocomplete="new-password">
                </div>

                <?php if ($dbExists): ?>
                    <label class="check">
                        <input type="checkbox" name="force" value="1">
                        Datenbank komplett neu aufbauen (bestehende Daten werden vorher gesichert)
                    </label>
                <?php endif; ?>

                <button class="btn btn--primary btn--block" type="submit">Einrichtung starten</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
