-- ATUS Weiz – Datenbankschema (SQLite)
-- Wird von bin/install.php eingespielt.

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------- Benutzer --
CREATE TABLE IF NOT EXISTS users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    username             TEXT    NOT NULL UNIQUE,
    name                 TEXT    NOT NULL DEFAULT '',
    email                TEXT    NOT NULL DEFAULT '',
    password_hash        TEXT    NOT NULL,
    role                 TEXT    NOT NULL DEFAULT 'sektionsleiter'
                                 CHECK (role IN ('superuser', 'sektionsleiter', 'kassier')),
    active               INTEGER NOT NULL DEFAULT 1,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    -- Ein Benutzer kann auch Mitglied des Vereins sein.
    member_id            INTEGER REFERENCES members(id) ON DELETE SET NULL,
    last_login_at        TEXT,
    created_at           TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Zuordnung Sektionsleiter -> Sektion(en). Superuser/Kassier brauchen keine Zeilen.
CREATE TABLE IF NOT EXISTS user_sections (
    user_id    INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, section_id)
);

-- --------------------------------------------------------------- Sektionen --
CREATE TABLE IF NOT EXISTS sections (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    slug          TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,               -- Sportart, z. B. "Badminton"
    club_name     TEXT    NOT NULL DEFAULT '',    -- z. B. "ATUS Weiz Badminton"
    tagline       TEXT    NOT NULL DEFAULT '',
    description   TEXT    NOT NULL DEFAULT '',    -- HTML (eingeschraenkt)
    training_info TEXT    NOT NULL DEFAULT '',    -- HTML (eingeschraenkt)
    website       TEXT    NOT NULL DEFAULT '',
    facebook      TEXT    NOT NULL DEFAULT '',
    instagram     TEXT    NOT NULL DEFAULT '',
    logo_path     TEXT    NOT NULL DEFAULT '',
    tile_path     TEXT    NOT NULL DEFAULT '',
    hero_path     TEXT    NOT NULL DEFAULT '',
    default_fee   REAL    NOT NULL DEFAULT 0,     -- Vorschlag fuer neue Mitglieder
    fee_free      INTEGER NOT NULL DEFAULT 0,     -- Sektion hebt grundsaetzlich keinen Beitrag ein
    base_funding  REAL    NOT NULL DEFAULT 0,     -- Basisfoerderung der Sektion je Jahr
    sort_order    INTEGER NOT NULL DEFAULT 0,
    published     INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_sections_sort ON sections(sort_order, name);

-- Ansprechpartner je Sektion (Triathlon hat z. B. fuenf)
CREATE TABLE IF NOT EXISTS section_contacts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    role_label TEXT    NOT NULL DEFAULT 'Sektionsleitung',
    name       TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    mobile     TEXT    NOT NULL DEFAULT '',
    fax        TEXT    NOT NULL DEFAULT '',
    email      TEXT    NOT NULL DEFAULT '',
    note       TEXT    NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_contacts_section ON section_contacts(section_id, sort_order);

-- ------------------------------------------------------------- Mitglieder --
CREATE TABLE IF NOT EXISTS members (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Historisch: bis zur Umstellung auf member_sections war hier die einzige
    -- Sektion hinterlegt. Wird nur noch als Hauptsektion mitgefuehrt.
    section_id          INTEGER REFERENCES sections(id),
    member_no           TEXT    NOT NULL DEFAULT '',
    first_name          TEXT    NOT NULL,
    last_name           TEXT    NOT NULL,
    birthdate           TEXT,                       -- YYYY-MM-DD
    gender              TEXT    NOT NULL DEFAULT 'unbekannt'
                                CHECK (gender IN ('m', 'w', 'd', 'unbekannt')),
    street              TEXT    NOT NULL DEFAULT '',
    zip                 TEXT    NOT NULL DEFAULT '',
    city                TEXT    NOT NULL DEFAULT '',
    gemeinde            TEXT    NOT NULL DEFAULT '',  -- massgeblich fuer die Abrechnung
    country             TEXT    NOT NULL DEFAULT 'AT',
    email               TEXT    NOT NULL DEFAULT '',
    phone               TEXT    NOT NULL DEFAULT '',
    fee_amount          REAL    NOT NULL DEFAULT 0,
    fee_category        TEXT    NOT NULL DEFAULT '',
    -- Beitragsart und Beginn der Beitragspflicht (siehe fee_plans/fee_entries)
    fee_plan_id         INTEGER REFERENCES fee_plans(id),
    fee_since           TEXT,                        -- YYYY-MM-DD
    -- Abweichungen vom Standard der Beitragsart (individuelle Vereinbarungen)
    fee_amount_override  REAL,
    fee_due_day_override INTEGER CHECK (fee_due_day_override IS NULL OR fee_due_day_override BETWEEN 1 AND 28),
    -- Mitglieder-Login (Haken wird von Admins gesetzt; Anmeldung per E-Mail)
    can_login            INTEGER NOT NULL DEFAULT 0,
    login_password_hash  TEXT    NOT NULL DEFAULT '',
    login_last_at        TEXT,
    -- Trainer-Kennzeichnung und Archiv (ehemalige Mitglieder)
    is_trainer           INTEGER NOT NULL DEFAULT 0,
    archived_at          TEXT,                    -- gesetzt = ehemaliges Mitglied
    status              TEXT    NOT NULL DEFAULT 'aktiv'
                                CHECK (status IN ('aktiv', 'inaktiv')),
    joined_on           TEXT,
    left_on             TEXT,
    notes               TEXT    NOT NULL DEFAULT '',
    photo_path          TEXT    NOT NULL DEFAULT '',   -- Mitgliedsfoto, nur intern sichtbar
    -- Loeschvormerkung durch Sektionsleitung
    delete_requested    INTEGER NOT NULL DEFAULT 0,
    delete_requested_by INTEGER REFERENCES users(id),
    delete_requested_at TEXT,
    delete_reason       TEXT    NOT NULL DEFAULT '',
    -- Papierkorb (nur Superuser)
    deleted_at          TEXT,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_members_section  ON members(section_id);
CREATE INDEX IF NOT EXISTS idx_members_name     ON members(last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_members_gemeinde ON members(gemeinde);
CREATE INDEX IF NOT EXISTS idx_members_status   ON members(status);
CREATE INDEX IF NOT EXISTS idx_members_delreq   ON members(delete_requested);
CREATE INDEX IF NOT EXISTS idx_members_deleted  ON members(deleted_at);

-- Mitgliedschaften: eine Person kann in mehreren Sektionen sein und zahlt
-- in jeder Sektion den dort hinterlegten Beitrag.
CREATE TABLE IF NOT EXISTS member_sections (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id    INTEGER NOT NULL REFERENCES members(id)  ON DELETE CASCADE,
    section_id   INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    fee_amount   REAL    NOT NULL DEFAULT 0,
    fee_category TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'aktiv' CHECK (status IN ('aktiv', 'inaktiv')),
    joined_on    TEXT,
    left_on      TEXT,
    note         TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (member_id, section_id)
);

CREATE INDEX IF NOT EXISTS idx_ms_member  ON member_sections(member_id);
CREATE INDEX IF NOT EXISTS idx_ms_section ON member_sections(section_id, status);

-- ------------------------------------------------------------- Beitraege --

-- Beitragsarten: Betrag, Zahlungsperiode und Faelligkeitstag.
-- Beispiele: "Monatsbeitrag" 50 € monatlich am 5.,
--            "Jahresbeitrag" 480 € jaehrlich am 15.
CREATE TABLE IF NOT EXISTS fee_plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    amount     REAL    NOT NULL DEFAULT 0,
    interval   TEXT    NOT NULL DEFAULT 'monatlich'
                       CHECK (interval IN ('monatlich', 'quartal', 'halbjahr', 'jahr')),
    due_day    INTEGER NOT NULL DEFAULT 1 CHECK (due_day BETWEEN 1 AND 28),
    active     INTEGER NOT NULL DEFAULT 1,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Beitragshistorie: eine Zeile je Mitglied und Zahlungsperiode.
-- "period" ist immer der Monat des Periodenbeginns (JJJJ-MM), unabhaengig vom
-- Intervall; das Label traegt die lesbare Bezeichnung (z. B. "3. Quartal 2026").
CREATE TABLE IF NOT EXISTS fee_entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id    INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    plan_id      INTEGER REFERENCES fee_plans(id) ON DELETE SET NULL,
    period       TEXT    NOT NULL,               -- JJJJ-MM (Periodenbeginn)
    period_label TEXT    NOT NULL DEFAULT '',
    due_date     TEXT    NOT NULL,               -- JJJJ-MM-TT
    amount       REAL    NOT NULL DEFAULT 0,     -- Soll laut Beitragsart
    paid         INTEGER NOT NULL DEFAULT 0,
    paid_on      TEXT,
    paid_amount  REAL,                           -- tatsaechlich bezahlter Betrag
    paid_by      INTEGER REFERENCES users(id),   -- wer die Zahlung erfasst hat
    note         TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (member_id, period)
);

CREATE INDEX IF NOT EXISTS idx_fee_entries_open   ON fee_entries(paid, due_date);
CREATE INDEX IF NOT EXISTS idx_fee_entries_member ON fee_entries(member_id, due_date DESC);

-- ------------------------------------------------------ Mitglieder-Dateien --

-- Profilbild und Dokumente je Mitglied (Anmeldeformular, Bestaetigungen ...).
-- Die Dateien liegen unter data/mitglieder/<member_id>/ AUSSERHALB des
-- Document-Roots und sind nur ueber die angemeldete Verwaltung abrufbar.
CREATE TABLE IF NOT EXISTS member_files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id   INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    filename    TEXT    NOT NULL,                 -- Originalname
    stored_name TEXT    NOT NULL,                 -- zufaelliger Name auf der Platte
    mime        TEXT    NOT NULL DEFAULT '',
    size        INTEGER NOT NULL DEFAULT 0,
    tag         TEXT    NOT NULL DEFAULT '',      -- z. B. "Mitgliedsformular"
    description TEXT    NOT NULL DEFAULT '',
    is_photo    INTEGER NOT NULL DEFAULT 0,       -- Profilbild (nur eines aktiv)
    uploaded_by INTEGER REFERENCES users(id),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_member_files ON member_files(member_id, is_photo);

-- -------------------------------------------------- Erziehungsberechtigte --

-- Je (minderjaehrigem) Mitglied beliebig viele Erziehungsberechtigte.
-- Ist die Person selbst Mitglied, wird sie verlinkt (guardian_member_id);
-- sonst werden Name und Kontaktdaten direkt erfasst.
CREATE TABLE IF NOT EXISTS member_guardians (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id          INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    guardian_member_id INTEGER REFERENCES members(id) ON DELETE SET NULL,
    name               TEXT NOT NULL DEFAULT '',
    relation           TEXT NOT NULL DEFAULT '',   -- Mutter, Vater, Oma ...
    phone              TEXT NOT NULL DEFAULT '',
    email              TEXT NOT NULL DEFAULT '',
    note               TEXT NOT NULL DEFAULT '',
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_guardians_member   ON member_guardians(member_id);
CREATE INDEX IF NOT EXISTS idx_guardians_guardian ON member_guardians(guardian_member_id);

-- Betragsaenderungen ab Stichtag: gilt je Eintrag (Beitragsart, Fixkosten
-- oder einzelnes Mitglied) ab valid_from. Bereits erzeugte Beitragszeilen
-- und Buchungen bleiben unveraendert; neue Perioden verwenden den zum
-- Faelligkeitsdatum gueltigen Betrag.
CREATE TABLE IF NOT EXISTS amount_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    entity     TEXT    NOT NULL CHECK (entity IN ('fee_plan', 'fixed_cost', 'member')),
    entity_id  INTEGER NOT NULL,
    amount     REAL    NOT NULL,
    valid_from TEXT    NOT NULL,               -- YYYY-MM-DD
    note       TEXT    NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES users(id),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_amount_history ON amount_history(entity, entity_id, valid_from DESC);

-- Beitragspausen: Mitglied ist im Zeitraum beitragsfrei ("ausgesetzt").
-- pause_to leer = bis auf Weiteres. Faelligkeiten im Pausenzeitraum werden
-- weder erzeugt noch prognostiziert.
CREATE TABLE IF NOT EXISTS member_pauses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    pause_from TEXT    NOT NULL,               -- YYYY-MM-DD
    pause_to   TEXT,                           -- YYYY-MM-DD oder NULL
    note       TEXT    NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES users(id),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pauses_member ON member_pauses(member_id, pause_from);

-- --------------------------------------------------------- Zahlungsarten --

-- Bank und Barkassa sind geschuetzt (protected) und immer vorhanden;
-- weitere Zahlungsarten (PayPal ...) lassen sich frei ergaenzen.
-- Bei kind='bank' werden die Bankdaten (IBAN usw.) mitgefuehrt.
CREATE TABLE IF NOT EXISTS payment_methods (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    name           TEXT    NOT NULL UNIQUE,
    kind           TEXT    NOT NULL DEFAULT 'sonstig'
                           CHECK (kind IN ('bar', 'bank', 'online', 'sonstig')),
    account_holder TEXT    NOT NULL DEFAULT '',
    iban           TEXT    NOT NULL DEFAULT '',
    bic            TEXT    NOT NULL DEFAULT '',
    bank_name      TEXT    NOT NULL DEFAULT '',
    protected      INTEGER NOT NULL DEFAULT 0,   -- nicht loeschbar (Bank, Barkassa)
    active         INTEGER NOT NULL DEFAULT 1,
    sort_order     INTEGER NOT NULL DEFAULT 0,
    note           TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ------------------------------------------------- Rechnungen an Mitglieder --

-- Freie Rechnungen (z. B. Boxhandschuhe gekauft): beliebiger Betrag mit
-- Kategorie. Beim Markieren als bezahlt entsteht automatisch eine Buchung
-- in der Buchhaltung; wieder oeffnen oder loeschen entfernt sie.
CREATE TABLE IF NOT EXISTS member_invoices (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id         INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    invoice_date      TEXT    NOT NULL,            -- YYYY-MM-DD
    text              TEXT    NOT NULL,            -- z. B. "Boxhandschuhe"
    category          TEXT    NOT NULL DEFAULT 'Verkauf',
    amount            REAL    NOT NULL DEFAULT 0,
    paid              INTEGER NOT NULL DEFAULT 0,
    paid_on           TEXT,
    payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL,
    note              TEXT    NOT NULL DEFAULT '',
    created_by        INTEGER REFERENCES users(id),
    created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_invoices_member ON member_invoices(member_id, paid);

-- ---------------------------------------------------------------- Vorstand --

-- Vereinsfunktionen: Vorstand und – als eigenes Organ laut Vereinsgesetz –
-- die Rechnungspruefer (mindestens zwei, nicht Teil des Vorstands).
-- Personen sind entweder mit einem Mitglied verlinkt oder extern erfasst.
-- Abgelaufene Funktionsperioden (term_to in der Vergangenheit) bleiben als
-- Historie erhalten.
CREATE TABLE IF NOT EXISTS board_members (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    organ      TEXT    NOT NULL DEFAULT 'vorstand'
                       CHECK (organ IN ('vorstand', 'pruefer')),
    function   TEXT    NOT NULL,                  -- z. B. "Obmann"
    member_id  INTEGER REFERENCES members(id) ON DELETE SET NULL,
    name       TEXT    NOT NULL DEFAULT '',       -- falls extern
    email      TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    since      TEXT,                              -- Funktionsperiode von (YYYY-MM-DD)
    term_to    TEXT,                              -- Funktionsperiode bis (leer = laufend)
    note       TEXT    NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------- Gewicht und Trainingsanwesenheit --

-- Gewichtsverlauf: Eintraege durch das Mitglied selbst (Login-Bereich) oder
-- durch Trainer/Verwaltung.
CREATE TABLE IF NOT EXISTS member_weights (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id   INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    measured_on TEXT    NOT NULL,                -- YYYY-MM-DD
    weight      REAL    NOT NULL,                -- kg
    note        TEXT    NOT NULL DEFAULT '',
    -- Herkunft: '' = Verwaltung/Website, 'app' = von der Gym141-App
    source      TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_weights_member ON member_weights(member_id, measured_on);

-- Trainingsanwesenheit: je Mitglied und Tag ein Eintrag, optional mit
-- Bewertung der Trainingsleistung (1-10, 10 = beste).
CREATE TABLE IF NOT EXISTS member_attendance (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id   INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    attended_on TEXT    NOT NULL,                -- YYYY-MM-DD
    rating      INTEGER CHECK (rating IS NULL OR rating BETWEEN 1 AND 10),
    note        TEXT    NOT NULL DEFAULT '',
    created_by  INTEGER REFERENCES users(id),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (member_id, attended_on)
);

CREATE INDEX IF NOT EXISTS idx_attendance_member ON member_attendance(member_id, attended_on DESC);

-- ------------------------------------------- Gruppen, Kalender, Mitglieder-Login --

-- Frei definierbare Gruppen (z. B. Wettkampfteam, Kids): steuern u. a.,
-- welche Mitglieder einen Termin sehen.
CREATE TABLE IF NOT EXISTS member_groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS member_group_members (
    group_id  INTEGER NOT NULL REFERENCES member_groups(id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES members(id)       ON DELETE CASCADE,
    PRIMARY KEY (group_id, member_id)
);

-- Wettkampf- und Eventkalender (nur im Login-Bereich sichtbar).
-- Termine koennen mehrere Tage dauern (ends_on leer = eintaegig).
CREATE TABLE IF NOT EXISTS calendar_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    kind        TEXT    NOT NULL DEFAULT 'event'
                        CHECK (kind IN ('wettkampf', 'event')),
    title       TEXT    NOT NULL,
    starts_on   TEXT    NOT NULL,               -- YYYY-MM-DD
    ends_on     TEXT,                           -- YYYY-MM-DD (mehrtaegig)
    location    TEXT    NOT NULL DEFAULT '',
    description TEXT    NOT NULL DEFAULT '',
    rsvp        INTEGER NOT NULL DEFAULT 1,     -- An-/Abmeldung moeglich
    -- Wiederholung (z. B. woechentliches Training); recur_until leer = offen
    recur       TEXT    NOT NULL DEFAULT 'keine'
                        CHECK (recur IN ('keine', 'woechentlich', '14taegig', 'monatlich')),
    recur_until TEXT,                           -- YYYY-MM-DD
    created_by  INTEGER REFERENCES users(id),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_calendar_start ON calendar_events(starts_on);

-- Sichtbarkeit: ohne Zeilen sieht JEDES eingeloggte Mitglied den Termin,
-- sonst nur Mitglieder der zugeordneten Gruppe(n).
CREATE TABLE IF NOT EXISTS calendar_event_groups (
    event_id INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    group_id INTEGER NOT NULL REFERENCES member_groups(id)   ON DELETE CASCADE,
    PRIMARY KEY (event_id, group_id)
);

-- Abstimmungen der Mitglieder zu Terminen ("komme" / "komme nicht").
-- occurs_on = Datum des Wiederholungstermins; '' = einmaliger Termin.
CREATE TABLE IF NOT EXISTS calendar_signups (
    event_id   INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    member_id  INTEGER NOT NULL REFERENCES members(id)         ON DELETE CASCADE,
    occurs_on  TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'zusage'
                       CHECK (status IN ('zusage', 'absage')),
    note       TEXT    NOT NULL DEFAULT '',
    updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (event_id, member_id, occurs_on)
);

-- ------------------------------------------------- Erfolge und Wettkaempfe --

-- Kaempfe (Boxen, Muay Thai, Kickboxen mit Stilen): daraus wird die Bilanz
-- gerechnet (Siege/Niederlagen/Unentschieden, KO-Quote), aufsplittbar nach
-- Alters- und Gewichtsklasse.
CREATE TABLE IF NOT EXISTS member_fights (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id     INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    sport         TEXT    NOT NULL DEFAULT 'Boxen',
    style         TEXT    NOT NULL DEFAULT '',     -- z. B. K-1, Low Kick, Pointfighting
    fight_date    TEXT,                            -- YYYY-MM-DD
    event         TEXT    NOT NULL DEFAULT '',     -- Veranstaltung
    location      TEXT    NOT NULL DEFAULT '',
    opponent      TEXT    NOT NULL DEFAULT '',
    opponent_club TEXT    NOT NULL DEFAULT '',
    weight_class  TEXT    NOT NULL DEFAULT '',
    age_class     TEXT    NOT NULL DEFAULT '',
    rounds        TEXT    NOT NULL DEFAULT '',     -- z. B. 3x2
    result        TEXT    NOT NULL DEFAULT 'sieg'
                          CHECK (result IN ('sieg', 'niederlage', 'unentschieden', 'kampflos')),
    method        TEXT    NOT NULL DEFAULT '',     -- KO, TKO, Punkte, Aufgabe, DQ
    end_round     INTEGER,                         -- Runde der Beendigung
    note          TEXT    NOT NULL DEFAULT '',
    created_by    INTEGER REFERENCES users(id),
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fights_member ON member_fights(member_id, fight_date DESC);
CREATE INDEX IF NOT EXISTS idx_fights_sport  ON member_fights(sport);

-- Kraftdreikampf-Wettkaempfe: je Disziplin drei Versuche. Konvention wie im
-- Powerlifting: ungueltige Versuche werden NEGATIV eingetragen (-105 =
-- 105 kg ungueltig). Total = Summe der besten gueltigen Versuche.
CREATE TABLE IF NOT EXISTS member_meets (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id    INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    meet_date    TEXT,                             -- YYYY-MM-DD
    event        TEXT    NOT NULL DEFAULT '',
    location     TEXT    NOT NULL DEFAULT '',
    age_class    TEXT    NOT NULL DEFAULT '',
    weight_class TEXT    NOT NULL DEFAULT '',
    bodyweight   REAL,                             -- Koerpergewicht am Wettkampftag
    squat_1      REAL, squat_2 REAL, squat_3 REAL,
    bench_1      REAL, bench_2 REAL, bench_3 REAL,
    dead_1       REAL, dead_2 REAL, dead_3 REAL,
    points       REAL,                             -- IPF-GL/DOTS/Wilks
    placement    TEXT    NOT NULL DEFAULT '',      -- Platzierung
    note         TEXT    NOT NULL DEFAULT '',
    created_by   INTEGER REFERENCES users(id),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_meets_member ON member_meets(member_id, meet_date DESC);

-- Auszeichnungen (Titel, Ehrungen, Sportler des Jahres ...)
CREATE TABLE IF NOT EXISTS member_awards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    award_date TEXT,                               -- YYYY-MM-DD
    title      TEXT    NOT NULL,                   -- z. B. "Landesmeister Muay Thai"
    sport      TEXT    NOT NULL DEFAULT '',
    note       TEXT    NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES users(id),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_awards_member ON member_awards(member_id, award_date DESC);

-- ---------------------------------------------- Vereinshistorie & Dokumente --

-- Ereignisse des Vereins: Rechnungspruefung, Vorstandssitzung,
-- Mitgliederversammlung, Generalversammlung ... mit Datum und Text.
CREATE TABLE IF NOT EXISTS club_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT    NOT NULL DEFAULT 'Sonstiges',
    event_date TEXT    NOT NULL,                  -- YYYY-MM-DD
    title      TEXT    NOT NULL DEFAULT '',
    text       TEXT    NOT NULL DEFAULT '',       -- Protokoll/Anmerkungen
    created_by INTEGER REFERENCES users(id),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_club_events ON club_events(event_date DESC);

-- Dokumentenarchiv: Statuten, Protokolle, Pruefberichte ... Dateien liegen
-- unter data/verein/ AUSSERHALB des Document-Roots; Zugriff hat nur der
-- Vorstand (Rollen Superuser und Kassier). Optional an ein Ereignis gehaengt.
CREATE TABLE IF NOT EXISTS club_documents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER REFERENCES club_events(id) ON DELETE SET NULL,
    doc_date    TEXT    NOT NULL,                 -- YYYY-MM-DD
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    filename    TEXT    NOT NULL,
    stored_name TEXT    NOT NULL,
    mime        TEXT    NOT NULL DEFAULT '',
    size        INTEGER NOT NULL DEFAULT 0,
    uploaded_by INTEGER REFERENCES users(id),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_club_documents ON club_documents(doc_date DESC);
CREATE INDEX IF NOT EXISTS idx_club_documents_event ON club_documents(event_id);

-- ----------------------------------------------------------- Buchhaltung --

-- Kassabuch des Vereins: Einnahmen und Ausgaben mit Kategorie.
-- Beitragszahlungen werden beim Abhaken automatisch gebucht
-- (fee_entry_id gesetzt); alles andere wird manuell erfasst.
CREATE TABLE IF NOT EXISTS ledger_entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    booked_on    TEXT    NOT NULL,                 -- Buchungsdatum YYYY-MM-DD
    type         TEXT    NOT NULL DEFAULT 'einnahme'
                         CHECK (type IN ('einnahme', 'ausgabe')),
    category     TEXT    NOT NULL DEFAULT '',
    text         TEXT    NOT NULL DEFAULT '',      -- Betreff/Beschreibung
    amount       REAL    NOT NULL DEFAULT 0,       -- immer positiv
    member_id    INTEGER REFERENCES members(id)     ON DELETE SET NULL,
    fee_entry_id INTEGER REFERENCES fee_entries(id) ON DELETE SET NULL,
    fixed_cost_id INTEGER REFERENCES fixed_costs(id) ON DELETE SET NULL,
    invoice_id   INTEGER REFERENCES member_invoices(id) ON DELETE SET NULL,
    payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL,
    created_by   INTEGER REFERENCES users(id),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ledger_booked   ON ledger_entries(booked_on DESC);
CREATE INDEX IF NOT EXISTS idx_ledger_category ON ledger_entries(type, category);
CREATE INDEX IF NOT EXISTS idx_ledger_member   ON ledger_entries(member_id);
CREATE INDEX IF NOT EXISTS idx_ledger_fee      ON ledger_entries(fee_entry_id);

-- Fixkosten bzw. wiederkehrende Buchungen (Miete, Internet, Versicherung ...):
-- werden je Periode automatisch gebucht, sobald der Buchungstag erreicht ist.
-- Perioden wie bei den Beitragsarten: monatlich, quartalsweise, halbjaehrlich,
-- jaehrlich.
CREATE TABLE IF NOT EXISTS fixed_costs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'ausgabe'
                       CHECK (type IN ('einnahme', 'ausgabe')),
    category   TEXT    NOT NULL DEFAULT '',
    amount     REAL    NOT NULL DEFAULT 0,
    interval   TEXT    NOT NULL DEFAULT 'monatlich'
                       CHECK (interval IN ('monatlich', 'quartal', 'halbjahr', 'jahr')),
    due_day    INTEGER NOT NULL DEFAULT 1 CHECK (due_day BETWEEN 1 AND 28),
    payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL,
    since      TEXT    NOT NULL DEFAULT (date('now', 'start of month')),
    active     INTEGER NOT NULL DEFAULT 1,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Amtliche Gemeindeliste (Quelle: STATISTIK AUSTRIA, Gebietsstand 2026).
-- "active" steuert, was im Mitgliederformular zur Auswahl steht – ohne Filter
-- waeren es ueber 2000 Eintraege.
CREATE TABLE IF NOT EXISTS gemeinden (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    gkz        TEXT    NOT NULL DEFAULT '',   -- amtliche Gemeindekennziffer
    name       TEXT    NOT NULL,
    plz        TEXT    NOT NULL DEFAULT '',
    bundesland TEXT    NOT NULL DEFAULT '',
    active     INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE (name, bundesland)
);

CREATE INDEX IF NOT EXISTS idx_gemeinden_active ON gemeinden(active, name);
CREATE INDEX IF NOT EXISTS idx_gemeinden_land   ON gemeinden(bundesland, name);

-- ------------------------------------------------------- Redaktionelle Seiten --
CREATE TABLE IF NOT EXISTS pages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    in_footer  INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    published  INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ------------------------------------------------------- Inhaltsbloecke --

-- Seiten werden aus konfigurierbaren Bloecken aufgebaut (Text, Hero, Bild,
-- Galerie, Video ...) - vergleichbar mit dem Paragraphs-Modul von Drupal.
-- Kontext: page_id gesetzt = redaktionelle Seite, section_id gesetzt =
-- Sektionsseite, beides NULL = Startseite. config ist JSON je Blocktyp.
CREATE TABLE IF NOT EXISTS page_blocks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id    INTEGER REFERENCES pages(id)    ON DELETE CASCADE,
    section_id INTEGER REFERENCES sections(id) ON DELETE CASCADE,
    type       TEXT    NOT NULL,
    config     TEXT    NOT NULL DEFAULT '{}',
    sort_order INTEGER NOT NULL DEFAULT 0,
    published  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_page_blocks_page    ON page_blocks(page_id, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_page_blocks_section ON page_blocks(section_id, sort_order, id);

-- ----------------------------------------------------------- Einstellungen --
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- ------------------------------------------------------------ Protokoll --
CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    username   TEXT NOT NULL DEFAULT '',
    action     TEXT NOT NULL,
    entity     TEXT NOT NULL DEFAULT '',
    entity_id  INTEGER,
    detail     TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC);

-- Brute-Force-Bremse fuer den Login
CREATE TABLE IF NOT EXISTS login_attempts (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    ip       TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_attempts_at ON login_attempts(at);

-- Erinnerungen je Mitglied (aerztliche Untersuchung, Kampfpassverlaengerung ...).
CREATE TABLE IF NOT EXISTS member_reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    title      TEXT    NOT NULL,
    due_on     TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    done       INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_reminders_due ON member_reminders(done, due_on);

-- Links und Dokumente zu Erfolgen (Kampf, Kraftdreikampf, Auszeichnung):
-- Ergebnislisten, YouTube-Videos, Urkunden ... beliebig viele je Eintrag.
CREATE TABLE IF NOT EXISTS achievement_media (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL CHECK (kind IN ('fight', 'meet', 'award')),
    ref_id     INTEGER NOT NULL,
    type       TEXT    NOT NULL CHECK (type IN ('link', 'file')),
    label      TEXT    NOT NULL DEFAULT '',
    url        TEXT    NOT NULL DEFAULT '',
    file_path  TEXT    NOT NULL DEFAULT '',
    file_name  TEXT    NOT NULL DEFAULT '',
    mime       TEXT    NOT NULL DEFAULT '',
    size       INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_achmedia_ref ON achievement_media(kind, ref_id);
CREATE INDEX IF NOT EXISTS idx_achmedia_member ON achievement_media(member_id);

-- Links zu Ereignissen der Vereinshistorie (Ergebnislisten, Videos ...).
CREATE TABLE IF NOT EXISTS club_event_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   INTEGER NOT NULL REFERENCES club_events(id) ON DELETE CASCADE,
    label      TEXT    NOT NULL DEFAULT '',
    url        TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_celinks_event ON club_event_links(event_id);

-- Dokumente zu Fixkosten (Vertraege, Rechnungen ...), Ablage in data/verein/.
CREATE TABLE IF NOT EXISTS fixed_cost_files (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fixed_cost_id INTEGER NOT NULL REFERENCES fixed_costs(id) ON DELETE CASCADE,
    filename      TEXT    NOT NULL,
    stored_name   TEXT    NOT NULL,
    mime          TEXT    NOT NULL DEFAULT '',
    size          INTEGER NOT NULL DEFAULT 0,
    uploaded_by   INTEGER REFERENCES users(id),
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fcfiles_cost ON fixed_cost_files(fixed_cost_id);

-- Zentrale Dateiablage (Dateibrowser): Ordner sind virtuell, die Dateien
-- liegen flach unter data/dateien/ mit Zufallsnamen.
CREATE TABLE IF NOT EXISTS media_folders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id  INTEGER REFERENCES media_folders(id) ON DELETE CASCADE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS media_files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    folder_id   INTEGER REFERENCES media_folders(id) ON DELETE SET NULL,
    filename    TEXT    NOT NULL,
    stored_name TEXT    NOT NULL,
    mime        TEXT    NOT NULL DEFAULT '',
    size        INTEGER NOT NULL DEFAULT 0,
    uploaded_by INTEGER REFERENCES users(id),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_mediafiles_folder ON media_files(folder_id, filename);

-- Leistungstests: Standardtests (Cooper, Liegestuetze ...) plus frei
-- definierbare. "higher_is_better" steuert, was als Bestwert gilt
-- (Wiederholungen/Distanz: mehr ist besser – Sprintzeiten: weniger).
CREATE TABLE IF NOT EXISTS performance_tests (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL,
    unit             TEXT    NOT NULL DEFAULT '',
    higher_is_better INTEGER NOT NULL DEFAULT 1,
    description      TEXT    NOT NULL DEFAULT '',
    active           INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS performance_results (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    test_id    INTEGER NOT NULL REFERENCES performance_tests(id) ON DELETE CASCADE,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    tested_on  TEXT    NOT NULL,
    value      REAL    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES users(id),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_perf_member ON performance_results(member_id, test_id, tested_on);
CREATE INDEX IF NOT EXISTS idx_perf_test   ON performance_results(test_id, tested_on);

-- ------------------------------------------------- Event-Organisation --
-- Aufgabenbereiche eines Termins (z. B. "Aufbau", "Kassa", "Catering") mit
-- zugeteilten Personen: Mitglieder ODER Externe (nur Name + Kontakt).
CREATE TABLE IF NOT EXISTS event_tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    title      TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_event_tasks ON event_tasks(event_id);

CREATE TABLE IF NOT EXISTS event_task_people (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id   INTEGER NOT NULL REFERENCES event_tasks(id) ON DELETE CASCADE,
    member_id INTEGER REFERENCES members(id) ON DELETE CASCADE,
    name      TEXT    NOT NULL DEFAULT '',   -- Externe: Name statt member_id
    contact   TEXT    NOT NULL DEFAULT '',   -- Telefon/E-Mail bei Externen
    note      TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_etp_task ON event_task_people(task_id);

-- To-do-Liste eines Termins (abhakbar).
CREATE TABLE IF NOT EXISTS event_todos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    title      TEXT    NOT NULL,
    due_on     TEXT,                         -- YYYY-MM-DD, optional
    done       INTEGER NOT NULL DEFAULT 0,
    done_by    INTEGER REFERENCES users(id),
    done_at    TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_event_todos ON event_todos(event_id, done, due_on);

-- Wochenplan der Trainings (Startseite + Sektionsseiten), im Backend pflegbar.
CREATE TABLE IF NOT EXISTS schedule_slots (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    day        INTEGER NOT NULL DEFAULT 1,          -- 1=Montag … 7=Sonntag
    time_from  TEXT    NOT NULL DEFAULT '',         -- HH:MM
    time_to    TEXT    NOT NULL DEFAULT '',         -- HH:MM
    title      TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    badge      TEXT    NOT NULL DEFAULT '',         -- z. B. "NEU"
    color      TEXT    NOT NULL DEFAULT '#d4a437',  -- Akzentfarbe der Kachel
    icon       TEXT    NOT NULL DEFAULT 'person',   -- Symbol (siehe Schedule::ICONS)
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_schedule_day ON schedule_slots(day, time_from, sort_order);

-- Zuordnung Einheit -> Sektion(en); die erste (nach sections.sort_order)
-- veroeffentlichte Sektion ist das Klickziel der Kachel.
CREATE TABLE IF NOT EXISTS schedule_slot_sections (
    slot_id    INTEGER NOT NULL REFERENCES schedule_slots(id) ON DELETE CASCADE,
    section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    PRIMARY KEY (slot_id, section_id)
);

-- ---------------------------------------------------------------------------
-- Zugriffstoken der Gym141-App (Mitglieder-API, /api/app/*).
-- Das Token verlaesst den Server nur einmal bei der Anmeldung;
-- gespeichert wird ausschliesslich der SHA-256-Hash.
CREATE TABLE IF NOT EXISTS member_api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id    INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    token_hash   TEXT    NOT NULL UNIQUE,
    device_name  TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    last_used_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_member_tokens_member ON member_api_tokens(member_id);

-- Zugriffstoken der Verwaltungs-Apps (Admin-/Trainer-App, /api/app/verwaltung/*).
CREATE TABLE IF NOT EXISTS user_api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash   TEXT    NOT NULL UNIQUE,
    device_name  TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    last_used_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_user_tokens_user ON user_api_tokens(user_id);

-- ---------------------------------------------------------------------------
-- Foerderjahre (aus ATUS Weiz uebernommen): je Jahr und Sektion ein Datensatz.
CREATE TABLE IF NOT EXISTS funding_years (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    year           INTEGER NOT NULL,
    section_id     INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    base_funding   REAL    NOT NULL DEFAULT 0,
    members_active INTEGER NOT NULL DEFAULT 0,
    children       INTEGER NOT NULL DEFAULT 0,
    fees           REAL    NOT NULL DEFAULT 0,
    child_bonus    REAL    NOT NULL DEFAULT 0,
    fee_share      REAL    NOT NULL DEFAULT 0,
    calculated     REAL    NOT NULL DEFAULT 0,   -- rechnerische Foerderung
    paid_out       REAL    NOT NULL DEFAULT 0,   -- tatsaechliche Auszahlung
    note           TEXT    NOT NULL DEFAULT '',
    closed         INTEGER NOT NULL DEFAULT 0,   -- Jahr abgeschlossen
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (year, section_id)
);

CREATE INDEX IF NOT EXISTS idx_funding_year ON funding_years(year);

-- Eigene Design-Templates des Vereins (die mitgelieferten liegen im Code
-- und sind nicht loeschbar; hier nur die selbst gespeicherten Kopien).
CREATE TABLE IF NOT EXISTS design_templates (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    config     TEXT    NOT NULL DEFAULT '{}',   -- JSON: colors[8] + font
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_design_templates_name ON design_templates(name COLLATE NOCASE);
