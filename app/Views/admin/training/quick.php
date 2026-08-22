<?php

use App\Core\Auth;

/**
 * Anwesenheits-Schnellerfassung: Trainingstag waehlen, Mitglieder abhaken.
 *
 * @var string                    $datum      Y-m-d
 * @var int                       $sectionId
 * @var list<array<string,mixed>> $sections
 * @var list<array<string,mixed>> $mitglieder aktive Mitglieder (gefiltert)
 * @var array<int,bool>           $anwesend   member_id => bereits erfasst
 */
$bereitsErfasst = count(array_intersect_key(
    $anwesend,
    array_column($mitglieder, null, 'id')
));
?>
<div class="page-head">
    <div>
        <h1>Anwesenheit erfassen</h1>
        <p class="page-head__sub">
            Trainingstag <?= e(format_date($datum)) ?> ·
            <?= $bereitsErfasst ?> von <?= count($mitglieder) ?> Mitgliedern bereits erfasst.
            Bewertungen (1–10) trägst du beim Mitglied unter „Entwicklung“ ein.
        </p>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/anwesenheit')) ?>">
    <div class="filters__row">
        <div class="field field--sm">
            <label for="f-datum">Trainingstag</label>
            <input id="f-datum" type="date" name="datum" value="<?= e($datum) ?>">
        </div>

        <div class="field">
            <label for="f-section">Sektion</label>
            <select id="f-section" name="section_id">
                <option value="">alle</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                        <?= e($section['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn--primary" type="submit">Anzeigen</button>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/anwesenheit')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="datum" value="<?= e($datum) ?>">
    <input type="hidden" name="section_id" value="<?= (int) $sectionId ?>">

    <div class="card">
        <div class="card__head">
            <h2>Wer war beim Training?</h2>
            <?php if (Auth::canWrite() && $mitglieder !== []): ?>
                <label class="check">
                    <input type="checkbox" data-check-all> alle auswählen
                </label>
            <?php endif; ?>
        </div>

        <?php if ($mitglieder === []): ?>
            <p class="empty">Keine aktiven Mitglieder für diese Auswahl.</p>
        <?php else: ?>
            <div class="attendance-grid">
                <?php foreach ($mitglieder as $member): ?>
                    <?php $daGewesen = isset($anwesend[(int) $member['id']]); ?>
                    <label class="attendance-item<?= $daGewesen ? ' is-done' : '' ?>">
                        <input type="checkbox" name="ids[]" value="<?= (int) $member['id'] ?>"
                            <?= $daGewesen ? 'checked disabled' : '' ?>>
                        <span>
                            <?= e($member['last_name']) ?>, <?= e($member['first_name']) ?>
                            <?php if ((int) ($member['is_trainer'] ?? 0) === 1): ?>
                                <span class="badge badge--gold">Trainer</span>
                            <?php endif; ?>
                            <small class="muted"><?= e($member['section_name']) ?></small>
                        </span>
                        <?php if ($daGewesen): ?>
                            <span class="attendance-item__done" title="bereits erfasst">✔</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if (Auth::canWrite()): ?>
                <div class="bulkbar">
                    <button class="btn btn--primary" type="submit">Anwesenheit speichern</button>
                    <span class="muted">Bereits erfasste Mitglieder sind fixiert und werden nicht doppelt gezählt.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</form>
