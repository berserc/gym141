<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Event141Client;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use RuntimeException;

/**
 * Wettkaempfe ueber Event141: offene Events anzeigen, eigene Mitglieder
 * anmelden/abmelden, Ergebnisse als Kaempfe in die "Erfolge" uebernehmen.
 *
 * Zuordnung Mitglied <-> Event141-Sportler ueber external_ref "gym141:<id>".
 * Sichtbereich wie ueberall: sektionsbezogene Rollen sehen nur ihre Mitglieder.
 */
final class Event141Controller
{
    private const REF = 'gym141:';

    public function index(): void
    {
        AuthController::requireLogin();

        $events  = [];
        $results = [];
        $gym     = null;
        $error   = '';

        if (Event141Client::configured()) {
            try {
                $client  = new Event141Client();
                $gym     = $client->gym()['gym'] ?? null;
                $events  = $client->openEvents();
                $results = $this->annotate($client->results());
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        View::display('admin/event141/index', [
            'title'      => 'Wettkämpfe',
            'configured' => Event141Client::configured(),
            'baseUrl'    => (new Event141Client())->baseUrl(),
            'gym'        => $gym,
            'events'     => $events,
            'results'    => $results,
            'error'      => $error,
            'members'    => $this->members(),
            'canEdit'    => Auth::canWrite(),
        ], 'layouts/admin');
    }

    /** Ausgewaehlte Mitglieder abgleichen und in einer Kategorie anmelden. */
    public function enter(): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $slug     = post('event_slug');
        $category = post_int('category_id');
        $ids      = array_values(array_filter(array_map('intval', (array) ($_POST['member_ids'] ?? []))));
        $erlaubt  = [];

        foreach ($this->members() as $m) {
            $erlaubt[(int) $m['id']] = $m;
        }

        $ids = array_values(array_filter($ids, static fn (int $id): bool => isset($erlaubt[$id])));

        if ($slug === '' || $ids === []) {
            Flash::error('Bitte ein Event und mindestens ein Mitglied auswählen.');
            Url::redirect('/admin/wettkaempfe');
        }

        $athletes = [];
        $entries  = [];

        foreach ($ids as $id) {
            $m = $erlaubt[$id];

            $athletes[] = array_filter([
                'external_ref' => self::REF . $id,
                'first_name'   => (string) $m['first_name'],
                'last_name'    => (string) $m['last_name'],
                'birthdate'    => $m['birthdate'] ?: null,
                'gender'       => in_array($m['gender'], ['m', 'w', 'd'], true) ? (string) $m['gender'] : null,
                'weight'       => $m['weight'] !== null ? (float) $m['weight'] : null,
            ], static fn ($v): bool => $v !== null);

            $entries[] = array_filter([
                'external_ref' => self::REF . $id,
                'category_id'  => $category > 0 ? $category : null,
                'note'         => post('note'),
            ], static fn ($v): bool => $v !== null && $v !== '');
        }

        try {
            $client = new Event141Client();
            $client->upsertAthletes($athletes);
            $antwort = $client->enter($slug, $entries);
        } catch (RuntimeException $e) {
            Flash::error('Anmeldung fehlgeschlagen: ' . $e->getMessage());
            Url::redirect('/admin/wettkaempfe');
        }

        $neu   = count(array_filter((array) ($antwort['entries'] ?? []), static fn ($x): bool => empty($x['existing'])));
        $schon = count((array) ($antwort['entries'] ?? [])) - $neu;

        Audit::log('event141_entries', 'event141', null, $slug . ': ' . $neu . ' angemeldet');

        if ($neu > 0) {
            Flash::success($neu . ' Mitglied(er) angemeldet – der Veranstalter bestätigt die Anmeldung.');
        }

        if ($schon > 0) {
            Flash::info($schon . ' waren in dieser Kategorie schon angemeldet.');
        }

        foreach ((array) ($antwort['errors'] ?? []) as $err) {
            Flash::error('Event141: ' . (string) ($err['error'] ?? 'Fehler'));
        }

        Url::redirect('/admin/wettkaempfe');
    }

    public function withdraw(): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        try {
            (new Event141Client())->withdraw(post_int('entry_id'));
            Audit::log('event141_withdraw', 'event141', null, 'Anmeldung ' . post_int('entry_id'));
            Flash::success('Abgemeldet.');
        } catch (RuntimeException $e) {
            Flash::error('Abmeldung fehlgeschlagen: ' . $e->getMessage());
        }

        Url::redirect('/admin/wettkaempfe');
    }

    /** Beendete Kaempfe als Eintraege unter "Erfolge" anlegen (jeder Kampf nur einmal). */
    public function importResults(): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        try {
            $results = $this->annotate((new Event141Client())->results());
        } catch (RuntimeException $e) {
            Flash::error('Ergebnisse konnten nicht geholt werden: ' . $e->getMessage());
            Url::redirect('/admin/wettkaempfe');
        }

        $nur = array_map('intval', (array) ($_POST['bout_ids'] ?? []));
        $neu = 0;

        foreach ($results as $r) {
            if ($r['member_id'] === null || $r['imported'] || $r['result'] === null) {
                continue;
            }

            if ($nur !== [] && !in_array((int) $r['bout_id'], $nur, true)) {
                continue;
            }

            Database::insert('member_fights', [
                'member_id'     => (int) $r['member_id'],
                'sport'         => self::sportOf((string) $r['style']),
                'style'         => (string) $r['style'],
                'fight_date'    => $r['date'] ?: null,
                'event'         => (string) $r['event'],
                'opponent'      => (string) $r['opponent'],
                'opponent_club' => (string) $r['opponent_gym'],
                'weight_class'  => (string) $r['category'],
                'result'        => (string) $r['result'],
                'method'        => (string) $r['method'],
                'end_round'     => is_numeric($r['round']) ? (int) $r['round'] : null,
                'note'          => 'aus Event141 übernommen',
                'source_ref'    => (string) $r['source_ref'],
                'created_by'    => Auth::id(),
            ]);
            $neu++;
        }

        Audit::log('event141_results', 'event141', null, $neu . ' Kämpfe übernommen');
        Flash::success($neu > 0 ? $neu . ' Kampf/Kämpfe in die Erfolge übernommen.' : 'Keine neuen Ergebnisse zum Übernehmen.');
        Url::redirect('/admin/wettkaempfe');
    }

    // ---------------------------------------------------------------- intern --

    /**
     * Ergebnisse um Mitglied, Erfolge-Ergebnis und "schon uebernommen" ergaenzen.
     *
     * @param list<array<string,mixed>> $results
     * @return list<array<string,mixed>>
     */
    private function annotate(array $results): array
    {
        $members = [];

        foreach ($this->members(false) as $m) {
            $members[(int) $m['id']] = $m;
        }

        $out = [];

        foreach ($results as $r) {
            $ref      = (string) ($r['external_ref'] ?? '');
            $memberId = str_starts_with($ref, self::REF) ? (int) substr($ref, strlen(self::REF)) : (int) ($r['gym141_member_id'] ?? 0);
            $memberId = isset($members[$memberId]) ? $memberId : null;

            $outcome = $r['outcome'] ?? null;
            $result  = match ($outcome) {
                'win'   => preg_match('/walkover|kampflos/i', (string) ($r['method'] ?? '')) ? 'kampflos' : 'sieg',
                'loss'  => 'niederlage',
                'draw'  => 'unentschieden',
                default => null,
            };

            $sourceRef = 'event141:' . (int) ($r['bout_id'] ?? 0) . ':' . (int) ($r['athlete_id'] ?? 0);

            $out[] = $r + [
                'member_id'  => $memberId,
                'result'     => $result,
                'source_ref' => $sourceRef,
                'imported'   => Database::one('SELECT id FROM member_fights WHERE source_ref = ?', [$sourceRef]) !== null,
            ];
        }

        return $out;
    }

    /** Disziplin aus Event141 auf die Sportarten der Erfolge abbilden. */
    private static function sportOf(string $style): string
    {
        $s = mb_strtolower($style);

        if (str_contains($s, 'muay') || str_contains($s, 'thai')) {
            return 'Muay Thai';
        }

        if (str_contains($s, 'box') && !str_contains($s, 'kick')) {
            return 'Boxen';
        }

        return 'Kickboxen';
    }

    /**
     * Mitglieder im Sichtbereich des Benutzers (mit letztem Gewicht).
     *
     * @return list<array<string,mixed>>
     */
    private function members(bool $activeOnly = true): array
    {
        $allowed = Auth::allowedSectionIds();
        $filter  = '';
        $params  = [];

        if ($allowed !== null) {
            if ($allowed === []) {
                return [];
            }

            $marks  = implode(',', array_fill(0, count($allowed), '?'));
            $filter = " AND EXISTS (SELECT 1 FROM member_sections ms WHERE ms.member_id = m.id AND ms.section_id IN ($marks))";
            $params = $allowed;
        }

        return Database::all(
            "SELECT m.id, m.first_name, m.last_name, m.birthdate, m.gender, m.member_no,
                    (SELECT w.weight FROM member_weights w WHERE w.member_id = m.id
                      ORDER BY w.measured_on DESC, w.id DESC LIMIT 1) AS weight,
                    (SELECT GROUP_CONCAT(s.name, ', ') FROM member_sections ms JOIN sections s ON s.id = ms.section_id
                      WHERE ms.member_id = m.id AND ms.status = 'aktiv') AS sektionen
               FROM members m
              WHERE m.deleted_at IS NULL" . ($activeOnly ? " AND m.archived_at IS NULL AND m.status = 'aktiv'" : '') . $filter . '
              ORDER BY m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE',
            $params
        );
    }
}
