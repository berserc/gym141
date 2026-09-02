<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * App-Einladungen: einmaliger Token (10 Minuten gueltig), mit dem sich die
 * Gym141-Mitglieder-App ohne Zugangsdaten verbindet. Gespeichert wird nur
 * der SHA-256-Hash; der Klartext existiert nur im Link/QR-Code.
 */
final class InviteRepo
{
    public const TTL_MINUTES = 10;

    /**
     * Neue Einladung fuer ein Mitglied.
     *
     * @return array{0: string, 1: string} [Token-Klartext, Ablauf UTC "Y-m-d H:i:s"]
     */
    public static function create(int $memberId, ?int $createdBy): array
    {
        // Abgelaufene und verbrauchte Einladungen bei der Gelegenheit raeumen.
        Database::run(
            "DELETE FROM member_invites WHERE used_at IS NOT NULL OR expires_at < datetime('now', '-1 day')"
        );

        $token   = bin2hex(random_bytes(20));
        $expires = gmdate('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60);

        Database::insert('member_invites', [
            'member_id'  => $memberId,
            'token_hash' => hash('sha256', $token),
            'created_by' => $createdBy,
            'expires_at' => $expires,
        ]);

        return [$token, $expires];
    }

    /**
     * Einladung einloesen (einmalig): liefert das Mitglied oder null.
     * Aktiviert can_login, damit das Mitglied ein vollwertiger App-Nutzer ist.
     *
     * @return array<string,mixed>|null
     */
    public static function redeem(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $invite = Database::one(
            "SELECT * FROM member_invites
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > datetime('now')",
            [hash('sha256', $token)]
        );

        if ($invite === null) {
            return null;
        }

        $member = Database::one(
            'SELECT * FROM members WHERE id = ? AND deleted_at IS NULL AND archived_at IS NULL',
            [(int) $invite['member_id']]
        );

        if ($member === null) {
            return null;
        }

        Database::update('member_invites', (int) $invite['id'], ['used_at' => gmdate('Y-m-d H:i:s')]);

        if ((int) $member['can_login'] !== 1) {
            Database::run('UPDATE members SET can_login = 1 WHERE id = ?', [(int) $member['id']]);
            $member['can_login'] = 1;
        }

        return $member;
    }

    /** Gueltige (offene) Einladung eines Mitglieds, falls vorhanden. */
    public static function openFor(int $memberId): ?array
    {
        return Database::one(
            "SELECT * FROM member_invites
              WHERE member_id = ? AND used_at IS NULL AND expires_at > datetime('now')
              ORDER BY id DESC LIMIT 1",
            [$memberId]
        );
    }

    /** Basis-URL der Instanz (Schema + Host) aus dem aktuellen Request. */
    public static function baseUrl(): string
    {
        $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $schema . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Einladungslink (oeffnet die Anleitung-Seite; enthaelt alle Daten). */
    public static function urlFor(string $token): string
    {
        return self::baseUrl() . url('/app-einladung/' . $token);
    }

    /** App-URI fuers QR-Payload: Server + Token in einem. */
    public static function uriFor(string $token): string
    {
        return 'gym141://einladung?s=' . rawurlencode(self::baseUrl()) . '&t=' . $token;
    }
}
