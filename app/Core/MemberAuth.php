<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Anmeldung fuer Mitglieder (Login-Bereich /mitglied).
 *
 * Getrennt von der Verwaltungs-Anmeldung (Auth): gleiches Session-Cookie,
 * eigener Session-Schluessel. Anmelden darf nur, wem ein Admin den Haken
 * "Login erlauben" gesetzt hat; Kennung ist die E-Mail-Adresse.
 */
final class MemberAuth
{
    private const DUMMY_HASH = '$2y$12$0000000000000000000000u1yWEyxYPZlSWDPjXmSSAsBnyBnZjJi';

    /** @var array<string,mixed>|null */
    private static ?array $member = null;

    private static bool $resolved = false;

    /** @return array<string,mixed>|null */
    public static function member(): ?array
    {
        if (self::$resolved) {
            return self::$member;
        }

        self::$resolved = true;
        $id             = $_SESSION['member_login_id'] ?? null;

        if (!is_int($id)) {
            return self::$member = null;
        }

        // Verwaltungsbenutzer im Modus "Mitglied" (Bruecke ueber users.member_id)
        // brauchen keinen eigenen Login-Haken – sie sind bereits angemeldet.
        $viaAdmin = !empty($_SESSION['member_login_admin']);

        $member = Database::one(
            'SELECT * FROM members
              WHERE id = ? AND deleted_at IS NULL AND archived_at IS NULL'
            . ($viaAdmin ? '' : ' AND can_login = 1'),
            [$id]
        );

        if ($member === null) {
            self::logout();

            return self::$member = null;
        }

        return self::$member = $member;
    }

    public static function check(): bool
    {
        return self::member() !== null;
    }

    public static function id(): ?int
    {
        $member = self::member();

        return $member === null ? null : (int) $member['id'];
    }

    public static function attempt(string $email, string $password): bool
    {
        $email = trim($email);

        $member = Database::one(
            "SELECT * FROM members
              WHERE email = ? COLLATE NOCASE AND email <> ''
                AND can_login = 1 AND deleted_at IS NULL AND archived_at IS NULL
              ORDER BY id
              LIMIT 1",
            [$email]
        );

        $hash    = (string) ($member['login_password_hash'] ?? '') ?: self::DUMMY_HASH;
        $matches = password_verify($password, $hash);

        if ($member === null || (string) $member['login_password_hash'] === '' || !$matches) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['member_login_id'] = (int) $member['id'];
        self::$member                = null;
        self::$resolved              = false;

        Database::update('members', (int) $member['id'], [
            'login_last_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['member_login_id'], $_SESSION['member_login_admin']);

        self::$member   = null;
        self::$resolved = true;
    }

    /** Erzwingt eine Anmeldung; leitet sonst zum Mitglieder-Login. */
    public static function require(): array
    {
        $member = self::member();

        if ($member === null) {
            Flash::error('Bitte melden Sie sich an.');
            Url::redirect('/mitglied/login');
        }

        return $member;
    }
}
