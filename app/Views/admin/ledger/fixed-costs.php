<?php

use App\Core\Auth;
use App\Core\Flash;
use App\Models\FeeRepo;

/**
 * Fixkosten / wiederkehrende Buchungen: werden je Periode automatisch gebucht
 * (monatlich, quartalsweise, halbjährlich oder jährlich – wie Beitragsarten).
 *
 * @var list<array<string,mixed>> $costs
 * @var list<string>              $categoriesIn
 * @var list<string>              $categoriesOut
 * @var list<array<string,mixed>> $methods
 * @var array<int,list<array<string,mixed>>> $amountHistories
 * @var array<int,list<array<string,mixed>>> $files Dokumente je Fixkosten-Eintrag
 * @var array<string,string>      $errors
 */
$methodName = static function ($id) use ($methods): string {
    foreach ($methods as $method) {
        if ((int) $method['id'] === (int) $id) {
            return (string) $method['name'];
        }
    }

    return '';
};
$old = Flash::oldInput();

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<p class="field__error">' . e($errors[$field]) . '</p>'
        : '';
};
?>
<?= admin_tabs([
    ['Kassabuch', '/admin/buchhaltung'],
    ['Auswertung', '/admin/buchhaltung/auswertung'],
    ['Fixkosten', '/admin/buchhaltung/fixkosten'],
    ['Zahlungsarten', '/admin/buchhaltung/zahlungsarten'],
]) ?>

<div class="page-head">
    <div>
        <h1>Fixkosten</h1>
        <p class="page-head__sub">
            Wiederkehrende Beträge (Miete, Internet, Versicherung …) werden – wie die
            Mitgliedsbeiträge – je Periode (monatlich, quartalsweise, halbjährlich, jährlich)
            automatisch am Buchungstag in die Buchhaltung gebucht.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung')) ?>">Zur Buchhaltung</a>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Bestehende Fixkosten</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Bezeichnung</th>
                <th>Art</th>
                <th>Kategorie</th>
                <th class="num">Betrag</th>
                <th>Periode</th>
                <th>Zahlungsart</th>
                <th>seit</th>
                <th>Status</th>
                <th>Notiz</th>
                <th>Dokumente</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($costs as $cost): ?>
                <tr>
                    <td class="strong"><?= e($cost['name']) ?></td>
                    <td><?= $cost['type'] === 'einnahme' ? '<span class="is-plus">Einnahme</span>' : '<span class="is-minus">Ausgabe</span>' ?></td>
                    <td><span class="badge"><?= e($cost['category']) ?></span></td>
                    <td class="num"><?= e(format_money($cost['amount'])) ?></td>
                    <td><?= e(FeeRepo::intervalLabel((string) ($cost['interval'] ?? 'monatlich'))) ?>, am <?= (int) $cost['due_day'] ?>.</td>
                    <td><?= e($methodName($cost['payment_method_id'] ?? null)) ?></td>
                    <td><?= e(format_date((string) $cost['since'])) ?></td>
                    <td>
                        <span class="pill pill--<?= (int) $cost['active'] === 1 ? 'aktiv' : 'inaktiv' ?>">
                            <?= (int) $cost['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td><?= e($cost['note']) ?></td>
                    <td>
                        <div class="media-chips">
                            <?php foreach ($files[(int) $cost['id']] ?? [] as $datei): ?>
                                <span class="media-chip">
                                    <a class="media-chip__main"
                                       href="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'] . '/datei/' . $datei['id'])) ?>"
                                       target="_blank" title="<?= e($datei['filename']) ?>">
                                        📄&nbsp;<?= e($datei['filename']) ?>
                                    </a>
                                    <form method="post" class="inline"
                                          action="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'] . '/datei-loeschen')) ?>"
                                          data-confirm="Dokument „<?= e($datei['filename']) ?>“ löschen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="file_id" value="<?= (int) $datei['id'] ?>">
                                        <button class="media-chip__remove" type="submit" title="löschen">×</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>

                            <details class="media-add">
                                <summary title="Dokument anhängen (Vertrag, Rechnung …)">+</summary>
                                <form method="post" enctype="multipart/form-data"
                                      action="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'] . '/datei')) ?>"
                                      class="media-add__form">
                                    <?= csrf_field() ?>
                                    <label>Datei (max. 25 MB)
                                        <input name="file" type="file">
                                    </label>
                                    <label>… oder aus der Dateiablage
                                        <input type="hidden" name="media_file_id" value="">
                                        <span class="media-add__pickrow">
                                            <button type="button" class="btn btn--sm js-open-picker"
                                                    data-picker-url="<?= e(url('/admin/dateien', ['picker' => 1])) ?>">📁 Datei wählen</button>
                                            <span class="js-picked muted"></span>
                                        </span>
                                    </label>
                                    <button class="btn btn--sm" type="submit">Anhängen</button>
                                </form>
                            </details>
                        </div>
                    </td>
                    <td class="row-actions">
                        <details class="plan-edit">
                            <summary class="linklike">bearbeiten</summary>
                            <form method="post" action="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'])) ?>" class="inline-form">
                                <?= csrf_field() ?>

                                <div class="field field--sm">
                                    <label>Bezeichnung</label>
                                    <input name="name" required value="<?= e($cost['name']) ?>">
                                </div>

                                <div class="field field--xs">
                                    <label>Art</label>
                                    <select name="type">
                                        <option value="ausgabe" <?= $cost['type'] === 'ausgabe' ? 'selected' : '' ?>>Ausgabe</option>
                                        <option value="einnahme" <?= $cost['type'] === 'einnahme' ? 'selected' : '' ?>>Einnahme</option>
                                    </select>
                                </div>

                                <div class="field field--sm">
                                    <label>Kategorie</label>
                                    <input name="category" list="kategorien-liste" value="<?= e($cost['category']) ?>">
                                </div>

                                <div class="field field--xs">
                                    <label>Betrag (€)</label>
                                    <input name="amount" type="number" step="0.01" min="0"
                                           value="<?= e(number_format((float) $cost['amount'], 2, '.', '')) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Periode</label>
                                    <select name="interval">
                                        <?php foreach (FeeRepo::INTERVALS as $key => [, $label]): ?>
                                            <option value="<?= e($key) ?>" <?= ($cost['interval'] ?? 'monatlich') === $key ? 'selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field field--xs">
                                    <label>Buchung am</label>
                                    <input name="due_day" type="number" min="1" max="28" value="<?= (int) $cost['due_day'] ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Zahlungsart</label>
                                    <select name="payment_method_id">
                                        <?php foreach ($methods as $method): ?>
                                            <option value="<?= (int) $method['id'] ?>"
                                                <?= (int) ($cost['payment_method_id'] ?? 0) === (int) $method['id'] ? 'selected' : '' ?>>
                                                <?= e($method['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field field--sm">
                                    <label>seit (Monat)</label>
                                    <input name="since" type="date" value="<?= e((string) $cost['since']) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Notiz</label>
                                    <input name="note" value="<?= e($cost['note']) ?>">
                                </div>

                                <label class="check">
                                    <input type="checkbox" name="active" value="1" <?= (int) $cost['active'] === 1 ? 'checked' : '' ?>> aktiv
                                </label>

                                <button class="btn btn--sm" type="submit">Speichern</button>
                            </form>
                        </details>

                        <details class="plan-edit">
                            <summary class="linklike">Betrag ändern ab …</summary>

                            <?php $historie = $amountHistories[(int) $cost['id']] ?? []; ?>
                            <?php if ($historie !== []): ?>
                                <table class="table table--compact" style="margin:.5rem 0">
                                    <thead><tr><th>gültig ab</th><th class="num">Betrag</th><th>Notiz</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($historie as $aenderung): ?>
                                        <tr>
                                            <td><?= e(format_date((string) $aenderung['valid_from'])) ?></td>
                                            <td class="num"><?= e(format_money($aenderung['amount'])) ?></td>
                                            <td><?= e($aenderung['note']) ?></td>
                                            <td>
                                                <form method="post" class="inline"
                                                      action="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'] . '/betrag-loeschen')) ?>"
                                                      data-confirm="Betragsänderung entfernen?">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="history_id" value="<?= (int) $aenderung['id'] ?>">
                                                    <button class="linklike linklike--danger" type="submit">entfernen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <form method="post" action="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'] . '/betrag')) ?>" class="inline-form">
                                <?= csrf_field() ?>

                                <div class="field field--sm">
                                    <label>gültig ab</label>
                                    <input name="valid_from" type="date" required
                                           value="<?= e(date('Y-m-01', strtotime('first day of next month'))) ?>">
                                </div>

                                <div class="field field--xs">
                                    <label>neuer Betrag (€)</label>
                                    <input name="amount" type="number" step="0.01" min="0" required>
                                </div>

                                <div class="field field--sm">
                                    <label>Notiz</label>
                                    <input name="note">
                                </div>

                                <button class="btn btn--sm" type="submit">Ändern</button>
                            </form>
                            <p class="field__hint">
                                Gilt für Buchungen ab dem Stichtag – bereits erstellte Buchungen bleiben unverändert.
                            </p>
                        </details>

                        <?php if (Auth::isSuperuser()): ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('/admin/buchhaltung/fixkosten/' . $cost['id'] . '/loeschen')) ?>"
                                  data-confirm="Fixkosten-Eintrag &quot;<?= e($cost['name']) ?>&quot; löschen? Bereits erstellte Buchungen bleiben erhalten.">
                                <?= csrf_field() ?>
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($costs === []): ?>
                <tr><td colspan="11" class="empty">Noch keine Fixkosten angelegt.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Neue Fixkosten</h2>
    </div>

    <form method="post" action="<?= e(url('/admin/buchhaltung/fixkosten')) ?>" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--sm">
            <label for="fk-name">Bezeichnung *</label>
            <input id="fk-name" name="name" required value="<?= e($old['name'] ?? '') ?>"
                   placeholder="z. B. Miete, Internet">
            <?= $err('name') ?>
        </div>

        <div class="field field--xs">
            <label for="fk-type">Art</label>
            <select id="fk-type" name="type">
                <option value="ausgabe" <?= ($old['type'] ?? 'ausgabe') === 'ausgabe' ? 'selected' : '' ?>>Ausgabe</option>
                <option value="einnahme" <?= ($old['type'] ?? '') === 'einnahme' ? 'selected' : '' ?>>Einnahme</option>
            </select>
        </div>

        <div class="field field--sm">
            <label for="fk-cat">Kategorie</label>
            <input id="fk-cat" name="category" list="kategorien-liste"
                   value="<?= e($old['category'] ?? 'Miete/Betriebskosten') ?>">
            <datalist id="kategorien-liste">
                <?php foreach (array_unique(array_merge($categoriesOut, $categoriesIn)) as $kategorie): ?>
                    <option value="<?= e($kategorie) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="field field--xs">
            <label for="fk-amount">Betrag (€)</label>
            <input id="fk-amount" name="amount" type="number" step="0.01" min="0" value="<?= e($old['amount'] ?? '') ?>">
            <?= $err('amount') ?>
        </div>

        <div class="field field--sm">
            <label for="fk-interval">Periode</label>
            <select id="fk-interval" name="interval">
                <?php foreach (FeeRepo::INTERVALS as $key => [, $label]): ?>
                    <option value="<?= e($key) ?>" <?= ($old['interval'] ?? 'monatlich') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= $err('interval') ?>
        </div>

        <div class="field field--xs">
            <label for="fk-due">Buchung am</label>
            <input id="fk-due" name="due_day" type="number" min="1" max="28" value="<?= e($old['due_day'] ?? '1') ?>">
            <?= $err('due_day') ?>
            <p class="field__hint">Tag im ersten Monat der Periode (z. B. „jährlich, am 15.“ → 15. Jänner).</p>
        </div>

        <div class="field field--sm">
            <label for="fk-method">Zahlungsart</label>
            <select id="fk-method" name="payment_method_id">
                <?php foreach ($methods as $method): ?>
                    <option value="<?= (int) $method['id'] ?>" <?= $method['kind'] === 'bank' ? 'selected' : '' ?>>
                        <?= e($method['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--sm">
            <label for="fk-since">seit (Monat)</label>
            <input id="fk-since" name="since" type="date" value="<?= e($old['since'] ?? date('Y-m-01')) ?>">
            <p class="field__hint">Ab diesem Monat wird gebucht (auch rückwirkend).</p>
        </div>

        <div class="field field--sm">
            <label for="fk-note">Notiz</label>
            <input id="fk-note" name="note" value="<?= e($old['note'] ?? '') ?>">
        </div>

        <label class="check">
            <input type="checkbox" name="active" value="1" checked> aktiv
        </label>

        <button class="btn btn--primary" type="submit">Anlegen</button>
    </form>
</div>
