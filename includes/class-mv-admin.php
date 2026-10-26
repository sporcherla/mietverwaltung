<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Backend: Menü, generisches CRUD für alle Entitäten, Offene-Posten-Übersicht.
 */
class Mietv_Admin {

	const CAP = 'mietv_verwalten';

	private static array $entities = [];

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_menu', [ __CLASS__, 'fokus_menu' ], 999 );
		add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_fokus' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_op_buchen' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 100 );
	}

	/* ------------------------------------------------------------------ */
	/* Offene Posten abhaken: offenen Betrag als Mieteingang zum 1. buchen  */
	/* ------------------------------------------------------------------ */

	public static function handle_op_buchen(): void {
		if ( empty( $_POST['mietv_op_nonce'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_op_nonce'] ?? '' ) ), 'mietv_op_buchen' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietfux' ) );
		}
		global $wpdb;
		$n   = 0;
		$mvs = [];
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			$mvs[ $mv->id ] = $mv;
		}
		foreach ( array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['op'] ?? [] ) ) ) as $key ) {
			[ $mv_id, $monat ] = array_pad( explode( '|', sanitize_text_field( $key ) ), 2, '' );
			$mv_id             = (int) $mv_id;
			if ( ! isset( $mvs[ $mv_id ] ) || ! preg_match( '/^\d{4}-\d{2}-01$/', $monat ) ) {
				continue;
			}
			$k = Mietv_Soll::konditionen( $mv_id, $monat );
			if ( ! $k ) {
				continue;
			}
			$soll  = (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz;
			$offen = round( $soll - Mietv_Soll::ist( $mv_id, $monat ), 2 );
			if ( $offen <= 0.005 ) {
				continue; // schon bezahlt
			}
			$wpdb->insert(
				mietv_table( 'zahlungen' ),
				[
					'mv_id'            => $mv_id,
					'datum'            => $monat,
					'betrag'           => $offen,
					'monat'            => $monat,
					'verwendungszweck' => sprintf( /* translators: %s: Monat */ __( 'Miete %s', 'mietfux' ), mietv_monat( $monat ) ),
					'quelle'           => 'abgehakt',
				]
			);
			++$n;
		}
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => 'mietv_uebersicht',
					'msg'  => 'gebucht',
					'n'    => $n,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Fokusmodus: WordPress-Menü aus-/einblenden (pro Benutzer)           */
	/* ------------------------------------------------------------------ */

	public static function fokus_aktiv(): bool {
		return (bool) get_user_meta( get_current_user_id(), 'mietv_fokus', true );
	}

	public static function fokus_url(): string {
		$ziel = self::fokus_aktiv() ? 'aus' : 'an';
		return wp_nonce_url( admin_url( 'admin.php?page=mietv_uebersicht&mietv_fokus=' . $ziel ), 'mietv_fokus' );
	}

	public static function handle_fokus(): void {
		if ( empty( $_GET['mietv_fokus'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'mietv_fokus' );
		update_user_meta( get_current_user_id(), 'mietv_fokus', $_GET['mietv_fokus'] === 'an' ? 1 : 0 );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_uebersicht' ) );
		exit;
	}

	/** Alle fremden Menüpunkte entfernen; Benutzer/Mediathek bleiben (werden für Portalzugang und Belege gebraucht). */
	public static function fokus_menu(): void {
		if ( ! self::fokus_aktiv() ) {
			return;
		}
		global $menu;
		$behalten = [ 'mietv_uebersicht', 'users.php', 'upload.php', 'profile.php' ];
		foreach ( (array) $menu as $pos => $item ) {
			if ( ! in_array( $item[2], $behalten, true ) ) {
				remove_menu_page( $item[2] );
			}
		}
		// Dashboard-Aufruf direkt zur Mietverwaltung lenken
		add_action(
			'load-index.php',
			function () {
				wp_safe_redirect( admin_url( 'admin.php?page=mietv_uebersicht' ) );
				exit;
			}
		);
	}

	public static function admin_bar( $bar ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$bar->add_node(
			[
				'id'    => 'mv-fokus',
				'title' => self::fokus_aktiv() ? esc_html__( '⌂ WordPress-Menü einblenden', 'mietfux' ) : esc_html__( '▣ Nur Mietfux anzeigen', 'mietfux' ),
				'href'  => self::fokus_url(),
				'meta'  => [ 'title' => esc_html__( 'Fokusmodus von Mietfux umschalten', 'mietfux' ) ],
			]
		);
	}


	public static function assets( string $hook ): void {
		wp_enqueue_style( 'mietv-menu', MIETV_URL . 'assets/menu.css', [ 'dashicons' ], MIETV_VERSION );
		if ( self::fokus_aktiv() ) {
			wp_add_inline_style( 'mietv-menu', '#wp-admin-bar-mv-fokus > a { background: #2271b1 !important; color: #fff !important; } #wp-admin-bar-new-content, #wp-admin-bar-comments, #wp-admin-bar-updates, #wp-admin-bar-wp-logo { display: none !important; }' );
		}
		if ( strpos( $hook, 'mietv_' ) === false ) {
			return;
		}
		wp_enqueue_style( 'mietv-admin', MIETV_URL . 'assets/admin.css', [], MIETV_VERSION );
		wp_enqueue_script( 'mietv-admin', MIETV_URL . 'assets/admin.js', [], MIETV_VERSION, true );
		$slug  = preg_replace( '/^.*_page_mietv_/', '', $hook );
		$icons = self::icons();
		$key   = isset( $icons[ $slug ] ) ? $slug : '__' . $slug;
		wp_localize_script(
			'mietv-admin',
			'mietvUI',
			[
				'icon'       => $icons[ $key ] ?? 'admin-home',
				'uebersicht' => admin_url( 'admin.php?page=mietv_uebersicht' ),
				'plugin'     => esc_html__( 'Mietfux', 'mietfux' ),
			]
		);
		if ( strpos( $hook, 'mietv_anlagegueter' ) !== false && ! empty( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			wp_enqueue_script( 'mietv-pdfjs', MIETV_URL . 'assets/pdfjs/pdf.min.js', [], '3.11.174', true );
			wp_enqueue_script( 'mietv-kaufvertrag', MIETV_URL . 'assets/kaufvertrag.js', [ 'mietv-pdfjs' ], MIETV_VERSION, true );
			wp_add_inline_script( 'mietv-kaufvertrag', 'window.mietvPdfWorker=' . wp_json_encode( MIETV_URL . 'assets/pdfjs/pdf.worker.min.js' ) . ';', 'before' );
			wp_localize_script(
				'mietv-kaufvertrag',
				'mietvI18n',
				[
					'kein_text'    => esc_html__( 'Kein Textlayer gefunden – das PDF ist vermutlich ein Scan. Bitte Werte manuell eintragen (oder das PDF vorher per OCR durchsuchbar machen).', 'mietfux' ),
					'kein_pdfjs'   => esc_html__( 'pdf.js konnte nicht geladen werden. Werte bitte manuell eintragen.', 'mietfux' ),
					'lese'         => esc_html__( 'Lese Kaufvertrag …', 'mietfux' ),
					'fehler'       => esc_html__( 'PDF konnte nicht gelesen werden:', 'mietfux' ),
					'nur_pdf'      => esc_html__( 'Auslesen nur bei PDF möglich.', 'mietfux' ),
					'erkannt'      => esc_html__( 'Aus dem Kaufvertrag erkannt', 'mietfux' ),
					'seiten'       => esc_html__( 'Seiten', 'mietfux' ),
					'uebernehmen'  => esc_html__( 'zum Übernehmen anklicken, danach prüfen:', 'mietfux' ),
					'nicht'        => esc_html__( 'nicht gefunden', 'mietfux' ),
					'kaufpreis'    => esc_html__( 'Kaufpreis', 'mietfux' ),
					'uebergang'    => esc_html__( 'Übergang Nutzen/Lasten', 'mietfux' ),
					'grund_betrag' => esc_html__( 'Grund und Boden (Betrag)', 'mietfux' ),
					'grund_anteil' => esc_html__( 'Anteil Grund und Boden', 'mietfux' ),
					'vertrag'      => esc_html__( 'Vertragsdatum (Info)', 'mietfux' ),
					'flaeche'      => esc_html__( 'Wohnfläche (Info)', 'mietfux' ),
					'hinweis'      => esc_html__( 'Erkennung ist heuristisch. Fehlt die Aufteilung Grund/Gebäude im Vertrag, hilft die BMF-Arbeitshilfe zur Kaufpreisaufteilung.', 'mietfux' ),
				]
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Entitäten                                                          */
	/* ------------------------------------------------------------------ */

	private static function entities(): array {
		if ( self::$entities ) {
			return self::$entities;
		}

		self::$entities = [
			'objekte'           => [
				'label'    => esc_html__( 'Objekte', 'mietfux' ),
				'singular' => esc_html__( 'Objekt', 'mietfux' ),
				'order'    => 'bezeichnung ASC',
				'owner'    => true,
				'children' => [ 'einheiten', 'objekt_id' ],
				'fields'   => [
					'bezeichnung'         => [
						'label'    => esc_html__( 'Bezeichnung', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'strasse'             => [
						'label' => esc_html__( 'Straße / Nr.', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'plz'                 => [
						'label' => esc_html__( 'PLZ', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'ort'                 => [
						'label' => esc_html__( 'Ort', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'baujahr'             => [
						'label' => esc_html__( 'Baujahr', 'mietfux' ),
						'type'  => 'int',
					],
					'heizung'             => [
						'label'   => esc_html__( 'Heizung', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'zentral'    => esc_html__( 'Zentralheizung (HeizkostenV, verbrauchsabhängig)', 'mietfux' ),
							'fernwaerme' => esc_html__( 'Fernwärme (HeizkostenV)', 'mietfux' ),
							'dezentral'  => esc_html__( 'Dezentral / Etagenheizung (Mieter rechnet selbst ab)', 'mietfux' ),
						],
					],
					'hk_verbrauchsanteil' => [
						'label' => esc_html__( 'Heizkosten: Verbrauchsanteil (%)', 'mietfux' ),
						'type'  => 'int',
						'hint'  => esc_html__( '50–70 % nach Verbrauch, Rest nach Wohnfläche (§ 7 HeizkostenV). Nur bei Zentralheizung/Fernwärme.', 'mietfux' ),
					],
					'notiz'               => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'einheiten'         => [
				'label'    => esc_html__( 'Einheiten', 'mietfux' ),
				'singular' => esc_html__( 'Einheit', 'mietfux' ),
				'order'    => 'objekt_id ASC, bezeichnung ASC',
				'children' => [ 'mietverhaeltnisse', 'einheit_id' ],
				'fields'   => [
					'objekt_id'   => [
						'label'    => esc_html__( 'Objekt', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bezeichnung' => [
						'label'    => esc_html__( 'Bezeichnung', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'z. B. „Wohnung EG links“', 'mietfux' ),
					],
					'lage'        => [
						'label' => esc_html__( 'Lage', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
						'hint'  => esc_html__( 'z. B. „1. OG“', 'mietfux' ),
					],
					'flaeche'     => [
						'label' => esc_html__( 'Wohnfläche (m²)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'zimmer'      => [
						'label' => esc_html__( 'Zimmer', 'mietfux' ),
						'type'  => 'decimal',
					],
					'typ'         => [
						'label'   => esc_html__( 'Typ', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'wohnung'    => esc_html__( 'Wohnung', 'mietfux' ),
							'gewerbe'    => esc_html__( 'Gewerbe', 'mietfux' ),
							'stellplatz' => esc_html__( 'Stellplatz', 'mietfux' ),
							'garage'     => esc_html__( 'Garage', 'mietfux' ),
							'keller'     => esc_html__( 'Keller / Lager', 'mietfux' ),
						],
					],
				],
			],
			'mieter'            => [
				'label'    => esc_html__( 'Mieter', 'mietfux' ),
				'singular' => esc_html__( 'Mieter', 'mietfux' ),
				'order'    => 'nachname ASC, vorname ASC',
				'owner'    => true,
				'children' => [ 'mietverhaeltnisse', 'mieter_id' ],
				'fields'   => [
					'anrede'         => [
						'label'   => esc_html__( 'Anrede', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							''      => '–',
							'Frau'  => esc_html__( 'Frau', 'mietfux' ),
							'Herr'  => esc_html__( 'Herr', 'mietfux' ),
							'Firma' => esc_html__( 'Firma', 'mietfux' ),
						],
					],
					'vorname'        => [
						'label' => esc_html__( 'Vorname', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'nachname'       => [
						'label'    => esc_html__( 'Nachname', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'email'          => [
						'label' => esc_html__( 'E-Mail', 'mietfux' ),
						'type'  => 'email',
						'list'  => true,
					],
					'telefon'        => [
						'label' => esc_html__( 'Telefon', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'geburtsdatum'   => [
						'label' => esc_html__( 'Geburtsdatum', 'mietfux' ),
						'type'  => 'date',
						'hint'  => esc_html__( 'Für den Mietvertrag (Identifizierung der Vertragspartei).', 'mietfux' ),
					],
					'anschrift_alt'  => [
						'label' => esc_html__( 'Bisherige Anschrift', 'mietfux' ),
						'type'  => 'text',
						'hint'  => esc_html__( 'Anschrift bei Vertragsschluss (vor Einzug) – erscheint im Vertrag.', 'mietfux' ),
					],
					'p_anrede'       => [
						'label'   => esc_html__( 'Mitmieter/in – Anrede', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							''     => '–',
							'Frau' => esc_html__( 'Frau', 'mietfux' ),
							'Herr' => esc_html__( 'Herr', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Ehe-/Lebenspartner als zweite Vertragspartei (optional). Erscheint automatisch in Mietvertrag, Schreiben, SEPA-Mandat und Übergabeprotokoll; haftet gesamtschuldnerisch.', 'mietfux' ),
					],
					'p_vorname'      => [
						'label' => esc_html__( 'Mitmieter/in – Vorname', 'mietfux' ),
						'type'  => 'text',
					],
					'p_nachname'     => [
						'label' => esc_html__( 'Mitmieter/in – Nachname', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'p_geburtsdatum' => [
						'label' => esc_html__( 'Mitmieter/in – Geburtsdatum', 'mietfux' ),
						'type'  => 'date',
					],
					'p_email'        => [
						'label' => esc_html__( 'Mitmieter/in – E-Mail', 'mietfux' ),
						'type'  => 'email',
						'hint'  => esc_html__( 'Erhält Mahnungen und Schreiben in Kopie, wenn angegeben.', 'mietfux' ),
					],
					'iban'           => [
						'label' => esc_html__( 'IBAN (für Bankimport-Zuordnung)', 'mietfux' ),
						'type'  => 'text',
					],
					'wp_user_id'     => [
						'label'   => esc_html__( 'Portalzugang (WP-Benutzer)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_users' ],
						'hint'    => esc_html__( 'Benutzer anlegen unter „Benutzer“, Rolle „Abonnent“ genügt.', 'mietfux' ),
					],
					'notiz'          => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'mietverhaeltnisse' => [
				'label'      => esc_html__( 'Mietverhältnisse', 'mietfux' ),
				'singular'   => esc_html__( 'Mietverhältnis', 'mietfux' ),
				'order'      => 'beginn DESC',
				'children'   => [ 'zahlungen', 'mv_id' ],
				'fields'     => [
					'einheit_id'      => [
						'label'    => esc_html__( 'Einheit', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_einheiten' ],
					],
					'mieter_id'       => [
						'label'    => esc_html__( 'Mieter', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mieter' ],
					],
					'beginn'          => [
						'label'    => esc_html__( 'Mietbeginn', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
					],
					'ende'            => [
						'label' => esc_html__( 'Mietende', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
						'hint'  => esc_html__( 'leer = unbefristet', 'mietfux' ),
					],
					'kaution'         => [
						'label' => esc_html__( 'Kaution (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'personen'        => [
						'label' => esc_html__( 'Personen im Haushalt', 'mietfux' ),
						'type'  => 'int',
						'hint'  => esc_html__( 'für Umlageschlüssel nach Personen', 'mietfux' ),
					],
					'zahltag'         => [
						'label' => esc_html__( 'Zahltag (Tag im Monat)', 'mietfux' ),
						'type'  => 'int',
					],
					'zusatzeinheiten' => [
						'label' => esc_html__( 'Weitere Einheiten im selben Vertrag', 'mietfux' ),
						'type'  => 'zusatz',
						'list'  => true,
						'hint'  => esc_html__( 'Garage, Stellplatz, Keller, die zusammen mit der Wohnung vermietet sind. Der Mietanteil ist in der Kaltmiete der Konditionen bereits enthalten und dient nur der Ausweisung im Vertrag.', 'mietfux' ),
					],
					'notiz'           => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
				'after_form' => esc_html__( 'Konditionen (Kaltmiete, Vorauszahlungen) werden unter „Konditionen“ mit Gültigkeitsdatum erfasst – als Gesamtmiete inkl. Garage/Stellplatz. Jede Erhöhung = neuer Eintrag.', 'mietfux' ),
			],
			'konditionen'       => [
				'label'    => esc_html__( 'Konditionen', 'mietfux' ),
				'singular' => esc_html__( 'Kondition', 'mietfux' ),
				'order'    => 'mv_id ASC, gueltig_ab DESC',
				'fields'   => [
					'mv_id'      => [
						'label'    => esc_html__( 'Mietverhältnis', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'gueltig_ab' => [
						'label'    => esc_html__( 'Gültig ab', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'In der Regel der 1. eines Monats', 'mietfux' ),
					],
					'kaltmiete'  => [
						'label'    => esc_html__( 'Kaltmiete (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'bk_vz'      => [
						'label' => esc_html__( 'Betriebskosten-VZ (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'hk_vz'      => [
						'label' => esc_html__( 'Heizkosten-VZ (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
				],
			],
			'zahlungen'         => [
				'label'    => esc_html__( 'Zahlungen', 'mietfux' ),
				'singular' => esc_html__( 'Zahlung', 'mietfux' ),
				'order'    => 'datum DESC, id DESC',
				'fields'   => [
					'mv_id'            => [
						'label'    => esc_html__( 'Mietverhältnis', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'datum'            => [
						'label'    => esc_html__( 'Zahlungsdatum', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
					],
					'betrag'           => [
						'label'    => esc_html__( 'Betrag (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'monat'            => [
						'label'    => esc_html__( 'Zuordnung Monat', 'mietfux' ),
						'type'     => 'month',
						'required' => true,
						'list'     => true,
					],
					'verwendungszweck' => [
						'label' => esc_html__( 'Verwendungszweck', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'quelle'           => [
						'label'   => esc_html__( 'Quelle', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'manuell'  => esc_html__( 'manuell', 'mietfux' ),
							'bank'     => esc_html__( 'Bankimport', 'mietfux' ),
							'abgehakt' => esc_html__( 'Übersicht (abgehakt)', 'mietfux' ),
						],
					],
				],
			],
			'belege'            => [
				'label'       => esc_html__( 'Belege', 'mietfux' ),
				'singular'    => esc_html__( 'Beleg', 'mietfux' ),
				'order'       => 'datum DESC, id DESC',
				'owner'       => true,
				'upload'      => true,
				'filter_year' => 'steuerjahr',
				'fields'      => [
					'objekt_id'        => [
						'label'    => esc_html__( 'Objekt', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'kostenart_id'     => [
						'label'    => esc_html__( 'Kostenart', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_kostenarten' ],
					],
					'datum'            => [
						'label'    => esc_html__( 'Zahlungsdatum', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Abflussdatum – maßgeblich für das Steuerjahr (§ 11 EStG). Bei Lastschrift: Tag der Abbuchung.', 'mietfux' ),
					],
					'steuerjahr'       => [
						'label' => esc_html__( 'Steuerjahr', 'mietfux' ),
						'type'  => 'int',
						'list'  => true,
						'hint'  => esc_html__( 'leer = Jahr des Zahlungsdatums. Abweichend nur bei regelmäßig wiederkehrenden Zahlungen um den Jahreswechsel (10-Tage-Regel).', 'mietfux' ),
					],
					'betrag'           => [
						'label'    => esc_html__( 'Betrag brutto (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'lieferant'        => [
						'label' => esc_html__( 'Rechnungssteller', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'belegnr'          => [
						'label' => esc_html__( 'Rechnungs-/Belegnummer', 'mietfux' ),
						'type'  => 'text',
					],
					'zeitraum_von'     => [
						'label' => esc_html__( 'Leistungszeitraum von', 'mietfux' ),
						'type'  => 'date',
						'hint'  => esc_html__( 'Für die Betriebskostenabrechnung (z. B. Versicherung 01.01.–31.12.). Leer = Zahlungsdatum.', 'mietfux' ),
					],
					'zeitraum_bis'     => [
						'label' => esc_html__( 'Leistungszeitraum bis', 'mietfux' ),
						'type'  => 'date',
					],
					'verteilung_jahre' => [
						'label'   => esc_html__( 'Verteilung (§ 82b EStDV)', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							'1' => esc_html__( 'nein – voll im Zahlungsjahr', 'mietfux' ),
							'2' => esc_html__( 'auf 2 Jahre', 'mietfux' ),
							'3' => esc_html__( 'auf 3 Jahre', 'mietfux' ),
							'4' => esc_html__( 'auf 4 Jahre', 'mietfux' ),
							'5' => esc_html__( 'auf 5 Jahre', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Nur für größeren Erhaltungsaufwand an Wohngebäuden. Gleichmäßige Verteilung ab dem Steuerjahr.', 'mietfux' ),
					],
					'par35a'           => [
						'label'   => esc_html__( '§ 35a EStG (für Mieter)', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							''           => esc_html__( '– nein –', 'mietfux' ),
							'handwerker' => esc_html__( 'Handwerkerleistung (20 %, max. 1.200 €)', 'mietfux' ),
							'haushalt'   => esc_html__( 'Haushaltsnahe Dienstleistung (20 %, max. 4.000 €)', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Hausmeister, Gartenpflege, Schornsteinfeger, Wartung Heizung … Der Lohnanteil wird in der Betriebskostenabrechnung für den Mieter ausgewiesen.', 'mietfux' ),
					],
					'lohnanteil'       => [
						'label' => esc_html__( 'davon Lohn-/Arbeitskosten brutto (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Laut Rechnung; ohne Material.', 'mietfux' ),
					],
					'beschreibung'     => [
						'label' => esc_html__( 'Beschreibung', 'mietfux' ),
						'type'  => 'textarea',
					],
					'attachment_id'    => [
						'label' => esc_html__( 'Belegdatei (PDF/Foto)', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'zaehler'           => [
				'label'    => esc_html__( 'Zähler', 'mietfux' ),
				'singular' => esc_html__( 'Zähler', 'mietfux' ),
				'order'    => 'objekt_id ASC, einheit_id ASC, typ ASC',
				'children' => [ 'zaehlerstaende', 'zaehler_id' ],
				'fields'   => [
					'objekt_id'  => [
						'label'    => esc_html__( 'Objekt', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'einheit_id' => [
						'label'   => esc_html__( 'Einheit', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_einheiten_optional' ],
						'hint'    => esc_html__( 'Leer = Hauptzähler des Hauses (nur zur Info, nicht für die Verteilung).', 'mietfux' ),
					],
					'typ'        => [
						'label'    => esc_html__( 'Art', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [
							'kaltwasser' => esc_html__( 'Kaltwasser (m³)', 'mietfux' ),
							'warmwasser' => esc_html__( 'Warmwasser (m³)', 'mietfux' ),
							'heizung'    => esc_html__( 'Heizung (kWh / Einheiten)', 'mietfux' ),
							'strom'      => esc_html__( 'Strom (kWh)', 'mietfux' ),
						],
					],
					'nummer'     => [
						'label' => esc_html__( 'Zählernummer', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'notiz'      => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'zaehlerstaende'    => [
				'label'    => esc_html__( 'Zählerstände', 'mietfux' ),
				'singular' => esc_html__( 'Zählerstand', 'mietfux' ),
				'order'    => 'datum DESC, zaehler_id ASC',
				'upload'   => true,
				'fields'   => [
					'zaehler_id'    => [
						'label'    => esc_html__( 'Zähler', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_zaehler' ],
					],
					'datum'         => [
						'label'    => esc_html__( 'Ablesedatum', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Für die Abrechnung: Stand zum Beginn und zum Ende des Zeitraums (z. B. 01.01. und 31.12.).', 'mietfux' ),
					],
					'stand'         => [
						'label'    => esc_html__( 'Zählerstand', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'quelle'        => [
						'label'   => esc_html__( 'Quelle', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'vermieter' => esc_html__( 'Vermieter/Ablesung', 'mietfux' ),
							'mieter'    => esc_html__( 'Mieter (Portal)', 'mietfux' ),
							'versorger' => esc_html__( 'Versorger/Ablesedienst', 'mietfux' ),
						],
					],
					'attachment_id' => [
						'label' => esc_html__( 'Foto', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'bank_regeln'       => [
				'label'    => esc_html__( 'Bankimport-Regeln', 'mietfux' ),
				'singular' => esc_html__( 'Regel', 'mietfux' ),
				'order'    => 'muster ASC',
				'owner'    => true,
				'fields'   => [
					'muster'       => [
						'label'    => esc_html__( 'Suchtext', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Wird in Name und Verwendungszweck gesucht (Groß-/Kleinschreibung egal), z. B. „Stadtwerke“ oder „N-ERGIE“.', 'mietfux' ),
					],
					'typ'          => [
						'label'   => esc_html__( 'Anlegen als', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'beleg'      => esc_html__( 'Beleg (Ausgabe)', 'mietfux' ),
							'zahlung'    => esc_html__( 'Mietzahlung (Eingang)', 'mietfux' ),
							'ignorieren' => esc_html__( 'Ignorieren', 'mietfux' ),
						],
					],
					'objekt_id'    => [
						'label'   => esc_html__( 'Objekt', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_objekte' ],
					],
					'kostenart_id' => [
						'label'   => esc_html__( 'Kostenart (bei Beleg)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_kostenarten_optional' ],
					],
					'mv_id'        => [
						'label'   => esc_html__( 'Mietverhältnis (bei Zahlung)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_mv_optional' ],
					],
				],
			],
			'anlagegueter'      => [
				'label'    => esc_html__( 'Abschreibung (AfA)', 'mietfux' ),
				'singular' => esc_html__( 'Anlagegut', 'mietfux' ),
				'order'    => 'objekt_id ASC, anschaffung ASC',
				'owner'    => true,
				'upload'   => true,
				'fields'   => [
					'attachment_id'        => [
						'label'   => esc_html__( 'Kaufvertrag (PDF)', 'mietfux' ),
						'type'    => 'file',
						'list'    => true,
						'extract' => 'kaufvertrag',
						'hint'    => esc_html__( 'Nach Auswahl wird der Vertrag im Browser ausgelesen; erkannte Werte erscheinen unten als Vorschläge. Nichts wird hochgeladen, bevor du speicherst.', 'mietfux' ),
					],
					'objekt_id'            => [
						'label'    => esc_html__( 'Objekt', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bezeichnung'          => [
						'label'    => esc_html__( 'Bezeichnung', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'z. B. „Gebäude Musterstraße 1“ oder „Einbauküche“', 'mietfux' ),
					],
					'typ'                  => [
						'label'   => esc_html__( 'Art', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'gebaeude'  => esc_html__( 'Gebäude', 'mietfux' ),
							'beweglich' => esc_html__( 'Bewegliches Wirtschaftsgut (Einbauküche, Möbel …)', 'mietfux' ),
						],
					],
					'anschaffung'          => [
						'label'    => esc_html__( 'Anschaffung / Fertigstellung', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Beim Kauf: Übergang von Nutzen und Lasten. AfA beginnt zeitanteilig ab diesem Monat.', 'mietfux' ),
					],
					'ak'                   => [
						'label'    => esc_html__( 'Kaufpreis / Herstellungskosten (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'nk_grunderwerbsteuer' => [
						'label' => esc_html__( 'Grunderwerbsteuer (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Bayern 3,5 % des Kaufpreises (Bescheid des Finanzamts).', 'mietfux' ),
					],
					'nk_notar'             => [
						'label' => esc_html__( 'Notar – Kaufvertrag (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Nur der Teil für Beurkundung des Kaufvertrags. Der Teil für die Grundschuld gehört unten zu „Finanzierung“.', 'mietfux' ),
					],
					'nk_grundbuch'         => [
						'label' => esc_html__( 'Grundbuch – Auflassung/Eigentumsumschreibung (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Kostenrechnung des Grundbuchamts für Vormerkung und Eigentumsumschreibung.', 'mietfux' ),
					],
					'nk_makler'            => [
						'label' => esc_html__( 'Maklerprovision (€)', 'mietfux' ),
						'type'  => 'decimal',
					],
					'nk_sonstige'          => [
						'label' => esc_html__( 'Sonstige Anschaffungsnebenkosten (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'z. B. Gutachter vor Kauf, Fahrtkosten zur Besichtigung, Übersetzer.', 'mietfux' ),
					],
					'nebenkosten'          => [
						'label' => esc_html__( 'Summe Anschaffungsnebenkosten (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Wird aus den Einzelpositionen berechnet, sobald dort etwas eingetragen ist; sonst manuell.', 'mietfux' ),
					],
					'nk_finanzierung'      => [
						'label' => esc_html__( 'Finanzierungskosten – Notar/Grundbuch Grundschuld (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Keine Anschaffungskosten! Sofort abziehbare Werbungskosten (Geldbeschaffungskosten). Beim Speichern wird automatisch ein Beleg der Kostenart „Geldbeschaffungskosten“ angelegt, falls noch keiner mit diesem Betrag existiert.', 'mietfux' ),
					],
					'grundanteil'          => [
						'label' => esc_html__( 'Anteil Grund und Boden (%)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Nur Gebäude; der Bodenanteil ist nicht abschreibbar. Aufteilung laut Kaufvertrag oder BMF-Arbeitshilfe zur Kaufpreisaufteilung. Auch Eigentumswohnungen haben einen Miteigentumsanteil am Grund (häufig 15–30 %). 0 % akzeptiert das Finanzamt in der Regel nicht.', 'mietfux' ),
					],
					'methode'              => [
						'label'   => esc_html__( 'Methode', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'linear'    => esc_html__( 'Linear (§ 7 Abs. 4 EStG)', 'mietfux' ),
							'degressiv' => esc_html__( 'Degressiv vom Restwert (§ 7 Abs. 5a EStG, Neubau Baubeginn 10/2023–09/2029)', 'mietfux' ),
						],
					],
					'satz'                 => [
						'label' => esc_html__( 'AfA-Satz (% p. a.)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Leer = automatisch: Gebäude nach Baujahr des Objekts (vor 1925: 2,5 %, ab 2023: 3 %, sonst 2 %), bewegliche Güter 100 / Nutzungsdauer. Degressiv: 5 %. Nur eintragen, wenn abweichend (z. B. 4 % bei nachgewiesener kürzerer Restnutzungsdauer).', 'mietfux' ),
					],
					'nutzungsdauer'        => [
						'label' => esc_html__( 'Nutzungsdauer (Jahre)', 'mietfux' ),
						'type'  => 'int',
						'hint'  => esc_html__( 'Nur bewegliche Wirtschaftsgüter, z. B. Einbauküche 10 Jahre. GWG bis 800 € netto: Nutzungsdauer 1 = Sofortabzug.', 'mietfux' ),
					],
					'ende'                 => [
						'label' => esc_html__( 'Ausscheiden (Verkauf/Entnahme)', 'mietfux' ),
						'type'  => 'date',
					],
					'notiz'                => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'darlehen'          => [
				'label'    => esc_html__( 'Darlehen', 'mietfux' ),
				'singular' => esc_html__( 'Darlehen', 'mietfux' ),
				'order'    => 'status ASC, auszahlung ASC',
				'owner'    => true,
				'children' => [ 'darlehen_jahre', 'darlehen_id' ],
				'upload'   => true,
				'fields'   => [
					'objekt_id'       => [
						'label'    => esc_html__( 'Objekt', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bank'            => [
						'label'    => esc_html__( 'Bank', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'bezeichnung'     => [
						'label' => esc_html__( 'Bezeichnung / Konto-Nr.', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'betrag'          => [
						'label'    => esc_html__( 'Darlehensbetrag (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'auszahlung'      => [
						'label' => esc_html__( 'Auszahlung', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
					],
					'zinssatz'        => [
						'label' => esc_html__( 'Sollzins (% p. a.)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'zinsbindung_bis' => [
						'label' => esc_html__( 'Zinsbindung bis', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
					],
					'rate'            => [
						'label' => esc_html__( 'Monatsrate (€)', 'mietfux' ),
						'type'  => 'decimal',
					],
					'tilgung'         => [
						'label' => esc_html__( 'Anfängliche Tilgung (% p. a.)', 'mietfux' ),
						'type'  => 'decimal',
					],
					'status'          => [
						'label'   => esc_html__( 'Status', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'aktiv'     => esc_html__( 'Aktiv', 'mietfux' ),
							'abgeloest' => esc_html__( 'Abgelöst', 'mietfux' ),
						],
					],
					'attachment_id'   => [
						'label' => esc_html__( 'Darlehensvertrag (PDF)', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
					'notiz'           => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'dokumente'         => [
				'label'    => esc_html__( 'Dokumente', 'mietfux' ),
				'singular' => esc_html__( 'Dokument', 'mietfux' ),
				'order'    => 'datum DESC, id DESC',
				'owner'    => true,
				'upload'   => true,
				'fields'   => [
					'objekt_id'       => [
						'label'    => esc_html__( 'Objekt', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'mv_id'           => [
						'label'   => esc_html__( 'Mietverhältnis', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_mv_optional' ],
						'hint'    => esc_html__( 'Nur bei mieterbezogenen Dokumenten (Mietvertrag, Übergabeprotokoll, Abrechnung).', 'mietfux' ),
					],
					'typ'             => [
						'label'   => esc_html__( 'Art', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'kaufvertrag'        => esc_html__( 'Kaufvertrag', 'mietfux' ),
							'grundbuch'          => esc_html__( 'Grundbuchauszug', 'mietfux' ),
							'teilungserklaerung' => esc_html__( 'Teilungserklärung / WEG', 'mietfux' ),
							'mietvertrag'        => esc_html__( 'Mietvertrag', 'mietfux' ),
							'uebergabeprotokoll' => esc_html__( 'Übergabeprotokoll', 'mietfux' ),
							'abrechnung'         => esc_html__( 'Betriebskostenabrechnung', 'mietfux' ),
							'versicherung'       => esc_html__( 'Versicherungsschein', 'mietfux' ),
							'energieausweis'     => esc_html__( 'Energieausweis', 'mietfux' ),
							'sonstiges'          => esc_html__( 'Sonstiges', 'mietfux' ),
						],
					],
					'bezeichnung'     => [
						'label'    => esc_html__( 'Bezeichnung', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'datum'           => [
						'label' => esc_html__( 'Datum', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
					],
					'mieter_sichtbar' => [
						'label'   => esc_html__( 'Im Mieterportal sichtbar', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'0' => esc_html__( 'Nein', 'mietfux' ),
							'1' => esc_html__( 'Ja', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Nur wirksam mit zugeordnetem Mietverhältnis.', 'mietfux' ),
					],
					'attachment_id'   => [
						'label'    => esc_html__( 'Datei (PDF)', 'mietfux' ),
						'type'     => 'file',
						'required' => true,
						'list'     => true,
					],
					'notiz'           => [
						'label' => esc_html__( 'Notiz', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'darlehen_jahre'    => [
				'label'       => esc_html__( 'Zinsbescheinigungen', 'mietfux' ),
				'singular'    => esc_html__( 'Jahreswert Darlehen', 'mietfux' ),
				'order'       => 'jahr DESC, darlehen_id ASC',
				'upload'      => true,
				'filter_year' => 'jahr',
				'fields'      => [
					'darlehen_id'   => [
						'label'    => esc_html__( 'Darlehen', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_darlehen' ],
					],
					'jahr'          => [
						'label'    => esc_html__( 'Jahr', 'mietfux' ),
						'type'     => 'int',
						'required' => true,
						'list'     => true,
					],
					'zinsen'        => [
						'label'    => esc_html__( 'Gezahlte Zinsen (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Laut Jahreszinsbescheinigung der Bank – das ist der Werbungskostenbetrag.', 'mietfux' ),
					],
					'tilgung'       => [
						'label' => esc_html__( 'Tilgung (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Nicht abziehbar, nur zur Info.', 'mietfux' ),
					],
					'restschuld'    => [
						'label' => esc_html__( 'Restschuld 31.12. (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'attachment_id' => [
						'label' => esc_html__( 'Zinsbescheinigung (PDF)', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'kostenarten'       => [
				'label'    => esc_html__( 'Kostenarten', 'mietfux' ),
				'singular' => esc_html__( 'Kostenart', 'mietfux' ),
				'order'    => 'sortierung ASC, name ASC',
				'owner'    => true,
				'children' => [ 'belege', 'kostenart_id' ],
				'fields'   => [
					'name'         => [
						'label'    => esc_html__( 'Bezeichnung', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'anlage_v'     => [
						'label'    => esc_html__( 'Anlage V – Gruppe', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ 'Mietv_DB', 'anlage_v_gruppen' ],
					],
					'umlagefaehig' => [
						'label'   => esc_html__( 'Umlagefähig (BetrKV)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'0' => esc_html__( 'Nein – trägt der Vermieter', 'mietfux' ),
							'1' => esc_html__( 'Ja – geht in die Betriebskostenabrechnung', 'mietfux' ),
						],
					],
					'schluessel'   => [
						'label'   => esc_html__( 'Umlageschlüssel', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							''          => '–',
							'flaeche'   => esc_html__( 'Wohnfläche', 'mietfux' ),
							'personen'  => esc_html__( 'Personen', 'mietfux' ),
							'verbrauch' => esc_html__( 'Verbrauch (Zähler)', 'mietfux' ),
							'einheit'   => esc_html__( 'je Einheit', 'mietfux' ),
						],
					],
					'betrkv'       => [
						'label' => esc_html__( 'Rechtsgrundlage', 'mietfux' ),
						'type'  => 'text',
						'hint'  => esc_html__( 'z. B. § 2 Nr. 1 BetrKV', 'mietfux' ),
					],
					'sortierung'   => [
						'label' => esc_html__( 'Sortierung', 'mietfux' ),
						'type'  => 'int',
					],
				],
			],
			'meldungen'         => [
				'label'    => esc_html__( 'Meldungen', 'mietfux' ),
				'singular' => esc_html__( 'Meldung', 'mietfux' ),
				'order'    => 'datum DESC',
				'fields'   => [
					'mv_id'   => [
						'label'    => esc_html__( 'Mietverhältnis', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'datum'   => [
						'label'    => esc_html__( 'Datum', 'mietfux' ),
						'type'     => 'datetime',
						'required' => true,
						'list'     => true,
					],
					'betreff' => [
						'label'    => esc_html__( 'Betreff', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'text'    => [
						'label' => esc_html__( 'Beschreibung', 'mietfux' ),
						'type'  => 'textarea',
					],
					'status'  => [
						'label'   => esc_html__( 'Status', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'offen'          => esc_html__( 'Offen', 'mietfux' ),
							'in_bearbeitung' => esc_html__( 'In Bearbeitung', 'mietfux' ),
							'erledigt'       => esc_html__( 'Erledigt', 'mietfux' ),
						],
					],
				],
			],
		];

		return self::$entities;
	}

	/* Options-Callbacks ------------------------------------------------- */

	public static function opt_objekte(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, bezeichnung FROM ' . $wpdb->prefix . 'mv_objekte ORDER BY bezeichnung' ) as $r ) {
			$out[ $r->id ] = $r->bezeichnung;
		}
		return $out;
	}

	public static function opt_einheiten(): array {
		global $wpdb;
		$out  = [];
		$rows = $wpdb->get_results( 'SELECT e.id, e.bezeichnung, o.bezeichnung AS objekt FROM ' . $wpdb->prefix . 'mv_einheiten e JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = e.objekt_id ORDER BY o.bezeichnung, e.bezeichnung' );
		foreach ( $rows as $r ) {
			$out[ $r->id ] = $r->objekt . ' / ' . $r->bezeichnung;
		}
		return $out;
	}

	public static function opt_mieter(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, vorname, nachname FROM ' . $wpdb->prefix . 'mv_mieter ORDER BY nachname, vorname' ) as $r ) {
			$out[ $r->id ] = mietv_mieter_name( $r );
		}
		return $out;
	}

	public static function opt_mv(): array {
		$out = [];
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			$out[ $mv->id ] = Mietv_Soll::mietv_label( $mv );
		}
		return $out;
	}

	public static function opt_kostenarten(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, name, umlagefaehig FROM ' . $wpdb->prefix . 'mv_kostenarten ORDER BY sortierung, name' ) as $r ) {
			$out[ $r->id ] = $r->name . ( $r->umlagefaehig ? ' [umlagefähig]' : '' );
		}
		return $out;
	}

	public static function opt_einheiten_optional(): array {
		return [ '' => '– Hauptzähler –' ] + self::opt_einheiten();
	}

	public static function opt_kostenarten_optional(): array {
		return [ '' => '–' ] + self::opt_kostenarten();
	}

	public static function opt_zaehler(): array {
		global $wpdb;
		$out  = [];
		$rows = $wpdb->get_results( 'SELECT z.*, o.bezeichnung AS objekt, e.bezeichnung AS einheit FROM ' . $wpdb->prefix . 'mv_zaehler z JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = z.objekt_id LEFT JOIN ' . $wpdb->prefix . 'mv_einheiten e ON e.id = z.einheit_id ORDER BY o.bezeichnung, e.bezeichnung, z.typ' );
		foreach ( $rows as $r ) {
			$out[ $r->id ] = $r->objekt . ' / ' . ( $r->einheit ?: 'Haus' ) . ' – ' . ucfirst( $r->typ ) . ( $r->nummer ? ' (' . $r->nummer . ')' : '' );
		}
		return $out;
	}

	public static function opt_mv_optional(): array {
		return [ '' => '– keines –' ] + self::opt_mv();
	}

	public static function opt_darlehen(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, bank, bezeichnung, betrag FROM ' . $wpdb->prefix . 'mv_darlehen ORDER BY bank' ) as $r ) {
			$out[ $r->id ] = $r->bank . ( $r->bezeichnung ? ' – ' . $r->bezeichnung : '' ) . ' (' . mietv_eur( $r->betrag ) . ')';
		}
		return $out;
	}

	public static function opt_users(): array {
		$out = [ '' => '– kein Portalzugang –' ];
		foreach ( get_users( [ 'fields' => [ 'ID', 'display_name', 'user_login' ] ] ) as $u ) {
			$out[ $u->ID ] = $u->display_name . ' (' . $u->user_login . ')';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Menü                                                               */
	/* ------------------------------------------------------------------ */

	public static function menu(): void {
		add_menu_page( __( 'Mietfux', 'mietfux' ), __( 'Mietfux', 'mietfux' ), self::CAP, 'mietv_uebersicht', [ __CLASS__, 'page_uebersicht' ], 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAyIDEuNSA5LjJsMS4xIDEuMkw0IDkuM1YxN2g1di00aDJ2NGg1VjkuM2wxLjQgMS4xIDEuMS0xLjJMMTAgMnptMCAyLjQgNC41IDMuOVYxNS41aC0yVjExSDcuNXY0LjVoLTJWOC4zTDEwIDQuNHoiLz48Y2lyY2xlIGZpbGw9ImJsYWNrIiBjeD0iMTQuNiIgY3k9IjUuMiIgcj0iMS4zIi8+PC9zdmc+', 58 );
		add_submenu_page( 'mietv_uebersicht', __( 'Übersicht', 'mietfux' ), '<span class="dashicons dashicons-dashboard"></span> ' . __( 'Übersicht', 'mietfux' ), self::CAP, 'mietv_uebersicht', [ __CLASS__, 'page_uebersicht' ] );
		// Gruppen (## = Überschrift) und Dashicons
		$reihenfolge = [
			'##' . esc_html__( 'Alltag', 'mietfux' ),
			'zahlungen',
			'belege',
			'__bank',
			'meldungen',
			'dokumente',
			'##' . esc_html__( 'Verwaltung', 'mietfux' ),
			'objekte',
			'einheiten',
			'mieter',
			'mietverhaeltnisse',
			'konditionen',
			'__mietvertrag',
			'__kaution',
			'__erhoehung',
			'__mahnung',
			'##' . esc_html__( 'Jahresabschluss', 'mietfux' ),
			'zaehler',
			'zaehlerstaende',
			'__abrechnung',
			'kostenarten',
			'anlagegueter',
			'darlehen',
			'__darlehenskonto',
			'darlehen_jahre',
			'__steuer',
			'##' . esc_html__( 'System', 'mietfux' ),
			'bank_regeln',
			'__datenschutz',
			'__einstellungen',
			'__assistent',
			'__hilfe',
		];
		$icons       = self::icons();
		$kurz        = [
			'__assistent'     => esc_html__( 'Assistent', 'mietfux' ),
			'__abrechnung'    => esc_html__( 'BK-Abrechnung', 'mietfux' ),
			'__einstellungen' => esc_html__( 'Einstellungen', 'mietfux' ),
			'__mietvertrag'   => esc_html__( 'Mietvertrag', 'mietfux' ),
			'darlehen_jahre'  => esc_html__( 'Zinsbescheinigung', 'mietfux' ),
			'bank_regeln'     => esc_html__( 'Bankregeln', 'mietfux' ),
		];
		$ico         = fn( string $key, string $label ) => '<span class="dashicons dashicons-' . esc_attr( $icons[ $key ] ?? 'marker' ) . '"></span> ' . ( $kurz[ $key ] ?? $label );
		$seiten      = [
			'__mietvertrag'    => [ __( 'Mietvertrag & Übergabe', 'mietfux' ), [ 'Mietv_Mietvertrag', 'page' ] ],
			'__bank'           => [ esc_html__( 'Bankimport', 'mietfux' ), [ 'Mietv_Bank', 'page' ] ],
			'__mahnung'        => [ esc_html__( 'Mahnwesen', 'mietfux' ), [ 'Mietv_Mahnung', 'page' ] ],
			'__erhoehung'      => [ esc_html__( 'Mieterhöhung', 'mietfux' ), [ 'Mietv_Erhoehung', 'page' ] ],
			'__kaution'        => [ esc_html__( 'Kaution', 'mietfux' ), [ 'Mietv_Kaution', 'page' ] ],
			'__darlehenskonto' => [ esc_html__( 'Darlehenskonto', 'mietfux' ), [ 'Mietv_Darlehen', 'page' ] ],
			'__abrechnung'     => [ esc_html__( 'Betriebskostenabrechnung', 'mietfux' ), [ 'Mietv_Abrechnung', 'page' ] ],
			'__steuer'         => [ esc_html__( 'Steuer (Anlage V)', 'mietfux' ), [ 'Mietv_Steuer', 'page' ] ],
			'__datenschutz'    => [ esc_html__( 'Datenschutz', 'mietfux' ), [ 'Mietv_Datenschutz', 'page' ] ],
			'__einstellungen'  => [ __( 'Einstellungen & Backup', 'mietfux' ), [ 'Mietv_Einstellungen', 'page' ] ],
			'__hilfe'          => [ esc_html__( 'Hilfe', 'mietfux' ), [ 'Mietv_Hilfe', 'page' ] ],
			'__assistent'      => [ esc_html__( 'Einrichtungsassistent', 'mietfux' ), [ 'Mietv_Assistent', 'page' ] ],
		];
		$ents        = self::entities();
		$g           = 0;
		foreach ( $reihenfolge as $key ) {
			if ( strpos( $key, '##' ) === 0 ) {
				++$g;
				add_submenu_page( 'mietv_uebersicht', '', '<span class="mv-menu-group">' . esc_html( substr( $key, 2 ) ) . '</span>', self::CAP, 'mietv_grp_' . $g, [ __CLASS__, 'page_uebersicht' ] );
			} elseif ( isset( $seiten[ $key ] ) ) {
				add_submenu_page( 'mietv_uebersicht', $seiten[ $key ][0], $ico( $key, $seiten[ $key ][0] ), self::CAP, 'mietv_' . substr( $key, 2 ), $seiten[ $key ][1] );
			} elseif ( isset( $ents[ $key ] ) ) {
				add_submenu_page( 'mietv_uebersicht', $ents[ $key ]['label'], $ico( $key, $ents[ $key ]['label'] ), self::CAP, 'mietv_' . $key, [ __CLASS__, 'page_entity' ] );
			}
		}
	}

	/** Dashicon je Menüpunkt. */
	public static function icons(): array {
		return [
			'uebersicht'        => 'dashboard',
			'zahlungen'         => 'money-alt',
			'belege'            => 'clipboard',
			'__bank'            => 'bank',
			'meldungen'         => 'warning',
			'dokumente'         => 'portfolio',
			'objekte'           => 'building',
			'einheiten'         => 'admin-home',
			'mieter'            => 'groups',
			'mietverhaeltnisse' => 'id-alt',
			'konditionen'       => 'tag',
			'__mietvertrag'     => 'media-document',
			'__kaution'         => 'lock',
			'__erhoehung'       => 'chart-line',
			'__mahnung'         => 'megaphone',
			'zaehler'           => 'performance',
			'zaehlerstaende'    => 'editor-ol',
			'__abrechnung'      => 'calculator',
			'kostenarten'       => 'category',
			'anlagegueter'      => 'chart-area',
			'darlehen'          => 'money',
			'__darlehenskonto'  => 'chart-bar',
			'darlehen_jahre'    => 'media-spreadsheet',
			'__steuer'          => 'chart-pie',
			'bank_regeln'       => 'randomize',
			'__datenschutz'     => 'shield',
			'__einstellungen'   => 'admin-generic',
			'__hilfe'           => 'editor-help',
			'__assistent'       => 'welcome-learn-more',
		];
	}

	private static function current_entity(): ?string {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$key  = substr( $page, 6 );
		return isset( self::entities()[ $key ] ) ? $key : null;
	}

	private static function url( string $key, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'mietv_' . $key ], $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Speichern / Löschen                                                */
	/* ------------------------------------------------------------------ */

	public static function handle_actions(): void {
		global $wpdb;

		// Speichern
		if ( isset( $_POST['mietv_entity'] ) ) {
			$key = sanitize_key( $_POST['mietv_entity'] ?? '' );
			$ent = self::entities()[ $key ] ?? null;
			if ( ! $ent || ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Keine Berechtigung.', 'mietfux' ) );
			}
			check_admin_referer( 'mietv_save_' . $key );

			$id     = isset( $_POST['mietv_record_id'] ) ? (int) $_POST['mietv_record_id'] : 0;
			$data   = [];
			$errors = [];

			$zusatz = null;
			foreach ( $ent['fields'] as $name => $f ) {
				if ( $f['type'] === 'zusatz' ) {
					$zusatz = [];
					foreach ( map_deep( wp_unslash( (array) ( $_POST[ $name ] ?? [] ) ), 'sanitize_text_field' ) as $eid => $anteil ) {
						if ( (int) $eid > 0 && ! empty( $_POST[ $name . '_on' ][ $eid ] ) ) {
							$zusatz[ (int) $eid ] = mietv_to_float( wp_unslash( $anteil ) );
						}
					}
					continue;
				}
				if ( $f['type'] === 'file' ) {
					if ( ! empty( $_FILES[ $name ]['name'] ) ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
						require_once ABSPATH . 'wp-admin/includes/image.php';
						require_once ABSPATH . 'wp-admin/includes/media.php';
						$att = media_handle_upload( $name, 0 );
						if ( is_wp_error( $att ) ) {
							$errors[] = 'Upload fehlgeschlagen: ' . $att->get_error_message();
						} else {
							$data[ $name ] = (int) $att;
						}
					}
					// ohne neuen Upload bleibt der bisherige Wert unverändert
					if ( ! empty( $f['required'] ) && ! $id && empty( $data[ $name ] ) ) {
						$errors[] = sprintf( /* translators: %s: Feldname */ __( '%s ist ein Pflichtfeld.', 'mietfux' ), $f['label'] );
					}
					continue;
				}
				$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- typabhängig in self::sanitize() bereinigt.
				$val = self::sanitize( $f['type'], $raw );
				if ( ! empty( $f['required'] ) && ( $val === '' || $val === null ) ) {
					$errors[] = sprintf( /* translators: %s: Feldname */ __( '%s ist ein Pflichtfeld.', 'mietfux' ), $f['label'] );
				}
				// Leere Fremdschlüssel (optionale Zuordnungen) als NULL speichern
				if ( $f['type'] === 'select' && $val === '' && substr( $name, -3 ) === '_id' ) {
					$val = null;
				}
				$data[ $name ] = $val;
			}

			if ( $key === 'anlagegueter' ) {
				$teile = (float) $data['nk_grunderwerbsteuer'] + (float) $data['nk_notar'] + (float) $data['nk_grundbuch'] + (float) $data['nk_makler'] + (float) $data['nk_sonstige'];
				if ( $teile > 0 ) {
					$data['nebenkosten'] = round( $teile, 2 );
				}
			}

			if ( $key === 'belege' ) {
				if ( empty( $data['steuerjahr'] ) && ! empty( $data['datum'] ) ) {
					$data['steuerjahr'] = (int) substr( $data['datum'], 0, 4 );
				}
				if ( empty( $data['zeitraum_von'] ) ) {
					$data['zeitraum_von'] = $data['datum'];
				}
				if ( empty( $data['zeitraum_bis'] ) ) {
					$data['zeitraum_bis'] = $data['zeitraum_von'];
				}
			}

			if ( $zusatz !== null ) {
				$data['__zusatz'] = $zusatz; // nur für Plausibilität, wird vor dem Speichern entfernt
			}
			$errors = array_merge( $errors, self::plausibel( $key, $data, $id ) );
			unset( $data['__zusatz'] );

			if ( $errors ) {
				set_transient( 'mietv_errors_' . get_current_user_id(), $errors, 120 );
				set_transient( 'mietv_formdata_' . get_current_user_id(), $data, 120 );
				wp_safe_redirect(
					self::url(
						$key,
						$id ? [
							'action' => 'edit',
							'id'     => $id,
						] : [ 'action' => 'new' ]
					)
				);
				exit;
			}

			$table = mietv_table( $key );
			$data  = self::null_bereinigen( $table, $data, (bool) $id );
			if ( $id ) {
				$ok = $wpdb->update( $table, $data, [ 'id' => $id ] );
			} else {
				if ( ! empty( $ent['owner'] ) ) {
					$data['owner_id'] = MIETV_OWNER_ID;
				}
				if ( $key === 'objekte' ) {
					$data['created_at'] = current_time( 'mysql' );
				}
				$ok = $wpdb->insert( $table, $data );
			}
			if ( $ok !== false && $key === 'anlagegueter' && (float) $data['nk_finanzierung'] > 0 ) {
				self::finanzierungsbeleg( (int) $data['objekt_id'], (float) $data['nk_finanzierung'], $data['anschaffung'], $data['bezeichnung'] );
			}
			if ( $ok !== false && $zusatz !== null ) {
				$mv_id = $id ?: (int) $wpdb->insert_id;
				$wpdb->delete( mietv_table( 'mv_einheiten' ), [ 'mv_id' => $mv_id ] );
				foreach ( $zusatz as $eid => $anteil ) {
					$wpdb->insert(
						mietv_table( 'mv_einheiten' ),
						[
							'mv_id'      => $mv_id,
							'einheit_id' => $eid,
							'mietanteil' => $anteil,
						]
					);
				}
			}
			if ( $ok === false ) {
				$errors[] = sprintf( /* translators: %s: Datenbankfehler */ __( 'Speichern fehlgeschlagen: %s', 'mietfux' ), $wpdb->last_error ?: __( 'unbekannter Datenbankfehler', 'mietfux' ) );
				set_transient( 'mietv_errors_' . get_current_user_id(), $errors, 120 );
				set_transient( 'mietv_formdata_' . get_current_user_id(), $data, 120 );
				wp_safe_redirect(
					self::url(
						$key,
						$id ? [
							'action' => 'edit',
							'id'     => $id,
						] : [ 'action' => 'new' ]
					)
				);
				exit;
			}
			wp_safe_redirect( self::url( $key, [ 'msg' => 'saved' ] ) );
			exit;
		}

		// Löschen
		$key = self::current_entity();
		if ( $key && isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) ) {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Keine Berechtigung.', 'mietfux' ) );
			}
			$id = ( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
			check_admin_referer( 'mietv_delete_' . $key . '_' . $id );
			$ent = self::entities()[ $key ];

			if ( ! empty( $ent['children'] ) ) {
				[ $ctable, $fk ] = $ent['children'];
				$cnt             = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mietv_table( $ctable ) . " WHERE $fk = %d", $id ) );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
				if ( $cnt > 0 ) {
					wp_safe_redirect( self::url( $key, [ 'msg' => 'haschildren' ] ) );
					exit;
				}
			}
			if ( $key === 'mietverhaeltnisse' ) {
				$wpdb->delete( mietv_table( 'mv_einheiten' ), [ 'mv_id' => $id ] );
				$wpdb->delete( mietv_table( 'konditionen' ), [ 'mv_id' => $id ] );
				$wpdb->delete( mietv_table( 'meldungen' ), [ 'mv_id' => $id ] );
			}
			$wpdb->delete( mietv_table( $key ), [ 'id' => $id ] );
			wp_safe_redirect( self::url( $key, [ 'msg' => 'deleted' ] ) );
			exit;
		}
	}

	/**
	 * NULL-Werte gegen die Spaltendefinition prüfen: NOT-NULL-Spalten bekommen beim Insert
	 * ihren Default (Schlüssel entfernen), beim Update den Default-Wert explizit.
	 */
	private static function null_bereinigen( string $table, array $data, bool $update ): array {
		global $wpdb;
		static $cache = [];
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = [];
			$cols            = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
			foreach ( (array) $cols as $c ) {
				$cache[ $table ][ $c['Field'] ] = [
					'null'    => strtoupper( (string) $c['Null'] ) === 'YES',
					'default' => $c['Default'],
				];
			}
		}
		foreach ( $data as $k => $v ) {
			if ( $v !== null || ! isset( $cache[ $table ][ $k ] ) || $cache[ $table ][ $k ]['null'] ) {
				continue;
			}
			$def = $cache[ $table ][ $k ]['default'];
			if ( $update ) {
				$data[ $k ] = $def ?? '';
			} elseif ( $def !== null ) {
				unset( $data[ $k ] ); // DB-Default greift
			} else {
				$data[ $k ] = '';
			}
		}
		return $data;
	}

	/** Geldbeschaffungskosten (Grundschuldbestellung) als Beleg anlegen – einmalig je Objekt/Betrag. */
	private static function finanzierungsbeleg( int $objekt_id, float $betrag, string $datum, string $bezeichnung ): void {
		global $wpdb;
		$ka = $wpdb->get_var( 'SELECT id FROM ' . $wpdb->prefix . "mv_kostenarten WHERE name LIKE '%Geldbeschaffung%' ORDER BY id LIMIT 1" );
		if ( ! $ka ) {
			$wpdb->insert(
				mietv_table( 'kostenarten' ),
				[
					'owner_id'     => MIETV_OWNER_ID,
					'name'         => 'Geldbeschaffungskosten / Disagio',
					'anlage_v'     => 'schuldzinsen',
					'umlagefaehig' => 0,
					'schluessel'   => '',
					'betrkv'       => '',
					'sortierung'   => 30,
				]
			);
			$ka = $wpdb->insert_id;
		}
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_belege WHERE objekt_id = %d AND kostenart_id = %d AND ABS(betrag - %f) < 0.01', $objekt_id, $ka, $betrag ) );
		if ( $exists ) {
			return;
		}
		$wpdb->insert(
			mietv_table( 'belege' ),
			[
				'owner_id'         => MIETV_OWNER_ID,
				'objekt_id'        => $objekt_id,
				'kostenart_id'     => (int) $ka,
				'datum'            => $datum,
				'steuerjahr'       => (int) substr( $datum, 0, 4 ),
				'betrag'           => $betrag,
				'zeitraum_von'     => $datum,
				'zeitraum_bis'     => $datum,
				'lieferant'        => 'Notar / Grundbuchamt',
				'belegnr'          => '',
				'beschreibung'     => 'Grundschuldbestellung für ' . $bezeichnung . ' – sofort abziehbare Geldbeschaffungskosten (automatisch aus Anlagegut)',
				'verteilung_jahre' => 1,
				'par35a'           => '',
				'lohnanteil'       => 0,
			]
		);
	}

	/** Fachliche Prüfungen über Feldgrenzen hinweg. */
	private static function plausibel( string $key, array $d, int $id ): array {
		global $wpdb;
		$e     = [];
		$heute = current_time( 'Y-m-d' );
		switch ( $key ) {
			case 'mietverhaeltnisse':
				if ( ! empty( $d['ende'] ) && ! empty( $d['beginn'] ) && $d['ende'] < $d['beginn'] ) {
					$e[] = __( 'Mietende liegt vor dem Mietbeginn.', 'mietfux' );
				}
				if ( ! empty( $d['einheit_id'] ) && ! empty( $d['beginn'] ) ) {
					$ende      = $d['ende'] ?: '9999-12-31';
					$kollision = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_mietverhaeltnisse WHERE einheit_id = %d AND id <> %d AND beginn <= %s AND COALESCE(NULLIF(ende,'0000-00-00'),'9999-12-31') >= %s", $d['einheit_id'], $id, $ende, $d['beginn'] ) );
					if ( $kollision ) {
						$e[] = __( 'Für diese Einheit besteht im Zeitraum bereits ein anderes Mietverhältnis.', 'mietfux' );
					}
				}
				if ( isset( $d['zahltag'] ) && ( $d['zahltag'] < 1 || $d['zahltag'] > 28 ) ) {
					$e[] = __( 'Zahltag muss zwischen 1 und 28 liegen.', 'mietfux' );
				}
				foreach ( array_keys( $d['__zusatz'] ?? [] ) as $eid ) {
					if ( $eid === (int) ( $d['einheit_id'] ?? 0 ) ) {
						$e[] = __( 'Die Haupteinheit kann nicht zugleich weitere Einheit sein.', 'mietfux' );
						continue;
					}
					$ende  = $d['ende'] ?: '9999-12-31';
					$koll  = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_mv_einheiten z JOIN ' . $wpdb->prefix . "mv_mietverhaeltnisse mv ON mv.id = z.mv_id WHERE z.einheit_id = %d AND mv.id <> %d AND mv.beginn <= %s AND COALESCE(NULLIF(mv.ende,'0000-00-00'),'9999-12-31') >= %s", $eid, $id, $ende, $d['beginn'] ?? '0000-00-00' ) );
					$koll += $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_mietverhaeltnisse WHERE einheit_id = %d AND id <> %d AND beginn <= %s AND COALESCE(NULLIF(ende,'0000-00-00'),'9999-12-31') >= %s", $eid, $id, $ende, $d['beginn'] ?? '0000-00-00' ) );
					if ( $koll ) {
						$e[] = sprintf( /* translators: %s: Einheit */ __( 'Weitere Einheit „%s“ ist im Zeitraum bereits anderweitig vermietet.', 'mietfux' ), self::opt_einheiten()[ $eid ] ?? $eid );
					}
				}
				break;
			case 'konditionen':
				if ( ! empty( $d['mv_id'] ) && ! empty( $d['gueltig_ab'] ) ) {
					$mv = $wpdb->get_row( $wpdb->prepare( 'SELECT beginn, kaution FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $d['mv_id'] ) );
					if ( $mv && $d['gueltig_ab'] < $mv->beginn ) {
						$e[] = 'Gültig ab (' . mietv_date( $d['gueltig_ab'] ) . ') liegt vor dem Mietbeginn (' . mietv_date( $mv->beginn ) . ').';
					}
					if ( $mv && (float) $mv->kaution > 3 * (float) $d['kaltmiete'] + 0.005 && (float) $d['kaltmiete'] > 0 ) {
						$e[] = 'Hinweis: Die Kaution (' . mietv_eur( $mv->kaution ) . ') übersteigt drei Kaltmieten (§ 551 BGB) – bitte Mietverhältnis prüfen.';
					}
				}
				if ( isset( $d['kaltmiete'] ) && $d['kaltmiete'] <= 0 ) {
					$e[] = __( 'Kaltmiete muss größer als 0 sein.', 'mietfux' );
				}
				break;
			case 'zahlungen':
				if ( isset( $d['betrag'] ) && abs( (float) $d['betrag'] ) < 0.005 ) {
					$e[] = __( 'Betrag darf nicht 0 sein.', 'mietfux' );
				}
				if ( ! empty( $d['datum'] ) && $d['datum'] > gmdate( 'Y-m-d', strtotime( $heute . ' +7 days' ) ) ) {
					$e[] = __( 'Zahlungsdatum liegt in der Zukunft.', 'mietfux' );
				}
				if ( ! empty( $d['mv_id'] ) && ! empty( $d['monat'] ) ) {
					$mv = $wpdb->get_row( $wpdb->prepare( 'SELECT beginn FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $d['mv_id'] ) );
					if ( $mv && $d['monat'] < substr( $mv->beginn, 0, 7 ) . '-01' ) {
						$e[] = __( 'Zuordnungsmonat liegt vor dem Mietbeginn.', 'mietfux' );
					}
				}
				break;
			case 'belege':
				if ( ! empty( $d['zeitraum_von'] ) && ! empty( $d['zeitraum_bis'] ) && $d['zeitraum_bis'] < $d['zeitraum_von'] ) {
					$e[] = __( 'Leistungszeitraum: „bis“ liegt vor „von“.', 'mietfux' );
				}
				if ( isset( $d['betrag'] ) && (float) $d['betrag'] <= 0 ) {
					$e[] = __( 'Betrag muss größer als 0 sein (Gutschriften als negativen Beleg mit Beschreibung erfassen ist nicht vorgesehen – bitte mit dem Originalbeleg verrechnen).', 'mietfux' );
				}
				if ( ! empty( $d['lohnanteil'] ) && ! empty( $d['betrag'] ) && (float) $d['lohnanteil'] > (float) $d['betrag'] ) {
					$e[] = __( 'Lohnanteil kann nicht größer als der Rechnungsbetrag sein.', 'mietfux' );
				}
				if ( ! empty( $d['steuerjahr'] ) && ! empty( $d['datum'] ) && abs( (int) $d['steuerjahr'] - (int) substr( $d['datum'], 0, 4 ) ) > 1 ) {
					$e[] = __( 'Steuerjahr weicht um mehr als ein Jahr vom Zahlungsdatum ab.', 'mietfux' );
				}
				break;
			case 'einheiten':
				if ( isset( $d['flaeche'] ) && (float) $d['flaeche'] <= 0 && in_array( $d['typ'] ?? '', [ 'wohnung', 'gewerbe' ], true ) ) {
					$e[] = __( 'Wohnfläche muss größer als 0 sein (wird für die Betriebskostenabrechnung benötigt).', 'mietfux' );
				}
				break;
			case 'zaehlerstaende':
				if ( ! empty( $d['zaehler_id'] ) && ! empty( $d['datum'] ) ) {
					$vorher = $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum < %s AND id <> %d ORDER BY datum DESC LIMIT 1', $d['zaehler_id'], $d['datum'], $id ) );
					if ( $vorher !== null && (float) $d['stand'] < (float) $vorher ) {
						$e[] = 'Zählerstand (' . $d['stand'] . ') ist kleiner als der vorherige Stand (' . $vorher . ') – Zählerwechsel? Dann neuen Zähler anlegen.';
					}
				}
				break;
			case 'anlagegueter':
				if ( ( $d['typ'] ?? '' ) === 'gebaeude' && ( (float) ( $d['grundanteil'] ?? 0 ) < 0 || (float) $d['grundanteil'] >= 100 ) ) {
					$e[] = __( 'Anteil Grund und Boden muss unter 100 % liegen.', 'mietfux' );
				}
				if ( ( $d['typ'] ?? '' ) === 'beweglich' && empty( $d['nutzungsdauer'] ) && (float) ( $d['satz'] ?? 0 ) <= 0 ) {
					$e[] = __( 'Bewegliches Wirtschaftsgut: Nutzungsdauer oder AfA-Satz angeben.', 'mietfux' );
				}
				break;
			case 'darlehen_jahre':
				if ( ! empty( $d['jahr'] ) && ( $d['jahr'] < 1990 || $d['jahr'] > (int) current_time( 'Y' ) ) ) {
					$e[] = __( 'Jahr ungültig.', 'mietfux' );
				}
				break;
			case 'mieter':
				if ( ! empty( $d['iban'] ) ) {
					$iban = strtoupper( preg_replace( '/\s+/', '', $d['iban'] ) );
					if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) || ! self::iban_ok( $iban ) ) {
						$e[] = __( 'IBAN ungültig (Prüfziffer).', 'mietfux' );
					}
				}
				break;
		}
		return $e;
	}

	/** IBAN-Prüfziffer (ISO 7064 Mod 97-10). */
	public static function iban_ok( string $iban ): bool {
		$um  = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$num = '';
		foreach ( str_split( $um ) as $c ) {
			$num .= ctype_alpha( $c ) ? (string) ( ord( $c ) - 55 ) : $c;
		}
		$rest = 0;
		foreach ( str_split( $num, 7 ) as $chunk ) {
			$rest = (int) ( ( $rest . $chunk ) % 97 );
		}
		return $rest === 1;
	}

	private static function sanitize( string $type, $raw ) {
		switch ( $type ) {
			case 'int':
				return $raw === '' ? null : (int) $raw;
			case 'decimal':
				return $raw === '' ? 0 : mietv_to_float( $raw );
			case 'date':
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : null;
			case 'month':
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^\d{4}-\d{2}$/', $raw ) ? $raw . '-01' : null;
			case 'datetime':
				$raw = sanitize_text_field( $raw );
				$ts  = strtotime( $raw );
				return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
			case 'email':
				return sanitize_email( $raw );
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'select':
				return sanitize_text_field( $raw ); // leer bleibt '' – Fremdschlüssel werden in handle_actions auf NULL gesetzt
			default:
				return sanitize_text_field( $raw );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Seiten                                                             */
	/* ------------------------------------------------------------------ */

	private static function notices(): void {
		$msg = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$map = [
			'saved'       => [ 'success', esc_html__( 'Gespeichert.', 'mietfux' ) ],
			'deleted'     => [ 'success', esc_html__( 'Gelöscht.', 'mietfux' ) ],
			'haschildren' => [ 'error', esc_html__( 'Löschen nicht möglich: Es sind noch abhängige Datensätze vorhanden.', 'mietfux' ) ],
			'gebucht'     => [ 'success', sprintf( /* translators: %d: Anzahl */ __( '%d Mieteingang/-eingänge gebucht.', 'mietfux' ), ( isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ) ) ], // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		];
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $map[ $msg ][0], esc_html( $map[ $msg ][1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		}
		$errors = get_transient( 'mietv_errors_' . get_current_user_id() );
		if ( $errors ) {
			delete_transient( 'mietv_errors_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
		}
	}

	public static function page_entity(): void {
		$key = self::current_entity();
		if ( ! $key ) {
			return;
		}
		$ent    = self::entities()[ $key ];
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.

		echo '<div class="wrap mv-wrap">';
		if ( $action === 'new' || $action === 'edit' ) {
			self::render_form( $key, $ent );
		} else {
			self::render_list( $key, $ent );
		}
		echo '</div>';
	}

	private static function render_list( string $key, array $ent ): void {
		global $wpdb;
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">' . /* translators: %s: Wert */ esc_html__( '%s hinzufügen', 'mietfux' ) . '</a><hr class="wp-header-end">',
			esc_html( $ent['label'] ),
			esc_url( self::url( $key, [ 'action' => 'new' ] ) ),
			esc_html( $ent['singular'] )
		);
		self::notices();

		$where = '';
		if ( ! empty( $ent['filter_year'] ) ) {
			$col   = $ent['filter_year'];
			$jahre = $wpdb->get_col( "SELECT DISTINCT $col FROM " . mietv_table( $key ) . " ORDER BY $col DESC" );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
			$jahr  = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : ( $jahre ? (int) $jahre[0] : (int) current_time( 'Y' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			if ( $jahr > 0 ) {
				$where = $wpdb->prepare( " WHERE $col = %d", $jahr );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
			}
			echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_' . esc_attr( $key ) . '"><label>Jahr: <select name="jahr" onchange="this.form.submit()">';
			printf( '<option value="0"%s>' . esc_html__( 'alle', 'mietfux' ) . '</option>', selected( $jahr, 0, false ) );
			foreach ( array_unique( array_merge( $jahre, [ (string) current_time( 'Y' ) ] ) ) as $j ) {
				printf( '<option value="%1$d"%2$s>%1$d</option>', (int) $j, selected( $jahr, (int) $j, false ) );
			}
			echo '</select></label></form>';
		}

		$rows = $wpdb->get_results( 'SELECT * FROM ' . mietv_table( $key ) . $where . ' ORDER BY ' . $ent['order'] );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
		$cols = array_filter( $ent['fields'], fn( $f ) => ! empty( $f['list'] ) );

		// Options einmalig auflösen
		$resolved = [];
		foreach ( $cols as $name => $f ) {
			if ( $f['type'] === 'select' ) {
				$resolved[ $name ] = is_callable( $f['options'] ) ? call_user_func( $f['options'] ) : $f['options'];
			}
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( $cols as $f ) {
			echo '<th>' . esc_html( $f['label'] ) . '</th>';
		}
		echo '<th class="mv-actions">' . esc_html__( 'Aktionen', 'mietfux' ) . '</th></tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( count( $cols ) + 1 ) . '">Noch keine Einträge.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr>';
			foreach ( $cols as $name => $f ) {
				if ( $f['type'] === 'zusatz' ) {
					$z = Mietv_Soll::zusatzeinheiten( (int) $r->id );
					echo '<td>' . esc_html( $z ? implode( ', ', array_map( fn( $x ) => $x->bezeichnung . ( (float) $x->mietanteil > 0 ? ' (' . mietv_eur( $x->mietanteil ) . ')' : '' ), $z ) ) : '–' ) . '</td>';
					continue;
				}
				if ( $f['type'] === 'file' ) {
					$url = $r->$name ? wp_get_attachment_url( (int) $r->$name ) : '';
					echo '<td>' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank">Beleg öffnen</a>' : '–' ) . '</td>';
					continue;
				}
				echo '<td>' . esc_html( self::display( $f, $r->$name, $resolved[ $name ] ?? [] ) ) . '</td>';
			}
			$edit = self::url(
				$key,
				[
					'action' => 'edit',
					'id'     => $r->id,
				]
			);
			$del  = wp_nonce_url(
				self::url(
					$key,
					[
						'action' => 'delete',
						'id'     => $r->id,
					]
				),
				'mietv_delete_' . $key . '_' . $r->id
			);
			printf( '<td class="mv-actions"><a href="%s">' . esc_html__( 'Bearbeiten', 'mietfux' ) . '</a> | <a href="%s" class="mv-delete" onclick="return confirm(\'Wirklich löschen?\')">' . esc_html__( 'Löschen', 'mietfux' ) . '</a></td>', esc_url( $edit ), esc_url( $del ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function display( array $f, $value, array $options ): string {
		switch ( $f['type'] ) {
			case 'decimal':
				return mietv_eur( $value );
			case 'date':
				return mietv_date( $value );
			case 'month':
				return mietv_monat( $value );
			case 'datetime':
				return $value ? date_i18n( 'd.m.Y H:i', strtotime( $value ) ) : '–';
			case 'select':
				return $options[ $value ] ?? ( $value ?: '–' );
			default:
				return (string) $value;
		}
	}

	private static function render_form( string $key, array $ent ): void {
		global $wpdb;
		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$row      = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( $key ) . ' WHERE id = %d', $id ) ) : null;  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
		$formdata = get_transient( 'mietv_formdata_' . get_current_user_id() );
		if ( is_array( $formdata ) ) {
			delete_transient( 'mietv_formdata_' . get_current_user_id() );
			$row = (object) array_merge( $row ? (array) $row : [], $formdata );
		}

		printf( '<h1>%s %s</h1>', $id ? esc_html__( 'Bearbeiten:', 'mietfux' ) : esc_html__( 'Neu:', 'mietfux' ), esc_html( $ent['singular'] ) );
		self::notices();

		echo '<form method="post" class="mv-form"' . ( ! empty( $ent['upload'] ) ? ' enctype="multipart/form-data"' : '' ) . '>';
		wp_nonce_field( 'mietv_save_' . $key );
		printf( '<input type="hidden" name="mietv_entity" value="%s"><input type="hidden" name="mietv_record_id" value="%d">', esc_attr( $key ), (int) $id );
		echo '<table class="form-table"><tbody>';

		foreach ( $ent['fields'] as $name => $f ) {
			$val = $row && property_exists( $row, $name ) ? $row->$name : '';
			$req = ! empty( $f['required'] ) ? ' required' : '';
			$lbl = esc_html( $f['label'] ) . ( ! empty( $f['required'] ) ? ' <span class="mv-req">*</span>' : '' );
			printf( '<tr><th scope="row"><label for="mietv_%s">%s</label></th><td>', esc_attr( $name ), $lbl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.

			switch ( $f['type'] ) {
				case 'zusatz':
					$vorhanden = [];
					foreach ( $id ? Mietv_Soll::zusatzeinheiten( $id ) : [] as $z ) {
						$vorhanden[ $z->einheit_id ] = (float) $z->mietanteil;
					}
					$alle = $wpdb->get_results( 'SELECT e.id, e.bezeichnung, e.typ, o.bezeichnung AS objekt FROM ' . $wpdb->prefix . 'mv_einheiten e JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = e.objekt_id ORDER BY o.bezeichnung, e.typ, e.bezeichnung' );
					if ( ! $alle ) {
						echo '<p class="description">' . esc_html__( 'Noch keine Einheiten angelegt.', 'mietfux' ) . '</p>';
					}
					echo '<table class="mv-zusatz">';
					foreach ( $alle as $e ) {
						$on = isset( $vorhanden[ $e->id ] );
						printf(
							'<tr><td><label><input type="checkbox" name="%1$s_on[%2$d]" value="1"%3$s> %4$s / %5$s <small>(%6$s)</small></label></td><td>%7$s <input type="text" name="%1$s[%2$d]" value="%8$s" class="small-text mv-num" inputmode="decimal"> €</td></tr>',
							esc_attr( $name ),
							(int) $e->id,
							checked( $on, true, false ),
							esc_html( $e->objekt ),
							esc_html( $e->bezeichnung ),
							esc_html( $e->typ ),
							esc_html__( 'Mietanteil', 'mietfux' ),
							esc_attr( $on && $vorhanden[ $e->id ] > 0 ? number_format( $vorhanden[ $e->id ], 2, ',', '' ) : '' )
						);
					}
					echo '</table>';
					break;
				case 'file':
					if ( $val ) {
						$url = wp_get_attachment_url( (int) $val );
						if ( $url ) {
							printf( '<p><a href="%s" target="_blank">' . esc_html__( 'Aktuelle Datei öffnen', 'mietfux' ) . '</a> ' . esc_html__( '– neue Datei wählen, um sie zu ersetzen.', 'mietfux' ) . '</p>', esc_url( $url ) );
						}
					}
					printf( '<input type="file" id="mietv_%1$s" name="%1$s" accept=".pdf,image/*"%2$s>', esc_attr( $name ), ! empty( $f['extract'] ) ? ' data-mv-extract="' . esc_attr( $f['extract'] ) . '"' : '' );
					if ( wp_is_mobile() ) {
						printf( ' <label class="button"><input type="file" name="%1$s" accept="image/*" capture="environment" style="display:none" onchange="document.getElementById(\'mietv_%1$s\').disabled=!!this.files.length">' . esc_html__( '📷 Foto aufnehmen', 'mietfux' ) . '</label>', esc_attr( $name ) );
					}
					if ( ! empty( $f['extract'] ) ) {
						echo '<div id="mv-extract-panel" class="mv-extract" hidden></div>';
					}
					break;
				case 'textarea':
					printf( '<textarea id="mietv_%1$s" name="%1$s" rows="4" class="large-text">%2$s</textarea>', esc_attr( $name ), esc_textarea( (string) $val ) );
					break;
				case 'select':
					$options = is_callable( $f['options'] ) ? call_user_func( $f['options'] ) : $f['options'];
					printf( '<select id="mietv_%1$s" name="%1$s"%2$s>', esc_attr( $name ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
					if ( ! empty( $f['required'] ) ) {
						echo '<option value="">' . esc_html__( '– bitte wählen –', 'mietfux' ) . '</option>';
					}
					foreach ( $options as $k => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $val, (string) $k, false ), esc_html( $label ) );
					}
					echo '</select>';
					break;
				case 'decimal':
					printf( '<input type="text" id="mietv_%1$s" name="%1$s" value="%2$s" class="regular-text mv-num" inputmode="decimal"%3$s>', esc_attr( $name ), esc_attr( $val !== '' && $val !== null ? number_format( (float) $val, 2, ',', '' ) : '' ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
					break;
				case 'int':
					printf( '<input type="number" id="mietv_%1$s" name="%1$s" value="%2$s" class="small-text"%3$s>', esc_attr( $name ), esc_attr( (string) $val ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
					break;
				case 'date':
					printf( '<input type="date" id="mietv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val && $val !== '0000-00-00' ? $val : '' ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
					break;
				case 'month':
					printf( '<input type="month" id="mietv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val ? substr( $val, 0, 7 ) : current_time( 'Y-m' ) ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
					break;
				case 'datetime':
					printf( '<input type="datetime-local" id="mietv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val ? gmdate( 'Y-m-d\TH:i', strtotime( $val ) ) : current_time( 'Y-m-d\TH:i' ) ), $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
					break;
				default:
					printf( '<input type="%4$s" id="mietv_%1$s" name="%1$s" value="%2$s" class="regular-text"%3$s>', esc_attr( $name ), esc_attr( (string) $val ), $req, $f['type'] === 'email' ? 'email' : 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
			}
			if ( ! empty( $f['hint'] ) ) {
				echo '<p class="description">' . esc_html( $f['hint'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $ent['after_form'] ) ) {
			echo '<p class="description">' . esc_html( $ent['after_form'] ) . '</p>';
		}
		submit_button( 'Speichern' );
		printf( '<a href="%s" class="button">' . esc_html__( 'Abbrechen', 'mietfux' ) . '</a>', esc_url( self::url( $key ) ) );
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Übersicht: Offene Posten                                           */
	/* ------------------------------------------------------------------ */

	/** Kennzahlen, Schnellzugriff und Hinweise oberhalb der offenen Posten. */
	private static function dashboard( array $mvs, int $offene_meldungen ): void {
		global $wpdb;
		$jahr       = (int) current_time( 'Y' );
		$objekte    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_objekte' );
		$einheiten  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_einheiten' );
		$einnahmen  = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(betrag),0) FROM ' . $wpdb->prefix . 'mv_zahlungen WHERE datum BETWEEN %s AND %s', $jahr . '-01-01', $jahr . '-12-31' ) );
		$kosten     = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(betrag),0) FROM ' . $wpdb->prefix . 'mv_belege WHERE steuerjahr = %d', $jahr ) );
		$rueckstand = 0.0;
		$soll_monat = 0.0;
		foreach ( $mvs as $mv ) {
			$op = Mietv_Soll::op_liste( $mv );
			if ( $op['saldo'] < -0.005 ) {
				$rueckstand += -$op['saldo'];
			}
			$k = Mietv_Soll::konditionen( (int) $mv->id, current_time( 'Y-m-01' ) );
			if ( $k ) {
				$soll_monat += (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz;
			}
		}
		$hinweise = class_exists( 'Mietv_Hinweise' ) ? Mietv_Hinweise::alle() : [];

		$kpi = [
			[ 'admin-home', __( 'Objekte / Einheiten', 'mietfux' ), $objekte . ' / ' . $einheiten, count( $mvs ) . ' ' . __( 'aktive Mietverhältnisse', 'mietfux' ), self::url( 'objekte' ), '' ],
			[ 'money-alt', __( 'Monatliches Soll', 'mietfux' ), mietv_eur( $soll_monat ), __( 'Kaltmiete + Vorauszahlungen', 'mietfux' ), self::url( 'konditionen' ), '' ],
			[ 'warning', __( 'Offene Mietforderungen', 'mietfux' ), mietv_eur( $rueckstand ), $rueckstand > 0.005 ? __( 'Rückstand – unten abhaken oder mahnen', 'mietfux' ) : __( 'Alle Mietkonten ausgeglichen', 'mietfux' ), admin_url( 'admin.php?page=mietv_mahnung' ), $rueckstand > 0.005 ? 'mv-kpi-neg' : 'mv-kpi-pos' ],
			[ 'chart-pie', sprintf( /* translators: %d: Jahr */ __( 'Einnahmen %d', 'mietfux' ), $jahr ), mietv_eur( $einnahmen ), sprintf( /* translators: %s: Betrag */ __( 'Werbungskosten bisher %s', 'mietfux' ), mietv_eur( $kosten ) ), admin_url( 'admin.php?page=mietv_steuer&jahr=' . $jahr ), '' ],
		];
		echo '<div class="mv-kpis">';
		foreach ( $kpi as [ $icon, $label, $wert, $sub, $url, $cls ] ) {
			printf(
				'<a class="mv-kpi %s" href="%s"><span class="mv-kpi-icon dashicons dashicons-%s"></span><span class="mv-kpi-label">%s</span><strong class="mv-kpi-wert">%s</strong><span class="mv-kpi-sub">%s</span></a>',
				esc_attr( $cls ),
				esc_url( $url ),
				esc_attr( $icon ),
				esc_html( $label ),
				esc_html( $wert ),
				esc_html( $sub )
			);
		}
		echo '</div>';

		$schnell = [
			[ 'money-alt', __( 'Zahlung erfassen', 'mietfux' ), self::url( 'zahlungen', [ 'action' => 'new' ] ) ],
			[ 'clipboard', __( 'Beleg erfassen', 'mietfux' ), self::url( 'belege', [ 'action' => 'new' ] ) ],
			[ 'bank', __( 'Bankimport', 'mietfux' ), admin_url( 'admin.php?page=mietv_bank' ) ],
			[ 'editor-ol', __( 'Zählerstand', 'mietfux' ), self::url( 'zaehlerstaende', [ 'action' => 'new' ] ) ],
			[ 'calculator', __( 'BK-Abrechnung', 'mietfux' ), admin_url( 'admin.php?page=mietv_abrechnung' ) ],
			[ 'media-document', __( 'Mietvertrag', 'mietfux' ), admin_url( 'admin.php?page=mietv_mietvertrag' ) ],
		];
		echo '<div class="mv-quick">';
		foreach ( $schnell as [ $icon, $label, $url ] ) {
			printf( '<a class="mv-quick-btn" href="%s"><span class="dashicons dashicons-%s"></span>%s</a>', esc_url( $url ), esc_attr( $icon ), esc_html( $label ) );
		}
		echo '</div>';

		if ( $hinweise || $offene_meldungen ) {
			echo '<section class="mv-card mv-hinweise"><header class="mv-card-head"><h2>' . esc_html__( 'Hinweise', 'mietfux' ) . '</h2></header><ul>';
			if ( $offene_meldungen ) {
				printf( '<li class="warn"><span class="dashicons dashicons-warning"></span><a href="%s">' . /* translators: %d: Anzahl */ esc_html__( '%d offene Meldung(en) aus dem Mieterportal', 'mietfux' ) . '</a></li>', esc_url( self::url( 'meldungen' ) ), (int) $offene_meldungen );
			}
			foreach ( array_slice( $hinweise, 0, 8 ) as $h ) {
				printf( '<li class="%s"><span class="dashicons dashicons-%s"></span><a href="%s">%s</a></li>', esc_attr( $h['typ'] ), esc_attr( $h['typ'] === 'error' ? 'dismiss' : ( $h['typ'] === 'warn' ? 'warning' : 'info' ) ), esc_url( $h['url'] ), esc_html( $h['text'] ) );
			}
			echo '</ul></section>';
		}
	}

	public static function page_uebersicht(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Mietfux – Übersicht', 'mietfux' ) . '</h1> ';
		printf( '<a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">', esc_url( self::fokus_url() ), self::fokus_aktiv() ? esc_html__( 'WordPress-Menü einblenden', 'mietfux' ) : esc_html__( 'WordPress-Menü ausblenden', 'mietfux' ) );
		self::notices();

		$mvs = Mietv_Soll::alle_mv( true );
		if ( ! $mvs ) {
			echo '<p>' . esc_html__( 'Noch kein aktives Mietverhältnis. Reihenfolge zum Start:', 'mietfux' ) . ' <strong>' . esc_html__( 'Objekt → Einheit → Mieter → Mietverhältnis → Konditionen', 'mietfux' ) . '</strong> – ' . sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=mietv_assistent' ) ), esc_html__( 'oder den Einrichtungsassistenten nutzen', 'mietfux' ) ) . '.</p></div>';
			return;
		}

		$offene = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_meldungen WHERE status <> 'erledigt'" );

		self::dashboard( $mvs, $offene );

		echo '<form method="post" id="mv-op-form">';
		wp_nonce_field( 'mietv_op_buchen', 'mietv_op_nonce' );
		echo '<p class="description mv-op-hinweis">' . esc_html__( 'Offene Monate abhaken und mit einem Klick als bezahlt buchen – gebucht wird der offene Betrag mit Zahlungsdatum 1. des Monats (Quelle „abgehakt“, nachträglich unter Zahlungen änderbar).', 'mietfux' ) . ' <button class="button button-primary" onclick="return document.querySelectorAll(\'#mv-op-form input[name=\\\'op[]\\\']:checked\').length>0||(alert(\'' . esc_js( esc_html__( 'Bitte mindestens einen Monat anhaken.', 'mietfux' ) ) . '\'),false)">' . esc_html__( 'Markierte als bezahlt buchen', 'mietfux' ) . '</button></p>';
		$offen_gesamt = 0;
		foreach ( $mvs as $mv ) {
			$op          = Mietv_Soll::op_liste( $mv );
			$saldo_class = $op['saldo'] < -0.005 ? 'mv-neg' : ( $op['saldo'] > 0.005 ? 'mv-pos' : '' );

			$meta = sprintf( /* translators: %1$s: Datum, %2$s: Betrag */ __( 'Mietbeginn %1$s · Kaution %2$s', 'mietfux' ), mietv_date( $mv->beginn ), mietv_eur( $mv->kaution ) );
			printf(
				'<section class="mv-card mv-mvcard"><header class="mv-card-head"><div><h2>%s</h2><p class="mv-muted">%s</p></div><div class="mv-card-tools"><span class="mv-badge %s">' . esc_html__( 'Saldo', 'mietfux' ) . ' %s</span> <a href="%s" class="button">' . esc_html__( 'Zahlung erfassen', 'mietfux' ) . '</a> <a href="%s" class="button">' . esc_html__( 'Mietvertrag', 'mietfux' ) . '</a></div></header>',
				esc_html( Mietv_Soll::mietv_label( $mv ) ),
				esc_html( $meta ),
				esc_attr( $saldo_class ?: 'mv-neutral' ),
				esc_html( mietv_eur( $op['saldo'] ) ),
				esc_url( self::url( 'zahlungen', [ 'action' => 'new' ] ) ),
				esc_url( admin_url( 'admin.php?page=mietv_mietvertrag&mv_id=' . (int) $mv->id ) )
			);

			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" title="' . esc_attr__( 'alle offenen Monate dieses Mietverhältnisses', 'mietfux' ) . '" onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)"></th><th>' . esc_html__( 'Monat', 'mietfux' ) . '</th><th>' . esc_html__( 'Kaltmiete', 'mietfux' ) . '</th><th>' . esc_html__( 'BK-VZ', 'mietfux' ) . '</th><th>' . esc_html__( 'HK-VZ', 'mietfux' ) . '</th><th>' . esc_html__( 'Soll', 'mietfux' ) . '</th><th>' . esc_html__( 'Ist', 'mietfux' ) . '</th><th>' . esc_html__( 'Differenz', 'mietfux' ) . '</th><th>' . esc_html__( 'Saldo kumuliert', 'mietfux' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $op['rows'] ) as $r ) {
				$cls   = $r['diff'] < -0.005 ? 'mv-neg' : ( $r['diff'] > 0.005 ? 'mv-pos' : '' );
				$offen = $r['diff'] < -0.005 && ! $r['ohne_konditionen'];
				if ( $offen ) {
					++$offen_gesamt;
				}
				printf(
					'<tr><td class="mv-op-check">%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td class="%s">%s</td><td class="%s">%s</td></tr>',
					$offen ? '<input type="checkbox" name="op[]" value="' . esc_attr( $mv->id . '|' . $r['monat'] ) . '" title="' . esc_attr( sprintf( /* translators: %s: Wert */ __( '%s als bezahlt buchen', 'mietfux' ), mietv_eur( -$r['diff'] ) ) ) . '">' : ( $r['diff'] < -0.005 ? '' : '✓' ),
					esc_html( mietv_monat( $r['monat'] ) ),
					$r['ohne_konditionen'] ? ' <span class="mv-warn" title="Keine Konditionen hinterlegt">⚠</span>' : '',
					esc_html( mietv_eur( $r['kalt'] ) ),
					esc_html( mietv_eur( $r['bk'] ) ),
					esc_html( mietv_eur( $r['hk'] ) ),
					esc_html( mietv_eur( $r['soll'] ) ),
					esc_html( mietv_eur( $r['ist'] ) ),
					esc_attr( $cls ),
					esc_html( mietv_eur( $r['diff'] ) ),
					esc_attr( $r['kum'] < -0.005 ? 'mv-neg' : '' ),
					esc_html( mietv_eur( $r['kum'] ) )
				);
			}
			printf(
				'</tbody><tfoot><tr><th></th><th>' . esc_html__( 'Gesamt', 'mietfux' ) . '</th><th></th><th></th><th></th><th>%s</th><th>%s</th><th class="%s">%s</th><th></th></tr></tfoot></table>',
				esc_html( mietv_eur( $op['soll'] ) ),
				esc_html( mietv_eur( $op['ist'] ) ),
				esc_attr( $saldo_class ),
				esc_html( mietv_eur( $op['saldo'] ) )
			);
			echo '</section>';
		}
		if ( $offen_gesamt ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Markierte als bezahlt buchen', 'mietfux' ) . '</button></p>';
		}
		echo '</form></div>';
	}
}
