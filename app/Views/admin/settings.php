<?php

/**
 * @var array<string,string> $settings
 * @var int                  $feeYear
 * @var int                  $gemeindenAktiv
 * @var int                  $gemeindenTotal
 */
$value = static fn (string $key, string $default = ''): string => $settings[$key] ?? $default;
?>
<div class="page-head">
    <h1>Einstellungen</h1>
</div>

<form method="post" action="<?= e(url('/admin/einstellungen')) ?>" class="form">
    <?= csrf_field() ?>

    <div class="form-grid">
        <fieldset class="card">
            <legend>Vereinsdaten (Fußbereich und Impressum)</legend>

            <div class="field">
                <label for="club_name">Vereinsname</label>
                <input id="club_name" name="club_name" value="<?= e($value('club_name')) ?>">
            </div>

            <div class="field">
                <label for="club_street">Straße</label>
                <input id="club_street" name="club_street" value="<?= e($value('club_street')) ?>">
            </div>

            <div class="field-row">
                <div class="field field--xs">
                    <label for="club_zip">PLZ</label>
                    <input id="club_zip" name="club_zip" value="<?= e($value('club_zip')) ?>">
                </div>

                <div class="field field--grow">
                    <label for="club_city">Ort</label>
                    <input id="club_city" name="club_city" value="<?= e($value('club_city')) ?>">
                </div>
            </div>

            <div class="field-row">
                <div class="field field--grow">
                    <label for="club_email">E-Mail</label>
                    <input id="club_email" name="club_email" type="email" value="<?= e($value('club_email')) ?>">
                </div>

                <div class="field field--grow">
                    <label for="club_phone">Telefon</label>
                    <input id="club_phone" name="club_phone" value="<?= e($value('club_phone')) ?>">
                </div>
            </div>

            <div class="field">
                <label for="whatsapp_number">WhatsApp-Nummer (Probetraining-Button)</label>
                <input id="whatsapp_number" name="whatsapp_number"
                       value="<?= e($value('whatsapp_number')) ?>" placeholder="+43 664 …">
                <p class="field__hint">
                    Ziel des „1. Training gratis“-Buttons auf der Website.
                    Mit Ländervorwahl angeben (z. B. +43 …). Leer = kein WhatsApp-Button auf der Website.
                </p>
            </div>

            <div class="field">
                <label for="club_zvr">ZVR-Zahl</label>
                <input id="club_zvr" name="club_zvr" value="<?= e($value('club_zvr')) ?>">
            </div>

            <div class="field-row">
                <div class="field field--grow">
                    <label for="club_iban">IBAN</label>
                    <input id="club_iban" name="club_iban" value="<?= e($value('club_iban')) ?>">
                </div>

                <div class="field field--grow">
                    <label for="club_bank">Bank</label>
                    <input id="club_bank" name="club_bank" value="<?= e($value('club_bank')) ?>">
                </div>
            </div>
        </fieldset>

        <fieldset class="card">
            <legend>Startseite und Beiträge</legend>

            <div class="field">
                <label for="home_title">Überschrift der Startseite</label>
                <input id="home_title" name="home_title" value="<?= e($value('home_title')) ?>">
            </div>

            <div class="field">
                <label for="home_text">Einleitungstext</label>
                <textarea id="home_text" name="home_text" rows="8" class="js-richtext" data-height="260"><?= e($value('home_text')) ?></textarea>
            </div>

            <div class="field field--xs">
                <label for="fee_year">Aktuelles Beitragsjahr</label>
                <input id="fee_year" name="fee_year" type="number" min="1900" max="2200" value="<?= (int) $feeYear ?>">
                <p class="field__hint">Vorbelegtes Jahr in den Auswertungen.</p>
            </div>

            <div class="field">
                <label for="fee_options">Wählbare Sektionsbeiträge</label>
                <input id="fee_options" name="fee_options"
                       value="<?= e($value('fee_options', '0;25;35;45;60')) ?>">
                <p class="field__hint">
                    Beträge in Euro, mit Strichpunkt getrennt – Auswahlliste für den
                    (optionalen) Beitrag je Sektionsmitgliedschaft. Die laufenden
                    Mitgliedsbeiträge werden über die
                    <a href="<?= e(url('/admin/beitragsarten')) ?>">Beitragsarten</a> gesteuert.
                </p>
            </div>

            <div class="field">
                <label for="reminder_email">Empfänger der Beitragserinnerung</label>
                <input id="reminder_email" name="reminder_email" type="email"
                       value="<?= e($value('reminder_email')) ?>">
                <p class="field__hint">
                    An diese Adresse geht die E-Mail „offene Mitgliedsbeiträge“ –
                    per Knopf auf der Beitragsseite oder automatisch per Cronjob
                    (bin/beitrags-erinnerung.php).
                </p>
            </div>
        </fieldset>

        <fieldset class="card">
            <legend>KI-Formularerkennung (Claude API)</legend>

            <div class="field">
                <label for="anthropic_api_key">
                    Anthropic API-Schlüssel
                    <?php if ($value('anthropic_api_key') !== ''): ?>
                        <span class="badge badge--ok">hinterlegt (…<?= e(substr($value('anthropic_api_key'), -4)) ?>)</span>
                    <?php else: ?>
                        <span class="badge badge--muted">nicht hinterlegt</span>
                    <?php endif; ?>
                </label>
                <input id="anthropic_api_key" name="anthropic_api_key" type="password"
                       autocomplete="off" placeholder="sk-ant-…">
                <p class="field__hint">
                    Wird für das automatische Auslesen von Mitgliedsformularen
                    (Foto/PDF) bei der Neuanlage verwendet. Schlüssel unter
                    <a href="https://platform.claude.com/" target="_blank" rel="noopener">platform.claude.com</a>
                    erstellen. Leer lassen = unverändert.
                </p>
            </div>

            <?php if ($value('anthropic_api_key') !== ''): ?>
                <label class="check">
                    <input type="checkbox" name="anthropic_api_key_clear" value="1">
                    Gespeicherten Schlüssel löschen
                </label>
            <?php endif; ?>
        </fieldset>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Einstellungen speichern</button>
    </div>
</form>

<div class="card">
    <div class="card__head">
        <h2>Gemeinden</h2>
        <a class="btn btn--sm" href="<?= e(url('/admin/gemeinden')) ?>">Gemeinden verwalten</a>
    </div>

    <p class="muted">
        Die amtliche Liste der STATISTIK AUSTRIA umfasst <?= (int) $gemeindenTotal ?> Gemeinden;
        davon sind derzeit <strong><?= (int) $gemeindenAktiv ?></strong> im Mitgliederformular
        zur Auswahl freigeschaltet.
    </p>
</div>
