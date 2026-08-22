<?php

use App\Core\Auth;

/**
 * Mitglieder-Gruppen (frei definiert): steuern u. a. die Termin-Sichtbarkeit.
 *
 * @var list<array<string,mixed>>            $groups
 * @var array<int,list<array<string,mixed>>> $groupMembers
 * @var list<array<string,mixed>>            $alleMitglieder
 * @var array<string,string>                 $errors
 */
$darfSchreiben = Auth::is('superuser', 'sektionsleiter');
?>
<div class="page-head">
    <div>
        <h1>Gruppen</h1>
        <p class="page-head__sub">
            Frei definierbare Gruppen (z. B. Kampfmannschaft, Kader, Wettkampfteam).
            Klick auf ein Mitglied öffnet dessen Datensatz. Wird ein Termin einer
            Gruppe zugeordnet, sehen ihn nur deren Mitglieder.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/termine')) ?>">Zu den Terminen</a>
    </div>
</div>

<datalist id="member-list">
    <?php foreach ($alleMitglieder as $m): ?>
        <option value="<?= e($m['last_name'] . ' ' . $m['first_name']) ?>">
            <?= (string) $m['member_no'] !== '' ? e('Nr. ' . $m['member_no']) : '' ?>
        </option>
    <?php endforeach; ?>
</datalist>

<?php if ($darfSchreiben): ?>
    <div class="card">
        <div class="card__head"><h2>Neue Gruppe</h2></div>
        <form method="post" action="<?= e(url('/admin/gruppen')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--sm">
                <label for="g-name">Name *</label>
                <input id="g-name" name="name" required placeholder="z. B. Wettkampfteam">
            </div>

            <div class="field field--grow">
                <label for="g-note">Notiz</label>
                <input id="g-note" name="note">
            </div>

            <button class="btn btn--primary" type="submit">Anlegen</button>
        </form>
    </div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
    <div class="card">
        <div class="card__head">
            <h2><?= e($group['name']) ?> <span class="badge"><?= (int) $group['member_count'] ?> Mitglieder</span></h2>
            <div>
                <?= (string) $group['note'] !== '' ? '<span class="muted">' . e($group['note']) . '</span>' : '' ?>
                <?php if (Auth::isSuperuser()): ?>
                    <form method="post" class="inline"
                          action="<?= e(url('/admin/gruppen/' . $group['id'] . '/loeschen')) ?>"
                          data-confirm="Gruppe „<?= e($group['name']) ?>“ löschen? Die Mitglieder bleiben natürlich erhalten.">
                        <?= csrf_field() ?>
                        <button class="linklike linklike--danger" type="submit">Gruppe löschen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php $mitglieder = $groupMembers[(int) $group['id']] ?? []; ?>

        <?php if ($mitglieder !== []): ?>
            <ul class="chip-list">
                <?php foreach ($mitglieder as $m): ?>
                    <li class="chip">
                        <a href="<?= e(url('/admin/mitglieder/' . $m['id'])) ?>"><?= e($m['last_name'] . ' ' . $m['first_name']) ?></a>
                        <?= (int) $m['can_login'] === 1 ? '' : ' <small class="muted" title="kein Login-Zugang">🚫</small>' ?>
                        <?php if ($darfSchreiben): ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('/admin/gruppen/' . $group['id'] . '/mitglied-entfernen')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                <button class="chip__remove" type="submit" title="aus der Gruppe entfernen">×</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Noch keine Mitglieder in dieser Gruppe.</p>
        <?php endif; ?>

        <?php if ($darfSchreiben): ?>
            <form method="post" action="<?= e(url('/admin/gruppen/' . $group['id'] . '/mitglied')) ?>" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--sm">
                    <label>Mitglied hinzufügen</label>
                    <input name="member_ref" list="member-list" placeholder="tippen zum Suchen …">
                </div>

                <button class="btn btn--sm" type="submit">Hinzufügen</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($groups === []): ?>
    <div class="card"><p class="empty">Noch keine Gruppen angelegt.</p></div>
<?php endif; ?>

<p class="muted">
    🚫 = Mitglied hat (noch) keinen Login-Zugang und sieht Termine daher nicht.
    Der Zugang wird im Mitgliedsformular freigeschaltet.
</p>
