<?php

use App\Core\Auth;

/**
 * @var array<string,mixed>       $member
 * @var list<array<string,mixed>> $sections
 * @var list<string>              $gemeinden
 * @var array<string,string>      $errors
 * @var list<array<string,mixed>> $fees
 * @var list<array<string,mixed>> $guardians   (nur Bestand)
 * @var list<array<string,mixed>> $wardsOf     (nur Bestand)
 * @var list<array<string,mixed>> $pauses      (nur Bestand)
 * @var list<array<string,mixed>> $amountHistory (nur Bestand)
 * @var list<array<string,mixed>> $invoices    (nur Bestand)
 * @var list<array<string,mixed>> $methods     (nur Bestand)
 * @var list<string>              $invoiceCategories (nur Bestand)
 * @var list<array<string,mixed>> $files       (nur Bestand)
 * @var array<string,mixed>|null  $photo       (nur Bestand)
 * @var list<array<string,mixed>> $ledger      (nur Bestand)
 * @var bool                      $hasEnrollmentFee
 * @var float                     $enrollmentFeeDefault
 * @var bool                      $askEnrollment
 * @var bool                      $isNew
 * @var bool                      $canEdit
 * @var list<float>               $feeOptions
 */
$id     = (int) ($member['id'] ?? 0);
$action = $isNew ? url('/admin/mitglieder') : url('/admin/mitglieder/' . $id);

/** Gibt die Fehlermeldung eines Feldes aus. */
$err = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<p class="field__error">' . e($errors[$field]) . '</p>'
        : '';
};

$disabled = $canEdit ? '' : ' disabled';
?>
<div class="page-head">
    <?php if (!$isNew && ($photo ?? null) !== null): ?>
        <img class="member-photo"
             src="<?= e(url('/admin/mitglieder/' . $id . '/datei/' . $photo['id'])) ?>"
             alt="Profilbild" width="72" height="72">
    <?php endif; ?>
    <div>
        <h1>
            <?= $isNew ? 'Neues Mitglied' : e($member['first_name'] . ' ' . $member['last_name']) ?>
            <?php if (!$isNew && (int) ($member['is_trainer'] ?? 0) === 1): ?>
                <span class="badge badge--gold">Trainer</span>
            <?php endif; ?>
            <?php if (!$isNew && ($member['archived_at'] ?? null) !== null): ?>
                <span class="badge badge--muted">ehemaliges Mitglied</span>
            <?php endif; ?>
        </h1>
        <?php if (!$isNew): ?>
            <p class="page-head__sub">
                <?= e($member['section_name'] ?? '') ?>
                <?php if ((string) ($member['updated_at'] ?? '') !== ''): ?>
                    · zuletzt geändert <?= e(format_datetime((string) $member['updated_at'])) ?>
                <?php endif; ?>
                <?php if (($linkedUser ?? null) !== null): ?>
                    · Benutzerkonto:
                    <?php if (Auth::isSuperuser()): ?>
                        <a href="<?= e(url('/admin/benutzer/' . (int) $linkedUser['id'])) ?>"><?= e($linkedUser['username']) ?></a>
                    <?php else: ?>
                        <?= e($linkedUser['username']) ?>
                    <?php endif; ?>
                    (<?= e(Auth::ROLES[$linkedUser['role']] ?? $linkedUser['role']) ?><?= (int) $linkedUser['active'] !== 1 ? ', gesperrt' : '' ?>)
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="page-head__actions">
        <?php if (!$isNew): ?>
            <a class="btn" href="<?= e(url('/admin/mitglieder/' . $id . '/erfolge')) ?>">Erfolge &amp; Wettkämpfe</a>
            <a class="btn" href="<?= e(url('/admin/mitglieder/' . $id . '/entwicklung')) ?>">Entwicklung</a>
        <?php endif; ?>
        <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder')) ?>">Zur Liste</a>
    </div>
</div>

<?php if (isset($errors['duplicate'])): ?>
    <div class="notice notice--warn">
        <?= e($errors['duplicate']) ?>
    </div>
<?php endif; ?>

<?php if (!$isNew && (int) $member['delete_requested'] === 1): ?>
    <div class="notice notice--warn">
        <strong>Zum Löschen vorgemerkt.</strong>
        <?php if ((string) $member['delete_reason'] !== ''): ?>
            Grund: <?= e($member['delete_reason']) ?>.
        <?php endif; ?>
        <?php if ((string) ($member['delete_requested_at'] ?? '') !== ''): ?>
            Vorgemerkt am <?= e(format_datetime((string) $member['delete_requested_at'])) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$isNew && ($member['deleted_at'] ?? null) !== null): ?>
    <div class="notice notice--warn">
        Dieser Datensatz liegt im Papierkorb (seit <?= e(format_datetime((string) $member['deleted_at'])) ?>).
    </div>
<?php endif; ?>

<?php if (!$isNew && ($member['archived_at'] ?? null) !== null): ?>
    <div class="notice notice--warn">
        <strong>Ehemaliges Mitglied</strong> – archiviert am
        <?= e(format_datetime((string) $member['archived_at'])) ?>.
        Es entstehen keine Beiträge mehr; Historie und Erfolge bleiben erhalten.
        <?php if (Auth::canWrite()): ?>
            <form method="post" class="inline" action="<?= e(url('/admin/mitglieder/' . $id . '/reaktivieren')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">
                <button class="btn btn--sm" type="submit">Reaktivieren</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($isNew): ?>
    <div class="card">
        <div class="card__head"><h2>🤖 Formular einlesen</h2></div>

        <?php if (!($hasScanKey ?? false)): ?>
            <p class="muted">
                Ausgefüllte Mitgliedsformulare (Foto oder PDF) können automatisch
                ausgelesen werden. Dazu in den
                <a href="<?= e(url('/admin/einstellungen')) ?>">Einstellungen</a>
                einen Anthropic API-Schlüssel hinterlegen.
            </p>
        <?php else: ?>
            <form method="post" action="<?= e(url('/admin/mitglieder/formular-scan')) ?>"
                  enctype="multipart/form-data" class="inline-form">
                <?= csrf_field() ?>
                <div class="field field--grow">
                    <label for="scan-file">Foto oder PDF des ausgefüllten Formulars</label>
                    <input id="scan-file" name="scan_file" type="file" required
                           accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,application/pdf,image/*">
                </div>
                <button class="btn" type="submit"
                        onclick="if(!this.form.reportValidity())return false;this.textContent='Wird ausgelesen …';this.disabled=true;this.form.submit();">
                    Felder erkennen
                </button>
            </form>
            <p class="field__hint">
                Die erkannten Werte werden unten eingetragen und müssen vor dem
                Speichern kontrolliert werden. Das Formular wird zur Auswertung an
                die Claude API (Anthropic) übertragen.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>
    <?php if (isset($errors['duplicate'])): ?>
        <input type="hidden" name="confirm_duplicate" value="1">
    <?php endif; ?>

    <div class="form-grid">
        <fieldset class="card">
            <legend>Person</legend>

            <div class="field-row">
                <div class="field">
                    <label for="first_name">Vorname *</label>
                    <input id="first_name" name="first_name" required<?= $disabled ?>
                           value="<?= e($member['first_name']) ?>">
                    <?= $err('first_name') ?>
                </div>

                <div class="field">
                    <label for="last_name">Zuname *</label>
                    <input id="last_name" name="last_name" required<?= $disabled ?>
                           value="<?= e($member['last_name']) ?>">
                    <?= $err('last_name') ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="birthdate">Geburtsdatum</label>
                    <input id="birthdate" name="birthdate" type="date"<?= $disabled ?>
                           value="<?= e($member['birthdate']) ?>">
                    <?= $err('birthdate') ?>
                </div>

                <div class="field">
                    <label for="gender">Geschlecht</label>
                    <select id="gender" name="gender"<?= $disabled ?>>
                        <option value="unbekannt" <?= $member['gender'] === 'unbekannt' ? 'selected' : '' ?>>ohne Angabe</option>
                        <option value="w" <?= $member['gender'] === 'w' ? 'selected' : '' ?>>weiblich</option>
                        <option value="m" <?= $member['gender'] === 'm' ? 'selected' : '' ?>>männlich</option>
                        <option value="d" <?= $member['gender'] === 'd' ? 'selected' : '' ?>>divers</option>
                    </select>
                </div>

                <div class="field">
                    <label for="member_no">Mitgliedsnummer</label>
                    <input id="member_no" name="member_no"<?= $disabled ?> value="<?= e($member['member_no']) ?>">
                </div>
            </div>
        </fieldset>

        <fieldset class="card">
            <legend>Adresse</legend>

            <div class="field">
                <label for="street">Straße und Hausnummer</label>
                <input id="street" name="street"<?= $disabled ?> value="<?= e($member['street']) ?>">
            </div>

            <div class="field-row">
                <div class="field field--xs">
                    <label for="zip">PLZ</label>
                    <input id="zip" name="zip" inputmode="numeric"<?= $disabled ?> value="<?= e($member['zip']) ?>">
                </div>

                <div class="field field--grow">
                    <label for="city">Ort</label>
                    <input id="city" name="city"<?= $disabled ?> value="<?= e($member['city']) ?>">
                </div>
            </div>

            <div class="field-row">
                <div class="field field--grow">
                    <label for="gemeinde">Gemeinde <small>(maßgeblich für die Abrechnung)</small></label>
                    <input id="gemeinde" name="gemeinde" list="gemeinde-list"<?= $disabled ?>
                           value="<?= e($member['gemeinde']) ?>">
                    <datalist id="gemeinde-list">
                        <?php foreach ($gemeinden as $gemeinde): ?>
                            <option value="<?= e($gemeinde) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="field field--xs">
                    <label for="country">Land</label>
                    <input id="country" name="country" maxlength="2"<?= $disabled ?> value="<?= e($member['country']) ?>">
                </div>
            </div>
        </fieldset>

        <fieldset class="card">
            <legend>Kontakt</legend>

            <div class="field">
                <label for="email">E-Mail</label>
                <input id="email" name="email" type="email"<?= $disabled ?> value="<?= e($member['email']) ?>">
                <?= $err('email') ?>
            </div>

            <div class="field">
                <label for="phone">Telefon</label>
                <input id="phone" name="phone" type="tel"<?= $disabled ?> value="<?= e($member['phone']) ?>">
            </div>
        </fieldset>

        <fieldset class="card">
            <legend>Mitgliedschaft</legend>

            <?php if ($isNew): ?>
                <?php
                // Vorbelegung: alte Eingabe nach Validierungsfehler, sonst erste Sektion.
                $gewaehlt = array_map('intval', (array) ($member['section_ids'] ?? []));
                if ($gewaehlt === [] && $sections !== []) {
                    $gewaehlt = [(int) $sections[0]['id']];
                }
                ?>
                <div class="field">
                    <label>Sektionen * <small>(Mehrfachauswahl möglich)</small></label>
                    <div class="checkbox-grid">
                        <?php foreach ($sections as $section): ?>
                            <label class="check">
                                <input type="checkbox" name="section_ids[]" value="<?= (int) $section['id'] ?>"
                                    <?= in_array((int) $section['id'], $gewaehlt, true) ? 'checked' : '' ?>>
                                <?= e($section['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= $err('section_ids') ?>
                    <p class="field__hint">Sektionen lassen sich auch nach dem Anlegen ergänzen oder entfernen.</p>
                </div>

                <div class="field field--xs">
                    <label for="fee_amount">Beitrag je gewählter Sektion</label>
                    <select id="fee_amount" name="fee_amount">
                        <?php foreach ($feeOptions as $wert): ?>
                            <option value="<?= e(number_format($wert, 2, '.', '')) ?>"><?= e(format_money($wert)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field__hint">Der Sektionsbeitrag lässt sich später je Sektion anpassen.</p>
                </div>
            <?php endif; ?>

            <div class="field-row">
                <div class="field field--xs">
                    <label for="status">Status der Person</label>
                    <select id="status" name="status"<?= $disabled ?>>
                        <option value="aktiv"   <?= $member['status'] === 'aktiv' ? 'selected' : '' ?>>aktiv</option>
                        <option value="inaktiv" <?= $member['status'] === 'inaktiv' ? 'selected' : '' ?>>inaktiv</option>
                    </select>
                </div>

                <div class="field field--sm">
                    <label for="joined_on">Eintritt</label>
                    <input id="joined_on" name="joined_on" type="date"<?= $disabled ?>
                           value="<?= e((string) ($member['joined_on'] ?? '')) ?>">
                </div>

                <div class="field field--sm">
                    <label for="left_on">Austritt am</label>
                    <input id="left_on" name="left_on" type="date"<?= $disabled ?>
                           value="<?= e((string) ($member['left_on'] ?? '')) ?>">
                    <?= $err('left_on') ?>
                    <p class="field__hint">Ab diesem Datum entstehen keine Beiträge mehr.</p>
                </div>
            </div>

            <label class="check">
                <input type="checkbox" name="is_trainer" value="1"
                    <?= (int) ($member['is_trainer'] ?? 0) === 1 ? 'checked' : '' ?><?= $disabled ?>>
                ist Trainer:in
            </label>

            <div class="field-row">
                <div class="field field--grow">
                    <label for="fee_plan_id">Beitragsart</label>
                    <select id="fee_plan_id" name="fee_plan_id"<?= $disabled ?>>
                        <option value="">– kein laufender Beitrag –</option>
                        <?php foreach ($feePlans as $plan): ?>
                            <option value="<?= (int) $plan['id'] ?>"
                                <?= (string) ($member['fee_plan_id'] ?? '') === (string) $plan['id'] ? 'selected' : '' ?>
                                <?= (int) $plan['active'] !== 1 ? ' disabled' : '' ?>>
                                <?= e($plan['name']) ?>
                                (<?= e(format_money($plan['amount'])) ?>
                                <?= e(\App\Models\FeeRepo::intervalLabel((string) $plan['interval'])) ?>,
                                fällig am <?= (int) $plan['due_day'] ?>.)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('fee_plan_id') ?>
                    <p class="field__hint">
                        Steuert die automatisch erzeugten Beitragszeilen in der Historie unten.
                        Beitragsarten werden unter <a href="<?= e(url('/admin/beitragsarten')) ?>">Beitragsarten</a> gepflegt.
                    </p>
                </div>

                <div class="field field--sm">
                    <label for="fee_since">beitragspflichtig ab</label>
                    <input id="fee_since" name="fee_since" type="date"<?= $disabled ?>
                           value="<?= e((string) ($member['fee_since'] ?? '')) ?>">
                    <?= $err('fee_since') ?>
                    <p class="field__hint">Leer = ab Eintrittsdatum.</p>
                </div>
            </div>

            <div class="field-row">
                <div class="field field--sm">
                    <label for="fee_amount_override">Betrag abweichend (€)</label>
                    <input id="fee_amount_override" name="fee_amount_override" type="number" step="0.01" min="0"<?= $disabled ?>
                           value="<?= e($member['fee_amount_override'] !== null && $member['fee_amount_override'] !== ''
                               ? number_format((float) $member['fee_amount_override'], 2, '.', '') : '') ?>">
                    <?= $err('fee_amount_override') ?>
                    <p class="field__hint">Leer = Betrag laut Beitragsart.</p>
                </div>

                <div class="field field--sm">
                    <label for="fee_due_day_override">Fälligkeitstag abweichend</label>
                    <input id="fee_due_day_override" name="fee_due_day_override" type="number" min="1" max="28"<?= $disabled ?>
                           value="<?= e((string) ($member['fee_due_day_override'] ?? '')) ?>">
                    <?= $err('fee_due_day_override') ?>
                    <p class="field__hint">Leer = Tag laut Beitragsart.</p>
                </div>
            </div>

            <div class="field">
                <label for="notes">Notizen</label>
                <textarea id="notes" name="notes" rows="3"<?= $disabled ?>><?= e($member['notes']) ?></textarea>
            </div>
        </fieldset>
    </div>

    <?php if ($canEdit): ?>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit"><?= $isNew ? 'Mitglied anlegen' : 'Änderungen speichern' ?></button>
            <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder')) ?>">Abbrechen</a>
        </div>
    <?php else: ?>
        <p class="muted">Ihre Rolle erlaubt nur das Ansehen der Stammdaten.</p>
    <?php endif; ?>
</form>

<?php if (!$isNew): ?>
    <div class="card">
        <div class="card__head">
            <h2>Sektionen</h2>
            <p class="muted">In jeder Sektion ist der dort eingetragene Beitrag zu zahlen.</p>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Sektion</th><th class="num">Beitrag</th><th>Kategorie</th><th>Status</th><th>Eintritt</th><th></th></tr>
                </thead>
                <tbody>
                <?php $summeBeitrag = 0.0; ?>
                <?php foreach (($member['memberships'] ?? []) as $ms): ?>
                    <?php $summeBeitrag += (float) $ms['fee_amount']; ?>
                    <tr>
                        <td class="strong">
                            <?= e($ms['section_name']) ?>
                            <?php if ((int) $ms['fee_free'] === 1): ?>
                                <span class="badge">beitragsfrei</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e(format_money($ms['fee_amount'])) ?></td>
                        <td><?= e($ms['fee_category']) ?></td>
                        <td><span class="pill pill--<?= e($ms['status']) ?>"><?= e($ms['status']) ?></span></td>
                        <td><?= e(format_date($ms['joined_on'] === null ? null : (string) $ms['joined_on'])) ?></td>
                        <td class="row-actions">
                            <?php if ($canEdit && Auth::canAccessSection((int) $ms['section_id'])): ?>
                                <form method="post" class="inline"
                                      action="<?= e(url('/admin/mitglieder/' . $id . '/mitgliedschaft-loeschen')) ?>"
                                      data-confirm="Mitgliedschaft in <?= e($ms['section_name']) ?> entfernen?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="section_id" value="<?= (int) $ms['section_id'] ?>">
                                    <button class="linklike linklike--danger" type="submit">entfernen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (($member['memberships'] ?? []) === []): ?>
                    <tr><td colspan="6" class="empty">Noch keiner Sektion zugeordnet.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr><th>Summe</th><th class="num"><?= e(format_money($summeBeitrag)) ?></th><th colspan="4"></th></tr>
                </tfoot>
            </table>
        </div>

        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/mitgliedschaft')) ?>" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--sm">
                    <label for="ms-section">Sektion</label>
                    <select id="ms-section" name="section_id" required>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= (int) $section['id'] ?>"><?= e($section['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field field--xs">
                    <label for="ms-fee">Beitrag</label>
                    <select id="ms-fee" name="fee_amount">
                        <?php foreach ($feeOptions as $wert): ?>
                            <option value="<?= e(number_format($wert, 2, '.', '')) ?>"><?= e(format_money($wert)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field field--xs">
                    <label for="ms-cat">Kategorie</label>
                    <input id="ms-cat" name="fee_category" list="fee-categories">
                    <datalist id="fee-categories">
                        <option value="Kind"></option><option value="Jugend"></option>
                        <option value="Erwachsen"></option><option value="Familie"></option>
                        <option value="Ermäßigt"></option><option value="Ehrenmitglied"></option>
                    </datalist>
                </div>

                <div class="field field--xs">
                    <label for="ms-status">Status</label>
                    <select id="ms-status" name="status">
                        <option value="aktiv">aktiv</option>
                        <option value="inaktiv">inaktiv</option>
                    </select>
                </div>

                <div class="field field--xs">
                    <label for="ms-joined">Eintritt</label>
                    <input id="ms-joined" name="joined_on" type="date">
                </div>

                <button class="btn" type="submit">Sektion zuordnen</button>
            </form>
            <p class="field__hint">
                Ist die Sektion bereits zugeordnet, werden Beitrag und Status überschrieben.
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Beitragshistorie</h2>
            <p class="muted">
                Zeilen entstehen automatisch aus der Beitragsart des Mitglieds;
                zusätzliche Zeilen lassen sich unten manuell erfassen.
            </p>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr>
                    <th>Periode</th>
                    <th>Beitragsart</th>
                    <th>Fällig am</th>
                    <th class="num">Soll</th>
                    <th>Status</th>
                    <th class="num">Bezahlt</th>
                    <th>am</th>
                    <th>erfasst von</th>
                    <th>Notiz</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($fees as $fee): ?>
                    <?php $offen = (int) $fee['paid'] !== 1; ?>
                    <?php $ueberfaellig = $offen && (string) $fee['due_date'] < date('Y-m-d'); ?>
                    <tr<?= $ueberfaellig ? ' class="is-flagged"' : '' ?>>
                        <td class="strong"><?= e($fee['period_label']) ?></td>
                        <td><?= e($fee['plan_name'] ?? 'manuell') ?></td>
                        <td><?= e(format_date((string) $fee['due_date'])) ?></td>
                        <td class="num"><?= e(format_money($fee['amount'])) ?></td>
                        <td>
                            <?php if (!$offen): ?>
                                <span class="pill pill--aktiv">bezahlt</span>
                            <?php elseif ($ueberfaellig): ?>
                                <span class="pill pill--offen">überfällig</span>
                            <?php else: ?>
                                <span class="pill pill--offen">offen</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $fee['paid_amount'] !== null ? e(format_money($fee['paid_amount'])) : '' ?></td>
                        <td><?= e(format_date($fee['paid_on'] === null ? null : (string) $fee['paid_on'])) ?></td>
                        <td><?= e((string) ($fee['paid_by_name'] ?? '')) ?></td>
                        <td><?= e($fee['note']) ?></td>
                        <td class="row-actions">
                            <?php if (Auth::canManageFees()): ?>
                                <?php if ($offen): ?>
                                    <form method="post" action="<?= e(url('/admin/beitraege/bezahlt')) ?>" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="entry_id" value="<?= (int) $fee['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">
                                        <button class="linklike" type="submit">als bezahlt markieren</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('/admin/beitraege/offen')) ?>" class="inline"
                                          data-confirm="Zahlung <?= e($fee['period_label']) ?> wieder auf offen setzen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="entry_id" value="<?= (int) $fee['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">
                                        <button class="linklike" type="submit">wieder öffnen</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (Auth::is('superuser', 'kassier')): ?>
                                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/beitrag-loeschen')) ?>"
                                      class="inline" data-confirm="Beitragszeile <?= e($fee['period_label']) ?> löschen?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="entry_id" value="<?= (int) $fee['id'] ?>">
                                    <button class="linklike linklike--danger" type="submit">löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($fees === []): ?>
                    <tr>
                        <td colspan="10" class="empty">
                            Noch keine Beiträge erfasst.
                            <?php if (($member['fee_plan_id'] ?? null) === null): ?>
                                Oben eine Beitragsart zuordnen, dann entstehen die Zeilen automatisch.
                            <?php else: ?>
                                Die fälligen Zeilen entstehen beim nächsten Aufruf der Seite
                                <a href="<?= e(url('/admin/beitraege')) ?>">Beiträge</a>.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (Auth::canManageFees()): ?>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/beitrag')) ?>" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--sm">
                    <label for="fee-label">Bezeichnung</label>
                    <input id="fee-label" name="label" placeholder="z. B. Nachzahlung, Turnierbeitrag">
                </div>

                <div class="field field--sm">
                    <label for="fee-due">Fällig am</label>
                    <input id="fee-due" name="due_date" type="date" value="<?= e(date('Y-m-d')) ?>">
                </div>

                <div class="field field--xs">
                    <label for="fee-amount">Betrag (€)</label>
                    <input id="fee-amount" name="amount" type="number" step="0.01" min="0" value="0.00">
                </div>

                <div class="field field--xs">
                    <label for="fee-paid-on">Bezahlt am</label>
                    <input id="fee-paid-on" name="paid_on" type="date" value="<?= e(date('Y-m-d')) ?>">
                </div>

                <div class="field field--grow">
                    <label for="fee-note">Notiz</label>
                    <input id="fee-note" name="note">
                </div>

                <label class="check">
                    <input type="checkbox" name="paid" value="1"> bereits bezahlt
                </label>

                <button class="btn" type="submit">Beitragszeile erfassen</button>
            </form>
            <p class="field__hint">
                Manuelle Zeilen sind für Sonderfälle gedacht – der laufende Beitrag
                kommt aus der Beitragsart. Je Monat ist eine Zeile möglich.
            </p>
        <?php endif; ?>
    </div>

    <div class="form-grid">
        <div class="card">
            <div class="card__head">
                <h2>Beitrag aussetzen</h2>
                <?php $heute = date('Y-m-d'); ?>
                <?php foreach ($pauses as $pause): ?>
                    <?php if ((string) $pause['pause_from'] <= $heute && ((($pause['pause_to'] ?? null) === null || $pause['pause_to'] === '') || (string) $pause['pause_to'] >= $heute)): ?>
                        <span class="badge badge--warn">derzeit ausgesetzt</span>
                        <?php break; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="table-scroll">
                <table class="table table--compact">
                    <thead>
                    <tr><th>Von</th><th>Bis</th><th>Notiz</th><th>erfasst von</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pauses as $pause): ?>
                        <tr>
                            <td><?= e(format_date((string) $pause['pause_from'])) ?></td>
                            <td><?= ($pause['pause_to'] ?? null) !== null && $pause['pause_to'] !== '' ? e(format_date((string) $pause['pause_to'])) : 'bis auf Weiteres' ?></td>
                            <td><?= e($pause['note']) ?></td>
                            <td class="muted"><?= e((string) ($pause['created_by_name'] ?? '')) ?></td>
                            <td class="row-actions">
                                <?php if (Auth::canManageFees()): ?>
                                    <form method="post" class="inline"
                                          action="<?= e(url('/admin/mitglieder/' . $id . '/aussetzen-loeschen')) ?>"
                                          data-confirm="Beitragspause entfernen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="pause_id" value="<?= (int) $pause['id'] ?>">
                                        <button class="linklike linklike--danger" type="submit">entfernen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pauses === []): ?>
                        <tr><td colspan="5" class="empty">Keine Beitragspausen.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (Auth::canManageFees()): ?>
                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/aussetzen')) ?>" class="inline-form">
                    <?= csrf_field() ?>

                    <div class="field field--sm">
                        <label for="pause-from">Von</label>
                        <input id="pause-from" name="pause_from" type="date" required value="<?= e(date('Y-m-d')) ?>">
                    </div>

                    <div class="field field--sm">
                        <label for="pause-to">Bis <small>(leer = bis auf Weiteres)</small></label>
                        <input id="pause-to" name="pause_to" type="date">
                    </div>

                    <div class="field field--grow">
                        <label for="pause-note">Notiz</label>
                        <input id="pause-note" name="note" placeholder="z. B. Verletzung, Auslandsaufenthalt">
                    </div>

                    <button class="btn" type="submit">Aussetzen</button>
                </form>
                <p class="field__hint">Im Pausenzeitraum entstehen keine Beitragsfälligkeiten – das Mitglied bleibt aktiv.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>Erinnerungen</h2>
                <p class="muted">z. B. Ablauf der ärztlichen Untersuchung, Kampfpassverlängerung – fällige erscheinen auf der Übersicht.</p>
            </div>

            <div class="table-scroll">
                <table class="table table--compact">
                    <thead>
                    <tr><th>fällig am</th><th>Erinnerung</th><th>Notiz</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (($reminders ?? []) as $reminder): ?>
                        <?php
                        $faellig    = (string) $reminder['due_on'];
                        $erledigt   = (int) $reminder['done'] === 1;
                        $ueberfaellig = !$erledigt && $faellig < date('Y-m-d');
                        $bald       = !$erledigt && !$ueberfaellig && $faellig <= date('Y-m-d', strtotime('+30 days'));
                        ?>
                        <tr>
                            <td><?= e(format_date($faellig)) ?></td>
                            <td class="strong"><?= e($reminder['title']) ?></td>
                            <td><?= e($reminder['note']) ?></td>
                            <td>
                                <?php if ($erledigt): ?>
                                    <span class="badge badge--ok">erledigt</span>
                                <?php elseif ($ueberfaellig): ?>
                                    <span class="badge badge--danger">überfällig</span>
                                <?php elseif ($bald): ?>
                                    <span class="badge badge--warn">bald fällig</span>
                                <?php else: ?>
                                    <span class="badge">offen</span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <?php if ($canEdit): ?>
                                    <form method="post" class="inline"
                                          action="<?= e(url('/admin/mitglieder/' . $id . '/erinnerung-umschalten')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                                        <button class="linklike" type="submit"><?= $erledigt ? 'wieder öffnen' : 'erledigt' ?></button>
                                    </form>
                                    <form method="post" class="inline"
                                          action="<?= e(url('/admin/mitglieder/' . $id . '/erinnerung-loeschen')) ?>"
                                          data-confirm="Erinnerung löschen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                                        <button class="linklike linklike--danger" type="submit">löschen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (($reminders ?? []) === []): ?>
                        <tr><td colspan="5" class="empty">Keine Erinnerungen.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/erinnerung')) ?>" class="inline-form">
                    <?= csrf_field() ?>

                    <div class="field field--grow">
                        <label for="rem-title">Erinnerung *</label>
                        <input id="rem-title" name="title" required
                               placeholder="z. B. Ärztliche Untersuchung läuft ab, Kampfpassverlängerung">
                    </div>

                    <div class="field field--sm">
                        <label for="rem-due">fällig am *</label>
                        <input id="rem-due" name="due_on" type="date" required>
                    </div>

                    <div class="field field--grow">
                        <label for="rem-note">Notiz</label>
                        <input id="rem-note" name="note">
                    </div>

                    <button class="btn" type="submit">Erinnerung anlegen</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__head">
                <h2>Beitragsänderungen</h2>
                <p class="muted">Individueller Beitrag ab Stichtag – frühere Fälligkeiten bleiben unverändert.</p>
            </div>

            <div class="table-scroll">
                <table class="table table--compact">
                    <thead>
                    <tr><th>gültig ab</th><th class="num">Betrag</th><th>Notiz</th><th>erfasst von</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($amountHistory as $aenderung): ?>
                        <tr>
                            <td><?= e(format_date((string) $aenderung['valid_from'])) ?></td>
                            <td class="num"><?= e(format_money($aenderung['amount'])) ?></td>
                            <td><?= e($aenderung['note']) ?></td>
                            <td class="muted"><?= e((string) ($aenderung['created_by_name'] ?? '')) ?></td>
                            <td class="row-actions">
                                <?php if (Auth::canManageFees()): ?>
                                    <form method="post" class="inline"
                                          action="<?= e(url('/admin/mitglieder/' . $id . '/beitragsaenderung-loeschen')) ?>"
                                          data-confirm="Beitragsänderung entfernen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="history_id" value="<?= (int) $aenderung['id'] ?>">
                                        <button class="linklike linklike--danger" type="submit">entfernen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($amountHistory === []): ?>
                        <tr><td colspan="5" class="empty">Keine Änderungen – es gilt Beitragsart bzw. Abweichung.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (Auth::canManageFees()): ?>
                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/beitragsaenderung')) ?>" class="inline-form">
                    <?= csrf_field() ?>

                    <div class="field field--sm">
                        <label for="ac-from">gültig ab <small>(auch rückwirkend)</small></label>
                        <input id="ac-from" name="valid_from" type="date" required value="<?= e(date('Y-m-d')) ?>">
                    </div>

                    <div class="field field--xs">
                        <label for="ac-amount">neuer Beitrag (€)</label>
                        <input id="ac-amount" name="amount" type="number" step="0.01" min="0" required>
                    </div>

                    <div class="field field--grow">
                        <label for="ac-note">Notiz</label>
                        <input id="ac-note" name="note">
                    </div>

                    <button class="btn" type="submit">Beitrag ändern</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Erziehungsberechtigte</h2>
            <?php $alter = age_from($member['birthdate'] === null || $member['birthdate'] === '' ? null : (string) $member['birthdate']); ?>
            <?php if ($alter !== null && $alter < 18): ?>
                <span class="badge badge--warn">minderjährig (<?= (int) $alter ?> J.)</span>
            <?php endif; ?>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Name</th><th>Beziehung</th><th>Telefon</th><th>E-Mail</th><th>Notiz</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($guardians as $g): ?>
                    <?php $verlinkt = $g['guardian_member_id'] !== null; ?>
                    <tr>
                        <td class="strong">
                            <?php if ($verlinkt): ?>
                                <a href="<?= e(url('/admin/mitglieder/' . $g['guardian_member_id'])) ?>">
                                    <?= e($g['gm_first_name'] . ' ' . $g['gm_last_name']) ?>
                                </a>
                                <span class="badge" title="selbst Mitglied – Kontaktdaten kommen aus dem Mitgliedsdatensatz">Mitglied</span>
                            <?php else: ?>
                                <?= e($g['name']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($g['relation']) ?></td>
                        <td><?= $verlinkt ? tel_link((string) $g['gm_phone']) : tel_link((string) $g['phone']) ?></td>
                        <td><?= $verlinkt ? mail_link((string) $g['gm_email']) : mail_link((string) $g['email']) ?></td>
                        <td><?= e($g['note']) ?></td>
                        <td class="row-actions">
                            <?php if ($canEdit): ?>
                                <form method="post" class="inline"
                                      action="<?= e(url('/admin/mitglieder/' . $id . '/erziehungsberechtigt-loeschen')) ?>"
                                      data-confirm="Erziehungsberechtigte(n) entfernen?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="guardian_id" value="<?= (int) $g['id'] ?>">
                                    <button class="linklike linklike--danger" type="submit">entfernen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($guardians === []): ?>
                    <tr><td colspan="6" class="empty">Keine Erziehungsberechtigten erfasst.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/erziehungsberechtigt')) ?>" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--sm">
                    <label for="g-ref">Ist selbst Mitglied</label>
                    <input id="g-ref" name="guardian_ref" placeholder="Nr. oder Vorname Zuname">
                    <p class="field__hint">Verlinkt zum Mitglied – Kontaktdaten bleiben dort gepflegt.</p>
                </div>

                <div class="field field--sm">
                    <label for="g-name">… oder Name (extern)</label>
                    <input id="g-name" name="name" placeholder="z. B. Maria Muster">
                </div>

                <div class="field field--xs">
                    <label for="g-relation">Beziehung</label>
                    <input id="g-relation" name="relation" list="relation-list" placeholder="Mutter">
                    <datalist id="relation-list">
                        <option value="Mutter"></option><option value="Vater"></option>
                        <option value="Oma"></option><option value="Opa"></option>
                        <option value="Obsorge"></option>
                    </datalist>
                </div>

                <div class="field field--sm">
                    <label for="g-phone">Telefon</label>
                    <input id="g-phone" name="phone" type="tel">
                </div>

                <div class="field field--sm">
                    <label for="g-email">E-Mail</label>
                    <input id="g-email" name="email" type="email">
                </div>

                <div class="field field--grow">
                    <label for="g-note">Notiz</label>
                    <input id="g-note" name="note">
                </div>

                <button class="btn" type="submit">Erfassen</button>
            </form>
        <?php endif; ?>

        <?php if ($wardsOf !== []): ?>
            <p class="muted" style="margin-top:.8rem">
                Diese Person ist selbst erziehungsberechtigt für:
                <?php foreach ($wardsOf as $i => $ward): ?>
                    <?= $i > 0 ? ', ' : '' ?>
                    <a href="<?= e(url('/admin/mitglieder/' . $ward['id'])) ?>"><?= e($ward['first_name'] . ' ' . $ward['last_name']) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if (Auth::isSuperuser()): ?>
        <div class="card">
            <div class="card__head">
                <h2>Login-Zugang (Mitgliederbereich)</h2>
                <?php if ((int) ($member['can_login'] ?? 0) === 1): ?>
                    <span class="pill pill--aktiv">freigeschaltet</span>
                <?php else: ?>
                    <span class="pill pill--inaktiv">kein Zugang</span>
                <?php endif; ?>
            </div>

            <p class="muted">
                Mit dem Zugang sieht das Mitglied unter
                <a href="<?= e(url('/mitglied')) ?>" target="_blank" rel="noopener">/mitglied</a>
                die eigenen Daten, den Beitragsstatus und den Termin­kalender.
                Anmeldung per E-Mail-Adresse
                (<?= (string) $member['email'] !== '' ? e($member['email']) : '<strong>fehlt – bitte oben eintragen!</strong>' ?>).
                <?php if (($member['login_last_at'] ?? null) !== null): ?>
                    Zuletzt angemeldet: <?= e(format_datetime((string) $member['login_last_at'])) ?>.
                <?php endif; ?>
            </p>

            <div class="danger-actions">
                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/login-zugang')) ?>" class="inline"
                      <?= (int) ($member['can_login'] ?? 0) === 1 ? 'data-confirm="Neues Passwort erzeugen? Das alte wird ungültig."' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="freischalten">
                    <button class="btn" type="submit">
                        <?= (int) ($member['can_login'] ?? 0) === 1 ? 'Passwort neu erzeugen' : 'Login freischalten' ?>
                    </button>
                </form>

                <?php if ((int) ($member['can_login'] ?? 0) === 1): ?>
                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/login-zugang')) ?>" class="inline"
                          data-confirm="Login-Zugang entziehen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="sperren">
                        <button class="btn btn--ghost" type="submit">Zugang entziehen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__head">
            <h2>Dateien &amp; Profilbild</h2>
            <p class="muted">
                Anmeldeformular, Bestätigungen usw. – nicht öffentlich, abrufbar
                nur angemeldet über die Verwaltung.
            </p>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr>
                    <th>Datei</th>
                    <th>Tag</th>
                    <th>Beschreibung</th>
                    <th class="num">Größe</th>
                    <th>hochgeladen</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $datei): ?>
                    <tr>
                        <td class="strong">
                            <a href="<?= e(url('/admin/mitglieder/' . $id . '/datei/' . $datei['id'])) ?>"
                               target="_blank" rel="noopener"><?= e($datei['filename']) ?></a>
                            <?php if ((int) $datei['is_photo'] === 1): ?>
                                <span class="badge">Profilbild</span>
                            <?php endif; ?>
                        </td>
                        <td><?php if ((string) $datei['tag'] !== ''): ?><span class="badge"><?= e($datei['tag']) ?></span><?php endif; ?></td>
                        <td><?= e($datei['description']) ?></td>
                        <td class="num"><?= number_format((int) $datei['size'] / 1024, 0, ',', '.') ?> KB</td>
                        <td class="muted">
                            <?= e(format_datetime((string) $datei['created_at'])) ?>
                            <?= (string) ($datei['uploaded_by_name'] ?? '') !== '' ? '· ' . e($datei['uploaded_by_name']) : '' ?>
                        </td>
                        <td class="row-actions">
                            <?php if ($canEdit): ?>
                                <details class="plan-edit">
                                    <summary class="linklike">bearbeiten</summary>
                                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/datei-bearbeiten')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="file_id" value="<?= (int) $datei['id'] ?>">

                                        <div class="field field--sm">
                                            <label>Tag</label>
                                            <input name="tag" list="file-tags" value="<?= e($datei['tag']) ?>">
                                        </div>

                                        <div class="field field--grow">
                                            <label>Beschreibung</label>
                                            <input name="description" value="<?= e($datei['description']) ?>">
                                        </div>

                                        <button class="btn btn--sm" type="submit">Speichern</button>
                                    </form>
                                </details>

                                <form method="post" class="inline"
                                      action="<?= e(url('/admin/mitglieder/' . $id . '/datei-loeschen')) ?>"
                                      data-confirm="Datei „<?= e($datei['filename']) ?>“ endgültig löschen?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="file_id" value="<?= (int) $datei['id'] ?>">
                                    <button class="linklike linklike--danger" type="submit">löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($files === []): ?>
                    <tr><td colspan="6" class="empty">Noch keine Dateien hochgeladen.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/datei')) ?>"
                  enctype="multipart/form-data" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--grow">
                    <label for="mf-file">Datei (Bild, PDF, Office – max. 15 MB)</label>
                    <input id="mf-file" name="file" type="file" required
                           accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt,.csv">
                </div>

                <div class="field field--sm">
                    <label for="mf-tag">Tag</label>
                    <input id="mf-tag" name="tag" list="file-tags" placeholder="z. B. Mitgliedsformular">
                    <datalist id="file-tags">
                        <option value="Mitgliedsformular"></option>
                        <option value="Einverständniserklärung"></option>
                        <option value="Ärztliche Bestätigung"></option>
                        <option value="Wettkampf/Lizenz"></option>
                        <option value="Sonstiges"></option>
                    </datalist>
                </div>

                <div class="field field--grow">
                    <label for="mf-desc">Beschreibung</label>
                    <input id="mf-desc" name="description">
                </div>

                <label class="check">
                    <input type="checkbox" name="is_photo" value="1"> als Profilbild verwenden
                </label>

                <div class="field field--sm">
                    <label for="mf-remind">Erinnerung am <small>(optional)</small></label>
                    <input id="mf-remind" name="reminder_on" type="date">
                    <p class="field__hint">z. B. Ablauf der ärztlichen Untersuchung – erscheint auf der Übersicht.</p>
                </div>

                <div class="field field--grow">
                    <label for="mf-remind-title">Erinnerungstext <small>(leer = aus Tag/Beschreibung)</small></label>
                    <input id="mf-remind-title" name="reminder_title"
                           placeholder="z. B. Ärztliche Untersuchung läuft ab">
                </div>

                <button class="btn" type="submit">Hochladen</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Rechnungen</h2>
            <p class="muted">
                Freie Rechnungen an das Mitglied (z. B. Boxhandschuhe) – bezahlte
                Rechnungen landen automatisch in der Buchhaltung.
            </p>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr>
                    <th>Datum</th>
                    <th>Betreff</th>
                    <th>Kategorie</th>
                    <th class="num">Betrag</th>
                    <th>Status</th>
                    <th>bezahlt am</th>
                    <th>Zahlungsart</th>
                    <th>Notiz</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $offen = (int) $invoice['paid'] !== 1; ?>
                    <tr>
                        <td><?= e(format_date((string) $invoice['invoice_date'])) ?></td>
                        <td class="strong"><?= e($invoice['text']) ?></td>
                        <td><span class="badge"><?= e($invoice['category']) ?></span></td>
                        <td class="num"><?= e(format_money($invoice['amount'])) ?></td>
                        <td>
                            <?php if ($offen): ?>
                                <span class="pill pill--offen">offen</span>
                            <?php else: ?>
                                <span class="pill pill--aktiv">bezahlt</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date($invoice['paid_on'] === null ? null : (string) $invoice['paid_on'])) ?></td>
                        <td><?= e((string) ($invoice['payment_method_name'] ?? '')) ?></td>
                        <td><?= e($invoice['note']) ?></td>
                        <td class="row-actions">
                            <?php if (Auth::canManageFees()): ?>
                                <?php if ($offen): ?>
                                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/rechnung-bezahlt')) ?>" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                        <span class="fee-quick">
                                            <input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>" aria-label="bezahlt am">
                                            <select name="payment_method_id" aria-label="Zahlungsart">
                                                <?php foreach ($methods as $method): ?>
                                                    <option value="<?= (int) $method['id'] ?>" <?= $method['kind'] === 'bar' ? 'selected' : '' ?>>
                                                        <?= e($method['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn--sm" type="submit">bezahlt ✓</button>
                                        </span>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/rechnung-offen')) ?>" class="inline"
                                          data-confirm="Zahlung wieder öffnen? Die Buchung wird entfernt.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                        <button class="linklike" type="submit">wieder öffnen</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/rechnung-loeschen')) ?>" class="inline"
                                      data-confirm="Rechnung „<?= e($invoice['text']) ?>“ löschen?<?= $offen ? '' : ' Die Buchung wird ebenfalls entfernt.' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                    <button class="linklike linklike--danger" type="submit">löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($invoices === []): ?>
                    <tr><td colspan="9" class="empty">Keine Rechnungen.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (Auth::canManageFees()): ?>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/rechnung')) ?>" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--sm">
                    <label for="inv-date">Datum</label>
                    <input id="inv-date" name="invoice_date" type="date" value="<?= e(date('Y-m-d')) ?>">
                </div>

                <div class="field field--grow">
                    <label for="inv-text">Wofür *</label>
                    <input id="inv-text" name="text" required placeholder="z. B. Boxhandschuhe">
                </div>

                <div class="field field--sm">
                    <label for="inv-cat">Kategorie</label>
                    <input id="inv-cat" name="category" list="invoice-categories" value="Verkauf">
                    <datalist id="invoice-categories">
                        <?php foreach ($invoiceCategories as $kategorie): ?>
                            <option value="<?= e($kategorie) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="field field--xs">
                    <label for="inv-amount">Betrag (€) *</label>
                    <input id="inv-amount" name="amount" type="number" step="0.01" min="0" required>
                </div>

                <div class="field field--grow">
                    <label for="inv-note">Notiz</label>
                    <input id="inv-note" name="note">
                </div>

                <button class="btn" type="submit">Rechnung erfassen</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Buchungen (Kassabuch)</h2>
            <div>
                <?php if (!$hasEnrollmentFee): ?>
                    <span class="badge badge--warn" title="In der Buchhaltung ist keine Einschreibegebühr für dieses Mitglied erfasst.">keine Einschreibegebühr erfasst</span>
                <?php endif; ?>
                <?php if (Auth::canManageFees()): ?>
                    <a class="btn btn--sm" href="<?= e(url('/admin/mitglieder/' . $id, ['einschreiben' => '1'])) ?>">Einschreibegebühr erfassen</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Datum</th><th>Kategorie</th><th>Betreff</th><th class="num">Einnahme</th><th class="num">Ausgabe</th><th>erfasst von</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ledger as $buchung): ?>
                    <tr>
                        <td><?= e(format_date((string) $buchung['booked_on'])) ?></td>
                        <td><span class="badge"><?= e($buchung['category']) ?></span></td>
                        <td><?= e($buchung['text']) ?></td>
                        <td class="num is-plus"><?= $buchung['type'] === 'einnahme' ? e(format_money($buchung['amount'])) : '' ?></td>
                        <td class="num is-minus"><?= $buchung['type'] === 'ausgabe' ? e(format_money($buchung['amount'])) : '' ?></td>
                        <td class="muted"><?= e((string) ($buchung['created_by_name'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($ledger === []): ?>
                    <tr><td colspan="6" class="empty">Noch keine Buchungen zu diesem Mitglied.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($askEnrollment): ?>
        <div class="modal-backdrop" id="enrollment-modal">
            <div class="modal">
                <h2>Einschreibegebühr erfassen</h2>
                <p>
                    Für <strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                    <?php if ($hasEnrollmentFee): ?>
                        wurde bereits einmal eine Einschreibegebühr gebucht –
                        bei einem Wiedereintritt fällt sie erneut an.
                    <?php else: ?>
                        ist noch keine Einschreibegebühr erfasst.
                    <?php endif; ?>
                </p>

                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/einschreibegebuehr')) ?>">
                    <?= csrf_field() ?>

                    <div class="field-row">
                        <div class="field field--xs">
                            <label for="ef-amount">Betrag (€)</label>
                            <input id="ef-amount" name="amount" type="number" step="0.01" min="0"
                                   value="<?= e(number_format($enrollmentFeeDefault, 2, '.', '')) ?>" autofocus>
                        </div>

                        <div class="field field--sm">
                            <label for="ef-date">Bezahlt am</label>
                            <input id="ef-date" name="booked_on" type="date" value="<?= e(date('Y-m-d')) ?>">
                        </div>

                        <div class="field field--sm">
                            <label for="ef-method">Zahlungsart</label>
                            <select id="ef-method" name="payment_method_id">
                                <?php foreach ($methods as $method): ?>
                                    <option value="<?= (int) $method['id'] ?>" <?= $method['kind'] === 'bar' ? 'selected' : '' ?>>
                                        <?= e($method['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field field--grow">
                            <label for="ef-note">Notiz</label>
                            <input id="ef-note" name="note">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn--primary" type="submit">Buchen</button>
                        <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder/' . $id)) ?>">Keine Gebühr / später</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (Auth::canWrite()): ?>
        <div class="card card--danger">
            <div class="card__head">
                <h2>Archivieren &amp; Löschen</h2>
            </div>

            <?php if (($member['archived_at'] ?? null) === null): ?>
                <p class="muted">
                    Empfohlen statt Löschen: Das Mitglied wird als <strong>ehemaliges Mitglied</strong> archiviert –
                    Beitragshistorie, Buchungen und Erfolge bleiben vollständig erhalten, es zählt aber nicht mehr
                    zum Mitgliederstand und erscheint nur in der Ansicht „Ehemalige“.
                </p>

                <div class="danger-actions">
                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/archivieren')) ?>"
                          data-confirm="Mitglied als ehemaliges Mitglied archivieren?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder')) ?>">
                        <button class="btn" type="submit">Als ehemaliges Mitglied archivieren</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (Auth::isSuperuser()): ?>
                <p class="muted">
                    Als Superuser können Sie den Datensatz in den Papierkorb legen und danach endgültig löschen.
                </p>

                <div class="danger-actions">
                    <?php if (($member['deleted_at'] ?? null) === null): ?>
                        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/papierkorb')) ?>"
                              data-confirm="Mitglied in den Papierkorb verschieben?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder')) ?>">
                            <button class="btn btn--danger" type="submit">In den Papierkorb</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/wiederherstellen')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">
                            <button class="btn" type="submit">Wiederherstellen</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/endgueltig-loeschen')) ?>"
                          data-confirm="Diesen Datensatz endgültig und unwiderruflich löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder')) ?>">
                        <button class="btn btn--danger" type="submit">Endgültig löschen</button>
                    </form>

                    <?php if ((int) $member['delete_requested'] === 1): ?>
                        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/loeschantrag-aufheben')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">
                            <button class="btn btn--ghost" type="submit">Vormerkung aufheben</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="muted">
                    Als Sektionsleitung können Sie ein Mitglied zum Löschen vormerken.
                    Ein Superuser entscheidet über die endgültige Löschung.
                </p>

                <?php if ((int) $member['delete_requested'] === 1): ?>
                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/loeschantrag-aufheben')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">
                        <button class="btn" type="submit">Vormerkung aufheben</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/loeschantrag')) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= e(url('/admin/mitglieder/' . $id)) ?>">

                        <div class="field field--grow">
                            <label for="delete-reason">Grund</label>
                            <input id="delete-reason" name="reason" placeholder="z. B. Austritt zum Jahresende">
                        </div>

                        <button class="btn btn--danger" type="submit">Zum Löschen vormerken</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
