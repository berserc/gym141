<?php

/**
 * Rendert die veröffentlichten Inhaltsblöcke einer Seite.
 *
 * Erwartet: $pageBlocks (BlockRepo::forPage(..., publishedOnly: true)).
 * Jeder Blocktyp hat sein Template blocks/<typ>.php und bekommt $cfg.
 */

/** @var list<array<string,mixed>> $pageBlocks */
foreach (($pageBlocks ?? []) as $pageBlock) {
    $template = __DIR__ . '/' . basename((string) $pageBlock['type']) . '.php';

    if (!is_file($template)) {
        continue; // Typ aus einer neueren Version – still überspringen
    }

    $cfg = (array) $pageBlock['config'];

    // Optionaler Ankerpunkt: macht den Block per /#anker (Menuepunkt) anspringbar.
    $anker = trim((string) ($cfg['anchor'] ?? ''));

    if ($anker !== '') {
        echo '<span id="' . e($anker) . '" style="display:block;position:relative;top:-4rem"></span>';
    }

    require $template;
}
