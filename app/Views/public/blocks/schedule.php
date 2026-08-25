<?php
/** @var array<string,mixed> $cfg */
$titel = trim((string) ($cfg['title'] ?? 'Wochenplan'));
$week  = \App\Models\Schedule::week();

if (array_filter($week) === []) {
    return; // keine Einheiten gepflegt
}
?>
<section class="wrap schedule block block--schedule" id="wochenplan">
    <?php if ($titel !== ''): ?>
        <h2 class="section-heading"><?= e($titel) ?></h2>
    <?php endif; ?>

    <?php require dirname(__DIR__) . '/_plan-grid.php'; ?>
</section>
