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
use App\Core\XlsxWriter;
use App\Models\Setting;

/**
 * Beitrags- und Foerderberechnung.
 *
 * Je Sektion:
 *   Beitragssumme = Summe der Mitgliedsbeitraege aller aktiven Mitglieder
 *   Foerderung    = Basisfoerderung
 *                 + Kinderbetrag je Kind bis zum vollendeten Kindesalter
 *                 + Anteil der Beitragssumme
 *
 * Die drei Rechengroessen (Kinderbetrag, Altersgrenze, Anteil) stehen in den
 * Einstellungen, damit sich geaenderte Foerderrichtlinien ohne Codeaenderung
 * abbilden lassen.
 */
final class FundingController
{
    public function index(): void
    {
        AuthController::requireRole('superuser');

        $jahr  = $this->jahrAusEingabe((int) query('jahr'));
        $daten = $this->berechne($jahr);

        $jahre = array_map(
            static fn (array $r): int => (int) $r['year'],
            Database::all('SELECT DISTINCT year FROM funding_years ORDER BY year DESC')
        );

        if (!in_array($jahr, $jahre, true)) {
            array_unshift($jahre, $jahr);
        }

        View::display('admin/reports/foerderung', $daten + [
            'title' => 'Beiträge und Förderung',
            'jahre' => $jahre,
        ], 'layouts/admin');
    }

    /** Basisfoerderung, Auszahlung und die Gesamtwerte des Jahres speichern. */
    public function save(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $jahr = $this->jahrAusEingabe(post_int('jahr'));

        /** @var array<int|string,mixed> $werte */
        $werte    = (array) ($_POST['base_funding'] ?? []);
        $auszahlung = (array) ($_POST['paid_out'] ?? []);
        $notizen  = (array) ($_POST['note'] ?? []);
        $anzahl   = 0;

        $betrag = static fn ($v): float => max(0.0, (float) str_replace(',', '.', trim((string) $v)));

        foreach ($werte as $sectionId => $wert) {
            $id = (int) $sectionId;

            if ($id <= 0) {
                continue;
            }

            $basis = $betrag($wert);

            // Basisfoerderung bleibt zusaetzlich an der Sektion, damit sie ins
            // Folgejahr uebernommen wird.
            Database::run(
                'UPDATE sections SET base_funding = ?, updated_at = ? WHERE id = ?',
                [$basis, gmdate('Y-m-d H:i:s'), $id]
            );

            Database::run(
                'INSERT INTO funding_years (year, section_id, base_funding, paid_out, note)
                 VALUES (:j, :s, :b, :a, :n)
                 ON CONFLICT(year, section_id) DO UPDATE SET
                    base_funding = excluded.base_funding,
                    paid_out     = excluded.paid_out,
                    note         = excluded.note,
                    updated_at   = datetime(\'now\')',
                [
                    'j' => $jahr,
                    's' => $id,
                    'b' => $basis,
                    'a' => $betrag($auszahlung[$sectionId] ?? 0),
                    'n' => trim((string) ($notizen[$sectionId] ?? '')),
                ]
            );

            $anzahl++;
        }

        Setting::setMany([
            'funding_total_base_' . $jahr => (string) max(0.0, post_float('funding_total_base')),
            'funding_expenses_' . $jahr   => (string) max(0.0, post_float('funding_expenses')),
            'funding_total_base'  => (string) max(0.0, post_float('funding_total_base')),
            'funding_child_bonus' => (string) max(0.0, post_float('funding_child_bonus')),
            'funding_child_age'   => (string) max(1, post_int('funding_child_age', 10)),
            'funding_fee_share'   => (string) max(0.0, post_float('funding_fee_share')),
            'funding_expenses'    => (string) max(0.0, post_float('funding_expenses')),
        ]);

        // Jahr abschliessen: Rechenwerte einfrieren, damit sie spaeter
        // nachvollziehbar bleiben.
        if (post('abschliessen') === '1') {
            $d = $this->berechne($jahr);

            foreach ($d['sektionen'] as $s) {
                Database::run(
                    'UPDATE funding_years
                        SET members_active = ?, children = ?, fees = ?,
                            child_bonus = ?, fee_share = ?, calculated = ?,
                            closed = 1, updated_at = datetime(\'now\')
                      WHERE year = ? AND section_id = ?',
                    [
                        (int) $s['aktiv'], (int) $s['kinder'], (float) $s['beitraege'],
                        (float) $s['kinderfoerderung'], (float) $s['beitragsanteil'],
                        (float) $s['foerderung'], $jahr, (int) $s['id'],
                    ]
                );
            }

            Audit::log('funding_closed', 'settings', null, 'Jahr ' . $jahr);
            Flash::success('Jahr ' . $jahr . ' abgeschlossen und eingefroren.');
            Url::redirect('/admin/auswertung/foerderung', ['jahr' => $jahr]);
        }

        Audit::log('funding_saved', 'settings', null, $anzahl . ' Sektionen, Jahr ' . $jahr);
        Flash::success('Förderwerte für ' . $jahr . ' gespeichert.');
        Url::redirect('/admin/auswertung/foerderung', ['jahr' => $jahr]);
    }

    private function jahrAusEingabe(int $jahr): int
    {
        return $jahr >= 1900 && $jahr <= 2200 ? $jahr : Setting::feeYear();
    }

    public function exportXlsx(): void
    {
        AuthController::requireRole('superuser');

        $d = $this->berechne($this->jahrAusEingabe((int) query('jahr')));

        $xlsx = new XlsxWriter();

        $kopf = [
            'Sektion', 'Mitglieder aktiv', 'Kinder bis ' . $d['kindAlter'],
            'Beitragssumme', 'Basisförderung',
            'Kinderförderung', (int) round($d['anteil'] * 100) . ' % der Beiträge',
            'Förderung gesamt', 'Auszahlung', 'Aufrundung',
        ];

        $zeilen = [$kopf];

        foreach ($d['sektionen'] as $s) {
            $zeilen[] = [
                (string) $s['name'],
                (int) $s['aktiv'],
                (int) $s['kinder'],
                (float) $s['beitraege'],
                (float) $s['basis'],
                (float) $s['kinderfoerderung'],
                (float) $s['beitragsanteil'],
                (float) $s['foerderung'],
                (float) $s['auszahlung'],
                (float) $s['differenz'],
            ];
        }

        $zeilen[] = [
            'Summe',
            (int) $d['summe']['aktiv'],
            (int) $d['summe']['kinder'],
            (float) $d['summe']['beitraege'],
            (float) $d['summe']['basis'],
            (float) $d['summe']['kinderfoerderung'],
            (float) $d['summe']['beitragsanteil'],
            (float) $d['summe']['foerderung'],
            (float) $d['summe']['auszahlung'],
            (float) $d['summe']['differenz'],
        ];

        $xlsx->addSheet('Förderung je Sektion', $zeilen, [24, 15, 15, 14, 14, 15, 18, 16, 22]);

        $xlsx->addSheet('Gesamtrechnung', [
            ['Posten', 'Betrag'],
            ['Basisförderung ATUS gesamt', (float) $d['gesamtBasis']],
            ['Basisförderung der Sektionen', (float) $d['summe']['basis']],
            ['Kinderförderung', (float) $d['summe']['kinderfoerderung']],
            [(int) round($d['anteil'] * 100) . ' % der Mitgliedsbeiträge', (float) $d['summe']['beitragsanteil']],
            ['Einnahmen gesamt', (float) $d['gesamt']['einnahmen']],
            ['Ausgaben (Förderung an Sektionen)', (float) $d['gesamt']['ausgaben']],
            ['Ergebnis', (float) $d['gesamt']['ergebnis']],
        ], [38, 16]);

        Audit::log('funding_export', 'report', null, 'Förderung ' . $d['jahr']);

        $xlsx->download('atus-foerderung-' . $d['jahr'] . '.xlsx');
    }

    // ----------------------------------------------------------- Berechnung --

    /** @return array<string,mixed> */
    private function berechne(?int $jahr = null): array
    {
        $jahr ??= Setting::feeYear();

        $kindAlter = max(1, (int) Setting::get('funding_child_age', '10'));
        $kindBetrag = (float) str_replace(',', '.', Setting::get('funding_child_bonus', '10'));
        $anteil     = (float) str_replace(',', '.', Setting::get('funding_fee_share', '0.75'));

        // Jahresbezogene Werte, mit Rueckfall auf die allgemeine Einstellung.
        $gesamtBasis = (float) str_replace(',', '.', Setting::get(
            'funding_total_base_' . $jahr,
            Setting::get('funding_total_base', '0')
        ));
        $ausgabenExtra = (float) str_replace(',', '.', Setting::get(
            'funding_expenses_' . $jahr,
            Setting::get('funding_expenses', '0')
        ));

        // Gespeicherte Werte des Jahres (Basisfoerderung, Auszahlung, Notiz)
        $gespeichert = [];
        foreach (Database::all('SELECT * FROM funding_years WHERE year = ?', [$jahr]) as $r) {
            $gespeichert[(int) $r['section_id']] = $r;
        }

        // "bis 10 Jahre" heisst: das 11. Lebensjahr noch nicht vollendet.
        $grenze = '-' . ($kindAlter + 1) . ' years';

        // Gerechnet wird je Mitgliedschaft: wer in zwei Sektionen ist, zaehlt
        // in beiden und zahlt in beiden den dort hinterlegten Beitrag.
        $rows = Database::all(
            "SELECT s.id, s.name, s.fee_free, s.base_funding,
                    COUNT(ms.id)                                             AS aktiv,
                    COALESCE(SUM(ms.fee_amount), 0)                          AS beitraege,
                    SUM(CASE WHEN m.birthdate IS NOT NULL AND m.birthdate <> ''
                              AND m.birthdate > date('now', ?) THEN 1 ELSE 0 END) AS kinder
               FROM sections s
               LEFT JOIN member_sections ms ON ms.section_id = s.id AND ms.status = 'aktiv'
               LEFT JOIN members m
                      ON m.id = ms.member_id
                     AND m.deleted_at IS NULL
                     AND m.status = 'aktiv'
              GROUP BY s.id, s.name, s.fee_free, s.base_funding
              ORDER BY s.sort_order, s.name COLLATE NOCASE",
            [$grenze]
        );

        $sektionen = [];
        $summe = [
            'aktiv' => 0, 'kinder' => 0, 'beitraege' => 0.0, 'basis' => 0.0,
            'kinderfoerderung' => 0.0, 'beitragsanteil' => 0.0, 'foerderung' => 0.0,
            'auszahlung' => 0.0, 'saldo' => 0.0,
        ];

        $abgeschlossen = false;

        foreach ($rows as $r) {
            $sid   = (int) $r['id'];
            $jahrD = $gespeichert[$sid] ?? null;

            // Ein abgeschlossenes Jahr zeigt die eingefrorenen Werte.
            $istZu = $jahrD !== null && (int) $jahrD['closed'] === 1;
            $abgeschlossen = $abgeschlossen || $istZu;

            $beitraege = $istZu
                ? (float) $jahrD['fees']
                : ((int) $r['fee_free'] === 1 ? 0.0 : (float) $r['beitraege']);

            $kinder = $istZu ? (int) $jahrD['children'] : (int) $r['kinder'];
            $aktiv  = $istZu ? (int) $jahrD['members_active'] : (int) $r['aktiv'];
            $basis  = $jahrD !== null ? (float) $jahrD['base_funding'] : (float) $r['base_funding'];

            $kinderfoerderung = $istZu ? (float) $jahrD['child_bonus'] : $kinder * $kindBetrag;
            $beitragsanteil   = $istZu ? (float) $jahrD['fee_share'] : $beitraege * $anteil;
            $foerderung       = $istZu ? (float) $jahrD['calculated'] : $basis + $kinderfoerderung + $beitragsanteil;
            $auszahlung       = $jahrD !== null ? (float) $jahrD['paid_out'] : 0.0;

            $eintrag = [
                'id'               => $sid,
                'name'             => (string) $r['name'],
                'fee_free'         => (int) $r['fee_free'] === 1,
                'aktiv'            => $aktiv,
                'kinder'           => $kinder,
                'beitraege'        => $beitraege,
                'basis'            => $basis,
                'kinderfoerderung' => $kinderfoerderung,
                'beitragsanteil'   => $beitragsanteil,
                'foerderung'       => $foerderung,
                'auszahlung'       => $auszahlung,
                'differenz'        => $auszahlung - $foerderung,
                'notiz'            => (string) ($jahrD['note'] ?? ''),
                'zu'               => $istZu,
                'saldo'            => $foerderung - $beitraege,
            ];

            $sektionen[] = $eintrag;

            foreach (['aktiv', 'kinder', 'beitraege', 'basis', 'kinderfoerderung', 'beitragsanteil', 'foerderung', 'auszahlung', 'saldo'] as $k) {
                $summe[$k] += $eintrag[$k];
            }
        }

        $summe['differenz'] = $summe['auszahlung'] - $summe['foerderung'];

        // Ausgezahlt wird, was tatsaechlich ueberwiesen wurde – sonst die Rechnung.
        $ausbezahlt = $summe['auszahlung'] > 0 ? $summe['auszahlung'] : $summe['foerderung'];
        $einnahmen  = $gesamtBasis + $summe['beitraege'];
        $ausgaben   = $ausbezahlt + $ausgabenExtra;

        return [
            'sektionen'   => $sektionen,
            'summe'       => $summe,
            'kindAlter'   => $kindAlter,
            'kindBetrag'  => $kindBetrag,
            'anteil'      => $anteil,
            'gesamtBasis' => $gesamtBasis,
            'ausgabenExtra' => $ausgabenExtra,
            'ausbezahlt'  => $ausbezahlt,
            'abgeschlossen' => $abgeschlossen,
            'jahr'        => $jahr,
            'darfBearbeiten' => Auth::isSuperuser() && !$abgeschlossen,
            'gesamt'      => [
                'einnahmen' => $einnahmen,
                'ausgaben'  => $ausgaben,
                'ergebnis'  => $einnahmen - $ausgaben,
            ],
        ];
    }
}
