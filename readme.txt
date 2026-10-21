=== Mietverwaltung ===
Contributors: sporcherla
Tags: rental, landlord, property management, tenant, germany
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.9.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rental management for German landlords: tenancies, payments, service charge statements, tax (Anlage V), contracts, tenant portal.

== Description ==

**Mietverwaltung** ("rental management") puts everything a private landlord in Germany needs for one or more flats into a single plugin. All data stays in your own WordPress database – no cloud service, no data transfer to third parties, no external scripts.

The user interface is available in German and English. The generated legal documents (tenancy agreement, house rules, information sheets) are in German because they implement German tenancy law (BGB, BetrKV, HeizkostenV, EStG).

**Features**

* **Master data** – properties, units (flat, garage, parking space), tenants with an optional spouse or partner as co-tenant, tenancies with additional units (flat + garage = one agreement, one total rent)
* **Payments** – due/paid per month, tick open items to book them, bank import (CSV / CAMT.053) with matching rules and duplicate detection
* **Receipts** – cost type, tax year, service period, file upload with camera support; spreading of maintenance costs over 2–5 years; labour share for tenants (§ 35a EStG)
* **Service charge statement** – per BetrKV with allocation by area, persons, units or consumption; heating per HeizkostenV; pro rata on tenant change; letter as PDF
* **Tax reporting (Anlage V)** – income, expenses by form line, depreciation, loan interest, prior-year comparison, CSV export
* **Depreciation** – statutory rate by year built, itemised acquisition costs, land share, purchase contract PDF parsed locally in the browser, 15 % guard
* **Loans** – repayment schedule, instalments to tick off, extra repayments, bank interest certificates
* **Tenancy agreement** – complete residential agreement with annexes: house rules, heating/ventilation leaflet, privacy notice, SEPA direct debit mandate, move-in/move-out handover protocol
* **Deposit account** (§ 551 BGB), **dunning** (three levels), **rent increases** (§ 558 / § 557b BGB)
* **Tenant portal** via shortcode `[mietverwaltung_portal]` – rent account, statements, released documents, damage reports, meter readings
* **Data protection** – deletion concept with retention periods, anonymisation, Art. 15 GDPR access report
* **Setup wizard**, in-app help, focus mode, backup/restore as JSON, clean uninstall

The plugin does not replace legal or tax advice. Contract templates and calculations are based on German law as of 2026 and must be checked before use.

= Deutsch =

Mietverwaltung für private Vermieter: Stammdaten, Zahlungen, Belege, Betriebskostenabrechnung nach BetrKV, Steuer (Anlage V), AfA, Darlehen, Mietvertrag mit allen Anlagen, Kaution, Mahnwesen, Mieterhöhung, Mieterportal und Datenschutz-Löschkonzept in einem Plugin. Alle Daten bleiben in der eigenen WordPress-Datenbank. Einrichtungsassistent und Hilfe sind im Backend enthalten.

== Installation ==

1. Upload the plugin and activate it – the `wp_mv_*` tables are created automatically.
2. Follow the setup wizard (landlord → property → units → tenant → tenancy) or open **Mietverwaltung → Help**.
3. For the tenant portal, insert the shortcode `[mietverwaltung_portal]` on a page and link the WordPress user on the tenant record.

== Frequently Asked Questions ==

= Is any data sent to third parties? =

No. All data is stored in the WordPress database and media library. The purchase contract parser (pdf.js) is bundled with the plugin and processes the PDF locally in the browser.

= Can I manage a flat and a garage in one agreement? =

Yes. Tick "Additional units in the same agreement" on the tenancy – you get one total rent, one booking and one statement.

= Is the generated tenancy agreement legally safe? =

The generator follows the German Civil Code (BGB) and Federal Court of Justice case law as of 2026, in its own wording. Have it checked by a lawyer in special situations (commercial, shared flats, furnished, rent cap areas).

= Which language is the interface in? =

German and English. WordPress selects the language from the site or user setting. Legal documents are generated in German only.

= What happens on uninstall? =

Data is kept by default. You can enable "Delete all tables and settings" in the plugin settings before deleting the plugin.

== Screenshots ==

1. Overview with open items – tick a month to book the rent payment
2. Setup wizard
3. Tenancy agreement options
4. Service charge statement
5. Tax report (Anlage V)
6. Loan account with repayment schedule
7. Tenant portal

== Changelog ==

= 0.9.9 =
* Setup wizard (five steps from landlord to first tenancy)
* WordPress Coding Standards: full PHPCS compliance, prefix changed to `mietv_` (existing installations are migrated automatically)
* pdf.js bundled locally instead of loaded from a CDN
* Fixed placeholder order in the index-linked rent notice

= 0.9.8 =
* Help page and contextual help in the admin area
* Complete English translation

= 0.9.7 =
* Spouse/partner as optional co-tenant – automatically included in agreement, letters, SEPA mandate and handover protocol

= 0.9.6 =
* Structured admin menu with groups and icons

= 0.9.5 =
* Tenancy agreement extended to 20 sections, rent cap exceptions, § 554 BGB, heating/ventilation leaflet

= 0.9.0 – 0.9.4 =
* Additional units per tenancy, tick open items, loan account, depreciation fix, itemised acquisition costs

Full changelog (German): https://github.com/sporcherla/mietverwaltung/blob/main/CHANGELOG.md
