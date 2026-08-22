<?php

use App\Core\Auth;

/**
 * @var array<string,int>         $stats
 * @var array{count:int,sum:float,members:int} $feeStats
 * @var list<array<string,mixed>> $bySection
 * @var int                       $pendingTotal
 * @var list<array<string,mixed>> $recent
 * @var array<string,mixed>|null  $authUser
 */
?>
<div class="page-head">
    <h1>Übersicht</h1>
    <p class="page-head__sub">
        Angemeldet als <strong><?= e($authUser['name'] !== '' ? $authUser['name'] : $authUser['username']) ?></strong>
        (<?= e(Auth::ROLES[$authUser['role']] ?? $authUser['role']) ?>)
    </p>
</div>

<?php
$limitAktiv = \App\Core\License::activeMemberCount();
$limitMax   = \App\Core\License::memberLimit();
?>
<?php if (Auth::isSuperuser() && $limitMax !== PHP_INT_MAX && $limitAktiv >= $limitMax - 5): ?>
    <div class="notice notice--warn">
        <strong>Gratis-Limit:</strong> <?= (int) $limitAktiv ?> von <?= (int) $limitMax ?>
        aktiven Mitgliedern belegt. Unbegrenzte Mitglieder gibt es mit
        <a href="https://portal.devworld-llc.com" target="_blank" rel="noopener">Gym141 Pro</a>
        – Lizenzschlüssel unter <a href="<?= e(url('/admin/einstellungen')) ?>">Einstellungen</a> eintragen.
    </div>
<?php endif; ?>

<?php if ($pendingTotal > 0 && Auth::isSuperuser()): ?>
    <div class="notice notice--warn">
        <strong><?= (int) $pendingTotal ?></strong> Mitglied(er) sind zum Löschen vorgemerkt.
        <a href="<?= e(url('/admin/mitglieder', ['delete_requested' => '1'])) ?>">Jetzt prüfen</a>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <a class="stat stat--ok" href="<?= e(url('/admin/mitglieder', ['status' => 'aktiv'])) ?>">
        <span class="stat__value"><?= (int) $stats['aktiv'] ?></span>
        <span class="stat__label">aktive Mitglieder</span>
    </a>
    <a class="stat" href="<?= e(url('/admin/mitglieder', ['status' => 'inaktiv'])) ?>">
        <span class="stat__value"><?= (int) $stats['inaktiv'] ?></span>
        <span class="stat__label">inaktiv</span>
    </a>
    <a class="stat stat--info" href="<?= e(url('/admin/mitglieder', ['paused' => '1'])) ?>">
        <span class="stat__value"><?= (int) $stats['ausgesetzt'] ?></span>
        <span class="stat__label">derzeit ausgesetzt (beitragsfrei)</span>
    </a>
    <a class="stat<?= (int) $feeStats['count'] > 0 ? ' stat--danger' : ' stat--ok' ?>" href="<?= e(url('/admin/beitraege')) ?>">
        <span class="stat__value"><?= (int) $feeStats['count'] ?></span>
        <span class="stat__label">fällige offene Beiträge</span>
    </a>
    <a class="stat<?= $feeStats['sum'] > 0 ? ' stat--danger' : ' stat--ok' ?>" href="<?= e(url('/admin/beitraege')) ?>">
        <span class="stat__value"><?= e(format_money($feeStats['sum'])) ?></span>
        <span class="stat__label">offener Betrag (<?= (int) $feeStats['members'] ?> Mitglieder)</span>
    </a>
    <a class="stat<?= (int) $stats['vorgemerkt'] > 0 ? ' stat--warn' : '' ?>" href="<?= e(url('/admin/mitglieder', ['delete_requested' => '1'])) ?>">
        <span class="stat__value"><?= (int) $stats['vorgemerkt'] ?></span>
        <span class="stat__label">zum Löschen vorgemerkt</span>
    </a>
    <?php if (Auth::isSuperuser()): ?>
        <a class="stat" href="<?= e(url('/admin/mitglieder', ['trashed' => '1'])) ?>">
            <span class="stat__value"><?= (int) $stats['papierkorb'] ?></span>
            <span class="stat__label">im Papierkorb</span>
        </a>
    <?php endif; ?>
</div>

<div class="form-grid">
    <div class="card">
        <div class="card__head">
            <h2>Erinnerungen</h2>
            <p class="muted">überfällig und in den nächsten 60 Tagen</p>
        </div>

        <?php if (($reminders ?? []) === []): ?>
            <p class="muted">Keine anstehenden Erinnerungen. 👍</p>
        <?php else: ?>
            <ul class="remind-list">
                <?php foreach ($reminders as $reminder): ?>
                    <?php $ueberfaellig = (string) $reminder['due_on'] < date('Y-m-d'); ?>
                    <li>
                        <span class="badge <?= $ueberfaellig ? 'badge--danger' : 'badge--warn' ?>">
                            <?= e(format_date((string) $reminder['due_on'])) ?>
                        </span>
                        <a href="<?= e(url('/admin/mitglieder/' . $reminder['member_id'])) ?>">
                            <?= e($reminder['last_name']) ?>, <?= e($reminder['first_name']) ?>
                        </a>
                        – <?= e($reminder['title']) ?>
                        <?= (string) $reminder['note'] !== '' ? '<small class="muted">(' . e($reminder['note']) . ')</small>' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Geburtstage 🎂</h2>
            <p class="muted">7 Tage vorher bis 7 Tage nachher</p>
        </div>

        <?php if (($birthdays ?? []) === []): ?>
            <p class="muted">Keine Geburtstage in diesem Zeitraum.</p>
        <?php else: ?>
            <ul class="remind-list">
                <?php foreach ($birthdays as $geb): ?>
                    <li>
                        <?php if ($geb['tage'] === 0): ?>
                            <span class="badge badge--gold">heute!</span>
                        <?php elseif ($geb['tage'] > 0): ?>
                            <span class="badge badge--info">in <?= (int) $geb['tage'] ?> Tag<?= $geb['tage'] === 1 ? '' : 'en' ?></span>
                        <?php else: ?>
                            <span class="badge">vor <?= abs((int) $geb['tage']) ?> Tag<?= $geb['tage'] === -1 ? '' : 'en' ?></span>
                        <?php endif; ?>
                        <a href="<?= e(url('/admin/mitglieder/' . $geb['id'])) ?>"><?= e($geb['name']) ?></a>
                        – <?= e(format_date((string) $geb['datum'])) ?>
                        <small class="muted">(<?= $geb['tage'] < 0 ? 'wurde' : 'wird' ?> <?= (int) $geb['alter'] ?>)</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Mitglieder je Sektion</h2>
        <a class="btn btn--sm" href="<?= e(url('/admin/mitglieder')) ?>">Alle Mitglieder</a>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Sektion</th>
                <th class="num">Aktiv</th>
                <th class="num">Inaktiv</th>
                <th class="num">Vorgemerkt</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bySection as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td>
                    <td class="num"><?= (int) $row['aktiv'] ?></td>
                    <td class="num"><?= (int) $row['inaktiv'] ?></td>
                    <td class="num"><?= (int) $row['vorgemerkt'] ?: '' ?></td>
                    <td class="row-actions">
                        <a href="<?= e(url('/admin/mitglieder', ['section_id' => $row['id']])) ?>">Mitglieder</a>
                        <a href="<?= e(url('/admin/sektionen/' . $row['id'])) ?>">Sektion</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($bySection === []): ?>
                <tr><td colspan="5" class="empty">Keine Sektionen zugeordnet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($recent !== []): ?>
    <div class="card">
        <div class="card__head">
            <h2>Letzte Änderungen</h2>
            <a class="btn btn--sm" href="<?= e(url('/admin/protokoll')) ?>">Vollständiges Protokoll</a>
        </div>

        <ul class="log-list">
            <?php foreach ($recent as $entry): ?>
                <li>
                    <time datetime="<?= e($entry['created_at']) ?>"><?= e(format_datetime((string) $entry['created_at'])) ?></time>
                    <strong><?= e($entry['username']) ?></strong>
                    <span><?= e($entry['action']) ?></span>
                    <?php if ((string) $entry['detail'] !== ''): ?>
                        <em><?= e(mb_strimwidth((string) $entry['detail'], 0, 90, '…')) ?></em>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
