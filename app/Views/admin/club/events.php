<?php

use App\Core\Auth;

/**
 * Vereinshistorie: Rechnungsprüfungen, Sitzungen, Versammlungen – mit Text,
 * Datum und Dokumenten. Nur für den Vorstand (Superuser, Kassier).
 *
 * @var list<array<string,mixed>>              $events
 * @var array<int,list<array<string,mixed>>>   $dokumente je Ereignis
 * @var array<int,list<array<string,mixed>>>   $links     je Ereignis
 * @var list<string>                           $types
 * @var array<string,string>                   $errors
 */
?>
<?= admin_tabs([
    ['Vereinshistorie', '/admin/verein'],
    ['Dokumentenarchiv', '/admin/verein/dokumente'],
]) ?>

<div class="page-head">
    <div>
        <h1>Vereinshistorie</h1>
        <p class="page-head__sub">
            Rechnungsprüfungen, Vorstandssitzungen, Mitglieder- und Generalversammlungen –
            mit Protokolltext und Dokumenten. Sichtbar nur für den Vorstand.
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/vorstand')) ?>">Zum Vorstand</a>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Neues Ereignis</h2>
    </div>

    <form method="post" action="<?= e(url('/admin/verein')) ?>" enctype="multipart/form-data" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--sm">
            <label for="ev-type">Art *</label>
            <select id="ev-type" name="type">
                <?php foreach ($types as $typ): ?>
                    <option value="<?= e($typ) ?>"><?= e($typ) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--sm">
            <label for="ev-date">Datum *</label>
            <input id="ev-date" name="event_date" type="date" required value="<?= e(date('Y-m-d')) ?>">
        </div>

        <div class="field field--grow">
            <label for="ev-title">Titel</label>
            <input id="ev-title" name="title" placeholder="z. B. Rechnungsprüfung Vereinsjahr 2025/26">
        </div>

        <div class="field field--grow">
            <label for="ev-text">Text / Protokoll</label>
            <textarea id="ev-text" name="text" rows="3"></textarea>
        </div>

        <div class="field field--grow">
            <label for="ev-file">Dokument <small>(optional, weitere später möglich)</small></label>
            <input id="ev-file" name="file" type="file"
                   accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.ppt,.pptx,.txt,.csv">
        </div>

        <button class="btn btn--primary" type="submit">Erfassen</button>
    </form>
</div>

<?php foreach ($events as $event): ?>
    <div class="card">
        <div class="card__head">
            <h2>
                <span class="badge"><?= e($event['type']) ?></span>
                <?= e(format_date((string) $event['event_date'])) ?>
                <?php if ((string) $event['title'] !== ''): ?>
                    – <?= e($event['title']) ?>
                <?php endif; ?>
            </h2>
            <p class="muted">
                erfasst von <?= e((string) ($event['created_by_name'] ?? '')) ?>
                · <?= (int) $event['doc_count'] ?> Dokument(e)
            </p>
        </div>

        <?php if (trim((string) $event['text']) !== ''): ?>
            <p style="white-space: pre-line"><?= e($event['text']) ?></p>
        <?php endif; ?>

        <?php if (($dokumente[(int) $event['id']] ?? []) !== []): ?>
            <div class="table-scroll">
                <table class="table table--compact">
                    <thead>
                    <tr><th>Dokument</th><th>Datum</th><th>Beschreibung</th><th class="num">Größe</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dokumente[(int) $event['id']] as $doc): ?>
                        <tr>
                            <td class="strong">
                                <a href="<?= e(url('/admin/verein/dokument/' . $doc['id'])) ?>" target="_blank" rel="noopener">
                                    <?= e($doc['title']) ?>
                                </a>
                                <small class="muted"><?= e($doc['filename']) ?></small>
                            </td>
                            <td><?= e(format_date((string) $doc['doc_date'])) ?></td>
                            <td><?= e($doc['description']) ?></td>
                            <td class="num"><?= number_format((int) $doc['size'] / 1024, 0, ',', '.') ?> KB</td>
                            <td class="row-actions">
                                <?php if (Auth::isSuperuser()): ?>
                                    <form method="post" class="inline"
                                          action="<?= e(url('/admin/verein/dokument/' . $doc['id'] . '/loeschen')) ?>"
                                          data-confirm="Dokument „<?= e($doc['title']) ?>“ endgültig löschen?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return" value="verein">
                                        <button class="linklike linklike--danger" type="submit">löschen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="media-chips" style="margin-bottom:.7rem">
            <?php foreach ($links[(int) $event['id']] ?? [] as $link): ?>
                <span class="media-chip">
                    <?php $istVideo = preg_match('#(youtube\.com|youtu\.be)/#i', (string) $link['url']) === 1; ?>
                    <?php if ($istVideo): ?>
                        <button type="button" class="media-chip__main" data-video="<?= e($link['url']) ?>"
                                title="Video im Mini-Player abspielen">▶&nbsp;<?= e((string) $link['label'] !== '' ? $link['label'] : 'Video') ?></button>
                    <?php else: ?>
                        <a class="media-chip__main" href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer">
                            🔗&nbsp;<?= e((string) $link['label'] !== '' ? $link['label'] : preg_replace('#^https?://(www\.)?#i', '', (string) $link['url'])) ?>
                        </a>
                    <?php endif; ?>
                    <form method="post" class="inline"
                          action="<?= e(url('/admin/verein/' . $event['id'] . '/link-loeschen')) ?>"
                          data-confirm="Link entfernen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                        <button class="media-chip__remove" type="submit" title="entfernen">×</button>
                    </form>
                </span>
            <?php endforeach; ?>

            <details class="media-add">
                <summary title="Link anhängen (Ergebnisliste, Video …)">+</summary>
                <form method="post" action="<?= e(url('/admin/verein/' . $event['id'] . '/link')) ?>" class="media-add__form">
                    <?= csrf_field() ?>
                    <label>Link (Ergebnisliste, YouTube …)
                        <input name="url" required placeholder="https://…">
                    </label>
                    <label>Bezeichnung
                        <input name="label" placeholder="z. B. Ergebnisliste, Video">
                    </label>
                    <button class="btn btn--sm" type="submit">Anhängen</button>
                </form>
            </details>
        </div>

        <div class="inline-form">
            <form method="post" action="<?= e(url('/admin/verein/' . $event['id'] . '/dokument')) ?>"
                  enctype="multipart/form-data" class="inline-form" style="border-top:0;margin-top:0;padding-top:0">
                <?= csrf_field() ?>

                <div class="field field--grow">
                    <label>Dokument nachreichen</label>
                    <input name="file" type="file"
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
                    <label>Titel</label>
                    <input name="title" placeholder="z. B. Prüfbericht">
                </div>

                <div class="field field--grow">
                    <label>Beschreibung</label>
                    <input name="description">
                </div>

                <button class="btn btn--sm" type="submit">Hochladen</button>
            </form>

            <details class="plan-edit">
                <summary class="linklike">Ereignis bearbeiten</summary>
                <form method="post" action="<?= e(url('/admin/verein/' . $event['id'])) ?>" class="inline-form">
                    <?= csrf_field() ?>

                    <div class="field field--sm">
                        <label>Art</label>
                        <select name="type">
                            <?php foreach ($types as $typ): ?>
                                <option value="<?= e($typ) ?>" <?= $event['type'] === $typ ? 'selected' : '' ?>><?= e($typ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field field--sm">
                        <label>Datum</label>
                        <input name="event_date" type="date" value="<?= e((string) $event['event_date']) ?>">
                    </div>

                    <div class="field field--grow">
                        <label>Titel</label>
                        <input name="title" value="<?= e($event['title']) ?>">
                    </div>

                    <div class="field field--grow">
                        <label>Text</label>
                        <textarea name="text" rows="3"><?= e($event['text']) ?></textarea>
                    </div>

                    <button class="btn btn--sm" type="submit">Speichern</button>
                </form>
            </details>

            <?php if (Auth::isSuperuser()): ?>
                <form method="post" class="inline"
                      action="<?= e(url('/admin/verein/' . $event['id'] . '/loeschen')) ?>"
                      data-confirm="Ereignis löschen? Dokumente bleiben im Archiv erhalten.">
                    <?= csrf_field() ?>
                    <button class="linklike linklike--danger" type="submit">Ereignis löschen</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($events === []): ?>
    <div class="card">
        <p class="empty">Noch keine Ereignisse erfasst.</p>
    </div>
<?php endif; ?>
