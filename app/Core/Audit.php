<?php

declare(strict_types=1);

namespace App\Core;

/** Schreibt nachvollziehbare Eintraege fuer alle veraendernden Aktionen. */
final class Audit
{
    public static function log(string $action, string $entity = '', ?int $entityId = null, string $detail = ''): void
    {
        $user = Auth::user();

        Database::insert('audit_log', [
            'user_id'   => $user['id'] ?? null,
            'username'  => (string) ($user['username'] ?? 'system'),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'detail'    => $detail,
            'ip'        => client_ip(),
        ]);
    }

    /**
     * Beschreibt die Unterschiede zweier Datensaetze in Kurzform.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function diff(array $before, array $after): string
    {
        $changes = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;

            if ((string) $old === (string) $value) {
                continue;
            }

            $changes[] = sprintf('%s: "%s" → "%s"', $key, (string) $old, (string) $value);
        }

        return implode('; ', array_slice($changes, 0, 25));
    }
}
