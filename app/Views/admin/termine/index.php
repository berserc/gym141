<?php

use App\Core\Auth;
use App\Models\CalendarRepo;

/**
 * Wettkampf- und Eventkalender verwalten (sichtbar im Mitgliederbereich).
 *
 * @var list<array<string,mixed>>            $events
 * @var array<int,list<array<string,mixed>>> $antworten je Termin
 * @var list<array<string,mixed>>            $groups
 * @var array<string,string>                 $kinds
 * @var array<string,string>                 $recurs
 * @var bool                                 $alle
 * @var array<string,string>                 $errors
 */
$darfSchreiben = Auth::is('superuser', 'kassier', 'sektionsleiter');

/** Formularfelder eines Termins. */
$felder = static function (array $e = []) use ($kinds, $groups, $recurs): void {
    $v = static fn (string $k): string => e((string) ($e[$k] ?? ''));
    $eventGroupIds = array_map(static fn (array $g): int => (int) $g['id'], $e['groups'] ?? []);
    ?>
    <div class="field field--sm">
        <label>Art</label>
        <select name="kind">
            <?php foreach ($kinds as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($e['kind'] ?? 'event') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field--grow">
        <label>Titel *</label>
        <input name="title" required value="<?= $v('title') ?>" placeholder="z. B. Landesmeisterschaft Muay Thai">
    </div>
    <div class="field field--sm">
        <label>Beginn *</label>
        <input name="starts_on" type="date" required value="<?= $v('starts_on') ?>">
    </div>
    <div class="field field--sm">
        <label>Ende <small>(leer = eintägig)</small></label>
        <input name="ends_on" type="date" value="<?= $v('ends_on') ?>">
    </div>
    <div class="field field--sm">
        <label>Ort</label>
        <input name="location" value="<?= $v('location') ?>">
    </div>
    <div class="field field--grow">
        <label>Beschreibung</label>
        <textarea name="description" rows="2"><?= $v('description') ?></textarea>
    </div>
    <div class="field field--sm">
        <label>Wiederholung</label>
        <select name="recur">
            <?php foreach ($recurs as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($e['recur'] ?? 'keine') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field--sm">
        <label>wiederholt bis <small>(leer = offen)</small></label>
        <input name="recur_until" type="date" value="<?= $v('recur_until') ?>">
    </div>
    <label class="check">
        <input type="checkbox" name="rsvp" value="1" <?= (int) ($e['rsvp'] ?? 1) === 1 ? 'checked' : '' ?>>
        Abstimmung möglich (komme / komme nicht)
    </label>
    <div class="field field--grow">
        <label>Sichtbar für <small>(nichts angehakt = alle Mitglieder mit Login)</small></label>
        <div class="checkbox-grid">
            <?php foreach ($groups as $group): ?>
                <label class="check">
                    <input type="checkbox" name="group_ids[]" value="<?= (int) $group['id'] ?>"
                        <?= in_array((int) $group['id'], $eventGroupIds, true) ? 'checked' : '' ?>>
                    <?= e($group['name']) ?>
                </label>
            <?php endforeach; ?>
            <?php if ($groups === []): ?>
                <span class="muted">Noch keine Gruppen angelegt (<a href="<?= e(url('/admin/gruppen')) ?>">Gruppen verwalten</a>).</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>
<div class="page-head">
    <div>
        <h1>Termine</h1>
        <p class="page-head__sub">
            Wettkampf- und Eventkalender – sichtbar für Mitglieder mit Login-Zugang
            unter <a href="<?= e(url('/mitglied')) ?>" target="_blank" rel="noopener">/mitglied</a>.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/termine/export.ics')) ?>">📅 Export (.ics)</a>
        <a class="btn" href="<?= e(url('/admin/gruppen')) ?>">Gruppen</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/termine', $alle ? [] : ['alle' => '1'])) ?>">
            <?= $alle ? 'nur kommende zeigen' : 'auch vergangene zeigen' ?>
        </a>
    </div>
</div>

<div class="notice">
    <strong>Kalender-Synchronisation:</strong> Abo-Adresse
    <code class="mono"><?= e(url('/api/termine.ics')) ?></code> (Anmeldung mit den
    Verwaltungs-Zugangsdaten, HTTP Basic Auth). Die REST-API unter
    <code class="mono">/api/termine</code> liefert JSON und kann Termine auch anlegen
    (POST), ändern (PUT) und löschen (DELETE).
</div>

<?php if ($darfSchreiben): ?>
    <div class="card">
        <div class="card__head"><h2>Neuer Termin</h2></div>
        <form method="post" action="<?= e(url('/admin/termine')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <?php $felder(); ?>
            <button class="btn btn--primary" type="submit">Eintragen</button>
        </form>
    </div>
<?php endif; ?>

<?php foreach ($events as $event): ?>
    <div class="card">
        <div class="card__head">
            <h2>
                <span class="badge<?= $event['kind'] === 'wettkampf' ? ' badge--warn' : '' ?>">
                    <?= e($kinds[$event['kind']] ?? $event['kind']) ?>
                </span>
                <?= e(CalendarRepo::rangeLabel((string) $event['starts_on'], $event['ends_on'] === null ? null : (string) $event['ends_on'])) ?>
                – <?= e($event['title']) ?>
                <?php if (CalendarRepo::recurLabel($event) !== ''): ?>
                    <span class="badge badge--info">🔁 <?= e(CalendarRepo::recurLabel($event)) ?></span>
                <?php endif; ?>
            </h2>
            <p class="muted">
                <?= (string) $event['location'] !== '' ? '📍 ' . e($event['location']) . ' · ' : '' ?>
                <?php if ($event['groups'] !== []): ?>
                    sichtbar für: <?= e(implode(', ', array_column($event['groups'], 'name'))) ?>
                <?php else: ?>
                    sichtbar für alle Mitglieder
                <?php endif; ?>
                · <?= (int) $event['zusagen'] ?> Zusagen / <?= (int) $event['absagen'] ?> Absagen
            </p>
        </div>

        <p>
            <a class="btn btn--sm" href="<?= e(url('/admin/termine/' . $event['id'] . '/orga')) ?>">🗂️ Organisation</a>
            <?php $o = $orga[(int) $event['id']] ?? []; ?>
            <?php if (($o['tasks'] ?? 0) > 0): ?>
                <span class="badge"><?= (int) $o['tasks'] ?> Aufgabe(n)</span>
            <?php endif; ?>
            <?php if (($o['todos'] ?? 0) > 0): ?>
                <span class="badge <?= ($o['erledigt'] ?? 0) >= $o['todos'] ? 'badge--ok' : 'badge--warn' ?>">
                    To-dos: <?= (int) ($o['erledigt'] ?? 0) ?>/<?= (int) $o['todos'] ?>
                </span>
            <?php endif; ?>
        </p>

        <?php if (trim((string) $event['description']) !== ''): ?>
            <p style="white-space: pre-line"><?= e($event['description']) ?></p>
        <?php endif; ?>

        <?php if (($antworten[(int) $event['id']] ?? []) !== []): ?>
            <details>
                <summary class="linklike">Antworten anzeigen (<?= count($antworten[(int) $event['id']]) ?>)</summary>
                <div class="table-scroll">
                    <table class="table table--compact">
                        <thead><tr><th>Termin</th><th>Mitglied</th><th>Antwort</th><th>Notiz</th><th>am</th></tr></thead>
                        <tbody>
                        <?php foreach ($antworten[(int) $event['id']] as $antwort): ?>
                            <tr>
                                <td>
                                    <?= (string) ($antwort['occurs_on'] ?? '') !== ''
                                        ? e(format_date((string) $antwort['occurs_on']))
                                        : '<span class="muted">gesamter Termin</span>' ?>
                                </td>
                                <td>
                                    <a href="<?= e(url('/admin/mitglieder/' . $antwort['member_id'])) ?>">
                                        <?= e($antwort['last_name'] . ', ' . $antwort['first_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="pill <?= $antwort['status'] === 'zusage' ? 'pill--aktiv' : 'pill--offen' ?>">
                                        <?= $antwort['status'] === 'zusage' ? 'Zusage' : 'Absage' ?>
                                    </span>
                                </td>
                                <td><?= e($antwort['note']) ?></td>
                                <td class="muted"><?= e(format_datetime((string) $antwort['updated_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($darfSchreiben): ?>
            <div class="inline-form">
                <details class="plan-edit">
                    <summary class="linklike">bearbeiten</summary>
                    <form method="post" action="<?= e(url('/admin/termine/' . $event['id'])) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <?php $felder($event); ?>
                        <button class="btn btn--sm" type="submit">Speichern</button>
                    </form>
                </details>

                <form method="post" class="inline"
                      action="<?= e(url('/admin/termine/' . $event['id'] . '/loeschen')) ?>"
                      data-confirm="Termin „<?= e($event['title']) ?>“ löschen (inkl. Antworten)?">
                    <?= csrf_field() ?>
                    <button class="linklike linklike--danger" type="submit">löschen</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($events === []): ?>
    <div class="card"><p class="empty">Keine Termine<?= $alle ? '' : ' – vergangene über den Schalter oben einblenden' ?>.</p></div>
<?php endif; ?>
