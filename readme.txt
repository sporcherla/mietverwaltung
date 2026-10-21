=== Mietverwaltung ===
Contributors: sporcherla
Tags: vermietung, mietverwaltung, landlord, rental, anlage v, betriebskosten, mietvertrag
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.9.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mietverwaltung für private Vermieter: Stammdaten, Zahlungen, Belege, Betriebskostenabrechnung, Anlage V, Mietvertrag, Kaution, Darlehen, Mieterportal.

== Description ==

**Mietverwaltung** bündelt alles, was ein privater Vermieter für eine oder mehrere Wohnungen braucht, in einem Plugin. Alle Daten bleiben in der eigenen WordPress-Datenbank – kein Cloud-Dienst, keine Datenübertragung an Dritte.

* **Stammdaten**: Objekte, Einheiten, Mieter (mit Ehe-/Lebenspartner als Mitmieter), Mietverhältnisse mit Zusatzeinheiten (Wohnung + Garage = ein Vertrag)
* **Zahlungen**: Soll/Ist, offene Posten per Häkchen buchen, Bankimport (CSV/CAMT.053) mit Regeln
* **Belege**: Kostenart, Steuerjahr, § 82b-Verteilung, § 35a-Lohnanteil, Kamera-Upload
* **Betriebskostenabrechnung** nach BetrKV/HeizkostenV mit Schreiben als PDF
* **Steuer (Anlage V)**: Einnahmen, Werbungskosten, AfA, Schuldzinsen, Vorjahresvergleich, CSV
* **AfA** mit gesetzlichem Satz nach Baujahr, Anschaffungsnebenkosten einzeln, Kaufvertrag-PDF auslesen, 15-%-Wächter
* **Darlehen** mit Tilgungsplan, Sondertilgungen, Zinsbescheinigung
* **Mietvertrag** (20 §§), Hausordnung, Merkblatt, Datenschutzinformation, SEPA-Mandat, Übergabeprotokoll
* **Kaution** (§ 551 BGB), **Mahnwesen** (3 Stufen), **Mieterhöhung** (§ 558 / § 557b)
* **Mieterportal** per Shortcode `[mietverwaltung_portal]`
* **Datenschutz**: Löschkonzept, Anonymisierung, Auskunft Art. 15
* Hilfe im Backend, Fokusmodus, Backup/Restore, Deutsch und Englisch

Das Plugin ersetzt keine Rechts- oder Steuerberatung. Vertragsmuster und Berechnungen beruhen auf deutschem Recht (Stand 2026).

= English =

Rental management for private landlords under German law: master data, payments, receipts, service charge statements, tax reporting (Anlage V), tenancy agreement, deposit, loans, dunning, rent increases and tenant portal. Complete English interface; generated legal documents remain in German.

== Installation ==

1. Plugin hochladen und aktivieren – die Tabellen `wp_mv_*` werden angelegt.
2. Menü **Mietverwaltung → Hilfe** öffnen und dem Schnellstart folgen: Einstellungen → Objekt & Einheiten → Mieter → Mietverhältnis & Konditionen → Anlagegut & Darlehen.
3. Für das Mieterportal den Shortcode `[mietverwaltung_portal]` auf einer Seite einfügen und beim Mieter den WordPress-Benutzer verknüpfen.

== Frequently Asked Questions ==

= Werden Daten an Dritte übertragen? =
Nein. Alle Daten liegen in der WordPress-Datenbank und der Mediathek. Nur der Kaufvertrags-Parser lädt pdf.js vom cdnjs-CDN in den Browser; das PDF selbst wird lokal verarbeitet.

= Kann ich Wohnung und Garage in einem Vertrag verwalten? =
Ja. Beim Mietverhältnis „Weitere Einheiten im selben Vertrag“ anhaken – es entsteht eine Gesamtmiete, eine Buchung, eine Abrechnung.

= Ist der Mietvertrag rechtssicher? =
Der Generator folgt BGB und BGH-Rechtsprechung (Stand 2026) in eigener Formulierung. Bei besonderen Konstellationen (Gewerbe, WG, möbliert, Mietpreisbremse) bitte rechtlich prüfen lassen.

= Was passiert bei der Deinstallation? =
Daten bleiben standardmäßig erhalten. In den Einstellungen kann „Alle Tabellen und Einstellungen löschen“ aktiviert werden.

== Changelog ==

= 0.9.8 =
* Hilfe-Seite und Kontexthilfe im Backend
* Vollständige englische Übersetzung
* Projekt auf GitHub, README deutsch/englisch

= 0.9.7 =
* Ehe-/Lebenspartner optional als Mitmieter – automatisch in Vertrag, Schreiben, SEPA-Mandat, Übergabeprotokoll

= 0.9.6 =
* Strukturiertes Menü mit Gruppen und Icons

= 0.9.5 =
* Mietvertrag auf 20 §§ ausgebaut, Mietpreisbremse, § 554 BGB, Merkblatt Heizen/Lüften

= 0.9.0 – 0.9.4 =
* Zusatzeinheiten je Mietverhältnis, offene Posten abhaken, Darlehenskonto, AfA-Korrektur, Anschaffungsnebenkosten einzeln

Vollständiges Changelog: https://github.com/sporcherla/mietverwaltung/blob/main/CHANGELOG.md
