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
use App\Models\MemberRepo;
use App\Models\SportRepo;

/**
 * Erfolge und Wettkaempfe: Seite je Mitglied (Kaempfe, Kraftdreikampf,
 * Auszeichnungen) und die Gesamtstatistik mit Splits nach Alters- und
 * Gewichtsklasse.
 */
final class AchievementController
{
    // -------------------------------------------------------- Seite je Mitglied --

    /** @param array<string,string> $args */
    public function memberPage(array $args): void
    {
        AuthController::requireLogin();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        // Links und Dokumente je Eintrag: media[kind][ref_id] = Liste.
        $media = [];

        foreach (Database::all(
            'SELECT * FROM achievement_media WHERE member_id = ? ORDER BY id',
            [$id]
        ) as $m) {
            $media[(string) $m['kind']][(int) $m['ref_id']][] = $m;
        }

        View::display('admin/achievements/member', [
            'title'    => 'Erfolge – ' . $member['first_name'] . ' ' . $member['last_name'],
            'member'   => $member,
            'media'    => $media,
            'record'   => SportRepo::recordForMember($id),
            'fights'   => SportRepo::fightsForMember($id),
            'meets'    => SportRepo::meetsForMember($id),
            'awards'   => SportRepo::awardsForMember($id),
            'canEdit'  => Auth::canWrite(),
            'sports'   => SportRepo::FIGHT_SPORTS,
            'styles'   => SportRepo::STYLES,
            'ageClasses' => SportRepo::AGE_CLASSES,
            'results'  => SportRepo::RESULTS,
            'methods'  => SportRepo::METHODS,
        ], 'layouts/admin');
    }

    // ----------------------------------------------------------------- Kaempfe --

    /** @param array<string,string> $args */
    public function saveFight(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $fightId = (int) ($args['fid'] ?? 0);

        $result = array_key_exists(post('result'), SportRepo::RESULTS) ? post('result') : 'sieg';

        $data = [
            'sport'         => post('sport') ?: 'Boxen',
            'style'         => post('style'),
            'fight_date'    => post('fight_date') === '' ? null : parse_date(post('fight_date')),
            'event'         => post('event'),
            'location'      => post('location'),
            'opponent'      => post('opponent'),
            'opponent_club' => post('opponent_club'),
            'weight_class'  => post('weight_class'),
            'age_class'     => post('age_class'),
            'rounds'        => post('rounds'),
            'result'        => $result,
            'method'        => post('method'),
            'end_round'     => post('end_round') === '' ? null : post_int('end_round'),
            'note'          => post('note'),
        ];

        if ($fightId > 0) {
            $this->findOwned('member_fights', $fightId, $id);
            Database::update('member_fights', $fightId, $data);
            Flash::success('Kampf aktualisiert.');
        } else {
            $data['member_id']  = $id;
            $data['created_by'] = Auth::id();
            Database::insert('member_fights', $data);
            Flash::success('Kampf erfasst (' . SportRepo::RESULTS[$result] . ').');
        }

        Audit::log('fight_saved', 'member', $id, $data['sport'] . ' vs. ' . $data['opponent']);
        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    /** @param array<string,string> $args */
    public function deleteFight(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $this->findOwned('member_fights', post_int('fight_id'), $id);

        Database::run('DELETE FROM member_fights WHERE id = ?', [post_int('fight_id')]);

        Flash::success('Kampf gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    // --------------------------------------------------------- Kraftdreikampf --

    /** @param array<string,string> $args */
    public function saveMeet(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $meetId = (int) ($args['fid'] ?? 0);

        $versuch = static function (string $feld): ?float {
            $wert = str_replace(',', '.', trim(post($feld)));

            return $wert === '' ? null : (float) $wert;
        };

        $data = [
            'meet_date'    => post('meet_date') === '' ? null : parse_date(post('meet_date')),
            'event'        => post('event'),
            'location'     => post('location'),
            'age_class'    => post('age_class'),
            'weight_class' => post('weight_class'),
            'bodyweight'   => $versuch('bodyweight'),
            'squat_1'      => $versuch('squat_1'),
            'squat_2'      => $versuch('squat_2'),
            'squat_3'      => $versuch('squat_3'),
            'bench_1'      => $versuch('bench_1'),
            'bench_2'      => $versuch('bench_2'),
            'bench_3'      => $versuch('bench_3'),
            'dead_1'       => $versuch('dead_1'),
            'dead_2'       => $versuch('dead_2'),
            'dead_3'       => $versuch('dead_3'),
            'points'       => $versuch('points'),
            'placement'    => post('placement'),
            'note'         => post('note'),
        ];

        if ($meetId > 0) {
            $this->findOwned('member_meets', $meetId, $id);
            Database::update('member_meets', $meetId, $data);
            Flash::success('Wettkampf aktualisiert.');
        } else {
            $data['member_id']  = $id;
            $data['created_by'] = Auth::id();
            Database::insert('member_meets', $data);
            Flash::success('Kraftdreikampf-Wettkampf erfasst.');
        }

        Audit::log('meet_saved', 'member', $id, (string) $data['event']);
        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    /** @param array<string,string> $args */
    public function deleteMeet(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $this->findOwned('member_meets', post_int('meet_id'), $id);

        Database::run('DELETE FROM member_meets WHERE id = ?', [post_int('meet_id')]);

        Flash::success('Wettkampf gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    // ---------------------------------------------------------- Auszeichnungen --

    /** @param array<string,string> $args */
    public function saveAward(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $awardId = (int) ($args['fid'] ?? 0);

        $title = post('title');

        if ($title === '') {
            Flash::error('Bitte die Auszeichnung angeben (z. B. "Landesmeister Muay Thai").');
            Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
        }

        $data = [
            'award_date' => post('award_date') === '' ? null : parse_date(post('award_date')),
            'title'      => $title,
            'sport'      => post('sport'),
            'note'       => post('note'),
        ];

        if ($awardId > 0) {
            $this->findOwned('member_awards', $awardId, $id);
            Database::update('member_awards', $awardId, $data);
            Flash::success('Auszeichnung aktualisiert.');
        } else {
            $data['member_id']  = $id;
            $data['created_by'] = Auth::id();
            Database::insert('member_awards', $data);
            Flash::success('Auszeichnung „' . $title . '“ erfasst.');
        }

        Audit::log('award_saved', 'member', $id, $title);
        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    /** @param array<string,string> $args */
    public function deleteAward(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $this->findOwned('member_awards', post_int('award_id'), $id);

        Database::run('DELETE FROM member_awards WHERE id = ?', [post_int('award_id')]);

        Flash::success('Auszeichnung gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    // ------------------------------------------------------------- Statistik --

    public function overview(): void
    {
        AuthController::requireLogin();

        $filters = [
            'sport' => query('sport'),
            'from'  => query('from'),
            'to'    => query('to'),
        ];

        View::display('admin/achievements/overview', [
            'title'        => 'Erfolge & Statistik',
            'filters'      => $filters,
            'sports'       => SportRepo::FIGHT_SPORTS,
            'bySport'      => SportRepo::fightStats('sport', $filters),
            'byAge'        => SportRepo::fightStats('age_class', $filters),
            'byWeight'     => SportRepo::fightStats('weight_class', $filters),
            'fighters'     => SportRepo::fighterList($filters),
            'meetsByAge'    => SportRepo::meetStats('age_class'),
            'meetsByWeight' => SportRepo::meetStats('weight_class'),
            'meetBest'     => SportRepo::meetBestList(),
            'awards'       => SportRepo::latestAwards(),
        ], 'layouts/admin');
    }

    // -------------------------------------------------------- Links & Dokumente --

    private const MEDIA_TABLES = [
        'fight' => 'member_fights',
        'meet'  => 'member_meets',
        'award' => 'member_awards',
    ];

    private const MEDIA_MAX_BYTES  = 15 * 1024 * 1024;
    private const MEDIA_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf',
        'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'csv',
    ];

    /** Link ODER Datei zu einem Kampf/KDK-Wettkampf/einer Auszeichnung. */
    public function saveMedia(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $kind  = post('kind');
        $refId = post_int('ref_id');
        $table = self::MEDIA_TABLES[$kind] ?? null;

        if ($table === null) {
            Flash::error('Unbekannter Eintragstyp.');
            Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
        }

        $this->findOwned($table, $refId, $id);

        $zurueck = '/admin/mitglieder/' . $id . '/erfolge';
        $link    = trim(post('url'));
        $file    = $_FILES['file'] ?? null;
        $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($link !== '') {
            if (!preg_match('#^https?://#i', $link)) {
                $link = 'https://' . $link;
            }

            if (filter_var($link, FILTER_VALIDATE_URL) === false) {
                Flash::error('Bitte eine gültige Adresse (http/https) angeben.');
                Url::redirect($zurueck);
            }

            Database::insert('achievement_media', [
                'member_id' => $id,
                'kind'      => $kind,
                'ref_id'    => $refId,
                'type'      => 'link',
                'label'     => post('label'),
                'url'       => $link,
            ]);

            Flash::success('Link gespeichert.');
            Url::redirect($zurueck);
        }

        // Datei aus der zentralen Ablage verknuepfen statt neu hochzuladen.
        $mediaFileId = post_int('media_file_id');

        if (!$hasFile && $mediaFileId > 0) {
            $zentral = FileController::find($mediaFileId);

            if ($zentral === null) {
                Flash::error('Die Datei aus der Ablage wurde nicht gefunden.');
                Url::redirect($zurueck);
            }

            Database::insert('achievement_media', [
                'member_id'     => $id,
                'kind'          => $kind,
                'ref_id'        => $refId,
                'type'          => 'file',
                'label'         => post('label') !== '' ? post('label') : (string) $zentral['filename'],
                'file_name'     => $zentral['filename'],
                'mime'          => $zentral['mime'],
                'size'          => (int) $zentral['size'],
                'media_file_id' => (int) $zentral['id'],
            ]);

            Flash::success('Datei aus der Ablage verknüpft: ' . $zentral['filename']);
            Url::redirect($zurueck);
        }

        if (!$hasFile) {
            Flash::error('Bitte einen Link eingeben, eine Datei auswählen oder eine Datei aus der Ablage wählen.');
            Url::redirect($zurueck);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            Flash::error('Upload fehlgeschlagen (Fehler ' . (int) $file['error'] . ') – ist die Datei zu groß?');
            Url::redirect($zurueck);
        }

        if ((int) $file['size'] > self::MEDIA_MAX_BYTES) {
            Flash::error('Die Datei ist größer als 15 MB.');
            Url::redirect($zurueck);
        }

        $original  = (string) $file['name'];
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!in_array($extension, self::MEDIA_EXTENSIONS, true)) {
            Flash::error('Dateityp .' . $extension . ' ist nicht erlaubt (Bilder, PDF und Office-Dokumente).');
            Url::redirect($zurueck);
        }

        $dir = BASE_ROOT . '/data/mitglieder/' . $id;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Flash::error('Ablageverzeichnis konnte nicht angelegt werden.');
            Url::redirect($zurueck);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $storedName)) {
            Flash::error('Die Datei konnte nicht gespeichert werden.');
            Url::redirect($zurueck);
        }

        $mime = class_exists(\finfo::class)
            ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($dir . '/' . $storedName)
            : '';

        Database::insert('achievement_media', [
            'member_id' => $id,
            'kind'      => $kind,
            'ref_id'    => $refId,
            'type'      => 'file',
            'label'     => post('label') !== '' ? post('label') : $original,
            'file_path' => $storedName,
            'file_name' => $original,
            'mime'      => $mime !== '' ? $mime : 'application/octet-stream',
            'size'      => (int) $file['size'],
        ]);

        Flash::success('Dokument gespeichert: ' . $original);
        Url::redirect($zurueck);
    }

    public function deleteMedia(array $args): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $media = Database::one(
            'SELECT * FROM achievement_media WHERE id = ? AND member_id = ?',
            [post_int('media_id'), $id]
        );

        if ($media !== null) {
            if ((string) $media['type'] === 'file' && (string) $media['file_path'] !== '') {
                $pfad = BASE_ROOT . '/data/mitglieder/' . $id . '/' . $media['file_path'];

                if (is_file($pfad)) {
                    unlink($pfad);
                }
            }

            Database::run('DELETE FROM achievement_media WHERE id = ?', [(int) $media['id']]);
            Flash::success('Eintrag entfernt.');
        }

        Url::redirect('/admin/mitglieder/' . $id . '/erfolge');
    }

    /** Liefert eine hochgeladene Erfolgs-Datei aus (nur fuer Berechtigte). */
    public function serveMedia(array $args): void
    {
        AuthController::requireLogin();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $media = Database::one(
            "SELECT * FROM achievement_media WHERE id = ? AND member_id = ? AND type = 'file'",
            [(int) ($args['mid'] ?? 0), $id]
        );

        // Referenz auf die zentrale Ablage? Dann von dort ausliefern.
        if ($media !== null && (int) ($media['media_file_id'] ?? 0) > 0) {
            $zentral = FileController::find((int) $media['media_file_id']);

            if ($zentral !== null) {
                $pfadZentral = FileController::path($zentral);

                if (is_file($pfadZentral)) {
                    $mime   = (string) $zentral['mime'];
                    $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

                    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
                    header('Content-Length: ' . (string) filesize($pfadZentral));
                    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
                        . '; filename="' . rawurlencode((string) $zentral['filename']) . '"');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, max-age=300');

                    readfile($pfadZentral);
                    exit;
                }
            }

            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        $pfad = $media === null ? '' : BASE_ROOT . '/data/mitglieder/' . $id . '/' . $media['file_path'];

        if ($media === null || !is_file($pfad)) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        $mime   = (string) $media['mime'];
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($pfad));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode((string) $media['file_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        readfile($pfad);
        exit;
    }

    // ------------------------------------------------------------------ Hilfen --

    /** @return array<string,mixed> */
    private function findAccessible(int $id): array
    {
        $member = MemberRepo::find($id);

        if ($member === null) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        if (!Auth::canAccessMember($member)) {
            http_response_code(403);
            View::display('errors/403', ['title' => 'Kein Zugriff'], 'layouts/admin');
            exit;
        }

        return $member;
    }

    /** Stellt sicher, dass der Eintrag zum Mitglied gehoert. */
    private function findOwned(string $table, int $entryId, int $memberId): void
    {
        $row = Database::one("SELECT id FROM $table WHERE id = ? AND member_id = ?", [$entryId, $memberId]);

        if ($row === null) {
            Flash::error('Eintrag nicht gefunden.');
            Url::redirect('/admin/mitglieder/' . $memberId . '/erfolge');
        }
    }
}
