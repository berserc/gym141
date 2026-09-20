<?php

/**
 * Wettkämpfe über Event141.
 *
 * @var bool                      $configured
 * @var string                    $baseUrl
 * @var array<string,mixed>|null  $gym
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $results
 * @var string                    $error
 * @var list<array<string,mixed>> $members
 * @var bool                      $canEdit
 */
$statusLabel = ['angemeldet' => 'angemeldet', 'bestaetigt' => 'bestätigt', 'abgelehnt' => 'abgelehnt', 'abgemeldet' => 'abgemeldet'];
$resultLabel = ['sieg' => 'Sieg', 'niederlage' => 'Niederlage', 'unentschieden' => 'Unentschieden', 'kampflos' => 'kampflos'];
$offen       = array_values(array_filter($results, static fn (array $r): bool => $r['member_id'] !== null && !$r['imported'] && $r['result'] !== null));
?>
<div class="page-head">
    <div>
        <h1>Wettkämpfe</h1>
        <p class="page-head__sub">
            Anmeldung zu Turnieren und Galas über Event141
            <?php if ($gym !== null): ?> · verbunden als <strong><?= e($gym['name'] ?? '') ?></strong>
                (<a href="<?= e($baseUrl) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', $baseUrl)) ?> ↗</a>)<?php endif; ?>
        </p>
    </div>
</div>

<?php if (!$configured): ?>
    <div class="card">
        <h2>Event141 verbinden</h2>
        <ol class="steps">
            <li>Im Gym-Bereich von Event141 anmelden (oder das Gym dort registrieren) und unter <strong>Gym141</strong> einen <strong>API-Schlüssel</strong> erzeugen.</li>
            <li>Hier unter <a href="<?= e(url('/admin/einstellungen')) ?>">Einstellungen</a> die Event141-Adresse und den Schlüssel eintragen.</li>
            <li>Danach erscheinen hier die offenen Events – Mitglieder auswählen, Kategorie wählen, anmelden. Ergebnisse landen per Knopfdruck unter „Erfolge“.</li>
        </ol>
    </div>
<?php elseif ($error !== ''): ?>
    <div class="notice notice--danger"><strong>Event141 antwortet nicht:</strong> <?= e($error) ?></div>
<?php else: ?>

    <?php if ($events === []): ?>
        <div class="notice">Derzeit ist kein Event zur Anmeldung geöffnet.</div>
    <?php endif; ?>

    <?php foreach ($events as $ev): ?>
        <?php
        $entries  = (array) ($ev['entries'] ?? []);
        $angemeldet = [];
        foreach ($entries as $x) {
            if (in_array($x['status'] ?? '', ['angemeldet', 'bestaetigt'], true)) {
                $angemeldet[] = $x;
            }
        }
        ?>
        <div class="card">
            <div class="card__head">
                <h2><?= e($ev['name'] ?? '') ?></h2>
            </div>
            <p class="muted">
                <?= e(format_date((string) ($ev['starts_on'] ?? ''))) ?><?= !empty($ev['ends_on']) ? ' – ' . e(format_date((string) $ev['ends_on'])) : '' ?>
                <?= ($ev['venue'] ?? '') !== '' ? ' · ' . e($ev['venue']) : '' ?>
                <?= !empty($ev['registration_until']) ? ' · Anmeldeschluss ' . e(format_date((string) $ev['registration_until'])) : '' ?>
                <?= (float) ($ev['entry_fee'] ?? 0) > 0 ? ' · Startgeld ' . e(format_money($ev['entry_fee'])) : '' ?>
                <?php if (($ev['url'] ?? '') !== ''): ?> · <a href="<?= e($ev['url']) ?>" target="_blank" rel="noopener">Event-Seite ↗</a><?php endif; ?>
            </p>

            <?php if ($entries !== []): ?>
                <div class="table-scroll">
                    <table class="table table--compact">
                        <thead><tr><th>Angemeldet</th><th>Kategorie</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($entries as $x): ?>
                            <tr>
                                <td class="strong"><?= e($x['athlete'] ?? '') ?></td>
                                <td><?= e($x['category'] ?? '–') ?></td>
                                <td><span class="pill <?= ($x['status'] ?? '') === 'bestaetigt' ? 'pill--aktiv' : 'pill--offen' ?>"><?= e($statusLabel[$x['status'] ?? ''] ?? ($x['status'] ?? '')) ?></span></td>
                                <td class="row-actions">
                                    <?php if ($canEdit && in_array($x['status'] ?? '', ['angemeldet', 'bestaetigt'], true)): ?>
                                        <form method="post" action="<?= e(url('/admin/wettkaempfe/abmelden')) ?>" class="inline"
                                              data-confirm="<?= e($x['athlete'] ?? '') ?> wirklich abmelden?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="entry_id" value="<?= (int) ($x['entry_id'] ?? 0) ?>">
                                            <button class="linklike linklike--danger" type="submit">Abmelden</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <details class="plan-edit"<?= $entries === [] ? ' open' : '' ?>>
                    <summary>Mitglieder anmelden</summary>
                    <form method="post" action="<?= e(url('/admin/wettkaempfe/anmelden')) ?>" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="event_slug" value="<?= e($ev['slug'] ?? '') ?>">

                        <?php if (!empty($ev['categories'])): ?>
                            <div class="field">
                                <label>Kategorie *</label>
                                <select name="category_id" required>
                                    <option value="">– bitte wählen –</option>
                                    <?php foreach ((array) $ev['categories'] as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= ($c['info'] ?? '') !== '' ? ' (' . e($c['info']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="attendance-grid">
                            <?php foreach ($members as $m): ?>
                                <?php $alter = age_from($m['birthdate'] === null ? null : (string) $m['birthdate']); ?>
                                <label class="attendance-item">
                                    <input type="checkbox" name="member_ids[]" value="<?= (int) $m['id'] ?>">
                                    <span><?= e($m['last_name']) ?> <?= e($m['first_name']) ?>
                                        <small><?= $alter !== null ? $alter . ' J.' : '' ?><?= $m['weight'] !== null ? ' · ' . e(number_format((float) $m['weight'], 1, ',', '')) . ' kg' : '' ?><?= ($m['sektionen'] ?? '') !== '' ? ' · ' . e($m['sektionen']) : '' ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($members === []): ?><p class="muted">Keine aktiven Mitglieder im Sichtbereich.</p><?php endif; ?>

                        <div class="field"><label>Anmerkung an den Veranstalter</label><input name="note" placeholder="optional"></div>
                        <p class="field__hint">Übertragen werden Name, Geburtsdatum, Geschlecht und das zuletzt erfasste Gewicht der ausgewählten Mitglieder.</p>
                        <div class="form-actions"><button class="btn btn--primary" type="submit">Ausgewählte anmelden</button></div>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/admin/wettkaempfe/ergebnisse')) ?>" class="card" id="ergebnisse">
        <?= csrf_field() ?>
        <div class="card__head">
            <h2>Kämpfe unserer Mitglieder</h2>
            <?php if ($canEdit && $offen !== []): ?>
                <button class="btn btn--primary btn--sm" type="submit"><?= count($offen) ?> Ergebnis(se) in die Erfolge übernehmen</button>
            <?php endif; ?>
        </div>
        <div class="table-scroll">
            <table class="table table--compact">
                <thead><tr><th>Datum</th><th>Event</th><th>Mitglied</th><th>Gegner</th><th>Klasse</th><th>Ergebnis</th><th>Erfolge</th></tr></thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="mono"><?= e(format_date($r['date'] ?? null)) ?></td>
                        <td><?= e($r['event'] ?? '') ?></td>
                        <td class="strong">
                            <?php if ($r['member_id'] !== null): ?>
                                <a href="<?= e(url('/admin/mitglieder/' . $r['member_id'] . '/erfolge')) ?>"><?= e($r['athlete'] ?? '') ?></a>
                            <?php else: ?>
                                <?= e($r['athlete'] ?? '') ?> <small class="muted">(kein Mitglied zugeordnet)</small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['opponent'] ?: 'offen') ?><?= ($r['opponent_gym'] ?? '') !== '' ? ' <small class="muted">' . e($r['opponent_gym']) . '</small>' : '' ?></td>
                        <td><small><?= e(trim(($r['style'] ?? '') . ' ' . ($r['category'] ?? ''))) ?></small></td>
                        <td>
                            <?php if ($r['result'] !== null): ?>
                                <span class="pill <?= in_array($r['result'], ['sieg', 'kampflos'], true) ? 'pill--aktiv' : 'pill--offen' ?>"><?= e($resultLabel[$r['result']]) ?></span>
                                <small><?= e(trim(($r['method'] ?? '') . (($r['round'] ?? '') !== '' ? ' · Rd. ' . $r['round'] : ''))) ?></small>
                            <?php else: ?>
                                <small class="muted"><?= ($r['status'] ?? '') === 'laufend' ? 'läuft' : (($r['status'] ?? '') === 'abgesagt' ? 'abgesagt' : 'offen') ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['imported']): ?><span class="badge badge--ok">übernommen</span>
                            <?php elseif ($r['member_id'] !== null && $r['result'] !== null): ?><span class="badge badge--warn">neu</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($results === []): ?><tr><td colspan="7" class="empty">Noch keine Kämpfe in Event141.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
<?php endif; ?>
