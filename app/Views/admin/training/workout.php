<?php

use App\Models\WorkoutRepo;

/**
 * Eine Trainingseinheit aus der App (Trainer-Ansicht) mit Wiedergabe.
 *
 * @var array<string,mixed> $member
 * @var array<string,mixed> $row      Kopfdaten + 'workout' (JSON)
 * @var string              $geoKey
 */
$id      = (int) $member['id'];
$workout = (array) $row['workout'];
$title   = (string) $row['title'] !== '' ? (string) $row['title'] : WorkoutRepo::typeLabel((string) $row['type']);
?>
<div class="page-head">
    <div>
        <h1><?= e($title) ?></h1>
        <p class="page-head__sub">
            <a href="<?= e(url('/admin/mitglieder/' . $id . '/entwicklung')) ?>"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></a>
            · <?= e(format_datetime((string) $row['started_at'])) ?>
            · <?= e(WorkoutRepo::typeLabel((string) $row['type'])) ?>
            <?= (string) $row['exercise'] !== '' ? '· Übung: ' . e((string) $row['exercise']) : '' ?>
            <?= (string) $row['device'] !== '' ? '· Sensor: ' . e((string) $row['device']) : '' ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder/' . $id . '/entwicklung')) ?>">Zurück zur Entwicklung</a>
    </div>
</div>

<div class="card">
    <div class="wo-stats">
        <div><b><?= e(WorkoutRepo::duration((int) $row['duration_s'])) ?></b><small>Dauer</small></div>
        <div><b><?= (int) $row['distance_m'] >= 50 ? e(number_format((int) $row['distance_m'] / 1000, 2, ',', '.')) . ' km' : '–' ?></b><small>Strecke</small></div>
        <div class="hr"><b><?= $row['avg_hr'] !== null ? (int) $row['avg_hr'] : '–' ?></b><small>Ø Puls</small></div>
        <div class="hr"><b><?= $row['max_hr'] !== null ? (int) $row['max_hr'] : '–' ?></b><small>max. Puls</small></div>
        <div><b><?= e(WorkoutRepo::pace((int) $row['duration_s'], (int) $row['distance_m']) ?: '–') ?></b><small>Tempo</small></div>
    </div>
    <?php if ((string) $row['note'] !== ''): ?>
        <p><?= nl2br(e((string) $row['note'])) ?></p>
    <?php endif; ?>
    <?php $accent = '#f2c14e'; require dirname(__DIR__, 2) . '/partials/_workout-player.php'; ?>
</div>
