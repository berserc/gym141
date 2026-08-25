<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\FeeController;
use App\Controllers\GemeindeController;
use App\Controllers\ImportController;
use App\Controllers\MemberController;
use App\Controllers\PageAdminController;
use App\Controllers\PublicController;
use App\Controllers\ReportController;
use App\Controllers\SectionAdminController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Models\MemberRepo;
use App\Models\PageRepo;
use App\Models\Setting;

require dirname(__DIR__) . '/app/bootstrap.php';

// Ohne Datenbank kann nur der Installationshinweis ausgegeben werden.
if (!is_file((string) Config::get('db_path'))) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo View::render('errors/setup', ['title' => 'Einrichtung erforderlich'], null);
    exit;
}

Auth::startSession();

// Sicherheits-Header
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

// In jedem Template verfuegbar
View::share('appName', (string) Config::get('app_name', 'Gym141'));
View::share('flash', Flash::take());
View::share('authUser', Auth::user());
View::share('csrf', Csrf::token());
View::share('footerPages', PageRepo::footerPages());
View::share('settings', Setting::all());
View::share('title', '');
View::share('metaDesc', '');
View::share('activePage', '');
View::share('isDev', (string) Config::get('env', 'live') === 'dev');
View::share('noindex', (bool) Config::get('noindex', false));
View::share('showEnvBanner', (bool) Config::get('show_env_banner', false));
View::share('pendingDeletions', Auth::isSuperuser() ? MemberRepo::pendingDeletions() : 0);
View::share('openFees', Auth::check() ? App\Models\FeeRepo::openStats(Auth::allowedSectionIds())['count'] : 0);

// Betriebsmodus: Gym141 kann die komplette Vereins-Website liefern ODER nur
// die Verwaltung neben einer bestehenden Website ("Nur Verwaltung").
// Umschaltbar unter /admin/einstellungen; der Mitgliederbereich ist separat.
$publicSite = Setting::get('public_site', '1') !== '0';
$memberArea = Setting::get('member_area', '1') !== '0';

View::share('publicSite', $publicSite);
View::share('memberArea', $memberArea);

$router = new Router();

// ------------------------------------------------------------- Oeffentlich --
$public = new PublicController();

if ($publicSite) {
    $router->get('/', [$public, 'home']);
    $router->get('/sektion/{slug}', [$public, 'section']);
    $router->get('/sportart/{slug}', [$public, 'legacySection']);   // alte Drupal-URLs
    $router->get('/sportarten', static fn () => App\Core\Url::redirect('/'));
    $router->get('/sitemap.xml', [$public, 'sitemap']);
} else {
    // Nur Verwaltung: die Startseite fuehrt direkt zur Anmeldung.
    $router->get('/', static fn () => App\Core\Url::redirect('/admin'));
}

// Redaktionelle Seiten (Impressum, Datenschutz) bleiben immer erreichbar –
// auch Login-Seiten und Mitgliederbereich brauchen sie.
$router->get('/seite/{slug}', [$public, 'page']);
$router->get('/robots.txt', [$public, 'robots']);
$router->get('/impressum', static fn () => (new PublicController())->page(['slug' => 'impressum']));
$router->get('/datenschutz', static fn () => (new PublicController())->page(['slug' => 'datenschutz']));

// Einbettbarer Wochenplan fuer bestehende Websites + oeffentliche Lese-API –
// gerade im Modus "Nur Verwaltung" der Weg, Trainingszeiten anzuzeigen.
$router->get('/embed/wochenplan', [$public, 'embedSchedule']);

// ------------------------------------------------------------------ Login --
$auth = new AuthController();

$router->get('/admin/login', [$auth, 'showLogin']);
$router->post('/admin/login', [$auth, 'login']);
$router->post('/admin/logout', [$auth, 'logout']);
$router->post('/admin/modus', [$auth, 'switchMode']);
$router->get('/admin/profil', [$auth, 'profile']);
$router->post('/admin/profil', [$auth, 'updatePassword']);

// -------------------------------------------------------------- Uebersicht --
$router->get('/admin', [new DashboardController(), 'index']);

// ------------------------------------------------------------- Mitglieder --
$members = new MemberController();

$router->get('/admin/mitglieder', [$members, 'index']);
$router->get('/admin/mitglieder/neu', [$members, 'create']);
$router->get('/admin/mitglieder/export.csv', [$members, 'export']);
$router->get('/admin/mitglieder/export.xlsx', [$members, 'exportXlsx']);
$router->post('/admin/mitglieder', [$members, 'store']);
$router->post('/admin/mitglieder/sammelaktion', [$members, 'bulk']);
$router->post('/admin/mitglieder/formular-scan', [$members, 'scanForm']);
$router->get('/admin/mitglieder/{id}', [$members, 'edit']);
$router->post('/admin/mitglieder/{id}', [$members, 'update']);
$router->post('/admin/mitglieder/{id}/loeschantrag', [$members, 'requestDelete']);
$router->post('/admin/mitglieder/{id}/loeschantrag-aufheben', [$members, 'cancelDelete']);
$router->post('/admin/mitglieder/{id}/papierkorb', [$members, 'trash']);
$router->post('/admin/mitglieder/{id}/wiederherstellen', [$members, 'restore']);
$router->post('/admin/mitglieder/{id}/endgueltig-loeschen', [$members, 'destroy']);
$router->post('/admin/mitglieder/{id}/mitgliedschaft', [$members, 'saveMembership']);
$router->post('/admin/mitglieder/{id}/mitgliedschaft-loeschen', [$members, 'deleteMembership']);
$router->post('/admin/mitglieder/{id}/beitrag', [$members, 'saveFee']);
$router->post('/admin/mitglieder/{id}/beitrag-loeschen', [$members, 'deleteFee']);
$router->post('/admin/mitglieder/{id}/erziehungsberechtigt', [$members, 'saveGuardian']);
$router->post('/admin/mitglieder/{id}/erziehungsberechtigt-loeschen', [$members, 'deleteGuardian']);
$router->post('/admin/mitglieder/{id}/einschreibegebuehr', [$members, 'enrollmentFee']);
$router->post('/admin/mitglieder/{id}/aussetzen', [$members, 'savePause']);
$router->post('/admin/mitglieder/{id}/aussetzen-loeschen', [$members, 'deletePause']);
$router->post('/admin/mitglieder/{id}/erinnerung', [$members, 'saveReminder']);
$router->post('/admin/mitglieder/{id}/erinnerung-umschalten', [$members, 'toggleReminder']);
$router->post('/admin/mitglieder/{id}/erinnerung-loeschen', [$members, 'deleteReminder']);
$router->post('/admin/mitglieder/{id}/beitragsaenderung', [$members, 'saveAmountChange']);
$router->post('/admin/mitglieder/{id}/beitragsaenderung-loeschen', [$members, 'deleteAmountChange']);
$router->post('/admin/mitglieder/{id}/login-zugang', [$members, 'updateLogin']);
$router->post('/admin/mitglieder/{id}/archivieren', [$members, 'archive']);
$router->post('/admin/mitglieder/{id}/reaktivieren', [$members, 'unarchive']);

// Entwicklung (Gewicht, Anwesenheit, Bewertung) + Schnellerfassung
$training = new App\Controllers\TrainingController();

$router->get('/admin/entwicklung', [$training, 'index']);
$router->post('/admin/entwicklung', [$training, 'openMember']);
$router->post('/admin/entwicklung/test', [$training, 'storeTest']);
$router->post('/admin/entwicklung/test/{id}/loeschen', [$training, 'deleteTest']);
$router->post('/admin/entwicklung/test/{id}', [$training, 'updateTest']);
$router->post('/admin/mitglieder/{id}/leistungstest', [$training, 'saveResult']);
$router->post('/admin/mitglieder/{id}/leistungstest-loeschen', [$training, 'deleteResult']);
$router->get('/admin/anwesenheit', [$training, 'quickAttendance']);
$router->post('/admin/anwesenheit', [$training, 'storeQuickAttendance']);
$router->get('/admin/mitglieder/{id}/entwicklung', [$training, 'memberPage']);
$router->post('/admin/mitglieder/{id}/gewicht', [$training, 'saveWeight']);
$router->post('/admin/mitglieder/{id}/gewicht-loeschen', [$training, 'deleteWeight']);
$router->post('/admin/mitglieder/{id}/anwesenheit', [$training, 'saveAttendance']);
$router->post('/admin/mitglieder/{id}/anwesenheit-loeschen', [$training, 'deleteAttendance']);

// Termine und Gruppen (Kalender im Mitgliederbereich)
$api = new App\Controllers\ApiController();

$router->get('/api/termine', [$api, 'listEvents']);
$router->post('/api/termine', [$api, 'createEvent']);
$router->put('/api/termine/{id}', [$api, 'updateEvent']);
$router->delete('/api/termine/{id}', [$api, 'deleteEvent']);
$router->get('/api/termine.ics', [$api, 'icsFeed']);

// Oeffentliche Lese-API (ohne Anmeldung): Wochenplan und Trainingsgruppen als
// JSON, damit bestehende Websites die Daten selbst rendern koennen.
$router->get('/api/wochenplan', [$api, 'listSchedule']);
$router->get('/api/sektionen', [$api, 'listSections']);

$termine = new App\Controllers\TermineController();

$router->get('/admin/termine', [$termine, 'index']);
$router->get('/admin/termine/export.ics', [$termine, 'exportIcs']);
$router->post('/admin/termine', [$termine, 'store']);
$router->get('/admin/termine/{id}/orga', [$termine, 'orga']);
$router->post('/admin/termine/{id}/aufgabe', [$termine, 'saveTask']);
$router->post('/admin/termine/{id}/aufgabe-loeschen', [$termine, 'deleteTask']);
$router->post('/admin/termine/{id}/aufgabe/{tid}/person', [$termine, 'addTaskPerson']);
$router->post('/admin/termine/{id}/aufgabe/{tid}', [$termine, 'saveTask']);
$router->post('/admin/termine/{id}/person-loeschen', [$termine, 'removeTaskPerson']);
$router->post('/admin/termine/{id}/todo', [$termine, 'addTodo']);
$router->post('/admin/termine/{id}/todo-umschalten', [$termine, 'toggleTodo']);
$router->post('/admin/termine/{id}/todo-loeschen', [$termine, 'deleteTodo']);
$router->post('/admin/termine/{id}/loeschen', [$termine, 'destroy']);
$router->post('/admin/termine/{id}', [$termine, 'update']);

$gruppen = new App\Controllers\GroupController();

$router->get('/admin/gruppen', [$gruppen, 'index']);
$router->post('/admin/gruppen', [$gruppen, 'store']);
$router->post('/admin/gruppen/{id}/loeschen', [$gruppen, 'destroy']);
$router->post('/admin/gruppen/{id}/mitglied', [$gruppen, 'addMember']);
$router->post('/admin/gruppen/{id}/mitglied-entfernen', [$gruppen, 'removeMember']);

// -------------------------------------------------------- Mitgliederbereich --
if ($memberArea) {
    $mitglied = new App\Controllers\MemberAreaController();

    $router->get('/mitglied/login', [$mitglied, 'showLogin']);
    $router->post('/mitglied/login', [$mitglied, 'login']);
    $router->post('/mitglied/logout', [$mitglied, 'logout']);
    $router->get('/mitglied', [$mitglied, 'home']);
    $router->get('/mitglied/termine', [$mitglied, 'events']);
    $router->post('/mitglied/termin/{id}/antwort', [$mitglied, 'respond']);
    $router->get('/mitglied/passwort', [$mitglied, 'showPassword']);
    $router->post('/mitglied/passwort', [$mitglied, 'changePassword']);
    $router->get('/mitglied/entwicklung', [$mitglied, 'development']);
    $router->post('/mitglied/gewicht', [$mitglied, 'saveWeight']);
}

// Erfolge und Wettkaempfe
$achievements = new App\Controllers\AchievementController();

$router->get('/admin/erfolge', [$achievements, 'overview']);
$router->get('/admin/mitglieder/{id}/erfolge', [$achievements, 'memberPage']);
$router->post('/admin/mitglieder/{id}/kampf', [$achievements, 'saveFight']);
$router->post('/admin/mitglieder/{id}/kampf-loeschen', [$achievements, 'deleteFight']);
$router->post('/admin/mitglieder/{id}/kampf/{fid}', [$achievements, 'saveFight']);
$router->post('/admin/mitglieder/{id}/kdk', [$achievements, 'saveMeet']);
$router->post('/admin/mitglieder/{id}/kdk-loeschen', [$achievements, 'deleteMeet']);
$router->post('/admin/mitglieder/{id}/kdk/{fid}', [$achievements, 'saveMeet']);
$router->post('/admin/mitglieder/{id}/erfolg-medien', [$achievements, 'saveMedia']);
$router->post('/admin/mitglieder/{id}/erfolg-medien-loeschen', [$achievements, 'deleteMedia']);
$router->get('/admin/mitglieder/{id}/erfolg-datei/{mid}', [$achievements, 'serveMedia']);
$router->post('/admin/mitglieder/{id}/auszeichnung', [$achievements, 'saveAward']);
$router->post('/admin/mitglieder/{id}/auszeichnung-loeschen', [$achievements, 'deleteAward']);
$router->post('/admin/mitglieder/{id}/auszeichnung/{fid}', [$achievements, 'saveAward']);

$router->get('/admin/mitglieder/{id}/datei/{fid}', [$members, 'serveFile']);
$router->post('/admin/mitglieder/{id}/datei', [$members, 'uploadFile']);
$router->post('/admin/mitglieder/{id}/datei-bearbeiten', [$members, 'updateFile']);
$router->post('/admin/mitglieder/{id}/datei-loeschen', [$members, 'deleteFile']);
$router->post('/admin/mitglieder/{id}/rechnung', [$members, 'saveInvoice']);
$router->post('/admin/mitglieder/{id}/rechnung-bezahlt', [$members, 'invoicePaid']);
$router->post('/admin/mitglieder/{id}/rechnung-offen', [$members, 'invoiceOpen']);
$router->post('/admin/mitglieder/{id}/rechnung-loeschen', [$members, 'deleteInvoice']);

// --------------------------------------------------------------- Sektionen --
$sections = new SectionAdminController();

$router->get('/admin/sektionen', [$sections, 'index']);
$router->get('/admin/sektionen/neu', [$sections, 'create']);
$router->post('/admin/sektionen', [$sections, 'store']);
$router->get('/admin/sektionen/{id}', [$sections, 'edit']);
$router->post('/admin/sektionen/{id}', [$sections, 'update']);
$router->post('/admin/sektionen/{id}/loeschen', [$sections, 'destroy']);
$router->post('/admin/sektionen/{id}/bild-entfernen', [$sections, 'removeImage']);
$router->post('/admin/sektionen/{id}/kontakt', [$sections, 'saveContact']);
$router->post('/admin/sektionen/{id}/kontakt-loeschen', [$sections, 'deleteContact']);

$schedule = new App\Controllers\ScheduleAdminController();
$router->get('/admin/wochenplan', [$schedule, 'index']);
$router->post('/admin/wochenplan', [$schedule, 'save']);
$router->post('/admin/wochenplan/loeschen', [$schedule, 'delete']);

// ---------------------------------------------------------------- Benutzer --
$users = new UserController();

$router->get('/admin/benutzer', [$users, 'index']);
$router->get('/admin/benutzer/neu', [$users, 'create']);
$router->post('/admin/benutzer', [$users, 'store']);
$router->get('/admin/benutzer/{id}', [$users, 'edit']);
$router->post('/admin/benutzer/{id}', [$users, 'update']);
$router->post('/admin/benutzer/{id}/passwort', [$users, 'resetPassword']);
$router->post('/admin/benutzer/{id}/loeschen', [$users, 'destroy']);

// ------------------------------------------------------------------ Seiten --
$pages = new PageAdminController();

$router->get('/admin/seiten', [$pages, 'index']);

// Inhaltsbloecke ("Paragraphs"): {page} = 0 steht fuer die Startseite.
$bloecke = new App\Controllers\BlockAdminController();

$router->get('/admin/inhalt/{page}', [$bloecke, 'index']);
$router->post('/admin/inhalt/{page}/neu', [$bloecke, 'store']);
$router->post('/admin/inhalt/{page}/optionen', [$bloecke, 'options']);
$router->post('/admin/block/{id}', [$bloecke, 'update']);
$router->post('/admin/block/{id}/verschieben', [$bloecke, 'move']);
$router->post('/admin/block/{id}/umschalten', [$bloecke, 'toggle']);
$router->post('/admin/block/{id}/loeschen', [$bloecke, 'destroy']);

$router->get('/admin/seiten/neu', [$pages, 'create']);
$router->post('/admin/seiten', [$pages, 'store']);
$router->get('/admin/seiten/{id}', [$pages, 'edit']);
$router->post('/admin/seiten/{id}', [$pages, 'update']);
$router->post('/admin/seiten/{id}/loeschen', [$pages, 'destroy']);

// ------------------------------------------------------------------ Import --
$import = new ImportController();

$router->get('/admin/import', [$import, 'form']);
$router->post('/admin/import/vorschau', [$import, 'preview']);
$router->post('/admin/import/ausfuehren', [$import, 'run']);

// ------------------------------------------------------------- Auswertungen --
$reports = new ReportController();

$router->get('/admin/auswertung/statistik', [$reports, 'statistik']);

// ------------------------------------------------------------- Beitraege --
$fees = new FeeController();

$router->get('/admin/beitraege', [$fees, 'index']);
$router->post('/admin/beitraege/bezahlt', [$fees, 'markPaid']);
$router->post('/admin/beitraege/offen', [$fees, 'markOpen']);
$router->post('/admin/beitraege/erinnerung', [$fees, 'sendReminder']);
$router->get('/admin/beitragsarten', [$fees, 'plans']);
$router->post('/admin/beitragsarten', [$fees, 'storePlan']);
$router->post('/admin/beitragsarten/{id}/betrag', [$fees, 'changePlanAmount']);
$router->post('/admin/beitragsarten/{id}/betrag-loeschen', [$fees, 'deletePlanAmountChange']);
$router->post('/admin/beitragsarten/{id}', [$fees, 'updatePlan']);
$router->post('/admin/beitragsarten/{id}/loeschen', [$fees, 'deletePlan']);

// ----------------------------------------------------------- Buchhaltung --
$ledger = new App\Controllers\LedgerController();

$router->get('/admin/buchhaltung', [$ledger, 'index']);
$router->get('/admin/buchhaltung/export.csv', [$ledger, 'exportCsv']);
$router->get('/admin/buchhaltung/auswertung', [$ledger, 'report']);
$router->get('/admin/buchhaltung/zahlungsarten', [$ledger, 'paymentMethods']);
$router->post('/admin/buchhaltung/zahlungsarten', [$ledger, 'storePaymentMethod']);
$router->post('/admin/buchhaltung/zahlungsarten/{id}', [$ledger, 'updatePaymentMethod']);
$router->post('/admin/buchhaltung/zahlungsarten/{id}/loeschen', [$ledger, 'deletePaymentMethod']);

// ------------------------------------------------------------------ Vorstand --
$board = new App\Controllers\BoardController();

$router->get('/admin/vorstand', [$board, 'index']);
$router->post('/admin/vorstand', [$board, 'store']);
$router->post('/admin/vorstand/{id}/beenden', [$board, 'endTerm']);
$router->post('/admin/vorstand/{id}/loeschen', [$board, 'destroy']);
$router->post('/admin/vorstand/{id}', [$board, 'update']);

// ------------------------------------- Vereinshistorie & Dokumentenarchiv --
$club = new App\Controllers\ClubController();

$router->get('/admin/verein', [$club, 'index']);
$router->post('/admin/verein', [$club, 'storeEvent']);
$router->get('/admin/verein/dokumente', [$club, 'documents']);
$router->post('/admin/verein/dokumente', [$club, 'storeDocument']);
$router->get('/admin/verein/dokument/{id}', [$club, 'serveDocument']);
$router->post('/admin/verein/dokument/{id}/bearbeiten', [$club, 'updateDocument']);
$router->post('/admin/verein/dokument/{id}/loeschen', [$club, 'deleteDocument']);
$router->post('/admin/verein/{id}/dokument', [$club, 'uploadEventDocument']);
$router->post('/admin/verein/{id}/link', [$club, 'storeEventLink']);
$router->post('/admin/verein/{id}/link-loeschen', [$club, 'deleteEventLink']);
$router->post('/admin/verein/{id}/loeschen', [$club, 'deleteEvent']);
$router->post('/admin/verein/{id}', [$club, 'updateEvent']);
$router->get('/admin/buchhaltung/fixkosten', [$ledger, 'fixedCosts']);
$router->post('/admin/buchhaltung/fixkosten', [$ledger, 'storeFixedCost']);
$router->post('/admin/buchhaltung/fixkosten/{id}/betrag', [$ledger, 'changeFixedCostAmount']);
$router->post('/admin/buchhaltung/fixkosten/{id}/betrag-loeschen', [$ledger, 'deleteFixedCostAmountChange']);
$router->post('/admin/buchhaltung/fixkosten/{id}/datei', [$ledger, 'uploadFixedCostFile']);
$router->post('/admin/buchhaltung/fixkosten/{id}/datei-loeschen', [$ledger, 'deleteFixedCostFile']);
$router->get('/admin/buchhaltung/fixkosten/{id}/datei/{fid}', [$ledger, 'serveFixedCostFile']);
$router->post('/admin/buchhaltung/fixkosten/{id}', [$ledger, 'updateFixedCost']);
$router->post('/admin/buchhaltung/fixkosten/{id}/loeschen', [$ledger, 'deleteFixedCost']);
$router->post('/admin/buchhaltung', [$ledger, 'store']);
$router->post('/admin/buchhaltung/{id}/loeschen', [$ledger, 'destroy']);

// ------------------------------------------------------------ Einstellungen --
$settings = new SettingsController();

$router->get('/admin/einstellungen', [$settings, 'index']);
$router->post('/admin/einstellungen', [$settings, 'save']);

$system = new App\Controllers\SystemController();
$router->get('/admin/updates', [$system, 'updates']);
$router->post('/admin/updates/installieren', [$system, 'installUpdate']);
$router->post('/admin/einstellungen/lizenz-pruefen', [$system, 'checkLicense']);
$router->get('/admin/protokoll', [$settings, 'auditLog']);

// ---------------------------------------------------------------- Gemeinden --
$files = new App\Controllers\FileController();

$router->get('/admin/dateien', [$files, 'index']);
$router->post('/admin/dateien/ordner', [$files, 'createFolder']);
$router->post('/admin/dateien/ordner-umbenennen', [$files, 'renameFolder']);
$router->post('/admin/dateien/ordner-loeschen', [$files, 'deleteFolder']);
$router->post('/admin/dateien/upload', [$files, 'upload']);
$router->post('/admin/dateien/umbenennen', [$files, 'renameFile']);
$router->post('/admin/dateien/loeschen', [$files, 'deleteFile']);
$router->get('/admin/dateien/{id}/anzeigen', [$files, 'serve']);

$gemeinden = new GemeindeController();

$router->get('/admin/gemeinden', [$gemeinden, 'index']);
$router->post('/admin/gemeinden/umschalten', [$gemeinden, 'toggle']);
$router->post('/admin/gemeinden/bundesland', [$gemeinden, 'toggleBundesland']);
$router->post('/admin/gemeinden/neu', [$gemeinden, 'store']);
$router->post('/admin/gemeinden/loeschen', [$gemeinden, 'destroy']);

$router->notFound([$public, 'notFound']);

// ------------------------------------------------------------------ Start --
$basePath = rtrim((string) Config::get('base_path', ''), '/');
$path     = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
}

try {
    $router->dispatch((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path === '' ? '/' : $path);
} catch (Throwable $e) {
    error_log('[gym141] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);

    if ((bool) Config::get('debug', false)) {
        echo '<pre>' . e($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>';
        exit;
    }

    echo View::render('errors/500', ['title' => 'Fehler'], null);
}
