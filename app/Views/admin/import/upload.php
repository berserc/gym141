<?php

/** @var list<array<string,mixed>> $sections */
?>
<div class="page-head">
    <h1>Mitglieder importieren</h1>
</div>

<div class="card">
    <h2>So funktioniert es</h2>
    <ol class="steps">
        <li>CSV-Datei hochladen (Trennzeichen Semikolon, Komma oder Tabulator).</li>
        <li>Spalten den Feldern zuordnen und die Vorschau prüfen.</li>
        <li>Zuerst einen <strong>Probelauf</strong> starten – dabei wird nichts gespeichert.</li>
        <li>Wenn die Zahlen stimmen, den Import wirklich ausführen.</li>
    </ol>

    <p class="muted">
        Pflichtfelder sind Vorname und Zuname. Die Sektion kann entweder aus einer Spalte gelesen
        oder für alle Zeilen fest vorgegeben werden. Datumsangaben werden als
        <code>TT.MM.JJJJ</code> oder <code>JJJJ-MM-TT</code> erkannt.
    </p>
</div>

<form method="post" action="<?= e(url('/admin/import/vorschau')) ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label for="csv">CSV-Datei *</label>
        <input id="csv" name="csv" type="file" accept=".csv,text/csv,text/plain" required>
        <p class="field__hint">Maximal 20 MB. Excel: „Speichern unter → CSV UTF-8“.</p>
    </div>

    <div class="field-row">
        <div class="field field--sm">
            <label for="delimiter">Trennzeichen</label>
            <select id="delimiter" name="delimiter">
                <option value="auto">automatisch erkennen</option>
                <option value=";">Semikolon (;)</option>
                <option value=",">Komma (,)</option>
                <option value="&#9;">Tabulator</option>
            </select>
        </div>

        <div class="field field--grow">
            <label for="default_section_id">Standard-Sektion</label>
            <select id="default_section_id" name="default_section_id">
                <option value="0">— aus Spalte lesen —</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>"><?= e($section['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="field__hint">Wird verwendet, wenn die Datei keine Sektionsspalte hat.</p>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Datei einlesen</button>
    </div>
</form>
