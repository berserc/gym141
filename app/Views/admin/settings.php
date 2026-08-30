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
                <label for="task141_url">Task141-Kopplung: Dienst-Adresse</label>
                <input id="task141_url" name="task141_url" type="text"
                       placeholder="https://task141.gym141.com"
                       value="<?= e($value('task141_url')) ?>">
                <p class="field__hint">
                    Mit einer Task141-Kopplung lassen sich Aufgaben aus der
                    Event-Organisation für Externe freigeben (Checkliste,
                    Datei-Uploads – ohne Vereins-Zugang).
                </p>
            </div>

            <div class="field">
                <label for="task141_service_key">Task141-Kopplung: Service-Schlüssel</label>
                <input id="task141_service_key" name="task141_service_key" type="text"
                       placeholder="sk_…"
                       value="<?= e($value('task141_service_key')) ?>">
                <p class="field__hint">
                    Wird im Task141-Konto unter „Kopplung“ erzeugt.
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
            <legend>Gym141 Pro (DevWorld-Lizenz)</legend>

            <?php
            $lizenzState = \App\Core\License::state();
            $mitglieder  = \App\Core\License::activeMemberCount();
            $limit       = \App\Core\License::memberLimit();
            ?>

            <p>
                <?php if (\App\Core\License::isPro()): ?>
                    <span class="badge badge--ok">Pro aktiv</span>
                    unbegrenzte Mitglieder
                    <?php if (($lizenzState['expires_at'] ?? null) !== null): ?>
                        · verlängert bis <?= e(format_date(substr((string) $lizenzState['expires_at'], 0, 10))) ?>
                    <?php endif; ?>
                    <?php $module = array_diff((array) ($lizenzState['features'] ?? []), [\App\Core\License::PRODUCT_CODE]); ?>
                    <?php if ($module !== []): ?>
                        · Module: <?= e(implode(', ', $module)) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge <?= $mitglieder >= $limit ? 'badge--danger' : 'badge--info' ?>">
                        Gratis-Version
                    </span>
                    <?= (int) $mitglieder ?> von <?= (int) $limit ?> aktiven Mitgliedern belegt.
                    <?php if (($lizenzState['reason'] ?? '') !== '' && \App\Core\License::key() !== ''): ?>
                        <br><small class="field__error">Letzte Prüfung: <?= e((string) $lizenzState['reason']) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </p>

            <div class="field">
                <label for="devworld_license_key">Lizenzschlüssel</label>
                <input id="devworld_license_key" name="devworld_license_key"
                       value="<?= e($value('devworld_license_key')) ?>" placeholder="DW-XXXX-XXXX-XXXX-XXXX">
                <p class="field__hint">
                    Gym141 ist Open Source und bis <?= \App\Core\License::FREE_MEMBER_LIMIT ?> aktive
                    Mitglieder kostenlos. Für unbegrenzte Mitglieder und Zusatzmodule gibt es
                    Gym141 Pro auf
                    <a href="https://account.devworld-llc.com" target="_blank" rel="noopener">account.devworld-llc.com</a> –
                    den Schlüssel aus „Meine Lizenzen“ hier eintragen.
                </p>
            </div>

            <?php if ($value('devworld_license_key') !== ''): ?>
                <button class="btn btn--sm" type="submit" form="lizenz-pruefen">Lizenz jetzt prüfen</button>
            <?php endif; ?>
        </fieldset>

        <fieldset class="card">
            <legend>Betriebsmodus</legend>

            <?php
            $publicSiteAn = $value('public_site') !== '0';
            $memberAreaAn = $value('member_area') !== '0';
            $schema       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $basisUrl     = $schema . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'deine-adresse.at')
                          . rtrim((string) \App\Core\Config::get('base_path', ''), '/');
            ?>

            <label class="check">
                <input type="checkbox" name="public_site" value="1" <?= $publicSiteAn ? 'checked' : '' ?>>
                Öffentliche Website aktiv (Startseite, Trainingsgruppen, Wochenplan)
            </label>
            <p class="field__hint">
                Abgehakt = „Nur Verwaltung“: Gym141 läuft neben eurer bestehenden
                Website (z. B. unter verwaltung.euer-verein.at). Die Startseite
                führt dann direkt zur Anmeldung, Suchmaschinen werden ausgesperrt.
                Impressum und Datenschutz bleiben erreichbar.
            </p>

            <label class="check">
                <input type="checkbox" name="member_area" value="1" <?= $memberAreaAn ? 'checked' : '' ?>>
                Mitgliederbereich aktiv (/mitglied – eigene Daten, Beitragsstatus, Termine)
            </label>

            <div class="field" style="margin-top: 0.9rem">
                <label for="embed_snippet">Wochenplan in eine bestehende Website einbetten</label>
                <input id="embed_snippet" type="text" readonly onclick="this.select()"
                       value='<?= e('<script src="' . $basisUrl . '/embed.js" defer></script>') ?>'>
                <p class="field__hint">
                    Diese eine Zeile dort einfügen, wo der Wochenplan erscheinen
                    soll (WordPress, Typo3, statisches HTML …) – Änderungen am
                    Wochenplan erscheinen dort automatisch. Für Entwickler gibt es
                    die Daten auch als JSON:
                    <a href="<?= e(url('/api/wochenplan')) ?>" target="_blank" rel="noopener">/api/wochenplan</a> und
                    <a href="<?= e(url('/api/sektionen')) ?>" target="_blank" rel="noopener">/api/sektionen</a>.
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

<form id="lizenz-pruefen" method="post" action="<?= e(url('/admin/einstellungen/lizenz-pruefen')) ?>">
    <?= csrf_field() ?>
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
