# Einreichung bei WordPress.org – Checkliste

## Stand nach dem zweiten Review (28.10.2026)

Zwei Themen: Nonce-Logik und spätes Escaping. Beides ist in 1.0.2 umgesetzt (Details in CHANGELOG.md).

| Punkt | Umsetzung |
|---|---|
| `Mietv_Portal::handle_abrechnung()` ohne Nonce, `ablegen=1` durch Mieter | Portal-Link mit `wp_nonce_url()`, `wp_verify_nonce()` vor dem Lesen; Ablage nur noch im Admin nach `check_admin_referer()` |
| `handle_actions()` liest `mietv_entity` vor der Nonce | `check_admin_referer( 'mietv_save' )` ist die erste Anweisung |
| `handle_meldung`/`handle_zaehler`: `! is_user_logged_in() \|\| ! wp_verify_nonce` | Getrennte Prüfungen, jede mit eigenem `wp_die()` |
| `Mietv_Abrechnung::brief()` liest `erg` vor der Nonce | Capability → `check_admin_referer()` → Lesen |
| `?msg=` in `page()`-Funktionen | Transients (`mietv_flash_set/get`) statt URL-Parameter |
| Alle `phpcs:ignore EscapeOutput` | Entfernt: `esc_attr()` für Attribute, `wp_kses()`/`wp_kses_post()` für HTML-Fragmente, SVG-Whitelist für das Diagramm; einzige Ausnahme der PDF-Byte-Stream |

**Antwort an das Review-Team** (kurz, im selben E-Mail-Thread):

> Thank you for the detailed review. I have uploaded version 1.0.2.
>
> Nonces: every handler now verifies the nonce (check_admin_referer / wp_verify_nonce) before reading any input, with the login and nonce checks separated. The tenant-portal statement link now carries a nonce, and storing a generated PDF ("ablegen") is only possible from the admin endpoints after their nonce check. Status notices after redirects use per-user transients instead of ?msg= parameters, so the page() functions no longer read request data.
>
> Escaping: all output marked with phpcs:ignore has been reworked to escape late – esc_attr() for attributes, wp_kses() with an explicit whitelist for generated form/table/SVG fragments and wp_kses_post() for letter bodies and contract paragraphs. The only remaining exception is the binary PDF stream sent with Content-Type: application/pdf.
>
> PHPCS (WordPress-Extra + WordPress-Docs) and Plugin Check report no issues; tested on a clean install with WP_DEBUG enabled.

## Stand nach dem ersten Review (26.10.2026)

Das Review-Team hat fünf Punkte genannt; alle sind in 1.0.1 umgesetzt:

| Punkt | Umsetzung |
|---|---|
| Name „Mietverwaltung“ zu generisch | Neuer Name **Mietfux – Mietverwaltung für Vermieter**, Slug `mietfux`, Text-Domain `mietfux`, Hauptdatei `mietfux.php` |
| Inline `<style>`/`<script>` | Portal-CSS, Druckansichten und Fokusmodus über `wp_enqueue_style`/`wp_add_inline_style`/`wp_print_styles`, Auto-Druck als `assets/druck.js` |
| Escaping (`__()` mit `&nbsp;`) | String aufgeteilt, `esc_html__()` |
| Contributors | `hirnmarkus` |
| Übersetzungsdateien / `load_plugin_textdomain()` | `.po/.mo` nicht mehr im Paket, Aufruf entfernt |

**Antwort an das Review-Team** (kurz, im selben E-Mail-Thread):

> Thank you for the review. I have uploaded version 1.0.1.
>
> Please reserve the new permalink **mietfux** (display name: “Mietfux – Mietverwaltung für Vermieter”). Plugin headers, readme and text domain already use it.
>
> All inline CSS/JS has been moved to enqueued assets, the escaping issue is fixed, translation files and load_plugin_textdomain() have been removed, and the contributor is now my WordPress.org username.
>
> Note: the plugin keeps its own database tables (wp_mv_*), hence the direct $wpdb queries; the generated tenancy agreement and house rules are German legal documents and are intentionally not translated.



Stand: Version 1.0.2. Alles Technische ist erledigt; die folgenden Schritte kann nur der Autor selbst ausführen (Account, Formular, SVN).

## Bereits erledigt (geprüft in dieser Version)

| Anforderung | Status |
|---|---|
| Eindeutiger Präfix ≥ 4 Zeichen für Funktionen, Klassen, Konstanten, Optionen, Hooks, Handles | ✅ `mietv_` / `Mietv_` / `MIETV_` |
| WordPress Coding Standards (PHPCS mit `WordPress` + `PHPCompatibility`) | ✅ 0 Fehler, 0 Warnungen (`phpcs.xml.dist`) |
| **Plugin Check** (offizielles Prüfwerkzeug, inkl. experimenteller Checks) | ✅ 0 Fehler, 0 Warnungen |
| Keine externen Skripte/CDN („Offloading“) | ✅ pdf.js liegt in `assets/pdfjs/` (Apache-2.0, Lizenz beiliegend) |
| Minifizierte Dateien nur mit Quellversion | ✅ `pdf.js` + `pdf.min.js`, `pdf.worker.js` + `pdf.worker.min.js` |
| Ausgabe-Escaping, Input-Sanitizing, Nonces, Capability-Prüfung | ✅ |
| Direkte Datenbankzugriffe begründet (eigene Tabellen) | ✅ Datei-Kommentare `phpcs:disable WordPress.DB.DirectDatabaseQuery` |
| `readme.txt` auf Englisch, gültige Kopfzeilen, ≤ 5 Tags, Kurzbeschreibung ≤ 150 Zeichen | ✅ |
| `Tested up to` = aktuelle stabile WordPress-Version | ✅ 7.1 (Stand Oktober 2026 – vor dem Absenden prüfen) |
| `Stable tag` = Version im Plugin-Header | ✅ 1.0.0 |
| GPL-kompatible Lizenz, Lizenzdatei | ✅ GPL-2.0-or-later, `LICENSE` |
| Deinstallation räumt auf (optional, Standard: Daten behalten) | ✅ `uninstall.php` |
| Übersetzbar, Text Domain = Slug, Domain Path | ✅ `mietverwaltung`, `/languages` |
| Banner 772×250 / 1544×500, Icon 128 / 256 / SVG, Screenshots | ✅ `.wordpress-org/` |
| Funktionstest in echter WordPress-Installation (7.1.3) | ✅ Aktivierung, Upgrade-Routine, alle Admin-Seiten |

## Vom Autor zu erledigen

1. **WordPress.org-Konto** anlegen bzw. anmelden (https://login.wordpress.org/). Der Nutzername wird der Contributor in `readme.txt` – dort steht derzeit `sporcherla`; ggf. anpassen.
2. **Slug prüfen**: Gewünscht ist `mietverwaltung`. Falls bereits vergeben, schlägt das Review-Team einen anderen vor; dann müssen Text Domain und Ordnername folgen (Suchen/Ersetzen `mietverwaltung` → neuer Slug in Plugin-Header, `load_plugin_textdomain`, Dateinamen unter `languages/`).
3. **ZIP hochladen** unter https://wordpress.org/plugins/developers/add/ – die Datei `mietfux.zip` aus dem Release (ohne `tests/`, `phpcs.xml.dist`, Markdown-Dateien, `.wordpress-org/`).
4. **Review abwarten** (derzeit mehrere Wochen). Rückfragen kommen per E-Mail an die Account-Adresse; Antworten im selben Thread. Typische Nachfragen bei diesem Plugin könnten sein:
   - *Direkte DB-Queries*: Begründung „eigene Tabellen `wp_mv_*`, keine WP-Objekte“ – steht als Kommentar in jeder Datei.
   - *Dateischreiben in `uploads/mietverwaltung`*: erfolgt über `WP_Filesystem`, Dateien werden als Attachments registriert.
   - *Rechtstexte nur deutsch*: bewusst, da deutsches Mietrecht; in `readme.txt` erklärt.
5. **Nach Freigabe**: SVN-Zugang kommt per Mail. Erstes Commit:
   ```bash
   svn co https://plugins.svn.wordpress.org/mietverwaltung mietverwaltung-svn
   cd mietverwaltung-svn
   # Plugin-Dateien (Inhalt des ZIP) nach trunk/, Bilder aus .wordpress-org/ nach assets/
   svn add trunk/* assets/*
   svn ci -m "Version 1.0.2"
   svn cp trunk tags/1.0.0 && svn ci -m "Tag 1.0.0"
   ```
   Alternativ den GitHub-Workflow „10up/action-wordpress-plugin-deploy“ einrichten, der bei jedem Git-Tag automatisch nach SVN deployt.
6. **Übersetzungen**: Nach Freigabe übernimmt translate.wordpress.org die Sprachpakete; die mitgelieferte `en_US` bleibt als Fallback.

## Release-Paket bauen (lokal)

```bash
cd ..
zip -r mietfux.zip mietverwaltung \
  -x 'mietfux/.git/*' 'mietfux/tests/*' 'mietfux/.wordpress-org/*' \
     'mietfux/phpcs.xml.dist' 'mietfux/.gitignore' 'mietfux/.distignore' \
     'mietfux/*.md'
```

## Prüfwerkzeuge erneut laufen lassen

```bash
# PHPCS (WPCS, PHPCSUtils, PHPCSExtra, PHPCompatibility müssen als installed_paths registriert sein)
phpcs

# Plugin Check in einer WordPress-Installation mit aktiviertem Plugin „Plugin Check“
wp plugin check mietfux --include-experimental
```
