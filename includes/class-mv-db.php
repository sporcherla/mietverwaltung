<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

class Mietv_DB {

	const DB_VERSION = '13';

	public static function install(): void {
		self::create_tables();
		self::seed_kostenarten();
		self::rollen();
		update_option( 'mietv_db_version', self::DB_VERSION );
		if ( class_exists( 'Mietv_Assistent' ) ) {
			Mietv_Assistent::aktiviert();
		}
	}

	public static function maybe_upgrade(): void {
		self::migrate_prefix();
		if ( get_option( 'mietv_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			self::seed_kostenarten();
			self::rollen();
			update_option( 'mietv_db_version', self::DB_VERSION );
		}
	}

	/** Rolle „Vermieter“ mit eigener Capability; Administratoren erhalten sie ebenfalls. */
	/**
	 * Versionen < 0.9.9 nutzten den Präfix „mv_“ für Optionen, Rolle, Capability und Benutzer-Meta.
	 * Einmalige Übernahme; die Tabellen behalten ihren Namen wp_mv_*.
	 */
	public static function migrate_prefix(): void {
		if ( get_option( 'mietv_db_version' ) !== false || get_option( 'mv_db_version' ) === false ) {
			return;
		}
		foreach ( [
			'mv_einstellungen'  => 'mietv_einstellungen',
			'mv_db_version'     => 'mietv_db_version',
			'mv_assistent'      => 'mietv_assistent',
			'mv_anlagev_zeilen' => 'mietv_anlagev_zeilen',
			'mv_hinweis_datum'  => 'mietv_hinweis_datum',
			'mv_hinweis_hash'   => 'mietv_hinweis_hash',
		] as $alt => $neu ) {
			$wert = get_option( $alt, null );
			if ( null !== $wert ) {
				update_option( $neu, $wert, 'mietv_einstellungen' === $neu );
				delete_option( $alt );
			}
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s", 'mietv_fokus', 'mv_fokus' ) );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'mv_verwalten' );
		}
		$alt_rolle = get_role( 'mv_vermieter' );
		if ( $alt_rolle ) {
			$nutzer = get_users(
				[
					'role'   => 'mv_vermieter',
					'fields' => 'ID',
				]
			);
			remove_role( 'mv_vermieter' );
			self::rollen();
			foreach ( $nutzer as $uid ) {
				$u = get_user_by( 'id', $uid );
				if ( $u ) {
					$u->add_role( 'mietv_vermieter' );
				}
			}
		}
		wp_clear_scheduled_hook( 'mv_taeglich' );
	}

	public static function rollen(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'mietv_verwalten' );
		}
		if ( ! get_role( 'mietv_vermieter' ) ) {
			add_role(
				'mietv_vermieter',
				__( 'Landlord', 'mietfux' ),
				[
					'read'            => true,
					'upload_files'    => true,
					'mietv_verwalten' => true,
				]
			);
		} else {
			get_role( 'mietv_vermieter' )->add_cap( 'mietv_verwalten' );
		}
	}

	/** Anlage-V-Gruppen (Zeilennummern ändern sich jährlich, daher nur Bezeichnungen). */
	public static function anlage_v_gruppen(): array {
		return [
			'einnahmen'    => esc_html__( 'Income (info only, do not book as receipt)', 'mietfux' ),
			'afa'          => esc_html__( 'Depreciation (AfA)', 'mietfux' ),
			'schuldzinsen' => esc_html__( 'Loan interest and financing costs', 'mietfux' ),
			'erhaltung'    => esc_html__( 'Maintenance expenses', 'mietfux' ),
			'grundsteuer'  => esc_html__( 'Property tax, street cleaning, refuse collection', 'mietfux' ),
			'wasser'       => esc_html__( 'Water supply, drainage, communal lighting', 'mietfux' ),
			'heizung'      => esc_html__( 'Heating, hot water', 'mietfux' ),
			'versicherung' => esc_html__( 'Chimney sweeping, building insurance, caretaker, stair cleaning, lift', 'mietfux' ),
			'verwaltung'   => esc_html__( 'Administration costs', 'mietfux' ),
			'sonstiges'    => esc_html__( 'Other expenses', 'mietfux' ),
		];
	}

	private static function seed_kostenarten(): void {
		global $wpdb;
		$table = mietv_table( 'kostenarten' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) > 0 ) {  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellenname intern.
			return;
		}
		// name, anlage_v, umlagefaehig, schluessel, betrkv
		$seed = [
			[ 'Sonderabschreibung / AfA manuell (nur falls nicht über Anlagegüter)', 'afa', 0, '', '' ],
			[ 'Schuldzinsen (nur falls nicht über Darlehen erfasst)', 'schuldzinsen', 0, '', '' ],
			[ 'Geldbeschaffungskosten / Disagio', 'schuldzinsen', 0, '', '' ],
			[ 'Erhaltungsaufwand (Reparatur, Instandhaltung)', 'erhaltung', 0, '', '' ],
			[ 'Grundsteuer', 'grundsteuer', 1, 'flaeche', '§ 2 Nr. 1 BetrKV' ],
			[ 'Straßenreinigung / Müllabfuhr', 'grundsteuer', 1, 'personen', '§ 2 Nr. 8 BetrKV' ],
			[ 'Wasser / Abwasser', 'wasser', 1, 'verbrauch', '§ 2 Nr. 2–3 BetrKV' ],
			[ 'Allgemeinstrom / Hausbeleuchtung', 'wasser', 1, 'flaeche', '§ 2 Nr. 11 BetrKV' ],
			[ 'Heizung / Warmwasser (Brennstoff, Wartung, Ablesung)', 'heizung', 1, 'verbrauch', '§ 2 Nr. 4–6 BetrKV' ],
			[ 'Schornsteinfeger', 'versicherung', 1, 'flaeche', '§ 2 Nr. 12 BetrKV' ],
			[ 'Gebäudeversicherung / Haftpflicht', 'versicherung', 1, 'flaeche', '§ 2 Nr. 13 BetrKV' ],
			[ 'Hausmeister / Gartenpflege / Treppenhausreinigung', 'versicherung', 1, 'flaeche', '§ 2 Nr. 9–10, 14 BetrKV' ],
			[ 'Hausverwaltung / WEG-Verwalter', 'verwaltung', 0, '', '' ],
			[ 'Kontoführung', 'verwaltung', 0, '', '' ],
			[ 'Sonstige umlagefähige Betriebskosten', 'sonstiges', 1, 'flaeche', '§ 2 Nr. 17 BetrKV' ],
			[ 'Sonstige Werbungskosten (Fahrten, Porto, Software, Steuerberater)', 'sonstiges', 0, '', '' ],
		];
		foreach ( $seed as $i => $s ) {
			$wpdb->insert(
				$table,
				[
					'owner_id'     => MIETV_OWNER_ID,
					'name'         => $s[0],
					'anlage_v'     => $s[1],
					'umlagefaehig' => $s[2],
					'schluessel'   => $s[3],
					'betrkv'       => $s[4],
					'sortierung'   => ( $i + 1 ) * 10,
				]
			);
		}
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql = [];

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_objekte (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  strasse varchar(190) NOT NULL DEFAULT '',
  plz varchar(10) NOT NULL DEFAULT '',
  ort varchar(100) NOT NULL DEFAULT '',
  baujahr smallint(5) unsigned DEFAULT NULL,
  heizung varchar(20) NOT NULL DEFAULT 'zentral',
  hk_verbrauchsanteil tinyint(3) unsigned NOT NULL DEFAULT 70,
  notiz text,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY owner_id (owner_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_einheiten (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  objekt_id bigint(20) unsigned NOT NULL,
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  lage varchar(100) NOT NULL DEFAULT '',
  flaeche decimal(8,2) NOT NULL DEFAULT 0,
  zimmer decimal(3,1) NOT NULL DEFAULT 0,
  typ varchar(30) NOT NULL DEFAULT 'wohnung',
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_mieter (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  anrede varchar(20) NOT NULL DEFAULT '',
  vorname varchar(100) NOT NULL DEFAULT '',
  nachname varchar(100) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  telefon varchar(50) NOT NULL DEFAULT '',
  geburtsdatum date DEFAULT NULL,
  anschrift_alt varchar(190) NOT NULL DEFAULT '',
  p_anrede varchar(20) NOT NULL DEFAULT '',
  p_vorname varchar(100) NOT NULL DEFAULT '',
  p_nachname varchar(100) NOT NULL DEFAULT '',
  p_geburtsdatum date DEFAULT NULL,
  p_email varchar(190) NOT NULL DEFAULT '',
  iban varchar(40) NOT NULL DEFAULT '',
  wp_user_id bigint(20) unsigned DEFAULT NULL,
  notiz text,
  PRIMARY KEY  (id),
  KEY owner_id (owner_id),
  KEY wp_user_id (wp_user_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_mietverhaeltnisse (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  einheit_id bigint(20) unsigned NOT NULL,
  mieter_id bigint(20) unsigned NOT NULL,
  beginn date NOT NULL,
  ende date DEFAULT NULL,
  kaution decimal(10,2) NOT NULL DEFAULT 0,
  personen tinyint(3) unsigned NOT NULL DEFAULT 1,
  zahltag tinyint(3) unsigned NOT NULL DEFAULT 3,
  notiz text,
  PRIMARY KEY  (id),
  KEY einheit_id (einheit_id),
  KEY mieter_id (mieter_id)
) $charset;";

		// Mietkonditionen mit Gültigkeitsbeginn (Staffel, Erhöhung, VZ-Anpassung)
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_konditionen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  gueltig_ab date NOT NULL,
  kaltmiete decimal(10,2) NOT NULL DEFAULT 0,
  bk_vz decimal(10,2) NOT NULL DEFAULT 0,
  hk_vz decimal(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY mv_id (mv_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_zahlungen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  betrag decimal(10,2) NOT NULL DEFAULT 0,
  monat date NOT NULL,
  verwendungszweck varchar(190) NOT NULL DEFAULT '',
  quelle varchar(20) NOT NULL DEFAULT 'manuell',
  PRIMARY KEY  (id),
  KEY mv_id (mv_id),
  KEY monat (monat)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_meldungen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  datum datetime NOT NULL,
  betreff varchar(190) NOT NULL DEFAULT '',
  text text,
  status varchar(20) NOT NULL DEFAULT 'offen',
  PRIMARY KEY  (id),
  KEY mv_id (mv_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_kostenarten (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  name varchar(190) NOT NULL DEFAULT '',
  anlage_v varchar(30) NOT NULL DEFAULT 'sonstiges',
  umlagefaehig tinyint(1) NOT NULL DEFAULT 0,
  schluessel varchar(20) NOT NULL DEFAULT '',
  betrkv varchar(60) NOT NULL DEFAULT '',
  sortierung int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY owner_id (owner_id)
) $charset;";

		// Belege: Zahlungsdatum = Abflusszeitpunkt (§ 11 EStG), Zeitraum = Leistungszeitraum für die BK-Abrechnung
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_belege (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  objekt_id bigint(20) unsigned NOT NULL,
  kostenart_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  steuerjahr smallint(5) unsigned NOT NULL,
  betrag decimal(10,2) NOT NULL DEFAULT 0,
  zeitraum_von date DEFAULT NULL,
  zeitraum_bis date DEFAULT NULL,
  lieferant varchar(190) NOT NULL DEFAULT '',
  belegnr varchar(100) NOT NULL DEFAULT '',
  beschreibung text,
  attachment_id bigint(20) unsigned DEFAULT NULL,
  verteilung_jahre tinyint(3) unsigned NOT NULL DEFAULT 1,
  par35a varchar(20) NOT NULL DEFAULT '',
  lohnanteil decimal(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id),
  KEY kostenart_id (kostenart_id),
  KEY steuerjahr (steuerjahr)
) $charset;";

		// Anlagegüter: Gebäude (mit Grund-und-Boden-Anteil) und bewegliche Wirtschaftsgüter
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_anlagegueter (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  objekt_id bigint(20) unsigned NOT NULL,
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  typ varchar(20) NOT NULL DEFAULT 'gebaeude',
  anschaffung date NOT NULL,
  ak decimal(12,2) NOT NULL DEFAULT 0,
  nebenkosten decimal(12,2) NOT NULL DEFAULT 0,
  nk_grunderwerbsteuer decimal(12,2) NOT NULL DEFAULT 0,
  nk_notar decimal(12,2) NOT NULL DEFAULT 0,
  nk_grundbuch decimal(12,2) NOT NULL DEFAULT 0,
  nk_makler decimal(12,2) NOT NULL DEFAULT 0,
  nk_sonstige decimal(12,2) NOT NULL DEFAULT 0,
  nk_finanzierung decimal(12,2) NOT NULL DEFAULT 0,
  grundanteil decimal(5,2) NOT NULL DEFAULT 0,
  methode varchar(20) NOT NULL DEFAULT 'linear',
  satz decimal(5,2) NOT NULL DEFAULT 0,
  nutzungsdauer smallint(5) unsigned DEFAULT NULL,
  ende date DEFAULT NULL,
  attachment_id bigint(20) unsigned DEFAULT NULL,
  notiz text,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_darlehen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  objekt_id bigint(20) unsigned NOT NULL,
  bank varchar(190) NOT NULL DEFAULT '',
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  betrag decimal(12,2) NOT NULL DEFAULT 0,
  auszahlung date DEFAULT NULL,
  zinssatz decimal(5,3) NOT NULL DEFAULT 0,
  zinsbindung_bis date DEFAULT NULL,
  rate decimal(10,2) NOT NULL DEFAULT 0,
  tilgung decimal(5,2) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'aktiv',
  attachment_id bigint(20) unsigned DEFAULT NULL,
  notiz text,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id)
) $charset;";

		// Dokumentablage: Kaufvertrag, Mietvertrag, Übergabeprotokoll, Grundbuch …
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_dokumente (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  objekt_id bigint(20) unsigned NOT NULL,
  mv_id bigint(20) unsigned DEFAULT NULL,
  typ varchar(30) NOT NULL DEFAULT 'sonstiges',
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  datum date DEFAULT NULL,
  mieter_sichtbar tinyint(1) NOT NULL DEFAULT 0,
  attachment_id bigint(20) unsigned DEFAULT NULL,
  notiz text,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id),
  KEY mv_id (mv_id)
) $charset;";

		// Jahreswerte aus der Zinsbescheinigung der Bank
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_darlehen_jahre (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  darlehen_id bigint(20) unsigned NOT NULL,
  jahr smallint(5) unsigned NOT NULL,
  zinsen decimal(10,2) NOT NULL DEFAULT 0,
  tilgung decimal(10,2) NOT NULL DEFAULT 0,
  restschuld decimal(12,2) NOT NULL DEFAULT 0,
  attachment_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY darlehen_id (darlehen_id),
  KEY jahr (jahr)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_zaehler (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  objekt_id bigint(20) unsigned NOT NULL,
  einheit_id bigint(20) unsigned DEFAULT NULL,
  typ varchar(20) NOT NULL DEFAULT 'kaltwasser',
  nummer varchar(60) NOT NULL DEFAULT '',
  notiz text,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id),
  KEY einheit_id (einheit_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_zaehlerstaende (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zaehler_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  stand decimal(12,3) NOT NULL DEFAULT 0,
  attachment_id bigint(20) unsigned DEFAULT NULL,
  quelle varchar(20) NOT NULL DEFAULT 'vermieter',
  PRIMARY KEY  (id),
  KEY zaehler_id (zaehler_id),
  KEY datum (datum)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_abrechnungen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  objekt_id bigint(20) unsigned NOT NULL,
  von date NOT NULL,
  bis date NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'entwurf',
  erstellt datetime DEFAULT NULL,
  notiz text,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_abrechnung_ergebnis (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  abrechnung_id bigint(20) unsigned NOT NULL,
  mv_id bigint(20) unsigned NOT NULL,
  tage smallint(5) unsigned NOT NULL DEFAULT 0,
  kosten_bk decimal(10,2) NOT NULL DEFAULT 0,
  kosten_hk decimal(10,2) NOT NULL DEFAULT 0,
  vz_bk decimal(10,2) NOT NULL DEFAULT 0,
  vz_hk decimal(10,2) NOT NULL DEFAULT 0,
  saldo decimal(10,2) NOT NULL DEFAULT 0,
  par35a_handwerker decimal(10,2) NOT NULL DEFAULT 0,
  par35a_haushalt decimal(10,2) NOT NULL DEFAULT 0,
  details longtext,
  freigegeben tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY abrechnung_id (abrechnung_id),
  KEY mv_id (mv_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_bank_regeln (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  muster varchar(190) NOT NULL DEFAULT '',
  typ varchar(20) NOT NULL DEFAULT 'beleg',
  objekt_id bigint(20) unsigned DEFAULT NULL,
  kostenart_id bigint(20) unsigned DEFAULT NULL,
  mv_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_bank_import (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hash char(32) NOT NULL,
  datum date NOT NULL,
  betrag decimal(10,2) NOT NULL DEFAULT 0,
  ziel varchar(20) NOT NULL DEFAULT '',
  ziel_id bigint(20) unsigned DEFAULT NULL,
  importiert datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_mahnungen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  stufe tinyint(3) unsigned NOT NULL DEFAULT 1,
  datum date NOT NULL,
  betrag decimal(10,2) NOT NULL DEFAULT 0,
  frist date DEFAULT NULL,
  per_email tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY mv_id (mv_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_erhoehungen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  art varchar(20) NOT NULL DEFAULT 'vergleich',
  zugang date NOT NULL,
  wirksam_ab date NOT NULL,
  alt decimal(10,2) NOT NULL DEFAULT 0,
  neu decimal(10,2) NOT NULL DEFAULT 0,
  begruendung text,
  PRIMARY KEY  (id),
  KEY mv_id (mv_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_vertraege (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  erstellt datetime DEFAULT NULL,
  optionen longtext,
  PRIMARY KEY  (id),
  KEY mv_id (mv_id)
) $charset;";

		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_kaution (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  art varchar(20) NOT NULL DEFAULT 'einzahlung',
  betrag decimal(10,2) NOT NULL DEFAULT 0,
  notiz varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY mv_id (mv_id)
) $charset;";

		// Weitere Einheiten eines Mietverhältnisses (Garage, Stellplatz, Keller) – gleicher Vertrag, eine Gesamtmiete
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_mv_einheiten (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mv_id bigint(20) unsigned NOT NULL,
  einheit_id bigint(20) unsigned NOT NULL,
  mietanteil decimal(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY mv_id (mv_id),
  KEY einheit_id (einheit_id)
) $charset;";

		// Gebuchte Darlehensraten (Tilgungsplan) und Sondertilgungen
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "mv_darlehen_raten (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  darlehen_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  rate decimal(10,2) NOT NULL DEFAULT 0,
  zins decimal(10,2) NOT NULL DEFAULT 0,
  tilgung decimal(10,2) NOT NULL DEFAULT 0,
  art varchar(20) NOT NULL DEFAULT 'rate',
  PRIMARY KEY  (id),
  KEY darlehen_id (darlehen_id),
  KEY datum (datum)
) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
