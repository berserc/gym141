<?php

/**
 * Auswertung: Monatsübersicht und Einnahmen-/Ausgaben-Rechnung je Kategorie,
 * inklusive Prognose (geplante Beiträge und Fixkosten bis zum Ende des Zeitraums).
 *
 * @var string $from
 * @var string $to
 * @var array{in:float,out:float,saldo:float,count:int} $sums
 * @var list<array{monat:string,ein:float,aus:float,saldo:float}> $monthly
 * @var array{in:list<array{category:string,summe:float}>,out:list<array{category:string,summe:float}>} $byCategory
 * @var float $balance
 * @var array<string,array{in:float,out:float}> $planned
 * @var float $plannedIn
 * @var float $plannedOut
 */
$monatsname = static function (string $ym): string {
    $namen = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    [$jahr, $monat] = explode('-', $ym);

    return ($namen[(int) $monat] ?? $monat) . ' ' . $jahr;
};

$jahr = (int) substr($from, 0, 4);

// Monatszeilen: echte Buchungen und Prognose zusammenfuehren (auch Monate,
// die nur geplante Betraege haben, sollen erscheinen).
$real = [];
foreach ($monthly as $zeile) {
    $real[$zeile['monat']] = $zeile;
}

$monate = array_unique(array_merge(array_keys($real), array_keys($planned)));
sort($monate);

// Prognose immer zeigen, sobald der Zeitraum in die Zukunft reicht –
// auch wenn (noch) keine geplanten Betraege anfallen.
$hatPrognose = $to >= date('Y-m-d') || $plannedIn > 0.004 || $plannedOut > 0.004;
$voraussichtlich = $sums['saldo'] + $plannedIn - $plannedOut;

// Monatsnavigation: Zeigt die Ansicht genau EINEN Kalendermonat, geht es von
// diesem aus vor/zurueck. Bei jeder anderen Auswahl (z. B. Jahresansicht)
// dient der aktuelle Monat als Ausgangspunkt – sonst spraenge "Naechster
// Monat" von der Jahresansicht aus in den Februar.
$istMonatsansicht = $from === substr($from, 0, 7) . '-01'
    && $to === date('Y-m-t', (int) strtotime($from));
$startMonat   = $istMonatsansicht ? substr($from, 0, 7) . '-01' : date('Y-m-01');
$vorigerVon   = date('Y-m-01', (int) strtotime($startMonat . ' -1 month'));
$naechsterVon = date('Y-m-01', (int) strtotime($startMonat . ' +1 month'));
?>
<?= admin_tabs([
    ['Kassabuch', '/admin/buchhaltung'],
    ['Auswertung', '/admin/buchhaltung/auswertung'],
    ['Fixkosten', '/admin/buchhaltung/fixkosten'],
    ['Zahlungsarten', '/admin/buchhaltung/zahlungsarten'],
]) ?>

<div class="page-head">
    <div>
        <h1>Auswertung</h1>
        <p class="page-head__sub">
            Zeitraum <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>
            · Kassastand gesamt: <strong><?= e(format_money($balance)) ?></strong>
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/buchhaltung/export.csv', ['from' => $from, 'to' => $to])) ?>">CSV-Export</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung')) ?>">Zur Buchhaltung</a>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/buchhaltung/auswertung')) ?>">
    <div class="filters__row">
        <div class="field field--sm">
            <label for="f-from">Von</label>
            <input id="f-from" type="date" name="from" value="<?= e($from) ?>">
        </div>

        <div class="field field--sm">
            <label for="f-to">Bis</label>
            <input id="f-to" type="date" name="to" value="<?= e($to) ?>">
        </div>

        <button class="btn btn--primary" type="submit">Anzeigen</button>

        <a class="btn btn--ghost" title="Monat <?= e($monatsname(substr($vorigerVon, 0, 7))) ?>"
           href="<?= e(url('/admin/buchhaltung/auswertung', ['from' => $vorigerVon, 'to' => date('Y-m-t', (int) strtotime($vorigerVon))])) ?>">‹ Voriger Monat</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung/auswertung', ['from' => date('Y-m-01'), 'to' => date('Y-m-t')])) ?>">Dieser Monat</a>
        <a class="btn btn--ghost" title="Monat <?= e($monatsname(substr($naechsterVon, 0, 7))) ?>"
           href="<?= e(url('/admin/buchhaltung/auswertung', ['from' => $naechsterVon, 'to' => date('Y-m-t', (int) strtotime($naechsterVon))])) ?>">Nächster Monat ›</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung/auswertung', ['from' => date('Y') . '-01-01', 'to' => date('Y') . '-12-31'])) ?>">Jahresvorschau <?= date('Y') ?></a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/buchhaltung/auswertung', ['from' => ($jahr - 1) . '-01-01', 'to' => ($jahr - 1) . '-12-31'])) ?>">Jahr <?= $jahr - 1 ?></a>
    </div>
</form>

<div class="stat-grid">
    <div class="stat stat--ok">
        <span class="stat__value">
            <?= e(format_money($sums['in'])) ?>
            <?php if ($plannedIn > 0.004): ?>
                <small class="stat__plan">(+ <?= e(format_money($plannedIn)) ?> geplant)</small>
            <?php endif; ?>
        </span>
        <span class="stat__label">Einnahmen im Zeitraum</span>
    </div>
    <div class="stat stat--warn">
        <span class="stat__value">
            <?= e(format_money($sums['out'])) ?>
            <?php if ($plannedOut > 0.004): ?>
                <small class="stat__plan">(+ <?= e(format_money($plannedOut)) ?> geplant)</small>
            <?php endif; ?>
        </span>
        <span class="stat__label">Ausgaben im Zeitraum</span>
    </div>
    <div class="stat <?= $sums['saldo'] >= 0 ? 'stat--ok' : 'stat--warn' ?>">
        <span class="stat__value"><?= e(format_money($sums['saldo'])) ?></span>
        <span class="stat__label"><?= $sums['saldo'] >= 0 ? 'Überschuss' : 'Abgang' ?> real (<?= (int) $sums['count'] ?> Buchungen)</span>
    </div>
    <?php if ($hatPrognose): ?>
        <div class="stat <?= $voraussichtlich >= 0 ? 'stat--ok' : 'stat--warn' ?>">
            <span class="stat__value"><?= e(format_money($voraussichtlich)) ?></span>
            <span class="stat__label">voraussichtlich (inkl. geplanter Beiträge und Fixkosten)</span>
        </div>
    <?php endif; ?>
</div>

<?php if ($hatPrognose): ?>
    <p class="muted" style="margin:-.4rem 0 1.2rem">
        Prognose: offene Beitragsforderungen, künftige Beitragsperioden aktiver Mitglieder
        und anstehende Fixkosten bis <?= e(format_date($to)) ?>.
        <?php if ($plannedIn <= 0.004 && $plannedOut <= 0.004): ?>
            Im gewählten Zeitraum stehen keine geplanten Beträge an
            (Fälligkeiten liegen außerhalb, sind bereits erfasst oder pausiert).
        <?php endif; ?>
    </p>
<?php endif; ?>

<div class="form-grid">
    <div class="card">
        <div class="card__head">
            <h2>Einnahmen nach Kategorie</h2>
        </div>

        <div class="table-scroll">
            <table class="table">
                <tbody>
                <?php foreach ($byCategory['in'] as $zeile): ?>
                    <tr>
                        <td><?= e($zeile['category']) ?></td>
                        <td class="num is-plus"><?= e(format_money($zeile['summe'])) ?></td>
                        <td class="row-actions">
                            <a href="<?= e(url('/admin/buchhaltung', ['type' => 'einnahme', 'category' => $zeile['category'] === '(ohne Kategorie)' ? '' : $zeile['category'], 'from' => $from, 'to' => $to])) ?>">Buchungen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byCategory['in'] === []): ?>
                    <tr><td colspan="3" class="empty">Keine Einnahmen im Zeitraum.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr><th>Summe Einnahmen</th><th class="num is-plus"><?= e(format_money($sums['in'])) ?></th><th></th></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Ausgaben nach Kategorie</h2>
        </div>

        <div class="table-scroll">
            <table class="table">
                <tbody>
                <?php foreach ($byCategory['out'] as $zeile): ?>
                    <tr>
                        <td><?= e($zeile['category']) ?></td>
                        <td class="num is-minus"><?= e(format_money($zeile['summe'])) ?></td>
                        <td class="row-actions">
                            <a href="<?= e(url('/admin/buchhaltung', ['type' => 'ausgabe', 'category' => $zeile['category'] === '(ohne Kategorie)' ? '' : $zeile['category'], 'from' => $from, 'to' => $to])) ?>">Buchungen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byCategory['out'] === []): ?>
                    <tr><td colspan="3" class="empty">Keine Ausgaben im Zeitraum.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr><th>Summe Ausgaben</th><th class="num is-minus"><?= e(format_money($sums['out'])) ?></th><th></th></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Monatsübersicht</h2>
        <p class="muted">Ein Klick auf den Monat zeigt die zugehörigen Buchungen.</p>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Monat</th>
                <th class="num">Einnahmen<?= $hatPrognose ? ' <small>(+ geplant)</small>' : '' ?></th>
                <th class="num">Ausgaben<?= $hatPrognose ? ' <small>(+ geplant)</small>' : '' ?></th>
                <th class="num">Saldo real</th>
                <?php if ($hatPrognose): ?>
                    <th class="num">voraussichtlich</th>
                <?php endif; ?>
                <th class="num">kumuliert<?= $hatPrognose ? ' <small>(vorauss.)</small>' : '' ?></th>
            </tr>
            </thead>
            <tbody>
            <?php $kumuliert = 0.0; ?>
            <?php foreach ($monate as $monat): ?>
                <?php
                $zeile  = $real[$monat] ?? ['ein' => 0.0, 'aus' => 0.0, 'saldo' => 0.0];
                $planIn  = $planned[$monat]['in'] ?? 0.0;
                $planOut = $planned[$monat]['out'] ?? 0.0;
                $erwartet = $zeile['saldo'] + $planIn - $planOut;
                $kumuliert += $erwartet;
                $start = $monat . '-01';
                $ende  = date('Y-m-t', (int) strtotime($start));
                ?>
                <tr>
                    <td>
                        <a class="strong" href="<?= e(url('/admin/buchhaltung', ['from' => $start, 'to' => $ende])) ?>">
                            <?= e($monatsname($monat)) ?>
                        </a>
                    </td>
                    <td class="num is-plus">
                        <?= e(format_money($zeile['ein'])) ?>
                        <?php if ($planIn > 0.004): ?>
                            <small class="muted">(+ <?= e(format_money($planIn)) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td class="num is-minus">
                        <?= e(format_money($zeile['aus'])) ?>
                        <?php if ($planOut > 0.004): ?>
                            <small class="muted">(+ <?= e(format_money($planOut)) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td class="num <?= $zeile['saldo'] >= 0 ? 'is-plus' : 'is-minus' ?>"><?= e(format_money($zeile['saldo'])) ?></td>
                    <?php if ($hatPrognose): ?>
                        <td class="num <?= $erwartet >= 0 ? 'is-plus' : 'is-minus' ?>"><?= e(format_money($erwartet)) ?></td>
                    <?php endif; ?>
                    <td class="num"><?= e(format_money($kumuliert)) ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($monate === []): ?>
                <tr><td colspan="<?= $hatPrognose ? 6 : 5 ?>" class="empty">Keine Buchungen im Zeitraum.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
            <tr>
                <th>Summe</th>
                <th class="num is-plus">
                    <?= e(format_money($sums['in'])) ?>
                    <?php if ($plannedIn > 0.004): ?>
                        <small>(+ <?= e(format_money($plannedIn)) ?>)</small>
                    <?php endif; ?>
                </th>
                <th class="num is-minus">
                    <?= e(format_money($sums['out'])) ?>
                    <?php if ($plannedOut > 0.004): ?>
                        <small>(+ <?= e(format_money($plannedOut)) ?>)</small>
                    <?php endif; ?>
                </th>
                <th class="num"><?= e(format_money($sums['saldo'])) ?></th>
                <?php if ($hatPrognose): ?>
                    <th class="num"><?= e(format_money($voraussichtlich)) ?></th>
                <?php endif; ?>
                <th></th>
            </tr>
            </tfoot>
        </table>
    </div>
</div>
