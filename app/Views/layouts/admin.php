<?php

use App\Core\Auth;

/**
 * Layout des Verwaltungsbereichs.
 *
 * @var string                   $content
 * @var string                   $title
 * @var string                   $appName
 * @var array<string,mixed>|null $authUser
 * @var list<array{type:string,message:string}> $flash
 * @var int                      $pendingDeletions
 */
// Monochrome Symbole (Strich-Stil, faerben sich ueber currentColor mit).
$icons = [
    'home'     => '<path d="M3 9.5 12 3l9 6.5V21h-6v-7h-6v7H3z"/>',
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M15 3.13a4 4 0 0 1 0 7.75"/>',
    'tag'      => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'card'     => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'check'    => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'award'    => '<circle cx="12" cy="8" r="6"/><polyline points="8.2 13 7 23 12 20 17 23 15.8 13"/>',
    'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    'case'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    'flag'     => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    'folder'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    'book'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    'trend'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'key'      => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m12 11 9-9m-3 3 3 3"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'map'      => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
    'sliders'  => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
    'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
];

$icon = static function (string $name) use ($icons): string {
    return '<svg class="admin-nav__icon" viewBox="0 0 24 24" width="15" height="15" fill="none"'
        . ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
};

// Navigation in Gruppen. Eintrag: [Pfad, Titel, Rolle(n)|null, zusaetzlich-aktive Pfade, Symbol].
// Zusammengefasste Bereiche (Dokumente, Gruppen, Statistik, Buchhaltungs-Unterseiten)
// erreichen ihre Seiten ueber Tabs im Seitenkopf.
$nav = [
    '' => [
        ['/admin', 'Übersicht', null, [], 'home'],
    ],
    'Mitglieder' => [
        ['/admin/mitglieder', 'Mitglieder', null, [], 'users'],
        ['/admin/gruppen', 'Gruppen', null, [], 'tag'],
        ['/admin/beitraege', 'Beiträge', null, [], 'card'],
        ['/admin/anwesenheit', 'Anwesenheit', null, [], 'check'],
        ['/admin/entwicklung', 'Entwicklung', null, [], 'activity'],
        ['/admin/erfolge', 'Erfolge', null, [], 'award'],
        ['/admin/auswertung/statistik', 'Statistik', null, [], 'chart'],
        ['/admin/auswertung/gemeinden', 'Gemeinde-Abrechnung', null, [], 'map'],
        ['/admin/auswertung/foerderung', 'Förderung', ['superuser'], [], 'chart'],
    ],
    'Verein' => [
        ['/admin/termine', 'Termine', null, [], 'calendar'],
        ['/admin/aufgaben', 'Aufgaben', null, [], 'check'],
        ['/admin/vorstand', 'Vorstand', null, [], 'case'],
        ['/admin/verein', 'Verein', ['superuser', 'kassier'], [], 'flag'],
        ['/admin/dateien', 'Dateien', null, [], 'folder'],
        ['/admin/sektionen', 'Sektionen', null, [], 'grid'],
        ['/admin/wochenplan', 'Wochenplan', ['superuser', 'sektionsleiter'], [], 'clock'],
    ],
    'Finanzen' => [
        ['/admin/buchhaltung', 'Buchhaltung', ['superuser', 'kassier'], [], 'book'],
        ['/admin/buchhaltung/auswertung', 'Auswertung', ['superuser', 'kassier'], [], 'trend'],
    ],
    'System' => [
        ['/admin/seiten', 'Seiten', 'superuser', [], 'file'],
        ['/admin/benutzer', 'Benutzer', 'superuser', [], 'key'],
        ['/admin/import', 'Import', 'superuser', [], 'download'],
        ['/admin/gemeinden', 'Gemeinden', 'superuser', [], 'map'],
        ['/admin/einstellungen', 'Einstellungen', 'superuser', [], 'sliders'],
        ['/admin/design', 'Design', 'superuser', [], 'sliders'],
        ['/admin/updates', 'Updates', 'superuser', [], 'download'],
        ['/admin/protokoll', 'Protokoll', 'superuser', [], 'list'],
    ],
];

// Der aktive Modus blendet alles aus, was nicht zur jeweiligen Aufgabe gehoert.
// Kein Rechtemodell: die Rollen-Pruefungen der Seiten gelten unveraendert.
$mode = Auth::mode();

$modeWhitelist = [
    'kassier' => [
        '/admin', '/admin/mitglieder', '/admin/beitraege', '/admin/auswertung/statistik',
        '/admin/verein', '/admin/dateien', '/admin/buchhaltung', '/admin/buchhaltung/auswertung',
    ],
    'trainer' => [
        '/admin', '/admin/mitglieder', '/admin/gruppen', '/admin/anwesenheit',
        '/admin/entwicklung', '/admin/erfolge', '/admin/termine', '/admin/aufgaben', '/admin/dateien',
    ],
];

if (isset($modeWhitelist[$mode])) {
    foreach ($nav as $gruppe => $items) {
        $nav[$gruppe] = array_values(array_filter(
            $items,
            static fn (array $i): bool => in_array($i[0], $modeWhitelist[$mode], true)
        ));
    }
}

$currentPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title !== '' ? $title . ' | Verwaltung' : 'Verwaltung') ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="admin<?= !empty($isDev) ? ' admin--dev' : '' ?>">
<script>
// Vor dem ersten Rendern anwenden, damit nichts flackert.
try {
    if (localStorage.getItem('gymNavHidden') === '1') {
        document.body.classList.add('nav-hidden');
    }
} catch (e) {}
</script>

<?php if (!empty($showEnvBanner)): ?>
    <div class="env-banner" role="status">
        Testumgebung – hier gearbeitete Änderungen erscheinen <strong>nicht</strong> auf der Produktivseite.
    </div>
<?php endif; ?>

<?php $lizenzHinweis = \App\Core\License::warning(); ?>
<?php if ($lizenzHinweis !== null): ?>
    <div class="env-banner" role="alert">
        <?= e($lizenzHinweis) ?>
    </div>
<?php endif; ?>

<header class="admin-top">
    <div class="admin-top__inner">
        <button class="admin-burger" type="button" aria-controls="admin-nav" aria-expanded="false">
            <span></span><span></span><span></span>
            <span class="sr-only">Menü</span>
        </button>

        <button class="admin-nav-toggle" type="button" data-nav-collapse
                title="Menü ein-/ausblenden" aria-controls="admin-nav">
            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path class="nav-toggle-hide" d="M9.8 3.2 5 8l4.8 4.8 1.1-1.1L7.2 8l3.7-3.7z" fill="currentColor"/><path class="nav-toggle-show" d="M6.2 3.2 11 8l-4.8 4.8-1.1-1.1L8.8 8 5.1 4.3z" fill="currentColor"/></svg>
            <span class="sr-only">Menü ein-/ausblenden</span>
        </button>

        <a class="admin-top__brand" href="<?= e(url('/admin')) ?>">
            <strong><?= e($appName) ?></strong> <span>Verwaltung</span>
        </a>

        <div class="admin-top__right">
            <?php if ($publicSite ?? true): ?>
                <a class="admin-top__link" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Website ansehen</a>
            <?php endif; ?>

            <?php if (!empty($authUser)): ?>
                <a class="admin-top__user" href="<?= e(url('/admin/profil')) ?>">
                    <?= e($authUser['name'] !== '' ? $authUser['name'] : $authUser['username']) ?>
                    <small><?= e(Auth::ROLES[$authUser['role']] ?? $authUser['role']) ?></small>
                </a>

                <form method="post" action="<?= e(url('/admin/logout')) ?>" class="inline">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit">Abmelden</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="admin-shell">
    <nav id="admin-nav" class="admin-nav" aria-label="Verwaltungsnavigation">
        <?php $erlaubteModi = Auth::allowedModes(); ?>
        <?php if (count($erlaubteModi) > 1): ?>
            <form method="post" action="<?= e(url('/admin/modus')) ?>" class="mode-switch"
                  aria-label="Ansicht wählen">
                <?= csrf_field() ?>
                <?php foreach ($erlaubteModi as $modus): ?>
                    <button type="submit" name="mode" value="<?= e($modus) ?>"
                            class="mode-switch__btn<?= $modus === $mode ? ' is-active' : '' ?>">
                        <?= e(Auth::MODES[$modus] ?? $modus) ?>
                    </button>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
        <?php
        // Bei verschachtelten Pfaden gewinnt der laengste passende Eintrag;
        // per Tab erreichbare Seiten zaehlen ueber die Zusatzpfade zum Eintrag.
        $bestMatch = '';
        foreach ($nav as $items) {
            foreach ($items as [$path, $label, $needsRole, $alsoActive]) {
                foreach (array_merge([$path], $alsoActive) as $prefix) {
                    $href = url($prefix);
                    if (($currentPath === $href || ($prefix !== '/admin' && str_starts_with($currentPath, $href . '/')))
                        && strlen($href) > strlen($bestMatch)) {
                        // Merken, welcher EINTRAG gewinnt (nicht der Zusatzpfad).
                        $bestMatch = url($path);
                    }
                }
            }
        }

        $renderItem = static function (array $item) use ($bestMatch, $pendingDeletions, $openFees, $icon): void {
            [$path, $label, , , $symbol] = $item;
            $href = url($path);
            ?>
            <a href="<?= e($href) ?>" class="admin-nav__item<?= $href === $bestMatch && $bestMatch !== '' ? ' is-active' : '' ?>">
                <span class="admin-nav__label"><?= $icon($symbol) ?><?= e($label) ?></span>
                <?php if ($path === '/admin/mitglieder' && $pendingDeletions > 0): ?>
                    <span class="badge badge--danger" title="Offene Löschvormerkungen"><?= (int) $pendingDeletions ?></span>
                <?php endif; ?>
                <?php if ($path === '/admin/beitraege' && !empty($openFees)): ?>
                    <span class="badge badge--danger" title="Fällige offene Beiträge"><?= (int) $openFees ?></span>
                <?php endif; ?>
            </a>
            <?php
        };
        ?>
        <?php foreach ($nav as $gruppe => $items): ?>
            <?php
            $sichtbar = array_values(array_filter(
                $items,
                static fn (array $i): bool => $i[2] === null || Auth::is(...(array) $i[2])
            ));
            if ($sichtbar === []) {
                continue;
            }

            // Gruppe mit dem aktiven Eintrag bleibt immer offen.
            $enthaeltAktiv = $bestMatch !== '' && in_array(
                $bestMatch,
                array_map(static fn (array $i): string => url($i[0]), $sichtbar),
                true
            );
            ?>
            <?php if ($gruppe === ''): ?>
                <?php foreach ($sichtbar as $item) { $renderItem($item); } ?>
            <?php else: ?>
                <details class="admin-nav__section" data-nav-group="<?= e($gruppe) ?>"
                         <?= $enthaeltAktiv ? 'data-has-active ' : '' ?>open>
                    <summary class="admin-nav__group">
                        <?= e($gruppe) ?>
                        <svg class="admin-nav__chevron" width="12" height="12" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M4.5 6l3.5 3.5L11.5 6l1 1-4.5 4.5L3.5 7z" fill="currentColor"/>
                        </svg>
                    </summary>
                    <?php foreach ($sichtbar as $item) { $renderItem($item); } ?>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>

        <button class="admin-nav__expand" type="button" data-nav-expand title="Alle Gruppen auf- bzw. zuklappen">
            <svg width="12" height="12" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2l4 4H4zM8 14l-4-4h8z" fill="currentColor"/></svg>
            <span data-nav-expand-label>alle aufklappen</span>
        </button>
    </nav>

    <main class="admin-main">
        <?php if (!empty($flash)): ?>
            <div class="flash-stack">
                <?php foreach ($flash as $message): ?>
                    <div class="flash flash--<?= e($message['type']) ?>" role="status">
                        <?= e($message['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </main>
</div>

<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
<?php // TinyMCE nur laden, wenn die Seite ein Rich-Text-Feld enthaelt ?>
<?php if (str_contains($content, 'js-richtext')): ?>
    <script src="<?= e(url('/assets/vendor/tinymce/tinymce.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/editor.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
