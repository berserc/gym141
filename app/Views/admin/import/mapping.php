<?php

use App\Controllers\ImportController;

/**
 * @var string                    $token
 * @var string                    $delimiter
 * @var list<string>              $header
 * @var list<list<string>>        $rows
 * @var array<string,int>         $mapping
 * @var list<array<string,mixed>> $sections
 * @var int                       $defaultSection
 */
?>
<div class="page-head">
    <h1>Spalten zuordnen</h1>
    <p class="page-head__sub">Vorschau der ersten <?= count($rows) ?> Datenzeilen</p>
</div>

<form method="post" action="<?= e(url('/admin/import/ausfuehren')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="delimiter" value="<?= e($delimiter) ?>">

    <div class="card">
        <h2>Zuordnung</h2>
        <p class="muted">Felder mit * müssen zugeordnet sein.</p>

        <div class="mapping-grid">
            <?php foreach (ImportController::TARGETS as $field => $definition): ?>
                <div class="field">
                    <label for="map-<?= e($field) ?>"><?= e($definition['label']) ?></label>
                    <select id="map-<?= e($field) ?>" name="mapping[<?= e($field) ?>]">
                        <option value="">— nicht importieren —</option>
                        <?php foreach ($header as $index => $column): ?>
                            <option value="<?= (int) $index ?>"
                                <?= isset($mapping[$field]) && $mapping[$field] === $index ? 'selected' : '' ?>>
                                <?= e($column !== '' ? $column : 'Spalte ' . ($index + 1)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2>Optionen</h2>

        <div class="field-row">
            <div class="field field--grow">
                <label for="default_section_id">Standard-Sektion</label>
                <select id="default_section_id" name="default_section_id">
                    <option value="0">— aus der zugeordneten Spalte —</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $defaultSection === (int) $section['id'] ? 'selected' : '' ?>>
                            <?= e($section['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field field--grow">
                <label for="mode">Vorgehen bei vorhandenen Mitgliedern</label>
                <select id="mode" name="mode">
                    <option value="insert">immer neu anlegen</option>
                    <option value="upsert">vorhandene aktualisieren (Mitgliedsnummer bzw. Name + Geburtsdatum)</option>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Vorschau</h2>

        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr>
                    <?php foreach ($header as $index => $column): ?>
                        <th><?= e($column !== '' ? $column : 'Spalte ' . ($index + 1)) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($header as $index => $unused): ?>
                            <td><?= e($row[$index] ?? '') ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit" name="dry_run" value="1">Probelauf (nichts speichern)</button>
        <button class="btn btn--primary" type="submit" name="dry_run" value="0"
                data-confirm-click="Import jetzt wirklich ausführen?">Import ausführen</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/import')) ?>">Abbrechen</a>
    </div>
</form>
