<?php

use App\Models\WorkoutRepo;

/**
 * Eigene Trainingseinheit aus der App (Mitgliederbereich) mit Wiedergabe.
 *
 * @var array<string,mixed> $row     Kopfdaten + 'workout' (JSON)
 * @var string              $geoKey
 */
$workout = (array) $row['workout'];
$title   = (string) $row['title'] !== '' ? (string) $row['title'] : WorkoutRepo::typeLabel((string) $row['type']);
?>
<p><a href="<?= e(url('/mitglied/entwicklung')) ?>">← Meine Entwicklung</a></p>
<h1><?= e($title) ?></h1>
<p class="muted-dark">
    <?= e(format_datetime((string) $row['started_at'])) ?> · <?= e(WorkoutRepo::typeLabel((string) $row['type'])) ?>
    <?= (string) $row['exercise'] !== '' ? '· ' . e((string) $row['exercise']) : '' ?>
</p>

<div class="m-card">
    <div class="wo-stats">
        <div><b><?= e(WorkoutRepo::duration((int) $row['duration_s'])) ?></b><small>Dauer</small></div>
        <div><b><?= (int) $row['distance_m'] >= 50 ? e(number_format((int) $row['distance_m'] / 1000, 2, ',', '.')) . ' km' : '–' ?></b><small>Strecke</small></div>
        <div class="hr"><b><?= $row['avg_hr'] !== null ? (int) $row['avg_hr'] : '–' ?></b><small>Ø Puls</small></div>
        <div class="hr"><b><?= $row['max_hr'] !== null ? (int) $row['max_hr'] : '–' ?></b><small>max. Puls</small></div>
    </div>
    <?php if ((string) $row['note'] !== ''): ?>
        <p><?= nl2br(e((string) $row['note'])) ?></p>
    <?php endif; ?>
    <?php $accent = '#f2c14e'; require dirname(__DIR__) . '/partials/_workout-player.php'; ?>
</div>
