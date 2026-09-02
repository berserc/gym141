<?php

/**
 * Oeffentliche App-Einladungsseite: erklaert dem Mitglied, wie es die
 * Gym141-App mit dem Verein verbindet. Der Link/Code ist 10 Minuten gueltig.
 *
 * @var bool   $gueltig
 * @var string $token
 * @var string $appUri  gym141://einladung?...
 */
?>
<section class="wrap" style="max-width:36rem;padding:2.5rem 1rem">
    <h1 style="margin:0 0 .5rem">Einladung zur Gym141-App</h1>

    <?php if (!$gueltig): ?>
        <p role="alert" style="background:#c0392b;color:#fff;padding:.7rem 1rem;border-radius:.4rem">
            Diese Einladung ist abgelaufen oder wurde bereits verwendet.
            Bitte im Verein eine neue anfordern.
        </p>
    <?php else: ?>
        <p>Mit dieser Einladung verbindest du die <strong>Gym141-App</strong> ohne
            Zugangsdaten mit deinem Verein – sie ist <strong>10 Minuten gültig</strong>
            und funktioniert nur einmal.</p>

        <ol style="line-height:1.9">
            <li>Öffne die <strong>Gym141-App</strong> (App Store / Google Play / Windows).</li>
            <li>Tippe im Menü auf <strong>„Verein hinzufügen“</strong>.</li>
            <li>Wähle <strong>„Ich habe eine Einladung“</strong> und füge den Code unten ein –
                oder fotografiere den QR-Code ab, den dir der Verein zeigt.</li>
        </ol>

        <div style="margin:1.2rem 0">
            <label style="display:block;font-size:.85rem;opacity:.7;margin-bottom:.3rem">Dein Einladungscode</label>
            <input readonly value="<?= e($appUri) ?>" onclick="this.select();document.execCommand('copy');this.nextElementSibling.hidden=false"
                   style="width:100%;padding:.6rem .7rem;font-family:monospace;font-size:.8rem">
            <small hidden style="color:#1e7d46">kopiert ✓</small>
        </div>

        <p style="opacity:.7;font-size:.9rem">
            Hast du die App auf DIESEM Gerät, kopiere den Code (antippen) und füge ihn
            in der App ein – fertig.
        </p>
    <?php endif; ?>
</section>
