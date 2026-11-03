<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Mieterportal: Shortcode [mietfux_portal] (Alias [mietverwaltung_portal]).
 * Zeigt dem eingeloggten Mieter Mietkonto, Konditionen, Kaution, Dokumente, Zähler und Meldungen;
 * nimmt Zählerstände und Schadens-/Mängelmeldungen entgegen. Optional (Einstellung portal_vollbild)
 * in einer eigenen Seitenvorlage ohne Theme-Kopf und -Fuß.
 */
class Mietv_Portal {

	/** Shortcodes, auf die das Portal reagiert. */
	const SHORTCODES = [ 'mietfux_portal', 'mietverwaltung_portal' ];

	public static function init(): void {
		add_shortcode( 'mietfux_portal', [ __CLASS__, 'shortcode' ] );
		add_shortcode( 'mietverwaltung_portal', [ __CLASS__, 'shortcode' ] ); // Alias aus Vorversionen.
		add_action( 'template_redirect', [ __CLASS__, 'handle_meldung' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_abrechnung' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_zaehler' ] );
		add_filter( 'template_include', [ __CLASS__, 'template_include' ], 99 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
		add_filter( 'show_admin_bar', [ __CLASS__, 'admin_bar' ] );
	}

	/** Im Vollbild-Portal keine Admin-Leiste für Mieter (Verwalter behalten sie). */
	public static function admin_bar( $show ) {
		if ( $show && self::vollbild() && ! current_user_can( Mietv_Admin::CAP ) ) {
			return false;
		}
		return $show;
	}

	/** Enthält die aktuelle Seite den Portal-Shortcode? */
	public static function ist_portalseite(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		foreach ( self::SHORTCODES as $sc ) {
			if ( has_shortcode( (string) $post->post_content, $sc ) ) {
				return true;
			}
		}
		return false;
	}

	/** Vollbild-Vorlage aktiv (Einstellung portal_vollbild, Default 1)? */
	public static function vollbild(): bool {
		return self::ist_portalseite() && (string) Mietv_Einstellungen::get( 'portal_vollbild', '1' ) === '1';
	}

	/** Eigene minimale Seitenvorlage ohne Theme-Kopf/-Fuß. */
	public static function template_include( $template ) {
		if ( self::vollbild() ) {
			return MIETV_PATH . 'includes/class-mv-portal-template.php';
		}
		return $template;
	}

	public static function body_class( array $classes ): array {
		if ( self::vollbild() ) {
			$classes[] = 'mv-portal-body';
		}
		return $classes;
	}

	/** Stylesheet und Skript nur auf der Portalseite laden. */
	public static function assets(): void {
		if ( ! self::ist_portalseite() ) {
			return;
		}
		wp_enqueue_style( 'mietv-portal', MIETV_URL . 'assets/portal.css', [], MIETV_VERSION );
		wp_enqueue_script( 'mietv-portal', MIETV_URL . 'assets/portal.js', [], MIETV_VERSION, true );
	}

	/* ------------------------------------------------------------------ Handler */

	/** Zählerstand aus dem Portal (nur selbst abzulesende Zähler der eigenen Einheit, mit Plausibilität). */
	public static function handle_zaehler(): void {
		if ( ! isset( $_POST['mietv_zaehler_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_zaehler_nonce'] ) ), 'mietv_zaehler' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'No access.', 'mietfux' ) );
		}
		global $wpdb;
		$zaehler_id = isset( $_POST['zaehler_id'] ) ? (int) $_POST['zaehler_id'] : 0;
		$stand      = mietv_to_float( sanitize_text_field( wp_unslash( $_POST['stand'] ?? '' ) ) );
		$datum      = ( sanitize_text_field( wp_unslash( $_POST['datum'] ?? '' ) ) ?: current_time( 'Y-m-d' ) );
		$z          = null;
		foreach ( self::eigene_mv() as $mv ) {
			foreach ( self::zaehler( $mv ) as $kandidat ) {
				if ( (int) $kandidat->id === $zaehler_id ) {
					$z = $kandidat;
				}
			}
		}
		$flag = 'fehler';
		if ( $z && $stand > 0 && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) && $datum <= current_time( 'Y-m-d' ) ) {
			$vorher = $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum <= %s ORDER BY datum DESC LIMIT 1', $z->id, $datum ) );
			if ( $vorher === null || $stand >= (float) $vorher ) {
				$wpdb->insert(
					mietv_table( 'zaehlerstaende' ),
					[
						'zaehler_id' => $z->id,
						'datum'      => $datum,
						'stand'      => $stand,
						'quelle'     => 'mieter',
					]
				);
				wp_mail( get_option( 'admin_email' ), __( '[Mietfux] Meter reading submitted', 'mietfux' ), sprintf( 'Zähler %s (%s): %s am %s', $z->typ, $z->nummer, number_format( $stand, 2, ',', '.' ), mietv_date( $datum ) ) );
				$flag = 'ok';
			} else {
				$flag = 'kleiner';
			}
		}
		mietv_flash_set( 'zaehler_' . $flag );
		wp_safe_redirect( remove_query_arg( [ 'mietv_zaehler', 'mietv_meldung' ] ) );
		exit;
	}

	/** Freigegebene Abrechnung als Schreiben – nur für den eigenen Mieter. */
	public static function handle_abrechnung(): void {
		if ( ! isset( $_GET['mietv_abrechnung'], $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mietv_portal_abrechnung' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'No access.', 'mietfux' ) );
		}
		global $wpdb;
		$erg_id  = (int) $_GET['mietv_abrechnung'];
		$erlaubt = array_map( fn( $mv ) => (int) $mv->id, self::eigene_mv() );
		$mv_id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT mv_id FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis WHERE id = %d AND freigegeben = 1', $erg_id ) );
		if ( ! $mv_id || ! in_array( $mv_id, $erlaubt, true ) ) {
			wp_die( esc_html__( 'No access.', 'mietfux' ) );
		}
		Mietv_Abrechnung::brief( $erg_id, true );
	}

	public static function handle_meldung(): void {
		if ( ! isset( $_POST['mietv_meldung_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_meldung_nonce'] ) ), 'mietv_meldung' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'No access.', 'mietfux' ) );
		}
		global $wpdb;
		$mv_id   = ( isset( $_POST['mv_id'] ) ? (int) $_POST['mv_id'] : 0 );
		$betreff = sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) );
		$text    = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );

		$mv = null;
		foreach ( self::eigene_mv() as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( ! $mv || $betreff === '' ) {
			mietv_flash_set( 'meldung_fehler' );
			wp_safe_redirect( remove_query_arg( 'mietv_meldung' ) );
			exit;
		}

		$wpdb->insert(
			mietv_table( 'meldungen' ),
			[
				'mv_id'   => $mv_id,
				'datum'   => current_time( 'mysql' ),
				'betreff' => $betreff,
				'text'    => $text,
				'status'  => 'offen',
			]
		);

		// Vermieter benachrichtigen.
		wp_mail(
			get_option( 'admin_email' ),
			__( '[Mietfux] New report: ', 'mietfux' ) . $betreff,
			'Mieter: ' . Mietv_Soll::mietv_label( $mv ) . "\n\n" . $text . "\n\nBearbeiten: " . admin_url( 'admin.php?page=mietv_meldungen' )
		);

		mietv_flash_set( 'meldung_ok' );
		wp_safe_redirect( remove_query_arg( 'mietv_meldung' ) );
		exit;
	}

	/* ------------------------------------------------------------------ Daten */

	/** Mietverhältnisse des eingeloggten Mieters. */
	private static function eigene_mv(): array {
		if ( ! is_user_logged_in() ) {
			return [];
		}
		$uid = get_current_user_id();
		return array_values( array_filter( Mietv_Soll::alle_mv(), fn( $mv ) => (int) $mv->wp_user_id === $uid ) );
	}

	/** Objekt zum Mietverhältnis (Adresse, Ablese-Einstellungen). */
	private static function objekt( object $mv ): ?object {
		global $wpdb;
		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT o.* FROM ' . $wpdb->prefix . 'mv_objekte o JOIN ' . $wpdb->prefix . 'mv_einheiten e ON e.objekt_id = o.id WHERE e.id = %d', $mv->einheit_id ) );
		return $o ?: null;
	}

	/**
	 * Zähler, die der Mieter selbst abliest: Spalte ablesung = 'mieter' (Fallback), kein Heizungs-/Warmwasserzähler
	 * bei Ablesedienst (objekt.heizung_ablesung), keine Wasserzähler ohne Verteilung nach Zähler (objekt.wasser_verteilung).
	 */
	private static function zaehler( object $mv ): array {
		global $wpdb;
		$objekt   = self::objekt( $mv );
		$heizung  = $objekt && isset( $objekt->heizung_ablesung ) ? $objekt->heizung_ablesung : 'eigen';
		$wasser   = $objekt && isset( $objekt->wasser_verteilung ) ? $objekt->wasser_verteilung : 'zaehler';
		$einheits = [ (int) $mv->einheit_id ];
		foreach ( Mietv_Soll::zusatzeinheiten( (int) $mv->id ) as $ze ) {
			$einheits[] = (int) $ze->einheit_id;
		}
		$ids  = implode( ',', array_map( 'intval', $einheits ) );
		$rows = $wpdb->get_results( "SELECT z.*, (SELECT CONCAT(stand, '|', datum) FROM " . $wpdb->prefix . 'mv_zaehlerstaende s WHERE s.zaehler_id = z.id ORDER BY datum DESC, id DESC LIMIT 1) AS letzter FROM ' . $wpdb->prefix . 'mv_zaehler z WHERE z.einheit_id IN (' . $ids . ') ORDER BY z.typ, z.nummer' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs sind ganzzahlig (intval).
		$out  = [];
		foreach ( $rows as $z ) {
			$ablesung = isset( $z->ablesung ) ? $z->ablesung : 'mieter';
			if ( $ablesung !== 'mieter' ) {
				continue;
			}
			if ( $heizung === 'ablesedienst' && in_array( $z->typ, [ 'heizung', 'warmwasser' ], true ) ) {
				continue;
			}
			if ( $wasser !== 'zaehler' && in_array( $z->typ, [ 'kaltwasser', 'warmwasser' ], true ) ) {
				continue;
			}
			$out[] = $z;
		}
		return $out;
	}

	/** Bezeichnung und Einheit je Zählertyp. */
	private static function zaehler_typen(): array {
		return [
			'kaltwasser' => [ __( 'Cold water', 'mietfux' ), 'm³' ],
			'warmwasser' => [ __( 'Hot water', 'mietfux' ), 'm³' ],
			'heizung'    => [ __( 'Heating', 'mietfux' ), '' ],
			'strom'      => [ __( 'Electricity', 'mietfux' ), 'kWh' ],
		];
	}

	/** Nächste Fälligkeit (Zahltag des Mietverhältnisses). */
	private static function naechste_faelligkeit( object $mv ): string {
		$tag   = max( 1, min( 28, (int) $mv->zahltag ) );
		$heute = current_time( 'Y-m-d' );
		$monat = substr( $heute, 0, 7 );
		$datum = sprintf( '%s-%02d', $monat, $tag );
		if ( $datum < $heute ) {
			$datum = gmdate( 'Y-m', strtotime( $monat . '-01 +1 month' ) ) . sprintf( '-%02d', $tag );
		}
		if ( ! empty( $mv->ende ) && $mv->ende !== '0000-00-00' && $datum > $mv->ende ) {
			return '';
		}
		return $datum;
	}

	/** Letztes Zahlungsdatum je Monat. */
	private static function zahlungsdaten( int $mv_id ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT monat, MAX(datum) AS datum FROM ' . $wpdb->prefix . 'mv_zahlungen WHERE mv_id = %d GROUP BY monat', $mv_id ) ) as $r ) {
			$out[ substr( (string) $r->monat, 0, 7 ) ] = $r->datum;
		}
		return $out;
	}

	/** Für den Mieter sichtbare Dokumente: eigenes Mietverhältnis oder objektweit (ohne MV). */
	private static function dokumente( object $mv ): array {
		global $wpdb;
		$objekt = self::objekt( $mv );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_dokumente WHERE mieter_sichtbar = 1 AND attachment_id IS NOT NULL AND attachment_id > 0 AND (mv_id = %d OR ((mv_id IS NULL OR mv_id = 0) AND objekt_id = %d)) ORDER BY datum DESC, id DESC', $mv->id, $objekt ? (int) $objekt->id : 0 ) );
	}

	/* ------------------------------------------------------------------ Ausgabe */

	public static function shortcode(): string {
		ob_start();
		echo '<div class="mv-portal">';
		if ( ! is_user_logged_in() ) {
			self::login();
		} else {
			$mvs = self::eigene_mv();
			if ( ! $mvs ) {
				self::kopf( null );
				echo '<main class="mv-main"><div class="mv-card"><div class="mv-b"><p>' . esc_html__( 'No tenancy is assigned to your user account yet. Please contact your landlord.', 'mietfux' ) . '</p></div></div></main>';
			} else {
				self::kopf( $mvs[0] );
				echo '<main class="mv-main">';
				self::flash();
				foreach ( $mvs as $i => $mv ) {
					self::mietverhaeltnis( $mv, count( $mvs ) > 1, $i === 0 );
				}
				echo '</main>';
			}
		}
		self::fuss();
		echo '</div>';
		return ob_get_clean();
	}

	/** Login-Karte für nicht angemeldete Besucher. */
	private static function login(): void {
		self::kopf( null );
		echo '<main class="mv-main"><div class="mv-login"><div class="mv-card"><h2>' . esc_html__( 'Sign in', 'mietfux' ) . '</h2><div class="mv-b">';
		echo '<p class="mv-muted">' . esc_html__( 'Please log in to see your tenant portal.', 'mietfux' ) . '</p>';
		echo wp_kses(
			wp_login_form(
				[
					'echo'           => false,
					'redirect'       => get_permalink(),
					'form_id'        => 'mv-loginform',
					'label_username' => __( 'Username or e-mail', 'mietfux' ),
					'label_password' => __( 'Password', 'mietfux' ),
					'label_remember' => __( 'Stay signed in', 'mietfux' ),
					'label_log_in'   => __( 'Sign in', 'mietfux' ),
				]
			),
			mietv_kses_html_tags()
		);
		echo '<p class="mv-muted mv-small"><a href="' . esc_url( wp_lostpassword_url( get_permalink() ) ) . '">' . esc_html__( 'Forgotten your password?', 'mietfux' ) . '</a></p>';
		echo '</div></div></div></main>';
	}

	/** Kopfbereich: Vermieter, Nutzer, Begrüßung. */
	private static function kopf( ?object $mv ): void {
		$name    = (string) Mietv_Einstellungen::get( 'vermieter_name', get_bloginfo( 'name' ) );
		$initial = mb_strtoupper( mb_substr( trim( $name, " \t\n\r\0\x0B" ), 0, 1 ) ) ?: 'M';
		echo '<header class="mv-header"><div class="mv-top"><div class="mv-brand"><span class="mv-logo" aria-hidden="true">' . esc_html( $initial ) . '</span><div><small>' . esc_html__( 'Tenant portal', 'mietfux' ) . '</small><b>' . esc_html( $name ) . '</b></div></div>';
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			echo '<div class="mv-user"><span>' . esc_html( $u->display_name ) . '</span><a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '">' . esc_html__( 'Log out', 'mietfux' ) . '</a></div>';
		}
		echo '</div>';
		if ( $mv ) {
			$zusatz = ! empty( $mv->zusatz ) ? ' + ' . str_replace( ',', ', ', $mv->zusatz ) : '';
			echo '<div class="mv-hero"><h1>' . sprintf( /* translators: %s: Vorname */ esc_html__( 'Hello %s', 'mietfux' ), esc_html( $mv->vorname ?: $mv->nachname ) ) . '</h1>';
			echo '<p>' . esc_html( $mv->objekt . ' · ' . $mv->einheit . $zusatz ) . ' · ' . sprintf( /* translators: %s: Datum */ esc_html__( 'since %s', 'mietfux' ), esc_html( mietv_date( $mv->beginn ) ) ) . '</p></div>';
		} elseif ( is_user_logged_in() ) {
			echo '<div class="mv-hero"><h1>' . esc_html__( 'Tenant portal', 'mietfux' ) . '</h1></div>';
		} else {
			echo '<div class="mv-hero"><h1>' . esc_html__( 'Tenant portal', 'mietfux' ) . '</h1><p>' . esc_html__( 'Rent account, documents, meter readings and reports – all in one place.', 'mietfux' ) . '</p></div>';
		}
		echo '</header>';
	}

	/** Statusmeldungen nach Redirect. */
	private static function flash(): void {
		$code  = mietv_flash_get()['code'] ?? '';
		$texte = [
			'zaehler_ok'      => [ 'ok', __( 'Meter reading saved. Thank you.', 'mietfux' ) ],
			'zaehler_kleiner' => [ 'err', __( 'The reading is lower than the last one recorded – please check.', 'mietfux' ) ],
			'zaehler_fehler'  => [ 'err', __( 'Meter reading could not be saved.', 'mietfux' ) ],
			'meldung_ok'      => [ 'ok', __( 'Your report has been submitted. Thank you.', 'mietfux' ) ],
			'meldung_fehler'  => [ 'err', __( 'The report could not be saved. Please enter a subject.', 'mietfux' ) ],
		];
		if ( isset( $texte[ $code ] ) ) {
			echo '<p class="mv-notice mv-' . esc_attr( $texte[ $code ][0] ) . '" role="status">' . esc_html( $texte[ $code ][1] ) . '</p>';
		}
	}

	/** Ein Mietverhältnis: Banner, Kacheln, Mietkonto, Meldungen, Aktionen, Dokumente, Zähler, Kontakt. */
	private static function mietverhaeltnis( object $mv, bool $mit_titel, bool $erstes ): void {
		$op     = Mietv_Soll::op_liste( $mv );
		$k      = Mietv_Soll::konditionen( (int) $mv->id, current_time( 'Y-m' ) . '-01' );
		$objekt = self::objekt( $mv );
		$gesamt = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0.0;
		$saldo  = (float) $op['saldo'];
		$zusatz = ! empty( $mv->zusatz ) ? ' + ' . str_replace( ',', ', ', $mv->zusatz ) : '';

		echo '<section class="mv-mv" id="mv-' . (int) $mv->id . '">';
		if ( $mit_titel ) {
			echo '<h2 class="mv-mv-titel">' . esc_html( $mv->objekt . ' · ' . $mv->einheit . $zusatz ) . '</h2>';
		}

		// Status-Banner.
		$iban = (string) Mietv_Einstellungen::get( 'vermieter_iban' );
		if ( $saldo < -0.005 ) {
			$zweck = sprintf( /* translators: %1$s: Einheit, %2$s: Nachname */ __( 'Rent %1$s %2$s', 'mietfux' ), $mv->einheit, $mv->nachname );
			echo '<div class="mv-banner mv-banner-offen" role="status"><div><b>' . sprintf( /* translators: %s: Betrag */ esc_html__( 'Open: %s', 'mietfux' ), esc_html( mietv_eur( abs( $saldo ) ) ) ) . '</b>';
			if ( $iban !== '' ) {
				echo ' <span class="mv-iban" id="mv-iban-' . (int) $mv->id . '">' . esc_html( self::iban_format( $iban ) ) . '</span> · ' . sprintf( /* translators: %s: Verwendungszweck */ esc_html__( 'Reference “%s”', 'mietfux' ), esc_html( $zweck ) );
			}
			echo '</div>';
			if ( $iban !== '' ) {
				echo '<button type="button" class="mv-btn mv-btn-sec" data-copy="' . esc_attr( $iban ) . '" data-target="mv-iban-' . (int) $mv->id . '" data-done="' . esc_attr__( 'Copied', 'mietfux' ) . '">' . esc_html__( 'Copy IBAN', 'mietfux' ) . '</button>';
			}
			echo '</div>';
		} else {
			echo '<div class="mv-banner mv-banner-ok" role="status"><div><b>' . esc_html__( 'Everything paid', 'mietfux' ) . '</b>';
			if ( $saldo > 0.005 ) {
				echo ' · ' . sprintf( /* translators: %s: Betrag */ esc_html__( 'Credit: %s', 'mietfux' ), esc_html( mietv_eur( $saldo ) ) );
			} else {
				echo ' · ' . esc_html__( 'Your rent account is balanced.', 'mietfux' );
			}
			echo '</div></div>';
		}

		// Kacheln.
		echo '<div class="mv-kpis">';
		echo '<div class="mv-kpi"><span>' . esc_html__( 'Current rent / month', 'mietfux' ) . '</span><strong>' . esc_html( $k ? mietv_eur( $gesamt ) : '–' ) . '</strong><small>' . ( $k ? sprintf( /* translators: %1$s: Kaltmiete, %2$s: BK-Vorauszahlung, %3$s: HK-Vorauszahlung */ esc_html__( '%1$s base + %2$s SC + %3$s heating', 'mietfux' ), esc_html( mietv_eur( $k->kaltmiete ) ), esc_html( mietv_eur( $k->bk_vz ) ), esc_html( mietv_eur( $k->hk_vz ) ) ) : esc_html__( 'No rent conditions recorded', 'mietfux' ) ) . '</small></div>';
		$adresse = $objekt ? trim( $objekt->strasse . ', ' . trim( $objekt->plz . ' ' . $objekt->ort, " \t\n\r\0\x0B" ), ', ' ) : '';
		echo '<div class="mv-kpi"><span>' . esc_html__( 'Tenancy', 'mietfux' ) . '</span><strong>' . esc_html( $mv->einheit . $zusatz ) . '</strong><small>' . esc_html( $adresse ?: $mv->objekt ) . ' · ' . sprintf( /* translators: %s: Datum */ esc_html__( 'since %s', 'mietfux' ), esc_html( mietv_date( $mv->beginn ) ) ) . '</small></div>';
		$kaution = Mietv_Kaution::konto( (int) $mv->id );
		if ( $kaution['buchungen'] ) {
			$voll = (float) $mv->kaution > 0 && $kaution['stand'] >= (float) $mv->kaution - 0.005;
			echo '<div class="mv-kpi"><span>' . esc_html__( 'Deposit', 'mietfux' ) . '</span><strong>' . esc_html( mietv_eur( $kaution['stand'] ) ) . '</strong><small>' . ( $voll ? esc_html__( 'paid in full', 'mietfux' ) : sprintf( /* translators: %s: Betrag */ esc_html__( 'of %s agreed', 'mietfux' ), esc_html( mietv_eur( $mv->kaution ) ) ) ) . '</small></div>';
		}
		$faellig = self::naechste_faelligkeit( $mv );
		echo '<div class="mv-kpi"><span>' . esc_html__( 'Next due date', 'mietfux' ) . '</span><strong>' . esc_html( $faellig ? mietv_date( $faellig ) : '–' ) . '</strong><small>' . ( $faellig && $k ? sprintf( /* translators: %s: Betrag */ esc_html__( 'rent %s', 'mietfux' ), esc_html( mietv_eur( $gesamt ) ) ) : esc_html__( 'tenancy ended', 'mietfux' ) ) . '</small></div>';
		echo '</div>';

		echo '<div class="mv-grid"><div class="mv-col">';
		self::mietkonto( $mv, $op );
		self::meldungen( $mv );
		echo '</div><div class="mv-col">';
		self::aktionen( $mv );
		self::dokumente_karte( $mv );
		if ( $erstes ) {
			self::kontakt();
		}
		echo '</div></div></section>';
	}

	/** Mietkonto: 12-Monats-Balken und Tabelle mit Status-Pills. */
	private static function mietkonto( object $mv, array $op ): void {
		$rows  = array_slice( array_reverse( $op['rows'] ), 0, 12 ); // neueste zuerst
		$daten = self::zahlungsdaten( (int) $mv->id );
		$max   = 0.0;
		foreach ( $rows as $r ) {
			$max = max( $max, (float) $r['soll'], (float) $r['ist'] );
		}
		echo '<div class="mv-card"><h2>' . esc_html__( 'Rent account – last 12 months', 'mietfux' ) . ( count( $rows ) > 6 ? '<button type="button" class="mv-link" data-toggle-rows="1" data-less="' . esc_attr__( 'show fewer', 'mietfux' ) . '">' . esc_html__( 'show all', 'mietfux' ) . '</button>' : '' ) . '</h2><div class="mv-b">';
		if ( ! $rows ) {
			echo '<p class="mv-muted">' . esc_html__( 'No months recorded yet.', 'mietfux' ) . '</p></div></div>';
			return;
		}
		echo '<div class="mv-bars" aria-hidden="true">';
		foreach ( array_reverse( $rows ) as $r ) {
			$soll   = (float) $r['soll'];
			$ist    = (float) $r['ist'];
			$h      = $max > 0 ? (int) round( max( $soll, $ist ) / $max * 20 ) * 5 : 0;
			$anteil = $soll > 0 ? (int) round( min( $ist / $soll, 1 ) * 20 ) * 5 : ( $ist > 0 ? 100 : 0 );
			$cls    = self::status_klasse( $soll, $ist );
			echo '<i class="mv-bar mv-h-' . (int) $h . ' mv-bar-' . esc_attr( $cls ) . '" title="' . esc_attr( mietv_monat( $r['monat'] ) . ': ' . mietv_eur( $ist ) . ' / ' . mietv_eur( $soll ) ) . '"><b class="mv-h-' . (int) $anteil . '"></b></i>';
		}
		echo '</div>';
		echo '<table class="mv-table"><thead><tr><th>' . esc_html__( 'Month', 'mietfux' ) . '</th><th class="mv-num">' . esc_html__( 'Due', 'mietfux' ) . '</th><th class="mv-num">' . esc_html__( 'Paid', 'mietfux' ) . '</th><th>' . esc_html__( 'Status', 'mietfux' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			$soll  = (float) $r['soll'];
			$ist   = (float) $r['ist'];
			$cls   = self::status_klasse( $soll, $ist );
			$label = [
				'ok'   => __( 'Paid', 'mietfux' ),
				'teil' => __( 'Partly paid', 'mietfux' ),
				'neg'  => __( 'Outstanding', 'mietfux' ),
				'leer' => '–',
			][ $cls ];
			$dat   = $daten[ substr( $r['monat'], 0, 7 ) ] ?? null;
			if ( $dat && in_array( $cls, [ 'ok', 'teil' ], true ) ) {
				$label .= ' ' . date_i18n( 'd.m.', strtotime( $dat ) );
			}
			echo '<tr' . ( $i >= 6 ? ' class="mv-row-more"' : '' ) . '><td>' . esc_html( mietv_monat( $r['monat'] ) ) . '</td><td class="mv-num">' . esc_html( mietv_eur( $soll ) ) . '</td><td class="mv-num">' . esc_html( mietv_eur( $ist ) ) . '</td><td><span class="mv-pill mv-pill-' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function status_klasse( float $soll, float $ist ): string {
		if ( $soll <= 0.005 && $ist <= 0.005 ) {
			return 'leer';
		}
		if ( $ist >= $soll - 0.005 ) {
			return 'ok';
		}
		return $ist > 0.005 ? 'teil' : 'neg';
	}

	/** Meldungen des Mieters, neueste zuerst. */
	private static function meldungen( object $mv ): void {
		global $wpdb;
		$meldungen = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_meldungen WHERE mv_id = %d ORDER BY datum DESC, id DESC LIMIT 20', $mv->id ) );
		$status    = [
			'offen'          => [ 'open', __( 'Outstanding', 'mietfux' ) ],
			'in_bearbeitung' => [ 'work', __( 'In progress', 'mietfux' ) ],
			'erledigt'       => [ 'ok', __( 'Done', 'mietfux' ) ],
		];
		echo '<div class="mv-card"><h2>' . esc_html__( 'My reports', 'mietfux' ) . '<a href="#mv-meldung-' . (int) $mv->id . '" class="mv-link" data-open="mv-meldung-' . (int) $mv->id . '">+ ' . esc_html__( 'new report', 'mietfux' ) . '</a></h2><div class="mv-b">';
		if ( ! $meldungen ) {
			echo '<p class="mv-muted">' . esc_html__( 'No reports yet.', 'mietfux' ) . '</p>';
		}
		foreach ( $meldungen as $m ) {
			$s = $status[ $m->status ] ?? [ 'open', $m->status ];
			echo '<div class="mv-row"><div><div class="mv-row-titel">' . esc_html( $m->betreff ) . '</div><span class="mv-muted mv-small">' . sprintf( /* translators: %s: Datum */ esc_html__( 'reported %s', 'mietfux' ), esc_html( date_i18n( 'd.m.Y', strtotime( $m->datum ) ) ) ) . ( $m->text !== '' && $m->text !== null ? ' · ' . esc_html( wp_trim_words( $m->text, 14, '…' ) ) : '' ) . '</span></div><span class="mv-pill mv-pill-' . esc_attr( $s[0] ) . '">' . esc_html( $s[1] ) . '</span></div>';
		}
		echo '</div></div>';
	}

	/** Aktionen: Zählerstand melden, Schaden/Anliegen melden (aufklappbare Formulare). */
	private static function aktionen( object $mv ): void {
		$zaehler = self::zaehler( $mv );
		$typen   = self::zaehler_typen();
		$heute   = current_time( 'Y-m-d' );
		echo '<div class="mv-actions">';

		if ( $zaehler ) {
			echo '<details class="mv-card mv-action" id="mv-zaehler-' . (int) $mv->id . '"><summary><span class="mv-ic" aria-hidden="true">▤</span>' . esc_html__( 'Submit meter reading', 'mietfux' ) . '</summary><div class="mv-b">';
			echo '<table class="mv-table"><thead><tr><th>' . esc_html__( 'Meter', 'mietfux' ) . '</th><th class="mv-num">' . esc_html__( 'Last reading', 'mietfux' ) . '</th></tr></thead><tbody>';
			foreach ( $zaehler as $z ) {
				[ $ls, $ld ] = $z->letzter ? explode( '|', $z->letzter ) : [ null, null ];
				$t           = $typen[ $z->typ ] ?? [ $z->typ, '' ];
				echo '<tr><td>' . esc_html( trim( $t[0] . ' ' . $z->nummer, " \t\n\r\0\x0B" ) ) . '</td><td class="mv-num">' . ( $ls !== null ? esc_html( trim( self::zahl( (float) $ls ) . ' ' . $t[1], " \t\n\r\0\x0B" ) ) . '<br><small class="mv-muted">' . esc_html( mietv_date( $ld ) ) . '</small>' : '–' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post" class="mv-form">';
			wp_nonce_field( 'mietv_zaehler', 'mietv_zaehler_nonce' );
			echo '<p><label for="mv-z-id-' . (int) $mv->id . '">' . esc_html__( 'Meter', 'mietfux' ) . '</label><select name="zaehler_id" id="mv-z-id-' . (int) $mv->id . '">';
			foreach ( $zaehler as $z ) {
				$t = $typen[ $z->typ ] ?? [ $z->typ, '' ];
				echo '<option value="' . (int) $z->id . '">' . esc_html( trim( $t[0] . ' ' . $z->nummer, " \t\n\r\0\x0B" ) ) . '</option>';
			}
			echo '</select></p>';
			echo '<div class="mv-form-row"><p><label for="mv-z-stand-' . (int) $mv->id . '">' . esc_html__( 'New reading', 'mietfux' ) . '</label><input type="text" name="stand" id="mv-z-stand-' . (int) $mv->id . '" inputmode="decimal" required></p>';
			echo '<p><label for="mv-z-datum-' . (int) $mv->id . '">' . esc_html__( 'Reading date', 'mietfux' ) . '</label><input type="date" name="datum" id="mv-z-datum-' . (int) $mv->id . '" value="' . esc_attr( $heute ) . '" max="' . esc_attr( $heute ) . '"></p></div>';
			echo '<p><button type="submit" class="mv-btn">' . esc_html__( 'Send meter reading', 'mietfux' ) . '</button></p></form>';
			echo '</div></details>';
		}

		echo '<details class="mv-card mv-action" id="mv-meldung-' . (int) $mv->id . '"><summary><span class="mv-ic" aria-hidden="true">!</span>' . esc_html__( 'Report damage or request', 'mietfux' ) . '</summary><div class="mv-b">';
		echo '<form method="post" class="mv-form">';
		wp_nonce_field( 'mietv_meldung', 'mietv_meldung_nonce' );
		echo '<input type="hidden" name="mv_id" value="' . (int) $mv->id . '">';
		echo '<p><label for="mv-m-betreff-' . (int) $mv->id . '">' . esc_html__( 'Subject', 'mietfux' ) . '</label><input type="text" name="betreff" id="mv-m-betreff-' . (int) $mv->id . '" required maxlength="190"></p>';
		echo '<p><label for="mv-m-text-' . (int) $mv->id . '">' . esc_html__( 'Details', 'mietfux' ) . '</label><textarea name="text" id="mv-m-text-' . (int) $mv->id . '" rows="4"></textarea></p>';
		echo '<p><button type="submit" class="mv-btn">' . esc_html__( 'Submit report', 'mietfux' ) . '</button></p></form>';
		echo '</div></details>';
		echo '</div>';

		if ( ! $zaehler ) {
			echo '<p class="mv-hinweis">' . esc_html__( 'The meters are read by the meter reading service or the landlord.', 'mietfux' ) . '</p>';
		}
	}

	/** Dokumente: freigegebene Dateien und Betriebskostenabrechnungen. */
	private static function dokumente_karte( object $mv ): void {
		global $wpdb;
		$docs = self::dokumente( $mv );
		$abrs = $wpdb->get_results( $wpdb->prepare( 'SELECT e.*, a.von, a.bis FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis e JOIN ' . $wpdb->prefix . 'mv_abrechnungen a ON a.id = e.abrechnung_id WHERE e.mv_id = %d AND e.freigegeben = 1 ORDER BY a.bis DESC', $mv->id ) );
		echo '<div class="mv-card"><h2>' . esc_html__( 'Documents', 'mietfux' ) . '</h2><div class="mv-b">';
		if ( ! $docs && ! $abrs ) {
			echo '<p class="mv-muted">' . esc_html__( 'No documents released yet.', 'mietfux' ) . '</p>';
		}
		foreach ( $abrs as $a ) {
			$titel = sprintf( /* translators: %s: Jahr bzw. Zeitraum */ __( 'Service charge statement %s', 'mietfux' ), substr( $a->von, 0, 4 ) === substr( $a->bis, 0, 4 ) ? substr( $a->bis, 0, 4 ) : mietv_date( $a->von ) . ' – ' . mietv_date( $a->bis ) );
			$info  = $a->saldo < 0 ? sprintf( /* translators: %s: Betrag */ __( 'Amount due %s', 'mietfux' ), mietv_eur( abs( $a->saldo ) ) ) : sprintf( /* translators: %s: Betrag */ __( 'Credit %s', 'mietfux' ), mietv_eur( abs( $a->saldo ) ) );
			echo '<div class="mv-row"><div class="mv-doc"><span class="mv-ic mv-ic-abrechnung" aria-hidden="true">€</span><div><div class="mv-row-titel">' . esc_html( $titel ) . '</div><span class="mv-muted mv-small">' . esc_html( $info ) . '</span></div></div><a class="mv-btn mv-btn-sec" target="_blank" rel="noopener" href="' . esc_url( wp_nonce_url( add_query_arg( 'mietv_abrechnung', (int) $a->id, get_permalink() ), 'mietv_portal_abrechnung' ) ) . '">' . esc_html__( 'Open', 'mietfux' ) . '</a></div>';
		}
		$icons = [
			'mietvertrag'        => '§',
			'uebergabeprotokoll' => '✓',
			'abrechnung'         => '€',
			'versicherung'       => '☂',
			'energieausweis'     => '⚡',
			'teilungserklaerung' => '¶',
		];
		foreach ( $docs as $d ) {
			$url = wp_get_attachment_url( (int) $d->attachment_id );
			if ( ! $url ) {
				continue;
			}
			$ext  = strtoupper( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$meta = trim( ( $ext ? $ext . ' · ' : '' ) . mietv_date( $d->datum ), ' ·' );
			echo '<div class="mv-row"><div class="mv-doc"><span class="mv-ic mv-ic-' . esc_attr( $d->typ ) . '" aria-hidden="true">' . esc_html( $icons[ $d->typ ] ?? '▤' ) . '</span><div><div class="mv-row-titel">' . esc_html( $d->bezeichnung ) . '</div><span class="mv-muted mv-small">' . esc_html( $meta ) . '</span></div></div><a class="mv-btn mv-btn-sec" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'mietfux' ) . '</a></div>';
		}
		echo '</div></div>';
	}

	/** Kontakt-Karte des Vermieters. */
	private static function kontakt(): void {
		$name    = (string) Mietv_Einstellungen::get( 'vermieter_name' );
		$adresse = (string) Mietv_Einstellungen::get( 'vermieter_adresse' );
		$telefon = (string) Mietv_Einstellungen::get( 'vermieter_telefon' );
		$email   = (string) Mietv_Einstellungen::get( 'vermieter_email' );
		if ( $name === '' && $adresse === '' && $telefon === '' && $email === '' ) {
			return;
		}
		echo '<div class="mv-card"><h2>' . esc_html__( 'Your landlord', 'mietfux' ) . '</h2><div class="mv-b mv-contact">';
		if ( $name !== '' ) {
			echo '<b>' . esc_html( $name ) . '</b><br>';
		}
		if ( $adresse !== '' ) {
			echo esc_html( str_replace( [ "\r\n", "\n" ], ', ', trim( $adresse, " \t\n\r\0\x0B" ) ) ) . '<br>';
		}
		$k = [];
		if ( $telefon !== '' ) {
			$k[] = '<a href="' . esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $telefon ) ) . '">' . esc_html( $telefon ) . '</a>';
		}
		if ( $email !== '' ) {
			$k[] = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
		}
		echo wp_kses( implode( ' · ', $k ), mietv_kses_html_tags() );
		echo '</div></div>';
	}

	private static function fuss(): void {
		$ds = get_privacy_policy_url();
		echo '<footer class="mv-footer">' . esc_html__( 'Tenant portal', 'mietfux' ) . ' · Mietfux' . ( $ds ? ' · <a href="' . esc_url( $ds ) . '">' . esc_html__( 'Privacy policy', 'mietfux' ) . '</a>' : '' ) . '</footer>';
	}

	/** IBAN in Vierergruppen. */
	private static function iban_format( string $iban ): string {
		return trim( chunk_split( strtoupper( preg_replace( '/\s+/', '', $iban ) ), 4, ' ' ), " \t\n\r\0\x0B" );
	}

	/** Zählerstand ohne unnötige Nachkommastellen. */
	private static function zahl( float $v ): string {
		$dez = abs( $v - round( $v ) ) < 0.0005 ? 0 : 2;
		return number_format( $v, $dez, ',', '.' );
	}
}
