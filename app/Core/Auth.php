<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Anmeldung, Rollen und Sektionsberechtigungen.
 *
 * Rollen:
 *   superuser      – darf alles, inkl. endgueltigem Loeschen und Benutzerverwaltung
 *   sektionsleiter – nur die eigenen Sektionen; loeschen nur als Vormerkung
 *   kassier        – vereinsweit lesend, plus Beitraege/Zahlungen und Auswertungen
 */
final class Auth
{
    /** Mindestlaenge fuer Passwoerter (an einer Stelle gepflegt). */
    public const MIN_PASSWORD_LENGTH = 8;

    /** Gueltiger bcrypt-Hash, der zu keinem vergebenen Passwort passt. */
    private const DUMMY_HASH = '$2y$12$0000000000000000000000u1yWEyxYPZlSWDPjXmSSAsBnyBnZjJi';

    public const ROLES = [
        'superuser'      => 'Superuser',
        'sektionsleiter' => 'Sektionsleitung',
        'kassier'        => 'Kassier',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    private static bool $resolved = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name((string) Config::get('session_name', 'gym141_sess'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (string) Config::get('base_path', '') . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;
        $id             = $_SESSION['user_id'] ?? null;

        if (!is_int($id)) {
            return self::$user = null;
        }

        $user = Database::one('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);

        if ($user === null) {
            // Benutzer wurde deaktiviert oder geloescht, waehrend die Session lief.
            self::logout();

            return self::$user = null;
        }

        $user['section_ids'] = array_map(
            'intval',
            array_column(
                Database::all('SELECT section_id FROM user_sections WHERE user_id = ?', [$id]),
                'section_id'
            )
        );

        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function role(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['role'];
    }

    public static function is(string ...$roles): bool
    {
        $role = self::role();

        return $role !== null && in_array($role, $roles, true);
    }

    public static function isSuperuser(): bool
    {
        return self::is('superuser');
    }

    /** Darf Stammdaten (Mitglieder, Sektionen) veraendern? */
    public static function canWrite(): bool
    {
        return self::is('superuser', 'sektionsleiter');
    }

    /** Darf Beitraege/Zahlungen erfassen? */
    public static function canManageFees(): bool
    {
        return self::is('superuser', 'kassier', 'sektionsleiter');
    }

    /**
     * IDs der Sektionen, auf die der angemeldete Benutzer zugreifen darf.
     * null bedeutet "alle" (Superuser und Kassier).
     *
     * @return list<int>|null
     */
    public static function allowedSectionIds(): ?array
    {
        $user = self::user();

        if ($user === null) {
            return [];
        }

        if (in_array($user['role'], ['superuser', 'kassier'], true)) {
            return null;
        }

        /** @var list<int> */
        return $user['section_ids'];
    }

    // ------------------------------------------------------ Verwaltungsmodus --

    /** Modi steuern, welche Menuepunkte sichtbar sind (kein Rechtemodell). */
    public const MODES = [
        'admin'    => 'Admin',
        'kassier'  => 'Kassier',
        'trainer'  => 'Trainer',
        'mitglied' => 'Mitglied',
    ];

    /**
     * Welche Modi darf dieses Konto verwenden? Der Superuser alle; Kassier und
     * Sektionsleitung nur ihren eigenen. "Mitglied" gibt es zusaetzlich, wenn
     * das Benutzerkonto mit einem Mitglied verknuepft ist.
     *
     * @return list<string>
     */
    public static function allowedModes(): array
    {
        $user = self::user();

        if ($user === null) {
            return [];
        }

        $modes = match ((string) $user['role']) {
            'superuser' => ['admin', 'kassier', 'trainer'],
            'kassier'   => ['kassier'],
            default     => ['trainer'],
        };

        if ((int) ($user['member_id'] ?? 0) > 0) {
            $modes[] = 'mitglied';
        }

        return $modes;
    }

    /** Aktiver Verwaltungsmodus (Vorgabe: der erste erlaubte). */
    public static function mode(): string
    {
        $allowed = self::allowedModes();
        $mode    = (string) ($_SESSION['admin_mode'] ?? '');

        if ($mode !== 'mitglied' && in_array($mode, $allowed, true)) {
            return $mode;
        }

        return $allowed[0] ?? 'admin';
    }

    public static function canAccessSection(int $sectionId): bool
    {
        $allowed = self::allowedSectionIds();

        return $allowed === null || in_array($sectionId, $allowed, true);
    }

    /**
     * Darf der Benutzer dieses Mitglied sehen/bearbeiten? Ein Mitglied kann in
     * mehreren Sektionen sein – es reicht, wenn EINE davon zugeordnet ist.
     *
     * @param array<string,mixed> $member Zeile aus members (id, section_id)
     */
    public static function canAccessMember(array $member): bool
    {
        $allowed = self::allowedSectionIds();

        if ($allowed === null) {
            return true;
        }

        $sectionIds = array_map(
            'intval',
            array_column(
                Database::all(
                    'SELECT section_id FROM member_sections WHERE member_id = ?',
                    [(int) ($member['id'] ?? 0)]
                ),
                'section_id'
            )
        );
        $sectionIds[] = (int) ($member['section_id'] ?? 0);

        return array_intersect($sectionIds, $allowed) !== [];
    }

    // ------------------------------------------------------------- Anmeldung --

    public static function attempt(string $username, string $password): bool
    {
        $user = Database::one(
            'SELECT * FROM users WHERE username = ? COLLATE NOCASE',
            [trim($username)]
        );

        // Auch bei unbekanntem Benutzer verifizieren, damit die Antwortzeit nichts verraet.
        // Der Platzhalter ist ein gueltiger bcrypt-Hash eines Zufallswerts.
        $hash    = (string) ($user['password_hash'] ?? self::DUMMY_HASH);
        $matches = password_verify($password, $hash);

        if ($user === null || (int) $user['active'] !== 1 || !$matches) {
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', (int) $user['id'], [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        self::$user          = null;
        self::$resolved      = false;

        Database::update('users', (int) $user['id'], [
            'last_login_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
            ]);
        }

        session_destroy();

        self::$user     = null;
        self::$resolved = true;
    }

    // ------------------------------------------------------ Brute-Force-Bremse --

    public static function isThrottled(string $ip): bool
    {
        $max = (int) Config::get('login_max_attempts', 10);

        $count = (int) Database::value(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > datetime('now', '-15 minutes')",
            [$ip]
        );

        return $count >= $max;
    }

    public static function recordFailedAttempt(string $ip, string $username): void
    {
        Database::insert('login_attempts', ['ip' => $ip, 'username' => $username]);

        // Aufraeumen, damit die Tabelle nicht ewig waechst.
        Database::run("DELETE FROM login_attempts WHERE at < datetime('now', '-1 day')");
    }

    public static function clearAttempts(string $ip): void
    {
        Database::run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    }
}
