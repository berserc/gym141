<?php

/**
 * @var list<array<string,mixed>> $rows
 * @var int                       $year
 * @var list<array<string,mixed>> $sections
 * @var array<string,mixed>       $filters
 */
$totals = ['mitglieder' => 0, 'aktiv' => 0, 'jugend' => 0, 'soll' => 0.0, 'bezahlt' => 0.0];

foreach ($rows as $row) {
    $totals['mitglieder'] += (int) $row['mitglieder'];
    $totals['aktiv']      += (int) $row['aktiv'];
    $totals['jugend']     += (int) $row['jugend'];
    $totals['soll']       += (float) $row['beitrag_soll'];
    $totals['bezahlt']    += (float) $row['beitrag_bezahlt'];
}
?>
<div class="page-head">
    <div>
        <h1>Abrechnung nach Gemeinde</h1>
        <p class="page-head__sub">Beitragsjahr <?= (int) $year ?></p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/auswertung/gemeinden.csv', ['year' => $year])) ?>">CSV-Export</a>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/auswertung/gemeinden')) ?>">
    <div class="filters__row">
        <div class="field field--xs">
            <label for="r-year">Jahr</label>
            <input id="r-year" name="year" type="number" min="1900" max="2200" value="<?= (int) $year ?>">
        </div>

        <div class="field">
            <label for="r-section">Sektion</label>
            <select id="r-section" name="section_id">
                <option value="">alle</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>"
                        <?= (int) $filters['section_id'] === (int) $section['id'] ? 'selected' : '' ?>>
                        <?= e($section['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="r-status">Status</label>
            <select id="r-status" name="status">
                <option value="">alle</option>
                <option value="aktiv"   <?= $filters['status'] === 'aktiv' ? 'selected' : '' ?>>nur aktive</option>
                <option value="inaktiv" <?= $filters['status'] === 'inaktiv' ? 'selected' : '' ?>>nur inaktive</option>
            </select>
        </div>

        <button class="btn btn--primary" type="submit">Anzeigen</button>
    </div>
</form>

<div class="card">
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Gemeinde</th>
                <th class="num">Mitglieder</th>
                <th class="num">davon aktiv</th>
                <th class="num">unter 18</th>
                <th class="num">Beitrag Soll</th>
                <th class="num">bezahlt <?= (int) $year ?></th>
                <th class="num">Zahlungen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('/admin/mitglieder', ['gemeinde' => $row['gemeinde'] === '(ohne Gemeinde)' ? '' : $row['gemeinde']])) ?>">
                            <?= e($row['gemeinde']) ?>
                        </a>
                    </td>
                    <td class="num"><?= (int) $row['mitglieder'] ?></td>
                    <td class="num"><?= (int) $row['aktiv'] ?></td>
                    <td class="num"><?= (int) $row['jugend'] ?></td>
                    <td class="num"><?= e(format_money($row['beitrag_soll'])) ?></td>
                    <td class="num"><?= e(format_money($row['beitrag_bezahlt'])) ?></td>
                    <td class="num"><?= (int) $row['anzahl_bezahlt'] ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="empty">Keine Daten für diese Auswahl.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($rows !== []): ?>
                <tfoot>
                <tr>
                    <th>Summe</th>
                    <th class="num"><?= (int) $totals['mitglieder'] ?></th>
                    <th class="num"><?= (int) $totals['aktiv'] ?></th>
                    <th class="num"><?= (int) $totals['jugend'] ?></th>
                    <th class="num"><?= e(format_money($totals['soll'])) ?></th>
                    <th class="num"><?= e(format_money($totals['bezahlt'])) ?></th>
                    <th></th>
                </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<p class="muted">
    „unter 18“ zählt Mitglieder, die zum heutigen Stichtag noch nicht 18 Jahre alt sind –
    maßgeblich für den Jugendtarif und die Jugendförderung der Gemeinden.
</p>
