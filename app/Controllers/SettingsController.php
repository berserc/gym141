<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\Setting;

final class SettingsController
{
    /** Felder, die ueber das Einstellungsformular gepflegt werden. */
    private const FIELDS = [
        'club_name', 'club_street', 'club_zip', 'club_city', 'club_zvr',
        'club_email', 'club_phone', 'whatsapp_number', 'club_iban', 'club_bank',
        'club_tagline', 'home_title', 'home_text', 'fee_year', 'fee_options',
        'fee_capture_from',
        'reminder_email',
        // E-Mail-Versand (SMTP); smtp_pass wird gesondert behandelt (leer = unveraendert)
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_secure', 'smtp_from', 'smtp_from_name',
        // Task141-Kopplung (Aufgaben-Freigaben fuer Externe)
        'task141_url', 'task141_service_key',
    ];

    public function index(): void
    {
        AuthController::requireRole('superuser');

        // Offene Beitraege VOR dem Erfassungs-Startdatum (Bereinigungs-Angebot).
        $erfassenAb  = Setting::get('fee_capture_from');
        $alteOffene  = ['count' => 0, 'sum' => 0.0];

        if ($erfassenAb !== '') {
            $alteOffene = Database::one(
                'SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS sum
                   FROM fee_entries WHERE paid = 0 AND due_date < ?',
                [$erfassenAb]
            ) ?? $alteOffene;
        }

        View::display('admin/settings', [
            'title'          => 'Einstellungen',
            'settings'       => Setting::all(),
            'feeYear'        => Setting::feeYear(),
            'gemeindenAktiv' => (int) Database::value('SELECT COUNT(*) FROM gemeinden WHERE active = 1'),
            'gemeindenTotal' => (int) Database::value('SELECT COUNT(*) FROM gemeinden'),
            'alteOffene'     => ['count' => (int) $alteOffene['count'], 'sum' => (float) $alteOffene['sum']],
        ], 'layouts/admin');
    }

    /**
     * Offene Beitraege vor dem Erfassungs-Startdatum endgueltig loeschen
     * (nach Bestaetigungsdialog). Bezahlte Beitraege bleiben IMMER erhalten.
     */
    public function purgeOldFees(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $erfassenAb = Setting::get('fee_capture_from');

        if ($erfassenAb === '') {
            Flash::error('Bitte zuerst „Beiträge erfassen ab“ setzen und speichern.');
            Url::redirect('/admin/einstellungen');
        }

        $anzahl = Database::run(
            'DELETE FROM fee_entries WHERE paid = 0 AND due_date < ?',
            [$erfassenAb]
        )->rowCount();

        Audit::log('fees_purged', 'settings', null, $anzahl . ' offene Beiträge vor ' . $erfassenAb . ' gelöscht');
        Flash::success($anzahl . ' offene Beiträge vor dem ' . format_date($erfassenAb) . ' gelöscht – bezahlte blieben unberührt.');
        Url::redirect('/admin/einstellungen');
    }

    public function save(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = $field === 'home_text'
                ? safe_html((string) ($_POST[$field] ?? ''))
                : post($field);
        }

        $year = (int) $values['fee_year'];
        $values['fee_year'] = $year >= 1900 && $year <= 2200 ? (string) $year : (string) date('Y');

        // "Beitraege erfassen ab": Datum validieren (leer = keine Grenze).
        if ($values['fee_capture_from'] !== '') {
            $ab = parse_date($values['fee_capture_from']);

            if ($ab === null) {
                Flash::error('„Beiträge erfassen ab“ ist kein gültiges Datum.');
                Url::redirect('/admin/einstellungen');
            }

            $values['fee_capture_from'] = $ab;
        }

        if ($values['reminder_email'] !== '' && !filter_var($values['reminder_email'], FILTER_VALIDATE_EMAIL)) {
            Flash::error('Die Empfängeradresse für die Beitragserinnerung ist keine gültige E-Mail-Adresse.');
            Url::redirect('/admin/einstellungen');
        }

        Setting::setMany($values);

        // Betriebsmodus: Checkboxen fehlen im POST, wenn sie abgehakt sind –
        // deshalb explizit als '1'/'0' speichern (nicht ueber FIELDS).
        Setting::set('public_site', post_bool('public_site') ? '1' : '0');
        Setting::set('member_area', post_bool('member_area') ? '1' : '0');

        // SMTP-Passwort: leer gelassen = unveraendert (Loeschen per Checkbox).
        $smtpPass = (string) ($_POST['smtp_pass'] ?? '');

        if (post_bool('smtp_pass_clear')) {
            Setting::set('smtp_pass', '');
        } elseif ($smtpPass !== '') {
            Setting::set('smtp_pass', $smtpPass);
        }

        // API-Schluessel: leer gelassen = unveraendert, damit er beim normalen
        // Speichern nicht verloren geht. Loeschen ueber die eigene Checkbox.
        $apiKey = trim(post('anthropic_api_key'));

        if (post_bool('anthropic_api_key_clear')) {
            Setting::set('anthropic_api_key', '');
        } elseif ($apiKey !== '') {
            Setting::set('anthropic_api_key', $apiKey);
        }

        // DevWorld-Lizenzschluessel: bei Aenderung sofort gegen den
        // Lizenzserver pruefen, damit der Status direkt sichtbar ist.
        $lizenz = trim(post('devworld_license_key'));

        if ($lizenz !== Setting::get('devworld_license_key')) {
            Setting::set('devworld_license_key', $lizenz);
            \App\Core\License::refresh();
        }

        Audit::log('settings_updated', 'settings');

        // "Speichern + Testmail senden": erst wurde alles gespeichert (oben),
        // dann testet der Versand mit genau diesen frischen Einstellungen.
        if (post('testmail') === '1') {
            $an = trim(post('testmail_an')) ?: trim($values['club_email'] ?? \App\Models\Setting::get('club_email'));

            if ($an === '') {
                Flash::error('Einstellungen gespeichert. Für die Testmail bitte eine Empfängeradresse angeben.');
                Url::redirect('/admin/einstellungen');
            }

            $fehler = \App\Core\Mailer::send(
                $an,
                'Testmail von ' . (\App\Models\Setting::get('club_name') ?: 'Gym141'),
                "Wenn du diese Nachricht liest, funktioniert der E-Mail-Versand.\n\n"
                . 'Versendet über ' . (\App\Core\Mailer::smtpConfigured() ? 'SMTP (' . \App\Models\Setting::get('smtp_host') . ')' : 'PHP mail()') . '.'
            );

            if ($fehler === '') {
                Flash::success('Einstellungen gespeichert und Testmail an ' . $an . ' versendet – bitte Posteingang prüfen.');
            } else {
                Flash::error('Einstellungen gespeichert, aber: ' . $fehler);
            }

            Url::redirect('/admin/einstellungen');
        }

        Flash::success('Einstellungen gespeichert.');
        Url::redirect('/admin/einstellungen');
    }

    public function auditLog(): void
    {
        AuthController::requireRole('superuser');

        $page  = max(1, (int) query('page', '1'));
        $total = (int) Database::value('SELECT COUNT(*) FROM audit_log');

        [$page, $offset, $pages] = paginate($total, 100, $page);

        View::display('admin/audit', [
            'title'   => 'Protokoll',
            'entries' => Database::all(
                'SELECT * FROM audit_log ORDER BY id DESC LIMIT 100 OFFSET ?',
                [$offset]
            ),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
        ], 'layouts/admin');
    }
}
