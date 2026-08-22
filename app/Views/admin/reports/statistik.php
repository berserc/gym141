<?php

/**
 * @var list<array<string,mixed>> $ageBands
 * @var list<array<string,mixed>> $byGender
 * @var list<array<string,mixed>> $bySection
 * @var list<array<string,mixed>> $feeSummary
 * @var int                       $feeYear
 */
$bandOrder = ['bis 5', '6–9', '10–13', '14–17', '18–26', '27–40', '41–60', '61+', 'unbekannt'];

$bands = [];
foreach ($ageBands as $row) {
    $bands[(string) $row['band']] = (int) $row['n'];
}

$maxBand = $bands === [] ? 1 : max(1, max($bands));

$genderLabels = ['w' => 'weiblich', 'm' => 'männlich', 'd' => 'divers', 'unbekannt' => 'ohne Angabe'];

$maxSection = 1;
foreach ($bySection as $row) {
    $maxSection = max($maxSection, (int) $row['n']);
}
?>
<div class="page-head">
    <h1>Statistik</h1>
    <p class="page-head__sub">Basis: aktive Mitglieder</p>
</div>

<div class="stat-grid">
    <a class="stat stat--ok" href="<?= e(url('/admin/mitglieder', ['status' => 'aktiv'])) ?>">
        <span class="stat__value"><?= (int) $stats['aktiv'] ?></span>
        <span class="stat__label">aktive Mitglieder</span>
    </a>
    <a class="stat" href="<?= e(url('/admin/mitglieder', ['status' => 'inaktiv'])) ?>">
        <span class="stat__value"><?= (int) $stats['inaktiv'] ?></span>
        <span class="stat__label">inaktiv</span>
    </a>
    <a class="stat" href="<?= e(url('/admin/mitglieder', ['trainer' => '1'])) ?>">
        <span class="stat__value"><?= (int) $stats['trainer'] ?></span>
        <span class="stat__label">Trainer</span>
    </a>
    <a class="stat stat--info" href="<?= e(url('/admin/mitglieder', ['paused' => '1'])) ?>">
        <span class="stat__value"><?= (int) $stats['ausgesetzt'] ?></span>
        <span class="stat__label">derzeit ausgesetzt (beitragsfrei)</span>
    </a>
    <a class="stat" href="<?= e(url('/admin/mitglieder', ['archived' => '1'])) ?>">
        <span class="stat__value"><?= (int) $stats['ehemalig'] ?></span>
        <span class="stat__label">ehemalige Mitglieder</span>
    </a>
    <a class="stat<?= (int) $feeStats['count'] > 0 ? ' stat--danger' : ' stat--ok' ?>" href="<?= e(url('/admin/beitraege')) ?>">
        <span class="stat__value"><?= (int) $feeStats['count'] ?></span>
        <span class="stat__label">fällige offene Beiträge</span>
    </a>
    <a class="stat<?= $feeStats['sum'] > 0 ? ' stat--danger' : ' stat--ok' ?>" href="<?= e(url('/admin/beitraege')) ?>">
        <span class="stat__value"><?= e(format_money($feeStats['sum'])) ?></span>
        <span class="stat__label">offener Betrag (<?= (int) $feeStats['members'] ?> Mitglieder)</span>
    </a>
</div>

<div class="form-grid">
    <div class="card">
        <h2>Altersstruktur</h2>

        <ul class="bars">
            <?php foreach ($bandOrder as $band): ?>
                <?php $n = $bands[$band] ?? 0; ?>
                <li class="bars__item">
                    <span class="bars__label"><?= e($band) ?></span>
                    <span class="bars__track">
                        <span class="bars__fill" style="width: <?= (int) round($n / $maxBand * 100) ?>%"></span>
                    </span>
                    <span class="bars__value"><?= $n ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h2>Geschlecht</h2>

        <table class="table">
            <tbody>
            <?php foreach ($byGender as $row): ?>
                <tr>
                    <td><?= e($genderLabels[$row['gender']] ?? $row['gender']) ?></td>
                    <td class="num"><?= (int) $row['n'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($byGender === []): ?>
                <tr><td class="empty">Keine Daten.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Mitglieder je Sektion</h2>

    <ul class="bars">
        <?php foreach ($bySection as $row): ?>
            <li class="bars__item">
                <span class="bars__label"><?= e($row['name']) ?></span>
                <span class="bars__track">
                    <span class="bars__fill" style="width: <?= (int) round((int) $row['n'] / $maxSection * 100) ?>%"></span>
                </span>
                <span class="bars__value"><?= (int) $row['n'] ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card">
    <div class="card__head">
        <h2>Beiträge nach Jahr</h2>
        <p class="muted">Aktuelles Beitragsjahr: <?= (int) $feeYear ?></p>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Jahr</th>
                <th class="num">Erfasste Zeilen</th>
                <th class="num">davon bezahlt</th>
                <th class="num">Summe Soll</th>
                <th class="num">Summe bezahlt</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($feeSummary as $row): ?>
                <tr>
                    <td><?= (int) $row['year'] ?></td>
                    <td class="num"><?= (int) $row['zeilen'] ?></td>
                    <td class="num"><?= (int) $row['bezahlt'] ?></td>
                    <td class="num"><?= e(format_money($row['summe_soll'])) ?></td>
                    <td class="num"><?= e(format_money($row['summe_bezahlt'])) ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($feeSummary === []): ?>
                <tr><td colspan="5" class="empty">Noch keine Beiträge erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
