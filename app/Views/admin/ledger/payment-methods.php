<?php

use App\Core\Auth;
use App\Core\Flash;

/**
 * Zahlungsarten: Bank und Barkassa sind fix (geschützt), weitere frei.
 * Bei Typ "Bank" werden die Bankdaten (IBAN usw.) mitgeführt.
 *
 * @var list<array<string,mixed>> $methods
 * @var array<string,string>      $errors
 */
$old = Flash::oldInput();

$kindLabel = static fn (string $kind): string => match ($kind) {
    'bar'    => 'Barkassa',
    'bank'   => 'Bank',
    'online' => 'Online (PayPal …)',
    default  => 'Sonstige',
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
        <h1>Zahlungsarten</h1>
        <p class="page-head__sub">
            Jede Buchung (Einnahme wie Auszahlung) wird einer Zahlungsart zugeordnet.
            <strong>Bank</strong> und <strong>Barkassa</strong> sind Standard und nicht löschbar.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung')) ?>">Zur Buchhaltung</a>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Bestehende Zahlungsarten</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Bezeichnung</th>
                <th>Typ</th>
                <th>Bankdaten</th>
                <th>Status</th>
                <th>Notiz</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($methods as $method): ?>
                <tr>
                    <td class="strong">
                        <?= e($method['name']) ?>
                        <?php if ((int) $method['protected'] === 1): ?>
                            <span class="badge" title="Standard – immer vorhanden">Standard</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($kindLabel((string) $method['kind'])) ?></td>
                    <td>
                        <?php if ((string) $method['kind'] === 'bank'): ?>
                            <?php if ((string) $method['iban'] !== ''): ?>
                                <?= e($method['account_holder']) ?><br>
                                <span class="mono"><?= e(trim(chunk_split((string) $method['iban'], 4, ' '))) ?></span>
                                <?= (string) $method['bic'] !== '' ? '· ' . e($method['bic']) : '' ?>
                                <?= (string) $method['bank_name'] !== '' ? '<br>' . e($method['bank_name']) : '' ?>
                            <?php else: ?>
                                <span class="muted">noch keine Bankdaten hinterlegt</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pill pill--<?= (int) $method['active'] === 1 ? 'aktiv' : 'inaktiv' ?>">
                            <?= (int) $method['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td><?= e($method['note']) ?></td>
                    <td class="row-actions">
                        <details class="plan-edit">
                            <summary class="linklike">bearbeiten</summary>
                            <form method="post" action="<?= e(url('/admin/buchhaltung/zahlungsarten/' . $method['id'])) ?>" class="inline-form">
                                <?= csrf_field() ?>

                                <div class="field field--sm">
                                    <label>Bezeichnung</label>
                                    <input name="name" required value="<?= e($method['name']) ?>"
                                        <?= (int) $method['protected'] === 1 ? 'readonly' : '' ?>>
                                </div>

                                <div class="field field--sm">
                                    <label>Typ</label>
                                    <select name="kind" <?= (int) $method['protected'] === 1 ? 'disabled' : '' ?>>
                                        <?php foreach (['bar', 'bank', 'online', 'sonstig'] as $kind): ?>
                                            <option value="<?= e($kind) ?>" <?= $method['kind'] === $kind ? 'selected' : '' ?>>
                                                <?= e($kindLabel($kind)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field field--sm">
                                    <label>Kontoinhaber</label>
                                    <input name="account_holder" value="<?= e($method['account_holder']) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>IBAN</label>
                                    <input name="iban" value="<?= e($method['iban']) ?>" placeholder="AT..">
                                </div>

                                <div class="field field--xs">
                                    <label>BIC</label>
                                    <input name="bic" value="<?= e($method['bic']) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Bankname</label>
                                    <input name="bank_name" value="<?= e($method['bank_name']) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Notiz</label>
                                    <input name="note" value="<?= e($method['note']) ?>">
                                </div>

                                <?php if ((int) $method['protected'] !== 1): ?>
                                    <label class="check">
                                        <input type="checkbox" name="active" value="1" <?= (int) $method['active'] === 1 ? 'checked' : '' ?>> aktiv
                                    </label>
                                <?php endif; ?>

                                <button class="btn btn--sm" type="submit">Speichern</button>
                            </form>
                            <p class="field__hint">Bankdaten sind nur beim Typ „Bank“ relevant.</p>
                        </details>

                        <?php if (Auth::isSuperuser() && (int) $method['protected'] !== 1): ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('/admin/buchhaltung/zahlungsarten/' . $method['id'] . '/loeschen')) ?>"
                                  data-confirm="Zahlungsart &quot;<?= e($method['name']) ?>&quot; löschen?">
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

<div class="card">
    <div class="card__head">
        <h2>Neue Zahlungsart</h2>
    </div>

    <form method="post" action="<?= e(url('/admin/buchhaltung/zahlungsarten')) ?>" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--sm">
            <label for="pm-name">Bezeichnung *</label>
            <input id="pm-name" name="name" required value="<?= e($old['name'] ?? '') ?>"
                   placeholder="z. B. Klarna, Zweitkonto">
        </div>

        <div class="field field--sm">
            <label for="pm-kind">Typ</label>
            <select id="pm-kind" name="kind">
                <option value="sonstig">Sonstige</option>
                <option value="bank">Bank</option>
                <option value="online">Online (PayPal …)</option>
                <option value="bar">Barkassa</option>
            </select>
        </div>

        <div class="field field--sm">
            <label for="pm-holder">Kontoinhaber</label>
            <input id="pm-holder" name="account_holder" value="<?= e($old['account_holder'] ?? '') ?>">
        </div>

        <div class="field field--sm">
            <label for="pm-iban">IBAN</label>
            <input id="pm-iban" name="iban" value="<?= e($old['iban'] ?? '') ?>" placeholder="AT..">
        </div>

        <div class="field field--xs">
            <label for="pm-bic">BIC</label>
            <input id="pm-bic" name="bic" value="<?= e($old['bic'] ?? '') ?>">
        </div>

        <div class="field field--sm">
            <label for="pm-bank">Bankname</label>
            <input id="pm-bank" name="bank_name" value="<?= e($old['bank_name'] ?? '') ?>">
        </div>

        <div class="field field--sm">
            <label for="pm-note">Notiz</label>
            <input id="pm-note" name="note" value="<?= e($old['note'] ?? '') ?>">
        </div>

        <label class="check">
            <input type="checkbox" name="active" value="1" checked> aktiv
        </label>

        <button class="btn btn--primary" type="submit">Anlegen</button>
    </form>
    <p class="field__hint">Die Bankdaten-Felder (IBAN, BIC …) nur beim Typ „Bank“ ausfüllen.</p>
</div>
