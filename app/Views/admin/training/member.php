<?php

/**
 * Entwicklung eines Mitglieds: Gewichtskurve, Trainingsbesuche je Monat,
 * Leistungsbewertung (1-10) – mit Erfassung.
 *
 * @var array<string,mixed>       $member
 * @var list<array<string,mixed>> $weights    chronologisch
 * @var list<array<string,mixed>> $attendance chronologisch
 * @var list<array{label:string,value:float}> $perMonth
 * @var list<array<string,mixed>> $tests   aktive Leistungstests
 * @var array<int,list<array<string,mixed>>> $results Ergebnisse je Test (chronologisch)
 * @var bool                      $canEdit
 */
$id = (int) $member['id'];

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

$trainingsGesamt = count($attendance);
$letzteBewertungen = array_slice($bewertungPunkte, -5);
$schnitt = $letzteBewertungen !== []
    ? array_sum(array_column($letzteBewertungen, 'value')) / count($letzteBewertungen)
    : null;
?>
<div class="page-head">
    <div>
        <h1>Entwicklung</h1>
        <p class="page-head__sub">
            <a href="<?= e(url('/admin/mitglieder/' . $id)) ?>"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></a>
            · <?= $trainingsGesamt ?> Trainingsbesuche erfasst
            <?php if ($weights !== []): ?>
                · aktuelles Gewicht <?= e(number_format((float) end($weights)['weight'], 1, ',', '.')) ?> kg
            <?php endif; ?>
            <?php if ($schnitt !== null): ?>
                · Formkurve zuletzt Ø <?= e(number_format($schnitt, 1, ',', '.')) ?>/10
            <?php endif; ?>
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/mitglieder/' . $id . '/erfolge')) ?>">Erfolge &amp; Wettkämpfe</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder/' . $id)) ?>">Zum Mitglied</a>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Gewichtsverlauf</h2>
    </div>
    <?= svg_line_chart($gewichtPunkte, ['unit' => 'kg']) ?>

    <?php if ($canEdit): ?>
        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/gewicht')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--sm">
                <label for="w-date">Datum</label>
                <input id="w-date" name="measured_on" type="date" value="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="field field--xs">
                <label for="w-time">Uhrzeit</label>
                <input id="w-time" name="measured_time" type="time" value="<?= e(date('H:i')) ?>">
            </div>

            <div class="field field--xs">
                <label for="w-kg">Gewicht (kg)</label>
                <input id="w-kg" name="weight" inputmode="decimal" required placeholder="z. B. 72,4">
            </div>

            <div class="field field--grow">
                <label for="w-note">Notiz</label>
                <input id="w-note" name="note">
            </div>

            <button class="btn" type="submit">Gewicht erfassen</button>
        </form>
    <?php endif; ?>

    <?php if ($weights !== []): ?>
        <details>
            <summary class="linklike">Alle Einträge (<?= count($weights) ?>)</summary>
            <div class="table-scroll">
                <table class="table table--compact">
                    <thead><tr><th>Datum</th><th class="num">Gewicht</th><th>Notiz</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($weights) as $w): ?>
                        <tr>
                            <td><?= e(format_date((string) $w['measured_on'])) ?><?= (string) ($w['measured_time'] ?? '') !== '' ? ', ' . e((string) $w['measured_time']) : '' ?></td>
                            <td class="num"><?= e(number_format((float) $w['weight'], 1, ',', '.')) ?> kg</td>
                            <td><?= e($w['note']) ?></td>
                            <td class="row-actions">
                                <?php if ($canEdit): ?>
                                    <form method="post" class="inline" action="<?= e(url('/admin/mitglieder/' . $id . '/gewicht-loeschen')) ?>"
                                          data-confirm="Gewichtseintrag löschen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="weight_id" value="<?= (int) $w['id'] ?>">
                                        <button class="linklike linklike--danger" type="submit">löschen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head">
        <h2>Trainingsbesuche (letzte 12 Monate)</h2>
        <a class="btn btn--sm" href="<?= e(url('/admin/anwesenheit')) ?>">Schnellerfassung</a>
    </div>
    <?= svg_bar_chart($perMonth) ?>
</div>

<div class="card">
    <div class="card__head">
        <h2>Leistungsbewertung (1–10, 10 = beste)</h2>
    </div>
    <?= svg_line_chart($bewertungPunkte, ['min' => 1, 'max' => 10]) ?>

    <?php if ($canEdit): ?>
        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/anwesenheit')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--sm">
                <label for="a-date">Training am</label>
                <input id="a-date" name="attended_on" type="date" value="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="field field--xs">
                <label for="a-rating">Bewertung (1–10)</label>
                <input id="a-rating" name="rating" type="number" min="1" max="10" placeholder="optional">
            </div>

            <div class="field field--grow">
                <label for="a-note">Notiz</label>
                <input id="a-note" name="note">
            </div>

            <button class="btn" type="submit">Training erfassen</button>
        </form>
        <p class="field__hint">
            Gibt es für den Tag schon einen Eintrag, werden Bewertung und Notiz aktualisiert.
        </p>
    <?php endif; ?>

    <?php if ($attendance !== []): ?>
        <details>
            <summary class="linklike">Alle Trainingsbesuche (<?= count($attendance) ?>)</summary>
            <div class="table-scroll">
                <table class="table table--compact">
                    <thead><tr><th>Datum</th><th class="num">Bewertung</th><th>Notiz</th><th>erfasst von</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($attendance) as $a): ?>
                        <tr>
                            <td><?= e(format_date((string) $a['attended_on'])) ?></td>
                            <td class="num"><?= $a['rating'] !== null ? (int) $a['rating'] . '/10' : '–' ?></td>
                            <td><?= e($a['note']) ?></td>
                            <td class="muted"><?= e((string) ($a['created_by_name'] ?? '')) ?></td>
                            <td class="row-actions">
                                <?php if ($canEdit): ?>
                                    <form method="post" class="inline" action="<?= e(url('/admin/mitglieder/' . $id . '/anwesenheit-loeschen')) ?>"
                                          data-confirm="Trainingseintrag löschen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="attendance_id" value="<?= (int) $a['id'] ?>">
                                        <button class="linklike linklike--danger" type="submit">löschen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head">
        <h2>Leistungstests</h2>
        <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/entwicklung')) ?>">Tests verwalten</a>
    </div>

    <?php if ($canEdit && $tests !== []): ?>
        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/leistungstest')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--grow">
                <label for="pt-test">Test</label>
                <select id="pt-test" name="test_id" required>
                    <?php foreach ($tests as $test): ?>
                        <option value="<?= (int) $test['id'] ?>" data-description="<?= e($test['description']) ?>">
                            <?= e($test['name']) ?><?= (string) $test['unit'] !== '' ? ' (' . e($test['unit']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field__hint" id="pt-description"></p>
            </div>

            <div class="field field--sm">
                <label for="pt-date">Datum</label>
                <input id="pt-date" name="tested_on" type="date" value="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="field field--xs">
                <label for="pt-value">Wert *</label>
                <input id="pt-value" name="value" inputmode="decimal" required placeholder="z. B. 2450">
            </div>

            <div class="field field--grow">
                <label for="pt-note">Notiz</label>
                <input id="pt-note" name="note">
            </div>

            <button class="btn" type="submit">Ergebnis erfassen</button>
        </form>

        <script>
        // Beschreibung des gewaehlten Tests unter der Auswahl anzeigen
        (function () {
            var select = document.getElementById('pt-test');
            var hint = document.getElementById('pt-description');

            if (!select || !hint) {
                return;
            }

            var sync = function () {
                var option = select.options[select.selectedIndex];
                hint.textContent = option ? (option.getAttribute('data-description') || '') : '';
            };

            select.addEventListener('change', sync);
            sync();
        })();
        </script>
    <?php endif; ?>

    <?php if ($results === []): ?>
        <p class="muted">Noch keine Testergebnisse erfasst.</p>
    <?php endif; ?>

    <?php foreach ($results as $testErgebnisse): ?>
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
            <details class="test-desc test-desc--head">
                <summary title="Klick zeigt die Beschreibung">
                    <h3><?= e($erster['test_name']) ?>
                        <span class="badge badge--ok" title="Bestwert (<?= (int) $erster['higher_is_better'] === 1 ? 'höher' : 'niedriger' ?> = besser)">
                            Bestwert: <?= e($zahl($best)) ?> <?= e($erster['unit']) ?>
                        </span>
                    </h3>
                </summary>
                <p class="muted"><?= e($beschreibung) ?></p>
            </details>
        <?php else: ?>
            <h3 style="margin-top:1.1rem">
                <?= e($erster['test_name']) ?>
                <span class="badge badge--ok" title="Bestwert (<?= (int) $erster['higher_is_better'] === 1 ? 'höher' : 'niedriger' ?> = besser)">
                    Bestwert: <?= e($zahl($best)) ?> <?= e($erster['unit']) ?>
                </span>
            </h3>
        <?php endif; ?>

        <?php if (count($punkte) > 1): ?>
            <?= svg_line_chart($punkte, ['unit' => (string) $erster['unit']]) ?>
        <?php endif; ?>

        <div class="table-scroll">
            <table class="table table--compact">
                <thead><tr><th>Datum</th><th class="num">Wert</th><th>Notiz</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($testErgebnisse) as $r): ?>
                    <tr>
                        <td><?= e(format_date((string) $r['tested_on'])) ?></td>
                        <td class="num<?= (float) $r['value'] === $best ? ' strong is-plus' : '' ?>">
                            <?= e($zahl((float) $r['value'])) ?> <?= e($r['unit']) ?>
                        </td>
                        <td><?= e($r['note']) ?></td>
                        <td class="row-actions">
                            <?php if ($canEdit): ?>
                                <form method="post" class="inline"
                                      action="<?= e(url('/admin/mitglieder/' . $id . '/leistungstest-loeschen')) ?>"
                                      data-confirm="Testergebnis löschen?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="result_id" value="<?= (int) $r['id'] ?>">
                                    <button class="linklike linklike--danger" type="submit">löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
