<?php

/**
 * @var array{inserted:int,updated:int,skipped:int,errors:list<string>} $result
 * @var bool                $dryRun
 * @var string              $token
 * @var array<string,mixed> $mapping
 * @var int                 $defaultSection
 * @var string              $mode
 * @var string              $delimiter
 */
?>
<div class="page-head">
    <h1><?= $dryRun ? 'Probelauf' : 'Import abgeschlossen' ?></h1>
</div>

<?php if ($dryRun): ?>
    <div class="notice">
        Es wurde <strong>nichts gespeichert</strong>. Die Zahlen zeigen, was ein echter Import bewirken würde.
        Gehen Sie zurück und führen Sie ihn aus, wenn alles passt.
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat stat--ok">
        <span class="stat__value"><?= (int) $result['inserted'] ?></span>
        <span class="stat__label">neu angelegt</span>
    </div>
    <div class="stat">
        <span class="stat__value"><?= (int) $result['updated'] ?></span>
        <span class="stat__label">aktualisiert</span>
    </div>
    <div class="stat<?= $result['skipped'] > 0 ? ' stat--warn' : '' ?>">
        <span class="stat__value"><?= (int) $result['skipped'] ?></span>
        <span class="stat__label">übersprungen</span>
    </div>
</div>

<?php if ($result['errors'] !== []): ?>
    <div class="card">
        <div class="card__head">
            <h2>Hinweise (<?= count($result['errors']) ?>)</h2>
        </div>
        <ul class="error-list">
            <?php foreach (array_slice($result['errors'], 0, 200) as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($result['errors']) > 200): ?>
            <p class="muted">… und <?= count($result['errors']) - 200 ?> weitere.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="form-actions">
    <?php if ($dryRun): ?>
        <form method="post" action="<?= e(url('/admin/import/ausfuehren')) ?>" class="inline"
              data-confirm="Import mit genau dieser Zuordnung jetzt wirklich ausführen?">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="delimiter" value="<?= e($delimiter) ?>">
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <input type="hidden" name="default_section_id" value="<?= (int) $defaultSection ?>">
            <input type="hidden" name="dry_run" value="0">
            <?php foreach ($mapping as $field => $index): ?>
                <input type="hidden" name="mapping[<?= e((string) $field) ?>]" value="<?= e((string) $index) ?>">
            <?php endforeach; ?>
            <button class="btn btn--primary" type="submit">Import jetzt ausführen</button>
        </form>

        <a class="btn btn--ghost" href="<?= e(url('/admin/import')) ?>">Neue Datei hochladen</a>
    <?php else: ?>
        <a class="btn btn--primary" href="<?= e(url('/admin/mitglieder')) ?>">Zur Mitgliederliste</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/import')) ?>">Weiteren Import starten</a>
    <?php endif; ?>
</div>
