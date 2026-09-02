<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Models\FeeRepo;
use App\Models\Setting;

/**
 * JSON-API fuer die Gym141-App (/api/app/*) – der Zugang eines einzelnen
 * Mitglieds von unterwegs, auch fuer Mitglieder mehrerer Vereine (die App
 * verbindet sich je Verein mit dessen Instanz).
 *
 * Anmeldung mit den Mitglieder-Zugangsdaten der Website (E-Mail + Passwort,
 * Haken "Login erlauben" wie beim Bereich /mitglied). Danach Bearer-Token;
 * gespeichert wird nur dessen SHA-256-Hash (member_api_tokens).
 *
 * Sichtbar: Stammdaten und Beitrag. Aenderbar: nur Kontakt-Stammdaten
 * (Adresse, Telefon, E-Mail) – Beitrag, Name und Geburtsdatum bleiben
 * ausschliesslich Sache der Verwaltung.
 */
final class MemberApiController
{
    /** Felder, die das Mitglied selbst aendern darf. */
    private const EDITABLE = ['street', 'zip', 'city', 'country', 'email', 'phone'];

    // ------------------------------------------------------------- Anmeldung --

    public function login(): void
    {
        $ip = client_ip();

        if (Auth::isThrottled($ip)) {
            $this->json(['error' => 'Zu viele Fehlversuche – bitte in 15 Minuten erneut versuchen.'], 429);
        }

        $body     = $this->body();
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $device   = mb_substr(trim((string) ($body['device'] ?? '')), 0, 120);

        $member = Database::one(
            "SELECT * FROM members
              WHERE email = ? COLLATE NOCASE AND email <> ''
                AND can_login = 1 AND deleted_at IS NULL AND archived_at IS NULL
              ORDER BY id
              LIMIT 1",
            [$email]
        );

        $hash = (string) ($member['login_password_hash'] ?? '');

        if ($member === null || $hash === '' || !password_verify($password, $hash)) {
            Auth::recordFailedAttempt($ip, 'app:' . $email);
            $this->json(['error' => 'E-Mail oder Passwort ist falsch – oder der Zugang ist nicht freigeschaltet.'], 401);
        }

        Auth::clearAttempts($ip);

        $token = bin2hex(random_bytes(32));

        Database::insert('member_api_tokens', [
            'member_id'   => (int) $member['id'],
            'token_hash'  => hash('sha256', $token),
            'device_name' => $device,
        ]);

        Database::update('members', (int) $member['id'], [
            'login_last_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->json([
            'token'  => $token,
            'club'   => $this->club(),
            'member' => $this->profile($member),
            'fee'    => $this->fee($member),
        ]);
    }

    public function logout(): void
    {
        [$tokenId] = $this->requireToken();
        Database::run('DELETE FROM member_api_tokens WHERE id = ?', [$tokenId]);
        $this->json(['ok' => true]);
    }

    /**
     * App-Einladung einloesen: einmaliger Kurzzeit-Token (Link/QR) statt
     * Zugangsdaten. Antwort wie login() - die App ist sofort verbunden.
     */
    public function invite_redeem(): void
    {
        $ip = client_ip();

        if (Auth::isThrottled($ip)) {
            $this->json(['error' => 'Zu viele Fehlversuche – bitte in 15 Minuten erneut versuchen.'], 429);
        }

        $body   = $this->body();
        $member = \App\Models\InviteRepo::redeem(trim((string) ($body['token'] ?? '')));

        if ($member === null) {
            Auth::recordFailedAttempt($ip, 'app-einladung');
            $this->json(['error' => 'Die Einladung ist ungültig, abgelaufen oder wurde schon verwendet – bitte eine neue anfordern.'], 401);
        }

        Auth::clearAttempts($ip);

        $token = bin2hex(random_bytes(32));

        Database::insert('member_api_tokens', [
            'member_id'   => (int) $member['id'],
            'token_hash'  => hash('sha256', $token),
            'device_name' => mb_substr(trim((string) ($body['device'] ?? '')), 0, 120),
        ]);

        Database::update('members', (int) $member['id'], [
            'login_last_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->json([
            'token'  => $token,
            'club'   => $this->club(),
            'member' => $this->profile($member),
            'fee'    => $this->fee($member),
        ]);
    }

    // --------------------------------------------------------------- Profil --

    public function profile_get(): void
    {
        [, $member] = $this->requireToken();

        $this->json([
            'club'   => $this->club(),
            'member' => $this->profile($member),
            'fee'    => $this->fee($member),
        ]);
    }

    public function profile_update(): void
    {
        [, $member] = $this->requireToken();

        $body = $this->body();

        // Offline-Sync-Konfliktbasis: Beruht die App-Aenderung auf einem
        // aelteren Stand (z. B. hat die Verwaltung inzwischen geaendert),
        // kommt 409 samt aktuellem Profil zurueck – das Mitglied entscheidet
        // in der App (erneut senden mit force) oder verschiebt es.
        $basis = trim((string) ($body['base_updated_at'] ?? ''));

        if ($basis !== '' && empty($body['force']) && $basis !== (string) ($member['updated_at'] ?? '')) {
            $this->json([
                'conflict' => true,
                'club'     => $this->club(),
                'member'   => $this->profile($member),
                'fee'      => $this->fee($member),
            ], 409);
        }

        $changes = [];

        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            $value = trim((string) $body[$field]);

            if ($field === 'email') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
                }
            } elseif ($field === 'country') {
                $value = strtoupper(mb_substr($value, 0, 2)) ?: 'AT';
            } else {
                $value = mb_substr($value, 0, 200);
            }

            if ($value !== (string) $member[$field]) {
                $changes[$field] = $value;
            }
        }

        if ($changes !== []) {
            $vorher = array_intersect_key($member, $changes);

            $changes['updated_at'] = gmdate('Y-m-d H:i:s');
            Database::update('members', (int) $member['id'], $changes);
            unset($changes['updated_at']);

            // Fuer Admin und Verwaltung nachvollziehbar (Aenderungs-Feed).
            \App\Core\Audit::logAs(
                null,
                trim((string) $member['first_name'] . ' ' . (string) $member['last_name']) . ' (Mitglieder-App)',
                'member_updated',
                'member',
                (int) $member['id'],
                \App\Core\Audit::diff($vorher, $changes)
            );

            $member = array_merge($member, $changes);
        }

        $this->json([
            'club'   => $this->club(),
            'member' => $this->profile($member),
            'fee'    => $this->fee($member),
        ]);
    }

    // -------------------------------------------------------------- Gewicht --

    /** Gewichtsverlauf (beide Quellen; die App zeigt Trainer-Messungen nur an). */
    public function weights_get(): void
    {
        [, $member] = $this->requireToken();

        $rows = Database::all(
            'SELECT measured_on, measured_time, weight, note, source FROM member_weights
              WHERE member_id = ? ORDER BY measured_on DESC, measured_time DESC, id DESC',
            [(int) $member['id']]
        );

        $this->json(['entries' => array_map(static fn (array $r): array => [
            'date'   => (string) $r['measured_on'],
            'time'   => (string) ($r['measured_time'] ?? ''),
            'kg'     => (float) $r['weight'],
            'note'   => (string) $r['note'],
            'source' => (string) $r['source'],
        ], $rows)]);
    }

    /**
     * Gewichtsverlauf der App uebermitteln (Spiegel-Semantik): ersetzt ALLE
     * Eintraege mit source='app' durch die gesendete Liste. Messungen der
     * Verwaltung (source='') bleiben unberuehrt. Damit ist der Abgleich
     * idempotent und Offline-Loeschungen in der App wirken auch am Server.
     */
    public function weights_put(): void
    {
        [, $member] = $this->requireToken();

        $entries = (array) ($this->body()['entries'] ?? []);

        if (count($entries) > 1000) {
            $this->json(['error' => 'Zu viele Einträge.'], 422);
        }

        $sauber = [];

        foreach ($entries as $entry) {
            $datum   = date_create((string) ($entry['date'] ?? ''));
            $gewicht = (float) ($entry['kg'] ?? 0);

            if ($datum === false || $gewicht <= 20 || $gewicht > 400) {
                $this->json(['error' => 'Ungültiger Eintrag (Datum oder Gewicht in kg, 20–400).'], 422);
            }

            $zeit = trim((string) ($entry['time'] ?? ''));

            $sauber[] = [
                'measured_on'   => $datum->format('Y-m-d'),
                // Uhrzeit HH:MM (mehrere Messungen pro Tag); '' = unbekannt
                'measured_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $zeit) === 1 ? $zeit : '',
                'weight'        => round($gewicht, 1),
                'note'          => mb_substr(trim((string) ($entry['note'] ?? '')), 0, 200),
            ];
        }

        $memberId = (int) $member['id'];

        Database::transaction(static function () use ($memberId, $sauber): void {
            Database::run("DELETE FROM member_weights WHERE member_id = ? AND source = 'app'", [$memberId]);

            foreach ($sauber as $row) {
                Database::insert('member_weights', $row + ['member_id' => $memberId, 'source' => 'app']);
            }
        });

        $this->json(['ok' => true, 'count' => count($sauber)]);
    }

    /**
     * Widerruf der Datenfreigabe: loescht die von der App uebermittelten
     * Gewichtsdaten (source='app') beim Verein. Trainer-Messungen bleiben.
     */
    public function weights_delete(): void
    {
        [, $member] = $this->requireToken();

        $geloescht = Database::run(
            "DELETE FROM member_weights WHERE member_id = ? AND source = 'app'",
            [(int) $member['id']]
        )->rowCount();

        $this->json(['ok' => true, 'deleted' => $geloescht]);
    }

    // --------------------------------------------------------------- Intern --

    /**
     * Bearer-Token pruefen; liefert [Token-Id, Mitglied] oder beendet mit 401.
     *
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function requireToken(): array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!str_starts_with($header, 'Bearer ') || strlen($header) < 20) {
            $this->json(['error' => 'Anmeldung erforderlich.'], 401);
        }

        $row = Database::one(
            "SELECT t.id AS token_id, t.last_used_at, m.*
               FROM member_api_tokens t
               JOIN members m ON m.id = t.member_id
              WHERE t.token_hash = ?
                AND m.can_login = 1 AND m.deleted_at IS NULL AND m.archived_at IS NULL",
            [hash('sha256', substr($header, 7))]
        );

        if ($row === null) {
            $this->json(['error' => 'Sitzung abgelaufen – bitte neu anmelden.'], 401);
        }

        $tokenId = (int) $row['token_id'];

        // last_used_at hoechstens alle 10 Minuten schreiben (SQLite schonen).
        if ((string) $row['last_used_at'] < gmdate('Y-m-d H:i:s', time() - 600)) {
            Database::run('UPDATE member_api_tokens SET last_used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $tokenId]);
        }

        unset($row['token_id'], $row['last_used_at']);

        return [$tokenId, $row];
    }

    /** @return array<string,mixed> */
    private function profile(array $member): array
    {
        return [
            'id'         => (int) $member['id'],
            'member_no'  => (string) $member['member_no'],
            'first_name' => (string) $member['first_name'],
            'last_name'  => (string) $member['last_name'],
            'birthdate'  => $member['birthdate'],
            'gender'     => (string) $member['gender'],
            'street'     => (string) $member['street'],
            'zip'        => (string) $member['zip'],
            'city'       => (string) $member['city'],
            'country'    => (string) $member['country'],
            'email'      => (string) $member['email'],
            'phone'      => (string) $member['phone'],
            'joined_on'  => $member['joined_on'],
            'updated_at' => (string) ($member['updated_at'] ?? ''),
            'editable'   => self::EDITABLE,
        ];
    }

    /** Beitrag des Mitglieds (nur lesend). @return array<string,mixed>|null */
    private function fee(array $member): ?array
    {
        $planId = (int) ($member['fee_plan_id'] ?? 0);

        if ($planId === 0) {
            return null;
        }

        $plan = FeeRepo::plan($planId);

        if ($plan === null) {
            return null;
        }

        // memberAmountAt erwartet den Plan-Betrag als plan_amount am Datensatz.
        $member['plan_amount'] = $plan['amount'];

        $betrag = FeeRepo::memberAmountAt($member, date('Y-m-d'));
        $monate = (int) (FeeRepo::INTERVALS[(string) $plan['interval']][0] ?? 1);

        $offen = 0.0;

        foreach (FeeRepo::entriesForMember((int) $member['id']) as $entry) {
            if ((int) $entry['paid'] !== 1 && (string) $entry['due_date'] <= date('Y-m-d')) {
                $offen += (float) $entry['amount'];
            }
        }

        return [
            'plan_name'      => (string) $plan['name'],
            'interval'       => (string) $plan['interval'],
            'interval_label' => FeeRepo::intervalLabel((string) $plan['interval']),
            'amount'         => round($betrag, 2),
            'monthly'        => round($betrag / max(1, $monate), 2),
            'category'       => (string) $member['fee_category'],
            'open_total'     => round($offen, 2),
            'currency'       => 'EUR',
            'since'          => $member['fee_since'],
        ];
    }

    /** @return array<string,string> */
    private function club(): array
    {
        return [
            'name'     => Setting::get('club_name') ?: (string) \App\Core\Config::get('app_name', 'Gym141'),
            'logo_url' => self::logoUrl(),
        ];
    }

    /** Absolute URL des Vereinslogos ('' = keines hinterlegt). */
    public static function logoUrl(): string
    {
        $logo = site_logo();
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($logo === '' || $host === '') {
            return '';
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return ($https ? 'https' : 'http') . '://' . $host . $logo;
    }

    /** @return array<string,mixed> JSON- oder Formular-Body. */
    private function body(): array
    {
        $raw = (string) file_get_contents('php://input');
        $ct  = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        // Content-Type ODER JSON-artiger Inhalt – je nach SAPI fehlt der Header.
        if ($raw !== '' && (str_contains($ct, 'json') || str_starts_with(ltrim($raw), '{'))) {
            $data = json_decode($raw, true);

            if (is_array($data)) {
                return $data;
            }
        }

        return $_POST;
    }

    private function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
