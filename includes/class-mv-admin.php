<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend: Menü, generisches CRUD für alle Entitäten, Offene-Posten-Übersicht.
 */
class MV_Admin {

	const CAP = 'mv_verwalten';

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
		add_action( 'admin_head', [ __CLASS__, 'fokus_css' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Offene Posten abhaken: offenen Betrag als Mieteingang zum 1. buchen  */
	/* ------------------------------------------------------------------ */

	public static function handle_op_buchen(): void {
		if ( empty( $_POST['mv_op_nonce'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['mv_op_nonce'], 'mv_op_buchen' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$n   = 0;
		$mvs = [];
		foreach ( MV_Soll::alle_mv() as $mv ) {
			$mvs[ $mv->id ] = $mv;
		}
		foreach ( (array) ( $_POST['op'] ?? [] ) as $key ) {
			[ $mv_id, $monat ] = array_pad( explode( '|', sanitize_text_field( $key ) ), 2, '' );
			$mv_id             = (int) $mv_id;
			if ( ! isset( $mvs[ $mv_id ] ) || ! preg_match( '/^\d{4}-\d{2}-01$/', $monat ) ) {
				continue;
			}
			$k = MV_Soll::konditionen( $mv_id, $monat );
			if ( ! $k ) {
				continue;
			}
			$soll  = (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz;
			$offen = round( $soll - MV_Soll::ist( $mv_id, $monat ), 2 );
			if ( $offen <= 0.005 ) {
				continue; // schon bezahlt
			}
			$wpdb->insert(
				mv_table( 'zahlungen' ),
				[
					'mv_id'            => $mv_id,
					'datum'            => $monat,
					'betrag'           => $offen,
					'monat'            => $monat,
					'verwendungszweck' => sprintf( /* translators: %s: Monat */ __( 'Miete %s', 'mietverwaltung' ), mv_monat( $monat ) ),
					'quelle'           => 'abgehakt',
				]
			);
			++$n;
		}
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => 'mv_uebersicht',
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
		return (bool) get_user_meta( get_current_user_id(), 'mv_fokus', true );
	}

	public static function fokus_url(): string {
		$ziel = self::fokus_aktiv() ? 'aus' : 'an';
		return wp_nonce_url( admin_url( 'admin.php?page=mv_uebersicht&mv_fokus=' . $ziel ), 'mv_fokus' );
	}

	public static function handle_fokus(): void {
		if ( empty( $_GET['mv_fokus'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'mv_fokus' );
		update_user_meta( get_current_user_id(), 'mv_fokus', $_GET['mv_fokus'] === 'an' ? 1 : 0 );
		wp_safe_redirect( admin_url( 'admin.php?page=mv_uebersicht' ) );
		exit;
	}

	/** Alle fremden Menüpunkte entfernen; Benutzer/Mediathek bleiben (werden für Portalzugang und Belege gebraucht). */
	public static function fokus_menu(): void {
		if ( ! self::fokus_aktiv() ) {
			return;
		}
		global $menu;
		$behalten = [ 'mv_uebersicht', 'users.php', 'upload.php', 'profile.php' ];
		foreach ( (array) $menu as $pos => $item ) {
			if ( ! in_array( $item[2], $behalten, true ) ) {
				remove_menu_page( $item[2] );
			}
		}
		// Dashboard-Aufruf direkt zur Mietverwaltung lenken
		add_action(
			'load-index.php',
			function () {
				wp_safe_redirect( admin_url( 'admin.php?page=mv_uebersicht' ) );
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
				'title' => self::fokus_aktiv() ? __( '⌂ WordPress-Menü einblenden', 'mietverwaltung' ) : __( '▣ Nur Mietverwaltung anzeigen', 'mietverwaltung' ),
				'href'  => self::fokus_url(),
				'meta'  => [ 'title' => __( 'Fokusmodus der Mietverwaltung umschalten', 'mietverwaltung' ) ],
			]
		);
	}

	public static function fokus_css(): void {
		if ( ! self::fokus_aktiv() ) {
			return;
		}
		echo '<style>#wp-admin-bar-mv-fokus > ' . 'a { background: #2271b1 !important; color: #fff !important; } #wp-admin-bar-new-content, #wp-admin-bar-comments, #wp-admin-bar-updates, #wp-admin-bar-wp-logo { display: none !important; }' . '</style>';
	}

	public static function assets( string $hook ): void {
		wp_enqueue_style( 'mv-menu', MV_URL . 'assets/menu.css', [ 'dashicons' ], MV_VERSION );
		if ( strpos( $hook, 'mv_' ) === false ) {
			return;
		}
		wp_enqueue_style( 'mv-admin', MV_URL . 'assets/admin.css', [], MV_VERSION );
		if ( strpos( $hook, 'mv_anlagegueter' ) !== false && ! empty( $_GET['action'] ) ) {
			wp_enqueue_script( 'mv-pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', [], '3.11.174', true );
			wp_enqueue_script( 'mv-kaufvertrag', MV_URL . 'assets/kaufvertrag.js', [ 'mv-pdfjs' ], MV_VERSION, true );
			wp_add_inline_script( 'mv-kaufvertrag', 'window.mvPdfWorker="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";', 'before' );
			wp_localize_script(
				'mv-kaufvertrag',
				'mvI18n',
				[
					'kein_text'    => __( 'Kein Textlayer gefunden – das PDF ist vermutlich ein Scan. Bitte Werte manuell eintragen (oder das PDF vorher per OCR durchsuchbar machen).', 'mietverwaltung' ),
					'kein_pdfjs'   => __( 'pdf.js konnte nicht geladen werden (Netzwerk/Adblocker?). Werte bitte manuell eintragen.', 'mietverwaltung' ),
					'lese'         => __( 'Lese Kaufvertrag …', 'mietverwaltung' ),
					'fehler'       => __( 'PDF konnte nicht gelesen werden:', 'mietverwaltung' ),
					'nur_pdf'      => __( 'Auslesen nur bei PDF möglich.', 'mietverwaltung' ),
					'erkannt'      => __( 'Aus dem Kaufvertrag erkannt', 'mietverwaltung' ),
					'seiten'       => __( 'Seiten', 'mietverwaltung' ),
					'uebernehmen'  => __( 'zum Übernehmen anklicken, danach prüfen:', 'mietverwaltung' ),
					'nicht'        => __( 'nicht gefunden', 'mietverwaltung' ),
					'kaufpreis'    => __( 'Kaufpreis', 'mietverwaltung' ),
					'uebergang'    => __( 'Übergang Nutzen/Lasten', 'mietverwaltung' ),
					'grund_betrag' => __( 'Grund und Boden (Betrag)', 'mietverwaltung' ),
					'grund_anteil' => __( 'Anteil Grund und Boden', 'mietverwaltung' ),
					'vertrag'      => __( 'Vertragsdatum (Info)', 'mietverwaltung' ),
					'flaeche'      => __( 'Wohnfläche (Info)', 'mietverwaltung' ),
					'hinweis'      => __( 'Erkennung ist heuristisch. Fehlt die Aufteilung Grund/Gebäude im Vertrag, hilft die BMF-Arbeitshilfe zur Kaufpreisaufteilung.', 'mietverwaltung' ),
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
				'label'    => __( 'Objekte', 'mietverwaltung' ),
				'singular' => __( 'Objekt', 'mietverwaltung' ),
				'order'    => 'bezeichnung ASC',
				'owner'    => true,
				'children' => [ 'einheiten', 'objekt_id' ],
				'fields'   => [
					'bezeichnung'         => [
						'label'    => __( 'Bezeichnung', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'strasse'             => [
						'label' => __( 'Straße / Nr.', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'plz'                 => [
						'label' => __( 'PLZ', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'ort'                 => [
						'label' => __( 'Ort', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'baujahr'             => [
						'label' => __( 'Baujahr', 'mietverwaltung' ),
						'type'  => 'int',
					],
					'heizung'             => [
						'label'   => __( 'Heizung', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'zentral'    => __( 'Zentralheizung (HeizkostenV, verbrauchsabhängig)', 'mietverwaltung' ),
							'fernwaerme' => __( 'Fernwärme (HeizkostenV)', 'mietverwaltung' ),
							'dezentral'  => __( 'Dezentral / Etagenheizung (Mieter rechnet selbst ab)', 'mietverwaltung' ),
						],
					],
					'hk_verbrauchsanteil' => [
						'label' => __( 'Heizkosten: Verbrauchsanteil (%)', 'mietverwaltung' ),
						'type'  => 'int',
						'hint'  => __( '50–70 % nach Verbrauch, Rest nach Wohnfläche (§ 7 HeizkostenV). Nur bei Zentralheizung/Fernwärme.', 'mietverwaltung' ),
					],
					'notiz'               => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
			],
			'einheiten'         => [
				'label'    => __( 'Einheiten', 'mietverwaltung' ),
				'singular' => __( 'Einheit', 'mietverwaltung' ),
				'order'    => 'objekt_id ASC, bezeichnung ASC',
				'children' => [ 'mietverhaeltnisse', 'einheit_id' ],
				'fields'   => [
					'objekt_id'   => [
						'label'    => __( 'Objekt', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bezeichnung' => [
						'label'    => __( 'Bezeichnung', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'z. B. „Wohnung EG links“', 'mietverwaltung' ),
					],
					'lage'        => [
						'label' => __( 'Lage', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
						'hint'  => __( 'z. B. „1. OG“', 'mietverwaltung' ),
					],
					'flaeche'     => [
						'label' => __( 'Wohnfläche (m²)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'zimmer'      => [
						'label' => __( 'Zimmer', 'mietverwaltung' ),
						'type'  => 'decimal',
					],
					'typ'         => [
						'label'   => __( 'Typ', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'wohnung'    => __( 'Wohnung', 'mietverwaltung' ),
							'gewerbe'    => __( 'Gewerbe', 'mietverwaltung' ),
							'stellplatz' => __( 'Stellplatz', 'mietverwaltung' ),
							'garage'     => __( 'Garage', 'mietverwaltung' ),
							'keller'     => __( 'Keller / Lager', 'mietverwaltung' ),
						],
					],
				],
			],
			'mieter'            => [
				'label'    => __( 'Mieter', 'mietverwaltung' ),
				'singular' => __( 'Mieter', 'mietverwaltung' ),
				'order'    => 'nachname ASC, vorname ASC',
				'owner'    => true,
				'children' => [ 'mietverhaeltnisse', 'mieter_id' ],
				'fields'   => [
					'anrede'         => [
						'label'   => __( 'Anrede', 'mietverwaltung' ),
						'type'    => 'select',
						'options' => [
							''      => '–',
							'Frau'  => __( 'Frau', 'mietverwaltung' ),
							'Herr'  => __( 'Herr', 'mietverwaltung' ),
							'Firma' => __( 'Firma', 'mietverwaltung' ),
						],
					],
					'vorname'        => [
						'label' => __( 'Vorname', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'nachname'       => [
						'label'    => __( 'Nachname', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'email'          => [
						'label' => __( 'E-Mail', 'mietverwaltung' ),
						'type'  => 'email',
						'list'  => true,
					],
					'telefon'        => [
						'label' => __( 'Telefon', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'geburtsdatum'   => [
						'label' => __( 'Geburtsdatum', 'mietverwaltung' ),
						'type'  => 'date',
						'hint'  => __( 'Für den Mietvertrag (Identifizierung der Vertragspartei).', 'mietverwaltung' ),
					],
					'anschrift_alt'  => [
						'label' => __( 'Bisherige Anschrift', 'mietverwaltung' ),
						'type'  => 'text',
						'hint'  => __( 'Anschrift bei Vertragsschluss (vor Einzug) – erscheint im Vertrag.', 'mietverwaltung' ),
					],
					'p_anrede'       => [
						'label'   => __( 'Mitmieter/in – Anrede', 'mietverwaltung' ),
						'type'    => 'select',
						'options' => [
							''     => '–',
							'Frau' => __( 'Frau', 'mietverwaltung' ),
							'Herr' => __( 'Herr', 'mietverwaltung' ),
						],
						'hint'    => __( 'Ehe-/Lebenspartner als zweite Vertragspartei (optional). Erscheint automatisch in Mietvertrag, Schreiben, SEPA-Mandat und Übergabeprotokoll; haftet gesamtschuldnerisch.', 'mietverwaltung' ),
					],
					'p_vorname'      => [
						'label' => __( 'Mitmieter/in – Vorname', 'mietverwaltung' ),
						'type'  => 'text',
					],
					'p_nachname'     => [
						'label' => __( 'Mitmieter/in – Nachname', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'p_geburtsdatum' => [
						'label' => __( 'Mitmieter/in – Geburtsdatum', 'mietverwaltung' ),
						'type'  => 'date',
					],
					'p_email'        => [
						'label' => __( 'Mitmieter/in – E-Mail', 'mietverwaltung' ),
						'type'  => 'email',
						'hint'  => __( 'Erhält Mahnungen und Schreiben in Kopie, wenn angegeben.', 'mietverwaltung' ),
					],
					'iban'           => [
						'label' => __( 'IBAN (für Bankimport-Zuordnung)', 'mietverwaltung' ),
						'type'  => 'text',
					],
					'wp_user_id'     => [
						'label'   => __( 'Portalzugang (WP-Benutzer)', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_users' ],
						'hint'    => __( 'Benutzer anlegen unter „Benutzer“, Rolle „Abonnent“ genügt.', 'mietverwaltung' ),
					],
					'notiz'          => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
			],
			'mietverhaeltnisse' => [
				'label'      => __( 'Mietverhältnisse', 'mietverwaltung' ),
				'singular'   => __( 'Mietverhältnis', 'mietverwaltung' ),
				'order'      => 'beginn DESC',
				'children'   => [ 'zahlungen', 'mv_id' ],
				'fields'     => [
					'einheit_id'      => [
						'label'    => __( 'Einheit', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_einheiten' ],
					],
					'mieter_id'       => [
						'label'    => __( 'Mieter', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mieter' ],
					],
					'beginn'          => [
						'label'    => __( 'Mietbeginn', 'mietverwaltung' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
					],
					'ende'            => [
						'label' => __( 'Mietende', 'mietverwaltung' ),
						'type'  => 'date',
						'list'  => true,
						'hint'  => __( 'leer = unbefristet', 'mietverwaltung' ),
					],
					'kaution'         => [
						'label' => __( 'Kaution (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'personen'        => [
						'label' => __( 'Personen im Haushalt', 'mietverwaltung' ),
						'type'  => 'int',
						'hint'  => __( 'für Umlageschlüssel nach Personen', 'mietverwaltung' ),
					],
					'zahltag'         => [
						'label' => __( 'Zahltag (Tag im Monat)', 'mietverwaltung' ),
						'type'  => 'int',
					],
					'zusatzeinheiten' => [
						'label' => __( 'Weitere Einheiten im selben Vertrag', 'mietverwaltung' ),
						'type'  => 'zusatz',
						'list'  => true,
						'hint'  => __( 'Garage, Stellplatz, Keller, die zusammen mit der Wohnung vermietet sind. Der Mietanteil ist in der Kaltmiete der Konditionen bereits enthalten und dient nur der Ausweisung im Vertrag.', 'mietverwaltung' ),
					],
					'notiz'           => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
				'after_form' => __( 'Konditionen (Kaltmiete, Vorauszahlungen) werden unter „Konditionen“ mit Gültigkeitsdatum erfasst – als Gesamtmiete inkl. Garage/Stellplatz. Jede Erhöhung = neuer Eintrag.', 'mietverwaltung' ),
			],
			'konditionen'       => [
				'label'    => __( 'Konditionen', 'mietverwaltung' ),
				'singular' => __( 'Kondition', 'mietverwaltung' ),
				'order'    => 'mv_id ASC, gueltig_ab DESC',
				'fields'   => [
					'mv_id'      => [
						'label'    => __( 'Mietverhältnis', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'gueltig_ab' => [
						'label'    => __( 'Gültig ab', 'mietverwaltung' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'In der Regel der 1. eines Monats', 'mietverwaltung' ),
					],
					'kaltmiete'  => [
						'label'    => __( 'Kaltmiete (€)', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'bk_vz'      => [
						'label' => __( 'Betriebskosten-VZ (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'hk_vz'      => [
						'label' => __( 'Heizkosten-VZ (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
					],
				],
			],
			'zahlungen'         => [
				'label'    => __( 'Zahlungen', 'mietverwaltung' ),
				'singular' => __( 'Zahlung', 'mietverwaltung' ),
				'order'    => 'datum DESC, id DESC',
				'fields'   => [
					'mv_id'            => [
						'label'    => __( 'Mietverhältnis', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'datum'            => [
						'label'    => __( 'Zahlungsdatum', 'mietverwaltung' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
					],
					'betrag'           => [
						'label'    => __( 'Betrag (€)', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'monat'            => [
						'label'    => __( 'Zuordnung Monat', 'mietverwaltung' ),
						'type'     => 'month',
						'required' => true,
						'list'     => true,
					],
					'verwendungszweck' => [
						'label' => __( 'Verwendungszweck', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'quelle'           => [
						'label'   => __( 'Quelle', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'manuell'  => __( 'manuell', 'mietverwaltung' ),
							'bank'     => __( 'Bankimport', 'mietverwaltung' ),
							'abgehakt' => __( 'Übersicht (abgehakt)', 'mietverwaltung' ),
						],
					],
				],
			],
			'belege'            => [
				'label'       => __( 'Belege', 'mietverwaltung' ),
				'singular'    => __( 'Beleg', 'mietverwaltung' ),
				'order'       => 'datum DESC, id DESC',
				'owner'       => true,
				'upload'      => true,
				'filter_year' => 'steuerjahr',
				'fields'      => [
					'objekt_id'        => [
						'label'    => __( 'Objekt', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'kostenart_id'     => [
						'label'    => __( 'Kostenart', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_kostenarten' ],
					],
					'datum'            => [
						'label'    => __( 'Zahlungsdatum', 'mietverwaltung' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'Abflussdatum – maßgeblich für das Steuerjahr (§ 11 EStG). Bei Lastschrift: Tag der Abbuchung.', 'mietverwaltung' ),
					],
					'steuerjahr'       => [
						'label' => __( 'Steuerjahr', 'mietverwaltung' ),
						'type'  => 'int',
						'list'  => true,
						'hint'  => __( 'leer = Jahr des Zahlungsdatums. Abweichend nur bei regelmäßig wiederkehrenden Zahlungen um den Jahreswechsel (10-Tage-Regel).', 'mietverwaltung' ),
					],
					'betrag'           => [
						'label'    => __( 'Betrag brutto (€)', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'lieferant'        => [
						'label' => __( 'Rechnungssteller', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'belegnr'          => [
						'label' => __( 'Rechnungs-/Belegnummer', 'mietverwaltung' ),
						'type'  => 'text',
					],
					'zeitraum_von'     => [
						'label' => __( 'Leistungszeitraum von', 'mietverwaltung' ),
						'type'  => 'date',
						'hint'  => __( 'Für die Betriebskostenabrechnung (z. B. Versicherung 01.01.–31.12.). Leer = Zahlungsdatum.', 'mietverwaltung' ),
					],
					'zeitraum_bis'     => [
						'label' => __( 'Leistungszeitraum bis', 'mietverwaltung' ),
						'type'  => 'date',
					],
					'verteilung_jahre' => [
						'label'   => __( 'Verteilung (§ 82b EStDV)', 'mietverwaltung' ),
						'type'    => 'select',
						'options' => [
							'1' => __( 'nein – voll im Zahlungsjahr', 'mietverwaltung' ),
							'2' => __( 'auf 2 Jahre', 'mietverwaltung' ),
							'3' => __( 'auf 3 Jahre', 'mietverwaltung' ),
							'4' => __( 'auf 4 Jahre', 'mietverwaltung' ),
							'5' => __( 'auf 5 Jahre', 'mietverwaltung' ),
						],
						'hint'    => __( 'Nur für größeren Erhaltungsaufwand an Wohngebäuden. Gleichmäßige Verteilung ab dem Steuerjahr.', 'mietverwaltung' ),
					],
					'par35a'           => [
						'label'   => __( '§ 35a EStG (für Mieter)', 'mietverwaltung' ),
						'type'    => 'select',
						'options' => [
							''           => __( '– nein –', 'mietverwaltung' ),
							'handwerker' => __( 'Handwerkerleistung (20 %, max. 1.200 €)', 'mietverwaltung' ),
							'haushalt'   => __( 'Haushaltsnahe Dienstleistung (20 %, max. 4.000 €)', 'mietverwaltung' ),
						],
						'hint'    => __( 'Hausmeister, Gartenpflege, Schornsteinfeger, Wartung Heizung … Der Lohnanteil wird in der Betriebskostenabrechnung für den Mieter ausgewiesen.', 'mietverwaltung' ),
					],
					'lohnanteil'       => [
						'label' => __( 'davon Lohn-/Arbeitskosten brutto (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'hint'  => __( 'Laut Rechnung; ohne Material.', 'mietverwaltung' ),
					],
					'beschreibung'     => [
						'label' => __( 'Beschreibung', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
					'attachment_id'    => [
						'label' => __( 'Belegdatei (PDF/Foto)', 'mietverwaltung' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'zaehler'           => [
				'label'    => __( 'Zähler', 'mietverwaltung' ),
				'singular' => __( 'Zähler', 'mietverwaltung' ),
				'order'    => 'objekt_id ASC, einheit_id ASC, typ ASC',
				'children' => [ 'zaehlerstaende', 'zaehler_id' ],
				'fields'   => [
					'objekt_id'  => [
						'label'    => __( 'Objekt', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'einheit_id' => [
						'label'   => __( 'Einheit', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_einheiten_optional' ],
						'hint'    => __( 'Leer = Hauptzähler des Hauses (nur zur Info, nicht für die Verteilung).', 'mietverwaltung' ),
					],
					'typ'        => [
						'label'    => __( 'Art', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [
							'kaltwasser' => __( 'Kaltwasser (m³)', 'mietverwaltung' ),
							'warmwasser' => __( 'Warmwasser (m³)', 'mietverwaltung' ),
							'heizung'    => __( 'Heizung (kWh / Einheiten)', 'mietverwaltung' ),
							'strom'      => __( 'Strom (kWh)', 'mietverwaltung' ),
						],
					],
					'nummer'     => [
						'label' => __( 'Zählernummer', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'notiz'      => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
			],
			'zaehlerstaende'    => [
				'label'    => __( 'Zählerstände', 'mietverwaltung' ),
				'singular' => __( 'Zählerstand', 'mietverwaltung' ),
				'order'    => 'datum DESC, zaehler_id ASC',
				'upload'   => true,
				'fields'   => [
					'zaehler_id'    => [
						'label'    => __( 'Zähler', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_zaehler' ],
					],
					'datum'         => [
						'label'    => __( 'Ablesedatum', 'mietverwaltung' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'Für die Abrechnung: Stand zum Beginn und zum Ende des Zeitraums (z. B. 01.01. und 31.12.).', 'mietverwaltung' ),
					],
					'stand'         => [
						'label'    => __( 'Zählerstand', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'quelle'        => [
						'label'   => __( 'Quelle', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'vermieter' => __( 'Vermieter/Ablesung', 'mietverwaltung' ),
							'mieter'    => __( 'Mieter (Portal)', 'mietverwaltung' ),
							'versorger' => __( 'Versorger/Ablesedienst', 'mietverwaltung' ),
						],
					],
					'attachment_id' => [
						'label' => __( 'Foto', 'mietverwaltung' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'bank_regeln'       => [
				'label'    => __( 'Bankimport-Regeln', 'mietverwaltung' ),
				'singular' => __( 'Regel', 'mietverwaltung' ),
				'order'    => 'muster ASC',
				'owner'    => true,
				'fields'   => [
					'muster'       => [
						'label'    => __( 'Suchtext', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'Wird in Name und Verwendungszweck gesucht (Groß-/Kleinschreibung egal), z. B. „Stadtwerke“ oder „N-ERGIE“.', 'mietverwaltung' ),
					],
					'typ'          => [
						'label'   => __( 'Anlegen als', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'beleg'      => __( 'Beleg (Ausgabe)', 'mietverwaltung' ),
							'zahlung'    => __( 'Mietzahlung (Eingang)', 'mietverwaltung' ),
							'ignorieren' => __( 'Ignorieren', 'mietverwaltung' ),
						],
					],
					'objekt_id'    => [
						'label'   => __( 'Objekt', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_objekte' ],
					],
					'kostenart_id' => [
						'label'   => __( 'Kostenart (bei Beleg)', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_kostenarten_optional' ],
					],
					'mv_id'        => [
						'label'   => __( 'Mietverhältnis (bei Zahlung)', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_mv_optional' ],
					],
				],
			],
			'anlagegueter'      => [
				'label'    => __( 'Abschreibung (AfA)', 'mietverwaltung' ),
				'singular' => __( 'Anlagegut', 'mietverwaltung' ),
				'order'    => 'objekt_id ASC, anschaffung ASC',
				'owner'    => true,
				'upload'   => true,
				'fields'   => [
					'attachment_id'        => [
						'label'   => __( 'Kaufvertrag (PDF)', 'mietverwaltung' ),
						'type'    => 'file',
						'list'    => true,
						'extract' => 'kaufvertrag',
						'hint'    => __( 'Nach Auswahl wird der Vertrag im Browser ausgelesen; erkannte Werte erscheinen unten als Vorschläge. Nichts wird hochgeladen, bevor du speicherst.', 'mietverwaltung' ),
					],
					'objekt_id'            => [
						'label'    => __( 'Objekt', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bezeichnung'          => [
						'label'    => __( 'Bezeichnung', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'z. B. „Gebäude Musterstraße 1“ oder „Einbauküche“', 'mietverwaltung' ),
					],
					'typ'                  => [
						'label'   => __( 'Art', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'gebaeude'  => __( 'Gebäude', 'mietverwaltung' ),
							'beweglich' => __( 'Bewegliches Wirtschaftsgut (Einbauküche, Möbel …)', 'mietverwaltung' ),
						],
					],
					'anschaffung'          => [
						'label'    => __( 'Anschaffung / Fertigstellung', 'mietverwaltung' ),
						'type'     => 'date',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'Beim Kauf: Übergang von Nutzen und Lasten. AfA beginnt zeitanteilig ab diesem Monat.', 'mietverwaltung' ),
					],
					'ak'                   => [
						'label'    => __( 'Kaufpreis / Herstellungskosten (€)', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'nk_grunderwerbsteuer' => [
						'label' => __( 'Grunderwerbsteuer (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'hint'  => __( 'Bayern 3,5 % des Kaufpreises (Bescheid des Finanzamts).', 'mietverwaltung' ),
					],
					'nk_notar'             => [
						'label' => __( 'Notar – Kaufvertrag (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'hint'  => __( 'Nur der Teil für Beurkundung des Kaufvertrags. Der Teil für die Grundschuld gehört unten zu „Finanzierung“.', 'mietverwaltung' ),
					],
					'nk_grundbuch'         => [
						'label' => __( 'Grundbuch – Auflassung/Eigentumsumschreibung (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'hint'  => __( 'Kostenrechnung des Grundbuchamts für Vormerkung und Eigentumsumschreibung.', 'mietverwaltung' ),
					],
					'nk_makler'            => [
						'label' => __( 'Maklerprovision (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
					],
					'nk_sonstige'          => [
						'label' => __( 'Sonstige Anschaffungsnebenkosten (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'hint'  => __( 'z. B. Gutachter vor Kauf, Fahrtkosten zur Besichtigung, Übersetzer.', 'mietverwaltung' ),
					],
					'nebenkosten'          => [
						'label' => __( 'Summe Anschaffungsnebenkosten (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => __( 'Wird aus den Einzelpositionen berechnet, sobald dort etwas eingetragen ist; sonst manuell.', 'mietverwaltung' ),
					],
					'nk_finanzierung'      => [
						'label' => __( 'Finanzierungskosten – Notar/Grundbuch Grundschuld (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'hint'  => __( 'Keine Anschaffungskosten! Sofort abziehbare Werbungskosten (Geldbeschaffungskosten). Beim Speichern wird automatisch ein Beleg der Kostenart „Geldbeschaffungskosten“ angelegt, falls noch keiner mit diesem Betrag existiert.', 'mietverwaltung' ),
					],
					'grundanteil'          => [
						'label' => __( 'Anteil Grund und Boden (%)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => __( 'Nur Gebäude; der Bodenanteil ist nicht abschreibbar. Aufteilung laut Kaufvertrag oder BMF-Arbeitshilfe zur Kaufpreisaufteilung. Auch Eigentumswohnungen haben einen Miteigentumsanteil am Grund (häufig 15–30 %). 0 % akzeptiert das Finanzamt in der Regel nicht.', 'mietverwaltung' ),
					],
					'methode'              => [
						'label'   => __( 'Methode', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'linear'    => __( 'Linear (§ 7 Abs. 4 EStG)', 'mietverwaltung' ),
							'degressiv' => __( 'Degressiv vom Restwert (§ 7 Abs. 5a EStG, Neubau Baubeginn 10/2023–09/2029)', 'mietverwaltung' ),
						],
					],
					'satz'                 => [
						'label' => __( 'AfA-Satz (% p. a.)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => __( 'Leer = automatisch: Gebäude nach Baujahr des Objekts (vor 1925: 2,5 %, ab 2023: 3 %, sonst 2 %), bewegliche Güter 100 / Nutzungsdauer. Degressiv: 5 %. Nur eintragen, wenn abweichend (z. B. 4 % bei nachgewiesener kürzerer Restnutzungsdauer).', 'mietverwaltung' ),
					],
					'nutzungsdauer'        => [
						'label' => __( 'Nutzungsdauer (Jahre)', 'mietverwaltung' ),
						'type'  => 'int',
						'hint'  => __( 'Nur bewegliche Wirtschaftsgüter, z. B. Einbauküche 10 Jahre. GWG bis 800 € netto: Nutzungsdauer 1 = Sofortabzug.', 'mietverwaltung' ),
					],
					'ende'                 => [
						'label' => __( 'Ausscheiden (Verkauf/Entnahme)', 'mietverwaltung' ),
						'type'  => 'date',
					],
					'notiz'                => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
			],
			'darlehen'          => [
				'label'    => __( 'Darlehen', 'mietverwaltung' ),
				'singular' => __( 'Darlehen', 'mietverwaltung' ),
				'order'    => 'status ASC, auszahlung ASC',
				'owner'    => true,
				'children' => [ 'darlehen_jahre', 'darlehen_id' ],
				'upload'   => true,
				'fields'   => [
					'objekt_id'       => [
						'label'    => __( 'Objekt', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'bank'            => [
						'label'    => __( 'Bank', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'bezeichnung'     => [
						'label' => __( 'Bezeichnung / Konto-Nr.', 'mietverwaltung' ),
						'type'  => 'text',
						'list'  => true,
					],
					'betrag'          => [
						'label'    => __( 'Darlehensbetrag (€)', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
					],
					'auszahlung'      => [
						'label' => __( 'Auszahlung', 'mietverwaltung' ),
						'type'  => 'date',
						'list'  => true,
					],
					'zinssatz'        => [
						'label' => __( 'Sollzins (% p. a.)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'zinsbindung_bis' => [
						'label' => __( 'Zinsbindung bis', 'mietverwaltung' ),
						'type'  => 'date',
						'list'  => true,
					],
					'rate'            => [
						'label' => __( 'Monatsrate (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
					],
					'tilgung'         => [
						'label' => __( 'Anfängliche Tilgung (% p. a.)', 'mietverwaltung' ),
						'type'  => 'decimal',
					],
					'status'          => [
						'label'   => __( 'Status', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'aktiv'     => __( 'Aktiv', 'mietverwaltung' ),
							'abgeloest' => __( 'Abgelöst', 'mietverwaltung' ),
						],
					],
					'attachment_id'   => [
						'label' => __( 'Darlehensvertrag (PDF)', 'mietverwaltung' ),
						'type'  => 'file',
						'list'  => true,
					],
					'notiz'           => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
			],
			'dokumente'         => [
				'label'    => __( 'Dokumente', 'mietverwaltung' ),
				'singular' => __( 'Dokument', 'mietverwaltung' ),
				'order'    => 'datum DESC, id DESC',
				'owner'    => true,
				'upload'   => true,
				'fields'   => [
					'objekt_id'       => [
						'label'    => __( 'Objekt', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_objekte' ],
					],
					'mv_id'           => [
						'label'   => __( 'Mietverhältnis', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [ __CLASS__, 'opt_mv_optional' ],
						'hint'    => __( 'Nur bei mieterbezogenen Dokumenten (Mietvertrag, Übergabeprotokoll, Abrechnung).', 'mietverwaltung' ),
					],
					'typ'             => [
						'label'   => __( 'Art', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'kaufvertrag'        => __( 'Kaufvertrag', 'mietverwaltung' ),
							'grundbuch'          => __( 'Grundbuchauszug', 'mietverwaltung' ),
							'teilungserklaerung' => __( 'Teilungserklärung / WEG', 'mietverwaltung' ),
							'mietvertrag'        => __( 'Mietvertrag', 'mietverwaltung' ),
							'uebergabeprotokoll' => __( 'Übergabeprotokoll', 'mietverwaltung' ),
							'abrechnung'         => __( 'Betriebskostenabrechnung', 'mietverwaltung' ),
							'versicherung'       => __( 'Versicherungsschein', 'mietverwaltung' ),
							'energieausweis'     => __( 'Energieausweis', 'mietverwaltung' ),
							'sonstiges'          => __( 'Sonstiges', 'mietverwaltung' ),
						],
					],
					'bezeichnung'     => [
						'label'    => __( 'Bezeichnung', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'datum'           => [
						'label' => __( 'Datum', 'mietverwaltung' ),
						'type'  => 'date',
						'list'  => true,
					],
					'mieter_sichtbar' => [
						'label'   => __( 'Im Mieterportal sichtbar', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'0' => __( 'Nein', 'mietverwaltung' ),
							'1' => __( 'Ja', 'mietverwaltung' ),
						],
						'hint'    => __( 'Nur wirksam mit zugeordnetem Mietverhältnis.', 'mietverwaltung' ),
					],
					'attachment_id'   => [
						'label'    => __( 'Datei (PDF)', 'mietverwaltung' ),
						'type'     => 'file',
						'required' => true,
						'list'     => true,
					],
					'notiz'           => [
						'label' => __( 'Notiz', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
				],
			],
			'darlehen_jahre'    => [
				'label'       => __( 'Zinsbescheinigungen', 'mietverwaltung' ),
				'singular'    => __( 'Jahreswert Darlehen', 'mietverwaltung' ),
				'order'       => 'jahr DESC, darlehen_id ASC',
				'upload'      => true,
				'filter_year' => 'jahr',
				'fields'      => [
					'darlehen_id'   => [
						'label'    => __( 'Darlehen', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_darlehen' ],
					],
					'jahr'          => [
						'label'    => __( 'Jahr', 'mietverwaltung' ),
						'type'     => 'int',
						'required' => true,
						'list'     => true,
					],
					'zinsen'        => [
						'label'    => __( 'Gezahlte Zinsen (€)', 'mietverwaltung' ),
						'type'     => 'decimal',
						'required' => true,
						'list'     => true,
						'hint'     => __( 'Laut Jahreszinsbescheinigung der Bank – das ist der Werbungskostenbetrag.', 'mietverwaltung' ),
					],
					'tilgung'       => [
						'label' => __( 'Tilgung (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
						'hint'  => __( 'Nicht abziehbar, nur zur Info.', 'mietverwaltung' ),
					],
					'restschuld'    => [
						'label' => __( 'Restschuld 31.12. (€)', 'mietverwaltung' ),
						'type'  => 'decimal',
						'list'  => true,
					],
					'attachment_id' => [
						'label' => __( 'Zinsbescheinigung (PDF)', 'mietverwaltung' ),
						'type'  => 'file',
						'list'  => true,
					],
				],
			],
			'kostenarten'       => [
				'label'    => __( 'Kostenarten', 'mietverwaltung' ),
				'singular' => __( 'Kostenart', 'mietverwaltung' ),
				'order'    => 'sortierung ASC, name ASC',
				'owner'    => true,
				'children' => [ 'belege', 'kostenart_id' ],
				'fields'   => [
					'name'         => [
						'label'    => __( 'Bezeichnung', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'anlage_v'     => [
						'label'    => __( 'Anlage V – Gruppe', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ 'MV_DB', 'anlage_v_gruppen' ],
					],
					'umlagefaehig' => [
						'label'   => __( 'Umlagefähig (BetrKV)', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'0' => __( 'Nein – trägt der Vermieter', 'mietverwaltung' ),
							'1' => __( 'Ja – geht in die Betriebskostenabrechnung', 'mietverwaltung' ),
						],
					],
					'schluessel'   => [
						'label'   => __( 'Umlageschlüssel', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							''          => '–',
							'flaeche'   => __( 'Wohnfläche', 'mietverwaltung' ),
							'personen'  => __( 'Personen', 'mietverwaltung' ),
							'verbrauch' => __( 'Verbrauch (Zähler)', 'mietverwaltung' ),
							'einheit'   => __( 'je Einheit', 'mietverwaltung' ),
						],
					],
					'betrkv'       => [
						'label' => __( 'Rechtsgrundlage', 'mietverwaltung' ),
						'type'  => 'text',
						'hint'  => __( 'z. B. § 2 Nr. 1 BetrKV', 'mietverwaltung' ),
					],
					'sortierung'   => [
						'label' => __( 'Sortierung', 'mietverwaltung' ),
						'type'  => 'int',
					],
				],
			],
			'meldungen'         => [
				'label'    => __( 'Meldungen', 'mietverwaltung' ),
				'singular' => __( 'Meldung', 'mietverwaltung' ),
				'order'    => 'datum DESC',
				'fields'   => [
					'mv_id'   => [
						'label'    => __( 'Mietverhältnis', 'mietverwaltung' ),
						'type'     => 'select',
						'required' => true,
						'list'     => true,
						'options'  => [ __CLASS__, 'opt_mv' ],
					],
					'datum'   => [
						'label'    => __( 'Datum', 'mietverwaltung' ),
						'type'     => 'datetime',
						'required' => true,
						'list'     => true,
					],
					'betreff' => [
						'label'    => __( 'Betreff', 'mietverwaltung' ),
						'type'     => 'text',
						'required' => true,
						'list'     => true,
					],
					'text'    => [
						'label' => __( 'Beschreibung', 'mietverwaltung' ),
						'type'  => 'textarea',
					],
					'status'  => [
						'label'   => __( 'Status', 'mietverwaltung' ),
						'type'    => 'select',
						'list'    => true,
						'options' => [
							'offen'          => __( 'Offen', 'mietverwaltung' ),
							'in_bearbeitung' => __( 'In Bearbeitung', 'mietverwaltung' ),
							'erledigt'       => __( 'Erledigt', 'mietverwaltung' ),
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
		foreach ( $wpdb->get_results( 'SELECT id, bezeichnung FROM ' . mv_table( 'objekte' ) . ' ORDER BY bezeichnung' ) as $r ) {
			$out[ $r->id ] = $r->bezeichnung;
		}
		return $out;
	}

	public static function opt_einheiten(): array {
		global $wpdb;
		$out  = [];
		$rows = $wpdb->get_results( 'SELECT e.id, e.bezeichnung, o.bezeichnung AS objekt FROM ' . mv_table( 'einheiten' ) . ' e JOIN ' . mv_table( 'objekte' ) . ' o ON o.id = e.objekt_id ORDER BY o.bezeichnung, e.bezeichnung' );
		foreach ( $rows as $r ) {
			$out[ $r->id ] = $r->objekt . ' / ' . $r->bezeichnung;
		}
		return $out;
	}

	public static function opt_mieter(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, vorname, nachname FROM ' . mv_table( 'mieter' ) . ' ORDER BY nachname, vorname' ) as $r ) {
			$out[ $r->id ] = mv_mieter_name( $r );
		}
		return $out;
	}

	public static function opt_mv(): array {
		$out = [];
		foreach ( MV_Soll::alle_mv() as $mv ) {
			$out[ $mv->id ] = MV_Soll::mv_label( $mv );
		}
		return $out;
	}

	public static function opt_kostenarten(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, name, umlagefaehig FROM ' . mv_table( 'kostenarten' ) . ' ORDER BY sortierung, name' ) as $r ) {
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
		$rows = $wpdb->get_results( 'SELECT z.*, o.bezeichnung AS objekt, e.bezeichnung AS einheit FROM ' . mv_table( 'zaehler' ) . ' z JOIN ' . mv_table( 'objekte' ) . ' o ON o.id = z.objekt_id LEFT JOIN ' . mv_table( 'einheiten' ) . ' e ON e.id = z.einheit_id ORDER BY o.bezeichnung, e.bezeichnung, z.typ' );
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
		foreach ( $wpdb->get_results( 'SELECT id, bank, bezeichnung, betrag FROM ' . mv_table( 'darlehen' ) . ' ORDER BY bank' ) as $r ) {
			$out[ $r->id ] = $r->bank . ( $r->bezeichnung ? ' – ' . $r->bezeichnung : '' ) . ' (' . mv_eur( $r->betrag ) . ')';
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
		add_menu_page( __( 'Mietverwaltung', 'mietverwaltung' ), __( 'Mietverwaltung', 'mietverwaltung' ), self::CAP, 'mv_uebersicht', [ __CLASS__, 'page_uebersicht' ], 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAyIDEuNSA5LjJsMS4xIDEuMkw0IDkuM1YxN2g1di00aDJ2NGg1VjkuM2wxLjQgMS4xIDEuMS0xLjJMMTAgMnptMCAyLjQgNC41IDMuOVYxNS41aC0yVjExSDcuNXY0LjVoLTJWOC4zTDEwIDQuNHoiLz48Y2lyY2xlIGZpbGw9ImJsYWNrIiBjeD0iMTQuNiIgY3k9IjUuMiIgcj0iMS4zIi8+PC9zdmc+', 58 );
		add_submenu_page( 'mv_uebersicht', __( 'Übersicht', 'mietverwaltung' ), '<span class="dashicons dashicons-dashboard"></span> ' . __( 'Übersicht', 'mietverwaltung' ), self::CAP, 'mv_uebersicht', [ __CLASS__, 'page_uebersicht' ] );
		// Gruppen (## = Überschrift) und Dashicons
		$reihenfolge = [
			'##' . __( 'Alltag', 'mietverwaltung' ),
			'zahlungen',
			'belege',
			'__bank',
			'meldungen',
			'dokumente',
			'##' . __( 'Verwaltung', 'mietverwaltung' ),
			'objekte',
			'einheiten',
			'mieter',
			'mietverhaeltnisse',
			'konditionen',
			'__mietvertrag',
			'__kaution',
			'__erhoehung',
			'__mahnung',
			'##' . __( 'Jahresabschluss', 'mietverwaltung' ),
			'zaehler',
			'zaehlerstaende',
			'__abrechnung',
			'kostenarten',
			'anlagegueter',
			'darlehen',
			'__darlehenskonto',
			'darlehen_jahre',
			'__steuer',
			'##' . __( 'System', 'mietverwaltung' ),
			'bank_regeln',
			'__datenschutz',
			'__einstellungen',
			'__assistent',
			'__hilfe',
		];
		$icons       = [
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
		$kurz        = [
			'__assistent'     => __( 'Assistent', 'mietverwaltung' ),
			'__abrechnung'    => __( 'BK-Abrechnung', 'mietverwaltung' ),
			'__einstellungen' => __( 'Einstellungen', 'mietverwaltung' ),
			'__mietvertrag'   => __( 'Mietvertrag', 'mietverwaltung' ),
			'darlehen_jahre'  => __( 'Zinsbescheinigung', 'mietverwaltung' ),
			'bank_regeln'     => __( 'Bankregeln', 'mietverwaltung' ),
		];
		$ico         = fn( string $key, string $label ) => '<span class="dashicons dashicons-' . esc_attr( $icons[ $key ] ?? 'marker' ) . '"></span> ' . ( $kurz[ $key ] ?? $label );
		$seiten      = [
			'__mietvertrag'    => [ __( 'Mietvertrag & Übergabe', 'mietverwaltung' ), [ 'MV_Mietvertrag', 'page' ] ],
			'__bank'           => [ __( 'Bankimport', 'mietverwaltung' ), [ 'MV_Bank', 'page' ] ],
			'__mahnung'        => [ __( 'Mahnwesen', 'mietverwaltung' ), [ 'MV_Mahnung', 'page' ] ],
			'__erhoehung'      => [ __( 'Mieterhöhung', 'mietverwaltung' ), [ 'MV_Erhoehung', 'page' ] ],
			'__kaution'        => [ __( 'Kaution', 'mietverwaltung' ), [ 'MV_Kaution', 'page' ] ],
			'__darlehenskonto' => [ __( 'Darlehenskonto', 'mietverwaltung' ), [ 'MV_Darlehen', 'page' ] ],
			'__abrechnung'     => [ __( 'Betriebskostenabrechnung', 'mietverwaltung' ), [ 'MV_Abrechnung', 'page' ] ],
			'__steuer'         => [ __( 'Steuer (Anlage V)', 'mietverwaltung' ), [ 'MV_Steuer', 'page' ] ],
			'__datenschutz'    => [ __( 'Datenschutz', 'mietverwaltung' ), [ 'MV_Datenschutz', 'page' ] ],
			'__einstellungen'  => [ __( 'Einstellungen & Backup', 'mietverwaltung' ), [ 'MV_Einstellungen', 'page' ] ],
			'__hilfe'          => [ __( 'Hilfe', 'mietverwaltung' ), [ 'MV_Hilfe', 'page' ] ],
			'__assistent'      => [ __( 'Einrichtungsassistent', 'mietverwaltung' ), [ 'MV_Assistent', 'page' ] ],
		];
		$ents        = self::entities();
		$g           = 0;
		foreach ( $reihenfolge as $key ) {
			if ( strpos( $key, '##' ) === 0 ) {
				++$g;
				add_submenu_page( 'mv_uebersicht', '', '<span class="mv-menu-group">' . esc_html( substr( $key, 2 ) ) . '</span>', self::CAP, 'mv_grp_' . $g, [ __CLASS__, 'page_uebersicht' ] );
			} elseif ( isset( $seiten[ $key ] ) ) {
				add_submenu_page( 'mv_uebersicht', $seiten[ $key ][0], $ico( $key, $seiten[ $key ][0] ), self::CAP, 'mv_' . substr( $key, 2 ), $seiten[ $key ][1] );
			} elseif ( isset( $ents[ $key ] ) ) {
				add_submenu_page( 'mv_uebersicht', $ents[ $key ]['label'], $ico( $key, $ents[ $key ]['label'] ), self::CAP, 'mv_' . $key, [ __CLASS__, 'page_entity' ] );
			}
		}
	}

	private static function current_entity(): ?string {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$key  = substr( $page, 3 );
		return isset( self::entities()[ $key ] ) ? $key : null;
	}

	private static function url( string $key, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'mv_' . $key ], $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Speichern / Löschen                                                */
	/* ------------------------------------------------------------------ */

	public static function handle_actions(): void {
		global $wpdb;

		// Speichern
		if ( isset( $_POST['mv_entity'] ) ) {
			$key = sanitize_key( $_POST['mv_entity'] );
			$ent = self::entities()[ $key ] ?? null;
			if ( ! $ent || ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
			}
			check_admin_referer( 'mv_save_' . $key );

			$id     = isset( $_POST['mv_record_id'] ) ? (int) $_POST['mv_record_id'] : 0;
			$data   = [];
			$errors = [];

			$zusatz = null;
			foreach ( $ent['fields'] as $name => $f ) {
				if ( $f['type'] === 'zusatz' ) {
					$zusatz = [];
					foreach ( (array) ( $_POST[ $name ] ?? [] ) as $eid => $anteil ) {
						if ( (int) $eid > 0 && ! empty( $_POST[ $name . '_on' ][ $eid ] ) ) {
							$zusatz[ (int) $eid ] = mv_to_float( wp_unslash( $anteil ) );
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
						$errors[] = sprintf( /* translators: %s: Feldname */ __( '%s ist ein Pflichtfeld.', 'mietverwaltung' ), $f['label'] );
					}
					continue;
				}
				$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
				$val = self::sanitize( $f['type'], $raw );
				if ( ! empty( $f['required'] ) && ( $val === '' || $val === null ) ) {
					$errors[] = sprintf( /* translators: %s: Feldname */ __( '%s ist ein Pflichtfeld.', 'mietverwaltung' ), $f['label'] );
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
				set_transient( 'mv_errors_' . get_current_user_id(), $errors, 120 );
				set_transient( 'mv_formdata_' . get_current_user_id(), $data, 120 );
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

			$table = mv_table( $key );
			$data  = self::null_bereinigen( $table, $data, (bool) $id );
			if ( $id ) {
				$ok = $wpdb->update( $table, $data, [ 'id' => $id ] );
			} else {
				if ( ! empty( $ent['owner'] ) ) {
					$data['owner_id'] = MV_OWNER_ID;
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
				$wpdb->delete( mv_table( 'mv_einheiten' ), [ 'mv_id' => $mv_id ] );
				foreach ( $zusatz as $eid => $anteil ) {
					$wpdb->insert(
						mv_table( 'mv_einheiten' ),
						[
							'mv_id'      => $mv_id,
							'einheit_id' => $eid,
							'mietanteil' => $anteil,
						]
					);
				}
			}
			if ( $ok === false ) {
				$errors[] = sprintf( /* translators: %s: Datenbankfehler */ __( 'Speichern fehlgeschlagen: %s', 'mietverwaltung' ), $wpdb->last_error ?: __( 'unbekannter Datenbankfehler', 'mietverwaltung' ) );
				set_transient( 'mv_errors_' . get_current_user_id(), $errors, 120 );
				set_transient( 'mv_formdata_' . get_current_user_id(), $data, 120 );
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
				wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
			}
			$id = (int) $_GET['id'];
			check_admin_referer( 'mv_delete_' . $key . '_' . $id );
			$ent = self::entities()[ $key ];

			if ( ! empty( $ent['children'] ) ) {
				[ $ctable, $fk ] = $ent['children'];
				$cnt             = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mv_table( $ctable ) . " WHERE $fk = %d", $id ) );
				if ( $cnt > 0 ) {
					wp_safe_redirect( self::url( $key, [ 'msg' => 'haschildren' ] ) );
					exit;
				}
			}
			if ( $key === 'mietverhaeltnisse' ) {
				$wpdb->delete( mv_table( 'mv_einheiten' ), [ 'mv_id' => $id ] );
				$wpdb->delete( mv_table( 'konditionen' ), [ 'mv_id' => $id ] );
				$wpdb->delete( mv_table( 'meldungen' ), [ 'mv_id' => $id ] );
			}
			$wpdb->delete( mv_table( $key ), [ 'id' => $id ] );
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
			$cols            = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
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
		$ka = $wpdb->get_var( 'SELECT id FROM ' . mv_table( 'kostenarten' ) . " WHERE name LIKE '%Geldbeschaffung%' ORDER BY id LIMIT 1" );
		if ( ! $ka ) {
			$wpdb->insert(
				mv_table( 'kostenarten' ),
				[
					'owner_id'     => MV_OWNER_ID,
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
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mv_table( 'belege' ) . ' WHERE objekt_id = %d AND kostenart_id = %d AND ABS(betrag - %f) < 0.01', $objekt_id, $ka, $betrag ) );
		if ( $exists ) {
			return;
		}
		$wpdb->insert(
			mv_table( 'belege' ),
			[
				'owner_id'         => MV_OWNER_ID,
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
					$e[] = __( 'Mietende liegt vor dem Mietbeginn.', 'mietverwaltung' );
				}
				if ( ! empty( $d['einheit_id'] ) && ! empty( $d['beginn'] ) ) {
					$ende      = $d['ende'] ?: '9999-12-31';
					$kollision = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mv_table( 'mietverhaeltnisse' ) . " WHERE einheit_id = %d AND id <> %d AND beginn <= %s AND COALESCE(NULLIF(ende,'0000-00-00'),'9999-12-31') >= %s", $d['einheit_id'], $id, $ende, $d['beginn'] ) );
					if ( $kollision ) {
						$e[] = __( 'Für diese Einheit besteht im Zeitraum bereits ein anderes Mietverhältnis.', 'mietverwaltung' );
					}
				}
				if ( isset( $d['zahltag'] ) && ( $d['zahltag'] < 1 || $d['zahltag'] > 28 ) ) {
					$e[] = __( 'Zahltag muss zwischen 1 und 28 liegen.', 'mietverwaltung' );
				}
				foreach ( array_keys( $d['__zusatz'] ?? [] ) as $eid ) {
					if ( $eid === (int) ( $d['einheit_id'] ?? 0 ) ) {
						$e[] = __( 'Die Haupteinheit kann nicht zugleich weitere Einheit sein.', 'mietverwaltung' );
						continue;
					}
					$ende  = $d['ende'] ?: '9999-12-31';
					$koll  = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mv_table( 'mv_einheiten' ) . ' z JOIN ' . mv_table( 'mietverhaeltnisse' ) . " mv ON mv.id = z.mv_id WHERE z.einheit_id = %d AND mv.id <> %d AND mv.beginn <= %s AND COALESCE(NULLIF(mv.ende,'0000-00-00'),'9999-12-31') >= %s", $eid, $id, $ende, $d['beginn'] ?? '0000-00-00' ) );
					$koll += $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mv_table( 'mietverhaeltnisse' ) . " WHERE einheit_id = %d AND id <> %d AND beginn <= %s AND COALESCE(NULLIF(ende,'0000-00-00'),'9999-12-31') >= %s", $eid, $id, $ende, $d['beginn'] ?? '0000-00-00' ) );
					if ( $koll ) {
						$e[] = sprintf( /* translators: %s: Einheit */ __( 'Weitere Einheit „%s“ ist im Zeitraum bereits anderweitig vermietet.', 'mietverwaltung' ), self::opt_einheiten()[ $eid ] ?? $eid );
					}
				}
				break;
			case 'konditionen':
				if ( ! empty( $d['mv_id'] ) && ! empty( $d['gueltig_ab'] ) ) {
					$mv = $wpdb->get_row( $wpdb->prepare( 'SELECT beginn, kaution FROM ' . mv_table( 'mietverhaeltnisse' ) . ' WHERE id = %d', $d['mv_id'] ) );
					if ( $mv && $d['gueltig_ab'] < $mv->beginn ) {
						$e[] = 'Gültig ab (' . mv_date( $d['gueltig_ab'] ) . ') liegt vor dem Mietbeginn (' . mv_date( $mv->beginn ) . ').';
					}
					if ( $mv && (float) $mv->kaution > 3 * (float) $d['kaltmiete'] + 0.005 && (float) $d['kaltmiete'] > 0 ) {
						$e[] = 'Hinweis: Die Kaution (' . mv_eur( $mv->kaution ) . ') übersteigt drei Kaltmieten (§ 551 BGB) – bitte Mietverhältnis prüfen.';
					}
				}
				if ( isset( $d['kaltmiete'] ) && $d['kaltmiete'] <= 0 ) {
					$e[] = __( 'Kaltmiete muss größer als 0 sein.', 'mietverwaltung' );
				}
				break;
			case 'zahlungen':
				if ( isset( $d['betrag'] ) && abs( (float) $d['betrag'] ) < 0.005 ) {
					$e[] = __( 'Betrag darf nicht 0 sein.', 'mietverwaltung' );
				}
				if ( ! empty( $d['datum'] ) && $d['datum'] > date( 'Y-m-d', strtotime( $heute . ' +7 days' ) ) ) {
					$e[] = __( 'Zahlungsdatum liegt in der Zukunft.', 'mietverwaltung' );
				}
				if ( ! empty( $d['mv_id'] ) && ! empty( $d['monat'] ) ) {
					$mv = $wpdb->get_row( $wpdb->prepare( 'SELECT beginn FROM ' . mv_table( 'mietverhaeltnisse' ) . ' WHERE id = %d', $d['mv_id'] ) );
					if ( $mv && $d['monat'] < substr( $mv->beginn, 0, 7 ) . '-01' ) {
						$e[] = __( 'Zuordnungsmonat liegt vor dem Mietbeginn.', 'mietverwaltung' );
					}
				}
				break;
			case 'belege':
				if ( ! empty( $d['zeitraum_von'] ) && ! empty( $d['zeitraum_bis'] ) && $d['zeitraum_bis'] < $d['zeitraum_von'] ) {
					$e[] = __( 'Leistungszeitraum: „bis“ liegt vor „von“.', 'mietverwaltung' );
				}
				if ( isset( $d['betrag'] ) && (float) $d['betrag'] <= 0 ) {
					$e[] = __( 'Betrag muss größer als 0 sein (Gutschriften als negativen Beleg mit Beschreibung erfassen ist nicht vorgesehen – bitte mit dem Originalbeleg verrechnen).', 'mietverwaltung' );
				}
				if ( ! empty( $d['lohnanteil'] ) && ! empty( $d['betrag'] ) && (float) $d['lohnanteil'] > (float) $d['betrag'] ) {
					$e[] = __( 'Lohnanteil kann nicht größer als der Rechnungsbetrag sein.', 'mietverwaltung' );
				}
				if ( ! empty( $d['steuerjahr'] ) && ! empty( $d['datum'] ) && abs( (int) $d['steuerjahr'] - (int) substr( $d['datum'], 0, 4 ) ) > 1 ) {
					$e[] = __( 'Steuerjahr weicht um mehr als ein Jahr vom Zahlungsdatum ab.', 'mietverwaltung' );
				}
				break;
			case 'einheiten':
				if ( isset( $d['flaeche'] ) && (float) $d['flaeche'] <= 0 && in_array( $d['typ'] ?? '', [ 'wohnung', 'gewerbe' ], true ) ) {
					$e[] = __( 'Wohnfläche muss größer als 0 sein (wird für die Betriebskostenabrechnung benötigt).', 'mietverwaltung' );
				}
				break;
			case 'zaehlerstaende':
				if ( ! empty( $d['zaehler_id'] ) && ! empty( $d['datum'] ) ) {
					$vorher = $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . mv_table( 'zaehlerstaende' ) . ' WHERE zaehler_id = %d AND datum < %s AND id <> %d ORDER BY datum DESC LIMIT 1', $d['zaehler_id'], $d['datum'], $id ) );
					if ( $vorher !== null && (float) $d['stand'] < (float) $vorher ) {
						$e[] = 'Zählerstand (' . $d['stand'] . ') ist kleiner als der vorherige Stand (' . $vorher . ') – Zählerwechsel? Dann neuen Zähler anlegen.';
					}
				}
				break;
			case 'anlagegueter':
				if ( ( $d['typ'] ?? '' ) === 'gebaeude' && ( (float) ( $d['grundanteil'] ?? 0 ) < 0 || (float) $d['grundanteil'] >= 100 ) ) {
					$e[] = __( 'Anteil Grund und Boden muss unter 100 % liegen.', 'mietverwaltung' );
				}
				if ( ( $d['typ'] ?? '' ) === 'beweglich' && empty( $d['nutzungsdauer'] ) && (float) ( $d['satz'] ?? 0 ) <= 0 ) {
					$e[] = __( 'Bewegliches Wirtschaftsgut: Nutzungsdauer oder AfA-Satz angeben.', 'mietverwaltung' );
				}
				break;
			case 'darlehen_jahre':
				if ( ! empty( $d['jahr'] ) && ( $d['jahr'] < 1990 || $d['jahr'] > (int) current_time( 'Y' ) ) ) {
					$e[] = __( 'Jahr ungültig.', 'mietverwaltung' );
				}
				break;
			case 'mieter':
				if ( ! empty( $d['iban'] ) ) {
					$iban = strtoupper( preg_replace( '/\s+/', '', $d['iban'] ) );
					if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) || ! self::iban_ok( $iban ) ) {
						$e[] = __( 'IBAN ungültig (Prüfziffer).', 'mietverwaltung' );
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
				return $raw === '' ? 0 : mv_to_float( $raw );
			case 'date':
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : null;
			case 'month':
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^\d{4}-\d{2}$/', $raw ) ? $raw . '-01' : null;
			case 'datetime':
				$raw = sanitize_text_field( $raw );
				$ts  = strtotime( $raw );
				return $ts ? date( 'Y-m-d H:i:s', $ts ) : null;
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
		$msg = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
		$map = [
			'saved'       => [ 'success', __( 'Gespeichert.', 'mietverwaltung' ) ],
			'deleted'     => [ 'success', __( 'Gelöscht.', 'mietverwaltung' ) ],
			'haschildren' => [ 'error', __( 'Löschen nicht möglich: Es sind noch abhängige Datensätze vorhanden.', 'mietverwaltung' ) ],
			'gebucht'     => [ 'success', sprintf( /* translators: %d: Anzahl */ __( '%d Mieteingang/-eingänge gebucht.', 'mietverwaltung' ), (int) ( $_GET['n'] ?? 0 ) ) ],
		];
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $map[ $msg ][0], esc_html( $map[ $msg ][1] ) );
		}
		$errors = get_transient( 'mv_errors_' . get_current_user_id() );
		if ( $errors ) {
			delete_transient( 'mv_errors_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
		}
	}

	public static function page_entity(): void {
		$key = self::current_entity();
		if ( ! $key ) {
			return;
		}
		$ent    = self::entities()[ $key ];
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

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
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">' . esc_html__( '%s hinzufügen', 'mietverwaltung' ) . '</a><hr class="wp-header-end">',
			esc_html( $ent['label'] ),
			esc_url( self::url( $key, [ 'action' => 'new' ] ) ),
			esc_html( $ent['singular'] )
		);
		self::notices();

		$where = '';
		if ( ! empty( $ent['filter_year'] ) ) {
			$col   = $ent['filter_year'];
			$jahre = $wpdb->get_col( "SELECT DISTINCT $col FROM " . mv_table( $key ) . " ORDER BY $col DESC" );
			$jahr  = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : ( $jahre ? (int) $jahre[0] : (int) current_time( 'Y' ) );
			if ( $jahr > 0 ) {
				$where = $wpdb->prepare( " WHERE $col = %d", $jahr );
			}
			echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mv_' . esc_attr( $key ) . '"><label>Jahr: <select name="jahr" onchange="this.form.submit()">';
			printf( '<option value="0"%s>' . esc_html__( 'alle', 'mietverwaltung' ) . '</option>', selected( $jahr, 0, false ) );
			foreach ( array_unique( array_merge( $jahre, [ (string) current_time( 'Y' ) ] ) ) as $j ) {
				printf( '<option value="%1$d"%2$s>%1$d</option>', (int) $j, selected( $jahr, (int) $j, false ) );
			}
			echo '</select></label></form>';
		}

		$rows = $wpdb->get_results( 'SELECT * FROM ' . mv_table( $key ) . $where . ' ORDER BY ' . $ent['order'] );
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
		echo '<th class="mv-actions">' . esc_html__( 'Aktionen', 'mietverwaltung' ) . '</th></tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( count( $cols ) + 1 ) . '">Noch keine Einträge.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr>';
			foreach ( $cols as $name => $f ) {
				if ( $f['type'] === 'zusatz' ) {
					$z = MV_Soll::zusatzeinheiten( (int) $r->id );
					echo '<td>' . esc_html( $z ? implode( ', ', array_map( fn( $x ) => $x->bezeichnung . ( (float) $x->mietanteil > 0 ? ' (' . mv_eur( $x->mietanteil ) . ')' : '' ), $z ) ) : '–' ) . '</td>';
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
				'mv_delete_' . $key . '_' . $r->id
			);
			printf( '<td class="mv-actions"><a href="%s">' . esc_html__( 'Bearbeiten', 'mietverwaltung' ) . '</a> | <a href="%s" class="mv-delete" onclick="return confirm(\'Wirklich löschen?\')">' . esc_html__( 'Löschen', 'mietverwaltung' ) . '</a></td>', esc_url( $edit ), esc_url( $del ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function display( array $f, $value, array $options ): string {
		switch ( $f['type'] ) {
			case 'decimal':
				return mv_eur( $value );
			case 'date':
				return mv_date( $value );
			case 'month':
				return mv_monat( $value );
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
		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row      = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mv_table( $key ) . ' WHERE id = %d', $id ) ) : null;
		$formdata = get_transient( 'mv_formdata_' . get_current_user_id() );
		if ( is_array( $formdata ) ) {
			delete_transient( 'mv_formdata_' . get_current_user_id() );
			$row = (object) array_merge( $row ? (array) $row : [], $formdata );
		}

		printf( '<h1>%s %s</h1>', $id ? __( 'Bearbeiten:', 'mietverwaltung' ) : __( 'Neu:', 'mietverwaltung' ), esc_html( $ent['singular'] ) );
		self::notices();

		echo '<form method="post" class="mv-form"' . ( ! empty( $ent['upload'] ) ? ' enctype="multipart/form-data"' : '' ) . '>';
		wp_nonce_field( 'mv_save_' . $key );
		printf( '<input type="hidden" name="mv_entity" value="%s"><input type="hidden" name="mv_record_id" value="%d">', esc_attr( $key ), $id );
		echo '<table class="form-table"><tbody>';

		foreach ( $ent['fields'] as $name => $f ) {
			$val = $row && property_exists( $row, $name ) ? $row->$name : '';
			$req = ! empty( $f['required'] ) ? ' required' : '';
			$lbl = esc_html( $f['label'] ) . ( ! empty( $f['required'] ) ? ' <span class="mv-req">*</span>' : '' );
			printf( '<tr><th scope="row"><label for="mv_%s">%s</label></th><td>', esc_attr( $name ), $lbl );

			switch ( $f['type'] ) {
				case 'zusatz':
					$vorhanden = [];
					foreach ( $id ? MV_Soll::zusatzeinheiten( $id ) : [] as $z ) {
						$vorhanden[ $z->einheit_id ] = (float) $z->mietanteil;
					}
					$alle = $wpdb->get_results( 'SELECT e.id, e.bezeichnung, e.typ, o.bezeichnung AS objekt FROM ' . mv_table( 'einheiten' ) . ' e JOIN ' . mv_table( 'objekte' ) . ' o ON o.id = e.objekt_id ORDER BY o.bezeichnung, e.typ, e.bezeichnung' );
					if ( ! $alle ) {
						echo '<p class="description">' . esc_html__( 'Noch keine Einheiten angelegt.', 'mietverwaltung' ) . '</p>';
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
							esc_html__( 'Mietanteil', 'mietverwaltung' ),
							esc_attr( $on && $vorhanden[ $e->id ] > 0 ? number_format( $vorhanden[ $e->id ], 2, ',', '' ) : '' )
						);
					}
					echo '</table>';
					break;
				case 'file':
					if ( $val ) {
						$url = wp_get_attachment_url( (int) $val );
						if ( $url ) {
							printf( '<p><a href="%s" target="_blank">' . esc_html__( 'Aktuelle Datei öffnen', 'mietverwaltung' ) . '</a> ' . esc_html__( '– neue Datei wählen, um sie zu ersetzen.', 'mietverwaltung' ) . '</p>', esc_url( $url ) );
						}
					}
					printf( '<input type="file" id="mv_%1$s" name="%1$s" accept=".pdf,image/*"%2$s>', esc_attr( $name ), ! empty( $f['extract'] ) ? ' data-mv-extract="' . esc_attr( $f['extract'] ) . '"' : '' );
					if ( wp_is_mobile() ) {
						printf( ' <label class="button"><input type="file" name="%1$s" accept="image/*" capture="environment" style="display:none" onchange="document.getElementById(\'mv_%1$s\').disabled=!!this.files.length">' . esc_html__( '📷 Foto aufnehmen', 'mietverwaltung' ) . '</label>', esc_attr( $name ) );
					}
					if ( ! empty( $f['extract'] ) ) {
						echo '<div id="mv-extract-panel" class="mv-extract" hidden></div>';
					}
					break;
				case 'textarea':
					printf( '<textarea id="mv_%1$s" name="%1$s" rows="4" class="large-text">%2$s</textarea>', esc_attr( $name ), esc_textarea( (string) $val ) );
					break;
				case 'select':
					$options = is_callable( $f['options'] ) ? call_user_func( $f['options'] ) : $f['options'];
					printf( '<select id="mv_%1$s" name="%1$s"%2$s>', esc_attr( $name ), $req );
					if ( ! empty( $f['required'] ) ) {
						echo '<option value="">' . esc_html__( '– bitte wählen –', 'mietverwaltung' ) . '</option>';
					}
					foreach ( $options as $k => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $val, (string) $k, false ), esc_html( $label ) );
					}
					echo '</select>';
					break;
				case 'decimal':
					printf( '<input type="text" id="mv_%1$s" name="%1$s" value="%2$s" class="regular-text mv-num" inputmode="decimal"%3$s>', esc_attr( $name ), esc_attr( $val !== '' && $val !== null ? number_format( (float) $val, 2, ',', '' ) : '' ), $req );
					break;
				case 'int':
					printf( '<input type="number" id="mv_%1$s" name="%1$s" value="%2$s" class="small-text"%3$s>', esc_attr( $name ), esc_attr( (string) $val ), $req );
					break;
				case 'date':
					printf( '<input type="date" id="mv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val && $val !== '0000-00-00' ? $val : '' ), $req );
					break;
				case 'month':
					printf( '<input type="month" id="mv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val ? substr( $val, 0, 7 ) : current_time( 'Y-m' ) ), $req );
					break;
				case 'datetime':
					printf( '<input type="datetime-local" id="mv_%1$s" name="%1$s" value="%2$s"%3$s>', esc_attr( $name ), esc_attr( $val ? date( 'Y-m-d\TH:i', strtotime( $val ) ) : current_time( 'Y-m-d\TH:i' ) ), $req );
					break;
				default:
					printf( '<input type="%4$s" id="mv_%1$s" name="%1$s" value="%2$s" class="regular-text"%3$s>', esc_attr( $name ), esc_attr( (string) $val ), $req, $f['type'] === 'email' ? 'email' : 'text' );
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
		printf( '<a href="%s" class="button">' . esc_html__( 'Abbrechen', 'mietverwaltung' ) . '</a>', esc_url( self::url( $key ) ) );
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Übersicht: Offene Posten                                           */
	/* ------------------------------------------------------------------ */

	public static function page_uebersicht(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Mietverwaltung – Übersicht', 'mietverwaltung' ) . '</h1> ';
		printf( '<a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">', esc_url( self::fokus_url() ), self::fokus_aktiv() ? __( 'WordPress-Menü einblenden', 'mietverwaltung' ) : __( 'WordPress-Menü ausblenden', 'mietverwaltung' ) );
		self::notices();

		$mvs = MV_Soll::alle_mv( true );
		if ( ! $mvs ) {
			echo '<p>' . esc_html__( 'Noch kein aktives Mietverhältnis. Reihenfolge zum Start:', 'mietverwaltung' ) . ' <strong>' . esc_html__( 'Objekt → Einheit → Mieter → Mietverhältnis → Konditionen', 'mietverwaltung' ) . '</strong> – ' . sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=mv_assistent' ) ), esc_html__( 'oder den Einrichtungsassistenten nutzen', 'mietverwaltung' ) ) . '.</p></div>';
			return;
		}

		$offene = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mv_table( 'meldungen' ) . " WHERE status <> 'erledigt'" );
		if ( $offene ) {
			printf( '<div class="notice notice-warning inline"><p>' . esc_html__( '%d offene Meldung(en) –', 'mietverwaltung' ) . ' <a href="%s">' . esc_html__( 'zur Bearbeitung', 'mietverwaltung' ) . '</a></p></div>', $offene, esc_url( self::url( 'meldungen' ) ) );
		}

		echo '<form method="post" id="mv-op-form">';
		wp_nonce_field( 'mv_op_buchen', 'mv_op_nonce' );
		echo '<p class="description">' . esc_html__( 'Offene Monate abhaken und mit einem Klick als bezahlt buchen – gebucht wird der offene Betrag mit Zahlungsdatum 1. des Monats (Quelle „abgehakt“, nachträglich unter Zahlungen änderbar).', 'mietverwaltung' ) . ' <button class="button button-primary" onclick="return document.querySelectorAll(\'#mv-op-form input[name=\\\'op[]\\\']:checked\').length>0||(alert(\'' . esc_js( __( 'Bitte mindestens einen Monat anhaken.', 'mietverwaltung' ) ) . '\'),false)">' . esc_html__( 'Markierte als bezahlt buchen', 'mietverwaltung' ) . '</button></p>';
		$offen_gesamt = 0;
		foreach ( $mvs as $mv ) {
			$op          = MV_Soll::op_liste( $mv );
			$saldo_class = $op['saldo'] < -0.005 ? 'mv-neg' : ( $op['saldo'] > 0.005 ? 'mv-pos' : '' );

			printf( '<h2>%s</h2>', esc_html( MV_Soll::mv_label( $mv ) ) );
			printf(
				'<p>' . esc_html__( 'Mietbeginn %1$s · Kaution %2$s · Saldo gesamt:', 'mietverwaltung' ) . ' <strong class="%s">%s</strong> <a href="%s" class="button button-small">' . esc_html__( 'Zahlung erfassen', 'mietverwaltung' ) . '</a></p>',
				esc_html( mv_date( $mv->beginn ) ),
				esc_html( mv_eur( $mv->kaution ) ),
				esc_attr( $saldo_class ),
				esc_html( mv_eur( $op['saldo'] ) ),
				esc_url( self::url( 'zahlungen', [ 'action' => 'new' ] ) )
			);

			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" title="' . esc_attr__( 'alle offenen Monate dieses Mietverhältnisses', 'mietverwaltung' ) . '" onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)"></th><th>' . esc_html__( 'Monat', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Kaltmiete', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'BK-VZ', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'HK-VZ', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Soll', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Ist', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Differenz', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Saldo kumuliert', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $op['rows'] ) as $r ) {
				$cls   = $r['diff'] < -0.005 ? 'mv-neg' : ( $r['diff'] > 0.005 ? 'mv-pos' : '' );
				$offen = $r['diff'] < -0.005 && ! $r['ohne_konditionen'];
				if ( $offen ) {
					++$offen_gesamt;
				}
				printf(
					'<tr><td class="mv-op-check">%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td class="%s">%s</td><td class="%s">%s</td></tr>',
					$offen ? '<input type="checkbox" name="op[]" value="' . esc_attr( $mv->id . '|' . $r['monat'] ) . '" title="' . esc_attr( sprintf( __( '%s als bezahlt buchen', 'mietverwaltung' ), mv_eur( -$r['diff'] ) ) ) . '">' : ( $r['diff'] < -0.005 ? '' : '✓' ),
					esc_html( mv_monat( $r['monat'] ) ),
					$r['ohne_konditionen'] ? ' <span class="mv-warn" title="Keine Konditionen hinterlegt">⚠</span>' : '',
					esc_html( mv_eur( $r['kalt'] ) ),
					esc_html( mv_eur( $r['bk'] ) ),
					esc_html( mv_eur( $r['hk'] ) ),
					esc_html( mv_eur( $r['soll'] ) ),
					esc_html( mv_eur( $r['ist'] ) ),
					esc_attr( $cls ),
					esc_html( mv_eur( $r['diff'] ) ),
					esc_attr( $r['kum'] < -0.005 ? 'mv-neg' : '' ),
					esc_html( mv_eur( $r['kum'] ) )
				);
			}
			printf(
				'</tbody><tfoot><tr><th></th><th>' . esc_html__( 'Gesamt', 'mietverwaltung' ) . '</th><th></th><th></th><th></th><th>%s</th><th>%s</th><th class="%s">%s</th><th></th></tr></tfoot></table>',
				esc_html( mv_eur( $op['soll'] ) ),
				esc_html( mv_eur( $op['ist'] ) ),
				esc_attr( $saldo_class ),
				esc_html( mv_eur( $op['saldo'] ) )
			);
		}
		if ( $offen_gesamt ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Markierte als bezahlt buchen', 'mietverwaltung' ) . '</button></p>';
		}
		echo '</form></div>';
	}
}
