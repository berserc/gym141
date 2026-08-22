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
 * Vereinshistorie (Rechnungspruefung, Vorstandssitzung, Mitglieder- und
 * Generalversammlung ...) und Dokumentenarchiv (Statuten, Protokolle ...).
 *
 * Zugriff hat nur der Vorstand – im Rollenmodell: Superuser und Kassier.
 * Die Dateien liegen unter data/verein/ AUSSERHALB des Document-Roots und
 * werden ausschliesslich ueber die angemeldete Verwaltung ausgeliefert.
 */
final class ClubController
{
    public const EVENT_TYPES = [
        'Rechnungsprüfung',
        'Vorstandssitzung',
        'Mitgliederversammlung',
        'Generalversammlung',
        'Sonstiges',
    ];

    private const FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'ppt', 'pptx', 'txt', 'csv',
    ];

    private const FILE_MAX_BYTES = 25 * 1024 * 1024;

    private function requireBoard(): void
    {
        AuthController::requireRole('superuser', 'kassier');
    }

    private function fileDir(): string
    {
        return BASE_ROOT . '/data/verein';
    }

    // ------------------------------------------------------- Vereinshistorie --

    public function index(): void
    {
        $this->requireBoard();

        $events = Database::all(
            'SELECT e.*, u.username AS created_by_name,
                    (SELECT COUNT(*) FROM club_documents d WHERE d.event_id = e.id) AS doc_count
               FROM club_events e
               LEFT JOIN users u ON u.id = e.created_by
              ORDER BY e.event_date DESC, e.id DESC'
        );

        $dokumente = [];

        foreach (Database::all(
            'SELECT * FROM club_documents WHERE event_id IS NOT NULL ORDER BY doc_date DESC, id DESC'
        ) as $doc) {
            $dokumente[(int) $doc['event_id']][] = $doc;
        }

        $links = [];

        foreach (Database::all('SELECT * FROM club_event_links ORDER BY id') as $link) {
            $links[(int) $link['event_id']][] = $link;
        }

        View::display('admin/club/events', [
            'title'     => 'Vereinshistorie',
            'events'    => $events,
            'dokumente' => $dokumente,
            'links'     => $links,
            'types'     => self::EVENT_TYPES,
            'errors'    => Flash::errors(),
        ], 'layouts/admin');
    }

    public function storeEvent(): void
    {
        $this->requireBoard();
        Csrf::verify();

        $type = post('type');

        if ($type === '') {
            $type = 'Sonstiges';
        }

        $date = parse_date(post('event_date'));

        if ($date === null) {
            Flash::error('Bitte ein gültiges Datum angeben.');
            Url::redirect('/admin/verein');
        }

        $id = Database::insert('club_events', [
            'type'       => $type,
            'event_date' => $date,
            'title'      => post('title'),
            'text'       => post('text'),
            'created_by' => Auth::id(),
        ]);

        // Optional gleich ein Dokument mit hochladen
        if (is_array($_FILES['file'] ?? null) && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $fehler = $this->storeUpload($_FILES['file'], $id, $date, post('title') !== '' ? post('title') : $type, '');

            if ($fehler !== null) {
                Flash::error('Ereignis angelegt, aber: ' . $fehler);
                Url::redirect('/admin/verein');
            }
        }

        Audit::log('club_event_created', 'club', $id, $type . ' ' . $date);
        Flash::success($type . ' am ' . format_date($date) . ' erfasst.');
        Url::redirect('/admin/verein');
    }

    /** @param array<string,string> $args */
    public function updateEvent(array $args): void
    {
        $this->requireBoard();
        Csrf::verify();

        $id    = (int) ($args['id'] ?? 0);
        $event = $this->findEvent($id);

        Database::update('club_events', $id, [
            'type'       => post('type') ?: (string) $event['type'],
            'event_date' => parse_date(post('event_date')) ?? (string) $event['event_date'],
            'title'      => post('title'),
            'text'       => post('text'),
        ]);

        Audit::log('club_event_updated', 'club', $id, (string) $event['type']);
        Flash::success('Ereignis gespeichert.');
        Url::redirect('/admin/verein');
    }

    /** @param array<string,string> $args */
    public function deleteEvent(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id    = (int) ($args['id'] ?? 0);
        $event = $this->findEvent($id);

        $anzahl = (int) Database::value('SELECT COUNT(*) FROM club_documents WHERE event_id = ?', [$id]);

        // Dokumente bleiben im Archiv erhalten (event_id wird NULL).
        Database::run('DELETE FROM club_events WHERE id = ?', [$id]);

        Audit::log('club_event_deleted', 'club', $id, (string) $event['type']);
        Flash::success('Ereignis gelöscht.' . ($anzahl > 0 ? " $anzahl Dokument(e) bleiben im Archiv erhalten." : ''));
        Url::redirect('/admin/verein');
    }

    /** Dokument zu einem bestehenden Ereignis hochladen oder aus der Ablage verknuepfen. */
    public function uploadEventDocument(array $args): void
    {
        $this->requireBoard();
        Csrf::verify();

        $id    = (int) ($args['id'] ?? 0);
        $event = $this->findEvent($id);

        $hasFile = is_array($_FILES['file'] ?? null)
            && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        $fehler = !$hasFile && post_int('media_file_id') > 0
            ? $this->storeFromLibrary(
                post_int('media_file_id'),
                $id,
                parse_date(post('doc_date')) ?? (string) $event['event_date'],
                post('title') !== '' ? post('title') : (string) $event['type'],
                post('description')
            )
            : $this->storeUpload(
                $_FILES['file'] ?? null,
                $id,
                parse_date(post('doc_date')) ?? (string) $event['event_date'],
                post('title') !== '' ? post('title') : (string) $event['type'],
                post('description')
            );

        if ($fehler !== null) {
            Flash::error($fehler);
        } else {
            Flash::success('Dokument hochgeladen.');
        }

        Url::redirect('/admin/verein');
    }

    /** Link (Ergebnisliste, Video ...) zu einem Ereignis speichern. */
    public function storeEventLink(array $args): void
    {
        $this->requireBoard();
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findEvent($id);

        $link = trim(post('url'));

        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            $link = 'https://' . $link;
        }

        if ($link === '' || filter_var($link, FILTER_VALIDATE_URL) === false) {
            Flash::error('Bitte eine gültige Adresse (http/https) angeben.');
            Url::redirect('/admin/verein');
        }

        Database::insert('club_event_links', [
            'event_id' => $id,
            'label'    => post('label'),
            'url'      => $link,
        ]);

        Flash::success('Link gespeichert.');
        Url::redirect('/admin/verein');
    }

    public function deleteEventLink(array $args): void
    {
        $this->requireBoard();
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findEvent($id);

        Database::run(
            'DELETE FROM club_event_links WHERE id = ? AND event_id = ?',
            [post_int('link_id'), $id]
        );

        Flash::success('Link entfernt.');
        Url::redirect('/admin/verein');
    }

    // ------------------------------------------------------ Dokumentenarchiv --

    public function documents(): void
    {
        $this->requireBoard();

        $filters = ['q' => query('q'), 'type' => query('type')];

        $conditions = ['1 = 1'];
        $params     = [];

        if ($filters['q'] !== '') {
            $like         = '%' . $filters['q'] . '%';
            $conditions[] = '(d.title LIKE ? OR d.description LIKE ? OR d.filename LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        if ($filters['type'] !== '') {
            $conditions[] = 'e.type = ?';
            $params[]     = $filters['type'];
        }

        $where = implode(' AND ', $conditions);

        View::display('admin/club/documents', [
            'title'   => 'Dokumentenarchiv',
            'filters' => $filters,
            'types'   => self::EVENT_TYPES,
            'docs'    => Database::all(
                "SELECT d.*, e.type AS event_type, e.event_date, e.title AS event_title,
                        u.username AS uploaded_by_name
                   FROM club_documents d
                   LEFT JOIN club_events e ON e.id = d.event_id
                   LEFT JOIN users u ON u.id = d.uploaded_by
                  WHERE $where
                  ORDER BY d.doc_date DESC, d.id DESC",
                $params
            ),
            'errors'  => Flash::errors(),
        ], 'layouts/admin');
    }

    /** Dokument direkt ins Archiv hochladen (z. B. Statuten). */
    public function storeDocument(): void
    {
        $this->requireBoard();
        Csrf::verify();

        $title = post('title');

        if ($title === '') {
            Flash::error('Bitte einen Titel angeben (z. B. "Statuten 2026").');
            Url::redirect('/admin/verein/dokumente');
        }

        $hasFile = is_array($_FILES['file'] ?? null)
            && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        $fehler = !$hasFile && post_int('media_file_id') > 0
            ? $this->storeFromLibrary(
                post_int('media_file_id'),
                null,
                parse_date(post('doc_date')) ?? date('Y-m-d'),
                $title,
                post('description')
            )
            : $this->storeUpload(
                $_FILES['file'] ?? null,
                null,
                parse_date(post('doc_date')) ?? date('Y-m-d'),
                $title,
                post('description')
            );

        if ($fehler !== null) {
            Flash::error($fehler);
        } else {
            Flash::success('Dokument „' . $title . '“ im Archiv abgelegt.');
        }

        Url::redirect('/admin/verein/dokumente');
    }

    /** Titel/Datum/Beschreibung eines Dokuments aendern. */
    public function updateDocument(array $args): void
    {
        $this->requireBoard();
        Csrf::verify();

        $id  = (int) ($args['id'] ?? 0);
        $doc = $this->findDocument($id);

        Database::update('club_documents', $id, [
            'title'       => post('title') ?: (string) $doc['title'],
            'doc_date'    => parse_date(post('doc_date')) ?? (string) $doc['doc_date'],
            'description' => post('description'),
        ]);

        Flash::success('Dokument-Infos gespeichert.');
        Url::redirect(post('return') === 'verein' ? '/admin/verein' : '/admin/verein/dokumente');
    }

    public function deleteDocument(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id  = (int) ($args['id'] ?? 0);
        $doc = $this->findDocument($id);

        $path = $this->fileDir() . '/' . $doc['stored_name'];

        if (is_file($path)) {
            @unlink($path);
        }

        Database::run('DELETE FROM club_documents WHERE id = ?', [$id]);

        Audit::log('club_document_deleted', 'club', $id, (string) $doc['title']);
        Flash::success('Dokument gelöscht.');
        Url::redirect(post('return') === 'verein' ? '/admin/verein' : '/admin/verein/dokumente');
    }

    /** Liefert ein Vereinsdokument aus – nur fuer den Vorstand. */
    public function serveDocument(array $args): void
    {
        $this->requireBoard();

        $doc = Database::one('SELECT * FROM club_documents WHERE id = ?', [(int) ($args['id'] ?? 0)]);

        // Referenz auf die zentrale Ablage? Dann von dort ausliefern.
        if ($doc !== null && (int) ($doc['media_file_id'] ?? 0) > 0) {
            $zentral = FileController::find((int) $doc['media_file_id']);

            if ($zentral !== null) {
                $doc['filename'] = $zentral['filename'];
                $doc['mime']     = $zentral['mime'];
                $path            = FileController::path($zentral);
            } else {
                $path = '';
            }
        } else {
            $path = $doc === null ? '' : $this->fileDir() . '/' . $doc['stored_name'];
        }

        if ($doc === null || !is_file($path)) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Dokument nicht gefunden'], 'layouts/admin');
            exit;
        }

        $mime   = (string) ($doc['mime'] ?: 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode((string) $doc['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ Hilfen --

    /**
     * Upload pruefen und ablegen.
     *
     * @param array<string,mixed>|null $file $_FILES-Eintrag
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    private function storeUpload(?array $file, ?int $eventId, string $docDate, string $title, string $description): ?string
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Bitte eine Datei auswählen.';
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return 'Upload fehlgeschlagen (Fehler ' . (int) $file['error'] . ') – ist die Datei zu groß?';
        }

        if ((int) $file['size'] > self::FILE_MAX_BYTES) {
            return 'Die Datei ist größer als 25 MB.';
        }

        $original  = (string) $file['name'];
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!in_array($extension, self::FILE_EXTENSIONS, true)) {
            return 'Dateityp .' . $extension . ' ist nicht erlaubt.';
        }

        $dir = $this->fileDir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Ablageverzeichnis konnte nicht angelegt werden.';
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $target     = $dir . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            return 'Die Datei konnte nicht gespeichert werden.';
        }

        $mime = class_exists(\finfo::class)
            ? ((string) (new \finfo(FILEINFO_MIME_TYPE))->file($target) ?: 'application/octet-stream')
            : 'application/octet-stream';

        $id = Database::insert('club_documents', [
            'event_id'    => $eventId,
            'doc_date'    => $docDate,
            'title'       => $title !== '' ? $title : $original,
            'description' => $description,
            'filename'    => $original,
            'stored_name' => $storedName,
            'mime'        => $mime,
            'size'        => (int) filesize($target),
            'uploaded_by' => Auth::id(),
        ]);

        Audit::log('club_document_uploaded', 'club', $id, $original);

        return null;
    }

    /**
     * Statt eines Uploads eine Datei aus der zentralen Ablage verknuepfen.
     *
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    private function storeFromLibrary(int $mediaFileId, ?int $eventId, string $docDate, string $title, string $description): ?string
    {
        $zentral = FileController::find($mediaFileId);

        if ($zentral === null) {
            return 'Die Datei aus der Ablage wurde nicht gefunden.';
        }

        $id = Database::insert('club_documents', [
            'event_id'      => $eventId,
            'doc_date'      => $docDate,
            'title'         => $title !== '' ? $title : (string) $zentral['filename'],
            'description'   => $description,
            'filename'      => $zentral['filename'],
            'stored_name'   => '',
            'mime'          => $zentral['mime'],
            'size'          => (int) $zentral['size'],
            'uploaded_by'   => Auth::id(),
            'media_file_id' => (int) $zentral['id'],
        ]);

        Audit::log('club_document_linked', 'club', $id, (string) $zentral['filename']);

        return null;
    }

    /** @return array<string,mixed> */
    private function findEvent(int $id): array
    {
        $event = Database::one('SELECT * FROM club_events WHERE id = ?', [$id]);

        if ($event === null) {
            Flash::error('Ereignis nicht gefunden.');
            Url::redirect('/admin/verein');
        }

        return $event;
    }

    /** @return array<string,mixed> */
    private function findDocument(int $id): array
    {
        $doc = Database::one('SELECT * FROM club_documents WHERE id = ?', [$id]);

        if ($doc === null) {
            Flash::error('Dokument nicht gefunden.');
            Url::redirect('/admin/verein/dokumente');
        }

        return $doc;
    }
}
