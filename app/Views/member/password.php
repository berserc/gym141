<?php
/** Passwort ändern im Mitgliederbereich. */
?>
<div class="m-card m-login">
    <h1>Passwort ändern</h1>

    <form method="post" action="<?= e(url('/mitglied/passwort')) ?>">
        <?= csrf_field() ?>

        <div class="m-field">
            <label for="current">Aktuelles Passwort</label>
            <input id="current" name="current" type="password" required autocomplete="current-password">
        </div>

        <div class="m-field">
            <label for="password">Neues Passwort (mind. 8 Zeichen)</label>
            <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        </div>

        <div class="m-field">
            <label for="password2">Neues Passwort wiederholen</label>
            <input id="password2" name="password2" type="password" required minlength="8" autocomplete="new-password">
        </div>

        <button class="btn btn--primary btn--block" type="submit">Passwort ändern</button>
    </form>
</div>
