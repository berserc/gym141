<?php

/**
 * @var list<array<string,mixed>> $entries
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 */
?>
<div class="page-head">
    <h1>Protokoll</h1>
    <p class="page-head__sub"><?= (int) $total ?> Einträge</p>
</div>

<div class="card">
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Zeitpunkt</th>
                <th>Benutzer</th>
                <th>Aktion</th>
                <th>Objekt</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e(format_datetime((string) $entry['created_at'])) ?></td>
                    <td><?= e($entry['username']) ?></td>
                    <td><code><?= e($entry['action']) ?></code></td>
                    <td>
                        <?= e($entry['entity']) ?>
                        <?= $entry['entity_id'] !== null ? '#' . (int) $entry['entity_id'] : '' ?>
                    </td>
                    <td class="wrap-anywhere"><?= e($entry['detail']) ?></td>
                    <td><?= e($entry['ip']) ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($entries === []): ?>
                <tr><td colspan="6" class="empty">Noch keine Einträge.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Seiten">
        <?php if ($page > 1): ?>
            <a href="<?= e(url('/admin/protokoll', ['page' => $page - 1])) ?>">Zurück</a>
        <?php endif; ?>
        <span class="pager__current">Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
        <?php if ($page < $pages): ?>
            <a href="<?= e(url('/admin/protokoll', ['page' => $page + 1])) ?>">Weiter</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
