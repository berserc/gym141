<?php

/**
 * @var list<array<string,mixed>> $sektionen
 * @var array<string,float|int>   $summe
 * @var int                       $kindAlter
 * @var float                     $kindBetrag
 * @var float                     $anteil
 * @var float                     $gesamtBasis
 * @var float                     $ausgabenExtra
 * @var int                       $jahr
 * @var bool                      $darfBearbeiten
 * @var array<string,float>       $gesamt
 */
$prozent = (int) round($anteil * 100);
?>
<div class="page-head">
    <div>
        <h1>Beiträge und Förderung</h1>
        <p class="page-head__sub">
            Förderjahr <?= (int) $jahr ?> · Grundlage: aktive Mitglieder
            <?php if (!empty($abgeschlossen)): ?>
                · <strong>abgeschlossen</strong>
            <?php endif; ?>
        </p>
    </div>

    <div class="page-head__actions">
        <form method="get" action="<?= e(url('/admin/auswertung/foerderung')) ?>" class="inline">
            <label class="sr-only" for="jahr-wahl">Jahr</label>
            <select id="jahr-wahl" name="jahr" onchange="this.form.submit()">
                <?php foreach ($jahre as $j): ?>
                    <option value="<?= (int) $j ?>" <?= (int) $j === (int) $jahr ? 'selected' : '' ?>><?= (int) $j ?></option>
                <?php endforeach; ?>
                <?php for ($j = (int) date('Y') + 1; $j >= (int) date('Y') - 5; $j--): ?>
                    <?php if (!in_array($j, array_map('intval', $jahre), true)): ?>
                        <option value="<?= $j ?>"><?= $j ?></option>
                    <?php endif; ?>
                <?php endfor; ?>
            </select>
            <noscript><button class="btn btn--sm" type="submit">anzeigen</button></noscript>
        </form>

        <a class="btn" href="<?= e(url('/admin/auswertung/foerderung.xlsx', ['jahr' => $jahr])) ?>">Excel-Export</a>
    </div>
</div>

<?php if (!empty($abgeschlossen)): ?>
    <div class="notice notice--warn">
        Dieses Jahr ist <strong>abgeschlossen</strong>. Mitgliederzahlen, Beiträge und die berechnete
        Förderung sind eingefroren und ändern sich nicht mehr, auch wenn sich der Mitgliederstand
        weiterentwickelt. Für die laufende Rechnung bitte oben das aktuelle Jahr wählen.
    </div>
<?php endif; ?>

<div class="notice">
    <strong>Rechenweg je Sektion:</strong>
    Basisförderung <span class="rechen">+</span> <?= e(format_money($kindBetrag)) ?> je Kind bis <?= (int) $kindAlter ?> Jahre
    <span class="rechen">+</span> <?= $prozent ?> % der Beitragssumme
    <span class="rechen">=</span> Förderung.
    Beitragsfreie Sektionen werden mit einer Beitragssumme von 0 gerechnet.
</div>

<form method="post" action="<?= e(url('/admin/auswertung/foerderung')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="jahr" value="<?= (int) $jahr ?>">

    <div class="card">
        <div class="card__head">
            <h2>Förderung je Sektion</h2>
            <?php if ($darfBearbeiten): ?>
                <p class="muted">Basisförderung eintragen und unten speichern.</p>
            <?php endif; ?>
        </div>

        <div class="table-scroll">
            <table class="table table--funding">
                <thead>
                <tr>
                    <th>Sektion</th>
                    <th class="num">Aktiv</th>
                    <th class="num">Kinder<br><small>bis <?= (int) $kindAlter ?> J.</small></th>
                    <th class="num">Beitragssumme</th>
                    <th class="num">Basisförderung</th>
                    <th class="num"><span class="rechen">+</span> Kinder</th>
                    <th class="num"><span class="rechen">+</span> <?= $prozent ?> % Beiträge</th>
                    <th class="num"><span class="rechen">=</span> Förderung</th>
                    <th class="num">Auszahlung</th>
                    <th class="num">Aufrundung</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sektionen as $s): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/admin/mitglieder', ['section_id' => $s['id'], 'status' => 'aktiv'])) ?>">
                                <?= e($s['name']) ?>
                            </a>
                            <?php if ($s['fee_free']): ?>
                                <span class="badge">beitragsfrei</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $s['aktiv'] ?></td>
                        <td class="num"><?= (int) $s['kinder'] ?></td>
                        <td class="num"><?= e(format_money($s['beitraege'])) ?></td>
                        <td class="num">
                            <?php if ($darfBearbeiten): ?>
                                <input type="text" inputmode="decimal" class="input--money"
                                       name="base_funding[<?= (int) $s['id'] ?>]"
                                       value="<?= e(number_format((float) $s['basis'], 2, ',', '')) ?>"
                                       aria-label="Basisförderung <?= e($s['name']) ?>">
                            <?php else: ?>
                                <?= e(format_money($s['basis'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e(format_money($s['kinderfoerderung'])) ?></td>
                        <td class="num"><?= e(format_money($s['beitragsanteil'])) ?></td>
                        <td class="num strong"><?= e(format_money($s['foerderung'])) ?></td>
                        <td class="num">
                            <?php if ($darfBearbeiten): ?>
                                <input type="text" inputmode="decimal" class="input--money"
                                       name="paid_out[<?= (int) $s['id'] ?>]"
                                       value="<?= e(number_format((float) $s['auszahlung'], 2, ',', '')) ?>"
                                       placeholder="<?= e(number_format(ceil((float) $s['foerderung'] / 10) * 10, 2, ',', '')) ?>"
                                       aria-label="Auszahlung <?= e($s['name']) ?>">
                            <?php else: ?>
                                <?= e(format_money($s['auszahlung'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="num <?= $s['differenz'] >= 0 ? 'is-plus' : 'is-minus' ?>">
                            <?php if (abs((float) $s['auszahlung']) < 0.005): ?>
                                <span class="muted">–</span>
                            <?php else: ?>
                                <?= $s['differenz'] >= 0 ? '+' : '−' ?><?= e(format_money(abs((float) $s['differenz']))) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($sektionen === []): ?>
                    <tr><td colspan="10" class="empty">Keine Sektionen vorhanden.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th>Summe über alle Sektionen</th>
                    <th class="num"><?= (int) $summe['aktiv'] ?></th>
                    <th class="num"><?= (int) $summe['kinder'] ?></th>
                    <th class="num"><?= e(format_money($summe['beitraege'])) ?></th>
                    <th class="num"><?= e(format_money($summe['basis'])) ?></th>
                    <th class="num"><?= e(format_money($summe['kinderfoerderung'])) ?></th>
                    <th class="num"><?= e(format_money($summe['beitragsanteil'])) ?></th>
                    <th class="num"><?= e(format_money($summe['foerderung'])) ?></th>
                    <th class="num"><?= e(format_money($summe['auszahlung'])) ?></th>
                    <th class="num <?= $summe['differenz'] >= 0 ? 'is-plus' : 'is-minus' ?>">
                        <?php if ($summe['auszahlung'] < 0.005): ?>
                            <span class="muted">–</span>
                        <?php else: ?>
                            <?= $summe['differenz'] >= 0 ? '+' : '−' ?><?= e(format_money(abs((float) $summe['differenz']))) ?>
                        <?php endif; ?>
                    </th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="form-grid">
        <div class="card">
            <div class="card__head"><h2>Gesamtrechnung ATUS</h2></div>

            <table class="table table--ledger">
                <tbody>
                <tr>
                    <td>Basisförderung ATUS gesamt</td>
                    <td class="num is-plus">
                        <?php if ($darfBearbeiten): ?>
                            <input type="text" inputmode="decimal" class="input--money"
                                   name="funding_total_base"
                                   value="<?= e(number_format($gesamtBasis, 2, ',', '')) ?>"
                                   aria-label="Basisförderung ATUS gesamt">
                        <?php else: ?>
                            <?= e(format_money($gesamtBasis)) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Mitgliedsbeiträge der Sektionen</td>
                    <td class="num is-plus">+ <?= e(format_money($summe['beitraege'])) ?></td>
                </tr>
                <tr class="is-subtotal">
                    <th>Einnahmen</th>
                    <th class="num"><?= e(format_money($gesamt['einnahmen'])) ?></th>
                </tr>
                <tr>
                    <td>
                        Förderung an die Sektionen
                        <?php if ($summe['auszahlung'] > 0): ?>
                            <small class="muted">(tatsächliche Auszahlung)</small>
                        <?php else: ?>
                            <small class="muted">(rechnerisch, noch keine Auszahlung erfasst)</small>
                        <?php endif; ?>
                    </td>
                    <td class="num is-minus">− <?= e(format_money($ausbezahlt)) ?></td>
                </tr>
                <tr>
                    <td>Weitere Ausgaben</td>
                    <td class="num is-minus">
                        <?php if ($darfBearbeiten): ?>
                            <input type="text" inputmode="decimal" class="input--money"
                                   name="funding_expenses"
                                   value="<?= e(number_format($ausgabenExtra, 2, ',', '')) ?>"
                                   aria-label="Weitere Ausgaben">
                        <?php else: ?>
                            − <?= e(format_money($ausgabenExtra)) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="is-subtotal">
                    <th>Ausgaben</th>
                    <th class="num"><?= e(format_money($gesamt['ausgaben'])) ?></th>
                </tr>
                <tr class="is-total">
                    <th>Ergebnis</th>
                    <th class="num <?= $gesamt['ergebnis'] >= 0 ? 'is-plus' : 'is-minus' ?>">
                        <?= $gesamt['ergebnis'] >= 0 ? '+' : '−' ?><?= e(format_money(abs($gesamt['ergebnis']))) ?>
                    </th>
                </tr>
                </tbody>
            </table>
        </div>

        <?php if ($darfBearbeiten): ?>
            <div class="card">
                <div class="card__head"><h2>Rechengrößen</h2></div>

                <div class="field-row">
                    <div class="field field--xs">
                        <label for="child_bonus">Betrag je Kind</label>
                        <input id="child_bonus" name="funding_child_bonus" inputmode="decimal"
                               value="<?= e(number_format($kindBetrag, 2, ',', '')) ?>">
                    </div>

                    <div class="field field--xs">
                        <label for="child_age">Kind bis Alter</label>
                        <input id="child_age" name="funding_child_age" type="number" min="1" max="30"
                               value="<?= (int) $kindAlter ?>">
                    </div>

                    <div class="field field--xs">
                        <label for="fee_share">Anteil der Beiträge</label>
                        <input id="fee_share" name="funding_fee_share" inputmode="decimal"
                               value="<?= e(number_format($anteil, 2, ',', '')) ?>">
                        <p class="field__hint">0,75 entspricht 75 %.</p>
                    </div>
                </div>

                <p class="muted">
                    Ändert sich die Förderrichtlinie, genügt es, diese drei Werte anzupassen –
                    die Tabelle rechnet damit neu.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($darfBearbeiten): ?>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Förderwerte <?= (int) $jahr ?> speichern</button>
            <button class="btn btn--danger" type="submit" name="abschliessen" value="1"
                    data-confirm-click="Jahr <?= (int) $jahr ?> abschließen? Mitgliederzahlen, Beiträge und die berechnete Förderung werden eingefroren und lassen sich danach nicht mehr ändern.">
                Jahr <?= (int) $jahr ?> abschließen
            </button>
        </div>
        <p class="muted">
            Abschließen friert die Zahlen des Jahres ein, damit die Abrechnung nachvollziehbar bleibt.
            Für das Folgejahr oben einfach das nächste Jahr wählen – es wird mit dem aktuellen
            Mitgliederstand neu gerechnet, die Basisförderungen werden übernommen.
        </p>
    <?php endif; ?>
</form>
