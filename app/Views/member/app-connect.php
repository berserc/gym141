<?php

/**
 * "Gym141-App verbinden": Das Mitglied erzeugt sich selbst einen QR-Code
 * (10 Minuten gueltig, einmalig; 5 Minuten Wartezeit zwischen Erzeugungen).
 *
 * @var string $token     frisch erzeugter Token ('' = keiner)
 * @var string $appUri    gym141://einladung?... (QR-Inhalt)
 * @var string $inviteUrl Einladungslink (fuers Selbst-Schicken)
 * @var int    $wartezeit Restsekunden bis zur naechsten Erzeugung
 * @var int    $ttlMinuten
 */
?>
<h1>Gym141-App verbinden</h1>

<div class="m-card">
    <div class="m-card__head">
        <h2>Die App auf dein Handy holen</h2>
    </div>

    <p class="muted-dark">
        Mit der <strong>Gym141-App</strong> hast du Beiträge, Termine und dein
        Gewichtstagebuch immer dabei – auch offline. Erzeuge hier deinen
        persönlichen QR-Code und fotografiere ihn mit der App ab:
        <em>„Verein hinzufügen“ → „Ich habe eine Einladung“ → „QR scannen“.</em>
    </p>

    <?php if ($token !== ''): ?>
        <div style="display:flex;flex-direction:column;align-items:center;gap:.6rem;margin:1rem 0">
            <div id="qr" style="background:#fff;padding:14px;border-radius:.6rem;line-height:0"></div>
            <p class="muted-dark" style="margin:0">
                Gültig für <?= (int) $ttlMinuten ?> Minuten, einmalig verwendbar.
            </p>
            <p style="word-break:break-all;font-size:.85rem;margin:0">
                Oder Link am Handy öffnen:
                <a href="<?= e($inviteUrl) ?>"><?= e($inviteUrl) ?></a>
            </p>
        </div>

        <script src="<?= e(asset('vendor/qrcode.js')) ?>"></script>
        <script>
        (function () {
            var qr = qrcode(0, 'M');
            qr.addData(<?= json_encode($appUri) ?>);
            qr.make();
            document.getElementById('qr').innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
            document.getElementById('qr').firstChild.style.width = '230px';
            document.getElementById('qr').firstChild.style.height = '230px';
        })();
        </script>
    <?php endif; ?>

    <?php if ($wartezeit > 0 && $token === ''): ?>
        <p class="muted-dark" role="status">
            ⏳ Du hast gerade einen QR-Code erzeugt – der nächste ist in
            <strong><?= intdiv($wartezeit, 60) ?>:<?= sprintf('%02d', $wartezeit % 60) ?> Minuten</strong> möglich.
        </p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/mitglied/app-einladung')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--primary" type="submit">
                <?= $token !== '' ? 'Neuen QR-Code erzeugen' : 'QR-Code erzeugen' ?>
            </button>
            <?php if ($token !== ''): ?>
                <p class="muted-dark" style="font-size:.85rem">Zwischen zwei Erzeugungen gilt eine Wartezeit von 5 Minuten.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<div class="m-card">
    <div class="m-card__head">
        <h2>So gehts</h2>
    </div>
    <ol style="line-height:1.9">
        <li>Gym141-App installieren (App Store / Google Play / Windows).</li>
        <li>Hier <strong>„QR-Code erzeugen“</strong> drücken.</li>
        <li>In der App: „Verein hinzufügen“ → „Ich habe eine Einladung“ →
            <strong>„QR-Code abfotografieren“</strong> – fertig, ohne Zugangsdaten.</li>
    </ol>
    <p class="muted-dark">
        Website und App nutzen dasselbe Konto: Alles, was du hier siehst und
        änderst, gilt auch in der App – und umgekehrt.
    </p>
</div>
