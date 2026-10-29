# Changelog

Alle nennenswerten Änderungen am Plugin „Mietfux“ (bis 1.0.0 „Mietverwaltung“).

## 1.0.3 – Quellsprache Englisch, Deutsch als Übersetzung

- Alle übersetzbaren Texte im Code sind jetzt englisch (Konvention von WordPress.org/translate.wordpress.org). Die vollständige deutsche Fassung liegt als `languages/mietfux-de_DE.po/.mo` bei und wird über `load_plugin_textdomain()` geladen – deutsche Installationen sehen exakt die bisherigen Texte. Rechtstexte (Mietvertrag, Hausordnung, Merkblatt, BetrKV-Katalog) bleiben unverändert deutsch.
- Plugin-Beschreibung im Header englisch.

## 1.0.2 – Zweite Review-Runde von WordPress.org (Nonces, spätes Escaping)

- **Nonce-Logik durchgängig**: Jeder Handler prüft zuerst die Nonce (`check_admin_referer`/`wp_verify_nonce`) und liest erst danach Eingaben. Admin-post-Endpunkte (PDF, CSV, Backup, Mahnung, Anonymisierung, Auskunft, Kautionsabrechnung, Mieterhöhungs-Schreiben, SEPA, Übergabeprotokoll) haben eigene Nonce-Aktionen; der Abrechnungs-Link im Mieterportal trägt eine Nonce, und `ablegen=1` wirkt nur noch nach Nonce-Prüfung im Admin (`Mietv_PDF::ablegen_aus_request()`).
- **Spätes Escaping**: Alle mit `phpcs:ignore … EscapeOutput` markierten Ausgaben wurden ersetzt – Attribute über `esc_attr()`, erzeugte HTML-Fragmente über `wp_kses()` mit Whitelist (`mietv_kses_html()`, `mietv_kses_svg()`) bzw. `wp_kses_post()` (Brieftext, Vertragsabsätze, Unterschriften, Anlagen, BetrKV-Tabelle). Einzige verbleibende Ausnahme: der binäre PDF-Datenstrom.
- **Statusmeldungen** nach Redirects laufen über kurzlebige Transients je Benutzer (`mietv_flash_set/get`) statt über `?msg=` in der URL (Admin und Portal).
- **Kein Inline-JavaScript mehr**: Bestätigungsabfragen (`data-confirm`), Alle-auswählen-Kästchen (`data-check-all`), Pflichtauswahl (`data-require-checked`) und Druckknöpfe (`data-action="print"`) werden von `assets/admin.js` bzw. `assets/dokument.js` bedient.

## 1.0.1 – Umbenennung in Mietfux, Review-Rückmeldungen von WordPress.org

- **Neuer Name „Mietfux – Mietverwaltung für Vermieter“**, Slug und Text-Domain `mietfux`, Hauptdatei `mietfux.php`. Das Review-Team stufte „Mietverwaltung“ als zu generisch ein. Interner Präfix `mietv_` und Tabellen `wp_mv_*` bleiben; der Shortcode `[mietverwaltung_portal]` funktioniert weiter als Alias zu `[mietfux_portal]`.
- **Kein Inline-CSS/JS mehr**: Portal-Stylesheet, Druckansichten (Schreiben, Mietvertrag) und Fokusmodus laufen über `wp_enqueue_style`/`wp_add_inline_style`/`wp_print_styles`; Auto-Druck als `assets/druck.js`.
- `load_plugin_textdomain()` entfernt (WordPress.org liefert Übersetzungen über translate.wordpress.org); `.po/.mo` bleiben im Repository, sind aber nicht mehr im Paket. Contributor in `readme.txt` auf den WordPress.org-Benutzer gesetzt.
- Behoben: die `admin_post_*`-Hooks trugen noch den alten Präfix – **alle PDF-/CSV-/Backup-/Auskunfts-Links liefen ins Leere**; `fputcsv`/`str_getcsv` mit ungültigem Enclosure-Zeichen (CSV-Export und Bank-CSV-Import brachen ab); Checkbox-Zeile im Übergabeprotokoll ohne Escaping.
- Bankimport: Zuordnungsfelder werden passend zum gewählten Typ ein-/ausgeblendet.

## 1.0.0 – Neues Backend-Layout

- **Seitenkopf** auf allen Plugin-Seiten mit Modul-Icon, Breadcrumb und Aktionsbuttons; Inhalte in Karten mit abgesetztem Seitenhintergrund.
- **Übersicht als Dashboard**: Kennzahlen (Objekte/Einheiten, monatliches Soll, offene Mietforderungen, Einnahmen des Jahres), Schnellzugriff (Zahlung, Beleg, Bankimport, Zählerstand, BK-Abrechnung, Mietvertrag), Hinweise-Karte; jedes Mietverhältnis als Karte mit Saldo-Badge und Direktlinks.
- Einheitliche Tabellen (ruhige Kopfzeile, Zeilen-Hover, tabellarische Zahlen, kein Wortumbruch in Spaltentiteln), Formulare mit klaren Eingabefeldern und Fokusrahmen, abgerundete Buttons in der Plugin-Farbwelt, Kennzahlenkacheln auf Steuer-, Kautions- und Darlehensseiten.
- Technik: `assets/admin.js` (Seitenkopf, Tabellen-/Formularkarten), überarbeitete `assets/admin.css` mit Design-Tokens; keine Änderungen an Datenmodell oder Logik.
- Behoben: Nach der Präfix-Umstellung waren alle Stammdaten-Seiten leer (`substr`-Offset); falsche Platzhalterreihenfolge in Betriebskosten-Anschreiben, Kautionsabrechnung und Mieterhöhungs-Schreiben; Vorauswahl auf Kautions-, Mieterhöhungs- und Darlehensseite griff nicht (fehlender Int-Cast).

## 0.9.9 – Einrichtungsassistent, Coding Standards, WordPress.org-Reife

- **Einrichtungsassistent**: fünf Schritte (Vermieter → Objekt → Einheiten mit Garage → Mieter mit Partner → Mietverhältnis mit Konditionen). Jeder Schritt speichert sofort, Wiederaufruf aktualisiert statt zu duplizieren; Start nach Aktivierung, Hinweis auf der Übersicht, Verlinkung aus der Hilfe.
- **WordPress Coding Standards**: vollständiger PHPCS-Durchlauf (WordPress, PHPCompatibility) ohne Befunde; `phpcs.xml.dist` im Repo. Dabei behoben: Ausgabe-Escaping, Input-Sanitizing mit `wp_unslash`, Nonce-Prüfungen, `gmdate()`, Dateischreiben über `WP_Filesystem`, CSV-Escape-Parameter, Translators-Kommentare.
- **Präfix** von `mv_`/`MV_` auf `mietv_`/`Mietv_`/`MIETV_` umgestellt (WordPress.org verlangt mindestens vier Zeichen). Bestehende Installationen werden beim ersten Aufruf automatisch migriert (Optionen, Rolle, Capability, Benutzer-Meta, Cron); die Datenbanktabellen `wp_mv_*` bleiben unverändert.
- **pdf.js** (Apache-2.0) liegt jetzt im Plugin (`assets/pdfjs/`) statt vom CDN geladen zu werden – Voraussetzung für das Plugin-Verzeichnis.
- **Plugin Check** (WordPress.org) ohne Fehler und Warnungen, auch mit experimentellen Prüfungen; `readme.txt` auf Englisch mit deutschem Abschnitt; Banner, Icon und sieben Screenshots unter `.wordpress-org/`.
- Datenbank-Upgrade läuft jetzt bei `init` nach dem Laden der Übersetzungen (kein `_load_textdomain_just_in_time`-Hinweis mehr).
- Bugfix: In der Indexmiet-Berechnung war die Platzhalterreihenfolge im Hinweistext falsch (Prozentwert zeigte den VPI-Wert).

## 0.9.8 – Hilfe, englische Übersetzung, GitHub

- Neue Seite **Hilfe** im Menü (System) mit Schnellstart in sechs Schritten und Kurzbeschreibung jedes Moduls; zusätzlich WordPress-Kontexthilfe (Tab „Hilfe“ oben rechts) auf jeder Plugin-Seite.
- **Vollständige englische Übersetzung** (919 Strings, `languages/mietverwaltung-en_US.po/.mo`). Oberfläche, Formulare, Hilfe und Anschreiben sind zweisprachig; Mietvertrag, Hausordnung und Merkblatt bleiben deutsch (Rechtsdokumente nach deutschem Recht).
- Technische Schlüssel (CSS-Klassen, Optionswerte, Statuscodes) aus den Übersetzungsfunktionen entfernt – bei fremder Sprache hätten sie Logik und Styling brechen können.
- Projekt auf GitHub: README (deutsch/englisch), CHANGELOG, readme.txt für WordPress.org, GPL-Lizenz.

## 0.9.7 – Ehe-/Lebenspartner als Mitmieter

- Mieter: Ehe-/Lebenspartner optional als zweite Vertragspartei (Anrede, Vor-/Nachname, Geburtsdatum, E-Mail) direkt am Mieter.
- Automatisch berücksichtigt in Mietvertrag § 1 (beide Parteien mit Geburtsdatum, eigene Unterschriftenzeilen), SEPA-Mandat (beide Kontoinhaber), Übergabeprotokoll, Empfängerblock und Anrede aller Schreiben („Sehr geehrte Frau …, sehr geehrter Herr …“), Mahnungen per E-Mail in Kopie, Listen/Dropdowns („Meier, Anna & Max Meier“), Datenschutz-Anonymisierung.
- Vertrags-Freitext „Weitere Mieter“ bleibt für dritte Personen (Hinweis angepasst).
- DB-Version 13 (Spalten werden beim Update automatisch ergänzt).

## 0.9.6 – Strukturiertes Menü

- Untermenü in vier Gruppen: **Alltag** (Zahlungen, Belege, Bankimport, Meldungen, Dokumente), **Verwaltung** (Objekte … Mahnwesen), **Jahresabschluss** (Zähler … Steuer), **System** (Bankregeln, Datenschutz, Einstellungen) – mit Gruppenüberschriften und passenden Dashicons je Eintrag.
- Eigenes Haus-Symbol für den Hauptmenüpunkt; lange Bezeichnungen im Menü gekürzt (Seitentitel unverändert).

## 0.9.5 – Mietvertrag auf Verbandsniveau

Der Vertragsgenerator wurde anhand eines aktuellen Verbandsmusters (Haus & Grund Baden, Version 1.2026) fachlich abgeglichen und auf **20 Paragraphen** ausgebaut – in eigener Formulierung, ohne Übernahme des urheberrechtlich geschützten Mustertexts. Neu geregelt:

- Vertragsparteien mit Geburtsdatum und bisheriger Anschrift des Mieters (neue Felder beim Mieter), Steuernummer und Vertreter des Vermieters (Einstellungen), gegenseitige Empfangsvollmacht mehrerer Mieter
- Mietsache: Mitbenutzungsrechte, Mietereigentum, Schlüsselersatz/Schließanlage, Vorbehalt der Stellplatzumsetzung
- Ausschluss der stillschweigenden Verlängerung (§ 545), Nutzungsentschädigung (§ 546a)
- Betriebskosten: **Katalog § 2 BetrKV mit Umlageschlüssel-Tabelle** (aus den Kostenarten), Vorauszahlung oder **Pauschale**, Anpassung nach § 560 Abs. 4, Eigenleistung des Vermieters, Stromliefervertrag, Telekommunikation, WEG-Schlüssel bei Eigentumswohnungen, Möblierungszuschlag
- **Mietpreisbremse**: alle vier Ausnahmetatbestände als Auswahl mit Auskunftstext nach § 556g Abs. 1a
- Verzugszinsen (§ 288), Mahnkostenpauschale 5 € mit Gegenbeweis, Zahlungsweise Dauerauftrag/SEPA
- Zustand und noch auszuführende Arbeiten (Vermieter/Mieter mit Frist), Wohnungsgeberbestätigung (§ 19 BMG), Haushaltsänderungen
- **§ 554 BGB**: Barrierefreiheit, E-Mobilität, Einbruchschutz, Steckersolargeräte mit zulässigen Bedingungen
- Sorgfaltspflichten mit Beweislastregel, **Vermieterhaftung** (Ausschluss § 536a Abs. 1 Alt. 1, Kardinalpflichten), Aufrechnungsankündigung (§ 556b Abs. 2)
- Zentralheizung: Heizperiode, 20 °C, Benutzungspflicht, Wärmecontracting, Zwischenablesung bei Nutzerwechsel; bei dezentraler Heizung eigene Klausel
- Gartenpflege (Option), Ersatzvornahme bei Reinigung/Winterdienst, Verhinderung
- Hausordnung erweitert (Akku-Laden, Cannabis/Vaping in Gemeinschaftsräumen, Bohren/Leitungen, Fahrzeuge), neues **Merkblatt „Richtig heizen und lüften“** als Anlage
- Datenschutzinformation: Empfängerkreis (Messdienst, WEG-Verwaltung, Meldebehörde, Belegeinsicht anderer Mieter), Löschfrist für Interessenten, Widerrufsrecht, Monatsfrist

## 0.9.4 – Anschaffungsnebenkosten einzeln

- Beim Anlagegut gibt es jetzt eigene Felder für **Grunderwerbsteuer, Notar (Kaufvertrag), Grundbuch (Auflassung), Makler, Sonstiges**; die Summe wird automatisch berechnet und bildet mit dem Kaufpreis die Anschaffungskosten. Die Steuer-Seite zeigt die Aufschlüsselung unter dem Gebäude.
- **Finanzierungskosten** (Notar/Grundbuch für die Grundschuld) sind ein eigenes Feld und zählen **nicht** zu den Anschaffungskosten: Sie sind sofort abziehbare Geldbeschaffungskosten. Beim Speichern legt das Plugin dafür automatisch einen Beleg der Kostenart „Geldbeschaffungskosten“ an (einmalig je Objekt und Betrag).
- Bestehende Anlagegüter mit Gesamtsumme bleiben gültig; die Einzelfelder können nachgetragen werden.

## 0.9.3 – AfA-Korrektur

- **Fehler**: Blieb der AfA-Satz beim Gebäude leer, rechnete das Plugin mit 0 % und zeigte das Gebäude in der Steuer-Auswertung gar nicht an. Jetzt wird der gesetzliche Satz automatisch aus dem Baujahr des Objekts abgeleitet (§ 7 Abs. 4 EStG: vor 1925 → 2,5 %, Fertigstellung ab 2023 → 3 %, sonst 2 %) und die Herkunft des Satzes angezeigt. Ein eingetragener Satz hat weiterhin Vorrang.
- Grundanteil 0 % blockiert das Speichern nicht mehr, wird aber in der Steuer-Auswertung als Warnung angezeigt (Finanzamt verlangt eine Aufteilung; auch Eigentumswohnungen haben einen Miteigentumsanteil am Grund).
- Gebäude erscheinen in der AfA-Tabelle ab dem Anschaffungsjahr immer, auch wenn kein Satz ermittelt werden konnte – dann mit Hinweis.

## 0.9.2 – Darlehenskonto mit Tilgungsplan

- Neue Seite **Darlehenskonto**: monatlicher Tilgungsplan ab Auszahlung (Zins = Restschuld × Sollzins / 12, Tilgung = Rate − Zins). Offene Monate **abhaken und buchen**, Restschuld läuft mit, Restlaufzeit wird geschätzt.
- **Sondertilgungen** buchen – Folgeraten rechnen mit der reduzierten Restschuld. Buchungen einzeln löschbar.
- **Jahreswerte** (Zinsen, Tilgung, Restschuld 31.12.) werden aus den Raten berechnet und fließen automatisch als Schuldzinsen in die Anlage V, solange keine Zinsbescheinigung der Bank für das Jahr erfasst ist. Die Bescheinigung hat Vorrang; weicht sie um mehr als 5 € von der Berechnung ab, erscheint ein Hinweis (Banken rechnen taggenau, Zinsänderungen nach Zinsbindung).
- Voraussetzung: Auszahlungsdatum, Sollzins und Monatsrate am Darlehen.

## 0.9.1 – Offene Posten abhaken

- In der **Übersicht** hat jeder offene Monat eine Checkbox; „Markierte als bezahlt buchen“ legt den offenen Betrag als Zahlung mit **Datum 1. des Monats** an (Quelle „abgehakt“, nachträglich unter Zahlungen änderbar). Teilzahlungen werden nur mit dem Rest gebucht; bereits bezahlte Monate zeigen ✓. Kopf-Checkbox markiert alle offenen Monate eines Mietverhältnisses.
- Zahlungen zeigen jetzt ihre Quelle (manuell, Bankimport, abgehakt).

## 0.9.0 – Wohnung + Garage in einem Mietverhältnis

- Im Mietverhältnis gibt es jetzt **„Weitere Einheiten im selben Vertrag“**: Garage, Stellplatz, Keller per Häkchen zuordnen, optional mit Mietanteil. Ergebnis: **eine Gesamtmiete, eine Buchung, eine Kaution, eine Abrechnung** – statt zwei Mietverhältnisse.
- Konditionen werden als Gesamtmiete erfasst; der Mietanteil dient nur der Ausweisung im Vertrag („insgesamt 700 €, davon 650 € Wohnung und 50 € Garage“) mit dem Hinweis auf das einheitliche Mietverhältnis (nur gemeinsam kündbar).
- Plausibilität: Eine Garage kann nicht gleichzeitig in zwei Verträgen sein; Haupteinheit kann nicht zugleich weitere Einheit sein.
- Übergabeprotokoll listet auch die Zähler der weiteren Einheiten (z. B. Garagenstrom). Betriebskostenabrechnung: Garagen und Stellplätze zählen nicht als Wohneinheit und nicht zur Wohnfläche.
- Bestehende Daten: Wer bisher zwei Mietverhältnisse angelegt hat, löscht das Garagen-Mietverhältnis und hakt die Garage beim Wohnungs-Mietverhältnis an; die Kaltmiete in den Konditionen auf die Summe anpassen.

## 0.8.2

- **Leere optionale Zahlenfelder** (Personen, Zahltag, Baujahr, Verbrauchsanteil, Sortierung, Zimmer …) führten zu `NULL` in `NOT NULL`-Spalten – Mietverhältnisse, Objekte und Kostenarten wurden still nicht gespeichert. Das Plugin liest jetzt die Spaltendefinition (`SHOW COLUMNS`) und verwendet den Datenbank-Default.
- **Datenbankfehler beim Speichern werden angezeigt** (bisher erschien „Gespeichert“, auch wenn der Insert fehlschlug); Eingaben bleiben erhalten.
- Alle Speicherroutinen geprüft: 16 Entitäten (Insert + Update, auch mit leeren Feldern) sowie Einstellungen, Kaution, Abrechnung, Bankimport, Mieterhöhung, Vertragsoptionen, Portal-Meldung, Portal-Zählerstand, Anlage-V-Zeilen. Tests liegen unter `tests/` (siehe dort).

## 0.8.1 (Fehlerbehebung nach erstem Praxistest)

- **Speichern von Konditionen, Zahlungen, Meldungen, Dokumenten und Bankregeln** schlug still fehl: Das versteckte Formularfeld für die Datensatz-ID hieß `mv_id` und kollidierte mit dem gleichnamigen Feld „Mietverhältnis“. Umbenannt in `mv_record_id`.
- **Leere Auswahlfelder** („– nein –“ bei § 35a, Anrede leer, Umlageschlüssel „–“) schrieben `NULL` in Pflichtspalten – Belege, Mieter und Kostenarten konnten so nicht gespeichert werden. Leere Auswahl wird jetzt als `''` gespeichert, nur optionale Fremdschlüssel als `NULL`.
- **Beträge mit Tausenderpunkt ohne Nachkommastellen** („1.200“) wurden als 1,20 gelesen. Behoben.
- Neuer Testlauf, der alle Entitäten per POST speichert und aktualisiert (siehe Entwicklungshinweise).

