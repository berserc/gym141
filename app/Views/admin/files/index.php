<?php

/**
 * Zentrale Dateiablage: Ordner, Upload, Umbenennen, Loeschen – und als
 * Picker (?picker=1) die Auswahl fuer Anhaenge an anderen Stellen.
 *
 * @var int                        $folderId
 * @var list<array<string,mixed>> $crumbs     Ordnerkette bis zur Wurzel
 * @var list<array<string,mixed>> $subfolders
 * @var list<array<string,mixed>> $files
 * @var bool                      $picker
 * @var string                    $suche
 */
$q = static fn (array $extra = []): array => array_merge(
    $picker ? ['picker' => 1] : [],
    $extra
);

$dateiIcon = static function (string $mime): string {
    return match (true) {
        str_starts_with($mime, 'image/') => '🖼️',
        str_starts_with($mime, 'video/') => '🎬',
        str_starts_with($mime, 'audio/') => '🎵',
        $mime === 'application/pdf'      => '📕',
        str_contains($mime, 'zip')       => '🗜️',
        str_contains($mime, 'sheet'), str_contains($mime, 'excel') => '📊',
        default => '📄',
    };
};

$groesse = static function (int $bytes): string {
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
        : number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' KB';
};
?>
<div class="page-head">
    <div>
        <h1><?= $picker ? 'Datei auswählen' : 'Dateien' ?></h1>
        <p class="page-head__sub">
            <?php if ($picker): ?>
                Datei anklicken und „Auswählen“ – oder zuerst in den passenden Ordner wechseln bzw. neu hochladen.
            <?php else: ?>
                Zentrale Ablage: einmal hochladen, überall verwenden (Erfolge, Fixkosten, Vereinsdokumente).
                Ordner helfen beim Sortieren.
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="card">
    <nav class="file-crumbs" aria-label="Ordnerpfad">
        <a href="<?= e(url('/admin/dateien', $q())) ?>">📁 Dateiablage</a>
        <?php foreach ($crumbs as $crumb): ?>
            <span aria-hidden="true">›</span>
            <a href="<?= e(url('/admin/dateien', $q(['ordner' => $crumb['id']]))) ?>"><?= e($crumb['name']) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="file-toolbar">
        <form method="get" action="<?= e(url('/admin/dateien')) ?>" class="file-toolbar__search">
            <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
            <input type="search" name="q" value="<?= e($suche) ?>" placeholder="Dateien suchen …">
            <button class="btn btn--sm" type="submit">Suchen</button>
        </form>

        <form method="post" action="<?= e(url('/admin/dateien/ordner')) ?>" class="file-toolbar__inline">
            <?= csrf_field() ?>
            <input type="hidden" name="parent_id" value="<?= (int) $folderId ?>">
            <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
            <input name="name" placeholder="Neuer Ordner …" required>
            <button class="btn btn--sm" type="submit">📁 Anlegen</button>
        </form>

        <form method="post" action="<?= e(url('/admin/dateien/upload')) ?>"
              enctype="multipart/form-data" class="file-toolbar__inline">
            <?= csrf_field() ?>
            <input type="hidden" name="folder_id" value="<?= (int) $folderId ?>">
            <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
            <input name="files[]" type="file" multiple required>
            <button class="btn btn--sm btn--primary" type="submit">⬆️ Hochladen</button>
        </form>
    </div>

    <div class="table-scroll">
        <table class="table table--compact file-table">
            <thead>
            <tr>
                <th></th>
                <th>Name</th>
                <th class="num">Größe</th>
                <th>hochgeladen</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($suche === '' && $folderId > 0): ?>
                <?php $eltern = count($crumbs) > 1 ? (int) $crumbs[count($crumbs) - 2]['id'] : 0; ?>
                <tr>
                    <td>↩️</td>
                    <td colspan="4">
                        <a href="<?= e(url('/admin/dateien', $q($eltern > 0 ? ['ordner' => $eltern] : []))) ?>">.. (eine Ebene hinauf)</a>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($subfolders as $sub): ?>
                <tr>
                    <td>📁</td>
                    <td class="strong">
                        <a href="<?= e(url('/admin/dateien', $q(['ordner' => $sub['id']]))) ?>"><?= e($sub['name']) ?></a>
                        <small class="muted"><?= (int) $sub['file_count'] ?> Datei(en)<?= (int) $sub['folder_count'] > 0 ? ', ' . (int) $sub['folder_count'] . ' Ordner' : '' ?></small>
                    </td>
                    <td class="num">–</td>
                    <td></td>
                    <td class="row-actions">
                        <details class="plan-edit">
                            <summary class="linklike">umbenennen</summary>
                            <form method="post" action="<?= e(url('/admin/dateien/ordner-umbenennen')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="folder_id" value="<?= (int) $sub['id'] ?>">
                                <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
                                <div class="field field--sm">
                                    <input name="name" value="<?= e($sub['name']) ?>" required>
                                </div>
                                <button class="btn btn--sm" type="submit">Speichern</button>
                            </form>
                        </details>
                        <form method="post" class="inline" action="<?= e(url('/admin/dateien/ordner-loeschen')) ?>"
                              data-confirm="Ordner „<?= e($sub['name']) ?>“ löschen? (geht nur, wenn er leer ist)">
                            <?= csrf_field() ?>
                            <input type="hidden" name="folder_id" value="<?= (int) $sub['id'] ?>">
                            <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
                            <button class="linklike linklike--danger" type="submit">löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php foreach ($files as $file): ?>
                <tr>
                    <td><?= e($dateiIcon((string) $file['mime'])) ?></td>
                    <td class="strong">
                        <a href="<?= e(url('/admin/dateien/' . $file['id'] . '/anzeigen')) ?>" target="_blank" rel="noopener">
                            <?= e($file['filename']) ?>
                        </a>
                        <?php if (($file['folder_name'] ?? null) !== null): ?>
                            <small class="muted">in 📁 <?= e($file['folder_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= e($groesse((int) $file['size'])) ?></td>
                    <td class="muted">
                        <?= e(format_date(substr((string) $file['created_at'], 0, 10))) ?>
                        <?= (string) ($file['uploaded_by_name'] ?? '') !== '' ? '· ' . e($file['uploaded_by_name']) : '' ?>
                    </td>
                    <td class="row-actions">
                        <?php if ($picker): ?>
                            <button class="btn btn--sm btn--primary js-pick" type="button"
                                    data-id="<?= (int) $file['id'] ?>" data-name="<?= e($file['filename']) ?>">
                                Auswählen
                            </button>
                        <?php endif; ?>
                        <details class="plan-edit">
                            <summary class="linklike">umbenennen</summary>
                            <form method="post" action="<?= e(url('/admin/dateien/umbenennen')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
                                <div class="field field--sm">
                                    <input name="filename" value="<?= e($file['filename']) ?>" required>
                                </div>
                                <button class="btn btn--sm" type="submit">Speichern</button>
                            </form>
                        </details>
                        <form method="post" class="inline" action="<?= e(url('/admin/dateien/loeschen')) ?>"
                              data-confirm="Datei „<?= e($file['filename']) ?>“ endgültig löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                            <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
                            <button class="linklike linklike--danger" type="submit">löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($subfolders === [] && $files === []): ?>
                <tr>
                    <td colspan="5" class="empty">
                        <?= $suche !== '' ? 'Keine Dateien gefunden.' : 'Dieser Ordner ist leer.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($picker): ?>
    <script>
    // Auswahl an das aufrufende Fenster melden und Popup schliessen.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.js-pick');

        if (!btn) {
            return;
        }

        if (window.opener && typeof window.opener.__filePicked === 'function') {
            window.opener.__filePicked({
                id: btn.getAttribute('data-id'),
                name: btn.getAttribute('data-name')
            });
        }

        window.close();
    });
    </script>
<?php endif; ?>
