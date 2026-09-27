# Kurzanleitung für die Verwaltung

Zum Weitergeben an Sektionsleitungen und den Kassier.
Der Verwaltungsbereich ist erreichbar unter **deine-domain.tld/admin**.

---

## Anmelden

Benutzername und Startpasswort bekommst du vom Superuser.
Beim ersten Login wirst du aufgefordert, ein eigenes Passwort zu vergeben (mindestens 10 Zeichen).

Passwort vergessen? Der Superuser setzt es unter *Benutzer* zurück und gibt dir ein neues Startpasswort.

---

## Was du siehst

| Rolle | Umfang |
|---|---|
| Sektionsleitung | ausschließlich die Mitglieder deiner eigenen Sektion(en) |
| Kassier | alle Mitglieder, aber nur lesend – dafür Beiträge und Zahlungen |
| Superuser | alles |

---

## Mitglieder

**Suchen und filtern.** Das Suchfeld findet Name, E-Mail, Ort, Gemeinde und Mitgliedsnummer.
Unter *Weitere Filter* gibt es Geschlecht, Altersbereich, die Beitragsart und
„nur mit fälligen offenen Beiträgen“.

**Sortieren.** Ein Klick auf eine Spaltenüberschrift sortiert, ein zweiter dreht die Richtung um.

**Neues Mitglied.** Pflicht sind Vorname, Zuname und Sektion. Alles andere kann später ergänzt werden.
Gibt es bereits jemanden mit gleichem Namen und Geburtsdatum, erscheint eine Warnung –
du kannst trotzdem anlegen, musst es aber bestätigen.

> **Gemeinde nicht vergessen.** Sie ist die Grundlage der Abrechnung mit den Gemeinden.
> Beim Tippen erscheinen Vorschläge aus dem amtlichen Gemeindeverzeichnis –
> bitte immer einen Vorschlag auswählen, sonst zählt dieselbe Gemeinde in der
> Auswertung doppelt („St. Ruprecht“ und „Sankt Ruprecht an der Raab“ sind für
> die Software zwei verschiedene Orte).
>
> Vorgeschlagen werden die steirischen Gemeinden. Fehlt eine, kann der Superuser
> sie unter *Gemeinden* freischalten.

**Mehrere auf einmal ändern.** Zeilen links ankreuzen, unten die Aktion wählen, *Ausführen*.
Möglich sind: Status auf aktiv/inaktiv, zum Löschen vormerken, fällige Beiträge als bezahlt erfassen.

**Exportieren.** Der Knopf *CSV-Export* liefert genau die aktuell gefilterte Liste –
also erst filtern, dann exportieren. Die Datei lässt sich direkt in Excel öffnen.

---

## Löschen

Als Sektionsleitung kannst du ein Mitglied **zum Löschen vormerken** (mit Grund).
Der Datensatz bleibt vollständig erhalten und ist in der Liste gelb markiert.
Ein Superuser prüft die Vormerkungen und entscheidet über die endgültige Löschung.

Eine Vormerkung lässt sich jederzeit wieder aufheben.

Bei einem Austritt ist meist der bessere Weg: **Status auf „inaktiv“** setzen und ein
Austrittsdatum eintragen. Dann bleibt die Historie für die Abrechnung erhalten.

---

## Beiträge

So funktioniert die Beitragsverwaltung:

1. **Beitragsarten** (einmalig, Superuser/Kassier): unter *Beiträge → Beitragsarten*
   die Beiträge des Vereins anlegen – Bezeichnung, Betrag, Periode
   (monatlich, quartalsweise, halbjährlich, jährlich) und Fälligkeitstag
   (z. B. „am 5.“).
2. **Zuordnen:** im Mitgliedsformular die *Beitragsart* wählen und bei Bedarf
   *beitragspflichtig ab* setzen (leer = ab Eintrittsdatum).
3. **Automatisch:** die fälligen Beitragszeilen entstehen von selbst und stehen in der
   **Beitragshistorie** des Mitglieds sowie gesammelt unter *Beiträge*.

**Zahlungen erfassen** unter *Beiträge*: je Zeile Betrag und Zahldatum prüfen und
*bezahlt ✓* klicken – oder mehrere Zeilen ankreuzen und mit *Auswahl abhaken*
gesammelt erledigen. Überfällige Zeilen sind gelb markiert.

**Erinnerung:** der Knopf *Erinnerung senden* schickt die Liste aller offenen
Beiträge per E-Mail an den Verein (Adresse in den *Einstellungen*).
Zusätzlich kann ein monatlicher Cronjob dieselbe E-Mail automatisch versenden.

**Sonderfälle:** in der Beitragshistorie des Mitglieds lassen sich Zahlungen
wieder öffnen, Zeilen löschen und manuelle Zeilen erfassen
(z. B. Nachzahlung oder Turnierbeitrag).

---

## Sektionsseite pflegen

Unter *Sektionen* deine Sektion öffnen. Änderbar sind:

* **Verein / Bezeichnung** und **Kurzbeschreibung** (erscheinen auf der Website)
* **Beschreibung** und **Training & Angebot** – einfache Textformatierung ist erlaubt:
  `<p>Absatz</p>`, `<strong>fett</strong>`, `<ul><li>Aufzählung</li></ul>`, `<h2>Zwischentitel</h2>`
* **Website, Facebook, Instagram**
* **Ansprechpartner** – beliebig viele. Telefonnummern und E-Mail-Adressen werden auf der
  Website automatisch klickbar, egal in welcher Schreibweise sie eingegeben werden
  (`03172 / 2197`, `+43 664 …`, `0664-123456` funktionieren alle).
* **Bilder**: Logo (quadratisch), Kachelbild (für die Startseite) und Titelbild (breit, oben
  auf der Sektionsseite). Große Fotos werden beim Hochladen automatisch verkleinert.

URL-Kürzel, Reihenfolge und Sichtbarkeit ändert nur der Superuser.

---

## Seiten aus Inhaltsblöcken bauen

Die Startseite und alle redaktionellen Seiten lassen sich aus **Blöcken**
zusammensetzen – vergleichbar mit Bausteinen: Block hinzufügen, befüllen,
mit den Pfeilen sortieren, fertig. Zu finden unter *Seiten* → Button
**Startseite gestalten** bzw. **Inhalt** neben jeder Seite.

Verfügbare Blöcke:

* **Text** – Fließtext mit Überschriften, Listen und Links.
* **Hero** – großer Aufmacher mit Hintergrundbild oder -video, Titel,
  Untertitel und Button. In der Größe „Groß“ füllt er als Seitenauftakt den
  ganzen Bildschirm.
* **Bild** – einzelnes Bild mit Bildunterschrift; Breite wählbar.
* **Galerie** – mehrere Bilder auf einmal hochladen oder aus der
  *Bildbibliothek* auswählen; zusätzlich lassen sich Tags angeben, dann
  erscheinen alle Bilder mit diesem Tag automatisch (z. B. „sommerfest 2026“).
  Bilder lassen sich per Drag & Drop in das Upload-Feld ziehen und durch Ziehen
  der Kacheln neu sortieren. Besucher blättern in einer Großansicht (Lightbox)
  durch die Bilder.
* **Video** – eigenes Video (MP4/WebM) oder ein YouTube-Video. YouTube wird
  erst geladen, wenn Besucher auf „Abspielen“ klicken – vorher fließen keine
  Daten an Google.
* **Wochenplan** – der Wochenplan aus der Terminverwaltung.
* **Trainingsgruppen** – die Kachelübersicht aller veröffentlichten Sektionen.
* **Aufruf (CTA)** – hervorgehobene Box mit Button, z. B. fürs Probetraining;
  auf Wunsch direkt als WhatsApp-Button.

Jeder Block lässt sich **ausblenden** (bleibt gespeichert, erscheint nur
nicht), **duplizieren** und **löschen**.

Auf der Startseite erscheinen Blöcke normalerweise zwischen Einleitung und
Trainingsgruppen. Wer die Startseite komplett selbst gestalten will, hakt oben
**„Startseite besteht nur aus den Blöcken unten“** an – dann verschwindet der
Standardaufbau, und Hero, Trainingsgruppen und Wochenplan werden als Blöcke in
beliebiger Reihenfolge eingefügt.

---

## Bildbibliothek und Galerien

**Einmal hochladen, überall verwenden.** Unter *Verein → Bilder* liegt die
Bildbibliothek. Mehrere Fotos auf einmal hochladen – sie werden am Server
verkleinert (max. 1800 px), Handyfotos richtig gedreht, und eine Vorschau wird
angelegt. Beim Hochladen gibst du **Tags** mit (mit Komma getrennt, z. B.
„sommerfest 2026, jugend“) und optional einen Bildtext.

* **Suchen**: Suchfeld (mehrere Wörter = alle müssen passen) und Tag-Wolke;
  ein Klick auf einen Tag filtert.
* **Bearbeiten**: bei jedem Bild *Tags / Bildtext* aufklappen und speichern.
* **Sammelaktionen**: Bilder anhaken, unten *Tags hinzufügen*, *Tags
  entfernen* oder *Löschen* wählen. Löschen fragt nach und entfernt das Bild
  auch aus allen Galerien und Blöcken.

**Galerien** (*Verein → Galerien*) sind benannte Bildersammlungen mit eigener
Seite unter `/galerie/<kürzel>`: Titel, Text, dann Bilder aus der Bibliothek
auswählen – erst nach Tag filtern, anhaken, unten die Reihenfolge per Ziehen
festlegen und mit ★ ein Titelbild bestimmen. Die Übersicht aller sichtbaren
Galerien liegt unter `/galerie`; trag sie unter *System → Menü* als Menüpunkt
ein, damit Besucher sie finden.

> Die Bildbibliothek ist etwas anderes als die *Dateiablage* (Verein →
> Dateien): Dort liegen Dokumente für die Verwaltung, nicht öffentlich.
> Bilder in der Bibliothek sind für die Website gedacht und öffentlich
> abrufbar.

---

## Wettkämpfe über Event141

Läuft ein Turnier oder eine Gala über **Event141**, meldet ihr eure Mitglieder
direkt aus Gym141 an – ohne Listen abzutippen – und holt die Ergebnisse zurück.

**Einmal verbinden** (Superuser): In Event141 im Gym-Bereich anmelden (oder das
Gym dort registrieren), unter „Gym141“ einen **API-Schlüssel** erzeugen und ihn
zusammen mit der Event141-Adresse unter *Einstellungen* eintragen.

**Anmelden:** Unter *Mitglieder → Wettkämpfe* erscheinen alle Events mit offener
Anmeldung. Kategorie wählen, Mitglieder anhaken, „Ausgewählte anmelden“.
Übertragen werden Name, Geburtsdatum, Geschlecht und das zuletzt erfasste
Gewicht. Der Veranstalter bestätigt die Anmeldung; den Status seht ihr in der
Liste. Abmelden geht, solange die Anmeldung offen ist.

**Ergebnisse:** Unten auf der Seite stehen alle Kämpfe eurer Mitglieder. Mit
„Ergebnisse in die Erfolge übernehmen“ entstehen daraus normale Kampf-Einträge
unter *Erfolge* (Gegner, Klasse, Methode, Runde). Jeder Kampf wird nur einmal
übernommen – der Knopf lässt sich gefahrlos mehrfach drücken.

Sektionsleitungen sehen und melden nur die Mitglieder ihrer Sektionen; Trainer
sehen die Seite nur lesend.

---

## Tickets verkaufen mit Ticket141

Verkauft ihr Eintrittskarten für eine Gala oder ein Turnier über
**Ticket141**, holt Gym141 den Shop direkt auf eure Website und zeigt die
Verkaufszahlen in der Verwaltung – ohne doppelte Pflege.

**Einmal verbinden** (Superuser): In Ticket141 unter *Verwaltung → API &
Webhooks* einen Schlüssel mit Bereich **read** erzeugen (Name z. B. „Gym141“).
Adresse und Schlüssel `tk_…` unter *Einstellungen → Ticket141 (Ticketshop)*
eintragen und **„Speichern + Verbindung prüfen“** drücken – die Meldung sagt
sofort, ob Ticket141 antwortet. Der Schlüssel wird nach dem Speichern nicht
mehr angezeigt; das Feld leer lassen heißt „unverändert“.

**Shop auf der Website:** Unter *Seiten → Inhalt* (oder *Startseite
gestalten*) den Block **Ticketshop (Ticket141)** hinzufügen und den
Event-Kürzel (Slug) eintragen – er steht in der Shop-Adresse des Events
(`…/t/kürzel`). Zwei Darstellungen:

* **Shop-Widget** – der komplette Ticketshop erscheint direkt auf der Seite;
  die Höhe passt sich automatisch an. Die Zahlung (Stripe) und die
  Ticketseite laufen auf der Ticket141-Adresse.
* **Nur Button** – ein Button „Tickets kaufen“, der zum Shop verlinkt.

Optional: eine Überschrift und ein **Zugangscode**, der in Ticket141
versteckte Kategorien (z. B. VIP) freischaltet. Für Widget und Button genügt
die Ticket141-Adresse; ohne Adresse bleibt der Block auf der Website
unsichtbar.

**Verkaufszahlen:** Unter *Mitglieder → Tickets* stehen alle Events aus
Ticket141 mit Termin, Status, verkauften und eingelassenen Tickets und
Umsatz. „Kategorien“ klappt den Verkauf je Kategorie auf; „Shop“ und
„Verwaltung“ führen direkt nach Ticket141. Die Seite sehen Superuser,
Verwaltung und Kassier – verkauft, storniert und gescannt wird in Ticket141.

---

## Trainingseinheiten aus der App

Mitglieder können in der Gym141-App unter *Mein Training* Einheiten aufzeichnen:
Herzfrequenz per Bluetooth-Brustgurt oder Uhr und die Strecke per GPS. Schaltet
das Mitglied in der App die Freigabe *Trainingseinheiten an den Verein
übermitteln* ein, erscheinen die Einheiten unter *Mitglied → Entwicklung* im
Abschnitt **Trainingseinheiten aus der App** – mit Datum, Art, Übung, Dauer,
Strecke und Puls. *Abspielen* öffnet die Wiedergabe: Karte mit Strecke, darunter
die Herzfrequenz-Kurve, Zeitleiste zum Vor- und Zurückspulen, Wiedergabe in
1- bis 60-facher Geschwindigkeit. Das Mitglied sieht dieselbe Ansicht in seinem
Mitgliederbereich unter *Meine Entwicklung*.

Die Karte lädt Kacheln von Geoapify (Schlüssel unter *Einstellungen → Karten*)
oder ohne Schlüssel von OpenStreetMap; die Karten-Bibliothek kommt von unpkg.com –
die Wiedergabe braucht also Internet.

---

## Für den Superuser

* **Benutzer** – Konten anlegen, Rolle und Sektionen zuweisen, Passwörter zurücksetzen.
  Das erzeugte Passwort wird genau einmal angezeigt: gleich sicher weitergeben.
* **Seiten** – Impressum und Datenschutz bearbeiten, weitere Seiten anlegen.
* **Import** – Mitglieder aus einer CSV-Datei übernehmen. Immer zuerst den *Probelauf* nutzen.
* **Gemeinden** – amtliche Liste; einzelne Gemeinden oder ganze Bundesländer für die
  Auswahl freischalten, eigene Einträge ergänzen.
* **Einstellungen** – Vereinsdaten, Startseitentext, Empfänger der Beitragserinnerung.
* **Protokoll** – wer hat wann was geändert.

---

## Testumgebung

Unter einer **dev.**-Subdomain läuft dieselbe Anwendung mit einer eigenen Datenbank.
Dort können Sie gefahrlos ausprobieren – ein gelber Balken am oberen Rand zeigt
immer an, dass Sie nicht auf der Echtseite sind. Änderungen dort erscheinen
**nicht** auf der Produktivseite, und umgekehrt.

**Papierkorb:** Gelöschte Mitglieder landen zuerst im Papierkorb und lassen sich von dort
vollständig wiederherstellen. Erst *Endgültig löschen* entfernt sie unwiderruflich.
