<?php

use App\Core\Auth;
use App\Core\Flash;

/**
 * Buchhaltung (Kassabuch).
 *
 * @var array<string,mixed>       $filters
 * @var list<array<string,mixed>> $entries
 * @var array{in:float,out:float,saldo:float,count:int} $sums
 * @var float                     $balance
 * @var list<string>              $categoriesIn
 * @var list<string>              $categoriesOut
 * @var list<array<string,mixed>> $methods
 * @var array<string,string>      $errors
 */
$old = Flash::oldInput();

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<p class="field__error">' . e($errors[$field]) . '</p>'
        : '';
};

$alleKategorien = array_values(array_unique(array_merge($categoriesIn, $categoriesOut)));
?>
<?= admin_tabs([
    ['Kassabuch', '/admin/buchhaltung'],
    ['Auswertung', '/admin/buchhaltung/auswertung'],
    ['Fixkosten', '/admin/buchhaltung/fixkosten'],
    ['Zahlungsarten', '/admin/buchhaltung/zahlungsarten'],
]) ?>

<div class="page-head">
    <div>
        <h1>Buchhaltung</h1>
        <p class="page-head__sub">
            Kassastand gesamt: <strong><?= e(format_money($balance)) ?></strong>
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/buchhaltung/export.csv', array_filter($filters))) ?>">CSV-Export</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/beitraege')) ?>">Zu den Beiträgen</a>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/buchhaltung')) ?>">
    <div class="filters__row">
        <div class="field field--grow">
            <label for="f-q">Suche</label>
            <input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                   placeholder="Betreff, Kategorie oder Mitglied …">
        </div>

        <div class="field field--sm">
            <label for="f-type">Art</label>
            <select id="f-type" name="type">
                <option value="">alle</option>
                <option value="einnahme" <?= $filters['type'] === 'einnahme' ? 'selected' : '' ?>>Einnahmen</option>
                <option value="ausgabe"  <?= $filters['type'] === 'ausgabe' ? 'selected' : '' ?>>Ausgaben</option>
            </select>
        </div>

        <div class="field field--sm">
            <label for="f-cat">Kategorie</label>
            <select id="f-cat" name="category">
                <option value="">alle</option>
                <?php foreach ($alleKategorien as $kategorie): ?>
                    <option value="<?= e($kategorie) ?>" <?= $filters['category'] === $kategorie ? 'selected' : '' ?>>
                        <?= e($kategorie) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--sm">
            <label for="f-method">Zahlungsart</label>
            <select id="f-method" name="payment_method_id">
                <option value="">alle</option>
                <?php foreach ($methods as $method): ?>
                    <option value="<?= (int) $method['id'] ?>"
                        <?= (string) $filters['payment_method_id'] === (string) $method['id'] ? 'selected' : '' ?>>
                        <?= e($method['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--sm">
            <label for="f-from">Von</label>
            <input id="f-from" type="date" name="from" value="<?= e($filters['from']) ?>">
        </div>

        <div class="field field--sm">
            <label for="f-to">Bis</label>
            <input id="f-to" type="date" name="to" value="<?= e($filters['to']) ?>">
        </div>

        <button class="btn btn--primary" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung', ['from' => ''])) ?>">Alles</a>
    </div>
</form>

<div class="stat-grid">
    <div class="stat stat--ok">
        <span class="stat__value"><?= e(format_money($sums['in'])) ?></span>
        <span class="stat__label">Einnahmen (Auswahl)</span>
    </div>
    <div class="stat stat--warn">
        <span class="stat__value"><?= e(format_money($sums['out'])) ?></span>
        <span class="stat__label">Ausgaben (Auswahl)</span>
    </div>
    <div class="stat">
        <span class="stat__value"><?= e(format_money($sums['saldo'])) ?></span>
        <span class="stat__label">Saldo (Auswahl, <?= (int) $sums['count'] ?> Buchungen)</span>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Neue Buchung</h2>
        <p class="muted">Beitragszahlungen werden beim Abhaken automatisch gebucht – hier nur alles andere erfassen.</p>
    </div>

    <form method="post" action="<?= e(url('/admin/buchhaltung')) ?>" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--sm">
            <label for="nb-type">Art</label>
            <select id="nb-type" name="type" data-ledger-type>
                <option value="einnahme" <?= ($old['type'] ?? '') !== 'ausgabe' ? 'selected' : '' ?>>Einnahme</option>
                <option value="ausgabe" <?= ($old['type'] ?? '') === 'ausgabe' ? 'selected' : '' ?>>Ausgabe</option>
            </select>
        </div>

        <div class="field field--sm">
            <label for="nb-date">Datum</label>
            <input id="nb-date" name="booked_on" type="date" value="<?= e($old['booked_on'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="field field--sm">
            <label for="nb-cat">Kategorie</label>
            <select id="nb-cat" name="category">
                <optgroup label="Einnahmen" data-ledger-group="einnahme">
                    <?php foreach ($categoriesIn as $kategorie): ?>
                        <option value="<?= e($kategorie) ?>" <?= ($old['category'] ?? '') === $kategorie ? 'selected' : '' ?>>
                            <?= e($kategorie) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Ausgaben" data-ledger-group="ausgabe">
                    <?php foreach ($categoriesOut as $kategorie): ?>
                        <option value="<?= e($kategorie) ?>" <?= ($old['category'] ?? '') === $kategorie ? 'selected' : '' ?>>
                            <?= e($kategorie) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <div class="field field--grow">
            <label for="nb-text">Betreff</label>
            <input id="nb-text" name="text" value="<?= e($old['text'] ?? '') ?>"
                   placeholder="z. B. Getränkeeinkauf, Spende Fa. Muster …">
            <?= $err('text') ?>
        </div>

        <div class="field field--xs">
            <label for="nb-amount">Betrag (€)</label>
            <input id="nb-amount" name="amount" type="number" step="0.01" min="0"
                   value="<?= e($old['amount'] ?? '') ?>">
            <?= $err('amount') ?>
        </div>

        <div class="field field--sm">
            <label for="nb-method">Zahlungsart</label>
            <select id="nb-method" name="payment_method_id">
                <?php foreach ($methods as $method): ?>
                    <option value="<?= (int) $method['id'] ?>"
                        <?= ($old['payment_method_id'] ?? '') === (string) $method['id'] ? 'selected' : '' ?>>
                        <?= e($method['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--sm">
            <label for="nb-member">Mitglied <small>(optional)</small></label>
            <input id="nb-member" name="member_ref" value="<?= e($old['member_ref'] ?? '') ?>"
                   placeholder="Nr. oder Vorname Zuname">
            <?= $err('member_ref') ?>
        </div>

        <button class="btn btn--primary" type="submit">Buchen</button>
    </form>
</div>

<div class="card">
    <div class="card__head">
        <h2><?= count($entries) ?> Buchung(en)</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Datum</th>
                <th>Kategorie</th>
                <th>Betreff</th>
                <th>Mitglied</th>
                <th>Zahlungsart</th>
                <th class="num">Einnahme</th>
                <th class="num">Ausgabe</th>
                <th>erfasst von</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e(format_date((string) $entry['booked_on'])) ?></td>
                    <td><span class="badge"><?= e($entry['category']) ?></span></td>
                    <td><?= e($entry['text']) ?></td>
                    <td>
                        <?php if ($entry['member_id'] !== null): ?>
                            <a href="<?= e(url('/admin/mitglieder/' . $entry['member_id'])) ?>">
                                <?= e(trim(($entry['last_name'] ?? '') . ', ' . ($entry['first_name'] ?? ''), ', ')) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($entry['payment_method_name'] ?? '')) ?></td>
                    <td class="num is-plus"><?= $entry['type'] === 'einnahme' ? e(format_money($entry['amount'])) : '' ?></td>
                    <td class="num is-minus"><?= $entry['type'] === 'ausgabe' ? e(format_money($entry['amount'])) : '' ?></td>
                    <td class="muted"><?= e((string) ($entry['created_by_name'] ?? '')) ?></td>
                    <td class="row-actions">
                        <?php if ($entry['fee_entry_id'] !== null): ?>
                            <span class="muted" title="Aus Beitragszahlung – zum Stornieren den Beitrag wieder öffnen.">automatisch</span>
                        <?php elseif (($entry['fixed_cost_id'] ?? null) !== null): ?>
                            <span class="muted" title="Aus den Fixkosten – Änderungen dort vornehmen.">Fixkosten</span>
                        <?php elseif (Auth::isSuperuser()): ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('/admin/buchhaltung/' . $entry['id'] . '/loeschen')) ?>"
                                  data-confirm="Buchung „<?= e($entry['text']) ?>“ löschen?">
                                <?= csrf_field() ?>
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($entries === []): ?>
                <tr><td colspan="9" class="empty">Keine Buchungen im gewählten Zeitraum.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="5">Summe (Auswahl)</th>
                <th class="num is-plus"><?= e(format_money($sums['in'])) ?></th>
                <th class="num is-minus"><?= e(format_money($sums['out'])) ?></th>
                <th colspan="2">Saldo: <?= e(format_money($sums['saldo'])) ?></th>
            </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
// Kategorienliste an die gewaehlte Buchungsart anpassen
(function () {
    var typ = document.querySelector('[data-ledger-type]');
    var kategorie = document.getElementById('nb-cat');
    if (!typ || !kategorie) return;

    var sync = function () {
        kategorie.querySelectorAll('optgroup').forEach(function (gruppe) {
            var passt = gruppe.getAttribute('data-ledger-group') === typ.value;
            gruppe.hidden = !passt;
            gruppe.querySelectorAll('option').forEach(function (option) { option.disabled = !passt; });
        });
        var gewaehlt = kategorie.selectedOptions[0];
        if (!gewaehlt || gewaehlt.disabled) {
            var erste = kategorie.querySelector('optgroup[data-ledger-group="' + typ.value + '"] option');
            if (erste) erste.selected = true;
        }
    };

    typ.addEventListener('change', sync);
    sync();
})();
</script>
