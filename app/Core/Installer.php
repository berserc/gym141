<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Einrichtung der Anwendung.
 *
 * Wird sowohl von bin/install.php (Kommandozeile) als auch von public/setup.php
 * (Browser, wenn nur FTP-Zugang besteht) verwendet – damit beide Wege exakt
 * dasselbe tun.
 */
final class Installer
{
    /** @var list<string> */
    private array $log = [];

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    private function say(string $message): void
    {
        $this->log[] = $message;
    }

    /**
     * Prueft die Serverumgebung.
     *
     * @return array{ok:bool, checks:list<array{name:string,ok:bool,hint:string}>}
     */
    public static function requirements(): array
    {
        $root   = dirname(__DIR__, 2);
        $checks = [];

        $checks[] = [
            'name' => 'PHP ' . PHP_VERSION,
            'ok'   => PHP_VERSION_ID >= 80100,
            'hint' => 'Benötigt wird PHP 8.1 oder neuer.',
        ];

        foreach (['pdo_sqlite', 'mbstring'] as $extension) {
            $checks[] = [
                'name' => 'Erweiterung ' . $extension,
                'ok'   => extension_loaded($extension),
                'hint' => 'In der PHP-Konfiguration aktivieren.',
            ];
        }

        $checks[] = [
            'name' => 'Erweiterung gd (optional)',
            'ok'   => extension_loaded('gd'),
            'hint' => 'Ohne GD werden hochgeladene Bilder nicht verkleinert.',
        ];

        foreach (['data' => $root . '/data', 'public/uploads' => $root . '/public/uploads'] as $label => $dir) {
            $checks[] = [
                'name' => "Verzeichnis $label beschreibbar",
                'ok'   => is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir)),
                'hint' => "Schreibrechte setzen: chmod 775 $label",
            ];
        }

        // Optionale Prüfungen dürfen die Installation nicht blockieren.
        $required = array_filter($checks, static fn (array $c): bool => !str_contains($c['name'], 'optional'));

        return [
            'ok'     => array_reduce($required, static fn (bool $c, array $x): bool => $c && $x['ok'], true),
            'checks' => $checks,
        ];
    }

    /**
     * Legt Datenbank, Grunddaten und den ersten Superuser an.
     *
     * @param string|null $dbPath Abweichende Datenbankdatei – wird gebraucht, wenn
     *                            eine vom Server geholte Kopie aktualisiert wird.
     * @return list<string> Protokoll der Schritte
     */
    public function run(string $adminUser, string $adminPassword, bool $force = false, ?string $dbPath = null): array
    {
        $this->log = [];

        $root   = dirname(__DIR__, 2);
        $dbPath = $dbPath ?? (string) Config::get('db_path');

        $this->prepareConfigFile($root);
        $this->prepareDirectories($root, $dbPath);

        if (is_file($dbPath) && $force) {
            $backup = $dbPath . '.' . date('Ymd-His') . '.bak';
            rename($dbPath, $backup);
            $this->say('Bestehende Datenbank gesichert nach ' . basename($backup) . '.');
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        Database::setPdo($pdo);

        $this->migrate($pdo);
        $this->applySchema($pdo, $root);
        $this->migrateLegacyFees($pdo);
        $this->migrateMemberships($pdo);
        @chmod($dbPath, 0664);

        $this->seedSections($pdo, $root);
        $this->seedDirectSection();
        $this->seedPages($root);
        $this->seedSettings();
        $this->seedPaymentMethods();
        $this->seedPerformanceTests();
        $this->seedGemeinden($root);
        $this->createSuperuser($adminUser, $adminPassword);

        $this->say('Einrichtung abgeschlossen. Datenbank: ' . $dbPath);

        return $this->log;
    }

    // ------------------------------------------------------------- Schritte --

    private function prepareConfigFile(string $root): void
    {
        $configFile = $root . '/app/config.php';

        if (!is_file($configFile)) {
            if (@copy($root . '/app/config.example.php', $configFile)) {
                $this->say('app/config.php aus der Vorlage erstellt.');
            } else {
                $this->say('WARNUNG: app/config.php konnte nicht angelegt werden – es gilt die Vorlage.');
            }
        }
    }

    private function prepareDirectories(string $root, string $dbPath): void
    {
        foreach ([
            dirname($dbPath),
            $root . '/public/uploads/sektionen',
            // Mitglieder- und Vereinsdokumente: bewusst AUSSERHALB des Document-Roots
            $root . '/data/mitglieder',
            $root . '/data/verein',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $dir);
            }
        }
    }

    /** Passt Datenbanken früherer Versionen an, bevor das Schema greift. */
    private function migrate(PDO $pdo): void
    {
        // Inhaltsbloecke (seit 1.2.0): explizit hier, damit die Tabelle auch
        // dann entsteht, wenn ein aelterer Updater die geschuetzte
        // data/schema.sql noch nicht ausgetauscht hat.
        $pdo->exec("CREATE TABLE IF NOT EXISTS page_blocks (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id    INTEGER REFERENCES pages(id) ON DELETE CASCADE,
            type       TEXT    NOT NULL,
            config     TEXT    NOT NULL DEFAULT '{}',
            sort_order INTEGER NOT NULL DEFAULT 0,
            published  INTEGER NOT NULL DEFAULT 1,
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_blocks_page ON page_blocks(page_id, sort_order, id)');

        // Seit 1.3.0 gibt es Bloecke auch auf Sektionsseiten.
        $this->addColumns($pdo, 'page_blocks', [
            'section_id' => 'INTEGER REFERENCES sections(id) ON DELETE CASCADE',
        ]);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_blocks_section ON page_blocks(section_id, sort_order, id)');

        // Seit 1.5.0: Herkunft eines Gewichtseintrags ('' = Verwaltung/Website,
        // 'app' = von der Gym141-App uebermittelt). Die App verwaltet nur die
        // eigenen Eintraege und ueberschreibt nie Messungen des Trainers.
        $this->addColumns($pdo, 'member_weights', [
            'source' => "TEXT NOT NULL DEFAULT ''",
            // Seit 1.13.0: Uhrzeit HH:MM ('' = Altbestand) - mehrere
            // Messungen pro Tag sind moeglich.
            'measured_time' => "TEXT NOT NULL DEFAULT ''",
        ]);

        // Seit 1.10.0: Task141-Freigabe-Link an Orga-Aufgaben.
        $this->addColumns($pdo, 'event_tasks', [
            'task141_url' => "TEXT NOT NULL DEFAULT ''",
        ]);

        // Seit 1.14.0: Mehrfach-Rollen je Benutzer (verwaltung, sektionskassier,
        // trainer als eigene Rollen). users.role bleibt als Hauptrolle bestehen;
        // bestehende Benutzer bekommen ihre bisherige Rolle als Startbestand.
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role    TEXT    NOT NULL CHECK (role IN (
                'superuser', 'verwaltung', 'kassier', 'sektionskassier',
                'sektionsleiter', 'trainer'
            )),
            PRIMARY KEY (user_id, role)
        )");
        $pdo->exec("INSERT OR IGNORE INTO user_roles (user_id, role)
                    SELECT id, role FROM users
                     WHERE role IN ('superuser', 'kassier', 'sektionsleiter')
                       AND id NOT IN (SELECT user_id FROM user_roles)");

        // Seit 1.15.0: Bankimport (Kontoauszuege einspielen und zuordnen).
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_imports (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            filename        TEXT    NOT NULL,
            imported_by     INTEGER REFERENCES users(id),
            row_count       INTEGER NOT NULL DEFAULT 0,
            new_count       INTEGER NOT NULL DEFAULT 0,
            duplicate_count INTEGER NOT NULL DEFAULT 0,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            import_id   INTEGER REFERENCES bank_imports(id) ON DELETE SET NULL,
            booked_on   TEXT    NOT NULL,
            amount      REAL    NOT NULL,
            currency    TEXT    NOT NULL DEFAULT 'EUR',
            counterpart TEXT    NOT NULL DEFAULT '',
            iban        TEXT    NOT NULL DEFAULT '',
            reference   TEXT    NOT NULL DEFAULT '',
            hash        TEXT    NOT NULL UNIQUE,
            status      TEXT    NOT NULL DEFAULT 'unbestimmt'
                                CHECK (status IN ('unbestimmt', 'vorgeschlagen', 'uebernommen')),
            member_id   INTEGER REFERENCES members(id) ON DELETE SET NULL,
            category    TEXT    NOT NULL DEFAULT '',
            note        TEXT    NOT NULL DEFAULT '',
            assigned_by INTEGER REFERENCES users(id),
            assigned_at TEXT,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_banktx_booked ON bank_transactions(booked_on DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_banktx_status ON bank_transactions(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_banktx_member ON bank_transactions(member_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transaction_files (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id INTEGER NOT NULL REFERENCES bank_transactions(id) ON DELETE CASCADE,
            filename       TEXT    NOT NULL,
            stored_as      TEXT    NOT NULL,
            mime           TEXT    NOT NULL DEFAULT '',
            size           INTEGER NOT NULL DEFAULT 0,
            created_at     TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_banktx_files ON bank_transaction_files(transaction_id)');

        // Seit 1.16.0: Kopplung Bankzahlung -> beglichene Beitragsperioden.
        $this->addColumns($pdo, 'bank_transactions', [
            'settled_info' => "TEXT NOT NULL DEFAULT ''",
            'settled_ids'  => "TEXT NOT NULL DEFAULT ''",
        ]);

        // Gemeindetabelle: frueher nur (id, name, sort_order)
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='gemeinden'")->fetchColumn();

        if ($exists !== false) {
            $columns = array_column($pdo->query('PRAGMA table_info(gemeinden)')->fetchAll(), 'name');

            if (!in_array('bundesland', $columns, true)) {
                $pdo->exec('DROP TABLE gemeinden');
                $this->say('Alte Gemeindetabelle ersetzt (neue Spalten für die amtliche Liste).');
            }
        }

        // Spalten, die spaeter dazugekommen sind, nachruesten.
        $this->addColumns($pdo, 'sections', [
            'fee_free'     => 'INTEGER NOT NULL DEFAULT 0',
            'base_funding' => 'REAL NOT NULL DEFAULT 0',
        ]);

        $this->addColumns($pdo, 'members', [
            'photo_path'           => "TEXT NOT NULL DEFAULT ''",
            'fee_plan_id'          => 'INTEGER REFERENCES fee_plans(id)',
            'fee_since'            => 'TEXT',
            'fee_amount_override'  => 'REAL',
            'fee_due_day_override' => 'INTEGER',
            'can_login'            => 'INTEGER NOT NULL DEFAULT 0',
            'login_password_hash'  => "TEXT NOT NULL DEFAULT ''",
            'login_last_at'        => 'TEXT',
            'is_trainer'           => 'INTEGER NOT NULL DEFAULT 0',
            'archived_at'          => 'TEXT',
        ]);

        $this->addColumns($pdo, 'users', [
            'member_id' => 'INTEGER REFERENCES members(id) ON DELETE SET NULL',
        ]);

        $this->addColumns($pdo, 'calendar_events', [
            'recur'       => "TEXT NOT NULL DEFAULT 'keine'",
            'recur_until' => 'TEXT',
        ]);

        // calendar_signups: Abstimmung je Wiederholungstermin -> occurs_on
        // gehoert in den Primaerschluessel, dafuer muss die Tabelle neu aufgebaut werden.
        $signupCols = array_column($pdo->query("PRAGMA table_info(calendar_signups)")->fetchAll(), 'name');

        if ($signupCols !== [] && !in_array('occurs_on', $signupCols, true)) {
            $pdo->exec('ALTER TABLE calendar_signups RENAME TO calendar_signups_alt');
            $pdo->exec("CREATE TABLE calendar_signups (
                event_id   INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
                member_id  INTEGER NOT NULL REFERENCES members(id)         ON DELETE CASCADE,
                occurs_on  TEXT    NOT NULL DEFAULT '',
                status     TEXT    NOT NULL DEFAULT 'zusage'
                           CHECK (status IN ('zusage', 'absage')),
                note       TEXT    NOT NULL DEFAULT '',
                updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
                PRIMARY KEY (event_id, member_id, occurs_on)
            )");
            $pdo->exec("INSERT INTO calendar_signups (event_id, member_id, occurs_on, status, note, updated_at)
                        SELECT event_id, member_id, '', status, note, updated_at FROM calendar_signups_alt");
            $pdo->exec('DROP TABLE calendar_signups_alt');
            $this->say('Termin-Abstimmungen auf Wiederholungstermine umgestellt (occurs_on).');
        }

        // Anhaenge koennen statt eines eigenen Uploads auch eine Datei aus der
        // zentralen Dateiablage referenzieren.
        $this->addColumns($pdo, 'achievement_media', [
            'media_file_id' => 'INTEGER REFERENCES media_files(id)',
        ]);
        $this->addColumns($pdo, 'fixed_cost_files', [
            'media_file_id' => 'INTEGER REFERENCES media_files(id)',
        ]);
        $this->addColumns($pdo, 'club_documents', [
            'media_file_id' => 'INTEGER REFERENCES media_files(id)',
        ]);

        $this->addColumns($pdo, 'ledger_entries', [
            'fixed_cost_id'     => 'INTEGER REFERENCES fixed_costs(id)',
            'invoice_id'        => 'INTEGER REFERENCES member_invoices(id)',
            'payment_method_id' => 'INTEGER REFERENCES payment_methods(id)',
        ]);

        $this->addColumns($pdo, 'fixed_costs', [
            'interval'          => "TEXT NOT NULL DEFAULT 'monatlich'",
            'payment_method_id' => 'INTEGER REFERENCES payment_methods(id)',
        ]);

        $this->addColumns($pdo, 'board_members', [
            'organ'   => "TEXT NOT NULL DEFAULT 'vorstand'",
            'term_to' => 'TEXT',
        ]);

        // Bereits erfasste Rechnungspruefer in das eigene Organ verschieben.
        $hatBoard = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='board_members'"
        )->fetchColumn();

        if ($hatBoard !== false) {
            $pdo->exec("UPDATE board_members SET organ = 'pruefer' WHERE function LIKE 'Rechnungsprüfer%'");
        }
    }

    /**
     * Uebertraegt die bisherige 1:1-Zuordnung Mitglied/Sektion in die
     * Mitgliedschaftstabelle. Laeuft erst, nachdem das Schema angelegt wurde.
     */
    private function migrateMemberships(PDO $pdo): void
    {
        $tabelle = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='member_sections'")->fetchColumn();

        if ($tabelle === false) {
            return;
        }

        $spalten = array_column($pdo->query('PRAGMA table_info(members)')->fetchAll(), 'name');

        if (!in_array('section_id', $spalten, true)) {
            return; // bereits vollstaendig umgestellt
        }

        $offen = (int) $pdo->query(
            'SELECT COUNT(*) FROM members m
              WHERE m.section_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM member_sections ms WHERE ms.member_id = m.id)'
        )->fetchColumn();

        if ($offen === 0) {
            return;
        }

        $pdo->exec(
            "INSERT OR IGNORE INTO member_sections
                (member_id, section_id, fee_amount, fee_category, status, joined_on, left_on)
             SELECT m.id, m.section_id, m.fee_amount, m.fee_category, m.status, m.joined_on, m.left_on
               FROM members m
              WHERE m.section_id IS NOT NULL"
        );

        $this->say("$offen bestehende Mitglieder in die Mitgliedschaftstabelle übernommen.");
    }

    /**
     * Ergaenzt fehlende Spalten einer Tabelle.
     *
     * @param array<string,string> $spalten Name => SQL-Definition
     */
    private function addColumns(PDO $pdo, string $table, array $spalten): void
    {
        $vorhanden = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();

        if ($vorhanden === false) {
            return;
        }

        $columns = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');

        foreach ($spalten as $name => $definition) {
            if (in_array($name, $columns, true)) {
                continue;
            }

            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $definition");
            $this->say("Spalte $table.$name ergänzt.");
        }
    }

    /**
     * ATUS-Weiz-Herkunft: jahresbasierte Beitraege (member_fees, ein Datensatz
     * je Mitglied und Jahr) in das Perioden-Modell (fee_entries) uebernehmen.
     * Idempotent ueber UNIQUE(member_id, period); die Alttabelle bleibt als
     * Beleg unangetastet. Muss NACH applySchema laufen (fee_entries noetig).
     */
    private function migrateLegacyFees(PDO $pdo): void
    {
        $alt = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='member_fees'"
        )->fetchColumn();

        if ($alt === false) {
            return;
        }

        $uebernommen = 0;

        foreach ($pdo->query('SELECT * FROM member_fees ORDER BY year, member_id')->fetchAll() as $f) {
            $jahr = (int) $f['year'];

            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO fee_entries
                    (member_id, plan_id, period, period_label, due_date, amount, paid, paid_on, paid_amount, note)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                (int) $f['member_id'],
                sprintf('%04d-01', $jahr),
                'Jahr ' . $jahr,
                sprintf('%04d-01-15', $jahr),
                (float) $f['amount'],
                (int) $f['paid'],
                $f['paid_on'] ?: null,
                (int) $f['paid'] === 1 ? (float) $f['amount'] : null,
                (string) $f['note'],
            ]);

            $uebernommen += $stmt->rowCount();
        }

        if ($uebernommen > 0) {
            $this->say("$uebernommen Jahresbeiträge aus member_fees in das Perioden-Modell übernommen.");
        }
    }

    private function applySchema(PDO $pdo, string $root): void
    {
        $schema = file_get_contents($root . '/data/schema.sql');

        if ($schema === false) {
            throw new RuntimeException('data/schema.sql konnte nicht gelesen werden.');
        }

        $pdo->exec($schema);
        $this->say('Datenbankschema eingespielt.');
    }

    private function seedSections(PDO $pdo, string $root): void
    {
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM sections')->fetchColumn();

        if ($existing > 0) {
            $this->say("Sektionen bereits vorhanden ($existing) – unverändert gelassen.");

            return;
        }

        $uploadDir = $root . '/public/uploads/sektionen';
        $order     = 10;

        foreach (SeedData::sections() as [$slug, $name, $clubName, $tagline, $contacts]) {
            $paths = [];

            foreach (['logo', 'tile', 'hero'] as $kind) {
                $paths[$kind] = '';

                foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $extension) {
                    if (is_file("$uploadDir/$slug-$kind.$extension")) {
                        $paths[$kind] = "sektionen/$slug-$kind.$extension";
                        break;
                    }
                }
            }

            $sectionId = Database::insert('sections', [
                'slug'          => $slug,
                'name'          => $name,
                'club_name'     => $clubName,
                'tagline'       => $tagline,
                'description'   => '',
                'training_info' => SeedData::trainingInfo()[$slug] ?? '',
                'logo_path'     => $paths['logo'],
                'tile_path'     => $paths['tile'],
                'hero_path'     => $paths['hero'],
                'sort_order'    => $order,
                'published'     => 1,
            ]);

            $contactOrder = 10;

            foreach ($contacts as [$role, $contactName, $phone, $mobile, $fax, $email]) {
                Database::insert('section_contacts', [
                    'section_id' => $sectionId,
                    'role_label' => $role,
                    'name'       => $contactName,
                    'phone'      => $phone,
                    'mobile'     => $mobile,
                    'fax'        => $fax,
                    'email'      => $email,
                    'sort_order' => $contactOrder,
                ]);

                $contactOrder += 10;
            }

            $order += 10;
        }

        $this->say(count(SeedData::sections()) . ' Sektionen samt Ansprechpartnern angelegt.');
    }

    /**
     * Sammelstelle fuer Mitglieder, die direkt beim Verein und in keiner
     * Sektion sind. Bewusst nicht veroeffentlicht – sie ist kein Training und
     * hat auf der Website nichts verloren.
     */
    private function seedDirectSection(): void
    {
        if (Database::one('SELECT id FROM sections WHERE slug = ?', ['direkt']) !== null) {
            return;
        }

        $sort = (int) Database::value('SELECT COALESCE(MAX(sort_order), 0) FROM sections') + 100;

        Database::insert('sections', [
            'slug'        => 'direkt',
            'name'        => 'Direkt (ohne Gruppe)',
            'club_name'   => 'Gym141',
            'tagline'     => 'Mitglieder ohne Zugehörigkeit zu einer Sektion',
            'description' => '',
            'sort_order'  => $sort,
            'published'   => 0,
        ]);

        $this->say('Sammelstelle „Direkt (ohne Gruppe)“ angelegt – nicht auf der Website sichtbar.');
    }

    private function seedPages(string $root): void
    {
        foreach ([['impressum', 'Impressum', 10], ['datenschutz', 'Datenschutz', 20]] as [$slug, $title, $sort]) {
            if (Database::one('SELECT id FROM pages WHERE slug = ?', [$slug]) !== null) {
                continue;
            }

            $file = $root . '/data/seed/' . $slug . '.html';
            $body = is_file($file) ? (string) file_get_contents($file) : '';

            Database::insert('pages', [
                'slug'       => $slug,
                'title'      => $title,
                'body'       => $body,
                'in_footer'  => 1,
                'sort_order' => $sort,
                'published'  => 1,
            ]);

            $this->say("Seite \"$title\" angelegt" . ($body === '' ? ' (ohne Inhalt).' : '.'));
        }
    }

    private function seedSettings(): void
    {
        foreach (SeedData::settings() as $key => $value) {
            Database::run('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)', [$key, $value]);
        }

        $this->say('Vereinsdaten und Startseitentext gesetzt.');
    }

    /** Bank und Barkassa muessen immer vorhanden sein; PayPal als Beispiel. */
    private function seedPaymentMethods(): void
    {
        foreach ([
            ['Barkassa', 'bar', 1, 10],
            ['Bank', 'bank', 1, 20],
            ['PayPal', 'online', 0, 30],
        ] as [$name, $kind, $protected, $sort]) {
            Database::run(
                'INSERT OR IGNORE INTO payment_methods (name, kind, protected, sort_order)
                 VALUES (?, ?, ?, ?)',
                [$name, $kind, $protected, $sort]
            );
        }

        // Falls Bank/Barkassa je geloescht wurden (Altbestand): Schutz sicherstellen.
        Database::run("UPDATE payment_methods SET protected = 1 WHERE name IN ('Barkassa', 'Bank')");

        $this->say('Zahlungsarten eingerichtet (Barkassa, Bank, PayPal).');
    }

    /** Gaengige Leistungstests vorbelegen – nur beim allerersten Einrichten. */
    private function seedPerformanceTests(): void
    {
        if ((int) Database::value('SELECT COUNT(*) FROM performance_tests') > 0) {
            return;
        }

        foreach ([
            ['Cooper-Test (12 min Lauf)', 'm', 1, 'Maximale Laufdistanz in 12 Minuten.'],
            ['Liegestütze (60 s)', 'Wdh.', 1, 'Saubere Wiederholungen in 60 Sekunden.'],
            ['Sit-ups (60 s)', 'Wdh.', 1, 'Wiederholungen in 60 Sekunden.'],
            ['Klimmzüge (max.)', 'Wdh.', 1, 'Maximale Anzahl am Stück.'],
            ['Standweitsprung', 'cm', 1, 'Beidbeiniger Absprung aus dem Stand.'],
            ['Sprint 30 m', 's', 0, 'Zeit über 30 Meter aus dem Hochstart.'],
            ['Pendellauf 4×10 m', 's', 0, 'Shuttle Run über 4×10 Meter.'],
            ['Unterarmstütz (Plank)', 's', 1, 'Maximale Haltezeit.'],
            ['Sit-and-Reach', 'cm', 1, 'Beweglichkeitstest, Fingerspitzen über Fußsohlenniveau.'],
            ['Seilspringen (60 s)', 'Wdh.', 1, 'Durchschläge in 60 Sekunden.'],
            ['Burpees (60 s)', 'Wdh.', 1, 'Wiederholungen in 60 Sekunden.'],
        ] as [$name, $unit, $higher, $beschreibung]) {
            Database::insert('performance_tests', [
                'name'             => $name,
                'unit'             => $unit,
                'higher_is_better' => $higher,
                'description'      => $beschreibung,
            ]);
        }

        $this->say('Leistungstests vorbelegt (Cooper, Liegestütze, Sprint 30 m ...).');
    }

    /**
     * Amtliche Gemeindeliste einspielen.
     * Quelle: STATISTIK AUSTRIA, "Gemeindeliste samt Kennziffern", Gebietsstand 2026.
     */
    private function seedGemeinden(string $root): void
    {
        $file = $root . '/data/seed/gemeinden.csv';

        if (!is_file($file)) {
            $this->say('WARNUNG: data/seed/gemeinden.csv fehlt – keine Gemeinden eingespielt.');

            return;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            $this->say('WARNUNG: data/seed/gemeinden.csv nicht lesbar.');

            return;
        }

        fgetcsv($handle, 0, ';', '"', '\\'); // Kopfzeile

        $count  = 0;
        $active = 0;

        Database::transaction(static function () use ($handle, &$count, &$active): void {
            while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                if (count($row) < 4 || trim((string) ($row[1] ?? '')) === '') {
                    continue;
                }

                // Für den Gym141 sind steirische Gemeinden der Regelfall;
                // alle übrigen lassen sich in den Einstellungen dazuschalten.
                $isActive = trim((string) $row[3]) === 'Steiermark' ? 1 : 0;
                $active  += $isActive;

                Database::run(
                    'INSERT INTO gemeinden (gkz, name, plz, bundesland, active, sort_order)
                     VALUES (:gkz, :name, :plz, :bundesland, :active, 0)
                     ON CONFLICT(name, bundesland) DO UPDATE SET
                        gkz = excluded.gkz,
                        plz = excluded.plz',
                    [
                        'gkz'        => trim((string) $row[0]),
                        'name'       => trim((string) $row[1]),
                        'plz'        => trim((string) $row[2]),
                        'bundesland' => trim((string) $row[3]),
                        'active'     => $isActive,
                    ]
                );

                $count++;
            }
        });

        fclose($handle);

        $this->say("$count Gemeinden eingespielt (Quelle: STATISTIK AUSTRIA), davon $active steirische zur Auswahl freigeschaltet.");
    }

    private function createSuperuser(string $username, string $password): void
    {
        $username = trim($username);

        if ($username === '') {
            $this->say('Kein Superuser angelegt (kein Benutzername angegeben).');

            return;
        }

        $existing = Database::one('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$username]);

        if ($existing !== null) {
            Database::update('users', (int) $existing['id'], [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role'          => 'superuser',
                'active'        => 1,
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ]);

            $this->say("Superuser \"$username\" war vorhanden – Passwort und Rolle aktualisiert.");

            return;
        }

        Database::insert('users', [
            'username'             => $username,
            'name'                 => 'Administrator',
            'email'                => str_contains($username, '@') ? $username : '',
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'role'                 => 'superuser',
            'active'               => 1,
            // Selbst gewähltes Passwort: kein erzwungener Wechsel.
            'must_change_password' => 0,
        ]);

        $this->say("Superuser \"$username\" angelegt.");
    }
}
