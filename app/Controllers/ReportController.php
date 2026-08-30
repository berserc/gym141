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
    /**
     * Abrechnung nach Gemeinde (aus ATUS Weiz uebernommen, angepasst an das
     * Perioden-Beitragsmodell): Mitglieder, Jugend, Soll- und bezahlte
     * Beitraege je Gemeinde - Grundlage fuer Gemeindefoerderungen.
     */
    public function gemeinden(): void
    {
        AuthController::requireLogin();

        $year             = max(1900, min(2200, (int) (query('year') ?: (string) Setting::feeYear())));
        $sectionId        = (int) query('section_id');
        [$scope, $params] = $this->scope();

        $conditions = ['m.deleted_at IS NULL', 'm.archived_at IS NULL', $scope];
        $args       = $params;

        if ($sectionId > 0 && Auth::canAccessSection($sectionId)) {
            $conditions[] = 'EXISTS (SELECT 1 FROM member_sections ms
                                      WHERE ms.member_id = m.id AND ms.section_id = ?)';
            $args[]       = $sectionId;
        }

        if (query('status') !== '') {
            $conditions[] = 'm.status = ?';
            $args[]       = query('status');
        }

        $where = implode(' AND ', $conditions);

        $rows = Database::all(
            "SELECT CASE WHEN m.gemeinde = '' THEN '(ohne Gemeinde)' ELSE m.gemeinde END AS gemeinde,
                    COUNT(*)                                                            AS mitglieder,
                    SUM(CASE WHEN m.status = 'aktiv' THEN 1 ELSE 0 END)                  AS aktiv,
                    SUM(CASE WHEN m.birthdate IS NOT NULL
                              AND m.birthdate > date('now', '-18 years') THEN 1 ELSE 0 END) AS jugend,
                    SUM((SELECT COALESCE(SUM(ms.fee_amount), 0)
                           FROM member_sections ms WHERE ms.member_id = m.id)) AS beitrag_soll,
                    COALESCE(SUM(f.amount_paid), 0)                                      AS beitrag_bezahlt,
                    COALESCE(SUM(f.n_paid), 0)                                           AS anzahl_bezahlt
               FROM members m
               LEFT JOIN (
                    SELECT member_id,
                           SUM(CASE WHEN paid = 1 THEN COALESCE(paid_amount, amount) ELSE 0 END) AS amount_paid,
                           SUM(CASE WHEN paid = 1 THEN 1 ELSE 0 END)                             AS n_paid
                      FROM fee_entries
                     WHERE period LIKE ? || '-%'
                     GROUP BY member_id
               ) f ON f.member_id = m.id
              WHERE $where
              GROUP BY gemeinde
              ORDER BY mitglieder DESC, gemeinde COLLATE NOCASE",
            array_merge([(string) $year], $args)
        );

        View::display('admin/reports/gemeinden', [
            'title'    => 'Abrechnung nach Gemeinde',
            'rows'     => $rows,
            'year'     => $year,
            'sections' => SectionRepo::forUser(Auth::allowedSectionIds()),
            'filters'  => ['section_id' => $sectionId, 'status' => query('status')],
        ], 'layouts/admin');
    }

    public function gemeindenCsv(): void
    {
        AuthController::requireLogin();

        $year             = max(1900, min(2200, (int) (query('year') ?: (string) Setting::feeYear())));
        [$scope, $params] = $this->scope();

        $rows = Database::all(
            "SELECT CASE WHEN m.gemeinde = '' THEN '(ohne Gemeinde)' ELSE m.gemeinde END AS gemeinde,
                    s.name AS sektion,
                    COUNT(*) AS mitglieder,
                    SUM(CASE WHEN ms.status = 'aktiv' THEN 1 ELSE 0 END) AS aktiv,
                    SUM(ms.fee_amount) AS beitrag_soll
               FROM members m
               JOIN member_sections ms ON ms.member_id = m.id
               JOIN sections s ON s.id = ms.section_id
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND $scope
              GROUP BY gemeinde, s.name
              ORDER BY gemeinde COLLATE NOCASE, s.name COLLATE NOCASE",
            $params
        );

        \App\Core\Audit::log('report_export', 'report', null, 'Gemeinden ' . $year);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="gemeinden-' . $year . '.csv"');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Gemeinde', 'Sektion', 'Mitglieder', 'davon aktiv', 'Beitrag Soll'], ';');

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['gemeinde'],
                $row['sektion'],
                $row['mitglieder'],
                $row['aktiv'],
                number_format((float) $row['beitrag_soll'], 2, ',', ''),
            ], ';');
        }

        fclose($out);
        exit;
    }

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
