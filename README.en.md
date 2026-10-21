# Mietverwaltung – WordPress plugin for private landlords

[Deutsche Version → README.md](README.md)

**Mietverwaltung** (“rental management”) puts everything a private landlord needs for one or more flats into a single WordPress plugin: master data, rent receipts, expense receipts, service charge statements, German tax reporting (Anlage V), a complete tenancy agreement with all annexes, deposit account, loans, dunning, rent increases and a tenant portal. All data stays in your own WordPress database – no cloud service, no data transfer to third parties.

- Version **0.9.9** · License GPL-2.0-or-later · WordPress ≥ 6.0 · PHP ≥ 8.0
- Languages: German (source), English (complete UI translation)
- Scope: long-term residential letting under **German law** (not holiday lets)

> The plugin implements German tenancy and tax law (BGB, BetrKV, HeizkostenV, EStG). The user interface, forms, help and letters are fully available in English; the generated tenancy agreement, house rules and leaflet remain in German because they are legal documents for use in Germany.

---

## Contents

1. [Feature overview](#feature-overview)
2. [Installation](#installation)
3. [Quick start](#quick-start)
4. [Modules](#modules)
5. [Tenant portal](#tenant-portal)
6. [Languages](#languages)
7. [Privacy & security](#privacy--security)
8. [Data model](#data-model)
9. [Development & tests](#development--tests)
10. [Roadmap](#roadmap)
11. [Legal notes](#legal-notes)

---

## Feature overview

| Area | What the plugin does |
|---|---|
| **Master data** | Properties (house/condominium with year built, heating type), units (flat, garage, parking space, cellar), tenants with optional spouse/partner as co-tenant, tenancies with additional units (flat + garage = one agreement, one total rent) |
| **Payments** | Due/paid per month derived from rent conditions, tick open items to book them on the 1st of the month, bank import (CSV / CAMT.053) with matching rules and duplicate detection |
| **Receipts** | Cost type, tax year, service period, file (camera upload on mobile); spreading over 2–5 years (§ 82b EStDV); § 35a labour share for tenants |
| **Service charge statement** | Per BetrKV with keys area / persons / units / consumption, HeizkostenV 50–70 %, pro rata on tenant change, vacancy borne by landlord, advance payments offset, § 35a certificate, adjustment of advance payments (§ 560 (4)) |
| **Tax (Anlage V)** | Cash basis, income split into rent and recoverable charges, expenses by Anlage V group with adjustable line numbers, depreciation, loan interest, prior-year comparison with chart, CSV export |
| **Depreciation** | Straight-line (statutory rate by year built: 2 % / 2.5 % / 3 %) or declining balance, pro rata; itemised acquisition costs (transfer tax, notary, land registry, agent, other); land share; purchase contract PDF parsed in the browser with suggested values; 15 % guard (§ 6 (1) no. 1a EStG) |
| **Financing** | Loans with annuity repayment schedule, tick instalments, extra repayments, annual figures → Anlage V; bank interest certificate takes precedence; fixed-rate period reminder |
| **Tenancy agreement** | Residential agreement with 20 sections generated from master data (fixed term, stepped/index rent, minor repairs, pets, decorative repairs, rent cap exceptions, § 554 BGB …), house rules, heating/ventilation leaflet, Art. 13 GDPR privacy notice, SEPA direct debit mandate, move-in/move-out handover protocol – as PDF or print view, filed under Documents |
| **Deposit** | Deposit account under § 551 BGB: payments, daily interest, offsetting, settlement as PDF |
| **Dunning** | Payment reminder, 1st and final dunning letter with deadlines, PDF, email to tenant and co-tenant, warning at ≥ 2 months' arrears |
| **Rent increase** | § 558 (reference rent, cap 15/20 %, waiting period), § 557b (index/CPI), agreement/stepped rent; letter and new rent condition created automatically |
| **Tenant portal** | Shortcode `[mietverwaltung_portal]`: rent account, statements, released documents, damage reports, meter readings |
| **Documents** | Storage with type, date, portal release; all generated PDFs can be filed here automatically |
| **Data protection** | Deletion concept with retention periods (§ 147 AO), anonymisation of ended tenancies, Art. 15 access report as JSON, notes for the record of processing activities |
| **System** | Setup wizard, help page + contextual help, structured menu with icons, focus mode (hide WordPress menu), dashboard widget with notices, daily email reminder, backup/restore as JSON, dedicated “Landlord” role, clean uninstall |

---

## Installation

**From the release ZIP**

1. Upload `mietverwaltung.zip` under **Plugins → Add New → Upload Plugin** and activate.
2. On activation the `wp_mv_*` tables are created, cost types per BetrKV are pre-filled and the “Landlord” role is set up.
3. The **Mietverwaltung** menu appears in the admin area.

**From the repository**

```bash
cd wp-content/plugins
git clone https://github.com/sporcherla/mietverwaltung.git
```

Then activate in the admin area. Updates: `git pull` – database changes are applied automatically on the next request (`MV_DB::maybe_upgrade`).

**Requirements:** WordPress ≥ 6.0, PHP ≥ 8.0, MySQL/MariaDB. The purchase contract parser (pdf.js, Apache-2.0) is bundled with the plugin and runs entirely in the browser.

To use the English interface, set the site or user language to **English (United States)** in WordPress.

---

## Quick start

The same guide is available in the admin area under **Mietverwaltung → Help**.

1. **Settings** – landlord details, bank account, creditor ID, tax number.
2. **Property & units** – create the house/condominium (year built, heating type), then flat, garage, parking space with area.
3. **Tenants** – master data, optionally spouse/partner as co-tenant.
4. **Tenancy & rent conditions** – tenant + unit + start date; tick the garage as additional unit; base rent and advance payments with valid-from date.
5. **Asset & loan** – purchase price, incidental costs, land share (let the purchase contract PDF be parsed); loan with interest, repayment, instalment.
6. **Day-to-day** – tick rent receipts or import from the bank, photograph receipts, record meter readings. At year end: service charge statement and Anlage V.

---

## Modules

### Overview
Open items per tenancy and month with cumulative balance. Tick → “Book selected as paid” creates the payment dated the 1st of the month (source “ticked”, editable later). Focus mode hides the rest of the WordPress menu (per user).

### Payments and bank import
Each payment belongs to a tenancy and a month. The bank import reads CSV exports (header row detected) or CAMT.053 XML, recognises previously imported transactions by hash, matches income by IBAN or last name and expenses via rules (search text → cost type). Rules can be saved directly during import.

### Receipts and tax
Receipts carry a cost type (determines recoverability and Anlage V group), tax year (10-day rule), service period (for the statement) and optionally § 82b spreading and § 35a labour share. The tax page works on a cash basis (§ 11 EStG), splits rent receipts into rent and recoverable charges, pulls in depreciation and loan interest and shows the trend against the prior year. Anlage V line numbers are entered once and pre-filled for subsequent years.

### Depreciation
Buildings: acquisition costs = purchase price + itemised incidental costs, less land share. Empty rate = statutory by year built (§ 7 (4) EStG), declining balance 5 % for new builds 10/2023–09/2029. Financing costs (notary/land registry for the land charge) are automatically created as an immediately deductible receipt. The purchase contract PDF is parsed locally in the browser (pdf.js) – purchase price, transfer date, land share and date are suggested; nothing leaves the browser before saving.

### Loan account
Repayment schedule from disbursement (interest = balance × rate / 12, repayment = instalment − interest). Tick instalments, book extra repayments, estimate remaining term. Annual figures flow into Anlage V as loan interest unless an interest certificate is entered.

### Service charge statement
Select period → Calculate. Receipts are allocated by the cost type's key, heating per HeizkostenV (consumption share on the property), vacancy borne by the landlord, tenant changes pro rata. Result per tenant with items, § 35a certificate, letter as PDF, filing and portal release, adjust advance payment.

### Tenancy agreement & handover
Options per tenancy (stored as JSON). Generates: tenancy agreement (20 sections, own wording per BGB and Federal Court of Justice case law, BetrKV catalogue with allocation key table), house rules, heating and ventilation leaflet, privacy notice, SEPA mandate, move-in/move-out handover protocol (with meters of all units). Co-tenants from the tenant record and additional persons from the free-text field appear automatically with their own signature lines.

### Deposit, dunning, rent increase
See feature overview. All letters use the shared letterhead (Settings), generate PDFs through the built-in engine without external libraries, offer an HTML print view (`?format=html`) and can be filed directly under Documents.

### Notices
Dashboard widget and daily cron check (`mv_taeglich`): missing rent, statement deadline, fixed-rate period, interest certificate, 15 % limit, retention period, end of tenancy. Optionally by email.

---

## Tenant portal

Insert the shortcode `[mietverwaltung_portal]` on a page. Link the WordPress user on the tenant record (the “Subscriber” role is sufficient). Logged-in tenants see: rent conditions and rent account for the last 12 months, service charge statements, released documents; they can report damage and submit meter readings (with photo). The landlord is notified by email.

---

## Languages

- Text domain `mietverwaltung`, source language German, POT under `languages/`.
- **English** complete: `mietverwaltung-en_US.po/.mo`. WordPress selects the language by site or user setting.
- The tenancy agreement, house rules and leaflet deliberately remain in German – they are legal documents under German law.
- Further languages: translate the POT with Poedit or Loco Translate and save as `mietverwaltung-<locale>.mo`.

---

## Privacy & security

- Access only with capability `mv_verwalten` (administrators and the “Landlord” role); all forms use nonces; input is sanitised, output escaped.
- Tenant portal only for the linked user; documents only when explicitly released.
- No external services; files are stored in the WordPress media library.
- Deletion concept: retention period (default 10 years, § 147 AO), anonymisation of ended tenancies, Art. 15 / Art. 20 access report as JSON.
- Uninstall: data is kept by default; option “Delete all tables and settings” in Settings.

---

## Data model

All tables use the prefix `wp_mv_` (dbDelta, `owner_id` prepared for multi-tenancy):

`objekte`, `einheiten`, `mieter`, `mietverhaeltnisse`, `mv_einheiten` (additional units), `konditionen`, `zahlungen`, `belege`, `kostenarten`, `anlagegueter`, `darlehen`, `darlehen_raten`, `darlehen_jahre`, `dokumente`, `zaehler`, `zaehlerstaende`, `abrechnungen`, `abrechnung_ergebnis`, `bank_regeln`, `bank_import`, `mahnungen`, `erhoehungen`, `vertraege`, `kaution`, `meldungen`.

Settings in `wp_options` (`mv_einstellungen`), focus mode in `user_meta`, database version `mv_db_version`.

---

## Development & tests

```
mietverwaltung.php        bootstrap, plugin header
includes/                 one class per module (MV_Admin, MV_Soll, MV_Abrechnung, MV_Steuer, MV_AfA, MV_Darlehen, MV_Mietvertrag, MV_PDF, MV_Brief, MV_Hilfe, …)
assets/                   admin.css, menu.css, kaufvertrag.js
languages/                POT, en_US
tests/                    tests without WordPress (SQLite $wpdb stub)
uninstall.php
```

Tests run without a WordPress installation:

```bash
php tests/save_all_test.php    # save/update all entities
php tests/abr_test.php         # service charge statement vs. manual calculation
php tests/afa_test.php         # depreciation cases
php tests/darlehen_test.php    # repayment schedule
php tests/menu_test.php        # menu structure
```

Changes per version: [CHANGELOG.md](CHANGELOG.md) (German). Contributions are welcome – please open issues or pull requests on GitHub.

---

## Roadmap

- Free/Pro split for WordPress.org (3 units free)
- Quick entry (receipt + photo in three clicks)
- Publication in the WordPress.org directory
- Further languages

---

## Legal notes

The plugin does not replace legal or tax advice. Contract templates, calculations and deadlines are based on German law (BGB, BetrKV, HeizkostenV, EStG, AO) as of 2026 and must be checked by a professional before use – especially in special situations (commercial, shared flats, furnished, rent cap areas). The contract generator uses its own wording; association templates were used only as a checklist.

License: [GPL-2.0-or-later](LICENSE). Author: Markus Hirn.
