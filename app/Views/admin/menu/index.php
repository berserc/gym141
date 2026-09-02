<?php

/**
 * Menuepunkte der oeffentlichen Website (Hauptmenue/Footer) verwalten.
 *
 * @var list<array<string,mixed>> $items
 * @var array<string,string>      $positions
 */
?>
<div class="page-head">
    <div>
        <h1>Menü</h1>
        <p class="page-head__sub">
            Eigene Menüpunkte für die Website – im Hauptmenü (oben) und/oder im
            Footer. Als Ziel eignet sich ein interner Pfad
            (z. B. <code>/seite/agb</code> oder <code>/meineurl</code>), ein
            <strong>Ankerpunkt auf der Startseite</strong> (<code>/#anker</code> –
            den Anker vergibst du beim Inhaltsblock) oder eine externe Adresse.
        </p>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2>Neuer Menüpunkt</h2></div>
    <form method="post" action="<?= e(url('/admin/menue')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <div class="field field--sm">
            <label>Beschriftung</label>
            <input name="label" required maxlength="60" placeholder="z. B. AGB">
        </div>
        <div class="field field--grow">
            <label>URL</label>
            <input name="url" required maxlength="300" placeholder="/seite/agb, /#kontakt oder https://…">
        </div>
        <div class="field field--sm">
            <label>Erscheint in</label>
            <select name="position">
                <?php foreach ($positions as $wert => $bezeichnung): ?>
                    <option value="<?= e($wert) ?>"><?= e($bezeichnung) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field field--xs">
            <label>Reihung</label>
            <input name="sort_order" type="number" value="0" style="max-width:5rem">
        </div>
        <input type="hidden" name="published" value="1">
        <button class="btn btn--primary" type="submit">Anlegen</button>
    </form>
</div>

<?php foreach ($items as $item): ?>
    <div class="card">
        <form method="post" action="<?= e(url('/admin/menue/' . (int) $item['id'])) ?>" class="inline-form">
            <?= csrf_field() ?>
            <div class="field field--sm">
                <label>Beschriftung</label>
                <input name="label" required maxlength="60" value="<?= e($item['label']) ?>">
            </div>
            <div class="field field--grow">
                <label>URL</label>
                <input name="url" required maxlength="300" value="<?= e($item['url']) ?>">
            </div>
            <div class="field field--sm">
                <label>Erscheint in</label>
                <select name="position">
                    <?php foreach ($positions as $wert => $bezeichnung): ?>
                        <option value="<?= e($wert) ?>" <?= $item['position'] === $wert ? 'selected' : '' ?>><?= e($bezeichnung) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--xs">
                <label>Reihung</label>
                <input name="sort_order" type="number" value="<?= (int) $item['sort_order'] ?>" style="max-width:5rem">
            </div>
            <input type="hidden" name="published" value="<?= (int) $item['published'] ?>">
            <button class="btn btn--sm" type="submit">Speichern</button>
        </form>

        <div class="inline-form" style="margin-top:.4rem;gap:.8rem">
            <?php if ((int) $item['published'] === 1): ?>
                <span class="pill pill--aktiv">sichtbar</span>
            <?php else: ?>
                <span class="badge badge--muted">ausgeblendet</span>
            <?php endif; ?>
            <form method="post" class="inline" action="<?= e(url('/admin/menue/' . (int) $item['id'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="umschalten">
                <button class="linklike" type="submit"><?= (int) $item['published'] === 1 ? 'ausblenden' : 'einblenden' ?></button>
            </form>
            <form method="post" class="inline" action="<?= e(url('/admin/menue/' . (int) $item['id'] . '/loeschen')) ?>"
                  data-confirm="Menüpunkt „<?= e($item['label']) ?>“ löschen?">
                <?= csrf_field() ?>
                <button class="linklike linklike--danger" type="submit">löschen</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($items === []): ?>
    <div class="card"><p class="muted">Noch keine eigenen Menüpunkte – oben den ersten anlegen.
        Die redaktionellen Seiten (Impressum, Datenschutz, AGB …) pflegst du unter
        <a href="<?= e(url('/admin/seiten')) ?>">Seiten</a>; Footer-Einträge dafür entstehen dort automatisch.</p></div>
<?php endif; ?>
