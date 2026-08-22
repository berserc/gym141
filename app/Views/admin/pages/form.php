<?php

/**
 * @var array<string,mixed>  $page
 * @var array<string,string> $errors
 * @var bool                 $isNew
 */
$id       = (int) ($page['id'] ?? 0);
$action   = $isNew ? url('/admin/seiten') : url('/admin/seiten/' . $id);
$isLegal  = in_array((string) $page['slug'], ['impressum', 'datenschutz'], true);

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<p class="field__error">' . e($errors[$field]) . '</p>' : '';
};
?>
<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Neue Seite' : e($page['title']) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-head__sub">
                <a href="<?= e(url('/seite/' . $page['slug'])) ?>" target="_blank" rel="noopener">
                    Seite ansehen: /seite/<?= e($page['slug']) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/seiten')) ?>">Zur Liste</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <div class="field-row">
            <div class="field field--grow">
                <label for="title">Titel *</label>
                <input id="title" name="title" required value="<?= e($page['title']) ?>">
                <?= $err('title') ?>
            </div>

            <div class="field field--grow">
                <label for="slug">URL-Kürzel</label>
                <input id="slug" name="slug" value="<?= e($page['slug']) ?>" <?= $isLegal ? 'readonly' : '' ?>>
                <?= $err('slug') ?>
            </div>

            <div class="field field--xs">
                <label for="sort_order">Reihung</label>
                <input id="sort_order" name="sort_order" type="number" value="<?= (int) $page['sort_order'] ?>">
            </div>
        </div>

        <div class="field">
            <label for="body">Inhalt</label>
            <textarea id="body" name="body" rows="24" class="js-richtext" data-height="620"><?= e($page['body']) ?></textarea>
        </div>

        <div class="field-row">
            <label class="check">
                <input type="checkbox" name="published" value="1" <?= (int) $page['published'] === 1 ? 'checked' : '' ?>>
                veröffentlicht
            </label>

            <label class="check">
                <input type="checkbox" name="in_footer" value="1" <?= (int) $page['in_footer'] === 1 ? 'checked' : '' ?>>
                im Fußbereich verlinken
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= $isNew ? 'Seite anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/seiten')) ?>">Abbrechen</a>
    </div>
</form>

<?php if (!$isNew && !$isLegal): ?>
    <div class="card card--danger">
        <div class="card__head"><h2>Seite löschen</h2></div>

        <form method="post" action="<?= e(url('/admin/seiten/' . $id . '/loeschen')) ?>"
              data-confirm="Seite „<?= e($page['title']) ?>“ löschen?">
            <?= csrf_field() ?>
            <button class="btn btn--danger" type="submit">Seite löschen</button>
        </form>
    </div>
<?php endif; ?>
