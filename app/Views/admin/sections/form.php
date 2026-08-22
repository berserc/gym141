<?php

use App\Core\Auth;

/**
 * @var array<string,mixed>       $section
 * @var list<array<string,mixed>> $contacts
 * @var array<string,string>      $errors
 * @var bool                      $isNew
 */
$id     = (int) ($section['id'] ?? 0);
$action = $isNew ? url('/admin/sektionen') : url('/admin/sektionen/' . $id);

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<p class="field__error">' . e($errors[$field]) . '</p>' : '';
};

$images = [
    'logo' => ['label' => 'Logo', 'path' => (string) $section['logo_path'], 'hint' => 'Vereinslogo, quadratisch, max. 500 px'],
    'tile' => ['label' => 'Kachelbild', 'path' => (string) $section['tile_path'], 'hint' => 'Bild in der Übersicht, quer, max. 900 px'],
    'hero' => ['label' => 'Titelbild', 'path' => (string) $section['hero_path'], 'hint' => 'Breites Bild oben auf der Sektionsseite, max. 1600 px'],
];
?>
<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Neue Sektion' : e($section['name']) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-head__sub">
                <a href="<?= e(url('/sektion/' . $section['slug'])) ?>" target="_blank" rel="noopener">
                    Seite ansehen: /sektion/<?= e($section['slug']) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/sektionen')) ?>">Zur Liste</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>

    <div class="form-grid">
        <fieldset class="card">
            <legend>Grunddaten</legend>

            <div class="field">
                <label for="name">Sportart *</label>
                <input id="name" name="name" required value="<?= e($section['name']) ?>">
                <?= $err('name') ?>
            </div>

            <div class="field">
                <label for="club_name">Verein / Bezeichnung</label>
                <input id="club_name" name="club_name" value="<?= e($section['club_name']) ?>"
                       placeholder="z. B. Gym141 Badminton">
            </div>

            <div class="field">
                <label for="tagline">Kurzbeschreibung</label>
                <input id="tagline" name="tagline" value="<?= e($section['tagline']) ?>"
                       placeholder="Ein Satz für die Übersicht">
            </div>

            <?php if (Auth::isSuperuser()): ?>
                <div class="field-row">
                    <div class="field field--grow">
                        <label for="slug">URL-Kürzel</label>
                        <input id="slug" name="slug" value="<?= e($section['slug']) ?>" placeholder="wird aus dem Namen gebildet">
                        <?= $err('slug') ?>
                    </div>

                    <div class="field field--xs">
                        <label for="sort_order">Reihung</label>
                        <input id="sort_order" name="sort_order" type="number" value="<?= (int) $section['sort_order'] ?>">
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" name="published" value="1" <?= (int) $section['published'] === 1 ? 'checked' : '' ?>>
                    auf der Website sichtbar
                </label>
            <?php endif; ?>
        </fieldset>

        <fieldset class="card">
            <legend>Links &amp; Beitrag</legend>

            <div class="field">
                <label for="website">Website</label>
                <input id="website" name="website" value="<?= e($section['website']) ?>" placeholder="www.beispiel.at">
                <?= $err('website') ?>
            </div>

            <div class="field">
                <label for="facebook">Facebook</label>
                <input id="facebook" name="facebook" value="<?= e($section['facebook']) ?>">
            </div>

            <div class="field">
                <label for="instagram">Instagram</label>
                <input id="instagram" name="instagram" value="<?= e($section['instagram']) ?>">
            </div>

            <div class="field field--xs">
                <label for="default_fee">Standardbeitrag (€)</label>
                <input id="default_fee" name="default_fee" inputmode="decimal"
                       value="<?= e(number_format((float) $section['default_fee'], 2, ',', '')) ?>">
                <p class="field__hint">Wird bei neuen Mitgliedern dieser Sektion vorgeschlagen.</p>
            </div>

            <label class="check">
                <input type="checkbox" name="fee_free" value="1"
                    <?= (int) ($section['fee_free'] ?? 0) === 1 ? 'checked' : '' ?>>
                Diese Sektion hebt keinen Mitgliedsbeitrag ein
            </label>
        </fieldset>
    </div>

    <fieldset class="card">
        <legend>Texte</legend>

        <div class="field">
            <label for="description">Beschreibung</label>
            <textarea id="description" name="description" rows="8" class="js-richtext"><?= e($section['description']) ?></textarea>
        </div>

        <div class="field">
            <label for="training_info">Training &amp; Angebot <small>(optionaler Zusatztext)</small></label>
            <textarea id="training_info" name="training_info" rows="6" class="js-richtext"><?= e($section['training_info']) ?></textarea>
            <p class="field__hint">
                Die Trainingszeiten selbst werden zentral im
                <a href="<?= e(url('/admin/wochenplan')) ?>">Wochenplan</a> gepflegt und
                erscheinen automatisch auf dieser Sektionsseite.
            </p>
        </div>
    </fieldset>

    <fieldset class="card">
        <legend>Bilder</legend>

        <div class="image-grid">
            <?php foreach ($images as $key => $image): ?>
                <div class="image-slot">
                    <p class="image-slot__label"><?= e($image['label']) ?></p>

                    <?php if ($image['path'] !== ''): ?>
                        <img src="<?= e(upload_url($image['path'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="image-slot__empty">kein Bild</div>
                    <?php endif; ?>

                    <input type="file" name="<?= e($key) ?>" accept="image/jpeg,image/png,image/gif,image/webp">
                    <p class="field__hint"><?= e($image['hint']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= $isNew ? 'Sektion anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/sektionen')) ?>">Abbrechen</a>
    </div>
</form>

<?php if (!$isNew): ?>
    <?php foreach ($images as $key => $image): ?>
        <?php if ($image['path'] === '') {
            continue;
        } ?>
        <form method="post" action="<?= e(url('/admin/sektionen/' . $id . '/bild-entfernen')) ?>" class="inline"
              data-confirm="<?= e($image['label']) ?> wirklich entfernen?">
            <?= csrf_field() ?>
            <input type="hidden" name="field" value="<?= e($key) ?>_path">
            <button class="linklike linklike--danger" type="submit"><?= e($image['label']) ?> entfernen</button>
        </form>
    <?php endforeach; ?>

    <div class="card">
        <div class="card__head">
            <h2>Ansprechpartner</h2>
        </div>

        <?php foreach ($contacts as $contact): ?>
            <form method="post" action="<?= e(url('/admin/sektionen/' . $id . '/kontakt')) ?>" class="contact-row">
                <?= csrf_field() ?>
                <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">

                <div class="field field--sm">
                    <label>Funktion</label>
                    <input name="role_label" value="<?= e($contact['role_label']) ?>">
                </div>

                <div class="field field--grow">
                    <label>Name</label>
                    <input name="name" value="<?= e($contact['name']) ?>">
                </div>

                <div class="field field--sm">
                    <label>Telefon</label>
                    <input name="phone" value="<?= e($contact['phone']) ?>">
                </div>

                <div class="field field--sm">
                    <label>Mobil</label>
                    <input name="mobile" value="<?= e($contact['mobile']) ?>">
                </div>

                <div class="field field--sm">
                    <label>Fax</label>
                    <input name="fax" value="<?= e($contact['fax']) ?>">
                </div>

                <div class="field field--grow">
                    <label>E-Mail</label>
                    <input name="email" type="email" value="<?= e($contact['email']) ?>">
                </div>

                <div class="field field--xs">
                    <label>Reihung</label>
                    <input name="sort_order" type="number" value="<?= (int) $contact['sort_order'] ?>">
                </div>

                <div class="contact-row__actions">
                    <button class="btn btn--sm" type="submit">Speichern</button>
                    <button class="linklike linklike--danger" type="submit"
                            formaction="<?= e(url('/admin/sektionen/' . $id . '/kontakt-loeschen')) ?>"
                            data-confirm-click="Kontakt wirklich entfernen?">Entfernen</button>
                </div>
            </form>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('/admin/sektionen/' . $id . '/kontakt')) ?>" class="contact-row contact-row--new">
            <?= csrf_field() ?>
            <input type="hidden" name="contact_id" value="0">

            <div class="field field--sm">
                <label for="new-role">Funktion</label>
                <input id="new-role" name="role_label" value="Sektionsleitung">
            </div>

            <div class="field field--grow">
                <label for="new-name">Name</label>
                <input id="new-name" name="name">
            </div>

            <div class="field field--sm">
                <label for="new-phone">Telefon</label>
                <input id="new-phone" name="phone">
            </div>

            <div class="field field--sm">
                <label for="new-mobile">Mobil</label>
                <input id="new-mobile" name="mobile">
            </div>

            <div class="field field--sm">
                <label for="new-fax">Fax</label>
                <input id="new-fax" name="fax">
            </div>

            <div class="field field--grow">
                <label for="new-email">E-Mail</label>
                <input id="new-email" name="email" type="email">
            </div>

            <div class="field field--xs">
                <label for="new-sort">Reihung</label>
                <input id="new-sort" name="sort_order" type="number" value="<?= count($contacts) * 10 ?>">
            </div>

            <div class="contact-row__actions">
                <button class="btn btn--sm btn--primary" type="submit">Hinzufügen</button>
            </div>
        </form>
    </div>

    <?php if (Auth::isSuperuser()): ?>
        <div class="card card--danger">
            <div class="card__head">
                <h2>Sektion löschen</h2>
            </div>

            <p class="muted">
                Nur möglich, solange der Sektion kein Mitglied mehr zugeordnet ist.
                Bilder und Ansprechpartner werden mitgelöscht.
            </p>

            <form method="post" action="<?= e(url('/admin/sektionen/' . $id . '/loeschen')) ?>"
                  data-confirm="Sektion „<?= e($section['name']) ?>“ endgültig löschen?">
                <?= csrf_field() ?>
                <button class="btn btn--danger" type="submit">Sektion löschen</button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
