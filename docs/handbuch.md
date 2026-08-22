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
