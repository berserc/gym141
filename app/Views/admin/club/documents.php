<?php

use App\Core\Auth;

/**
 * Dokumentenarchiv des Vereins: Statuten, Protokolle, Prüfberichte …
 * Datumsmäßig erfasst mit genauer Beschreibung. Nur für den Vorstand.
 *
 * @var array<string,string>      $filters
 * @var list<string>              $types
 * @var list<array<string,mixed>> $docs
 * @var array<string,string>      $errors
 */
?>
<?= admin_tabs([
    ['Vereinshistorie', '/admin/verein'],
    ['Dokumentenarchiv', '/admin/verein/dokumente'],
]) ?>

<div class="page-head">
    <div>
        <h1>Dokumentenarchiv</h1>
        <p class="page-head__sub">
            Statuten, Protokolle, Prüfberichte … – sicher abgelegt, sichtbar nur für
            den Vorstand. Dokumente aus der Vereinshistorie erscheinen hier ebenfalls.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/verein')) ?>">Vereinshistorie</a>
        <a class="btn btn--ghost" href="<?= e(url('/admin/vorstand')) ?>">Zum Vorstand</a>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/verein/dokumente')) ?>">
    <div class="filters__row">
        <div class="field field--grow">
            <label for="f-q">Suche</label>
            <input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                   placeholder="Titel, Beschreibung oder Dateiname …">
        </div>

        <div class="field field--sm">
            <label for="f-type">Ereignis-Art</label>
            <select id="f-type" name="type">
                <option value="">alle</option>
                <?php foreach ($types as $typ): ?>
                    <option value="<?= e($typ) ?>" <?= $filters['type'] === $typ ? 'selected' : '' ?>><?= e($typ) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn--primary" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/verein/dokumente')) ?>">Zurücksetzen</a>
    </div>
</form>

<div class="card">
    <div class="card__head">
        <h2>Neues Dokument ablegen</h2>
        <p class="muted">z. B. die aktuellen Statuten als PDF.</p>
    </div>

    <form method="post" action="<?= e(url('/admin/verein/dokumente')) ?>" enctype="multipart/form-data" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--grow">
            <label for="nd-file">Datei <small>(max. 25 MB)</small></label>
            <input id="nd-file" name="file" type="file"
                   accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.ppt,.pptx,.txt,.csv">
        </div>

        <div class="field">
            <label>… oder aus der Dateiablage</label>
            <input type="hidden" name="media_file_id" value="">
            <span class="media-add__pickrow">
                <button type="button" class="btn btn--sm js-open-picker"
                        data-picker-url="<?= e(url('/admin/dateien', ['picker' => 1])) ?>">📁 Datei wählen</button>
                <span class="js-picked muted"></span>
            </span>
        </div>

        <div class="field field--sm">
            <label for="nd-title">Titel *</label>
            <input id="nd-title" name="title" required placeholder="z. B. Statuten 2026">
        </div>

        <div class="field field--sm">
            <label for="nd-date">Datum</label>
            <input id="nd-date" name="doc_date" type="date" value="<?= e(date('Y-m-d')) ?>">
        </div>

        <div class="field field--grow">
            <label for="nd-desc">Genaue Beschreibung</label>
            <input id="nd-desc" name="description"
                   placeholder="z. B. beschlossen in der Generalversammlung am …">
        </div>

        <button class="btn btn--primary" type="submit">Ablegen</button>
    </form>
</div>

<div class="card">
    <div class="card__head">
        <h2><?= count($docs) ?> Dokument(e)</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Datum</th>
                <th>Titel</th>
                <th>Beschreibung</th>
                <th>Ereignis</th>
                <th class="num">Größe</th>
                <th>abgelegt</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($docs as $doc): ?>
                <tr>
                    <td><?= e(format_date((string) $doc['doc_date'])) ?></td>
                    <td class="strong">
                        <a href="<?= e(url('/admin/verein/dokument/' . $doc['id'])) ?>" target="_blank" rel="noopener">
                            <?= e($doc['title']) ?>
                        </a>
                        <small class="muted"><?= e($doc['filename']) ?></small>
                    </td>
                    <td><?= e($doc['description']) ?></td>
                    <td>
                        <?php if ($doc['event_id'] !== null): ?>
                            <span class="badge"><?= e((string) $doc['event_type']) ?></span>
                            <small class="muted"><?= e(format_date((string) $doc['event_date'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int) $doc['size'] / 1024, 0, ',', '.') ?> KB</td>
                    <td class="muted">
                        <?= e(format_datetime((string) $doc['created_at'])) ?>
                        <?= (string) ($doc['uploaded_by_name'] ?? '') !== '' ? '· ' . e($doc['uploaded_by_name']) : '' ?>
                    </td>
                    <td class="row-actions">
                        <details class="plan-edit">
                            <summary class="linklike">bearbeiten</summary>
                            <form method="post" action="<?= e(url('/admin/verein/dokument/' . $doc['id'] . '/bearbeiten')) ?>" class="inline-form">
                                <?= csrf_field() ?>

                                <div class="field field--sm">
                                    <label>Titel</label>
                                    <input name="title" value="<?= e($doc['title']) ?>">
                                </div>

                                <div class="field field--sm">
                                    <label>Datum</label>
                                    <input name="doc_date" type="date" value="<?= e((string) $doc['doc_date']) ?>">
                                </div>

                                <div class="field field--grow">
                                    <label>Beschreibung</label>
                                    <input name="description" value="<?= e($doc['description']) ?>">
                                </div>

                                <button class="btn btn--sm" type="submit">Speichern</button>
                            </form>
                        </details>

                        <?php if (Auth::isSuperuser()): ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('/admin/verein/dokument/' . $doc['id'] . '/loeschen')) ?>"
                                  data-confirm="Dokument „<?= e($doc['title']) ?>“ endgültig löschen?">
                                <?= csrf_field() ?>
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($docs === []): ?>
                <tr><td colspan="7" class="empty">Keine Dokumente gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
