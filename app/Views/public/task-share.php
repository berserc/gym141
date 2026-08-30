<?php

use App\Controllers\TaskController;

/**
 * Oeffentliche Aufgaben-Freigabe: Externe koennen ohne Konto die Checkliste
 * abhaken und Dateien hochladen. Zugang nur ueber den Freigabe-Token.
 *
 * @var array<string,mixed>       $task
 * @var string                    $token
 * @var list<array<string,mixed>> $items
 * @var list<array<string,mixed>> $files
 */
$aktion   = url('/f/' . $token);
$erledigt = $task['status'] === 'erledigt';
$typen    = implode(',', array_map(static fn (string $e): string => '.' . $e, array_keys(TaskController::FILE_TYPES)));
$fehler   = trim((string) ($_GET['fehler'] ?? ''));
?>
<section class="wrap" style="max-width:44rem;padding:2.5rem 1rem">
    <p style="opacity:.6;font-size:.85rem;margin:0 0 .3rem">Freigegebene Aufgabe – Mitarbeit ohne Konto</p>
    <h1 style="margin:0 0 .5rem"><?= e($task['title']) ?><?= $erledigt ? ' ✓' : '' ?></h1>

    <?php if ((string) $task['description'] !== ''): ?>
        <p style="white-space:pre-line;opacity:.85"><?= e($task['description']) ?></p>
    <?php endif; ?>
    <?php if ($task['due_date'] !== null): ?>
        <p style="opacity:.7">Fällig am <?= e(date('d.m.Y', (int) strtotime((string) $task['due_date']))) ?></p>
    <?php endif; ?>

    <?php if ($fehler !== ''): ?>
        <p role="alert" style="background:#c0392b;color:#fff;padding:.6rem .9rem;border-radius:.4rem">
            <?= e($fehler) ?>
        </p>
    <?php endif; ?>

    <?php if ($items !== []): ?>
        <h2 style="font-size:1.1rem;margin:1.5rem 0 .5rem">Checkliste</h2>
        <?php foreach ($items as $item): ?>
            <form method="post" action="<?= e($aktion) ?>" style="margin:.25rem 0">
                <input type="hidden" name="item" value="<?= (int) $item['id'] ?>">
                <button type="submit" style="all:unset;cursor:pointer;display:flex;gap:.5rem;align-items:baseline">
                    <span style="font-size:1.1rem"><?= (int) $item['done'] === 1 ? '☑' : '☐' ?></span>
                    <span style="<?= (int) $item['done'] === 1 ? 'text-decoration:line-through;opacity:.55' : '' ?>">
                        <?= e($item['title']) ?>
                    </span>
                </button>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($files !== []): ?>
        <h2 style="font-size:1.1rem;margin:1.5rem 0 .5rem">Dateien</h2>
        <?php foreach ($files as $file): ?>
            <p style="margin:.25rem 0">
                📎 <a href="<?= e(url('/f/' . $token . '/datei/' . $file['id'])) ?>" target="_blank" rel="noopener">
                    <?= e($file['filename']) ?></a>
                <small style="opacity:.6">(<?= e(number_format((int) $file['size'] / 1048576, 1, ',', '.')) ?> MB
                    · von <?= e((string) $file['uploaded_by']) ?>)</small>
            </p>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 style="font-size:1.1rem;margin:1.5rem 0 .5rem">Datei beisteuern</h2>
    <form method="post" action="<?= e($aktion) ?>" enctype="multipart/form-data"
          style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
        <input name="von" maxlength="80" placeholder="Dein Name" style="padding:.45rem .6rem">
        <input type="file" name="datei" required accept="<?= e($typen) ?>">
        <button class="btn" type="submit">Hochladen</button>
    </form>
    <p style="opacity:.6;font-size:.85rem">Dokumente, Fotos und Videos bis 200 MB.</p>
</section>
