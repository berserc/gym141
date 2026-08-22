<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Models\FeeRepo;
use App\Models\MemberRepo;
use App\Models\SectionRepo;

final class DashboardController
{
    public function index(): void
    {
        AuthController::requireLogin();

        $allowed = Auth::allowedSectionIds();

        // Faellige Beitragsperioden nachziehen, damit die Kennzahlen stimmen.
        FeeRepo::generateDue();

        [$scope, $params] = $this->scope($allowed);

        $stats = [
            'aktiv'    => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'aktiv' AND $scope",
                $params
            ),
            'inaktiv'  => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'inaktiv' AND $scope",
                $params
            ),
            'vorgemerkt' => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.delete_requested = 1 AND $scope",
                $params
            ),
            'ausgesetzt' => (int) Database::value(
                "SELECT COUNT(*) FROM members m
                  WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'aktiv'
                    AND EXISTS (SELECT 1 FROM member_pauses mp
                                 WHERE mp.member_id = m.id
                                   AND mp.pause_from <= date('now')
                                   AND (mp.pause_to IS NULL OR mp.pause_to = '' OR mp.pause_to >= date('now')))
                    AND $scope",
                $params
            ),
            'papierkorb' => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NOT NULL AND $scope",
                $params
            ),
        ];

        $feeStats = FeeRepo::openStats($allowed);

        $sectionScope = '1 = 1';
        $sectionArgs  = [];

        if ($allowed !== null) {
            $sectionScope = $allowed === []
                ? '1 = 0'
                : 's.id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $sectionArgs  = $allowed === [] ? [] : array_values($allowed);
        }

        $bySection = Database::all(
            "SELECT s.id, s.name,
                    SUM(CASE WHEN ms.status = 'aktiv'   THEN 1 ELSE 0 END) AS aktiv,
                    SUM(CASE WHEN ms.status = 'inaktiv' THEN 1 ELSE 0 END) AS inaktiv,
                    SUM(CASE WHEN m.delete_requested = 1 THEN 1 ELSE 0 END) AS vorgemerkt
               FROM sections s
               LEFT JOIN member_sections ms ON ms.section_id = s.id
               LEFT JOIN members m ON m.id = ms.member_id AND m.deleted_at IS NULL
              WHERE $sectionScope
              GROUP BY s.id, s.name
              ORDER BY s.sort_order, s.name COLLATE NOCASE",
            $sectionArgs
        );

        // Offene Erinnerungen: ueberfaellige und in den naechsten 60 Tagen faellige.
        $reminders = Database::all(
            "SELECT r.*, m.first_name, m.last_name
               FROM member_reminders r
               JOIN members m ON m.id = r.member_id
              WHERE r.done = 0 AND m.deleted_at IS NULL AND m.archived_at IS NULL
                AND r.due_on <= date('now', '+60 days')
                AND $scope
              ORDER BY r.due_on
              LIMIT 30",
            $params
        );

        // Geburtstage: 7 Tage vorher bis 7 Tage nachher.
        $birthdays = [];
        $heute     = new \DateTimeImmutable('today');

        foreach (Database::all(
            "SELECT m.id, m.first_name, m.last_name, m.birthdate
               FROM members m
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'aktiv'
                AND m.birthdate IS NOT NULL AND m.birthdate <> ''
                AND $scope",
            $params
        ) as $m) {
            try {
                $geboren = new \DateTimeImmutable((string) $m['birthdate']);
            } catch (\Exception) {
                continue;
            }

            // Jahrestag im Vorjahr/heuer/naechsten Jahr pruefen (Jahreswechsel!).
            foreach ([-1, 0, 1] as $dy) {
                $jahr = (int) $heute->format('Y') + $dy;
                $anni = $geboren->setDate($jahr, (int) $geboren->format('n'), (int) $geboren->format('j'));
                $tage = (int) $heute->diff($anni)->format('%r%a');

                if ($tage >= -7 && $tage <= 7) {
                    $birthdays[] = [
                        'id'    => (int) $m['id'],
                        'name'  => $m['last_name'] . ', ' . $m['first_name'],
                        'tage'  => $tage,
                        'alter' => $jahr - (int) $geboren->format('Y'),
                        'datum' => $anni->format('Y-m-d'),
                    ];
                    break;
                }
            }
        }

        usort($birthdays, static fn (array $a, array $b): int => $a['tage'] <=> $b['tage']);

        View::display('admin/dashboard', [
            'title'        => 'Übersicht',
            'reminders'    => $reminders,
            'birthdays'    => $birthdays,
            'stats'        => $stats,
            'feeStats'     => $feeStats,
            'bySection'    => $bySection,
            'pendingTotal' => Auth::isSuperuser() ? MemberRepo::pendingDeletions() : $stats['vorgemerkt'],
            'sections'     => SectionRepo::forUser($allowed),
            'recent'       => Auth::isSuperuser()
                ? Database::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 12')
                : [],
        ], 'layouts/admin');
    }

    /**
     * Liefert eine WHERE-Teilbedingung, die auf die erlaubten Sektionen einschraenkt.
     *
     * @param list<int>|null $allowed
     * @return array{0:string,1:list<int>}
     */
    private function scope(?array $allowed): array
    {
        if ($allowed === null) {
            return ['1 = 1', []];
        }

        if ($allowed === []) {
            return ['1 = 0', []];
        }

        $in = implode(',', array_fill(0, count($allowed), '?'));

        return [
            "EXISTS (SELECT 1 FROM member_sections ms WHERE ms.member_id = m.id AND ms.section_id IN ($in))",
            array_values($allowed),
        ];
    }
}
