<?php
/** @var array<string,mixed> $cfg */
$titel    = trim((string) ($cfg['title'] ?? ''));
$sections = \App\Models\SectionRepo::allPublished();

if ($sections === []) {
    return;
}
?>
<section class="wrap block block--sections" id="training">
    <?php if ($titel !== ''): ?>
        <h2 class="section-heading"><?= e($titel) ?></h2>
    <?php endif; ?>

    <?php require dirname(__DIR__) . '/_section-tiles.php'; ?>
</section>
