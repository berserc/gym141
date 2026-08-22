<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;

/**
 * Verwaltung der amtlichen Gemeindeliste.
 *
 * Die Tabelle enthaelt alle rund 2100 oesterreichischen Gemeinden. Damit die
 * Auswahl im Mitgliederformular brauchbar bleibt, ist nur ein Teil davon
 * freigeschaltet ("active"); standardmaessig die steirischen.
 */
final class GemeindeController
{
    private const PER_PAGE = 100;

    public function index(): void
    {
        AuthController::requireRole('superuser');

        $search     = query('q');
        $bundesland = query('bundesland');
        $onlyActive = query('nur_aktive');

        $conditions = ['1 = 1'];
        $params     = [];

        if ($search !== '') {
            $conditions[] = '(name LIKE ? OR plz LIKE ? OR gkz LIKE ?)';
            $like         = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($bundesland !== '') {
            $conditions[] = 'bundesland = ?';
            $params[]     = $bundesland;
        }

        if ($onlyActive !== '') {
            $conditions[] = 'active = 1';
        }

        $where = implode(' AND ', $conditions);
        $total = (int) Database::value("SELECT COUNT(*) FROM gemeinden WHERE $where", $params);

        [$page, $offset, $pages] = paginate($total, self::PER_PAGE, max(1, (int) query('page', '1')));

        View::display('admin/gemeinden', [
            'title'      => 'Gemeinden',
            'rows'       => Database::all(
                "SELECT * FROM gemeinden WHERE $where
                  ORDER BY bundesland COLLATE NOCASE, name COLLATE NOCASE
                  LIMIT " . self::PER_PAGE . " OFFSET $offset",
                $params
            ),
            'laender'    => Database::all(
                'SELECT bundesland,
                        COUNT(*) AS gesamt,
                        SUM(active) AS aktiv
                   FROM gemeinden
                  GROUP BY bundesland
                  ORDER BY bundesland COLLATE NOCASE'
            ),
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'filters'    => ['q' => $search, 'bundesland' => $bundesland, 'nur_aktive' => $onlyActive],
            'aktivGesamt' => (int) Database::value('SELECT COUNT(*) FROM gemeinden WHERE active = 1'),
        ], 'layouts/admin');
    }

    /** Einzelne Gemeinde in der Auswahlliste an- oder abschalten. */
    public function toggle(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id       = post_int('id');
        $gemeinde = Database::one('SELECT * FROM gemeinden WHERE id = ?', [$id]);

        if ($gemeinde === null) {
            Flash::error('Gemeinde nicht gefunden.');
            Url::redirectRaw(post('return_to') ?: url('/admin/gemeinden'));
        }

        $neu = (int) $gemeinde['active'] === 1 ? 0 : 1;

        Database::update('gemeinden', $id, ['active' => $neu]);
        Audit::log('gemeinde_toggled', 'gemeinde', $id, $gemeinde['name'] . ' → ' . ($neu ? 'aktiv' : 'inaktiv'));

        Flash::success(sprintf(
            '„%s“ ist %s in der Auswahlliste.',
            $gemeinde['name'],
            $neu ? 'jetzt' : 'nicht mehr'
        ));

        Url::redirectRaw(post('return_to') ?: url('/admin/gemeinden'));
    }

    /** Ganzes Bundesland auf einmal freischalten oder ausblenden. */
    public function toggleBundesland(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $bundesland = post('bundesland');
        $aktiv      = post('aktiv') === '1' ? 1 : 0;

        if ($bundesland === '') {
            Url::redirect('/admin/gemeinden');
        }

        Database::run('UPDATE gemeinden SET active = ? WHERE bundesland = ?', [$aktiv, $bundesland]);

        $anzahl = (int) Database::value('SELECT COUNT(*) FROM gemeinden WHERE bundesland = ?', [$bundesland]);

        Audit::log('gemeinde_bundesland', 'gemeinde', null, $bundesland . ' → ' . ($aktiv ? 'aktiv' : 'inaktiv'));
        Flash::success(sprintf(
            '%d Gemeinden in %s %s.',
            $anzahl,
            $bundesland,
            $aktiv ? 'freigeschaltet' : 'ausgeblendet'
        ));

        Url::redirect('/admin/gemeinden');
    }

    /** Eintrag von Hand ergaenzen – etwa fuer Mitglieder mit Wohnsitz im Ausland. */
    public function store(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $name = post('name');

        if ($name === '') {
            Flash::error('Bitte einen Namen angeben.');
            Url::redirect('/admin/gemeinden');
        }

        $bundesland = post('bundesland', 'sonstige') ?: 'sonstige';

        $vorhanden = Database::one(
            'SELECT id FROM gemeinden WHERE name = ? COLLATE NOCASE AND bundesland = ?',
            [$name, $bundesland]
        );

        if ($vorhanden !== null) {
            Database::update('gemeinden', (int) $vorhanden['id'], ['active' => 1]);
            Flash::info('„' . $name . '“ gab es bereits und ist jetzt freigeschaltet.');
            Url::redirect('/admin/gemeinden');
        }

        Database::insert('gemeinden', [
            'gkz'        => '',
            'name'       => $name,
            'plz'        => post('plz'),
            'bundesland' => $bundesland,
            'active'     => 1,
            'sort_order' => 0,
        ]);

        Audit::log('gemeinde_added', 'gemeinde', null, $name);
        Flash::success('„' . $name . '“ hinzugefügt und freigeschaltet.');
        Url::redirect('/admin/gemeinden');
    }

    /** Nur selbst angelegte Eintraege loeschen; amtliche werden ausgeblendet. */
    public function destroy(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id       = post_int('id');
        $gemeinde = Database::one('SELECT * FROM gemeinden WHERE id = ?', [$id]);

        if ($gemeinde === null) {
            Url::redirect('/admin/gemeinden');
        }

        if ((string) $gemeinde['gkz'] !== '') {
            Flash::error('Amtliche Gemeinden werden nicht gelöscht, sondern nur ausgeblendet.');
            Url::redirect('/admin/gemeinden');
        }

        $inUse = (int) Database::value(
            'SELECT COUNT(*) FROM members WHERE gemeinde = ? AND deleted_at IS NULL',
            [(string) $gemeinde['name']]
        );

        Database::run('DELETE FROM gemeinden WHERE id = ?', [$id]);
        Audit::log('gemeinde_deleted', 'gemeinde', $id, (string) $gemeinde['name']);

        if ($inUse > 0) {
            Flash::info(sprintf(
                'Eintrag entfernt. %d Mitglied(er) tragen diese Gemeinde weiterhin – die Daten bleiben unverändert.',
                $inUse
            ));
        } else {
            Flash::success('Eintrag entfernt.');
        }

        Url::redirect('/admin/gemeinden');
    }
}
