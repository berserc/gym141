<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class UserRepo
{
    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $users = Database::all(
            'SELECT u.*, m.first_name AS member_first_name, m.last_name AS member_last_name,
                    m.member_no AS member_no, m.archived_at AS member_archived_at
               FROM users u
               LEFT JOIN members m ON m.id = u.member_id AND m.deleted_at IS NULL
              ORDER BY u.role, u.username COLLATE NOCASE'
        );

        foreach ($users as $i => $user) {
            $users[$i]['sections'] = Database::all(
                'SELECT s.id, s.name
                   FROM user_sections us JOIN sections s ON s.id = us.section_id
                  WHERE us.user_id = ?
                  ORDER BY s.name COLLATE NOCASE',
                [(int) $user['id']]
            );
        }

        return $users;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $user = Database::one(
            'SELECT u.*, m.first_name AS member_first_name, m.last_name AS member_last_name
               FROM users u
               LEFT JOIN members m ON m.id = u.member_id AND m.deleted_at IS NULL
              WHERE u.id = ?',
            [$id]
        );

        if ($user === null) {
            return null;
        }

        $user['section_ids'] = array_map(
            'intval',
            array_column(
                Database::all('SELECT section_id FROM user_sections WHERE user_id = ?', [$id]),
                'section_id'
            )
        );

        return $user;
    }

    public static function usernameTaken(string $username, ?int $ignoreId = null): bool
    {
        return Database::one(
            'SELECT id FROM users WHERE username = ? COLLATE NOCASE AND (? IS NULL OR id <> ?)',
            [$username, $ignoreId, $ignoreId]
        ) !== null;
    }

    /** @param list<int> $sectionIds */
    public static function setSections(int $userId, array $sectionIds): void
    {
        Database::run('DELETE FROM user_sections WHERE user_id = ?', [$userId]);

        foreach (array_unique($sectionIds) as $sectionId) {
            Database::run(
                'INSERT OR IGNORE INTO user_sections (user_id, section_id) VALUES (?, ?)',
                [$userId, (int) $sectionId]
            );
        }
    }

    /** Verhindert, dass der letzte aktive Superuser deaktiviert oder geloescht wird. */
    public static function activeSuperuserCount(?int $excludingId = null): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM users
              WHERE role = 'superuser' AND active = 1 AND (? IS NULL OR id <> ?)",
            [$excludingId, $excludingId]
        );
    }

    /**
     * Erzeugt ein gut lesbares Startpasswort (ohne leicht verwechselbare Zeichen).
     */
    public static function generatePassword(int $length = 14): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
