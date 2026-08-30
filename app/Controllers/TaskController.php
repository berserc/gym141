<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;

/**
 * Aufgaben – Task141, fest in Gym141 eingebaut: Checklisten, Anhaenge
 * (Dokumente/Fotos/Videos) und Freigabe-Links, mit denen Externe ohne
 * Vereins-Zugang mitarbeiten (abhaken, Dateien hochladen).
 *
 * Jede Aufgabe traegt updated_at als Konfliktbasis: schreibende API-Zugriffe
 * der Apps koennen ihre Basis mitschicken und erhalten 409, wenn der Stand
 * am Server neuer ist - entschieden wird dann vom Ersteller (siehe Apps).
 */
final class TaskController
{
    /** Erlaubte Anhangstypen (Endung => MIME). */
    public const FILE_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ];

    public const MAX_FILE_BYTES = 200 * 1024 * 1024;

    // ------------------------------------------------------------- Verwaltung --

    public function index(): void
    {
        AuthController::requireLogin();

        View::display('admin/tasks/index', [
            'title' => 'Aufgaben',
            'tasks' => Database::all(
                'SELECT t.*, u.name AS ersteller,
                        (SELECT COUNT(*) FROM club_task_items i WHERE i.task_id = t.id) AS n_items,
                        (SELECT COUNT(*) FROM club_task_items i WHERE i.task_id = t.id AND i.done = 1) AS n_done,
                        (SELECT COUNT(*) FROM club_task_files f WHERE f.task_id = t.id) AS n_files
                   FROM club_tasks t
                   LEFT JOIN users u ON u.id = t.created_by
                  ORDER BY t.status, COALESCE(t.due_date, \'9999\'), t.id DESC'
            ),
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $titel = trim(post('title'));

        if ($titel === '') {
            Flash::error('Bitte einen Titel angeben.');
            Url::redirect('/admin/aufgaben');
        }

        $id = Database::insert('club_tasks', [
            'title'      => mb_substr($titel, 0, 200),
            'due_date'   => post('due_date') ?: null,
            'created_by' => Auth::id(),
        ]);

        Audit::log('task_create', 'club_tasks', $id, $titel);
        Url::redirect('/admin/aufgaben/' . $id);
    }

    public function show(array $args): void
    {
        AuthController::requireLogin();

        $task = $this->find((int) ($args['id'] ?? 0));

        View::display('admin/tasks/show', [
            'title' => $task['title'],
            'task'  => $task,
            'items' => Database::all('SELECT * FROM club_task_items WHERE task_id = ? ORDER BY sort, id', [(int) $task['id']]),
            'files' => Database::all('SELECT * FROM club_task_files WHERE task_id = ? ORDER BY id', [(int) $task['id']]),
        ], 'layouts/admin');
    }

    public function update(array $args): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $task = $this->find((int) ($args['id'] ?? 0));
        $id   = (int) $task['id'];

        if (post('aktion') === 'status') {
            Database::update('club_tasks', $id, [
                'status'     => post('status') === 'erledigt' ? 'erledigt' : 'offen',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } elseif (post('aktion') === 'stammdaten') {
            Database::update('club_tasks', $id, [
                'title'       => mb_substr(trim(post('title')) ?: (string) $task['title'], 0, 200),
                'description' => mb_substr(post('description'), 0, 4000),
                'due_date'    => post('due_date') ?: null,
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } elseif (post('aktion') === 'item_neu' && trim(post('titel')) !== '') {
            Database::insert('club_task_items', [
                'task_id' => $id,
                'title'   => mb_substr(trim(post('titel')), 0, 200),
                'sort'    => (int) Database::value('SELECT COALESCE(MAX(sort),0)+1 FROM club_task_items WHERE task_id = ?', [$id]),
            ]);
            $this->touch($id);
        } elseif (post('aktion') === 'item_umschalten') {
            Database::run('UPDATE club_task_items SET done = 1 - done WHERE id = ? AND task_id = ?', [post_int('item'), $id]);
            $this->touch($id);
        } elseif (post('aktion') === 'item_loeschen') {
            Database::run('DELETE FROM club_task_items WHERE id = ? AND task_id = ?', [post_int('item'), $id]);
            $this->touch($id);
        } elseif (post('aktion') === 'upload' && isset($_FILES['datei'])) {
            [$ok, $meldung] = self::storeUpload($id, $_FILES['datei'], (string) (Auth::user()['name'] ?? 'Verwaltung'));

            if (!$ok) {
                Flash::error($meldung);
            }
        } elseif (post('aktion') === 'datei_loeschen') {
            $f = Database::one('SELECT * FROM club_task_files WHERE id = ? AND task_id = ?', [post_int('datei'), $id]);

            if ($f !== null) {
                @unlink(self::uploadDir($id) . '/' . $f['stored_as']);
                Database::run('DELETE FROM club_task_files WHERE id = ?', [(int) $f['id']]);
            }
        } elseif (post('aktion') === 'freigabe') {
            Database::update('club_tasks', $id, [
                'share_token' => post('an') === '1' ? bin2hex(random_bytes(16)) : null,
            ]);
            Audit::log('task_share', 'club_tasks', $id, post('an') === '1' ? 'freigegeben' : 'Freigabe beendet');
        } elseif (post('aktion') === 'loeschen') {
            foreach (Database::all('SELECT stored_as FROM club_task_files WHERE task_id = ?', [$id]) as $f) {
                @unlink(self::uploadDir($id) . '/' . $f['stored_as']);
            }

            @rmdir(self::uploadDir($id));
            Database::run('DELETE FROM club_tasks WHERE id = ?', [$id]);
            Audit::log('task_delete', 'club_tasks', $id, (string) $task['title']);
            Flash::success('Aufgabe gelöscht.');
            Url::redirect('/admin/aufgaben');
        }

        Url::redirect('/admin/aufgaben/' . $id);
    }

    // ----------------------------------------------------- Freigabe (extern) --

    /** Oeffentliche Freigabe-Seite: sehen, abhaken, Dateien hochladen. */
    public function share(array $args): void
    {
        $task = $this->sharedTask((string) ($args['token'] ?? ''));

        if ($task === null) {
            http_response_code(404);
            exit('Freigabe nicht gefunden oder beendet.');
        }

        View::display('public/task-share', [
            'title' => $task['title'],
            'task'  => $task,
            'token' => (string) $args['token'],
            'items' => Database::all('SELECT * FROM club_task_items WHERE task_id = ? ORDER BY sort, id', [(int) $task['id']]),
            'files' => Database::all('SELECT * FROM club_task_files WHERE task_id = ? ORDER BY id', [(int) $task['id']]),
        ], 'layouts/public');
    }

    public function shareAction(array $args): void
    {
        $task = $this->sharedTask((string) ($args['token'] ?? ''));

        if ($task === null) {
            http_response_code(404);
            exit('Freigabe nicht gefunden.');
        }

        $id = (int) $task['id'];

        if (post('item') !== '') {
            Database::run('UPDATE club_task_items SET done = 1 - done WHERE id = ? AND task_id = ?', [post_int('item'), $id]);
            $this->touch($id);
        } elseif (isset($_FILES['datei'])) {
            $von = trim(post('von')) ?: 'Extern';
            [$ok, $meldung] = self::storeUpload($id, $_FILES['datei'], $von . ' (extern)');

            if (!$ok) {
                // Externe haben keine Session – Fehlermeldung ueber die URL.
                Url::redirect('/f/' . $args['token'], ['fehler' => $meldung]);
            }
        }

        Url::redirect('/f/' . $args['token']);
    }

    /** Datei ausliefern: Verwaltung (Session) oder gueltiger Freigabe-Token. */
    public function file(array $args): void
    {
        $file = Database::one(
            'SELECT f.*, t.share_token FROM club_task_files f JOIN club_tasks t ON t.id = f.task_id WHERE f.id = ?',
            [(int) ($args['id'] ?? 0)]
        );

        $token = (string) ($args['token'] ?? '');
        $ok    = $file !== null && (
            Auth::check()
            || ($token !== '' && $file['share_token'] !== null && hash_equals((string) $file['share_token'], $token))
        );

        $pfad = $ok ? self::uploadDir((int) $file['task_id']) . '/' . $file['stored_as'] : '';

        if (!$ok || !is_file($pfad)) {
            http_response_code(404);
            exit('Datei nicht gefunden.');
        }

        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . (string) filesize($pfad));
        header('Content-Disposition: inline; filename="' . rawurlencode((string) $file['filename']) . '"');
        readfile($pfad);
        exit;
    }

    // ---------------------------------------------------------------- Intern --

    /** @return array{0: bool, 1: string} */
    public static function storeUpload(int $taskId, array $file, string $von): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, 'Upload fehlgeschlagen – ist die Datei zu groß für den Server?'];
        }

        if ((int) $file['size'] > self::MAX_FILE_BYTES) {
            return [false, 'Datei zu groß (max. 200 MB).'];
        }

        $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset(self::FILE_TYPES[$ext])) {
            return [false, 'Dateityp .' . $ext . ' ist nicht erlaubt.'];
        }

        $dir = self::uploadDir($taskId);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [false, 'Ablage nicht beschreibbar.'];
        }

        $intern = bin2hex(random_bytes(16)) . '.' . $ext;

        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $intern)) {
            return [false, 'Datei konnte nicht gespeichert werden.'];
        }

        Database::insert('club_task_files', [
            'task_id'     => $taskId,
            'filename'    => mb_substr((string) $file['name'], 0, 150),
            'stored_as'   => $intern,
            'mime'        => self::FILE_TYPES[$ext],
            'size'        => (int) $file['size'],
            'uploaded_by' => mb_substr($von, 0, 80),
        ]);

        Database::run("UPDATE club_tasks SET updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), $taskId]);

        return [true, (string) $file['name']];
    }

    public static function uploadDir(int $taskId): string
    {
        return dirname((string) Config::get('db_path')) . '/aufgaben/' . $taskId;
    }

    private function find(int $id): array
    {
        $task = Database::one('SELECT * FROM club_tasks WHERE id = ?', [$id]);

        if ($task === null) {
            Flash::error('Aufgabe nicht gefunden.');
            Url::redirect('/admin/aufgaben');
        }

        return $task;
    }

    private function sharedTask(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token)
            ? Database::one('SELECT * FROM club_tasks WHERE share_token = ?', [$token])
            : null;
    }

    private function touch(int $id): void
    {
        Database::run('UPDATE club_tasks SET updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }
}
