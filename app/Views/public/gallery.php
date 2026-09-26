<?php

/**
 * Eine Galerie: Text + Bilderraster mit Lightbox (blocks.js, .js-lightbox).
 *
 * @var array<string,mixed>        $gallery
 * @var list<array<string,mixed>> $images
 * @var list<array<string,mixed>> $others  weitere sichtbare Galerien
 */
?>
<article class="wrap text-page">
    <p class="muted"><a href="<?= e(url('/galerie')) ?>">← Alle Galerien</a></p>
    <h1><?= e((string) $gallery['title']) ?></h1>
    <?php if (trim((string) $gallery['text']) !== ''): ?>
        <div class="rich-text"><?= nl2p((string) $gallery['text']) ?></div>
    <?php endif; ?>
</article>

<?php if ($images !== []): ?>
<section class="wrap block block--gallery" style="--gallery-spalten: 3">
    <div class="gallery-grid">
        <?php foreach ($images as $img): ?>
            <figure class="gallery-item">
                <a href="<?= e(upload_url((string) $img['file'])) ?>" class="js-lightbox" data-caption="<?= e((string) $img['caption']) ?>">
                    <img src="<?= e(upload_url((string) $img['thumb'])) ?>" alt="<?= e((string) $img['caption']) ?>" loading="lazy" width="<?= (int) $img['width'] ?>" height="<?= (int) $img['height'] ?>">
                </a>
                <?php if ((string) $img['caption'] !== ''): ?><figcaption><?= e((string) $img['caption']) ?></figcaption><?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($others !== []): ?>
<section class="wrap block">
    <h2 class="section-heading">Weitere Galerien</h2>
    <div class="tiles gallery-tiles">
        <?php foreach ($others as $g): ?>
            <article class="tile">
                <a class="tile__link" href="<?= e(url('/galerie/' . $g['slug'])) ?>">
                    <div class="tile__media"><?php if (!empty($g['cover_thumb'])): ?><img src="<?= e(upload_url((string) $g['cover_thumb'])) ?>" alt="" loading="lazy"><?php else: ?><div class="tile__placeholder"></div><?php endif; ?></div>
                    <div class="tile__body"><h3 class="tile__title"><?= e((string) $g['title']) ?></h3><p class="tile__sub"><?= (int) $g['image_count'] ?> Bilder</p></div>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
