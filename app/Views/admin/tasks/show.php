<?php

use App\Controllers\TaskController;

/**
 * Aufgaben-Detail: Stammdaten, Checkliste, Anhaenge, Freigabe fuer Externe.
 *
 * @var array<string,mixed>       $task
 * @var list<array<string,mixed>> $items
 * @var list<array<string,mixed>> $files
 */
$id       = (int) $task['id'];
$erledigt = $task['status'] === 'erledigt';
$aktion   = url('/admin/aufgaben/' . $id);
$typen    = implode(',', array_map(static fn (string $e): string => '.' . $e, array_keys(TaskController::FILE_TYPES)));
?>
<div class="page-head">
    <div>
        <h1><?= e($task['title']) ?> <?= $erledigt ? '<span class="badge">erledigt</span>' : '' ?></h1>
        <p class="page-head__sub">
            <?= $task['due_date'] !== null ? 'fällig am ' . e(date('d.m.Y', (int) strtotime((string) $task['due_date']))) : 'ohne Termin' ?>
            · angelegt <?= e(date('d.m.Y', (int) strtotime((string) $task['created_at']))) ?>
        </p>
    </div>

    <div class="page-head__actions">
        <form method="post" action="<?= e($aktion) ?>" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="status">
            <input type="hidden" name="status" value="<?= $erledigt ? 'offen' : 'erledigt' ?>">
            <button class="btn <?= $erledigt ? 'btn--ghost' : '' ?>" type="submit">
                <?= $erledigt ? 'Wieder öffnen' : '✓ Als erledigt markieren' ?>
            </button>
        </form>
        <a class="btn btn--ghost" href="<?= e(url('/admin/aufgaben')) ?>">Alle Aufgaben</a>
    </div>
</div>

<div class="form-grid">
    <div>
        <div class="card">
            <div class="card__head"><h2>Checkliste</h2></div>

            <?php foreach ($items as $item): ?>
                <div class="inline-form" style="align-items:center;gap:.5rem">
                    <form method="post" action="<?= e($aktion) ?>" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="item_umschalten">
                        <input type="hidden" name="item" value="<?= (int) $item['id'] ?>">
                        <button class="linklike" type="submit" title="abhaken bzw. öffnen"
                                style="font-size:1.1rem"><?= (int) $item['done'] === 1 ? '☑' : '☐' ?></button>
                    </form>
                    <span style="flex:1;<?= (int) $item['done'] === 1 ? 'text-decoration:line-through;opacity:.6' : '' ?>">
                        <?= e($item['title']) ?>
                    </span>
                    <form method="post" action="<?= e($aktion) ?>" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="item_loeschen">
                        <input type="hidden" name="item" value="<?= (int) $item['id'] ?>">
                        <button class="linklike linklike--danger" type="submit">entfernen</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?= e($aktion) ?>" class="inline-form" style="margin-top:.5rem">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="item_neu">
                <div class="field field--grow">
                    <input name="titel" required maxlength="200" placeholder="Neuer Punkt">
                </div>
                <button class="btn btn--sm" type="submit">Hinzufügen</button>
            </form>
        </div>

        <div class="card">
            <div class="card__head"><h2>Anhänge</h2></div>

            <?php foreach ($files as $file): ?>
                <div class="inline-form" style="align-items:center;gap:.5rem">
                    <a href="<?= e(url('/aufgabe-datei/' . $file['id'])) ?>" target="_blank" rel="noopener" style="flex:1">
                        📎 <?= e($file['filename']) ?>
                    </a>
                    <span class="muted"><?= e(number_format((int) $file['size'] / 1048576, 1, ',', '.')) ?> MB
                        · von <?= e((string) $file['uploaded_by']) ?></span>
                    <form method="post" action="<?= e($aktion) ?>" class="inline"
                          data-confirm="Datei „<?= e($file['filename']) ?>“ löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="datei_loeschen">
                        <input type="hidden" name="datei" value="<?= (int) $file['id'] ?>">
                        <button class="linklike linklike--danger" type="submit">löschen</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?= e($aktion) ?>" enctype="multipart/form-data"
                  class="inline-form" style="margin-top:.5rem">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="upload">
                <div class="field field--grow">
                    <input type="file" name="datei" required accept="<?= e($typen) ?>">
                </div>
                <button class="btn btn--sm" type="submit">Hochladen</button>
            </form>
            <p class="muted">Dokumente, Fotos und Videos bis 200 MB.</p>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__head"><h2>Details</h2></div>
            <form method="post" action="<?= e($aktion) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="stammdaten">
                <div class="field">
                    <label>Titel</label>
                    <input name="title" required maxlength="200" value="<?= e($task['title']) ?>">
                </div>
                <div class="field">
                    <label>Beschreibung</label>
                    <textarea name="description" rows="4" maxlength="4000"><?= e($task['description']) ?></textarea>
                </div>
                <div class="field">
                    <label>Fällig am</label>
                    <input type="date" name="due_date" value="<?= e((string) ($task['due_date'] ?? '')) ?>">
                </div>
                <button class="btn" type="submit">Speichern</button>
            </form>
        </div>

        <div class="card">
            <div class="card__head"><h2>Freigabe für Externe</h2></div>
            <?php if ($task['share_token'] !== null): ?>
                <?php
                $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $link   = $schema . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/f/' . $task['share_token']);
                ?>
                <p class="muted" style="word-break:break-all">
                    <a href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($link) ?></a>
                </p>
                <p class="muted">Wer den Link hat, kann die Checkliste abhaken und Dateien hochladen – ohne Konto.</p>
                <form method="post" action="<?= e($aktion) ?>" class="inline"
                      data-confirm="Freigabe beenden? Der Link funktioniert danach nicht mehr.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="freigabe">
                    <input type="hidden" name="an" value="0">
                    <button class="btn btn--ghost btn--sm" type="submit">Freigabe beenden</button>
                </form>
            <?php else: ?>
                <p class="muted">Erzeuge einen Link, mit dem Außenstehende (Helfer, Eltern, Lieferanten)
                    ohne Vereins-Zugang mitarbeiten können.</p>
                <form method="post" action="<?= e($aktion) ?>" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="freigabe">
                    <input type="hidden" name="an" value="1">
                    <button class="btn btn--sm" type="submit">Freigabe-Link erzeugen</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__head"><h2>Gefahrenzone</h2></div>
            <form method="post" action="<?= e($aktion) ?>"
                  data-confirm="Aufgabe „<?= e($task['title']) ?>“ samt Checkliste und Anhängen endgültig löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="loeschen">
                <button class="btn btn--danger btn--sm" type="submit">Aufgabe löschen</button>
            </form>
        </div>
    </div>
</div>
