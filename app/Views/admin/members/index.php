<?php

use App\Core\Auth;

/**
 * @var array<string,mixed>       $filters
 * @var list<array<string,mixed>> $sections
 * @var list<string>              $gemeinden
 * @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} $result
 * @var string                    $sort
 * @var string                    $dir
 * @var string                    $perPage
 * @var list<array<string,mixed>> $feePlans
 * @var array{active:int,monthly_members:int,monthly_sum:float} $feeTotals
 */
$trashed  = !empty($filters['trashed']);
$archived = !$trashed && !empty($filters['archived']);
$returnTo = url('/admin/mitglieder') . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '');

/** Baut einen Sortier-Link, der die aktuellen Filter beibehält. */
$sortLink = static function (string $key, string $label) use ($filters, $sort, $dir): string {
    $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $query   = array_merge($filters, ['sort' => $key, 'dir' => $nextDir]);
    $arrow   = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';

    return '<a href="' . e(url('/admin/mitglieder', $query)) . '">' . e($label) . $arrow . '</a>';
};
?>
<div class="page-head">
    <div>
        <h1><?= $trashed ? 'Papierkorb' : ($archived ? 'Ehemalige Mitglieder' : 'Mitglieder') ?></h1>
        <?php if ($archived): ?>
            <p class="page-head__sub">
                Archivierte Mitglieder: Historie, Beiträge und Erfolge bleiben vollständig erhalten,
                sie zählen aber nicht mehr zum Mitgliederstand.
            </p>
        <?php elseif (!$trashed): ?>
            <p class="page-head__sub">
                Gesamtstand: <strong><?= (int) $feeTotals['active'] ?></strong> aktive Mitglieder ·
                Beiträge, umgerechnet auf den Monat: <strong><?= e(format_money($feeTotals['monthly_sum'])) ?></strong>
                (<?= (int) $feeTotals['monthly_members'] ?> beitragspflichtige Mitglieder;
                Quartal ÷ 3, Halbjahr ÷ 6, Jahr ÷ 12)
                <?php if ((int) ($feeTotals['trainer'] ?? 0) > 0): ?>
                    · <a href="<?= e(url('/admin/mitglieder', ['trainer' => '1'])) ?>">Trainer:
                        <strong><?= (int) $feeTotals['trainer'] ?></strong></a>
                <?php endif; ?>
                <?php if ((int) $feeTotals['paused'] > 0): ?>
                    · <a href="<?= e(url('/admin/mitglieder', ['paused' => '1', 'status' => ''])) ?>">derzeit ausgesetzt:
                        <strong><?= (int) $feeTotals['paused'] ?></strong></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="page-head__actions">
        <?php if (Auth::canWrite() && !$trashed && !$archived): ?>
            <a class="btn btn--primary" href="<?= e(url('/admin/mitglieder/neu')) ?>">Neues Mitglied</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('/admin/mitglieder/export.xlsx')) ?>">Excel-Liste</a>
        <a class="btn" href="<?= e(url('/admin/mitglieder/export.csv', $filters)) ?>">CSV (gefiltert)</a>
        <?php if (!$trashed): ?>
            <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder', $archived ? [] : ['archived' => '1'])) ?>">
                <?= $archived ? 'Zurück zur Liste' : 'Ehemalige' ?>
            </a>
        <?php endif; ?>
        <?php if (Auth::isSuperuser()): ?>
            <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder', $trashed ? [] : ['trashed' => '1'])) ?>">
                <?= $trashed ? 'Zurück zur Liste' : 'Papierkorb' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/mitglieder')) ?>">
    <?php if ($trashed): ?>
        <input type="hidden" name="trashed" value="1">
    <?php elseif ($archived): ?>
        <input type="hidden" name="archived" value="1">
    <?php endif; ?>

    <div class="filters__row">
        <div class="field field--grow">
            <label for="f-q">Suche</label>
            <input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                   placeholder="Name, E-Mail, Ort, Mitgliedsnummer …">
        </div>

        <div class="field">
            <label for="f-section">Sektion</label>
            <select id="f-section" name="section_id">
                <option value="">alle</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>"
                        <?= (string) $filters['section_id'] === (string) $section['id'] ? 'selected' : '' ?>>
                        <?= e($section['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status">
                <option value="">alle</option>
                <option value="aktiv"   <?= $filters['status'] === 'aktiv' ? 'selected' : '' ?>>aktiv</option>
                <option value="inaktiv" <?= $filters['status'] === 'inaktiv' ? 'selected' : '' ?>>inaktiv</option>
            </select>
        </div>

        <div class="field">
            <label for="f-gemeinde">Gemeinde</label>
            <select id="f-gemeinde" name="gemeinde">
                <option value="">alle</option>
                <?php foreach ($gemeinden as $gemeinde): ?>
                    <option value="<?= e($gemeinde) ?>" <?= $filters['gemeinde'] === $gemeinde ? 'selected' : '' ?>>
                        <?= e($gemeinde) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--xs">
            <label for="f-per-page">pro Seite</label>
            <select id="f-per-page" name="per_page">
                <?php foreach (\App\Controllers\MemberController::PAGE_SIZES as $groesse): ?>
                    <option value="<?= e($groesse) ?>" <?= $perPage === $groesse ? 'selected' : '' ?>>
                        <?= e($groesse) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn--primary" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder', $trashed ? ['trashed' => '1'] : [])) ?>">Zurücksetzen</a>
    </div>

    <details class="filters__more"<?= ($filters['gender'] || $filters['delete_requested'] || $filters['fee_overdue'] || $filters['fee_plan_id'] || $filters['paused'] || $filters['trainer'] || $filters['age_from'] || $filters['age_to']) ? ' open' : '' ?>>
        <summary>Weitere Filter</summary>

        <div class="filters__row">
            <div class="field">
                <label for="f-gender">Geschlecht</label>
                <select id="f-gender" name="gender">
                    <option value="">alle</option>
                    <option value="m" <?= $filters['gender'] === 'm' ? 'selected' : '' ?>>männlich</option>
                    <option value="w" <?= $filters['gender'] === 'w' ? 'selected' : '' ?>>weiblich</option>
                    <option value="d" <?= $filters['gender'] === 'd' ? 'selected' : '' ?>>divers</option>
                    <option value="unbekannt" <?= $filters['gender'] === 'unbekannt' ? 'selected' : '' ?>>ohne Angabe</option>
                </select>
            </div>

            <div class="field field--xs">
                <label for="f-age-from">Alter ab</label>
                <input id="f-age-from" type="number" name="age_from" min="0" max="120" value="<?= e($filters['age_from']) ?>">
            </div>

            <div class="field field--xs">
                <label for="f-age-to">bis</label>
                <input id="f-age-to" type="number" name="age_to" min="0" max="120" value="<?= e($filters['age_to']) ?>">
            </div>

            <div class="field">
                <label for="f-plan">Beitragsart</label>
                <select id="f-plan" name="fee_plan_id">
                    <option value="">alle</option>
                    <?php foreach ($feePlans as $plan): ?>
                        <option value="<?= (int) $plan['id'] ?>"
                            <?= (string) $filters['fee_plan_id'] === (string) $plan['id'] ? 'selected' : '' ?>>
                            <?= e($plan['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="check">
                <input type="checkbox" name="fee_overdue" value="1" <?= $filters['fee_overdue'] ? 'checked' : '' ?>>
                nur mit fälligen offenen Beiträgen
            </label>

            <label class="check">
                <input type="checkbox" name="paused" value="1" <?= $filters['paused'] ? 'checked' : '' ?>>
                nur derzeit ausgesetzte
            </label>

            <label class="check">
                <input type="checkbox" name="trainer" value="1" <?= $filters['trainer'] ? 'checked' : '' ?>>
                nur Trainer
            </label>

            <label class="check">
                <input type="checkbox" name="delete_requested" value="1" <?= $filters['delete_requested'] ? 'checked' : '' ?>>
                nur Löschvormerkungen
            </label>
        </div>
    </details>
</form>

<form method="post" action="<?= e(url('/admin/mitglieder/sammelaktion')) ?>" data-confirm-bulk>
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

    <div class="card">
        <div class="card__head">
            <h2><?= (int) $result['total'] ?> Treffer</h2>
            <p class="muted">Seite <?= (int) $result['page'] ?> von <?= (int) $result['pages'] ?></p>
        </div>

        <div class="table-scroll">
            <table class="table table--members">
                <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" data-check-all aria-label="Alle auswählen"></th>
                    <th><?= $sortLink('name', 'Zuname, Vorname') ?></th>
                    <th><?= $sortLink('geburtstag', 'Geburtsdatum') ?></th>
                    <th class="num"><?= $sortLink('geburtstag', 'Alter') ?></th>
                    <th>Geschl.</th>
                    <th><?= $sortLink('sektion', 'Sektion') ?></th>
                    <th><?= $sortLink('gemeinde', 'Gemeinde') ?></th>
                    <th><?= $sortLink('beitrag', 'Beitragsart') ?></th>
                    <th><?= $sortLink('status', 'Status') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $member): ?>
                    <tr<?= (int) $member['delete_requested'] === 1 ? ' class="is-flagged"' : '' ?>>
                        <td class="col-check">
                            <input type="checkbox" name="ids[]" value="<?= (int) $member['id'] ?>"
                                   aria-label="<?= e($member['last_name'] . ' ' . $member['first_name']) ?> auswählen">
                        </td>
                        <td>
                            <a class="strong" href="<?= e(url('/admin/mitglieder/' . $member['id'])) ?>">
                                <?= e($member['last_name']) ?>, <?= e($member['first_name']) ?>
                            </a>
                            <?php if ((int) ($member['is_trainer'] ?? 0) === 1): ?>
                                <span class="badge badge--gold">Trainer</span>
                            <?php endif; ?>
                            <?php if (($member['archived_at'] ?? null) !== null): ?>
                                <span class="badge badge--muted" title="archiviert am <?= e(format_date(substr((string) $member['archived_at'], 0, 10))) ?>">ehemalig</span>
                            <?php endif; ?>
                            <?php if ((int) $member['delete_requested'] === 1): ?>
                                <span class="badge badge--danger" title="<?= e($member['delete_reason']) ?>">zum Löschen vorgemerkt</span>
                            <?php endif; ?>
                            <?php if ((string) $member['member_no'] !== ''): ?>
                                <small class="muted">Nr. <?= e($member['member_no']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date($member['birthdate'] === null ? null : (string) $member['birthdate'])) ?></td>
                        <td class="num">
                            <?php $age = age_from($member['birthdate'] === null ? null : (string) $member['birthdate']); ?>
                            <?= $age !== null ? $age : '–' ?>
                        </td>
                        <td><?= e(['m' => 'm', 'w' => 'w', 'd' => 'd', 'unbekannt' => '–'][$member['gender']] ?? '–') ?></td>
                        <td><?= e($member['section_name']) ?></td>
                        <td><?= e($member['gemeinde']) ?></td>
                        <td>
                            <?php if (($member['fee_plan_name'] ?? null) !== null): ?>
                                <?= e($member['fee_plan_name']) ?>
                                <?php if ($member['fee_effective'] !== null): ?>
                                    (<?= e(format_money($member['fee_effective'])) ?>)
                                <?php endif; ?>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                            <?php if ((int) ($member['fees_open'] ?? 0) > 0): ?>
                                <span class="badge badge--danger"
                                      title="fällige offene Beiträge"><?= (int) $member['fees_open'] ?> offen</span>
                            <?php endif; ?>
                            <?php if ((int) ($member['is_paused'] ?? 0) === 1): ?>
                                <span class="badge badge--info" title="laufende Beitragspause – beitragsfrei">ausgesetzt</span>
                            <?php endif; ?>
                            <?php if (($member['left_on'] ?? null) !== null && (string) $member['left_on'] !== ''): ?>
                                <span class="badge badge--warn" title="Austrittsdatum">Austritt <?= e(format_date((string) $member['left_on'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill pill--<?= e($member['status']) ?>"><?= e($member['status']) ?></span>
                        </td>
                        <td class="row-actions">
                            <?php if ($trashed): ?>
                                <?php if (Auth::isSuperuser()): ?>
                                    <button class="linklike" type="submit" form="restore-<?= (int) $member['id'] ?>">Wiederherstellen</button>
                                <?php endif; ?>
                            <?php elseif ($archived): ?>
                                <a href="<?= e(url('/admin/mitglieder/' . $member['id'])) ?>">Ansehen</a>
                                <?php if (Auth::canWrite()): ?>
                                    <button class="linklike" type="submit" form="unarchive-<?= (int) $member['id'] ?>">Reaktivieren</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="<?= e(url('/admin/mitglieder/' . $member['id'])) ?>">Bearbeiten</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($result['rows'] === []): ?>
                    <tr><td colspan="10" class="empty">Keine Mitglieder gefunden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($result['rows'] !== [] && !$trashed && !$archived): ?>
            <div class="bulkbar">
                <label for="bulk-action">Für Auswahl:</label>
                <select id="bulk-action" name="action">
                    <?php if (Auth::canWrite()): ?>
                        <option value="aktiv">Status auf „aktiv“</option>
                        <option value="inaktiv">Status auf „inaktiv“</option>
                        <option value="archive">Als ehemalige Mitglieder archivieren</option>
                        <option value="delete_request">Zum Löschen vormerken</option>
                    <?php endif; ?>
                    <?php if (Auth::canManageFees()): ?>
                        <option value="mark_paid">Fällige Beiträge als bezahlt erfassen</option>
                        <option value="fee_change">Beitragsänderung ab Stichtag</option>
                    <?php endif; ?>
                    <?php if (Auth::isSuperuser()): ?>
                        <option value="trash">In den Papierkorb</option>
                    <?php endif; ?>
                </select>

                <input type="text" name="reason" placeholder="Grund / Notiz" class="input--reason">

                <?php if (Auth::canManageFees()): ?>
                    <input type="number" step="0.01" min="0" name="fee_amount" class="input--money"
                           placeholder="neuer Beitrag €" title="Neuer Beitrag (nur bei „Beitragsänderung ab Stichtag“)">
                    <input type="date" name="fee_valid_from" value="<?= e(date('Y-m-d')) ?>"
                           title="Stichtag der Beitragsänderung – auch rückwirkend möglich (leer = heute)">
                <?php endif; ?>

                <button class="btn" type="submit">Ausführen</button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if ($trashed && Auth::isSuperuser()): ?>
    <?php foreach ($result['rows'] as $member): ?>
        <form id="restore-<?= (int) $member['id'] ?>" method="post" class="hidden"
              action="<?= e(url('/admin/mitglieder/' . $member['id'] . '/wiederherstellen')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($archived && Auth::canWrite()): ?>
    <?php foreach ($result['rows'] as $member): ?>
        <form id="unarchive-<?= (int) $member['id'] ?>" method="post" class="hidden"
              action="<?= e(url('/admin/mitglieder/' . $member['id'] . '/reaktivieren')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ((int) $result['pages'] > 1): ?>
    <nav class="pager" aria-label="Seiten">
        <?php
        $current = (int) $result['page'];
        $last    = (int) $result['pages'];
        $window  = range(max(1, $current - 2), min($last, $current + 2));
        ?>
        <?php if ($current > 1): ?>
            <a href="<?= e(url('/admin/mitglieder', array_merge($filters, ['sort' => $sort, 'dir' => $dir, 'page' => $current - 1]))) ?>">Zurück</a>
        <?php endif; ?>

        <?php if (!in_array(1, $window, true)): ?>
            <a href="<?= e(url('/admin/mitglieder', array_merge($filters, ['sort' => $sort, 'dir' => $dir, 'page' => 1]))) ?>">1</a>
            <span class="pager__gap">…</span>
        <?php endif; ?>

        <?php foreach ($window as $p): ?>
            <?php if ($p === $current): ?>
                <span class="pager__current" aria-current="page"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e(url('/admin/mitglieder', array_merge($filters, ['sort' => $sort, 'dir' => $dir, 'page' => $p]))) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!in_array($last, $window, true)): ?>
            <span class="pager__gap">…</span>
            <a href="<?= e(url('/admin/mitglieder', array_merge($filters, ['sort' => $sort, 'dir' => $dir, 'page' => $last]))) ?>"><?= $last ?></a>
        <?php endif; ?>

        <?php if ($current < $last): ?>
            <a href="<?= e(url('/admin/mitglieder', array_merge($filters, ['sort' => $sort, 'dir' => $dir, 'page' => $current + 1]))) ?>">Weiter</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
