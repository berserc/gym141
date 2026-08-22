<?php

/**
 * Entwicklung – Startseite: Mitglied auswaehlen (Gewicht, Training, Formkurve,
 * Leistungstests) und die Leistungstests verwalten.
 *
 * @var list<array<string,mixed>> $mitglieder Auswahlliste (aktive + inaktive)
 * @var list<array<string,mixed>> $zuletzt    zuletzt trainierte Mitglieder
 * @var list<array<string,mixed>> $tests      alle Leistungstests inkl. result_count
 * @var bool                      $canEdit
 */
use App\Core\Auth;
?>
<div class="page-head">
    <div>
        <h1>Entwicklung</h1>
        <p class="page-head__sub">
            Gewichtsverlauf, Trainingsbesuche, Formkurve und Leistungstests je Mitglied.
        </p>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2>Mitglied öffnen</h2>
    </div>

        <form method="post" action="<?= e(url('/admin/entwicklung')) ?>" class="inline-form">
            <?= csrf_field() ?>

            <div class="field field--grow">
                <label for="dev-ref">Mitglied</label>
                <input id="dev-ref" name="member_ref" list="member-list" required
                       placeholder="tippen zum Suchen … (Zuname Vorname oder Mitgliedsnummer)">
                <datalist id="member-list">
                    <?php foreach ($mitglieder as $m): ?>
                        <option value="<?= e($m['last_name'] . ' ' . $m['first_name']) ?>">
                            <?= e(trim(((string) $m['member_no'] !== '' ? 'Nr. ' . $m['member_no'] . ' · ' : '')
                                . (($m['birthdate'] ?? null) !== null ? 'geb. ' . format_date((string) $m['birthdate']) : ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <button class="btn btn--primary" type="submit">Entwicklung öffnen</button>
        </form>

        <?php if ($zuletzt !== []): ?>
            <h3 class="muted" style="margin-top:1rem">Zuletzt beim Training</h3>
            <ul class="remind-list">
                <?php foreach ($zuletzt as $m): ?>
                    <li>
                        <span class="badge"><?= e(format_date((string) $m['letztes_training'])) ?></span>
                        <a href="<?= e(url('/admin/mitglieder/' . $m['id'] . '/entwicklung')) ?>">
                            <?= e($m['last_name']) ?>, <?= e($m['first_name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>Leistungstests</h2>
            <p class="muted">Erfassung der Ergebnisse direkt beim Mitglied.</p>
        </div>

        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr><th>Test</th><th>Einheit</th><th>Bestwert</th><th class="num">Ergebnisse</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tests as $test): ?>
                    <tr>
                        <td class="strong">
                            <?php if ((string) $test['description'] !== ''): ?>
                                <details class="test-desc">
                                    <summary title="Klick zeigt die Beschreibung"><?= e($test['name']) ?></summary>
                                    <p class="muted"><?= e($test['description']) ?></p>
                                </details>
                            <?php else: ?>
                                <?= e($test['name']) ?>
                            <?php endif; ?>
                            <?php if ((int) $test['active'] !== 1): ?>
                                <span class="badge badge--muted">deaktiviert</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($test['unit']) ?></td>
                        <td><?= (int) $test['higher_is_better'] === 1 ? 'höher = besser' : 'niedriger = besser' ?></td>
                        <td class="num"><?= (int) $test['result_count'] ?></td>
                        <td class="row-actions">
                            <?php if ($canEdit): ?>
                                <details class="plan-edit">
                                    <summary class="linklike">bearbeiten</summary>
                                    <form method="post" action="<?= e(url('/admin/entwicklung/test/' . $test['id'])) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <div class="field field--sm">
                                            <label>Name</label>
                                            <input name="name" required value="<?= e($test['name']) ?>">
                                        </div>
                                        <div class="field field--xs">
                                            <label>Einheit</label>
                                            <input name="unit" value="<?= e($test['unit']) ?>">
                                        </div>
                                        <div class="field field--sm">
                                            <label>Bestwert</label>
                                            <select name="better">
                                                <option value="higher" <?= (int) $test['higher_is_better'] === 1 ? 'selected' : '' ?>>höher = besser</option>
                                                <option value="lower" <?= (int) $test['higher_is_better'] === 0 ? 'selected' : '' ?>>niedriger = besser</option>
                                            </select>
                                        </div>
                                        <div class="field field--grow">
                                            <label>Beschreibung</label>
                                            <input name="description" value="<?= e($test['description']) ?>">
                                        </div>
                                        <button class="btn btn--sm" type="submit">Speichern</button>
                                    </form>
                                </details>
                                <form method="post" class="inline" action="<?= e(url('/admin/entwicklung/test/' . $test['id'])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="toggle" value="1">
                                    <button class="linklike" type="submit"><?= (int) $test['active'] === 1 ? 'deaktivieren' : 'aktivieren' ?></button>
                                </form>
                                <?php if (Auth::isSuperuser() && (int) $test['result_count'] === 0): ?>
                                    <form method="post" class="inline" action="<?= e(url('/admin/entwicklung/test/' . $test['id'] . '/loeschen')) ?>"
                                          data-confirm="Test „<?= e($test['name']) ?>“ löschen?">
                                        <?= csrf_field() ?>
                                        <button class="linklike linklike--danger" type="submit">löschen</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($tests === []): ?>
                    <tr><td colspan="5" class="empty">Noch keine Leistungstests angelegt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canEdit): ?>
            <details class="plan-edit">
                <summary class="linklike">+ Eigenen Test definieren</summary>
                <form method="post" action="<?= e(url('/admin/entwicklung/test')) ?>" class="inline-form">
                    <?= csrf_field() ?>

                    <div class="field field--sm">
                        <label>Name *</label>
                        <input name="name" required placeholder="z. B. Schattenboxen 3 min">
                    </div>

                    <div class="field field--xs">
                        <label>Einheit</label>
                        <input name="unit" placeholder="Wdh., s, m, kg …">
                    </div>

                    <div class="field field--sm">
                        <label>Bestwert</label>
                        <select name="better">
                            <option value="higher">höher = besser</option>
                            <option value="lower">niedriger = besser</option>
                        </select>
                    </div>

                    <div class="field field--grow">
                        <label>Beschreibung</label>
                        <input name="description" placeholder="Testablauf, Regeln …">
                    </div>

                    <button class="btn btn--primary" type="submit">Anlegen</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
