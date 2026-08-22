<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\FeeRepo;
use App\Models\SectionRepo;
use App\Models\Setting;

/**
 * Beitragsverwaltung: offene Beitraege (Erinnerungsliste), Zahlungen
 * erfassen und Beitragsarten pflegen.
 */
final class FeeController
{
    // -------------------------------------------------------- Offene Beitraege --

    public function index(): void
    {
        AuthController::requireLogin();

        // Fehlende Perioden bei jedem Aufruf ergaenzen – so ist die Liste
        // immer aktuell, ohne dass ein Cronjob noetig waere.
        FeeRepo::generateDue();

        $filters = [
            'q'          => query('q'),
            'plan_id'    => query('plan_id'),
            'section_id' => query('section_id'),
            'only_due'   => query('alle') === '1' ? '' : '1',
        ];

        $allowed = Auth::allowedSectionIds();

        View::display('admin/fees/index', [
            'title'    => 'Beiträge',
            'filters'  => $filters,
            'entries'  => FeeRepo::openEntries($filters, $allowed),
            'stats'    => FeeRepo::openStats($allowed),
            'plans'    => FeeRepo::plans(),
            'methods'  => \App\Models\LedgerRepo::paymentMethods(true),
            'sections' => SectionRepo::forUser($allowed),
        ], 'layouts/admin');
    }

    /** Einzelne Zeile oder Auswahl als bezahlt markieren. */
    public function markPaid(): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $returnTo = post('return_to') ?: url('/admin/beitraege');

        // Einzelzeile: entry_id + optional Betrag/Datum/Notiz/Zahlungsart
        $entryId  = post_int('entry_id');
        $methodId = post_int('payment_method_id');
        $methodId = $methodId > 0 ? $methodId : null;

        if ($entryId > 0) {
            $entry = $this->findAccessibleEntry($entryId);

            $amount = post('paid_amount') === '' ? null : post_float('paid_amount');
            $paidOn = post('paid_on') === '' ? null : parse_date(post('paid_on'));

            FeeRepo::markPaid($entryId, $amount, $paidOn, Auth::id(), post('note'), $methodId);

            Audit::log('fee_paid', 'member', (int) $entry['member_id'], $entry['period_label'] .
                ' (' . format_money($amount ?? (float) $entry['amount']) . ')');
            Flash::success('Beitrag ' . $entry['period_label'] . ' als bezahlt markiert.');
            Url::redirectRaw($returnTo);
        }

        // Sammelaktion: ids[] abhaken
        /** @var list<int> $ids */
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

        if ($ids === []) {
            Flash::error('Es wurde keine Beitragszeile ausgewählt.');
            Url::redirectRaw($returnTo);
        }

        $paidOn = parse_date(post('paid_on')) ?? date('Y-m-d');
        $done   = 0;

        Database::transaction(function () use ($ids, $paidOn, $methodId, &$done): void {
            foreach ($ids as $id) {
                $entry = $this->findEntry($id);

                if ($entry === null || !$this->canAccessMember((int) $entry['member_id'])) {
                    continue;
                }

                FeeRepo::markPaid($id, null, $paidOn, Auth::id(), '', $methodId);
                $done++;
            }
        });

        Audit::log('fee_paid_bulk', 'member', null, $done . ' Beitragszeile(n)');
        Flash::success($done . ' Beitrag/Beiträge als bezahlt markiert.');
        Url::redirectRaw($returnTo);
    }

    /** Zahlung wieder auf offen setzen. */
    public function markOpen(): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $entryId = post_int('entry_id');
        $entry   = $this->findAccessibleEntry($entryId);

        FeeRepo::markOpen($entryId);

        Audit::log('fee_reopened', 'member', (int) $entry['member_id'], $entry['period_label']);
        Flash::success('Beitrag ' . $entry['period_label'] . ' wieder auf offen gesetzt.');
        Url::redirectRaw(post('return_to') ?: url('/admin/beitraege'));
    }

    /** Erinnerungsmail mit allen offenen Beitraegen sofort versenden. */
    public function sendReminder(): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        FeeRepo::generateDue();

        $to = trim(Setting::get('reminder_email'));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Flash::error('Keine gültige Empfängeradresse. Bitte in den Einstellungen hinterlegen.');
            Url::redirect('/admin/beitraege');
        }

        $entries = FeeRepo::openEntries(['only_due' => 1], null);

        if ($entries === []) {
            Flash::info('Derzeit sind keine Beiträge offen – es wurde nichts versendet.');
            Url::redirect('/admin/beitraege');
        }

        $sent = self::mailReminder($to, $entries);

        if ($sent) {
            Audit::log('fee_reminder_sent', 'report', null, count($entries) . ' offene Beiträge an ' . $to);
            Flash::success('Erinnerung mit ' . count($entries) . ' offenen Beiträgen an ' . $to . ' gesendet.');
        } else {
            Flash::error('Die E-Mail konnte nicht versendet werden (mail() fehlgeschlagen).');
        }

        Url::redirect('/admin/beitraege');
    }

    /**
     * Baut und versendet die Erinnerungsmail.
     * Wird auch von bin/beitrags-erinnerung.php (Cronjob) verwendet.
     *
     * @param list<array<string,mixed>> $entries
     */
    public static function mailReminder(string $to, array $entries): bool
    {
        $sum   = array_sum(array_map(static fn (array $e): float => (float) $e['amount'], $entries));
        $lines = [];

        foreach ($entries as $e) {
            $lines[] = sprintf(
                '%-30s %-20s faellig %s  %8s',
                mb_strimwidth((string) $e['last_name'] . ', ' . $e['first_name'], 0, 30),
                (string) $e['period_label'],
                format_date((string) $e['due_date']),
                format_money((float) $e['amount'])
            );
        }

        $body = "Offene Mitgliedsbeiträge – Stand " . date('d.m.Y') . "\n"
            . str_repeat('=', 70) . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . str_repeat('-', 70) . "\n"
            . sprintf("%d offene Beiträge, gesamt %s\n\n", count($entries), format_money($sum))
            . 'Zahlungen erfassen: https://' . \App\Core\Config::get('canonical_host', '') . "/admin/beitraege\n";

        $from = (string) \App\Core\Config::get('mail_from', '');

        $headers = 'From: ' . Setting::get('club_name', 'Gym141') . ' Verwaltung <' . $from . ">\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";

        $subject = 'Offene Mitgliedsbeiträge: ' . count($entries)
            . ' (' . format_money($sum) . ')';

        return @mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $body,
            $headers
        );
    }

    // ----------------------------------------------------------- Beitragsarten --

    public function plans(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        $plans     = FeeRepo::plans();
        $histories = [];

        foreach ($plans as $plan) {
            $histories[(int) $plan['id']] = FeeRepo::amountHistory('fee_plan', (int) $plan['id']);
        }

        View::display('admin/fees/plans', [
            'title'     => 'Beitragsarten',
            'plans'     => $plans,
            'intervals' => FeeRepo::INTERVALS,
            'amountHistories' => $histories,
            'errors'    => Flash::errors(),
        ], 'layouts/admin');
    }

    public function storePlan(): void
    {
        $this->savePlan(0);
    }

    /** @param array<string,string> $args */
    public function updatePlan(array $args): void
    {
        $this->savePlan((int) ($args['id'] ?? 0));
    }

    private function savePlan(int $id): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $name     = post('name');
        $amount   = post_float('amount');
        $interval = post('interval');
        $dueDay   = post_int('due_day', 1);

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Bitte eine Bezeichnung angeben.';
        }

        if ($amount < 0) {
            $errors['amount'] = 'Der Betrag darf nicht negativ sein.';
        }

        if (!isset(FeeRepo::INTERVALS[$interval])) {
            $errors['interval'] = 'Bitte eine gültige Periode wählen.';
        }

        if ($dueDay < 1 || $dueDay > 28) {
            $errors['due_day'] = 'Der Fälligkeitstag muss zwischen 1 und 28 liegen.';
        }

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/beitragsarten');
        }

        $data = [
            'name'       => $name,
            'amount'     => $amount,
            'interval'   => $interval,
            'due_day'    => $dueDay,
            'active'     => post_bool('active'),
            'note'       => post('note'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            if (FeeRepo::plan($id) === null) {
                Flash::error('Beitragsart nicht gefunden.');
                Url::redirect('/admin/beitragsarten');
            }

            Database::update('fee_plans', $id, $data);
            Audit::log('fee_plan_updated', 'fee_plan', $id, $name);
            Flash::success('Beitragsart gespeichert. Bereits angelegte Beitragszeilen bleiben unverändert.');
        } else {
            unset($data['updated_at']);
            $id = Database::insert('fee_plans', $data);
            Audit::log('fee_plan_created', 'fee_plan', $id, $name);
            Flash::success('Beitragsart angelegt.');
        }

        Url::redirect('/admin/beitragsarten');
    }

    /**
     * Betragsaenderung ab Stichtag fuer eine Beitragsart – gilt fuer alle
     * Mitglieder dieser Beitragsart (ausser sie haben eigene Abweichungen).
     *
     * @param array<string,string> $args
     */
    public function changePlanAmount(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $plan = FeeRepo::plan($id);

        if ($plan === null) {
            Flash::error('Beitragsart nicht gefunden.');
            Url::redirect('/admin/beitragsarten');
        }

        $errors = FeeRepo::addAmountChange('fee_plan', $id, post('valid_from'), post_float('amount'), post('note'), Auth::id());

        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            Url::redirect('/admin/beitragsarten');
        }

        Audit::log('fee_plan_amount_change', 'fee_plan', $id, format_money(post_float('amount')) . ' ab ' . post('valid_from'));
        Flash::success(sprintf(
            'Beitragsart "%s": %s gilt ab %s. Bereits erzeugte Beitragszeilen bleiben unverändert.',
            (string) $plan['name'],
            format_money(post_float('amount')),
            format_date(parse_date(post('valid_from')))
        ));
        Url::redirect('/admin/beitragsarten');
    }

    /** @param array<string,string> $args */
    public function deletePlanAmountChange(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        Database::run(
            "DELETE FROM amount_history WHERE id = ? AND entity = 'fee_plan' AND entity_id = ?",
            [post_int('history_id'), (int) ($args['id'] ?? 0)]
        );

        Flash::success('Betragsänderung entfernt.');
        Url::redirect('/admin/beitragsarten');
    }

    /** @param array<string,string> $args */
    public function deletePlan(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $plan = FeeRepo::plan($id);

        if ($plan === null) {
            Flash::error('Beitragsart nicht gefunden.');
            Url::redirect('/admin/beitragsarten');
        }

        $inUse = (int) Database::value(
            'SELECT COUNT(*) FROM members WHERE fee_plan_id = ? AND deleted_at IS NULL',
            [$id]
        );

        if ($inUse > 0) {
            Flash::error(sprintf(
                'Die Beitragsart "%s" ist noch %d Mitglied(ern) zugeordnet und kann nicht gelöscht werden. Tipp: inaktiv setzen.',
                (string) $plan['name'],
                $inUse
            ));
            Url::redirect('/admin/beitragsarten');
        }

        Database::run('UPDATE members SET fee_plan_id = NULL WHERE fee_plan_id = ?', [$id]);
        Database::run('DELETE FROM fee_plans WHERE id = ?', [$id]);

        Audit::log('fee_plan_deleted', 'fee_plan', $id, (string) $plan['name']);
        Flash::success('Beitragsart gelöscht. Die Beitragshistorie bleibt erhalten.');
        Url::redirect('/admin/beitragsarten');
    }

    // ------------------------------------------------------------------ Hilfen --

    /** @return array<string,mixed>|null */
    private function findEntry(int $id): ?array
    {
        return Database::one('SELECT * FROM fee_entries WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> Bricht ab, wenn kein Zugriff besteht. */
    private function findAccessibleEntry(int $id): array
    {
        $entry = $this->findEntry($id);

        if ($entry === null) {
            Flash::error('Beitragszeile nicht gefunden.');
            Url::redirect('/admin/beitraege');
        }

        if (!$this->canAccessMember((int) $entry['member_id'])) {
            http_response_code(403);
            View::display('errors/403', ['title' => 'Kein Zugriff'], 'layouts/admin');
            exit;
        }

        return $entry;
    }

    private function canAccessMember(int $memberId): bool
    {
        $allowed = Auth::allowedSectionIds();

        if ($allowed === null) {
            return true;
        }

        if ($allowed === []) {
            return false;
        }

        $in = implode(',', array_fill(0, count($allowed), '?'));

        return (int) Database::value(
            "SELECT COUNT(*) FROM member_sections ms
              WHERE ms.member_id = ? AND ms.section_id IN ($in)",
            array_merge([$memberId], array_values($allowed))
        ) > 0;
    }
}
