<?php

use App\Core\Auth;

/**
 * Vorstand und Rechnungsprüfer mit Funktionsperioden und Historie.
 *
 * @var list<array<string,mixed>> $vorstand   aktive Vorstandsfunktionen
 * @var list<array<string,mixed>> $pruefer    aktive Rechnungsprüfer
 * @var list<array<string,mixed>> $historie   abgelaufene Funktionsperioden
 * @var list<string>              $functions
 * @var int                       $minPruefer
 * @var list<array<string,mixed>> $mitglieder
 * @var array<string,string>      $errors
 */

/** Anzeigename inkl. Mitgliedslink. */
$person = static function (array $row): string {
    if ($row['member_id'] !== null) {
        return '<a href="' . e(url('/admin/mitglieder/' . $row['member_id'])) . '">'
            . e($row['first_name'] . ' ' . $row['last_name']) . '</a>'
            . ' <span class="badge" title="mit Mitgliedsdatensatz verlinkt">Mitglied</span>';
    }

    return e((string) $row['name']);
};

/** Formular-Vorbelegung fuer die Auswahlbox. */
$refValue = static function (array $row): string {
    return $row['member_id'] !== null
        ? $row['last_name'] . ' ' . $row['first_name']
        : '';
};

$periode = static function (array $row): string {
    $von = ($row['since'] ?? null) !== null && $row['since'] !== '' ? format_date((string) $row['since']) : '–';
    $bis = ($row['term_to'] ?? null) !== null && $row['term_to'] !== '' ? format_date((string) $row['term_to']) : 'laufend';

    return $von . ' – ' . $bis;
};

/** Zeile inkl. Bearbeiten/Beenden/Loeschen. */
$zeile = static function (array $row) use ($person, $periode, $refValue, $functions): string {
    ob_start();
    ?>
    <tr>
        <td class="strong"><?= e($row['function']) ?></td>
        <td><?= $person($row) ?></td>
        <td><?= tel_link((string) ($row['member_id'] !== null && (string) $row['phone'] === '' ? $row['m_phone'] : $row['phone'])) ?></td>
        <td><?= mail_link((string) ($row['member_id'] !== null && (string) $row['email'] === '' ? $row['m_email'] : $row['email'])) ?></td>
        <td><?= e($periode($row)) ?></td>
        <td><?= e($row['note']) ?></td>
        <td class="row-actions">
            <?php if (Auth::isSuperuser()): ?>
                <details class="plan-edit">
                    <summary class="linklike">bearbeiten</summary>
                    <form method="post" action="<?= e(url('/admin/vorstand/' . $row['id'])) ?>" class="inline-form">
                        <?= csrf_field() ?>

                        <div class="field field--sm">
                            <label>Funktion</label>
                            <input name="function" list="function-list" value="<?= e($row['function']) ?>">
                        </div>

                        <div class="field field--sm">
                            <label>Mitglied</label>
                            <input name="member_ref" list="member-list" value="<?= e($refValue($row)) ?>"
                                   placeholder="tippen zum Suchen …">
                        </div>

                        <div class="field field--sm">
                            <label>… oder Name (extern)</label>
                            <input name="name" value="<?= e($row['name']) ?>">
                        </div>

                        <div class="field field--sm">
                            <label>Telefon</label>
                            <input name="phone" type="tel" value="<?= e($row['phone']) ?>">
                        </div>

                        <div class="field field--sm">
                            <label>E-Mail</label>
                            <input name="email" type="email" value="<?= e($row['email']) ?>">
                        </div>

                        <div class="field field--sm">
                            <label>Periode von</label>
                            <input name="since" type="date" value="<?= e((string) ($row['since'] ?? '')) ?>">
                        </div>

                        <div class="field field--sm">
                            <label>Periode bis</label>
                            <input name="term_to" type="date" value="<?= e((string) ($row['term_to'] ?? '')) ?>">
                        </div>

                        <div class="field field--sm">
                            <label>Notiz</label>
                            <input name="note" value="<?= e($row['note']) ?>">
                        </div>

                        <button class="btn btn--sm" type="submit">Speichern</button>
                    </form>
                </details>

                <?php if (($row['term_to'] ?? null) === null || $row['term_to'] === '' || (string) $row['term_to'] >= date('Y-m-d')): ?>
                    <form method="post" action="<?= e(url('/admin/vorstand/' . $row['id'] . '/beenden')) ?>" class="inline"
                          data-confirm="Funktionsperiode mit heutigem Datum beenden? Der Eintrag bleibt in der Historie.">
                        <?= csrf_field() ?>
                        <button class="linklike" type="submit">beenden</button>
                    </form>
                <?php endif; ?>

                <form method="post" class="inline"
                      action="<?= e(url('/admin/vorstand/' . $row['id'] . '/loeschen')) ?>"
                      data-confirm="Eintrag „<?= e($row['function']) ?>“ endgültig löschen (ohne Historie)?">
                    <?= csrf_field() ?>
                    <button class="linklike linklike--danger" type="submit">löschen</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="page-head">
    <div>
        <h1>Vorstand</h1>
        <p class="page-head__sub">
            Funktionen laut Statuten mit Funktionsperioden. Die
            <strong>Rechnungsprüfer</strong> sind laut Vereinsgesetz ein eigenes Organ
            (nicht Teil des Vorstands) – mindestens <?= (int) $minPruefer ?> sind Pflicht.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/verein')) ?>">Vereinshistorie</a>
        <a class="btn" href="<?= e(url('/admin/verein/dokumente')) ?>">Dokumentenarchiv</a>
    </div>
</div>

<?php if (count($pruefer) < $minPruefer): ?>
    <div class="notice notice--warn">
        Derzeit <?= count($pruefer) === 0 ? 'sind keine' : 'ist nur ' . count($pruefer) ?> Rechnungsprüfer erfasst –
        das Vereinsgesetz verlangt mindestens <?= (int) $minPruefer ?>.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <h2>Vorstand</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Funktion</th><th>Person</th><th>Telefon</th><th>E-Mail</th>
                <th>Funktionsperiode</th><th>Notiz</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($vorstand as $row) { echo $zeile($row); } ?>
            <?php if ($vorstand === []): ?>
                <tr><td colspan="7" class="empty">Keine aktiven Vorstandsfunktionen erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Rechnungsprüfer</h2>
        <span class="badge <?= count($pruefer) >= $minPruefer ? '' : 'badge--warn' ?>">
            <?= count($pruefer) ?> von mind. <?= (int) $minPruefer ?>
        </span>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Funktion</th><th>Person</th><th>Telefon</th><th>E-Mail</th>
                <th>Funktionsperiode</th><th>Notiz</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pruefer as $row) { echo $zeile($row); } ?>
            <?php if ($pruefer === []): ?>
                <tr><td colspan="7" class="empty">Keine aktiven Rechnungsprüfer erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (Auth::isSuperuser()): ?>
    <div class="card">
        <div class="card__head">
            <h2>Funktion besetzen</h2>
        </div>

        <form method="post" action="<?= e(url('/admin/vorstand')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--sm">
                <label for="bd-organ">Organ</label>
                <select id="bd-organ" name="organ">
                    <option value="vorstand">Vorstand</option>
                    <option value="pruefer">Rechnungsprüfer</option>
                </select>
            </div>

            <div class="field field--sm">
                <label for="bd-function">Funktion</label>
                <input id="bd-function" name="function" list="function-list"
                       placeholder="z. B. Obmann/Obfrau">
                <datalist id="function-list">
                    <?php foreach ($functions as $funktion): ?>
                        <option value="<?= e($funktion) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Rechnungsprüfer/in"></option>
                </datalist>
                <p class="field__hint">Bei Rechnungsprüfern: leer lassen = „Rechnungsprüfer/in“.</p>
            </div>

            <div class="field field--sm">
                <label for="bd-ref">Mitglied (Auswahl)</label>
                <input id="bd-ref" name="member_ref" list="member-list" placeholder="tippen zum Suchen …">
                <datalist id="member-list">
                    <?php foreach ($mitglieder as $m): ?>
                        <option value="<?= e($m['last_name'] . ' ' . $m['first_name']) ?>">
                            <?= e(trim(((string) $m['member_no'] !== '' ? 'Nr. ' . $m['member_no'] . ' · ' : '')
                                . (($m['birthdate'] ?? null) !== null ? 'geb. ' . format_date((string) $m['birthdate']) : ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="field field--sm">
                <label for="bd-name">… oder Name (extern)</label>
                <input id="bd-name" name="name">
            </div>

            <div class="field field--sm">
                <label for="bd-phone">Telefon</label>
                <input id="bd-phone" name="phone" type="tel">
                <p class="field__hint">Leer = Daten des Mitglieds.</p>
            </div>

            <div class="field field--sm">
                <label for="bd-email">E-Mail</label>
                <input id="bd-email" name="email" type="email">
            </div>

            <div class="field field--sm">
                <label for="bd-since">Periode von</label>
                <input id="bd-since" name="since" type="date" value="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="field field--sm">
                <label for="bd-term">Periode bis <small>(leer = laufend)</small></label>
                <input id="bd-term" name="term_to" type="date">
            </div>

            <div class="field field--grow">
                <label for="bd-note">Notiz</label>
                <input id="bd-note" name="note">
            </div>

            <button class="btn btn--primary" type="submit">Hinzufügen</button>
        </form>
        <p class="field__hint">
            Dieselbe Funktion kann mehrfach besetzt sein (mehrere Rechnungsprüfer, Beiräte).
            „Beenden“ schließt eine Funktionsperiode ab und verschiebt den Eintrag in die Historie.
        </p>
    </div>
<?php endif; ?>

<?php if ($historie !== []): ?>
    <div class="card">
        <div class="card__head">
            <h2>Historie</h2>
            <p class="muted">Abgelaufene Funktionsperioden (Vorstand und Rechnungsprüfer).</p>
        </div>

        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr>
                    <th>Organ</th><th>Funktion</th><th>Person</th>
                    <th>Funktionsperiode</th><th>Notiz</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($historie as $row): ?>
                    <tr>
                        <td><?= $row['organ'] === 'pruefer' ? 'Rechnungsprüfer' : 'Vorstand' ?></td>
                        <td class="strong"><?= e($row['function']) ?></td>
                        <td><?= $person($row) ?></td>
                        <td><?= e($periode($row)) ?></td>
                        <td><?= e($row['note']) ?></td>
                        <td class="row-actions">
                            <?php if (Auth::isSuperuser()): ?>
                                <form method="post" class="inline"
                                      action="<?= e(url('/admin/vorstand/' . $row['id'] . '/loeschen')) ?>"
                                      data-confirm="Historieneintrag endgültig löschen?">
                                    <?= csrf_field() ?>
                                    <button class="linklike linklike--danger" type="submit">löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
