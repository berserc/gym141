<?php

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $laender
 * @var int                       $total
 * @var int                       $page
 * @var int                       $pages
 * @var array<string,string>      $filters
 * @var int                       $aktivGesamt
 */
$returnTo = url('/admin/gemeinden') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<div class="page-head">
    <div>
        <h1>Gemeinden</h1>
        <p class="page-head__sub">
            <?= (int) $aktivGesamt ?> von <?= (int) array_sum(array_column($laender, 'gesamt')) ?>
            Gemeinden stehen im Mitgliederformular zur Auswahl.
        </p>
    </div>
</div>

<div class="notice">
    Die Liste stammt aus dem amtlichen Gemeindeverzeichnis der
    <strong>STATISTIK AUSTRIA</strong> (Gebietsstand 2026) und enthält alle
    österreichischen Gemeinden. Freigeschaltet sind standardmäßig die steirischen –
    das hält die Auswahl bei der Mitgliedererfassung übersichtlich.
    Im Mitgliederformular lässt sich außerdem jederzeit ein freier Text eintragen.
</div>

<div class="card">
    <div class="card__head">
        <h2>Nach Bundesland</h2>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Bundesland</th>
                <th class="num">Gemeinden</th>
                <th class="num">freigeschaltet</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($laender as $land): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('/admin/gemeinden', ['bundesland' => $land['bundesland']])) ?>">
                            <?= e($land['bundesland']) ?>
                        </a>
                    </td>
                    <td class="num"><?= (int) $land['gesamt'] ?></td>
                    <td class="num"><?= (int) $land['aktiv'] ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/gemeinden/bundesland')) ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="bundesland" value="<?= e($land['bundesland']) ?>">
                            <input type="hidden" name="aktiv" value="1">
                            <button class="linklike" type="submit">alle freischalten</button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/gemeinden/bundesland')) ?>" class="inline"
                              data-confirm="Alle Gemeinden in <?= e($land['bundesland']) ?> aus der Auswahlliste nehmen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="bundesland" value="<?= e($land['bundesland']) ?>">
                            <input type="hidden" name="aktiv" value="0">
                            <button class="linklike linklike--danger" type="submit">alle ausblenden</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/gemeinden')) ?>">
    <div class="filters__row">
        <div class="field field--grow">
            <label for="g-q">Suche</label>
            <input id="g-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                   placeholder="Name, PLZ oder Gemeindekennziffer">
        </div>

        <div class="field">
            <label for="g-land">Bundesland</label>
            <select id="g-land" name="bundesland">
                <option value="">alle</option>
                <?php foreach ($laender as $land): ?>
                    <option value="<?= e($land['bundesland']) ?>"
                        <?= $filters['bundesland'] === $land['bundesland'] ? 'selected' : '' ?>>
                        <?= e($land['bundesland']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <label class="check">
            <input type="checkbox" name="nur_aktive" value="1" <?= $filters['nur_aktive'] !== '' ? 'checked' : '' ?>>
            nur freigeschaltete
        </label>

        <button class="btn btn--primary" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/gemeinden')) ?>">Zurücksetzen</a>
    </div>
</form>

<div class="card">
    <div class="card__head">
        <h2><?= (int) $total ?> Treffer</h2>
        <p class="muted">Seite <?= (int) $page ?> von <?= (int) $pages ?></p>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Gemeinde</th>
                <th>PLZ</th>
                <th>Bundesland</th>
                <th>GKZ</th>
                <th>Auswahlliste</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="strong"><?= e($row['name']) ?></td>
                    <td><?= e($row['plz']) ?></td>
                    <td><?= e($row['bundesland']) ?></td>
                    <td><?= e($row['gkz']) ?: '<span class="muted">eigener Eintrag</span>' ?></td>
                    <td>
                        <?php if ((int) $row['active'] === 1): ?>
                            <span class="pill pill--aktiv">freigeschaltet</span>
                        <?php else: ?>
                            <span class="pill pill--offen">ausgeblendet</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/gemeinden/umschalten')) ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                            <button class="linklike" type="submit">
                                <?= (int) $row['active'] === 1 ? 'ausblenden' : 'freischalten' ?>
                            </button>
                        </form>

                        <?php if ((string) $row['gkz'] === ''): ?>
                            <form method="post" action="<?= e(url('/admin/gemeinden/loeschen')) ?>" class="inline"
                                  data-confirm="Eigenen Eintrag „<?= e($row['name']) ?>“ löschen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="empty">Keine Gemeinde gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Seiten">
        <?php if ($page > 1): ?>
            <a href="<?= e(url('/admin/gemeinden', array_merge($filters, ['page' => $page - 1]))) ?>">Zurück</a>
        <?php endif; ?>
        <span class="pager__current">Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
        <?php if ($page < $pages): ?>
            <a href="<?= e(url('/admin/gemeinden', array_merge($filters, ['page' => $page + 1]))) ?>">Weiter</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <h2>Eigenen Eintrag ergänzen</h2>
        <p class="muted">Etwa für Mitglieder mit Wohnsitz im Ausland.</p>
    </div>

    <form method="post" action="<?= e(url('/admin/gemeinden/neu')) ?>" class="inline-form">
        <?= csrf_field() ?>

        <div class="field field--grow">
            <label for="neu-name">Name</label>
            <input id="neu-name" name="name" required>
        </div>

        <div class="field field--xs">
            <label for="neu-plz">PLZ</label>
            <input id="neu-plz" name="plz">
        </div>

        <div class="field field--sm">
            <label for="neu-land">Bundesland / Land</label>
            <input id="neu-land" name="bundesland" value="sonstige">
        </div>

        <button class="btn" type="submit">Hinzufügen</button>
    </form>
</div>
