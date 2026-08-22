<?php

/**
 * Mitglieder-Login: Anmeldung per E-Mail und Passwort (vom Verein vergeben).
 */
?>
<?php $verein = \App\Models\Setting::get('club_name', 'Gym141'); ?>
<div class="m-card m-login">
    <div class="m-login__brand">
        <?php if (site_logo() !== ''): ?>
            <img src="<?= e(site_logo()) ?>" alt="<?= e($verein) ?>" width="96" height="96">
        <?php endif; ?>
        <h1>Mitglieder-Login</h1>
        <p class="muted-dark">Zugang für Mitglieder – <?= e($verein) ?></p>
    </div>

    <form method="post" action="<?= e(url('/mitglied/login')) ?>">
        <?= csrf_field() ?>

        <div class="m-field">
            <label for="email">E-Mail-Adresse</label>
            <input id="email" name="email" type="email" required autocomplete="username" autofocus>
        </div>

        <div class="m-field">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <button class="btn btn--primary btn--block" type="submit">Anmelden</button>
    </form>

    <p class="m-login__hint">
        Kein Zugang? Die Zugangsdaten bekommst du vom Verein<?php
        $mail = \App\Models\Setting::get('club_email');
        ?><?= $mail !== '' ? ' (<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>)' : '' ?>.
    </p>

    <p class="m-login__back"><a href="<?= e(url('/')) ?>">← Zur Website</a></p>
</div>
