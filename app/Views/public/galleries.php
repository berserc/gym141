<?php

/** @var list<array<string,mixed>> $galleries */
?>
<article class="wrap text-page">
    <h1>Galerie</h1>
    <?php if ($galleries === []): ?>
        <p class="empty">Noch keine Bilder – bald mehr.</p>
    <?php else: ?>
        <div class="tiles gallery-tiles">
            <?php foreach ($galleries as $g): ?>
                <article class="tile">
                    <a class="tile__link" href="<?= e(url('/galerie/' . $g['slug'])) ?>">
                        <div class="tile__media">
                            <?php if (!empty($g['cover_thumb'])): ?>
                                <img src="<?= e(upload_url((string) $g['cover_thumb'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="tile__placeholder"></div>
                            <?php endif; ?>
                        </div>
                        <div class="tile__body">
                            <h2 class="tile__title"><?= e((string) $g['title']) ?></h2>
                            <p class="tile__sub"><?= (int) $g['image_count'] ?> Bilder</p>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>
