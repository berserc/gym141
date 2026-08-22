<?php

/**
 * Eigene Entwicklung im Mitgliederbereich: Gewicht selbst eintragen,
 * Gewichtskurve, Trainingsbesuche und Leistungsbewertung ansehen.
 *
 * @var array<string,mixed>       $member
 * @var list<array<string,mixed>> $weights    chronologisch
 * @var list<array<string,mixed>> $attendance chronologisch
 * @var list<array{label:string,value:float}> $perMonth
 */
$gewichtPunkte = array_map(static fn (array $w): array => [
    'label' => format_date((string) $w['measured_on']),
    'value' => (float) $w['weight'],
], $weights);

$bewertungPunkte = array_values(array_filter(array_map(
    static fn (array $a): ?array => $a['rating'] === null ? null : [
        'label' => format_date((string) $a['attended_on']),
        'value' => (float) $a['rating'],
    ],
    $attendance
)));

$dreissigTage = count(array_filter(
    $attendance,
    static fn (array $a): bool => (string) $a['attended_on'] >= date('Y-m-d', strtotime('-30 days'))
));
?>
<h1>Meine Entwicklung</h1>

<div class="m-card">
    <div class="m-card__head">
        <h2>Mein Gewicht</h2>
        <?php if ($weights !== []): ?>
            <span class="badge-dark">aktuell <?= e(number_format((float) end($weights)['weight'], 1, ',', '.')) ?> kg</span>
        <?php endif; ?>
    </div>

    <?= svg_line_chart($gewichtPunkte, ['unit' => 'kg']) ?>

    <form method="post" action="<?= e(url('/mitglied/gewicht')) ?>" class="m-inline-form">
        <?= csrf_field() ?>

        <div class="m-field">
            <label for="w-date">Datum</label>
            <input id="w-date" name="measured_on" type="date" value="<?= e(date('Y-m-d')) ?>">
        </div>

        <div class="m-field">
            <label for="w-kg">Gewicht (kg)</label>
            <input id="w-kg" name="weight" inputmode="decimal" required placeholder="z. B. 72,4">
        </div>

        <div class="m-field m-field--grow">
            <label for="w-note">Notiz (optional)</label>
            <input id="w-note" name="note" placeholder="z. B. vor dem Training">
        </div>

        <button class="btn btn--primary" type="submit">Eintragen</button>
    </form>

    <?php if ($weights !== []): ?>
        <details class="m-details">
            <summary>Alle Einträge (<?= count($weights) ?>)</summary>
            <ul class="m-list">
                <?php foreach (array_reverse($weights) as $w): ?>
                    <li>
                        <?= e(format_date((string) $w['measured_on'])) ?> –
                        <strong><?= e(number_format((float) $w['weight'], 1, ',', '.')) ?> kg</strong>
                        <?= (string) $w['note'] !== '' ? '<span class="muted-dark">(' . e($w['note']) . ')</span>' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</div>

<div class="m-card">
    <div class="m-card__head">
        <h2>Meine Trainings</h2>
        <span class="badge-dark"><?= $dreissigTage ?>× in den letzten 30 Tagen</span>
    </div>

    <?= svg_bar_chart($perMonth) ?>

    <?php if ($attendance === []): ?>
        <p class="muted-dark">Noch keine Trainingsbesuche erfasst – die Erfassung übernehmen die Trainer.</p>
    <?php endif; ?>
</div>

<div class="m-card">
    <div class="m-card__head">
        <h2>Meine Formkurve</h2>
        <span class="muted-dark">Bewertung durch die Trainer, 1–10 (10 = beste)</span>
    </div>

    <?= svg_line_chart($bewertungPunkte, ['min' => 1, 'max' => 10]) ?>

    <?php if ($bewertungPunkte === []): ?>
        <p class="muted-dark">Noch keine Bewertungen vorhanden.</p>
    <?php endif; ?>
</div>

<div class="m-card">
    <div class="m-card__head">
        <h2>Meine Leistungstests</h2>
        <span class="muted-dark">erfasst durch die Trainer</span>
    </div>

    <?php if (($results ?? []) === []): ?>
        <p class="muted-dark">Noch keine Testergebnisse vorhanden.</p>
    <?php endif; ?>

    <?php foreach (($results ?? []) as $testErgebnisse): ?>
        <?php
        $erster = $testErgebnisse[0];
        $werte  = array_map(static fn (array $r): float => (float) $r['value'], $testErgebnisse);
        $best   = (int) $erster['higher_is_better'] === 1 ? max($werte) : min($werte);

        $punkte = array_map(static fn (array $r): array => [
            'label' => format_date((string) $r['tested_on']),
            'value' => (float) $r['value'],
        ], $testErgebnisse);

        $zahl = static fn (float $v): string => $v == (int) $v
            ? (string) (int) $v
            : number_format($v, 2, ',', '.');
        ?>
        <?php $beschreibung = (string) ($erster['test_description'] ?? ''); ?>
        <?php if ($beschreibung !== ''): ?>
            <details class="test-desc test-desc--head" style="margin-top:1rem">
                <summary title="Klick zeigt die Beschreibung">
                    <h3 style="display:inline"><?= e($erster['test_name']) ?></h3>
                    <span class="badge-dark badge-dark--wettkampf">Bestwert: <?= e($zahl($best)) ?> <?= e($erster['unit']) ?></span>
                </summary>
                <p class="muted-dark"><?= e($beschreibung) ?></p>
            </details>
        <?php else: ?>
            <h3 style="margin-top:1rem">
                <?= e($erster['test_name']) ?>
                <span class="badge-dark badge-dark--wettkampf">Bestwert: <?= e($zahl($best)) ?> <?= e($erster['unit']) ?></span>
            </h3>
        <?php endif; ?>

        <?php if (count($punkte) > 1): ?>
            <?= svg_line_chart($punkte, ['unit' => (string) $erster['unit']]) ?>
        <?php endif; ?>

        <ul class="m-list">
            <?php foreach (array_reverse($testErgebnisse) as $r): ?>
                <li>
                    <?= e(format_date((string) $r['tested_on'])) ?> –
                    <strong><?= e($zahl((float) $r['value'])) ?> <?= e($r['unit']) ?></strong>
                    <?= (float) $r['value'] === $best ? '<span class="m-ok">★ Bestwert</span>' : '' ?>
                    <?= (string) $r['note'] !== '' ? '<span class="muted-dark">(' . e($r['note']) . ')</span>' : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</div>
