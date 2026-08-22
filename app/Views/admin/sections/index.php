<?php

use App\Core\Auth;

/**
 * @var list<array<string,mixed>> $sections
 * @var array<int,int>            $counts
 */
?>
<div class="page-head">
    <h1>Sektionen</h1>

    <?php if (Auth::isSuperuser()): ?>
        <div class="page-head__actions">
            <a class="btn btn--primary" href="<?= e(url('/admin/sektionen/neu')) ?>">Neue Sektion</a>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Logo</th>
                <th>Sportart</th>
                <th>Verein / Bezeichnung</th>
                <th>URL</th>
                <th class="num">Mitglieder</th>
                <th class="num">Reihung</th>
                <th>Sichtbar</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sections as $section): ?>
                <tr>
                    <td class="col-thumb">
                        <?php if ((string) $section['logo_path'] !== ''): ?>
                            <img src="<?= e(upload_url((string) $section['logo_path'])) ?>" alt="" width="48" height="48" loading="lazy">
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="strong" href="<?= e(url('/admin/sektionen/' . $section['id'])) ?>"><?= e($section['name']) ?></a>
                    </td>
                    <td><?= e($section['club_name']) ?></td>
                    <td>
                        <a href="<?= e(url('/sektion/' . $section['slug'])) ?>" target="_blank" rel="noopener">
                            /sektion/<?= e($section['slug']) ?>
                        </a>
                    </td>
                    <td class="num"><?= (int) ($counts[(int) $section['id']] ?? 0) ?></td>
                    <td class="num"><?= (int) $section['sort_order'] ?></td>
                    <td>
                        <?php if ((int) $section['published'] === 1): ?>
                            <span class="pill pill--aktiv">ja</span>
                        <?php else: ?>
                            <span class="pill pill--offen">nein</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a href="<?= e(url('/admin/sektionen/' . $section['id'])) ?>">Bearbeiten</a>
                        <a href="<?= e(url('/admin/mitglieder', ['section_id' => $section['id']])) ?>">Mitglieder</a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($sections === []): ?>
                <tr><td colspan="8" class="empty">Keine Sektionen vorhanden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
