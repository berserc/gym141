<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Zentraler Wochenplan – speist die Stundenplan-Kacheln auf der Startseite
 * UND die Trainingszeiten je Sektionsseite. Gepflegt wird er im
 * Verwaltungsbereich unter /admin/wochenplan (Tabelle schedule_slots).
 */
final class Schedule
{
    /** @var array<int,string> */
    public const DAYS = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    /** Waehlbare Symbole mit Beschriftung fuer das Backend. */
    public const ICON_LABELS = [
        'person'  => 'Person (Crosstraining)',
        'barbell' => 'Hantel (Kraft)',
        'glove'   => 'Boxhandschuh',
        'star'    => 'Stern (Kids)',
        'clock'   => 'Uhr (Open Gym)',
    ];

    /** Kleine Inline-SVG-Symbole (currentColor), Stil wie in der Plan-Grafik. */
    private const ICONS = [
        'person'  => '<path d="M -11 -8 a 11 11 0 0 1 22 0" fill="none" stroke="currentColor" stroke-width="4"/>'
                   . '<circle cx="0" cy="8" r="13" fill="currentColor"/>',
        'barbell' => '<g fill="currentColor"><rect x="-9" y="-3" width="18" height="6" rx="2"/>'
                   . '<rect x="-17" y="-11" width="6" height="22" rx="2"/><rect x="11" y="-11" width="6" height="22" rx="2"/>'
                   . '<rect x="-24" y="-7" width="5" height="14" rx="2"/><rect x="19" y="-7" width="5" height="14" rx="2"/></g>',
        'glove'   => '<path d="M -12 -6 q 0 -12 12 -12 q 14 0 14 13 q 0 10 -8 14 l -12 0 q -6 -3 -6 -15 z" fill="currentColor"/>'
                   . '<rect x="-8" y="9" width="16" height="7" rx="3" fill="currentColor" opacity="0.75"/>',
        'star'    => '<path d="M 0 -15 l 4.2 8.8 9.8 1.2 -7.2 6.7 1.9 9.6 -8.7 -4.8 -8.7 4.8 1.9 -9.6 -7.2 -6.7 9.8 -1.2 z" fill="currentColor"/>',
        'clock'   => '<circle cx="0" cy="0" r="14" fill="none" stroke="currentColor" stroke-width="4"/>'
                   . '<path d="M 0 -7 v 7 l 6 4" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>',
    ];

    /** Symbol als einsatzfertiges SVG-Element. */
    public static function icon(string $name, int $size = 30): string
    {
        $body = self::ICONS[$name] ?? self::ICONS['person'];

        return '<svg viewBox="-26 -26 52 52" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
            . $body . '</svg>';
    }

    /**
     * Alle Einheiten inkl. zugeordneter Sektionen, sortiert nach Tag und Beginn.
     *
     * Jede Einheit traegt:
     *   sections     – Liste der zugeordneten Sektionen (id, slug, name, published)
     *   link         – Slug der ersten veroeffentlichten Sektion ('' = kein Link)
     *   from/to      – Alias fuer time_from/time_to
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $slots = Database::all(
            'SELECT * FROM schedule_slots ORDER BY day, time_from, sort_order, id'
        );

        if ($slots === []) {
            return [];
        }

        $zuordnung = Database::all(
            'SELECT sss.slot_id, s.id, s.slug, s.name, s.published
               FROM schedule_slot_sections sss
               JOIN sections s ON s.id = sss.section_id
              ORDER BY s.sort_order, s.name'
        );

        $proSlot = [];
        foreach ($zuordnung as $z) {
            $proSlot[(int) $z['slot_id']][] = [
                'id'        => (int) $z['id'],
                'slug'      => (string) $z['slug'],
                'name'      => (string) $z['name'],
                'published' => (int) $z['published'],
            ];
        }

        foreach ($slots as &$slot) {
            $sections = $proSlot[(int) $slot['id']] ?? [];

            $link = '';
            foreach ($sections as $section) {
                if ($section['published'] === 1) {
                    $link = $section['slug'];
                    break;
                }
            }

            $slot['sections'] = $sections;
            $slot['link']     = $link;
            $slot['from']     = (string) $slot['time_from'];
            $slot['to']       = (string) $slot['time_to'];
        }

        return $slots;
    }

    /**
     * Plan nach Tagen gruppiert; Tage ohne Training liefern eine leere Liste.
     *
     * @return array<int,list<array<string,mixed>>>
     */
    public static function week(): array
    {
        $week = array_fill_keys(array_keys(self::DAYS), []);

        foreach (self::all() as $slot) {
            $week[(int) $slot['day']][] = $slot;
        }

        return $week;
    }

    /**
     * Einheiten einer Sektion, nach Tag und Beginn sortiert.
     *
     * @return list<array<string,mixed>>
     */
    public static function forSection(string $slug): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $slot): bool => in_array(
                $slug,
                array_column($slot['sections'], 'slug'),
                true
            )
        ));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        foreach (self::all() as $slot) {
            if ((int) $slot['id'] === $id) {
                return $slot;
            }
        }

        return null;
    }
}
