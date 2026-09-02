<?php

use App\Controllers\BankController;

/**
 * Zahlungen aus dem Bankimport: excel-artige Tabelle mit Filtern
 * (Zeitraum, Betrag, Volltext, Mitglied, Status, Kategorie), Inline-
 * Zuordnung, Belegen und XLSX-Export der aktuellen Filterung.
 *
 * @var list<array<string,mixed>>           $rows
 * @var array<int,list<array<string,mixed>>> $files
 * @var array<string,string>                $filters
 * @var array<string,string>                $categories
 * @var array<string,mixed>                 $stats
 * @var list<array<string,mixed>>           $mitglieder
 */
$exportQuery = array_filter($filters, static fn (string $v): bool => $v !== '');
?>
<div class="page-head">
    <div>
        <h1>Zahlungen</h1>
        <p class="page-head__sub">
            Eingespielte Kontobewegungen zuordnen und auswerten.
            <?php if ((int) $stats['unbestimmt'] > 0): ?>
                <span class="badge badge--danger"><?= (int) $stats['unbestimmt'] ?> unbestimmt</span>
            <?php endif; ?>
            <?php if ((int) $stats['vorschlaege'] > 0): ?>
                <span class="badge"><?= (int) $stats['vorschlaege'] ?> Vorschläge</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if ((int) $stats['vorschlaege'] > 0): ?>
            <form method="post" action="<?= e(url('/admin/bank/vorschlaege-uebernehmen')) ?>" class="inline"
                  data-confirm="Alle <?= (int) $stats['vorschlaege'] ?> automatisch vorgeschlagenen Zuordnungen übernehmen?">
                <?= csrf_field() ?>
                <button class="btn" type="submit">Alle Vorschläge übernehmen</button>
            </form>
        <?php endif; ?>
        <a class="btn btn--ghost" href="<?= e(url('/admin/bank/export.xlsx', $exportQuery)) ?>">Excel-Export (Filterung)</a>
        <a class="btn btn--primary" href="<?= e(url('/admin/bank/import')) ?>">Kontoauszug einspielen</a>
    </div>
</div>

<datalist id="member-list">
    <?php foreach ($mitglieder as $m): ?>
        <option value="<?= e($m['last_name'] . ' ' . $m['first_name']) ?>">
            <?= (string) $m['member_no'] !== '' ? e('Nr. ' . $m['member_no']) : '' ?>
        </option>
    <?php endforeach; ?>
</datalist>

<div class="card">
    <form method="get" action="<?= e(url('/admin/bank')) ?>" class="inline-form" style="align-items:flex-end">
        <div class="field field--sm">
            <label>Zeitraum</label>
            <select name="zeitraum" onchange="this.form.von.value='';this.form.bis.value='';this.form.submit()">
                <option value="">alles / eigener Zeitraum</option>
                <?php foreach (['monat' => 'dieser Monat', 'quartal' => 'dieses Quartal', 'jahr' => 'dieses Jahr'] as $wert => $label): ?>
                    <option value="<?= $wert ?>" <?= $filters['zeitraum'] === $wert ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field field--sm">
            <label>von</label>
            <input type="date" name="von" value="<?= e($filters['von']) ?>">
        </div>
        <div class="field field--sm">
            <label>bis</label>
            <input type="date" name="bis" value="<?= e($filters['bis']) ?>">
        </div>
        <div class="field field--xs">
            <label>Betrag von</label>
            <input name="betrag_von" inputmode="decimal" value="<?= e($filters['betrag_von']) ?>" placeholder="z. B. 10">
        </div>
        <div class="field field--xs">
            <label>Betrag bis</label>
            <input name="betrag_bis" inputmode="decimal" value="<?= e($filters['betrag_bis']) ?>" placeholder="z. B. 100">
        </div>
        <div class="field field--grow">
            <label>Volltext (Zweck, Name, IBAN, Notiz)</label>
            <input name="q" value="<?= e($filters['q']) ?>" placeholder="suchen …">
        </div>
        <div class="field field--sm">
            <label>Mitglied</label>
            <input name="mitglied" list="member-list" value="<?= e($filters['mitglied']) ?>" placeholder="Zuname Vorname">
        </div>
        <div class="field field--sm">
            <label>Status</label>
            <select name="status">
                <option value="">alle</option>
                <option value="offen" <?= $filters['status'] === 'offen' ? 'selected' : '' ?>>zu bearbeiten</option>
                <option value="unbestimmt" <?= $filters['status'] === 'unbestimmt' ? 'selected' : '' ?>>unbestimmt</option>
                <option value="vorgeschlagen" <?= $filters['status'] === 'vorgeschlagen' ? 'selected' : '' ?>>vorgeschlagen</option>
                <option value="uebernommen" <?= $filters['status'] === 'uebernommen' ? 'selected' : '' ?>>übernommen</option>
            </select>
        </div>
        <div class="field field--sm">
            <label>Kategorie</label>
            <select name="kategorie">
                <option value="">alle</option>
                <?php foreach ($categories as $wert => $label): ?>
                    <option value="<?= e($wert) ?>" <?= $filters['kategorie'] === $wert ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/bank')) ?>">Zurücksetzen</a>
    </form>
</div>

<div class="card">
    <p class="muted">
        <?= count($rows) ?> Zahlung(en)<?= count($rows) === 1000 ? ' (Anzeige auf 1000 begrenzt – bitte Filter eingrenzen)' : '' ?>
        · Summe: <strong><?= e(number_format((float) $stats['summe'], 2, ',', '.')) ?> €</strong>
    </p>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr>
                <th>Datum</th>
                <th class="num">Betrag</th>
                <th>Gegenpartei / Verwendungszweck</th>
                <th>Zuordnung</th>
                <th>Belege</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $id = (int) $r['id']; ?>
                <tr>
                    <td style="white-space:nowrap"><?= e(format_date((string) $r['booked_on'])) ?></td>
                    <td class="num" style="white-space:nowrap;color:<?= (float) $r['amount'] < 0 ? '#c0392b' : '#1e7d46' ?>">
                        <strong><?= e(number_format((float) $r['amount'], 2, ',', '.')) ?></strong>
                        <?= $r['currency'] !== 'EUR' ? e((string) $r['currency']) : '€' ?>
                    </td>
                    <td>
                        <?= (string) $r['counterpart'] !== '' ? '<strong>' . e($r['counterpart']) . '</strong><br>' : '' ?>
                        <span class="muted"><?= e(mb_strimwidth((string) $r['reference'], 0, 120, '…')) ?></span>
                        <?php if ((string) $r['note'] !== ''): ?>
                            <br><em class="muted">📝 <?= e($r['note']) ?></em>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:16rem">
                        <?php if ($r['status'] === 'uebernommen'): ?>
                            <span class="pill pill--aktiv">übernommen</span>
                            <?php if ($r['member_id'] !== null): ?>
                                <a href="<?= e(url('/admin/mitglieder/' . (int) $r['member_id'])) ?>">
                                    <?= e($r['last_name'] . ' ' . $r['first_name']) ?></a>
                            <?php endif; ?>
                            <?php if ((string) $r['category'] !== ''): ?>
                                <span class="badge"><?= e($categories[(string) $r['category']] ?? $r['category']) ?></span>
                            <?php endif; ?>
                            <?php if ((string) ($r['settled_info'] ?? '') !== ''): ?>
                                <br><em class="muted">✓ <?= e((string) $r['settled_info']) ?></em>
                            <?php endif; ?>
                            <form method="post" class="inline" action="<?= e(url('/admin/bank/' . $id . '/zuordnen')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="aktion" value="zuruecksetzen">
                                <button class="linklike" type="submit">ändern</button>
                            </form>
                        <?php else: ?>
                            <?php if ($r['status'] === 'vorgeschlagen'): ?>
                                <span class="pill pill--offen">Vorschlag</span>
                            <?php else: ?>
                                <span class="badge badge--danger">unbestimmt</span>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/admin/bank/' . $id . '/zuordnen')) ?>" class="inline-form" style="margin-top:.3rem">
                                <?= csrf_field() ?>
                                <div class="field field--sm">
                                    <input name="member_ref" list="member-list" placeholder="Mitglied …"
                                           value="<?= $r['member_id'] !== null ? e($r['last_name'] . ' ' . $r['first_name']) : '' ?>">
                                </div>
                                <div class="field field--sm">
                                    <select name="category">
                                        <option value="">– Kategorie –</option>
                                        <?php foreach ($categories as $wert => $label): ?>
                                            <option value="<?= e($wert) ?>" <?= ($r['member_id'] !== null && $wert === 'mitgliedsbeitrag') ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field field--sm">
                                    <input name="note" placeholder="Notiz" value="<?= e((string) $r['note']) ?>">
                                </div>
                                <?php if ((float) $r['amount'] > 0): ?>
                                    <label class="check" title="Bei Kategorie Mitgliedsbeitrag: offene Beitragsperioden des Mitglieds mit dieser Zahlung als bezahlt verbuchen (inkl. Kassabuch)">
                                        <input type="checkbox" name="settle" value="1" checked> Beiträge ausgleichen
                                    </label>
                                <?php endif; ?>
                                <button class="btn btn--sm" type="submit">Übernehmen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:9rem">
                        <?php foreach ($files[$id] ?? [] as $f): ?>
                            <div style="white-space:nowrap">
                                📎 <a href="<?= e(url('/admin/bank/beleg/' . (int) $f['id'])) ?>" target="_blank" rel="noopener">
                                    <?= e(mb_strimwidth((string) $f['filename'], 0, 22, '…')) ?></a>
                                <form method="post" class="inline" action="<?= e(url('/admin/bank/beleg/' . (int) $f['id'] . '/loeschen')) ?>"
                                      data-confirm="Beleg löschen?">
                                    <?= csrf_field() ?>
                                    <button class="linklike linklike--danger" type="submit">×</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" action="<?= e(url('/admin/bank/' . $id . '/beleg')) ?>"
                              enctype="multipart/form-data" class="inline">
                            <?= csrf_field() ?>
                            <label class="linklike" style="cursor:pointer">
                                + Beleg
                                <input type="file" name="datei" hidden onchange="this.form.submit()">
                            </label>
                        </form>
                    </td>
                    <td class="muted" style="font-size:.75rem"><?= (string) $r['iban'] !== '' ? e(mb_strimwidth((string) $r['iban'], 0, 10, '…')) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="muted">Keine Zahlungen für diese Filterung –
                    <a href="<?= e(url('/admin/bank/import')) ?>">Kontoauszug einspielen</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
