<?php

use App\Models\SportRepo;

/**
 * Erfolge & Statistik über alle Mitglieder: Kampfbilanzen (nach Sportart,
 * Alters- und Gewichtsklasse), Kraftdreikampf-Bestenliste, Auszeichnungen.
 *
 * @var array<string,string>      $filters
 * @var list<string>              $sports
 * @var list<array<string,mixed>> $bySport
 * @var list<array<string,mixed>> $byAge
 * @var list<array<string,mixed>> $byWeight
 * @var list<array<string,mixed>> $fighters
 * @var list<array<string,mixed>> $meetsByAge
 * @var list<array<string,mixed>> $meetsByWeight
 * @var list<array<string,mixed>> $meetBest
 * @var list<array<string,mixed>> $awards
 */
$kg = static fn ($v): string => $v === null ? '–' : number_format((float) $v, 1, ',', '.') . ' kg';

/** Bilanz-Tabelle (Kaempfe) fuer eine Gruppierung. */
$bilanzTabelle = static function (string $ueberschrift, array $zeilen): void {
    ?>
    <div class="card">
        <div class="card__head"><h2><?= e($ueberschrift) ?></h2></div>
        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr>
                    <th><?= e($ueberschrift) ?></th>
                    <th class="num">Kämpfe</th>
                    <th class="num">Sportler</th>
                    <th class="num">Siege</th>
                    <th class="num">Niederlagen</th>
                    <th class="num">Unentschieden</th>
                    <th class="num">KO/TKO-Siege</th>
                    <th class="num">Siegquote</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($zeilen as $zeile): ?>
                    <tr>
                        <td class="strong"><?= e($zeile['gruppe']) ?></td>
                        <td class="num"><?= (int) $zeile['kaempfe'] ?></td>
                        <td class="num"><?= (int) $zeile['sportler'] ?></td>
                        <td class="num is-plus"><?= (int) $zeile['siege'] ?></td>
                        <td class="num is-minus"><?= (int) $zeile['niederlagen'] ?></td>
                        <td class="num"><?= (int) $zeile['unentschieden'] ?></td>
                        <td class="num"><?= (int) $zeile['ko'] ?></td>
                        <td class="num"><?= $zeile['kaempfe'] > 0 ? round($zeile['siege'] / $zeile['kaempfe'] * 100) . ' %' : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($zeilen === []): ?>
                    <tr><td colspan="8" class="empty">Keine Kämpfe im gewählten Zeitraum.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
};
?>
<div class="page-head">
    <div>
        <h1>Erfolge &amp; Statistik</h1>
        <p class="page-head__sub">
            Bilanzen und Bestleistungen aller Mitglieder – Kämpfe werden auf der
            jeweiligen Mitgliedsseite unter „Erfolge &amp; Wettkämpfe“ erfasst.
        </p>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/erfolge')) ?>">
    <div class="filters__row">
        <div class="field field--sm">
            <label for="f-sport">Sportart (Kämpfe)</label>
            <select id="f-sport" name="sport">
                <option value="">alle</option>
                <?php foreach ($sports as $sport): ?>
                    <option value="<?= e($sport) ?>" <?= $filters['sport'] === $sport ? 'selected' : '' ?>><?= e($sport) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--sm">
            <label for="f-from">Von</label>
            <input id="f-from" type="date" name="from" value="<?= e($filters['from']) ?>">
        </div>

        <div class="field field--sm">
            <label for="f-to">Bis</label>
            <input id="f-to" type="date" name="to" value="<?= e($filters['to']) ?>">
        </div>

        <button class="btn btn--primary" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/erfolge')) ?>">Zurücksetzen</a>
    </div>
</form>

<?php $bilanzTabelle('Sportart', $bySport); ?>

<div class="form-grid">
    <?php $bilanzTabelle('Altersklasse', $byAge); ?>
    <?php $bilanzTabelle('Gewichtsklasse', $byWeight); ?>
</div>

<div class="card">
    <div class="card__head">
        <h2>Kämpferliste</h2>
        <p class="muted">Bilanz: Siege-Niederlagen-Unentschieden<?= $filters['sport'] !== '' ? ' (' . e($filters['sport']) . ')' : '' ?></p>
    </div>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr>
                <th>Sportler/in</th>
                <th class="num">Kämpfe</th>
                <th>Bilanz</th>
                <th class="num">KO/TKO</th>
                <th>letzter Kampf</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($fighters as $fighter): ?>
                <tr>
                    <td class="strong">
                        <a href="<?= e(url('/admin/mitglieder/' . $fighter['member_id'] . '/erfolge')) ?>">
                            <?= e($fighter['last_name'] . ', ' . $fighter['first_name']) ?>
                        </a>
                    </td>
                    <td class="num"><?= (int) $fighter['kaempfe'] ?></td>
                    <td><?= e(SportRepo::recordLabel((int) $fighter['siege'], (int) $fighter['niederlagen'], (int) $fighter['unentschieden'])) ?></td>
                    <td class="num"><?= (int) $fighter['ko'] ?></td>
                    <td><?= e(format_date($fighter['letzter_kampf'] === null ? null : (string) $fighter['letzter_kampf'])) ?></td>
                    <td class="row-actions">
                        <a href="<?= e(url('/admin/mitglieder/' . $fighter['member_id'] . '/erfolge')) ?>">Erfolge</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($fighters === []): ?>
                <tr><td colspan="6" class="empty">Noch keine Kämpfe erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Kraftdreikampf – Bestenliste</h2>
        <p class="muted">Bestes Total je Sportler/in (Summe der besten gültigen Versuche).</p>
    </div>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr>
                <th>Sportler/in</th>
                <th class="num">Total</th>
                <th class="num">Kniebeuge</th>
                <th class="num">Bankdrücken</th>
                <th class="num">Kreuzheben</th>
                <th class="num">Punkte</th>
                <th>Klassen</th>
                <th>Wettkampf</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($meetBest as $meet): ?>
                <tr>
                    <td class="strong">
                        <a href="<?= e(url('/admin/mitglieder/' . $meet['member_id'] . '/erfolge')) ?>">
                            <?= e($meet['last_name'] . ', ' . $meet['first_name']) ?>
                        </a>
                    </td>
                    <td class="num strong"><?= e($kg($meet['total'])) ?></td>
                    <td class="num"><?= e($kg($meet['best_squat'])) ?></td>
                    <td class="num"><?= e($kg($meet['best_bench'])) ?></td>
                    <td class="num"><?= e($kg($meet['best_dead'])) ?></td>
                    <td class="num"><?= $meet['points'] !== null ? e(number_format((float) $meet['points'], 2, ',', '.')) : '–' ?></td>
                    <td><?= e(trim($meet['age_class'] . ' ' . $meet['weight_class'])) ?></td>
                    <td>
                        <?= e($meet['event']) ?>
                        <small class="muted"><?= e(format_date($meet['meet_date'] === null ? null : (string) $meet['meet_date'])) ?></small>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($meetBest === []): ?>
                <tr><td colspan="8" class="empty">Noch keine Kraftdreikampf-Wettkämpfe erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="form-grid">
    <div class="card">
        <div class="card__head"><h2>Kraftdreikampf nach Altersklasse</h2></div>
        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr><th>Altersklasse</th><th class="num">Starts</th><th class="num">Sportler</th><th class="num">bestes Total</th><th class="num">beste Punkte</th></tr>
                </thead>
                <tbody>
                <?php foreach ($meetsByAge as $zeile): ?>
                    <tr>
                        <td class="strong"><?= e($zeile['gruppe']) ?></td>
                        <td class="num"><?= (int) $zeile['starts'] ?></td>
                        <td class="num"><?= (int) $zeile['sportler'] ?></td>
                        <td class="num"><?= e($kg($zeile['best_total'])) ?> <small class="muted"><?= e($zeile['best_total_name']) ?></small></td>
                        <td class="num"><?= $zeile['best_points'] !== null ? e(number_format($zeile['best_points'], 2, ',', '.')) : '–' ?> <small class="muted"><?= e($zeile['best_points_name']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($meetsByAge === []): ?>
                    <tr><td colspan="5" class="empty">Keine Wettkämpfe erfasst.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2>Kraftdreikampf nach Gewichtsklasse</h2></div>
        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr><th>Gewichtsklasse</th><th class="num">Starts</th><th class="num">Sportler</th><th class="num">bestes Total</th><th class="num">beste Punkte</th></tr>
                </thead>
                <tbody>
                <?php foreach ($meetsByWeight as $zeile): ?>
                    <tr>
                        <td class="strong"><?= e($zeile['gruppe']) ?></td>
                        <td class="num"><?= (int) $zeile['starts'] ?></td>
                        <td class="num"><?= (int) $zeile['sportler'] ?></td>
                        <td class="num"><?= e($kg($zeile['best_total'])) ?> <small class="muted"><?= e($zeile['best_total_name']) ?></small></td>
                        <td class="num"><?= $zeile['best_points'] !== null ? e(number_format($zeile['best_points'], 2, ',', '.')) : '–' ?> <small class="muted"><?= e($zeile['best_points_name']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($meetsByWeight === []): ?>
                    <tr><td colspan="5" class="empty">Keine Wettkämpfe erfasst.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2>Auszeichnungen</h2></div>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr><th>Datum</th><th>Sportler/in</th><th>Auszeichnung</th><th>Sportart</th><th>Notiz</th></tr>
            </thead>
            <tbody>
            <?php foreach ($awards as $award): ?>
                <tr>
                    <td><?= e(format_date($award['award_date'] === null ? null : (string) $award['award_date'])) ?></td>
                    <td class="strong">
                        <a href="<?= e(url('/admin/mitglieder/' . $award['member_id'] . '/erfolge')) ?>">
                            <?= e($award['last_name'] . ', ' . $award['first_name']) ?>
                        </a>
                    </td>
                    <td>🏆 <?= e($award['title']) ?></td>
                    <td><?= e($award['sport']) ?></td>
                    <td><?= e($award['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($awards === []): ?>
                <tr><td colspan="5" class="empty">Noch keine Auszeichnungen erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
