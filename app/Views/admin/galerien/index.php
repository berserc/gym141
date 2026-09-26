<?php

/**
 * @var list<array<string,mixed>> $galleries
 * @var int                        $imageCount
 */
?>
<div class="page-head">
    <div>
        <h1>Galerien</h1>
        <p class="page-head__sub">
            Bildersammlungen aus der <a href="<?= e(url('/admin/bilder')) ?>">Bildbibliothek</a> mit eigener Seite unter <code>/galerie/…</code>.
            Die Übersicht aller sichtbaren Galerien liegt unter <a href="<?= e(url('/galerie')) ?>" target="_blank" rel="noopener">/galerie</a> – als Menüpunkt unter <a href="<?= e(url('/admin/menue')) ?>">Menü</a> eintragen.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/bilder')) ?>">Bilder (<?= $imageCount ?>)</a>
        <a class="btn btn--primary" href="<?= e(url('/admin/galerien/neu')) ?>">Neue Galerie</a>
    </div>
</div>

<div class="card">
    <?php if ($galleries === []): ?>
        <p class="empty">Noch keine Galerie. <?= $imageCount === 0 ? 'Zuerst Bilder hochladen, dann hier eine Galerie anlegen.' : '' ?></p>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th class="col-thumb"></th><th>Titel</th><th>URL</th><th class="num">Bilder</th><th>Sichtbar</th><th>Reihung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($galleries as $g): ?>
                <tr>
                    <td class="col-thumb"><?php if (!empty($g['cover_thumb'])): ?><img src="<?= e(upload_url((string) $g['cover_thumb'])) ?>" alt="" style="width:56px;height:42px;object-fit:cover;border-radius:4px;"><?php endif; ?></td>
                    <td><a class="strong" href="<?= e(url('/admin/galerien/' . (int) $g['id'])) ?>"><?= e((string) $g['title']) ?></a></td>
                    <td><a href="<?= e(url('/galerie/' . $g['slug'])) ?>" target="_blank" rel="noopener">/galerie/<?= e((string) $g['slug']) ?></a></td>
                    <td class="num"><?= (int) $g['image_count'] ?></td>
                    <td><?= (int) $g['published'] === 1 ? '<span class="pill pill--aktiv">ja</span>' : '<span class="pill pill--offen">nein</span>' ?></td>
                    <td><?= (int) $g['sort_order'] ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/galerien/' . (int) $g['id'] . '/umschalten')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn--sm" type="submit"><?= (int) $g['published'] === 1 ? 'Verstecken' : 'Sichtbar machen' ?></button></form>
                        <form method="post" action="<?= e(url('/admin/galerien/' . (int) $g['id'] . '/loeschen')) ?>" class="inline" data-confirm="Galerie „<?= e((string) $g['title']) ?>“ löschen? Die Bilder bleiben in der Bildbibliothek."><?= csrf_field() ?><button class="btn btn--sm btn--danger" type="submit">Löschen</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
