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
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
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
					'verwendungszweck' => sprintf( /* translators: %s: Monat */ __( 'Rent %s', 'mietfux' ), mietv_monat( $monat ) ),
					'quelle'           => 'abgehakt',
				]
			);
			++$n;
		}
		mietv_flash_set( 'gebucht', [ 'n' => $n ] );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_uebersicht' ) );
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
		if ( ! isset( $_GET['mietv_fokus'] ) ) {
			return;
		}
		check_admin_referer( 'mietv_fokus' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
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
				'title' => self::fokus_aktiv() ? esc_html__( '⌂ Show WordPress menu', 'mietfux' ) : esc_html__( '▣ Show Mietfux only', 'mietfux' ),
				'href'  => self::fokus_url(),
				'meta'  => [ 'title' => esc_html__( 'Toggle Mietfux focus mode', 'mietfux' ) ],
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
					'kein_text'    => esc_html__( 'No text layer found – the PDF is probably a scan. Please enter the values manually (or make the PDF searchable with OCR first).', 'mietfux' ),
					'kein_pdfjs'   => esc_html__( 'pdf.js could not be loaded. Please enter the values manually.', 'mietfux' ),
					'lese'         => esc_html__( 'Reading purchase contract …', 'mietfux' ),
					'fehler'       => esc_html__( 'PDF could not be read:', 'mietfux' ),
					'nur_pdf'      => esc_html__( 'Extraction is only possible for PDF files.', 'mietfux' ),
					'erkannt'      => esc_html__( 'Detected from the purchase contract', 'mietfux' ),
					'seiten'       => esc_html__( 'pages', 'mietfux' ),
					'uebernehmen'  => esc_html__( 'click to apply, then verify:', 'mietfux' ),
					'nicht'        => esc_html__( 'not found', 'mietfux' ),
					'kaufpreis'    => esc_html__( 'Purchase price', 'mietfux' ),
					'uebergang'    => esc_html__( 'Transfer of possession', 'mietfux' ),
					'grund_betrag' => esc_html__( 'Land (amount)', 'mietfux' ),
					'grund_anteil' => esc_html__( 'Land share', 'mietfux' ),
					'vertrag'      => esc_html__( 'Contract date (info)', 'mietfux' ),
					'flaeche'      => esc_html__( 'Living area (info)', 'mietfux' ),
					'hinweis'      => esc_html__( 'Detection is heuristic. If the contract lacks a land/building split, use the Federal Ministry of Finance (BMF) purchase price allocation tool.', 'mietfux' ),
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
				'label'    => esc_html__( 'Properties', 'mietfux' ),
				'singular' => esc_html__( 'Property', 'mietfux' ),
				'order'    => 'bezeichnung ASC',
				'owner'    => true,
				'children' => [ 'einheiten', 'objekt_id' ],
				'fields'   => [
					'bezeichnung'         => [
						'label'    => esc_html__( 'Name', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'strasse'             => [
						'label' => esc_html__( 'Street / no.', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'plz'                 => [
						'label' => esc_html__( 'Postcode', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'ort'                 => [
						'label' => esc_html__( 'Town', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'baujahr'             => [
						'label' => esc_html__( 'Year built', 'mietfux' ),
						'type'  => 'int',
					],
					'heizung'             => [
						'label'   => esc_html__( 'Heating', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'zentral'    => esc_html__( 'Central heating (HeizkostenV, consumption-based)', 'mietfux' ),
							'fernwaerme' => esc_html__( 'District heating (HeizkostenV)', 'mietfux' ),
							'dezentral'  => esc_html__( 'Decentralised / per-flat heating (tenant settles directly)', 'mietfux' ),
						],
					],
					'hk_verbrauchsanteil' => [
						'label' => esc_html__( 'Heating: consumption share (%)', 'mietfux' ),
						'type'  => 'int',
						'hint'  => esc_html__( '50–70 % by consumption, remainder by living area (§ 7 HeizkostenV). Central/district heating only.', 'mietfux' ),
					],
					'notiz'               => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'einheiten'         => [
				'label'    => esc_html__( 'Units', 'mietfux' ),
				'singular' => esc_html__( 'Unit', 'mietfux' ),
				'order'    => 'objekt_id ASC, bezeichnung ASC',
				'children' => [ 'mietverhaeltnisse', 'einheit_id' ],
				'fields'   => [
					'objekt_id'   => [
						'label'    => esc_html__( 'Property', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bezeichnung' => [
						'label'    => esc_html__( 'Name', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'e.g. “Ground floor flat, left”', 'mietfux' ),
					],
					'lage'        => [
						'label' => esc_html__( 'Location', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
						'hint'  => esc_html__( 'e.g. “1st floor”', 'mietfux' ),
					],
					'flaeche'     => [
						'label' => esc_html__( 'Living area (m²)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'zimmer'      => [
						'label' => esc_html__( 'Rooms', 'mietfux' ),
						'type'  => 'decimal',
					],
					'typ'         => [
						'label'   => esc_html__( 'Type', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'wohnung'    => esc_html__( 'Flat', 'mietfux' ),
							'gewerbe'    => esc_html__( 'Commercial', 'mietfux' ),
							'stellplatz' => esc_html__( 'Parking space', 'mietfux' ),
							'garage'     => esc_html__( 'Garage', 'mietfux' ),
							'keller'     => esc_html__( 'Cellar / storage', 'mietfux' ),
						],
					],
				],
			],
			'mieter'            => [
				'label'    => esc_html__( 'Tenants', 'mietfux' ),
				'singular' => esc_html__( 'Tenants', 'mietfux' ),
				'order'    => 'nachname ASC, vorname ASC',
				'owner'    => true,
				'children' => [ 'mietverhaeltnisse', 'mieter_id' ],
				'fields'   => [
					'anrede'         => [
						'label'   => esc_html__( 'Salutation', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							''      => '–',
							'Frau'  => esc_html__( 'Ms', 'mietfux' ),
							'Herr'  => esc_html__( 'Mr', 'mietfux' ),
							'Firma' => esc_html__( 'Company', 'mietfux' ),
						],
					],
					'vorname'        => [
						'label' => esc_html__( 'First name', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'nachname'       => [
						'label'    => esc_html__( 'Last name', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'email'          => [
						'label' => esc_html__( 'Email', 'mietfux' ),
						'type'  => 'email',
						'list'  => true,
					],
					'telefon'        => [
						'label' => esc_html__( 'Phone', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'geburtsdatum'   => [
						'label' => esc_html__( 'Date of birth', 'mietfux' ),
						'type'  => 'date',
						'hint'  => esc_html__( 'For the tenancy agreement (identification of the contracting party).', 'mietfux' ),
					],
					'anschrift_alt'  => [
						'label' => esc_html__( 'Previous address', 'mietfux' ),
						'type'  => 'text',
						'hint'  => esc_html__( 'Address at the time of signing (before moving in) – appears in the agreement.', 'mietfux' ),
					],
					'p_anrede'       => [
						'label'   => esc_html__( 'Co-tenant – salutation', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							''     => '–',
							'Frau' => esc_html__( 'Ms', 'mietfux' ),
							'Herr' => esc_html__( 'Mr', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Spouse/partner as second contracting party (optional). Appears automatically in the tenancy agreement, letters, SEPA mandate and handover protocol; jointly and severally liable.', 'mietfux' ),
					],
					'p_vorname'      => [
						'label' => esc_html__( 'Co-tenant – first name', 'mietfux' ),
						'type'  => 'text',
					],
					'p_nachname'     => [
						'label' => esc_html__( 'Co-tenant – last name', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'p_geburtsdatum' => [
						'label' => esc_html__( 'Co-tenant – date of birth', 'mietfux' ),
						'type'  => 'date',
					],
					'p_email'        => [
						'label' => esc_html__( 'Co-tenant – email', 'mietfux' ),
						'type'  => 'email',
						'hint'  => esc_html__( 'Receives reminders and letters in copy if provided.', 'mietfux' ),
					],
					'iban'           => [
						'label' => esc_html__( 'IBAN (for bank import matching)', 'mietfux' ),
						'type'  => 'text',
					],
					'wp_user_id'     => [
						'label'   => esc_html__( 'Portal access (WP user)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_users' ],
						'hint'    => esc_html__( 'Create the user under “Users”; the “Subscriber” role is sufficient.', 'mietfux' ),
					],
					'notiz'          => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'mietverhaeltnisse' => [
				'label'      => esc_html__( 'Tenancies', 'mietfux' ),
				'singular'   => esc_html__( 'Tenancy', 'mietfux' ),
				'order'      => 'beginn DESC',
				'children'   => [ 'zahlungen', 'mv_id' ],
				'fields'     => [
					'einheit_id'      => [
						'label'    => esc_html__( 'Unit', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_einheiten' ],
					],
					'mieter_id'       => [
						'label'    => esc_html__( 'Tenants', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mieter' ],
					],
					'beginn'          => [
						'label'    => esc_html__( 'Start of tenancy', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
					],
					'ende'            => [
						'label' => esc_html__( 'End of tenancy', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
						'hint'  => esc_html__( 'empty = open-ended', 'mietfux' ),
					],
					'kaution'         => [
						'label' => esc_html__( 'Deposit (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'personen'        => [
						'label' => esc_html__( 'Persons in household', 'mietfux' ),
						'type'  => 'int',
						'hint'  => esc_html__( 'for allocation by number of persons', 'mietfux' ),
					],
					'zahltag'         => [
						'label' => esc_html__( 'Payment day (day of month)', 'mietfux' ),
						'type'  => 'int',
					],
					'zusatzeinheiten' => [
						'label' => esc_html__( 'Additional units in the same agreement', 'mietfux' ),
						'type'  => 'zusatz',
						'list'  => true,
						'hint'  => esc_html__( 'Garage, parking space or cellar let together with the flat. The rent share is already included in the base rent of the rent conditions and is only shown separately in the agreement.', 'mietfux' ),
					],
					'notiz'           => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
				'after_form' => esc_html__( 'Rent conditions (base rent, advance payments) are entered under “Rent conditions” with a valid-from date – as the total rent incl. garage/parking. Each increase = new entry.', 'mietfux' ),
			],
			'konditionen'       => [
				'label'    => esc_html__( 'Rent conditions', 'mietfux' ),
				'singular' => esc_html__( 'Rent condition', 'mietfux' ),
				'order'    => 'mv_id ASC, gueltig_ab DESC',
				'fields'   => [
					'mv_id'      => [
						'label'    => esc_html__( 'Tenancy', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'gueltig_ab' => [
						'label'    => esc_html__( 'Valid from', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Usually the 1st of a month', 'mietfux' ),
					],
					'kaltmiete'  => [
						'label'    => esc_html__( 'Base rent (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'bk_vz'      => [
						'label' => esc_html__( 'Service charge advance (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'hk_vz'      => [
						'label' => esc_html__( 'Heating advance (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
				],
			],
			'zahlungen'         => [
				'label'    => esc_html__( 'Payments', 'mietfux' ),
				'singular' => esc_html__( 'Payment', 'mietfux' ),
				'order'    => 'datum DESC, id DESC',
				'fields'   => [
					'mv_id'            => [
						'label'    => esc_html__( 'Tenancy', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'datum'            => [
						'label'    => esc_html__( 'Payment date', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
					],
					'betrag'           => [
						'label'    => esc_html__( 'Amount (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'monat'            => [
						'label'    => esc_html__( 'Assigned month', 'mietfux' ),
						'type'     => 'month',
						'required' => true,
						'list'     => true,
					],
					'verwendungszweck' => [
						'label' => esc_html__( 'Reference', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'quelle'           => [
						'label'   => esc_html__( 'Source', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'manuell'  => esc_html__( 'manual', 'mietfux' ),
							'bank'     => esc_html__( 'Bank import', 'mietfux' ),
							'abgehakt' => esc_html__( 'Overview (ticked)', 'mietfux' ),
						],
					],
				],
			],
			'belege'            => [
				'label'       => esc_html__( 'Receipts', 'mietfux' ),
				'singular'    => esc_html__( 'Receipt', 'mietfux' ),
				'order'       => 'datum DESC, id DESC',
				'owner'       => true,
				'upload'      => true,
				'filter_year' => 'steuerjahr',
				'fields'      => [
					'objekt_id'        => [
						'label'    => esc_html__( 'Property', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'kostenart_id'     => [
						'label'    => esc_html__( 'Cost type', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_kostenarten' ],
					],
					'datum'            => [
						'label'    => esc_html__( 'Payment date', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Payment date – decisive for the tax year (§ 11 EStG). For direct debits: date of debit.', 'mietfux' ),
					],
					'steuerjahr'       => [
						'label' => esc_html__( 'Tax year', 'mietfux' ),
						'type'  => 'int',
						'list'  => true,
						'hint'  => esc_html__( 'empty = year of the payment date. Differs only for regularly recurring payments around the turn of the year (10-day rule).', 'mietfux' ),
					],
					'betrag'           => [
						'label'    => esc_html__( 'Gross amount (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'lieferant'        => [
						'label' => esc_html__( 'Issuer', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'belegnr'          => [
						'label' => esc_html__( 'Invoice / receipt number', 'mietfux' ),
						'type'  => 'text',
					],
					'zeitraum_von'     => [
						'label' => esc_html__( 'Service period from', 'mietfux' ),
						'type'  => 'date',
						'hint'  => esc_html__( 'For the service charge statement (e.g. insurance 01.01.–31.12.). Empty = payment date.', 'mietfux' ),
					],
					'zeitraum_bis'     => [
						'label' => esc_html__( 'Service period to', 'mietfux' ),
						'type'  => 'date',
					],
					'verteilung_jahre' => [
						'label'   => esc_html__( 'Spreading (§ 82b EStDV)', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							'1' => esc_html__( 'no – fully in the year of payment', 'mietfux' ),
							'2' => esc_html__( 'over 2 years', 'mietfux' ),
							'3' => esc_html__( 'over 3 years', 'mietfux' ),
							'4' => esc_html__( 'over 4 years', 'mietfux' ),
							'5' => esc_html__( 'over 5 years', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Only for larger maintenance expenses on residential buildings. Spread evenly from the tax year.', 'mietfux' ),
					],
					'par35a'           => [
						'label'   => esc_html__( '§ 35a EStG (for tenants)', 'mietfux' ),
						'type'    => 'select',
						'options' => [
							''           => esc_html__( '– no –', 'mietfux' ),
							'handwerker' => esc_html__( 'Tradesmen\'s services (20 %, max. €1,200)', 'mietfux' ),
							'haushalt'   => esc_html__( 'Household-related services (20 %, max. €4,000)', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Caretaker, gardening, chimney sweep, heating maintenance … The labour share is shown for the tenant in the service charge statement.', 'mietfux' ),
					],
					'lohnanteil'       => [
						'label' => esc_html__( 'of which gross labour costs (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'As per invoice; excluding materials.', 'mietfux' ),
					],
					'beschreibung'     => [
						'label' => esc_html__( 'Description', 'mietfux' ),
						'type'  => 'textarea',
					],
					'attachment_id'    => [
						'label' => esc_html__( 'Receipt file (PDF/photo)', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'zaehler'           => [
				'label'    => esc_html__( 'Meters', 'mietfux' ),
				'singular' => esc_html__( 'Meters', 'mietfux' ),
				'order'    => 'objekt_id ASC, einheit_id ASC, typ ASC',
				'children' => [ 'zaehlerstaende', 'zaehler_id' ],
				'fields'   => [
					'objekt_id'  => [
						'label'    => esc_html__( 'Property', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'einheit_id' => [
						'label'   => esc_html__( 'Unit', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_einheiten_optional' ],
						'hint'    => esc_html__( 'Empty = main meter of the building (info only, not used for allocation).', 'mietfux' ),
					],
					'typ'        => [
						'label'    => esc_html__( 'Type', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [
							'kaltwasser' => esc_html__( 'Cold water (m³)', 'mietfux' ),
							'warmwasser' => esc_html__( 'Hot water (m³)', 'mietfux' ),
							'heizung'    => esc_html__( 'Heating (kWh / units)', 'mietfux' ),
							'strom'      => esc_html__( 'Electricity (kWh)', 'mietfux' ),
						],
					],
					'nummer'     => [
						'label' => esc_html__( 'Meter number', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'notiz'      => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'zaehlerstaende'    => [
				'label'    => esc_html__( 'Meter readings', 'mietfux' ),
				'singular' => esc_html__( 'Meter reading', 'mietfux' ),
				'order'    => 'datum DESC, zaehler_id ASC',
				'upload'   => true,
				'fields'   => [
					'zaehler_id'    => [
						'label'    => esc_html__( 'Meters', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_zaehler' ],
					],
					'datum'         => [
						'label'    => esc_html__( 'Reading date', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'For the statement: reading at the start and end of the period (e.g. 01.01. and 31.12.).', 'mietfux' ),
					],
					'stand'         => [
						'label'    => esc_html__( 'Meter reading', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'quelle'        => [
						'label'   => esc_html__( 'Source', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'vermieter' => esc_html__( 'Landlord / reading', 'mietfux' ),
							'mieter'    => esc_html__( 'Tenant (portal)', 'mietfux' ),
							'versorger' => esc_html__( 'Supplier / meter reading service', 'mietfux' ),
						],
					],
					'attachment_id' => [
						'label' => esc_html__( 'Photo', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'bank_regeln'       => [
				'label'    => esc_html__( 'Bank import rules', 'mietfux' ),
				'singular' => esc_html__( 'Rule', 'mietfux' ),
				'order'    => 'muster ASC',
				'owner'    => true,
				'fields'   => [
					'muster'       => [
						'label'    => esc_html__( 'Search text', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'Searched in name and reference (case-insensitive), e.g. “Stadtwerke” or “N-ERGIE”.', 'mietfux' ),
					],
					'typ'          => [
						'label'   => esc_html__( 'Create as', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'beleg'      => esc_html__( 'Receipt (expense)', 'mietfux' ),
							'zahlung'    => esc_html__( 'Rent payment (income)', 'mietfux' ),
							'ignorieren' => esc_html__( 'Ignore', 'mietfux' ),
						],
					],
					'objekt_id'    => [
						'label'   => esc_html__( 'Property', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_objekte' ],
					],
					'kostenart_id' => [
						'label'   => esc_html__( 'Cost type (for receipts)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_kostenarten_optional' ],
					],
					'mv_id'        => [
						'label'   => esc_html__( 'Tenancy (for payments)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_mv_optional' ],
					],
				],
			],
			'anlagegueter'      => [
				'label'    => esc_html__( 'Depreciation', 'mietfux' ),
				'singular' => esc_html__( 'Asset', 'mietfux' ),
				'order'    => 'objekt_id ASC, anschaffung ASC',
				'owner'    => true,
				'upload'   => true,
				'fields'   => [
					'attachment_id'        => [
						'label'   => esc_html__( 'Purchase contract (PDF)', 'mietfux' ),
						'type'    => 'file',
						'list'    => true,
						'extract' => 'kaufvertrag',
						'hint'    => esc_html__( 'After selection the contract is read in the browser; detected values appear below as suggestions. Nothing is uploaded until you save.', 'mietfux' ),
					],
					'objekt_id'            => [
						'label'    => esc_html__( 'Property', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bezeichnung'          => [
						'label'    => esc_html__( 'Name', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'e.g. “Building, 1 Sample Street” or “Fitted kitchen”', 'mietfux' ),
					],
					'typ'                  => [
						'label'   => esc_html__( 'Type', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'gebaeude'  => esc_html__( 'Building', 'mietfux' ),
							'beweglich' => esc_html__( 'Movable asset (fitted kitchen, furniture …)', 'mietfux' ),
						],
					],
					'anschaffung'          => [
						'label'    => esc_html__( 'Acquisition / completion', 'mietfux' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'On purchase: transfer of possession. Depreciation starts pro rata from this month.', 'mietfux' ),
					],
					'ak'                   => [
						'label'    => esc_html__( 'Purchase price / construction costs (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'nk_grunderwerbsteuer' => [
						'label' => esc_html__( 'Real estate transfer tax (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Bavaria: 3.5 % of the purchase price (tax office assessment).', 'mietfux' ),
					],
					'nk_notar'             => [
						'label' => esc_html__( 'Notary – purchase contract (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Only the part for notarising the purchase contract. The part for the land charge belongs under “Financing” below.', 'mietfux' ),
					],
					'nk_grundbuch'         => [
						'label' => esc_html__( 'Land registry – conveyance / transfer of title (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Land registry invoice for priority notice and transfer of title.', 'mietfux' ),
					],
					'nk_makler'            => [
						'label' => esc_html__( 'Estate agent\'s commission (€)', 'mietfux' ),
						'type'  => 'decimal',
					],
					'nk_sonstige'          => [
						'label' => esc_html__( 'Other acquisition costs (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'e.g. surveyor before purchase, travel costs for viewings, translator.', 'mietfux' ),
					],
					'nebenkosten'          => [
						'label' => esc_html__( 'Total acquisition costs (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Calculated from the individual items as soon as any are entered; otherwise manual.', 'mietfux' ),
					],
					'nk_finanzierung'      => [
						'label' => esc_html__( 'Financing costs – notary / land registry for land charge (€)', 'mietfux' ),
						'type'  => 'decimal',
						'hint'  => esc_html__( 'Not acquisition costs! Immediately deductible expenses (financing costs). On saving, a receipt of cost type “Financing costs” is created automatically unless one with this amount already exists.', 'mietfux' ),
					],
					'grundanteil'          => [
						'label' => esc_html__( 'Land share (%)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Building only; the land share is not depreciable. Split as per purchase contract or the BMF purchase price allocation tool. Condominiums also have a co-ownership share of the land (often 15–30 %). The tax office generally does not accept 0 %.', 'mietfux' ),
					],
					'methode'              => [
						'label'   => esc_html__( 'Method', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'linear'    => esc_html__( 'Straight-line (§ 7 (4) EStG)', 'mietfux' ),
							'degressiv' => esc_html__( 'Declining balance (§ 7 (5a) EStG, new build started 10/2023–09/2029)', 'mietfux' ),
						],
					],
					'satz'                 => [
						'label' => esc_html__( 'Depreciation rate (% p.a.)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Empty = automatic: buildings by year built of the property (before 1925: 2.5 %, from 2023: 3 %, otherwise 2 %), movable assets 100 / useful life. Declining balance: 5 %. Enter only if different (e.g. 4 % with proven shorter remaining useful life).', 'mietfux' ),
					],
					'nutzungsdauer'        => [
						'label' => esc_html__( 'Useful life (years)', 'mietfux' ),
						'type'  => 'int',
						'hint'  => esc_html__( 'Movable assets only, e.g. fitted kitchen 10 years. Low-value assets up to €800 net: useful life 1 = immediate deduction.', 'mietfux' ),
					],
					'ende'                 => [
						'label' => esc_html__( 'Disposal (sale/withdrawal)', 'mietfux' ),
						'type'  => 'date',
					],
					'notiz'                => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'darlehen'          => [
				'label'    => esc_html__( 'Loans', 'mietfux' ),
				'singular' => esc_html__( 'Loans', 'mietfux' ),
				'order'    => 'status ASC, auszahlung ASC',
				'owner'    => true,
				'children' => [ 'darlehen_jahre', 'darlehen_id' ],
				'upload'   => true,
				'fields'   => [
					'objekt_id'       => [
						'label'    => esc_html__( 'Property', 'mietfux' ),
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
						'label' => esc_html__( 'Name / account no.', 'mietfux' ),
						'type'  => 'text',
						'list'  => true,
					],
					'betrag'          => [
						'label'    => esc_html__( 'Loan amount (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'auszahlung'      => [
						'label' => esc_html__( 'Disbursement', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
					],
					'zinssatz'        => [
						'label' => esc_html__( 'Interest rate (% p.a.)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'zinsbindung_bis' => [
						'label' => esc_html__( 'Fixed-rate period until', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
					],
					'rate'            => [
						'label' => esc_html__( 'Monthly instalment (€)', 'mietfux' ),
						'type'  => 'decimal',
					],
					'tilgung'         => [
						'label' => esc_html__( 'Initial repayment (% p.a.)', 'mietfux' ),
						'type'  => 'decimal',
					],
					'status'          => [
						'label'   => esc_html__( 'Status', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'aktiv'     => esc_html__( 'Active', 'mietfux' ),
							'abgeloest' => esc_html__( 'Redeemed', 'mietfux' ),
						],
					],
					'attachment_id'   => [
						'label' => esc_html__( 'Loan agreement (PDF)', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
					'notiz'           => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'dokumente'         => [
				'label'    => esc_html__( 'Documents', 'mietfux' ),
				'singular' => esc_html__( 'Document', 'mietfux' ),
				'order'    => 'datum DESC, id DESC',
				'owner'    => true,
				'upload'   => true,
				'fields'   => [
					'objekt_id'       => [
						'label'    => esc_html__( 'Property', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'mv_id'           => [
						'label'   => esc_html__( 'Tenancy', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_mv_optional' ],
						'hint'    => esc_html__( 'Only for tenant-related documents (tenancy agreement, handover protocol, statement).', 'mietfux' ),
					],
					'typ'             => [
						'label'   => esc_html__( 'Type', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'kaufvertrag'        => esc_html__( 'Purchase contract', 'mietfux' ),
							'grundbuch'          => esc_html__( 'Land register extract', 'mietfux' ),
							'teilungserklaerung' => esc_html__( 'Declaration of division / HOA', 'mietfux' ),
							'mietvertrag'        => esc_html__( 'Tenancy agreement', 'mietfux' ),
							'uebergabeprotokoll' => esc_html__( 'Handover protocol', 'mietfux' ),
							'abrechnung'         => esc_html__( 'Service charge statement', 'mietfux' ),
							'versicherung'       => esc_html__( 'Insurance policy', 'mietfux' ),
							'energieausweis'     => esc_html__( 'Energy certificate', 'mietfux' ),
							'sonstiges'          => esc_html__( 'Other', 'mietfux' ),
						],
					],
					'bezeichnung'     => [
						'label'    => esc_html__( 'Name', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'datum'           => [
						'label' => esc_html__( 'Date', 'mietfux' ),
						'type'  => 'date',
						'list'  => true,
					],
					'mieter_sichtbar' => [
						'label'   => esc_html__( 'Visible in tenant portal', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'0' => esc_html__( 'No', 'mietfux' ),
							'1' => esc_html__( 'Yes', 'mietfux' ),
						],
						'hint'    => esc_html__( 'Only effective with an assigned tenancy.', 'mietfux' ),
					],
					'attachment_id'   => [
						'label'    => esc_html__( 'File (PDF)', 'mietfux' ),
						'type'     => 'file',
						'required' => true,
						'list'     => true,
					],
					'notiz'           => [
						'label' => esc_html__( 'Note', 'mietfux' ),
						'type'  => 'textarea',
					],
				],
			],
			'darlehen_jahre'    => [
				'label'       => esc_html__( 'Interest certificates', 'mietfux' ),
				'singular'    => esc_html__( 'Annual loan figures', 'mietfux' ),
				'order'       => 'jahr DESC, darlehen_id ASC',
				'upload'      => true,
				'filter_year' => 'jahr',
				'fields'      => [
					'darlehen_id'   => [
						'label'    => esc_html__( 'Loans', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_darlehen' ],
					],
					'jahr'          => [
						'label'    => esc_html__( 'Year', 'mietfux' ),
						'type'     => 'int',
						'required' => true,
						'list'     => true,
					],
					'zinsen'        => [
						'label'    => esc_html__( 'Interest paid (€)', 'mietfux' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
						'hint'     => esc_html__( 'As per the bank\'s annual interest certificate – this is the deductible amount.', 'mietfux' ),
					],
					'tilgung'       => [
						'label' => esc_html__( 'Repayment (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => esc_html__( 'Not deductible, info only.', 'mietfux' ),
					],
					'restschuld'    => [
						'label' => esc_html__( 'Outstanding balance 31 Dec (€)', 'mietfux' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'attachment_id' => [
						'label' => esc_html__( 'Interest certificate (PDF)', 'mietfux' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'kostenarten'       => [
				'label'    => esc_html__( 'Cost types', 'mietfux' ),
				'singular' => esc_html__( 'Cost type', 'mietfux' ),
				'order'    => 'sortierung ASC, name ASC',
				'owner'    => true,
				'children' => [ 'belege', 'kostenart_id' ],
				'fields'   => [
					'name'         => [
						'label'    => esc_html__( 'Name', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'anlage_v'     => [
						'label'    => esc_html__( 'Anlage V – group', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ 'Mietv_DB', 'anlage_v_gruppen' ],
					],
					'umlagefaehig' => [
						'label'   => esc_html__( 'Recoverable (BetrKV)', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'0' => esc_html__( 'No – borne by the landlord', 'mietfux' ),
							'1' => esc_html__( 'Yes – included in the service charge statement', 'mietfux' ),
						],
					],
					'schluessel'   => [
						'label'   => esc_html__( 'Allocation key', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							''          => '–',
							'flaeche'   => esc_html__( 'Living area', 'mietfux' ),
							'personen'  => esc_html__( 'Persons', 'mietfux' ),
							'verbrauch' => esc_html__( 'Consumption (meter)', 'mietfux' ),
							'einheit'   => esc_html__( 'per unit', 'mietfux' ),
						],
					],
					'betrkv'       => [
						'label' => esc_html__( 'Legal basis', 'mietfux' ),
						'type'  => 'text',
						'hint'  => esc_html__( 'e.g. § 2 no. 1 BetrKV', 'mietfux' ),
					],
					'sortierung'   => [
						'label' => esc_html__( 'Sort order', 'mietfux' ),
						'type'  => 'int',
					],
				],
			],
			'meldungen'         => [
				'label'    => esc_html__( 'Reports', 'mietfux' ),
				'singular' => esc_html__( 'Report', 'mietfux' ),
				'order'    => 'datum DESC',
				'fields'   => [
					'mv_id'   => [
						'label'    => esc_html__( 'Tenancy', 'mietfux' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'datum'   => [
						'label'    => esc_html__( 'Date', 'mietfux' ),
						'type'     => 'datetime',
						'required' => true,
						'list'     => true,
					],
					'betreff' => [
						'label'    => esc_html__( 'Subject', 'mietfux' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'text'    => [
						'label' => esc_html__( 'Description', 'mietfux' ),
						'type'  => 'textarea',
					],
					'status'  => [
						'label'   => esc_html__( 'Status', 'mietfux' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'offen'          => esc_html__( 'Outstanding', 'mietfux' ),
							'in_bearbeitung' => esc_html__( 'In progress', 'mietfux' ),
							'erledigt'       => esc_html__( 'Done', 'mietfux' ),
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
		add_submenu_page( 'mietv_uebersicht', __( 'Overview', 'mietfux' ), '<span class="dashicons dashicons-dashboard"></span> ' . __( 'Overview', 'mietfux' ), self::CAP, 'mietv_uebersicht', [ __CLASS__, 'page_uebersicht' ] );
		// Gruppen (## = Überschrift) und Dashicons
		$reihenfolge = [
			'##' . esc_html__( 'Daily', 'mietfux' ),
			'zahlungen',
			'belege',
			'__bank',
			'meldungen',
			'dokumente',
			'##' . esc_html__( 'Administration', 'mietfux' ),
			'objekte',
			'einheiten',
			'mieter',
			'mietverhaeltnisse',
			'konditionen',
			'__mietvertrag',
			'__kaution',
			'__erhoehung',
			'__mahnung',
			'##' . esc_html__( 'Year end', 'mietfux' ),
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
			'__assistent'     => esc_html__( 'Wizard', 'mietfux' ),
			'__abrechnung'    => esc_html__( 'Statement', 'mietfux' ),
			'__einstellungen' => esc_html__( 'Settings', 'mietfux' ),
			'__mietvertrag'   => esc_html__( 'Tenancy agreement', 'mietfux' ),
			'darlehen_jahre'  => esc_html__( 'Interest certificate', 'mietfux' ),
			'bank_regeln'     => esc_html__( 'Bank rules', 'mietfux' ),
		];
		$ico         = fn( string $key, string $label ) => '<span class="dashicons dashicons-' . esc_attr( $icons[ $key ] ?? 'marker' ) . '"></span> ' . ( $kurz[ $key ] ?? $label );
		$seiten      = [
			'__mietvertrag'    => [ __( 'Agreement & handover', 'mietfux' ), [ 'Mietv_Mietvertrag', 'page' ] ],
			'__bank'           => [ esc_html__( 'Bank import', 'mietfux' ), [ 'Mietv_Bank', 'page' ] ],
			'__mahnung'        => [ esc_html__( 'Dunning', 'mietfux' ), [ 'Mietv_Mahnung', 'page' ] ],
			'__erhoehung'      => [ esc_html__( 'Rent increase', 'mietfux' ), [ 'Mietv_Erhoehung', 'page' ] ],
			'__kaution'        => [ esc_html__( 'Deposit', 'mietfux' ), [ 'Mietv_Kaution', 'page' ] ],
			'__darlehenskonto' => [ esc_html__( 'Loan account', 'mietfux' ), [ 'Mietv_Darlehen', 'page' ] ],
			'__abrechnung'     => [ esc_html__( 'Service charge statement', 'mietfux' ), [ 'Mietv_Abrechnung', 'page' ] ],
			'__steuer'         => [ esc_html__( 'Tax (Anlage V)', 'mietfux' ), [ 'Mietv_Steuer', 'page' ] ],
			'__datenschutz'    => [ esc_html__( 'Data protection', 'mietfux' ), [ 'Mietv_Datenschutz', 'page' ] ],
			'__einstellungen'  => [ __( 'Settings & backup', 'mietfux' ), [ 'Mietv_Einstellungen', 'page' ] ],
			'__hilfe'          => [ esc_html__( 'Help', 'mietfux' ), [ 'Mietv_Hilfe', 'page' ] ],
			'__assistent'      => [ esc_html__( 'Setup wizard', 'mietfux' ), [ 'Mietv_Assistent', 'page' ] ],
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
			check_admin_referer( 'mietv_save' );
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
			}
			$key = sanitize_key( $_POST['mietv_entity'] );
			$ent = self::entities()[ $key ] ?? null;
			if ( ! $ent ) {
				wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
			}

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
						$errors[] = sprintf( /* translators: %s: Feldname */ __( '%s is required.', 'mietfux' ), $f['label'] );
					}
					continue;
				}
				$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- typabhängig in self::sanitize() bereinigt.
				$val = self::sanitize( $f['type'], $raw );
				if ( ! empty( $f['required'] ) && ( $val === '' || $val === null ) ) {
					$errors[] = sprintf( /* translators: %s: Feldname */ __( '%s is required.', 'mietfux' ), $f['label'] );
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
				$errors[] = sprintf( /* translators: %s: Datenbankfehler */ __( 'Saving failed: %s', 'mietfux' ), $wpdb->last_error ?: __( 'unknown database error', 'mietfux' ) );
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
			mietv_flash_set( 'saved' );
			wp_safe_redirect( self::url( $key ) );
			exit;
		}

		// Löschen
		$key = self::current_entity();
		if ( $key && isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) ) {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
			}
			$id = ( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
			check_admin_referer( 'mietv_delete_' . $key . '_' . $id );
			$ent = self::entities()[ $key ];

			if ( ! empty( $ent['children'] ) ) {
				[ $ctable, $fk ] = $ent['children'];
				$cnt             = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mietv_table( $ctable ) . " WHERE $fk = %d", $id ) );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellen- und Spaltennamen stammen aus der internen Entitätsdefinition, nicht aus Nutzereingaben.
				if ( $cnt > 0 ) {
					mietv_flash_set( 'haschildren' );
					wp_safe_redirect( self::url( $key ) );
					exit;
				}
			}
			if ( $key === 'mietverhaeltnisse' ) {
				$wpdb->delete( mietv_table( 'mv_einheiten' ), [ 'mv_id' => $id ] );
				$wpdb->delete( mietv_table( 'konditionen' ), [ 'mv_id' => $id ] );
				$wpdb->delete( mietv_table( 'meldungen' ), [ 'mv_id' => $id ] );
			}
			$wpdb->delete( mietv_table( $key ), [ 'id' => $id ] );
			mietv_flash_set( 'deleted' );
			wp_safe_redirect( self::url( $key ) );
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
					$e[] = __( 'End of tenancy is before the start.', 'mietfux' );
				}
				if ( ! empty( $d['einheit_id'] ) && ! empty( $d['beginn'] ) ) {
					$ende      = $d['ende'] ?: '9999-12-31';
					$kollision = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_mietverhaeltnisse WHERE einheit_id = %d AND id <> %d AND beginn <= %s AND COALESCE(NULLIF(ende,'0000-00-00'),'9999-12-31') >= %s", $d['einheit_id'], $id, $ende, $d['beginn'] ) );
					if ( $kollision ) {
						$e[] = __( 'Another tenancy already exists for this unit in the period.', 'mietfux' );
					}
				}
				if ( isset( $d['zahltag'] ) && ( $d['zahltag'] < 1 || $d['zahltag'] > 28 ) ) {
					$e[] = __( 'Payment day must be between 1 and 28.', 'mietfux' );
				}
				foreach ( array_keys( $d['__zusatz'] ?? [] ) as $eid ) {
					if ( $eid === (int) ( $d['einheit_id'] ?? 0 ) ) {
						$e[] = __( 'The main unit cannot also be an additional unit.', 'mietfux' );
						continue;
					}
					$ende  = $d['ende'] ?: '9999-12-31';
					$koll  = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_mv_einheiten z JOIN ' . $wpdb->prefix . "mv_mietverhaeltnisse mv ON mv.id = z.mv_id WHERE z.einheit_id = %d AND mv.id <> %d AND mv.beginn <= %s AND COALESCE(NULLIF(mv.ende,'0000-00-00'),'9999-12-31') >= %s", $eid, $id, $ende, $d['beginn'] ?? '0000-00-00' ) );
					$koll += $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_mietverhaeltnisse WHERE einheit_id = %d AND id <> %d AND beginn <= %s AND COALESCE(NULLIF(ende,'0000-00-00'),'9999-12-31') >= %s", $eid, $id, $ende, $d['beginn'] ?? '0000-00-00' ) );
					if ( $koll ) {
						$e[] = sprintf( /* translators: %s: Einheit */ __( 'Additional unit “%s” is already let to someone else in the period.', 'mietfux' ), self::opt_einheiten()[ $eid ] ?? $eid );
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
					$e[] = __( 'Base rent must be greater than 0.', 'mietfux' );
				}
				break;
			case 'zahlungen':
				if ( isset( $d['betrag'] ) && abs( (float) $d['betrag'] ) < 0.005 ) {
					$e[] = __( 'Amount must not be 0.', 'mietfux' );
				}
				if ( ! empty( $d['datum'] ) && $d['datum'] > gmdate( 'Y-m-d', strtotime( $heute . ' +7 days' ) ) ) {
					$e[] = __( 'Payment date is in the future.', 'mietfux' );
				}
				if ( ! empty( $d['mv_id'] ) && ! empty( $d['monat'] ) ) {
					$mv = $wpdb->get_row( $wpdb->prepare( 'SELECT beginn FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $d['mv_id'] ) );
					if ( $mv && $d['monat'] < substr( $mv->beginn, 0, 7 ) . '-01' ) {
						$e[] = __( 'Assigned month is before the start of tenancy.', 'mietfux' );
					}
				}
				break;
			case 'belege':
				if ( ! empty( $d['zeitraum_von'] ) && ! empty( $d['zeitraum_bis'] ) && $d['zeitraum_bis'] < $d['zeitraum_von'] ) {
					$e[] = __( 'Service period: “to” is before “from”.', 'mietfux' );
				}
				if ( isset( $d['betrag'] ) && (float) $d['betrag'] <= 0 ) {
					$e[] = __( 'Amount must be greater than 0 (credit notes as negative receipts are not supported – please offset against the original receipt).', 'mietfux' );
				}
				if ( ! empty( $d['lohnanteil'] ) && ! empty( $d['betrag'] ) && (float) $d['lohnanteil'] > (float) $d['betrag'] ) {
					$e[] = __( 'Labour share cannot exceed the invoice amount.', 'mietfux' );
				}
				if ( ! empty( $d['steuerjahr'] ) && ! empty( $d['datum'] ) && abs( (int) $d['steuerjahr'] - (int) substr( $d['datum'], 0, 4 ) ) > 1 ) {
					$e[] = __( 'Tax year differs from the payment date by more than one year.', 'mietfux' );
				}
				break;
			case 'einheiten':
				if ( isset( $d['flaeche'] ) && (float) $d['flaeche'] <= 0 && in_array( $d['typ'] ?? '', [ 'wohnung', 'gewerbe' ], true ) ) {
					$e[] = __( 'Living area must be greater than 0 (needed for the service charge statement).', 'mietfux' );
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
					$e[] = __( 'Land share must be below 100 %.', 'mietfux' );
				}
				if ( ( $d['typ'] ?? '' ) === 'beweglich' && empty( $d['nutzungsdauer'] ) && (float) ( $d['satz'] ?? 0 ) <= 0 ) {
					$e[] = __( 'Movable asset: enter useful life or depreciation rate.', 'mietfux' );
				}
				break;
			case 'darlehen_jahre':
				if ( ! empty( $d['jahr'] ) && ( $d['jahr'] < 1990 || $d['jahr'] > (int) current_time( 'Y' ) ) ) {
					$e[] = __( 'Invalid year.', 'mietfux' );
				}
				break;
			case 'mieter':
				if ( ! empty( $d['iban'] ) ) {
					$iban = strtoupper( preg_replace( '/\s+/', '', $d['iban'] ) );
					if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) || ! self::iban_ok( $iban ) ) {
						$e[] = __( 'Invalid IBAN (check digit).', 'mietfux' );
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
		$flash = mietv_flash_get();
		$msg   = $flash['code'] ?? '';
		$map   = [
			'saved'       => [ 'success', esc_html__( 'Saved.', 'mietfux' ) ],
			'deleted'     => [ 'success', esc_html__( 'Deleted.', 'mietfux' ) ],
			'haschildren' => [ 'error', esc_html__( 'Cannot delete: dependent records still exist.', 'mietfux' ) ],
			'gebucht'     => [ 'success', sprintf( /* translators: %d: Anzahl */ __( '%d rent payment(s) booked.', 'mietfux' ), (int) ( $flash['n'] ?? 0 ) ) ],
		];
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
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
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">' . /* translators: %s: Wert */ esc_html__( 'Add %s', 'mietfux' ) . '</a><hr class="wp-header-end">',
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
			printf( '<option value="0"%s>' . esc_html__( 'all', 'mietfux' ) . '</option>', selected( $jahr, 0, false ) );
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
		echo '<th class="mv-actions">' . esc_html__( 'Actions', 'mietfux' ) . '</th></tr></thead><tbody>';

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
			printf( '<td class="mv-actions"><a href="%s">' . esc_html__( 'Edit', 'mietfux' ) . '</a> | <a href="%s" class="mv-delete" data-confirm="' . esc_attr__( 'Really delete?', 'mietfux' ) . '">' . esc_html__( 'Delete', 'mietfux' ) . '</a></td>', esc_url( $edit ), esc_url( $del ) );
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

		printf( '<h1>%s %s</h1>', $id ? esc_html__( 'Edit:', 'mietfux' ) : esc_html__( 'New:', 'mietfux' ), esc_html( $ent['singular'] ) );
		self::notices();

		echo '<form method="post" class="mv-form"' . ( ! empty( $ent['upload'] ) ? ' enctype="multipart/form-data"' : '' ) . '>';
		wp_nonce_field( 'mietv_save' );
		printf( '<input type="hidden" name="mietv_entity" value="%s"><input type="hidden" name="mietv_record_id" value="%d">', esc_attr( $key ), (int) $id );
		echo '<table class="form-table"><tbody>';

		foreach ( $ent['fields'] as $name => $f ) {
			$val = $row && property_exists( $row, $name ) ? $row->$name : '';
			$req = ! empty( $f['required'] ) ? ' required' : '';
			$lbl = esc_html( $f['label'] ) . ( ! empty( $f['required'] ) ? ' <span class="mv-req">*</span>' : '' );
			printf( '<tr><th scope="row"><label for="mietv_%s">%s</label></th><td>', esc_attr( $name ), wp_kses( $lbl, [ 'span' => [ 'class' => true ] ] ) );

			switch ( $f['type'] ) {
				case 'zusatz':
					$vorhanden = [];
					foreach ( $id ? Mietv_Soll::zusatzeinheiten( $id ) : [] as $z ) {
						$vorhanden[ $z->einheit_id ] = (float) $z->mietanteil;
					}
					$alle = $wpdb->get_results( 'SELECT e.id, e.bezeichnung, e.typ, o.bezeichnung AS objekt FROM ' . $wpdb->prefix . 'mv_einheiten e JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = e.objekt_id ORDER BY o.bezeichnung, e.typ, e.bezeichnung' );
					if ( ! $alle ) {
						echo '<p class="description">' . esc_html__( 'No units created yet.', 'mietfux' ) . '</p>';
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
							esc_html__( 'Rent share', 'mietfux' ),
							esc_attr( $on && $vorhanden[ $e->id ] > 0 ? number_format( $vorhanden[ $e->id ], 2, ',', '' ) : '' )
						);
					}
					echo '</table>';
					break;
				case 'file':
					if ( $val ) {
						$url = wp_get_attachment_url( (int) $val );
						if ( $url ) {
							printf( '<p><a href="%s" target="_blank">' . esc_html__( 'Open current file', 'mietfux' ) . '</a> ' . esc_html__( '– choose a new file to replace it.', 'mietfux' ) . '</p>', esc_url( $url ) );
						}
					}
					printf( '<input type="file" id="mietv_%1$s" name="%1$s" accept=".pdf,image/*"%2$s>', esc_attr( $name ), ! empty( $f['extract'] ) ? ' data-mv-extract="' . esc_attr( $f['extract'] ) . '"' : '' );
					if ( wp_is_mobile() ) {
						printf( ' <label class="button"><input type="file" name="%1$s" accept="image/*" capture="environment" style="display:none" onchange="document.getElementById(\'mietv_%1$s\').disabled=!!this.files.length">' . esc_html__( '📷 Take photo', 'mietfux' ) . '</label>', esc_attr( $name ) );
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
					printf( '<select id="mietv_%1$s" name="%1$s"%2$s>', esc_attr( $name ), esc_attr( $req ) );
					if ( ! empty( $f['required'] ) ) {
						echo '<option value="">' . esc_html__( '– please select –', 'mietfux' ) . '</option>';
					}
					foreach ( $options as $k => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $val, (string) $k, false ), esc_html( $label ) );
					}
					echo '</select>';
					break;
				case 'decimal':
					printf( '<input type="text" id="mietv_%1$s" name="%1$s" value="%2$s" class="regular-text mv-num" inputmode="decimal"%3$s>', esc_attr( $name ), esc_attr( $val !== '' && $val !== null ? number_format( (float) $val, 2, ',', '' ) : '' ), esc_attr( $req ) );
					break;
				case 'int':
					printf( '<input type="number" id="mietv_%1$s" name="%1$s" value="%2$s" class="small-text"%3$s>', esc_attr( $name ), esc_attr( (string) $val ), esc_attr( $req ) );
					break;
				case 'date':
					printf( '<input type="date" id="mietv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val && $val !== '0000-00-00' ? $val : '' ), esc_attr( $req ) );
					break;
				case 'month':
					printf( '<input type="month" id="mietv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val ? substr( $val, 0, 7 ) : current_time( 'Y-m' ) ), esc_attr( $req ) );
					break;
				case 'datetime':
					printf( '<input type="datetime-local" id="mietv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val ? gmdate( 'Y-m-d\TH:i', strtotime( $val ) ) : current_time( 'Y-m-d\TH:i' ) ), esc_attr( $req ) );
					break;
				default:
					printf( '<input type="%4$s" id="mietv_%1$s" name="%1$s" value="%2$s" class="regular-text"%3$s>', esc_attr( $name ), esc_attr( (string) $val ), esc_attr( $req ), esc_attr( $f['type'] === 'email' ? 'email' : 'text' ) );
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
		printf( '<a href="%s" class="button">' . esc_html__( 'Cancel', 'mietfux' ) . '</a>', esc_url( self::url( $key ) ) );
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
			[ 'admin-home', __( 'Properties / units', 'mietfux' ), $objekte . ' / ' . $einheiten, count( $mvs ) . ' ' . __( 'active tenancies', 'mietfux' ), self::url( 'objekte' ), '' ],
			[ 'money-alt', __( 'Monthly rent due', 'mietfux' ), mietv_eur( $soll_monat ), __( 'Base rent + advance payments', 'mietfux' ), self::url( 'konditionen' ), '' ],
			[ 'warning', __( 'Open rent claims', 'mietfux' ), mietv_eur( $rueckstand ), $rueckstand > 0.005 ? __( 'Arrears – tick below or send a reminder', 'mietfux' ) : __( 'All rent accounts balanced', 'mietfux' ), admin_url( 'admin.php?page=mietv_mahnung' ), $rueckstand > 0.005 ? 'mv-kpi-neg' : 'mv-kpi-pos' ],
			[ 'chart-pie', sprintf( /* translators: %d: Jahr */ __( 'Income %d', 'mietfux' ), $jahr ), mietv_eur( $einnahmen ), sprintf( /* translators: %s: Betrag */ __( 'Expenses so far %s', 'mietfux' ), mietv_eur( $kosten ) ), admin_url( 'admin.php?page=mietv_steuer&jahr=' . $jahr ), '' ],
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
			[ 'money-alt', __( 'Record payment', 'mietfux' ), self::url( 'zahlungen', [ 'action' => 'new' ] ) ],
			[ 'clipboard', __( 'Record receipt', 'mietfux' ), self::url( 'belege', [ 'action' => 'new' ] ) ],
			[ 'bank', __( 'Bank import', 'mietfux' ), admin_url( 'admin.php?page=mietv_bank' ) ],
			[ 'editor-ol', __( 'Meter reading', 'mietfux' ), self::url( 'zaehlerstaende', [ 'action' => 'new' ] ) ],
			[ 'calculator', __( 'Statement', 'mietfux' ), admin_url( 'admin.php?page=mietv_abrechnung' ) ],
			[ 'media-document', __( 'Tenancy agreement', 'mietfux' ), admin_url( 'admin.php?page=mietv_mietvertrag' ) ],
		];
		echo '<div class="mv-quick">';
		foreach ( $schnell as [ $icon, $label, $url ] ) {
			printf( '<a class="mv-quick-btn" href="%s"><span class="dashicons dashicons-%s"></span>%s</a>', esc_url( $url ), esc_attr( $icon ), esc_html( $label ) );
		}
		echo '</div>';

		if ( $hinweise || $offene_meldungen ) {
			echo '<section class="mv-card mv-hinweise"><header class="mv-card-head"><h2>' . esc_html__( 'Notices', 'mietfux' ) . '</h2></header><ul>';
			if ( $offene_meldungen ) {
				printf( '<li class="warn"><span class="dashicons dashicons-warning"></span><a href="%s">' . /* translators: %d: Anzahl */ esc_html__( '%d open report(s) from the tenant portal', 'mietfux' ) . '</a></li>', esc_url( self::url( 'meldungen' ) ), (int) $offene_meldungen );
			}
			foreach ( array_slice( $hinweise, 0, 8 ) as $h ) {
				printf( '<li class="%s"><span class="dashicons dashicons-%s"></span><a href="%s">%s</a></li>', esc_attr( $h['typ'] ), esc_attr( $h['typ'] === 'error' ? 'dismiss' : ( $h['typ'] === 'warn' ? 'warning' : 'info' ) ), esc_url( $h['url'] ), esc_html( $h['text'] ) );
			}
			echo '</ul></section>';
		}
	}

	public static function page_uebersicht(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Mietfux – Overview', 'mietfux' ) . '</h1> ';
		printf( '<a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">', esc_url( self::fokus_url() ), self::fokus_aktiv() ? esc_html__( 'Show WordPress menu', 'mietfux' ) : esc_html__( 'Hide WordPress menu', 'mietfux' ) );
		self::notices();

		$mvs = Mietv_Soll::alle_mv( true );
		if ( ! $mvs ) {
			echo '<p>' . esc_html__( 'No active tenancy yet. Order to get started:', 'mietfux' ) . ' <strong>' . esc_html__( 'Property → Unit → Tenant → Tenancy → Rent conditions', 'mietfux' ) . '</strong> – ' . sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=mietv_assistent' ) ), esc_html__( 'or use the setup wizard', 'mietfux' ) ) . '.</p></div>';
			return;
		}

		$offene = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_meldungen WHERE status <> 'erledigt'" );

		self::dashboard( $mvs, $offene );

		echo '<form method="post" id="mv-op-form">';
		wp_nonce_field( 'mietv_op_buchen', 'mietv_op_nonce' );
		echo '<p class="description mv-op-hinweis">' . esc_html__( 'Tick open months and book them as paid with one click – the open amount is booked with payment date 1st of the month (source “ticked”, editable later under Payments).', 'mietfux' ) . ' <button class="button button-primary" data-require-checked="input[name=\'op[]\']" data-require-msg="' . esc_attr__( 'Please tick at least one month.', 'mietfux' ) . '">' . esc_html__( 'Book selected as paid', 'mietfux' ) . '</button></p>';
		$offen_gesamt = 0;
		foreach ( $mvs as $mv ) {
			$op          = Mietv_Soll::op_liste( $mv );
			$saldo_class = $op['saldo'] < -0.005 ? 'mv-neg' : ( $op['saldo'] > 0.005 ? 'mv-pos' : '' );

			$meta = sprintf( /* translators: %1$s: Datum, %2$s: Betrag */ __( 'Start %1$s · Deposit %2$s', 'mietfux' ), mietv_date( $mv->beginn ), mietv_eur( $mv->kaution ) );
			printf(
				'<section class="mv-card mv-mvcard"><header class="mv-card-head"><div><h2>%s</h2><p class="mv-muted">%s</p></div><div class="mv-card-tools"><span class="mv-badge %s">' . esc_html__( 'Balance', 'mietfux' ) . ' %s</span> <a href="%s" class="button">' . esc_html__( 'Record payment', 'mietfux' ) . '</a> <a href="%s" class="button">' . esc_html__( 'Tenancy agreement', 'mietfux' ) . '</a></div></header>',
				esc_html( Mietv_Soll::mietv_label( $mv ) ),
				esc_html( $meta ),
				esc_attr( $saldo_class ?: 'mv-neutral' ),
				esc_html( mietv_eur( $op['saldo'] ) ),
				esc_url( self::url( 'zahlungen', [ 'action' => 'new' ] ) ),
				esc_url( admin_url( 'admin.php?page=mietv_mietvertrag&mv_id=' . (int) $mv->id ) )
			);

			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" title="' . esc_attr__( 'all open months of this tenancy', 'mietfux' ) . '" data-check-all="tbody input[type=checkbox]"></th><th>' . esc_html__( 'Month', 'mietfux' ) . '</th><th>' . esc_html__( 'Base rent', 'mietfux' ) . '</th><th>' . esc_html__( 'SC advance', 'mietfux' ) . '</th><th>' . esc_html__( 'Heating advance', 'mietfux' ) . '</th><th>' . esc_html__( 'Due', 'mietfux' ) . '</th><th>' . esc_html__( 'Actual', 'mietfux' ) . '</th><th>' . esc_html__( 'Difference', 'mietfux' ) . '</th><th>' . esc_html__( 'Cumulative balance', 'mietfux' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $op['rows'] ) as $r ) {
				$cls   = $r['diff'] < -0.005 ? 'mv-neg' : ( $r['diff'] > 0.005 ? 'mv-pos' : '' );
				$offen = $r['diff'] < -0.005 && ! $r['ohne_konditionen'];
				if ( $offen ) {
					++$offen_gesamt;
				}
				printf(
					'<tr><td class="mv-op-check">%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td class="%s">%s</td><td class="%s">%s</td></tr>',
					$offen ? '<input type="checkbox" name="op[]" value="' . esc_attr( $mv->id . '|' . $r['monat'] ) . '" title="' . esc_attr( sprintf( /* translators: %s: Wert */ __( 'Book %s as paid', 'mietfux' ), mietv_eur( -$r['diff'] ) ) ) . '">' : ( $r['diff'] < -0.005 ? '' : '✓' ),
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
				'</tbody><tfoot><tr><th></th><th>' . esc_html__( 'Total', 'mietfux' ) . '</th><th></th><th></th><th></th><th>%s</th><th>%s</th><th class="%s">%s</th><th></th></tr></tfoot></table>',
				esc_html( mietv_eur( $op['soll'] ) ),
				esc_html( mietv_eur( $op['ist'] ) ),
				esc_attr( $saldo_class ),
				esc_html( mietv_eur( $op['saldo'] ) )
			);
			echo '</section>';
		}
		if ( $offen_gesamt ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Book selected as paid', 'mietfux' ) . '</button></p>';
		}
		echo '</form></div>';
	}
}
