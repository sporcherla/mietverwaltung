# Mietverwaltung – WordPress-Plugin für private Vermieter

[English version → README.en.md](README.en.md)

**Mietverwaltung** bündelt alles, was ein privater Vermieter für eine oder mehrere Wohnungen braucht, in einem WordPress-Plugin: Stammdaten, Mieteingänge, Belege, Betriebskostenabrechnung, Steuer (Anlage V), Mietvertrag mit allen Anlagen, Kaution, Darlehen, Mahnwesen, Mieterhöhung und ein Mieterportal. Alle Daten bleiben in der eigenen WordPress-Datenbank – kein Cloud-Dienst, keine Datenübertragung an Dritte.

- Version: **0.9.8** · Lizenz: GPL-2.0-or-later · WordPress ≥ 6.0 · PHP ≥ 8.0
- Sprachen: Deutsch (Quellsprache), Englisch
- Zielgruppe: Dauervermietung von Wohnraum in Deutschland (keine Ferienwohnungen)

---

## Inhalt

1. [Funktionsübersicht](#funktionsübersicht)
2. [Installation](#installation)
3. [Schnellstart](#schnellstart)
4. [Module im Detail](#module-im-detail)
5. [Mieterportal](#mieterportal)
6. [Mehrsprachigkeit](#mehrsprachigkeit)
7. [Datenschutz & Sicherheit](#datenschutz--sicherheit)
8. [Datenmodell](#datenmodell)
9. [Entwicklung & Tests](#entwicklung--tests)
10. [Roadmap](#roadmap)
11. [Rechtliche Hinweise](#rechtliche-hinweise)

---

## Funktionsübersicht

| Bereich | Was das Plugin kann |
|---|---|
| **Stammdaten** | Objekte (Haus/ETW mit Baujahr, Heizungsart), Einheiten (Wohnung, Garage, Stellplatz, Keller), Mieter mit optionalem Ehe-/Lebenspartner als Mitmieter, Mietverhältnisse mit Zusatzeinheiten (Wohnung + Garage = ein Vertrag, eine Gesamtmiete) |
| **Zahlungen** | Soll/Ist je Monat aus den Konditionen, offene Posten per Häkchen zum 1. des Monats buchen, Bankimport (CSV / CAMT.053) mit Zuordnungsregeln und Duplikaterkennung |
| **Belege** | Erfassung mit Kostenart, Steuerjahr, Leistungszeitraum, Datei (am Handy per Kamera); § 82b EStDV-Verteilung auf 2–5 Jahre; § 35a-Lohnanteil für die Mieter |
| **Betriebskostenabrechnung** | Nach BetrKV mit Schlüsseln Fläche / Personen / Einheiten / Verbrauch, HeizkostenV 50–70 %, zeitanteilig bei Mieterwechsel, Leerstand beim Vermieter, Vorauszahlungen gegengerechnet, § 35a-Bescheinigung, Anpassung der Vorauszahlung (§ 560 Abs. 4) |
| **Steuer (Anlage V)** | Zufluss-/Abflussprinzip, Einnahmen getrennt in Miete und Umlagen, Werbungskosten nach Anlage-V-Gruppen mit anpassbaren Zeilennummern, AfA, Schuldzinsen, Vorjahresvergleich mit Chart, CSV-Export |
| **AfA** | Linear (gesetzlicher Satz nach Baujahr: 2 % / 2,5 % / 3 %) oder degressiv, zeitanteilig; Anschaffungsnebenkosten einzeln (Grunderwerbsteuer, Notar, Grundbuch, Makler, Sonstiges); Grundanteil; Kaufvertrag-PDF wird im Browser ausgelesen und schlägt Werte vor; 15-%-Wächter (§ 6 Abs. 1 Nr. 1a EStG) |
| **Finanzierung** | Darlehen mit Tilgungsplan (Annuität), Raten abhaken, Sondertilgungen, Jahreswerte → Anlage V; Zinsbescheinigung der Bank hat Vorrang; Zinsbindungs-Erinnerung |
| **Mietvertrag** | Wohnraummietvertrag mit 20 §§ aus den Stammdaten (Befristung, Staffel/Index, Kleinreparaturen, Tiere, Schönheitsreparaturen, Mietpreisbremse-Ausnahmen, § 554 BGB …), Hausordnung, Merkblatt Heizen/Lüften, Datenschutzinformation Art. 13, SEPA-Lastschriftmandat, Übergabeprotokoll Einzug/Auszug – als PDF oder Druckansicht, Ablage in den Dokumenten |
| **Kaution** | Kautionskonto nach § 551 BGB: Einzahlungen, taggenaue Verzinsung, Verrechnung, Rückzahlungsabrechnung als PDF |
| **Mahnwesen** | Zahlungserinnerung, 1. und letzte Mahnung mit Fristen, PDF, E-Mail an Mieter und Mitmieter, Warnung bei ≥ 2 Monatsmieten Rückstand |
| **Mieterhöhung** | § 558 (Vergleichsmiete, Kappungsgrenze 15/20 %, Sperrfrist), § 557b (Index/VPI), Vereinbarung/Staffel; Schreiben und neue Kondition automatisch |
| **Mieterportal** | Shortcode `[mietverwaltung_portal]`: Mietkonto, Abrechnungen, freigegebene Dokumente, Schadensmeldung, Zählerstand melden |
| **Dokumente** | Ablage mit Typ, Datum, Portal-Freigabe; alle erzeugten PDFs landen auf Wunsch automatisch hier |
| **Datenschutz** | Löschkonzept mit Fristen (§ 147 AO), Anonymisierung beendeter Mietverhältnisse, Auskunft Art. 15 als JSON, Hinweise für das Verarbeitungsverzeichnis |
| **System** | Hilfe-Seite + Kontexthilfe, strukturiertes Menü mit Icons, Fokusmodus (WordPress-Menü ausblenden), Dashboard-Widget mit Hinweisen, tägliche E-Mail-Erinnerung, Backup/Restore als JSON, eigene Rolle „Vermieter“, saubere Deinstallation |

---

## Installation

**Aus dem Release-ZIP**

1. `mietverwaltung.zip` unter **Plugins → Installieren → Plugin hochladen** hochladen und aktivieren.
2. Beim Aktivieren werden die Tabellen `wp_mv_*` angelegt, die Kostenarten nach BetrKV vorbelegt und die Rolle „Vermieter“ eingerichtet.
3. Im Backend erscheint das Menü **Mietverwaltung**.

**Aus dem Repository**

```bash
cd wp-content/plugins
git clone https://github.com/sporcherla/mietverwaltung.git
```

Danach im Backend aktivieren. Updates: `git pull` – Datenbankänderungen werden beim nächsten Aufruf automatisch eingespielt (`MV_DB::maybe_upgrade`).

**Anforderungen:** WordPress ≥ 6.0, PHP ≥ 8.0, MySQL/MariaDB. Der Kaufvertrags-Parser (pdf.js, Apache-2.0) ist im Plugin enthalten und läuft vollständig im Browser.

---

## Schnellstart

Die gleiche Anleitung steht im Backend unter **Mietverwaltung → Hilfe**.

1. **Einstellungen** – Vermieterdaten, Bankverbindung, Gläubiger-ID, Steuernummer.
2. **Objekt & Einheiten** – Haus/ETW anlegen (Baujahr, Heizungsart), darunter Wohnung, Garage, Stellplatz mit Fläche.
3. **Mieter** – Stammdaten, optional Ehe-/Lebenspartner als Mitmieter.
4. **Mietverhältnis & Konditionen** – Mieter + Einheit + Beginn; Garage als Zusatzeinheit anhaken; Kaltmiete und Vorauszahlungen mit Gültig-ab-Datum.
5. **Anlagegut & Darlehen** – Kaufpreis, Nebenkosten, Grundanteil (Kaufvertrag-PDF auslesen lassen); Darlehen mit Zins, Tilgung, Rate.
6. **Laufender Betrieb** – Mieteingänge abhaken oder per Bankimport, Belege fotografieren, Zählerstände erfassen. Am Jahresende: Betriebskostenabrechnung und Anlage V.

---

## Module im Detail

### Übersicht
Offene Posten je Mietverhältnis und Monat mit kumuliertem Saldo. Häkchen setzen → „Markierte als bezahlt buchen“ legt die Zahlung mit Datum 1. des Monats an (Quelle „abgehakt“, später änderbar). Fokusmodus blendet das übrige WordPress-Menü aus (Einstellung pro Benutzer).

### Zahlungen und Bankimport
Jede Zahlung gehört zu einem Mietverhältnis und einem Monat. Der Bankimport liest CSV-Exporte (Kopfzeile wird erkannt) oder CAMT.053-XML, erkennt bereits importierte Buchungen per Hash und ordnet Eingänge über IBAN oder Nachname, Ausgaben über Regeln (Suchtext → Kostenart) zu. Regeln lassen sich beim Import direkt merken.

### Belege und Steuer
Belege tragen Kostenart (entscheidet über Umlagefähigkeit und Anlage-V-Gruppe), Steuerjahr (10-Tage-Regel), Leistungszeitraum (für die Abrechnung) und optional § 82b-Verteilung und § 35a-Lohnanteil. Die Steuer-Seite rechnet nach § 11 EStG, trennt Mieteingänge anteilig in Miete und Umlagen, übernimmt AfA und Schuldzinsen und zeigt die Entwicklung zum Vorjahr. Zeilennummern der Anlage V werden einmal eingetragen und für Folgejahre vorbelegt.

### Abschreibung
Gebäude: Anschaffungskosten = Kaufpreis + Nebenkosten (einzeln erfasst), abzüglich Grundanteil. Satz leer = gesetzlich nach Baujahr (§ 7 Abs. 4 EStG), degressiv 5 % für Neubau 10/2023–09/2029. Finanzierungskosten (Notar/Grundbuch Grundschuld) werden automatisch als sofort abziehbarer Beleg angelegt. Der Kaufvertrag wird als PDF lokal im Browser geparst (pdf.js) – Kaufpreis, Übergang, Grundanteil, Datum werden vorgeschlagen; nichts verlässt den Browser vor dem Speichern.

### Darlehenskonto
Tilgungsplan ab Auszahlung (Zins = Restschuld × Sollzins / 12, Tilgung = Rate − Zins). Raten abhaken, Sondertilgungen buchen, Restlaufzeit schätzen. Jahreswerte fließen als Schuldzinsen in die Anlage V, solange keine Zinsbescheinigung erfasst ist.

### Betriebskostenabrechnung
Zeitraum wählen → Berechnen. Belege werden nach dem Schlüssel der Kostenart verteilt, Heizkosten nach HeizkostenV (Verbrauchsanteil am Objekt), Leerstand trägt der Vermieter, Mieterwechsel zeitanteilig. Ergebnis je Mieter mit Positionen, § 35a-Bescheinigung, Schreiben als PDF, Ablage und Portal-Freigabe, Vorauszahlung anpassen.

### Mietvertrag & Übergabe
Optionen je Mietverhältnis (JSON gespeichert). Erzeugt: Mietvertrag (20 §§, eigene Formulierung nach BGB und BGH-Rechtsprechung, BetrKV-Katalog mit Schlüsseltabelle), Hausordnung, Merkblatt Heizen und Lüften, Datenschutzinformation, SEPA-Mandat, Übergabeprotokoll Einzug/Auszug (mit Zählern aller Einheiten). Mitmieter aus den Mieterdaten und weitere Personen aus dem Freitext erscheinen automatisch mit eigenen Unterschriftenzeilen.

### Kaution, Mahnwesen, Mieterhöhung
Siehe Funktionsübersicht. Alle Schreiben nutzen den gemeinsamen Briefkopf (Einstellungen), erzeugen PDF über die eingebaute Engine ohne externe Bibliothek, bieten eine HTML-Druckansicht (`?format=html`) und können direkt in den Dokumenten abgelegt werden.

### Hinweise
Dashboard-Widget und tägliche Cron-Prüfung (`mv_taeglich`): fehlende Mieteingänge, Abrechnungsfrist, Zinsbindung, Zinsbescheinigung, 15-%-Grenze, Aufbewahrungsfrist, Mietende. Optional als E-Mail.

---

## Mieterportal

Shortcode `[mietverwaltung_portal]` auf einer Seite einfügen. Beim Mieter den WordPress-Benutzer verknüpfen (Rolle „Abonnent“ genügt). Angemeldete Mieter sehen: Konditionen und Mietkonto der letzten 12 Monate, Betriebskostenabrechnungen, freigegebene Dokumente; sie können Schäden melden und Zählerstände (mit Foto) übermitteln. Der Vermieter wird per E-Mail benachrichtigt.

---

## Mehrsprachigkeit

- Text Domain `mietverwaltung`, Quellsprache Deutsch, POT unter `languages/`.
- **Englisch** vollständig: `mietverwaltung-en_US.po/.mo`. WordPress wählt die Sprache nach Site- bzw. Benutzereinstellung.
- Mietvertrag, Hausordnung und Merkblatt bleiben bewusst deutsch – sie sind Rechtsdokumente nach deutschem Recht.
- Weitere Sprachen: POT mit Poedit oder Loco Translate übersetzen und als `mietverwaltung-<locale>.mo` ablegen.

---

## Datenschutz & Sicherheit

- Zugriff nur mit Capability `mv_verwalten` (Administratoren und Rolle „Vermieter“); alle Formulare mit Nonces; Eingaben werden bereinigt, Ausgaben escaped.
- Mieterportal nur für den verknüpften Benutzer, Dokumente nur bei expliziter Freigabe.
- Keine externen Dienste, keine CDN-Skripte; Dateien liegen in der WordPress-Mediathek.
- Löschkonzept: Aufbewahrungsfrist (Standard 10 Jahre, § 147 AO), Anonymisierung beendeter Mietverhältnisse, Auskunft Art. 15 / Art. 20 als JSON.
- Deinstallation: Daten bleiben standardmäßig erhalten; Option „Alle Tabellen und Einstellungen löschen“ in den Einstellungen.

---

## Datenmodell

Alle Tabellen mit Präfix `wp_mv_` (dbDelta, `owner_id` als Vorbereitung für Mandantenfähigkeit):

`objekte`, `einheiten`, `mieter`, `mietverhaeltnisse`, `mv_einheiten` (Zusatzeinheiten), `konditionen`, `zahlungen`, `belege`, `kostenarten`, `anlagegueter`, `darlehen`, `darlehen_raten`, `darlehen_jahre`, `dokumente`, `zaehler`, `zaehlerstaende`, `abrechnungen`, `abrechnung_ergebnis`, `bank_regeln`, `bank_import`, `mahnungen`, `erhoehungen`, `vertraege`, `kaution`, `meldungen`.

Einstellungen in `wp_options` (`mv_einstellungen`), Fokusmodus in `user_meta`, Datenbankversion `mv_db_version`.

---

## Entwicklung & Tests

```
mietverwaltung.php        Bootstrap, Plugin-Header
includes/                 eine Klasse je Modul (MV_Admin, MV_Soll, MV_Abrechnung, MV_Steuer, MV_AfA, MV_Darlehen, MV_Mietvertrag, MV_PDF, MV_Brief, MV_Hilfe, …)
assets/                   admin.css, menu.css, kaufvertrag.js
languages/                POT, en_US
tests/                    Tests ohne WordPress (SQLite-$wpdb-Stub)
uninstall.php
```

Tests laufen ohne WordPress-Installation:

```bash
php tests/save_all_test.php    # alle Entitäten speichern/aktualisieren
php tests/abr_test.php         # Betriebskostenabrechnung gegen Handrechnung
php tests/afa_test.php         # AfA-Fälle
php tests/darlehen_test.php    # Tilgungsplan
php tests/menu_test.php        # Menüstruktur
```

Änderungen je Version: [CHANGELOG.md](CHANGELOG.md). Beiträge sind willkommen – bitte Issues oder Pull Requests auf GitHub.

---

## Roadmap

- Free/Pro-Aufteilung für WordPress.org (3 Einheiten frei)
- Einrichtungsassistent, Schnellerfassung
- WPCS-Prüfung (PHPCS)
- Weitere Sprachen

---

## Rechtliche Hinweise

Das Plugin ersetzt keine Rechts- oder Steuerberatung. Vertragsmuster, Berechnungen und Fristen beruhen auf der Rechtslage in Deutschland (BGB, BetrKV, HeizkostenV, EStG, AO) mit Stand 2026 und sind vor Verwendung – insbesondere bei besonderen Konstellationen (Gewerbe, WG, möbliert, Mietpreisbremse) – fachlich zu prüfen. Der Vertragsgenerator verwendet eigene Formulierungen; Verbandsmuster wurden nur als fachliche Checkliste herangezogen.

Lizenz: [GPL-2.0-or-later](LICENSE). Autor: Markus Hirn.
