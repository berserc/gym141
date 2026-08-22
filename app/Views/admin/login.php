<?php

/**
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var list<array{type:string,message:string}> $flash
 * @var string $appName
 */
?>
<main class="login">
    <div class="login__box">
        <h1 class="login__title"><?= e($appName) ?></h1>
        <p class="login__sub">Verwaltung – bitte anmelden</p>

        <?php foreach ($flash as $message): ?>
            <div class="flash flash--<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('/admin/login')) ?>" class="form">
            <?= csrf_field() ?>

            <div class="field">
                <label for="username">Benutzername</label>
                <input id="username" name="username" type="text" autocomplete="username" required
                       autofocus value="<?= e($old['username'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="password">Passwort</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <button class="btn btn--primary btn--block" type="submit">Anmelden</button>
        </form>

        <p class="login__back"><a href="<?= e(url('/')) ?>">Zurück zur Website</a></p>
    </div>
</main>
