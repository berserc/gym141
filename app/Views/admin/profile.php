<?php

use App\Core\Auth;

/**
 * @var array<string,mixed>  $user
 * @var array<string,string> $errors
 */
$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<p class="field__error">' . e($errors[$field]) . '</p>' : '';
};
?>
<div class="page-head">
    <h1>Mein Konto</h1>
</div>

<div class="form-grid">
    <div class="card">
        <h2>Angaben</h2>
        <dl class="datalist">
            <dt>Benutzername</dt><dd><?= e($user['username']) ?></dd>
            <dt>Name</dt><dd><?= e($user['name']) ?: '–' ?></dd>
            <dt>E-Mail</dt><dd><?= e($user['email']) ?: '–' ?></dd>
            <dt>Rolle</dt><dd><?= e(Auth::ROLES[$user['role']] ?? $user['role']) ?></dd>
            <dt>Letzte Anmeldung</dt>
            <dd><?= e(format_datetime($user['last_login_at'] === null ? null : (string) $user['last_login_at'])) ?: '–' ?></dd>
        </dl>
        <p class="muted">Name und E-Mail ändert ein Superuser in der Benutzerverwaltung.</p>
    </div>

    <form method="post" action="<?= e(url('/admin/profil')) ?>" class="card form">
        <?= csrf_field() ?>
        <h2>Passwort ändern</h2>

        <?php if ((int) $user['must_change_password'] === 1): ?>
            <div class="notice notice--warn">Bitte vergeben Sie ein eigenes Passwort.</div>
        <?php endif; ?>

        <div class="field">
            <label for="current_password">Aktuelles Passwort</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
            <?= $err('current_password') ?>
        </div>

        <div class="field">
            <label for="new_password">Neues Passwort</label>
            <input id="new_password" name="new_password" type="password" required autocomplete="new-password"
                   minlength="<?= Auth::MIN_PASSWORD_LENGTH ?>">
            <?= $err('new_password') ?>
            <p class="field__hint">Mindestens <?= Auth::MIN_PASSWORD_LENGTH ?> Zeichen.</p>
        </div>

        <div class="field">
            <label for="new_password_confirm">Neues Passwort wiederholen</label>
            <input id="new_password_confirm" name="new_password_confirm" type="password" required autocomplete="new-password">
            <?= $err('new_password_confirm') ?>
        </div>

        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Passwort ändern</button>
        </div>
    </form>
</div>
