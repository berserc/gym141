<?php
/** @var array<string,mixed> $cfg */
$bilder  = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
$spalten = (int) ($cfg['columns'] ?? 3);

// Bilder aus der Bildbibliothek nach Tags (dynamisch), ohne Doppelte zur festen Auswahl
$tags = array_values(array_filter((array) ($cfg['library_tags'] ?? [])));
if ($tags !== []) {
    try {
        $schon = array_map(static fn (array $b): int => (int) ($b['image_id'] ?? 0), $bilder);
        foreach (\App\Models\ImageRepo::byTags($tags) as $img) {
            if (!in_array((int) $img['id'], $schon, true)) {
                $bilder[] = ['file' => $img['file'], 'thumb' => $img['thumb'], 'caption' => $img['caption'], 'image_id' => (int) $img['id']];
            }
        }
    } catch (\Throwable) {
        // Tabelle fehlt noch (Migration ausstehend) – nur die festen Bilder zeigen
    }
}

if ($bilder === []) {
    return;
}
?>
<section class="wrap block block--gallery" style="--gallery-spalten: <?= $spalten ?>">
    <div class="gallery-grid">
        <?php foreach ($bilder as $gBild): ?>
            <?php $datei = (string) ($gBild['file'] ?? ''); if ($datei === '') { continue; } ?>
            <figure class="gallery-item">
                <a href="<?= e(upload_url($datei)) ?>" class="js-lightbox"
                   data-caption="<?= e((string) ($gBild['caption'] ?? '')) ?>">
                    <img src="<?= e(upload_url((string) ($gBild['thumb'] ?? $datei))) ?>" alt="<?= e((string) ($gBild['caption'] ?? '')) ?>" loading="lazy">
                </a>
                <?php if ((string) ($gBild['caption'] ?? '') !== ''): ?>
                    <figcaption><?= e((string) $gBild['caption']) ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
</section>
