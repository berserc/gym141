<?php

use App\Models\Schedule;

/**
 * Wochenplan pflegen – speist Startseite und Sektionsseiten.
 *
 * @var list<array<string,mixed>> $slots
 * @var list<array<string,mixed>> $sections
 * @var array<string,string>      $errors
 */

/** Formularfelder einer Einheit. */
$felder = static function (array $slot = []) use ($sections): void {
    $v = static fn (string $k, string $d = ''): string => e((string) ($slot[$k] ?? $d));
    $gewaehlt = array_column($slot['sections'] ?? [], 'id');
    ?>
    <input type="hidden" name="slot_id" value="<?= (int) ($slot['id'] ?? 0) ?>">

    <div class="field field--sm">
        <label>Tag *</label>
        <select name="day">
            <?php foreach (Schedule::DAYS as $nr => $name): ?>
                <option value="<?= $nr ?>" <?= (int) ($slot['day'] ?? 1) === $nr ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field field--xs">
        <label>Beginn</label>
        <input name="time_from" type="time" value="<?= $v('time_from') ?>">
    </div>

    <div class="field field--xs">
        <label>Ende</label>
        <input name="time_to" type="time" value="<?= $v('time_to') ?>">
    </div>

    <div class="field field--grow">
        <label>Einheit *</label>
        <input name="title" required value="<?= $v('title') ?>" placeholder="z. B. Fitnessboxen">
    </div>

    <div class="field field--grow">
        <label>Hinweis <small>(2. Zeile auf der Kachel)</small></label>
        <input name="note" value="<?= $v('note') ?>" placeholder="z. B. alle Level, eigener Bereich">
    </div>

    <div class="field field--xs">
        <label>Badge</label>
        <input name="badge" value="<?= $v('badge') ?>" placeholder="NEU">
    </div>

    <div class="field field--xs">
        <label>Farbe</label>
        <input name="color" type="color" value="<?= $v('color', '#d4a437') ?>">
    </div>

    <div class="field field--sm">
        <label>Symbol</label>
        <select name="icon">
            <?php foreach (Schedule::ICON_LABELS as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($slot['icon'] ?? 'person') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field field--xs">
        <label>Reihung</label>
        <input name="sort_order" type="number" value="<?= (int) ($slot['sort_order'] ?? 0) ?>">
        <p class="field__hint">bei gleicher Zeit</p>
    </div>

    <div class="field field--grow">
        <label>Sektionen <small>(Kachel verlinkt auf die erste; Zeiten erscheinen auf deren Seiten)</small></label>
        <div class="checkbox-grid">
            <?php foreach ($sections as $section): ?>
                <label class="check">
                    <input type="checkbox" name="section_ids[]" value="<?= (int) $section['id'] ?>"
                        <?= in_array((int) $section['id'], $gewaehlt, true) ? 'checked' : '' ?>>
                    <?= e($section['name']) ?><?= (int) $section['published'] !== 1 ? ' (unveröffentlicht)' : '' ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="field__hint">Nichts angehakt = Einheit erscheint nur auf der Startseite (z. B. Open Gym).</p>
    </div>
    <?php
};
?>
<div class="page-head">
    <div>
        <h1>Wochenplan</h1>
        <p class="page-head__sub">
            Erscheint auf der <a href="<?= e(url('/#wochenplan')) ?>" target="_blank" rel="noopener">Startseite</a>
            und als Trainingszeiten auf den Sektionsseiten.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/sektionen')) ?>">Sektionen</a>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2>Neue Einheit</h2></div>
    <form method="post" action="<?= e(url('/admin/wochenplan')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <?php $felder(); ?>
        <button class="btn btn--primary" type="submit">Eintragen</button>
    </form>
</div>

<?php foreach (Schedule::DAYS as $nr => $name): ?>
    <?php $tagSlots = array_values(array_filter($slots, static fn (array $s): bool => (int) $s['day'] === $nr)); ?>
    <div class="card">
        <div class="card__head">
            <h2><?= e($name) ?></h2>
            <?php if ($tagSlots === []): ?>
                <span class="badge badge--muted">trainingsfrei</span>
            <?php endif; ?>
        </div>

        <?php foreach ($tagSlots as $slot): ?>
            <div class="slot-row">
                <p class="slot-row__head">
                    <span class="slot-dot" style="background: <?= e((string) $slot['color']) ?>"></span>
                    <span class="slot-row__icon" style="color: <?= e((string) $slot['color']) ?>"><?= Schedule::icon((string) $slot['icon'], 18) ?></span>
                    <strong><?= e($slot['time_from'] !== '' ? $slot['time_from'] . '–' . $slot['time_to'] : 'ohne Zeit') ?></strong>
                    <?= e($slot['title']) ?>
                    <?php if ((string) $slot['badge'] !== ''): ?>
                        <span class="badge badge--gold"><?= e($slot['badge']) ?></span>
                    <?php endif; ?>
                    <?php if ($slot['sections'] !== []): ?>
                        <span class="muted">→ <?= e(implode(', ', array_column($slot['sections'], 'name'))) ?></span>
                    <?php else: ?>
                        <span class="muted">nur Startseite</span>
                    <?php endif; ?>
                </p>

                <div class="inline-form">
                    <details class="plan-edit">
                        <summary class="linklike">bearbeiten</summary>
                        <form method="post" action="<?= e(url('/admin/wochenplan')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <?php $felder($slot); ?>
                            <button class="btn btn--sm" type="submit">Speichern</button>
                        </form>
                    </details>

                    <form method="post" class="inline" action="<?= e(url('/admin/wochenplan/loeschen')) ?>"
                          data-confirm="Einheit „<?= e($slot['title']) ?>“ (<?= e($name) ?>) löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                        <button class="linklike linklike--danger" type="submit">löschen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($tagSlots === []): ?>
            <p class="empty">Keine Einheiten – oben eine neue anlegen.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
