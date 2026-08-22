<?php

use App\Core\Auth;
use App\Core\Flash;
use App\Models\FeeRepo;

/**
 * Beitragsarten pflegen (Betrag, Periode, Fälligkeitstag) inkl.
 * Betragsänderungen ab Stichtag mit Historie.
 *
 * @var list<array<string,mixed>>          $plans
 * @var array<string,array{0:int,1:string}> $intervals
 * @var array<int,list<array<string,mixed>>> $amountHistories
 * @var array<string,string>               $errors
 */
$old = Flash::oldInput();

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<p class="field__error">' . e($errors[$field]) . '</p>'
        : '';
};
?>
<div class="page-head">
    <div>
        <h1>Beitragsarten</h1>
        <p class="page-head__sub">
            Jede Beitragsart legt Betrag, Zahlungsperiode und Fälligkeitstag fest.
            Die Zuordnung zum Mitglied erfolgt im Mitgliedsformular.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/beitraege')) ?>">Zu den offenen Beiträgen</a>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Bestehende Beitragsarten</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Bezeichnung</th>
                <th class="num">Betrag</th>
                <th>Periode</th>
                <th>Fällig am</th>
                <th class="num">Mitglieder</th>
                <th>Status</th>
                <th>Notiz</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $plan): ?>
                <tr>
                    <td class="strong"><?= e($plan['name']) ?></td>
                    <td class="num"><?= e(format_money($plan['amount'])) ?></td>
                    <td><?= e(FeeRepo::intervalLabel((string) $plan['interval'])) ?></td>
                    <td>am <?= (int) $plan['due_day'] ?>.</td>
                    <td class="num"><?= (int) $plan['member_count'] ?></td>
                    <td>
                        <span class="pill pill--<?= (int) $plan['active'] === 1 ? 'aktiv' : 'inaktiv' ?>">
                            <?= (int) $plan['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td><?= e($plan['note']) ?></td>
                    <td class="row-actions">
                        <details class="plan-edit">
                            <summary class="linklike">bearbeiten</summary>
                            <form method="post" action="<?= e(url('/admin/beitragsarten/' . $plan['id'])) ?>" class="inline-form">
                                <?= csrf_field() ?>

                                <div class="field field--sm">
                                    <label>Bezeichnung</label>
                                    <input name="name" required value="<?= e($plan['name']) ?>">
                                </div>

                                <div class="field field--xs">
                                    <label>Betrag (€)</label>
                                    <input name="amount" type="number" step="0.01" min="0"
                                           value="<?= e(number_format((float) $plan['amount'], 2, '.', '')) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Periode</label>
                                    <select name="interval">
                                        <?php foreach ($intervals as $key => [, $label]): ?>
                                            <option value="<?= e($key) ?>" <?= $plan['interval'] === $key ? 'selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field field--xs">
                                    <label>Fällig am</label>
                                    <input name="due_day" type="number" min="1" max="28"
                                           value="<?= (int) $plan['due_day'] ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Notiz</label>
                                    <input name="note" value="<?= e($plan['note']) ?>">
                                </div>

                                <label class="check">
                                    <input type="checkbox" name="active" value="1"
                                        <?= (int) $plan['active'] === 1 ? 'checked' : '' ?>> aktiv
                                </label>

                                <button class="btn btn--sm" type="submit">Speichern</button>
                            </form>
                        </details>

                        <details class="plan-edit">
                            <summary class="linklike">Betrag ändern ab …</summary>

                            <?php $historie = $amountHistories[(int) $plan['id']] ?? []; ?>
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
                                                      action="<?= e(url('/admin/beitragsarten/' . $plan['id'] . '/betrag-loeschen')) ?>"
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

                            <form method="post" action="<?= e(url('/admin/beitragsarten/' . $plan['id'] . '/betrag')) ?>" class="inline-form">
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
                                Gilt für alle Mitglieder dieser Beitragsart ab dem Stichtag –
                                individuelle Abweichungen und bereits erzeugte Zeilen bleiben unverändert.
                            </p>
                        </details>

                        <?php if (Auth::isSuperuser()): ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('/admin/beitragsarten/' . $plan['id'] . '/loeschen')) ?>"
                                  data-confirm="Beitragsart &quot;<?= e($plan['name']) ?>&quot; löschen?">
                                <?= csrf_field() ?>
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($plans === []): ?>
                <tr><td colspan="8" class="empty">Noch keine Beitragsart angelegt.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Neue Beitragsart</h2>
    </div>

    <form method="post" action="<?= e(url('/admin/beitragsarten')) ?>" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--sm">
            <label for="np-name">Bezeichnung *</label>
            <input id="np-name" name="name" required value="<?= e($old['name'] ?? '') ?>"
                   placeholder="z. B. Monatsbeitrag Erwachsene">
            <?= $err('name') ?>
        </div>

        <div class="field field--xs">
            <label for="np-amount">Betrag (€)</label>
            <input id="np-amount" name="amount" type="number" step="0.01" min="0"
                   value="<?= e($old['amount'] ?? '50.00') ?>">
            <?= $err('amount') ?>
        </div>

        <div class="field field--sm">
            <label for="np-interval">Periode</label>
            <select id="np-interval" name="interval">
                <?php foreach ($intervals as $key => [, $label]): ?>
                    <option value="<?= e($key) ?>" <?= ($old['interval'] ?? 'monatlich') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= $err('interval') ?>
        </div>

        <div class="field field--xs">
            <label for="np-due">Fällig am</label>
            <input id="np-due" name="due_day" type="number" min="1" max="28"
                   value="<?= e($old['due_day'] ?? '5') ?>">
            <?= $err('due_day') ?>
        </div>

        <div class="field field--sm">
            <label for="np-note">Notiz</label>
            <input id="np-note" name="note" value="<?= e($old['note'] ?? '') ?>">
        </div>

        <label class="check">
            <input type="checkbox" name="active" value="1" checked> aktiv
        </label>

        <button class="btn btn--primary" type="submit">Anlegen</button>
    </form>

    <p class="field__hint">
        Der Fälligkeitstag gilt für den ersten Monat der jeweiligen Periode
        (z. B. „quartalsweise, fällig am 5.“ → 5. Jänner, 5. April, 5. Juli, 5. Oktober).
        Möglich sind die Tage 1 bis 28, damit jeder Monat funktioniert.
    </p>
</div>
