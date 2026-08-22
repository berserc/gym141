<?php

use App\Models\SportRepo;

/**
 * Erfolge & Wettkämpfe eines Mitglieds: Kämpfe mit Bilanz, Kraftdreikampf
 * mit Versuchen, Auszeichnungen.
 *
 * @var array<string,mixed>       $member
 * @var list<array<string,mixed>> $record
 * @var list<array<string,mixed>> $fights
 * @var list<array<string,mixed>> $meets
 * @var list<array<string,mixed>> $awards
 * @var array<string,array<int,list<array<string,mixed>>>> $media Links/Dokumente je Eintrag
 * @var bool                      $canEdit
 * @var list<string>              $sports
 * @var list<string>              $styles
 * @var list<string>              $ageClasses
 * @var array<string,string>      $results
 * @var list<string>              $methods
 */
$id = (int) $member['id'];

$kg = static fn ($v): string => $v === null || $v === '' ? '–' : number_format((float) $v, 1, ',', '.') . ' kg';

/** Versuch: ungueltige (negative) rot durchgestrichen. */
$attempt = static function ($v): string {
    if ($v === null || $v === '') {
        return '<span class="muted">–</span>';
    }

    $wert = (float) $v;

    return $wert < 0
        ? '<s class="is-minus" title="ungültiger Versuch">' . e(number_format(abs($wert), 1, ',', '.')) . '</s>'
        : e(number_format($wert, 1, ',', '.'));
};

/**
 * Links & Dokumente eines Eintrags: Chips (YouTube-Links als Mini-Player)
 * plus Formular zum Anhaengen beliebig vieler weiterer.
 */
$mediaCell = static function (string $kind, int $refId) use ($media, $canEdit, $id): void {
    $eintraege = $media[$kind][$refId] ?? [];
    ?>
    <div class="media-chips">
        <?php foreach ($eintraege as $m): ?>
            <span class="media-chip">
                <?php if ((string) $m['type'] === 'link'): ?>
                    <?php $istVideo = preg_match('#(youtube\.com|youtu\.be)/#i', (string) $m['url']) === 1; ?>
                    <?php if ($istVideo): ?>
                        <button type="button" class="media-chip__main" data-video="<?= e($m['url']) ?>"
                                title="Video im Mini-Player abspielen">▶&nbsp;<?= e((string) $m['label'] !== '' ? $m['label'] : 'Video') ?></button>
                    <?php else: ?>
                        <a class="media-chip__main" href="<?= e($m['url']) ?>" target="_blank" rel="noopener noreferrer">
                            🔗&nbsp;<?= e((string) $m['label'] !== '' ? $m['label'] : preg_replace('#^https?://(www\.)?#i', '', (string) $m['url'])) ?>
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="media-chip__main" href="<?= e(url('/admin/mitglieder/' . $id . '/erfolg-datei/' . $m['id'])) ?>"
                       target="_blank" title="<?= e($m['file_name']) ?>">
                        📄&nbsp;<?= e((string) $m['label'] !== '' ? $m['label'] : $m['file_name']) ?>
                    </a>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <form method="post" class="inline"
                          action="<?= e(url('/admin/mitglieder/' . $id . '/erfolg-medien-loeschen')) ?>"
                          data-confirm="„<?= e((string) $m['label'] !== '' ? $m['label'] : ($m['file_name'] ?: $m['url'])) ?>“ entfernen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="media_id" value="<?= (int) $m['id'] ?>">
                        <button class="media-chip__remove" type="submit" title="entfernen">×</button>
                    </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>

        <?php if ($canEdit): ?>
            <details class="media-add">
                <summary title="Link oder Dokument anhängen">+</summary>
                <form method="post" enctype="multipart/form-data"
                      action="<?= e(url('/admin/mitglieder/' . $id . '/erfolg-medien')) ?>" class="media-add__form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="<?= e($kind) ?>">
                    <input type="hidden" name="ref_id" value="<?= (int) $refId ?>">

                    <label>Link (Ergebnisliste, YouTube …)
                        <input name="url" placeholder="https://…">
                    </label>
                    <label>Bezeichnung
                        <input name="label" placeholder="z. B. Kampfvideo, Ergebnisliste">
                    </label>
                    <label>… oder Datei (max. 15 MB)
                        <input name="file" type="file">
                    </label>
                    <label>… oder aus der Dateiablage
                        <input type="hidden" name="media_file_id" value="">
                        <span class="media-add__pickrow">
                            <button type="button" class="btn btn--sm js-open-picker"
                                    data-picker-url="<?= e(url('/admin/dateien', ['picker' => 1])) ?>">📁 Datei wählen</button>
                            <span class="js-picked muted"></span>
                        </span>
                    </label>
                    <button class="btn btn--sm" type="submit">Anhängen</button>
                </form>
            </details>
        <?php elseif ($eintraege === []): ?>
            <span class="muted">–</span>
        <?php endif; ?>
    </div>
    <?php
};

/** Formularfelder eines Kampfs (fuer Neu und Bearbeiten). */
$fightFields = static function (array $f = []) use ($sports, $styles, $ageClasses, $results, $methods): void {
    $v = static fn (string $k, string $d = ''): string => e((string) ($f[$k] ?? $d));
    ?>
    <div class="field field--sm">
        <label>Sportart</label>
        <select name="sport">
            <?php foreach ($sports as $sport): ?>
                <option value="<?= e($sport) ?>" <?= ($f['sport'] ?? '') === $sport ? 'selected' : '' ?>><?= e($sport) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field--sm">
        <label>Stil</label>
        <input name="style" list="style-list" value="<?= $v('style') ?>" placeholder="z. B. K-1">
    </div>
    <div class="field field--sm">
        <label>Datum</label>
        <input name="fight_date" type="date" value="<?= $v('fight_date') ?>">
    </div>
    <div class="field field--sm">
        <label>Veranstaltung</label>
        <input name="event" value="<?= $v('event') ?>" placeholder="z. B. NAFN 3">
    </div>
    <div class="field field--sm">
        <label>Ort</label>
        <input name="location" value="<?= $v('location') ?>">
    </div>
    <div class="field field--sm">
        <label>Gegner</label>
        <input name="opponent" value="<?= $v('opponent') ?>">
    </div>
    <div class="field field--sm">
        <label>Verein des Gegners</label>
        <input name="opponent_club" value="<?= $v('opponent_club') ?>">
    </div>
    <div class="field field--xs">
        <label>Gewichtsklasse</label>
        <input name="weight_class" value="<?= $v('weight_class') ?>" placeholder="z. B. -63,5 kg">
    </div>
    <div class="field field--sm">
        <label>Altersklasse</label>
        <input name="age_class" list="ageclass-list" value="<?= $v('age_class') ?>">
    </div>
    <div class="field field--xs">
        <label>Runden</label>
        <input name="rounds" value="<?= $v('rounds') ?>" placeholder="3x2">
    </div>
    <div class="field field--sm">
        <label>Ergebnis</label>
        <select name="result">
            <?php foreach ($results as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($f['result'] ?? 'sieg') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field--sm">
        <label>Art</label>
        <input name="method" list="method-list" value="<?= $v('method') ?>" placeholder="Punkte, KO …">
    </div>
    <div class="field field--xs">
        <label>in Runde</label>
        <input name="end_round" type="number" min="1" max="15" value="<?= $v('end_round') ?>">
    </div>
    <div class="field field--grow">
        <label>Notiz</label>
        <input name="note" value="<?= $v('note') ?>">
    </div>
    <?php
};

/** Formularfelder eines KDK-Wettkampfs. */
$meetFields = static function (array $f = []) use ($ageClasses): void {
    $v = static fn (string $k): string => $f === [] ? '' : e((string) ($f[$k] ?? ''));
    ?>
    <div class="field field--sm">
        <label>Datum</label>
        <input name="meet_date" type="date" value="<?= $v('meet_date') ?>">
    </div>
    <div class="field field--sm">
        <label>Veranstaltung</label>
        <input name="event" value="<?= $v('event') ?>" placeholder="z. B. Landesmeisterschaft">
    </div>
    <div class="field field--sm">
        <label>Ort</label>
        <input name="location" value="<?= $v('location') ?>">
    </div>
    <div class="field field--sm">
        <label>Altersklasse</label>
        <input name="age_class" list="ageclass-list" value="<?= $v('age_class') ?>">
    </div>
    <div class="field field--xs">
        <label>Gewichtsklasse</label>
        <input name="weight_class" value="<?= $v('weight_class') ?>" placeholder="-93 kg">
    </div>
    <div class="field field--xs">
        <label>Körpergewicht</label>
        <input name="bodyweight" inputmode="decimal" value="<?= $v('bodyweight') ?>">
    </div>
    <?php foreach ([['squat', 'Kniebeuge'], ['bench', 'Bankdrücken'], ['dead', 'Kreuzheben']] as [$feld, $label]): ?>
        <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="field" style="max-width:95px">
                <label><?= e($label) ?> <?= $i ?></label>
                <input name="<?= e($feld) ?>_<?= $i ?>" inputmode="decimal" value="<?= $v($feld . '_' . $i) ?>">
            </div>
        <?php endfor; ?>
    <?php endforeach; ?>
    <div class="field field--xs">
        <label>Punkte</label>
        <input name="points" inputmode="decimal" value="<?= $v('points') ?>" placeholder="IPF-GL/DOTS">
    </div>
    <div class="field field--xs">
        <label>Platzierung</label>
        <input name="placement" value="<?= $v('placement') ?>" placeholder="1.">
    </div>
    <div class="field field--grow">
        <label>Notiz</label>
        <input name="note" value="<?= $v('note') ?>">
    </div>
    <?php
};
?>
<div class="page-head">
    <div>
        <h1>Erfolge &amp; Wettkämpfe</h1>
        <p class="page-head__sub">
            <a href="<?= e(url('/admin/mitglieder/' . $id)) ?>"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></a>
            <?php foreach ($record as $r): ?>
                · <strong><?= e($r['sport']) ?>:</strong>
                <?= e(SportRepo::recordLabel($r['siege'], $r['niederlagen'], $r['unentschieden'])) ?>
                <?= $r['ko'] > 0 ? '(' . (int) $r['ko'] . ' KO)' : '' ?>
            <?php endforeach; ?>
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/erfolge')) ?>">Gesamtstatistik</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/mitglieder/' . $id)) ?>">Zum Mitglied</a>
    </div>
</div>

<datalist id="style-list">
    <?php foreach ($styles as $stil): ?><option value="<?= e($stil) ?>"></option><?php endforeach; ?>
</datalist>
<datalist id="ageclass-list">
    <?php foreach ($ageClasses as $klasse): ?><option value="<?= e($klasse) ?>"></option><?php endforeach; ?>
</datalist>
<datalist id="method-list">
    <?php foreach ($methods as $methode): ?><option value="<?= e($methode) ?>"></option><?php endforeach; ?>
</datalist>

<div class="card">
    <div class="card__head">
        <h2>Kämpfe</h2>
        <?php if ($record !== []): ?>
            <p class="muted">Bilanz: Siege-Niederlagen-Unentschieden</p>
        <?php endif; ?>
    </div>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr>
                <th>Datum</th><th>Sportart / Stil</th><th>Veranstaltung</th><th>Gegner</th>
                <th>Gew.-Kl.</th><th>Altersklasse</th><th>Ergebnis</th><th>Notiz</th>
                <th>Links &amp; Dokumente</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($fights as $fight): ?>
                <tr>
                    <td><?= e(format_date($fight['fight_date'] === null ? null : (string) $fight['fight_date'])) ?></td>
                    <td>
                        <?= e($fight['sport']) ?>
                        <?= (string) $fight['style'] !== '' ? '<small class="muted">' . e($fight['style']) . '</small>' : '' ?>
                    </td>
                    <td>
                        <?= e($fight['event']) ?>
                        <?= (string) $fight['location'] !== '' ? '<small class="muted">' . e($fight['location']) . '</small>' : '' ?>
                    </td>
                    <td>
                        <?= e($fight['opponent']) ?>
                        <?= (string) $fight['opponent_club'] !== '' ? '<small class="muted">' . e($fight['opponent_club']) . '</small>' : '' ?>
                    </td>
                    <td><?= e($fight['weight_class']) ?></td>
                    <td><?= e($fight['age_class']) ?></td>
                    <td>
                        <span class="pill <?= $fight['result'] === 'sieg' ? 'pill--aktiv' : ($fight['result'] === 'niederlage' ? 'pill--offen' : '') ?>">
                            <?= e(SportRepo::RESULTS[$fight['result']] ?? $fight['result']) ?>
                        </span>
                        <small class="muted">
                            <?= e($fight['method']) ?><?= $fight['end_round'] !== null ? ', Rd. ' . (int) $fight['end_round'] : '' ?>
                            <?= (string) $fight['rounds'] !== '' ? '(' . e($fight['rounds']) . ')' : '' ?>
                        </small>
                    </td>
                    <td><?= e($fight['note']) ?></td>
                    <td><?php $mediaCell('fight', (int) $fight['id']); ?></td>
                    <td class="row-actions">
                        <?php if ($canEdit): ?>
                            <details class="plan-edit">
                                <summary class="linklike">bearbeiten</summary>
                                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/kampf/' . $fight['id'])) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <?php $fightFields($fight); ?>
                                    <button class="btn btn--sm" type="submit">Speichern</button>
                                </form>
                            </details>
                            <form method="post" class="inline" action="<?= e(url('/admin/mitglieder/' . $id . '/kampf-loeschen')) ?>"
                                  data-confirm="Kampf löschen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="fight_id" value="<?= (int) $fight['id'] ?>">
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($fights === []): ?>
                <tr><td colspan="10" class="empty">Noch keine Kämpfe erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit): ?>
        <details class="plan-edit">
            <summary class="linklike">+ Kampf erfassen</summary>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/kampf')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <?php $fightFields(); ?>
                <button class="btn btn--primary" type="submit">Erfassen</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head">
        <h2>Kraftdreikampf</h2>
        <p class="muted">Ungültige Versuche negativ eintragen (z. B. −105). Total = beste gültige Versuche.</p>
    </div>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr>
                <th>Datum</th><th>Veranstaltung</th><th>Klassen</th><th class="num">KG</th>
                <th class="num">Kniebeuge 1/2/3</th>
                <th class="num">Bankdrücken 1/2/3</th>
                <th class="num">Kreuzheben 1/2/3</th>
                <th class="num">Total</th><th class="num">Punkte</th><th>Platz</th>
                <th>Links &amp; Dok.</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($meets as $meet): ?>
                <tr>
                    <td><?= e(format_date($meet['meet_date'] === null ? null : (string) $meet['meet_date'])) ?></td>
                    <td>
                        <?= e($meet['event']) ?>
                        <?= (string) $meet['location'] !== '' ? '<small class="muted">' . e($meet['location']) . '</small>' : '' ?>
                    </td>
                    <td>
                        <?= e($meet['age_class']) ?>
                        <?= (string) $meet['weight_class'] !== '' ? '<small class="muted">' . e($meet['weight_class']) . '</small>' : '' ?>
                    </td>
                    <td class="num"><?= $meet['bodyweight'] !== null ? e(number_format((float) $meet['bodyweight'], 1, ',', '.')) : '–' ?></td>
                    <td class="num"><?= $attempt($meet['squat_1']) ?> / <?= $attempt($meet['squat_2']) ?> / <?= $attempt($meet['squat_3']) ?></td>
                    <td class="num"><?= $attempt($meet['bench_1']) ?> / <?= $attempt($meet['bench_2']) ?> / <?= $attempt($meet['bench_3']) ?></td>
                    <td class="num"><?= $attempt($meet['dead_1']) ?> / <?= $attempt($meet['dead_2']) ?> / <?= $attempt($meet['dead_3']) ?></td>
                    <td class="num strong"><?= $kg($meet['total']) ?></td>
                    <td class="num"><?= $meet['points'] !== null ? e(number_format((float) $meet['points'], 2, ',', '.')) : '–' ?></td>
                    <td><?= e($meet['placement']) ?></td>
                    <td><?php $mediaCell('meet', (int) $meet['id']); ?></td>
                    <td class="row-actions">
                        <?php if ($canEdit): ?>
                            <details class="plan-edit">
                                <summary class="linklike">bearbeiten</summary>
                                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/kdk/' . $meet['id'])) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <?php $meetFields($meet); ?>
                                    <button class="btn btn--sm" type="submit">Speichern</button>
                                </form>
                            </details>
                            <form method="post" class="inline" action="<?= e(url('/admin/mitglieder/' . $id . '/kdk-loeschen')) ?>"
                                  data-confirm="Wettkampf löschen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="meet_id" value="<?= (int) $meet['id'] ?>">
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($meets === []): ?>
                <tr><td colspan="12" class="empty">Noch keine Kraftdreikampf-Wettkämpfe erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit): ?>
        <details class="plan-edit">
            <summary class="linklike">+ Wettkampf erfassen</summary>
            <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/kdk')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <?php $meetFields(); ?>
                <button class="btn btn--primary" type="submit">Erfassen</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head">
        <h2>Auszeichnungen</h2>
    </div>

    <div class="table-scroll">
        <table class="table table--compact">
            <thead>
            <tr><th>Datum</th><th>Auszeichnung</th><th>Sportart</th><th>Notiz</th><th>Links &amp; Dokumente</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($awards as $award): ?>
                <tr>
                    <td><?= e(format_date($award['award_date'] === null ? null : (string) $award['award_date'])) ?></td>
                    <td class="strong">🏆 <?= e($award['title']) ?></td>
                    <td><?= e($award['sport']) ?></td>
                    <td><?= e($award['note']) ?></td>
                    <td><?php $mediaCell('award', (int) $award['id']); ?></td>
                    <td class="row-actions">
                        <?php if ($canEdit): ?>
                            <details class="plan-edit">
                                <summary class="linklike">bearbeiten</summary>
                                <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/auszeichnung/' . $award['id'])) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <div class="field field--sm">
                                        <label>Datum</label>
                                        <input name="award_date" type="date" value="<?= e((string) ($award['award_date'] ?? '')) ?>">
                                    </div>
                                    <div class="field field--grow">
                                        <label>Auszeichnung</label>
                                        <input name="title" required value="<?= e($award['title']) ?>">
                                    </div>
                                    <div class="field field--sm">
                                        <label>Sportart</label>
                                        <input name="sport" value="<?= e($award['sport']) ?>">
                                    </div>
                                    <div class="field field--grow">
                                        <label>Notiz</label>
                                        <input name="note" value="<?= e($award['note']) ?>">
                                    </div>
                                    <button class="btn btn--sm" type="submit">Speichern</button>
                                </form>
                            </details>
                            <form method="post" class="inline" action="<?= e(url('/admin/mitglieder/' . $id . '/auszeichnung-loeschen')) ?>"
                                  data-confirm="Auszeichnung löschen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="award_id" value="<?= (int) $award['id'] ?>">
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($awards === []): ?>
                <tr><td colspan="6" class="empty">Noch keine Auszeichnungen erfasst.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit): ?>
        <form method="post" action="<?= e(url('/admin/mitglieder/' . $id . '/auszeichnung')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--sm">
                <label for="aw-date">Datum</label>
                <input id="aw-date" name="award_date" type="date" value="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="field field--grow">
                <label for="aw-title">Auszeichnung *</label>
                <input id="aw-title" name="title" required placeholder="z. B. Landesmeister Muay Thai -63,5 kg">
            </div>

            <div class="field field--sm">
                <label for="aw-sport">Sportart</label>
                <input id="aw-sport" name="sport" list="sport-award-list">
                <datalist id="sport-award-list">
                    <?php foreach ($sports as $sport): ?><option value="<?= e($sport) ?>"></option><?php endforeach; ?>
                    <option value="Kraftdreikampf"></option>
                </datalist>
            </div>

            <div class="field field--grow">
                <label for="aw-note">Notiz</label>
                <input id="aw-note" name="note">
            </div>

            <button class="btn" type="submit">Erfassen</button>
        </form>
    <?php endif; ?>
</div>
