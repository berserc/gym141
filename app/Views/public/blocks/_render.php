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
    require $template;
}
