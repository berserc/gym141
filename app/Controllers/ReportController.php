<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Models\SectionRepo;
use App\Models\Setting;

/** Auswertungen: Mitglieder-Statistik (Alter, Geschlecht, Sektionen). */
final class ReportController
{
    public function statistik(): void
    {
        AuthController::requireLogin();

        [$scope, $params] = $this->scope();

        $ageBands = Database::all(
            // Die Grenze bei 18 ist bewusst gesetzt: bis dahin gilt der Jugendtarif.
            "SELECT CASE
                        WHEN m.birthdate IS NULL OR m.birthdate = ''       THEN 'unbekannt'
                        WHEN m.birthdate > date('now', '-6 years')         THEN 'bis 5'
                        WHEN m.birthdate > date('now', '-10 years')        THEN '6–9'
                        WHEN m.birthdate > date('now', '-14 years')        THEN '10–13'
                        WHEN m.birthdate > date('now', '-18 years')        THEN '14–17'
                        WHEN m.birthdate > date('now', '-27 years')        THEN '18–26'
                        WHEN m.birthdate > date('now', '-41 years')        THEN '27–40'
                        WHEN m.birthdate > date('now', '-61 years')        THEN '41–60'
                        ELSE '61+'
                    END AS band,
                    COUNT(*) AS n
               FROM members m
              WHERE m.deleted_at IS NULL AND m.status = 'aktiv' AND $scope
              GROUP BY band",
            $params
        );

        $byGender = Database::all(
            "SELECT m.gender, COUNT(*) AS n
               FROM members m
              WHERE m.deleted_at IS NULL AND m.status = 'aktiv' AND $scope
              GROUP BY m.gender",
            $params
        );

        $allowed      = Auth::allowedSectionIds();
        $sectionScope = '1 = 1';
        $sectionArgs  = [];

        if ($allowed !== null) {
            if ($allowed === []) {
                $sectionScope = '1 = 0';
            } else {
                $sectionScope = 's.id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
                $sectionArgs  = array_values($allowed);
            }
        }

        $bySection = Database::all(
            "SELECT s.name,
                    COUNT(ms.id) AS n,
                    COALESCE(SUM(ms.fee_amount), 0) AS soll
               FROM sections s
               LEFT JOIN member_sections ms ON ms.section_id = s.id AND ms.status = 'aktiv'
               LEFT JOIN members m
                      ON m.id = ms.member_id AND m.deleted_at IS NULL AND m.status = 'aktiv'
              WHERE $sectionScope
              GROUP BY s.id, s.name
              ORDER BY n DESC, s.name COLLATE NOCASE",
            $sectionArgs
        );

        $feeYear = Setting::feeYear();

        unset($sectionArgs);

        $feeSummary = Database::all(
            "SELECT CAST(strftime('%Y', f.due_date) AS INTEGER)    AS year,
                    COUNT(*)                                       AS zeilen,
                    SUM(CASE WHEN f.paid = 1 THEN 1 ELSE 0 END)    AS bezahlt,
                    SUM(CASE WHEN f.paid = 1 THEN COALESCE(f.paid_amount, f.amount) ELSE 0 END) AS summe_bezahlt,
                    SUM(f.amount)                                  AS summe_soll
               FROM fee_entries f
               JOIN members m ON m.id = f.member_id
              WHERE m.deleted_at IS NULL AND $scope
              GROUP BY year
              ORDER BY year DESC
              LIMIT 10",
            $params
        );

        // Kennzahlen wie auf der Uebersichtsseite
        $stats = [
            'aktiv'      => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'aktiv' AND $scope",
                $params
            ),
            'inaktiv'    => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'inaktiv' AND $scope",
                $params
            ),
            'trainer'    => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.is_trainer = 1 AND $scope",
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
            'ehemalig'   => (int) Database::value(
                "SELECT COUNT(*) FROM members m WHERE m.deleted_at IS NULL AND m.archived_at IS NOT NULL AND $scope",
                $params
            ),
        ];

        View::display('admin/reports/statistik', [
            'title'      => 'Statistik',
            'stats'      => $stats,
            'feeStats'   => \App\Models\FeeRepo::openStats($allowed),
            'ageBands'   => $ageBands,
            'byGender'   => $byGender,
            'bySection'  => $bySection,
            'feeSummary' => $feeSummary,
            'feeYear'    => $feeYear,
        ], 'layouts/admin');
    }

    /** @return array{0:string,1:list<int>} */
    private function scope(): array
    {
        $allowed = Auth::allowedSectionIds();

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
