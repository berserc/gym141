<?php

/**
 * Slideshow-Block: Bilderwechsler ohne Fremdbibliothek. Konfigurierbar:
 * Wechselzeit (0 = kein Autoplay), Pfeile, Punkte, Breite, Übergang.
 *
 * @var array<string,mixed> $cfg
 * @var array<string,mixed> $block
 */
$bilder   = is_array($cfg['images'] ?? null) ? array_values(array_filter($cfg['images'], static fn ($b): bool => (string) ($b['file'] ?? '') !== '')) : [];
$interval = (int) ($cfg['interval'] ?? 5000);
$pfeile   = !isset($cfg['arrows']) || (int) $cfg['arrows'] === 1;
$punkte   = !isset($cfg['bullets']) || (int) $cfg['bullets'] === 1;
$voll     = ($cfg['width'] ?? 'normal') === 'voll';
$effekt   = ($cfg['effect'] ?? 'fade') === 'slide' ? 'slide' : 'fade';
$blockId  = 'slideshow-' . (int) ($block['id'] ?? 0);

if ($bilder === []) {
    return;
}
?>
<section class="<?= $voll ? '' : 'wrap ' ?>block block--slideshow">
    <div class="slideshow slideshow--<?= $effekt ?>" id="<?= e($blockId) ?>"
         data-interval="<?= $interval ?>" role="region" aria-roledescription="Slideshow" aria-label="Bildergalerie">
        <div class="slideshow__slides">
            <?php foreach ($bilder as $i => $bild): ?>
                <figure class="slideshow__slide<?= $i === 0 ? ' is-active' : '' ?>" data-slide="<?= $i ?>">
                    <img src="<?= e(upload_url((string) $bild['file'])) ?>"
                         alt="<?= e((string) ($bild['caption'] ?? '')) ?>"
                         <?= $i === 0 ? '' : 'loading="lazy"' ?>>
                    <?php if ((string) ($bild['caption'] ?? '') !== ''): ?>
                        <figcaption class="slideshow__caption"><?= e((string) $bild['caption']) ?></figcaption>
                    <?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>

        <?php if ($pfeile && count($bilder) > 1): ?>
            <button class="slideshow__arrow slideshow__arrow--zurueck" type="button" data-dir="-1" aria-label="Vorheriges Bild">&#10094;</button>
            <button class="slideshow__arrow slideshow__arrow--vor" type="button" data-dir="1" aria-label="Nächstes Bild">&#10095;</button>
        <?php endif; ?>

        <?php if ($punkte && count($bilder) > 1): ?>
            <div class="slideshow__bullets" role="tablist">
                <?php foreach ($bilder as $i => $bild): ?>
                    <button class="slideshow__bullet<?= $i === 0 ? ' is-active' : '' ?>" type="button"
                            data-ziel="<?= $i ?>" aria-label="Bild <?= $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php if (empty($GLOBALS['gym141SlideshowJs'])): $GLOBALS['gym141SlideshowJs'] = true; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.slideshow').forEach(function (show) {
        var slides = show.querySelectorAll('.slideshow__slide');
        if (slides.length < 2) { return; }

        var bullets  = show.querySelectorAll('.slideshow__bullet');
        var interval = parseInt(show.dataset.interval || '0', 10);
        var aktiv    = 0;
        var timer    = null;

        function zeige(i) {
            aktiv = (i + slides.length) % slides.length;
            slides.forEach(function (s, n) { s.classList.toggle('is-active', n === aktiv); });
            bullets.forEach(function (b, n) { b.classList.toggle('is-active', n === aktiv); });
            if (show.classList.contains('slideshow--slide')) {
                show.querySelector('.slideshow__slides').style.transform = 'translateX(-' + (aktiv * 100) + '%)';
            }
        }

        function start() {
            if (interval > 0 && timer === null) {
                timer = setInterval(function () { zeige(aktiv + 1); }, interval);
            }
        }
        function stopp() { if (timer !== null) { clearInterval(timer); timer = null; } }

        show.querySelectorAll('.slideshow__arrow').forEach(function (btn) {
            btn.addEventListener('click', function () { stopp(); zeige(aktiv + parseInt(btn.dataset.dir, 10)); start(); });
        });
        bullets.forEach(function (btn) {
            btn.addEventListener('click', function () { stopp(); zeige(parseInt(btn.dataset.ziel, 10)); start(); });
        });

        // Wischgesten (Touch)
        var startX = null;
        show.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; stopp(); }, { passive: true });
        show.addEventListener('touchend', function (e) {
            if (startX !== null) {
                var dx = e.changedTouches[0].clientX - startX;
                if (Math.abs(dx) > 40) { zeige(aktiv + (dx < 0 ? 1 : -1)); }
                startX = null;
            }
            start();
        }, { passive: true });

        show.addEventListener('mouseenter', stopp);
        show.addEventListener('mouseleave', start);

        start();
    });
});
</script>
<?php endif; ?>
