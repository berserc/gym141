<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\Schedule;
use App\Models\SectionRepo;

/** Wochenplan der Trainings pflegen (Startseite + Sektionsseiten). */
final class ScheduleAdminController
{
    public function index(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');

        View::display('admin/schedule/index', [
            'title'    => 'Wochenplan',
            'slots'    => Schedule::all(),
            'sections' => SectionRepo::all(),
            'errors'   => Flash::errors(),
        ], 'layouts/admin');
    }

    public function save(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = post_int('slot_id');

        [$data, $sectionIds, $fehler] = $this->validate();

        if ($fehler !== null) {
            Flash::error($fehler);
            Url::redirect('/admin/wochenplan');
        }

        if ($id > 0) {
            if (Database::one('SELECT id FROM schedule_slots WHERE id = ?', [$id]) === null) {
                Flash::error('Einheit nicht gefunden.');
                Url::redirect('/admin/wochenplan');
            }

            $data['updated_at'] = gmdate('Y-m-d H:i:s');
            Database::update('schedule_slots', $id, $data);
            Audit::log('schedule_updated', 'schedule', $id, (string) $data['title']);
        } else {
            $id = Database::insert('schedule_slots', $data);
            Audit::log('schedule_created', 'schedule', $id, (string) $data['title']);
        }

        Database::run('DELETE FROM schedule_slot_sections WHERE slot_id = ?', [$id]);

        foreach ($sectionIds as $sectionId) {
            Database::run(
                'INSERT OR IGNORE INTO schedule_slot_sections (slot_id, section_id) VALUES (?, ?)',
                [$id, $sectionId]
            );
        }

        Flash::success('Wochenplan gespeichert.');
        Url::redirect('/admin/wochenplan');
    }

    public function delete(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id   = post_int('slot_id');
        $slot = Database::one('SELECT * FROM schedule_slots WHERE id = ?', [$id]);

        if ($slot === null) {
            Flash::error('Einheit nicht gefunden.');
            Url::redirect('/admin/wochenplan');
        }

        Database::run('DELETE FROM schedule_slots WHERE id = ?', [$id]);

        Audit::log('schedule_deleted', 'schedule', $id, (string) $slot['title']);
        Flash::success('Einheit gelöscht.');
        Url::redirect('/admin/wochenplan');
    }

    /**
     * @return array{0:array<string,mixed>,1:list<int>,2:?string}
     */
    private function validate(): array
    {
        $tag = post_int('day');

        if ($tag < 1 || $tag > 7) {
            return [[], [], 'Bitte einen Wochentag wählen.'];
        }

        $titel = post('title');

        if ($titel === '') {
            return [[], [], 'Bitte einen Namen für die Einheit angeben.'];
        }

        $von = post('time_from');
        $bis = post('time_to');

        foreach ([$von, $bis] as $zeit) {
            if ($zeit !== '' && preg_match('/^\d{2}:\d{2}$/', $zeit) !== 1) {
                return [[], [], 'Zeiten bitte als HH:MM angeben.'];
            }
        }

        if ($von !== '' && $bis !== '' && $bis <= $von) {
            return [[], [], 'Das Ende muss nach dem Beginn liegen.'];
        }

        $farbe = post('color', '#d4a437');

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $farbe) !== 1) {
            $farbe = '#d4a437';
        }

        $icon = post('icon', 'person');

        if (!array_key_exists($icon, Schedule::ICON_LABELS)) {
            $icon = 'person';
        }

        /** @var list<int> $sectionIds */
        $sectionIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['section_ids'] ?? []))
        )));

        foreach ($sectionIds as $sectionId) {
            if (SectionRepo::find($sectionId) === null) {
                return [[], [], 'Bitte gültige Sektionen wählen.'];
            }
        }

        return [[
            'day'        => $tag,
            'time_from'  => $von,
            'time_to'    => $bis,
            'title'      => $titel,
            'note'       => post('note'),
            'badge'      => mb_substr(post('badge'), 0, 20),
            'color'      => strtolower($farbe),
            'icon'       => $icon,
            'sort_order' => post_int('sort_order'),
        ], $sectionIds, null];
    }
}
