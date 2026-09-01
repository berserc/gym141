<?php

/**
 * Kontoauszug einspielen: CSV-/XLSX-Export der Bank hochladen.
 *
 * @var list<array<string,mixed>> $imports bisherige Importe
 */
?>
<div class="page-head">
    <div>
        <h1>Kontoauszug einspielen</h1>
        <p class="page-head__sub">
            CSV- oder Excel-Export deiner Bank hochladen – die Spalten werden automatisch
            erkannt (Raiffeisen, Erste/George, BAWAG, Sparkasse, N26, Revolut u. a.).
            Bereits eingespielte Buchungen werden erkannt und <strong>nicht doppelt übernommen</strong>.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/bank')) ?>">Zu den Zahlungen</a>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= e(url('/admin/bank/import')) ?>" enctype="multipart/form-data" class="inline-form">
        <?= csrf_field() ?>
        <div class="field field--grow">
            <label>Konto-Export (CSV oder XLSX)</label>
            <input type="file" name="datei" required accept=".csv,.txt,.xlsx">
        </div>
        <button class="btn btn--primary" type="submit">Einspielen</button>
    </form>
    <p class="field__hint">
        Nach dem Import: automatisch erkannte Mitglieder erscheinen als <em>Vorschlag</em>
        (Mitgliedsnummer im Verwendungszweck, bekannte IBAN oder eindeutiger Name),
        alles andere als <em>unbestimmt</em> – beides wird erst durch deine Bestätigung
        endgültig übernommen.
    </p>
</div>

<?php if ($imports !== []): ?>
    <div class="card">
        <div class="card__head"><h2>Bisherige Importe</h2></div>
        <div class="table-scroll">
            <table class="table table--compact">
                <thead><tr><th>Datum</th><th>Datei</th><th>durch</th><th class="num">Zeilen</th><th class="num">neu</th><th class="num">Duplikate</th></tr></thead>
                <tbody>
                <?php foreach ($imports as $imp): ?>
                    <tr>
                        <td><?= e(format_datetime((string) $imp['created_at'])) ?></td>
                        <td><?= e($imp['filename']) ?></td>
                        <td><?= e((string) ($imp['username'] ?? '–')) ?></td>
                        <td class="num"><?= (int) $imp['row_count'] ?></td>
                        <td class="num"><strong><?= (int) $imp['new_count'] ?></strong></td>
                        <td class="num muted"><?= (int) $imp['duplicate_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
