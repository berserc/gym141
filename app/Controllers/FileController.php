<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;

/**
 * Zentrale Dateiablage mit Ordnern (Dateibrowser aehnlich IMCE).
 *
 * Dateien werden EINMAL hochgeladen und koennen dann an mehreren Stellen
 * (Erfolge, Fixkosten, Vereinsdokumente) ausgewaehlt statt erneut hochgeladen
 * werden. Ordner sind rein virtuell; die Dateien liegen flach mit Zufallsnamen
 * unter data/dateien/ AUSSERHALB des Document-Roots.
 */
final class FileController
{
    private const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'svg',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'mp4', 'mov', 'mp3',
    ];

    private const MAX_BYTES = 50 * 1024 * 1024;

    /** Tabellen, die Dateien aus der Ablage referenzieren koennen. */
    private const REF_TABLES = [
        'achievement_media' => 'Erfolge',
        'fixed_cost_files'  => 'Fixkosten',
        'club_documents'    => 'Vereinsdokumente',
    ];

    public static function dir(): string
    {
        return BASE_ROOT . '/data/dateien';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM media_files WHERE id = ?', [$id]);
    }

    /** Absoluter Pfad einer Ablage-Datei. */
    public static function path(array $file): string
    {
        return self::dir() . '/' . $file['stored_name'];
    }

    // ------------------------------------------------------------------ Browser --

    public function index(): void
    {
        AuthController::requireLogin();

        $folderId = (int) query('ordner');
        $picker   = query('picker') === '1';
        $suche    = query('q');

        $folder = $folderId > 0
            ? Database::one('SELECT * FROM media_folders WHERE id = ?', [$folderId])
            : null;

        if ($folder === null) {
            $folderId = 0;
        }

        // Brotkrumen bis zur Wurzel aufbauen.
        $crumbs = [];
        $cursor = $folder;

        while ($cursor !== null) {
            array_unshift($crumbs, $cursor);
            $cursor = $cursor['parent_id'] === null
                ? null
                : Database::one('SELECT * FROM media_folders WHERE id = ?', [(int) $cursor['parent_id']]);
        }

        if ($suche !== '') {
            $files      = Database::all(
                "SELECT f.*, u.username AS uploaded_by_name,
                        (SELECT name FROM media_folders mf WHERE mf.id = f.folder_id) AS folder_name
                   FROM media_files f
                   LEFT JOIN users u ON u.id = f.uploaded_by
                  WHERE f.filename LIKE ?
                  ORDER BY f.filename COLLATE NOCASE",
                ['%' . $suche . '%']
            );
            $subfolders = [];
        } else {
            $files = Database::all(
                'SELECT f.*, u.username AS uploaded_by_name, NULL AS folder_name
                   FROM media_files f
                   LEFT JOIN users u ON u.id = f.uploaded_by
                  WHERE f.folder_id ' . ($folderId > 0 ? '= ?' : 'IS NULL') . '
                  ORDER BY f.filename COLLATE NOCASE',
                $folderId > 0 ? [$folderId] : []
            );

            $subfolders = Database::all(
                'SELECT mf.*,
                        (SELECT COUNT(*) FROM media_files x WHERE x.folder_id = mf.id) AS file_count,
                        (SELECT COUNT(*) FROM media_folders y WHERE y.parent_id = mf.id) AS folder_count
                   FROM media_folders mf
                  WHERE mf.parent_id ' . ($folderId > 0 ? '= ?' : 'IS NULL') . '
                  ORDER BY mf.name COLLATE NOCASE',
                $folderId > 0 ? [$folderId] : []
            );
        }

        View::display('admin/files/index', [
            'title'      => 'Dateien',
            'folderId'   => $folderId,
            'crumbs'     => $crumbs,
            'subfolders' => $subfolders,
            'files'      => $files,
            'picker'     => $picker,
            'suche'      => $suche,
        ], $picker ? 'layouts/admin-blank' : 'layouts/admin');
    }

    // ------------------------------------------------------------------- Ordner --

    public function createFolder(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $name   = post('name');
        $parent = post_int('parent_id');

        if ($name === '') {
            Flash::error('Bitte einen Ordnernamen angeben.');
            $this->back($parent);
        }

        Database::insert('media_folders', [
            'name'      => $name,
            'parent_id' => $parent > 0 ? $parent : null,
        ]);

        Flash::success('Ordner „' . $name . '“ angelegt.');
        $this->back($parent);
    }

    public function renameFolder(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $folder = Database::one('SELECT * FROM media_folders WHERE id = ?', [post_int('folder_id')]);
        $name   = post('name');

        if ($folder !== null && $name !== '') {
            Database::update('media_folders', (int) $folder['id'], ['name' => $name]);
            Flash::success('Ordner umbenannt.');
        }

        $this->back((int) ($folder['parent_id'] ?? 0));
    }

    public function deleteFolder(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $folder = Database::one('SELECT * FROM media_folders WHERE id = ?', [post_int('folder_id')]);

        if ($folder === null) {
            $this->back(0);
        }

        $inhalt = (int) Database::value('SELECT COUNT(*) FROM media_files WHERE folder_id = ?', [(int) $folder['id']])
            + (int) Database::value('SELECT COUNT(*) FROM media_folders WHERE parent_id = ?', [(int) $folder['id']]);

        if ($inhalt > 0) {
            Flash::error('Der Ordner ist nicht leer – bitte zuerst Inhalt löschen oder verschieben.');
            $this->back((int) $folder['id']);
        }

        Database::run('DELETE FROM media_folders WHERE id = ?', [(int) $folder['id']]);
        Flash::success('Ordner gelöscht.');
        $this->back((int) ($folder['parent_id'] ?? 0));
    }

    // ------------------------------------------------------------------- Dateien --

    /** Upload (auch mehrere Dateien auf einmal). */
    public function upload(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $folderId = post_int('folder_id');
        $batch    = $_FILES['files'] ?? null;

        if (!is_array($batch) || !is_array($batch['name'] ?? null) || $batch['name'] === []) {
            Flash::error('Bitte mindestens eine Datei auswählen.');
            $this->back($folderId);
        }

        $dir = self::dir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Flash::error('Ablageverzeichnis konnte nicht angelegt werden.');
            $this->back($folderId);
        }

        $ok     = 0;
        $fehler = [];

        foreach ($batch['name'] as $i => $original) {
            $original = (string) $original;

            if ((int) $batch['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ((int) $batch['error'][$i] !== UPLOAD_ERR_OK) {
                $fehler[] = $original . ' (Upload-Fehler ' . (int) $batch['error'][$i] . ')';
                continue;
            }

            if ((int) $batch['size'][$i] > self::MAX_BYTES) {
                $fehler[] = $original . ' (größer als 50 MB)';
                continue;
            }

            $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

            if (!in_array($extension, self::EXTENSIONS, true)) {
                $fehler[] = $original . ' (Dateityp nicht erlaubt)';
                continue;
            }

            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

            if (!move_uploaded_file((string) $batch['tmp_name'][$i], $dir . '/' . $storedName)) {
                $fehler[] = $original . ' (konnte nicht gespeichert werden)';
                continue;
            }

            $mime = class_exists(\finfo::class)
                ? ((string) (new \finfo(FILEINFO_MIME_TYPE))->file($dir . '/' . $storedName) ?: 'application/octet-stream')
                : 'application/octet-stream';

            Database::insert('media_files', [
                'folder_id'   => $folderId > 0 ? $folderId : null,
                'filename'    => $original,
                'stored_name' => $storedName,
                'mime'        => $mime,
                'size'        => (int) $batch['size'][$i],
                'uploaded_by' => Auth::id(),
            ]);
            $ok++;
        }

        if ($ok > 0) {
            Audit::log('media_uploaded', 'media', null, $ok . ' Datei(en)');
            Flash::success($ok . ' Datei(en) hochgeladen.');
        }

        if ($fehler !== []) {
            Flash::error('Nicht übernommen: ' . implode(', ', $fehler));
        }

        $this->back($folderId);
    }

    public function renameFile(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $file = self::find(post_int('file_id'));
        $name = post('filename');

        if ($file !== null && $name !== '') {
            // Endung beibehalten, damit Typ und Auslieferung konsistent bleiben.
            $extension = strtolower((string) pathinfo((string) $file['filename'], PATHINFO_EXTENSION));

            if ($extension !== '' && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
                $name .= '.' . $extension;
            }

            Database::update('media_files', (int) $file['id'], ['filename' => $name]);
            Flash::success('Datei umbenannt.');
        }

        $this->back((int) ($file['folder_id'] ?? 0));
    }

    public function deleteFile(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $file = self::find(post_int('file_id'));

        if ($file === null) {
            $this->back(0);
        }

        // Referenzierte Dateien duerfen nicht geloescht werden – sonst
        // zerbraechen die Anhaenge an Erfolgen, Fixkosten oder Dokumenten.
        $verwendet = [];

        foreach (self::REF_TABLES as $tabelle => $label) {
            if ((int) Database::value("SELECT COUNT(*) FROM $tabelle WHERE media_file_id = ?", [(int) $file['id']]) > 0) {
                $verwendet[] = $label;
            }
        }

        if ($verwendet !== []) {
            Flash::error(sprintf(
                '„%s“ wird noch verwendet (%s) – bitte zuerst die Verknüpfungen entfernen.',
                $file['filename'],
                implode(', ', $verwendet)
            ));
            $this->back((int) ($file['folder_id'] ?? 0));
        }

        $pfad = self::path($file);

        if (is_file($pfad)) {
            @unlink($pfad);
        }

        Database::run('DELETE FROM media_files WHERE id = ?', [(int) $file['id']]);

        Audit::log('media_deleted', 'media', (int) $file['id'], (string) $file['filename']);
        Flash::success('Datei gelöscht.');
        $this->back((int) ($file['folder_id'] ?? 0));
    }

    /** Liefert eine Datei aus – nur fuer angemeldete Verwaltungsbenutzer. */
    public function serve(array $args): void
    {
        AuthController::requireLogin();

        $file = self::find((int) ($args['id'] ?? 0));
        $pfad = $file === null ? '' : self::path($file);

        if ($file === null || !is_file($pfad)) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        $mime   = (string) ($file['mime'] ?: 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf'
            || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($pfad));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode((string) $file['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        readfile($pfad);
        exit;
    }

    // ------------------------------------------------------------------- Hilfen --

    /** Zurueck in den Browser – Ordner und Picker-Modus bleiben erhalten. */
    private function back(int $folderId): void
    {
        $query = [];

        if ($folderId > 0) {
            $query['ordner'] = $folderId;
        }

        if (post('picker') === '1' || query('picker') === '1') {
            $query['picker'] = 1;
        }

        Url::redirect('/admin/dateien' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }
}
