<?php

use App\Core\Auth;

/**
 * @var array<string,mixed>       $user
 * @var list<array<string,mixed>> $sections
 * @var list<array<string,mixed>> $mitglieder Auswahlbox "Benutzer ist auch Mitglied"
 * @var array<string,string>      $errors
 * @var bool                      $isNew
 * @var array<string,mixed>|null  $authUser
 */
$id         = (int) ($user['id'] ?? 0);
$action     = $isNew ? url('/admin/benutzer') : url('/admin/benutzer/' . $id);
$sectionIds = array_map('intval', (array) ($user['section_ids'] ?? []));

// Vorbelegung der Mitglieds-Auswahl: alte Eingabe > verknuepftes Mitglied.
$memberRef = (string) ($user['member_ref']
    ?? (($user['member_last_name'] ?? null) !== null
        ? $user['member_last_name'] . ' ' . $user['member_first_name']
        : ''));

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<p class="field__error">' . e($errors[$field]) . '</p>' : '';
};
?>
<div class="page-head">
    <h1><?= $isNew ? 'Neuer Benutzer' : e($user['username']) ?></h1>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/benutzer')) ?>">Zur Liste</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>

    <div class="form-grid">
        <fieldset class="card">
            <legend>Konto</legend>

            <div class="field">
                <label for="username">Benutzername *</label>
                <input id="username" name="username" required value="<?= e($user['username']) ?>" autocomplete="off">
                <?= $err('username') ?>
            </div>

            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" value="<?= e($user['name']) ?>">
            </div>

            <div class="field">
                <label for="email">E-Mail</label>
                <input id="email" name="email" type="email" value="<?= e($user['email']) ?>">
                <?= $err('email') ?>
            </div>

            <div class="field">
                <label for="password">
                    <?= $isNew ? 'Startpasswort (leer = automatisch erzeugen)' : 'Neues Passwort (leer = unverändert)' ?>
                </label>
                <input id="password" name="password" type="text" autocomplete="new-password"
                       placeholder="mindestens <?= Auth::MIN_PASSWORD_LENGTH ?> Zeichen">
                <?= $err('password') ?>
                <p class="field__hint">Das Passwort wird nach dem Speichern einmalig angezeigt.</p>
            </div>

            <label class="check">
                <input type="checkbox" name="active" value="1" <?= (int) $user['active'] === 1 ? 'checked' : '' ?>>
                Konto ist aktiv
            </label>

            <div class="field">
                <label for="member-ref">Ist auch Mitglied</label>
                <input id="member-ref" name="member_ref" list="member-list"
                       value="<?= e($memberRef) ?>" placeholder="tippen zum Suchen … (leer = keine Verknüpfung)">
                <datalist id="member-list">
                    <?php foreach ($mitglieder as $m): ?>
                        <option value="<?= e($m['last_name'] . ' ' . $m['first_name']) ?>">
                            <?= e(trim(((string) $m['member_no'] !== '' ? 'Nr. ' . $m['member_no'] . ' · ' : '')
                                . (($m['birthdate'] ?? null) !== null ? 'geb. ' . format_date((string) $m['birthdate']) : ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </datalist>
                <?= $err('member_ref') ?>
                <?php if (($user['member_id'] ?? null) !== null): ?>
                    <p class="field__hint">
                        Verknüpft mit
                        <a href="<?= e(url('/admin/mitglieder/' . (int) $user['member_id'])) ?>"><?=
                            e($user['member_first_name'] . ' ' . $user['member_last_name']) ?></a>
                        – Feld leeren und speichern hebt die Verknüpfung auf.
                    </p>
                <?php else: ?>
                    <p class="field__hint">Verknüpft das Benutzerkonto mit dem Mitgliedsdatensatz der gleichen Person.</p>
                <?php endif; ?>
            </div>
        </fieldset>

        <fieldset class="card">
            <legend>Rolle und Zuständigkeit</legend>

            <div class="field">
                <label for="role">Rolle *</label>
                <select id="role" name="role" data-role-select>
                    <?php foreach (Auth::ROLES as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('role') ?>
            </div>

            <ul class="role-help">
                <li><strong>Superuser</strong> – Vollzugriff, endgültiges Löschen, Benutzer- und Seitenverwaltung.</li>
                <li><strong>Sektionsleitung</strong> – nur die zugeordneten Sektionen; Löschen nur als Vormerkung.</li>
                <li><strong>Kassier</strong> – sieht alle Mitglieder, pflegt Beiträge und Auswertungen, ändert keine Stammdaten.</li>
            </ul>

            <div class="field" data-role-sections<?= $user['role'] === 'sektionsleiter' ? '' : ' hidden' ?>>
                <label>Zugeordnete Sektionen</label>
                <?= $err('section_ids') ?>

                <div class="checkbox-grid">
                    <?php foreach ($sections as $section): ?>
                        <label class="check">
                            <input type="checkbox" name="section_ids[]" value="<?= (int) $section['id'] ?>"
                                <?= in_array((int) $section['id'], $sectionIds, true) ? 'checked' : '' ?>>
                            <?= e($section['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </fieldset>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/benutzer')) ?>">Abbrechen</a>
    </div>
</form>

<?php if (!$isNew): ?>
    <div class="card card--danger">
        <div class="card__head">
            <h2>Weitere Aktionen</h2>
        </div>

        <div class="danger-actions">
            <form method="post" action="<?= e(url('/admin/benutzer/' . $id . '/passwort')) ?>"
                  data-confirm="Neues Zufallspasswort erzeugen? Das bisherige wird ungültig.">
                <?= csrf_field() ?>
                <button class="btn" type="submit">Passwort zurücksetzen</button>
            </form>

            <?php if ($id !== (int) ($authUser['id'] ?? 0)): ?>
                <form method="post" action="<?= e(url('/admin/benutzer/' . $id . '/loeschen')) ?>"
                      data-confirm="Benutzer „<?= e($user['username']) ?>“ endgültig löschen?">
                    <?= csrf_field() ?>
                    <button class="btn btn--danger" type="submit">Benutzer löschen</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
