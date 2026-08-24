<?php

/** @var list<array<string,mixed>> $pages */
?>
<div class="page-head">
    <h1>Seiten</h1>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/inhalt/0')) ?>">Startseite gestalten</a>
        <a class="btn btn--primary" href="<?= e(url('/admin/seiten/neu')) ?>">Neue Seite</a>
    </div>
</div>

<div class="card">
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Titel</th>
                <th>URL</th>
                <th>Im Fußbereich</th>
                <th>Sichtbar</th>
                <th>Geändert</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pages as $page): ?>
                <tr>
                    <td><a class="strong" href="<?= e(url('/admin/seiten/' . $page['id'])) ?>"><?= e($page['title']) ?></a></td>
                    <td><a href="<?= e(url('/seite/' . $page['slug'])) ?>" target="_blank" rel="noopener">/seite/<?= e($page['slug']) ?></a></td>
                    <td><?= (int) $page['in_footer'] === 1 ? 'ja' : 'nein' ?></td>
                    <td>
                        <?php if ((int) $page['published'] === 1): ?>
                            <span class="pill pill--aktiv">ja</span>
                        <?php else: ?>
                            <span class="pill pill--offen">nein</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_datetime((string) $page['updated_at'])) ?></td>
                    <td class="row-actions">
                        <a href="<?= e(url('/admin/seiten/' . $page['id'])) ?>">Bearbeiten</a>
                        <a href="<?= e(url('/admin/inhalt/' . $page['id'])) ?>">Inhalt</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
