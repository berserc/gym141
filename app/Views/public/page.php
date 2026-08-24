<?php

/**
 * Redaktionelle Seite: Titel + klassischer Textkörper, darunter die frei
 * konfigurierbaren Inhaltsblöcke (Verwaltung: Seiten → Inhalt).
 *
 * @var array<string,mixed>       $page
 * @var list<array<string,mixed>> $pageBlocks
 */
?>
<article class="wrap text-page">
    <h1><?= e($page['title']) ?></h1>
    <?php if (trim((string) $page['body']) !== ''): ?>
        <div class="rich-text"><?= $page['body'] ?></div>
    <?php endif; ?>
</article>

<?php $pageBlocks = $pageBlocks ?? []; ?>
<?php require __DIR__ . '/blocks/_render.php'; ?>
