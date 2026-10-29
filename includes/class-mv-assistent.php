<?php
/**
 * Einrichtungsassistent: fünf Schritte vom leeren Plugin zum ersten Mietverhältnis mit Konditionen.
 * Jeder Schritt speichert sofort; Fortschritt und angelegte IDs liegen in der Option mietv_assistent.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

class Mietv_Assistent {

	const OPTION = 'mietv_assistent';
	const SLUG   = 'mietv_assistent';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'redirect_nach_aktivierung' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_notices', [ __CLASS__, 'hinweis' ] );
	}

	/** Beim Aktivieren gesetzt (Mietv_DB::install). */
	public static function aktiviert(): void {
		set_transient( 'mietv_aktiviert', 1, 60 );
	}

	public static function redirect_nach_aktivierung(): void {
		if ( ! get_transient( 'mietv_aktiviert' ) || wp_doing_ajax() || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		delete_transient( 'mietv_aktiviert' );
		if ( isset( $_GET['activate-multi'] ) || self::fertig() || self::hat_daten() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function fertig(): bool {
		return ! empty( self::stand()['fertig'] );
	}

	public static function hat_daten(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse' ) > 0;
	}

	public static function stand(): array {
		$s = get_option( self::OPTION, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function merken( array $neu ): void {
		update_option( self::OPTION, array_merge( self::stand(), $neu ), false );
	}

	/** Hinweis auf der Übersicht, solange nichts angelegt und der Assistent nicht übersprungen wurde. */
	public static function hinweis(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( $screen->id, 'mietv_uebersicht' ) === false || self::fertig() || self::hat_daten() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Welcome to Mietfux.', 'mietfux' ),
			esc_html__( 'The setup wizard creates landlord, property, unit, tenant and tenancy in five steps.', 'mietfux' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Start wizard', 'mietfux' )
		);
	}

	public static function schritte(): array {
		return [
			1 => __( 'Landlord', 'mietfux' ),
			2 => __( 'Property', 'mietfux' ),
			3 => __( 'Units', 'mietfux' ),
			4 => __( 'Tenants', 'mietfux' ),
			5 => __( 'Tenancy', 'mietfux' ),
		];
	}

	/* ---------------------------------------------------------------- Verarbeitung */

	public static function handle(): void {
		if ( empty( $_POST['mietv_assistent_schritt'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		check_admin_referer( 'mietv_assistent' );
		$schritt = ( isset( $_POST['mietv_assistent_schritt'] ) ? (int) $_POST['mietv_assistent_schritt'] : 0 );
		$p       = wp_unslash( $_POST );
		$t       = fn( string $k ) => sanitize_text_field( $p[ $k ] ?? '' );

		if ( isset( $p['mietv_ueberspringen'] ) ) {
			self::merken( [ 'fertig' => 1 ] );
			wp_safe_redirect( admin_url( 'admin.php?page=mietv_uebersicht' ) );
			exit;
		}

		global $wpdb;
		$fehler = [];
		$stand  = self::stand();

		switch ( $schritt ) {
			case 1:
				if ( $t( 'vermieter_name' ) === '' ) {
					$fehler[] = __( 'Landlord name is missing.', 'mietfux' );
					break;
				}
				$o = array_merge( Mietv_Einstellungen::defaults(), get_option( Mietv_Einstellungen::OPTION, [] ) );
				foreach ( [ 'vermieter_name', 'vermieter_adresse', 'vermieter_telefon', 'vermieter_email', 'vermieter_iban', 'vermieter_bank', 'vermieter_steuernr' ] as $k ) {
					$o[ $k ] = $k === 'vermieter_adresse' ? sanitize_textarea_field( $p[ $k ] ?? '' ) : $t( $k );
				}
				update_option( Mietv_Einstellungen::OPTION, $o );
				break;

			case 2:
				if ( $t( 'bezeichnung' ) === '' || $t( 'strasse' ) === '' ) {
					$fehler[] = __( 'Name and street are required.', 'mietfux' );
					break;
				}
				$daten = [
					'owner_id'    => MIETV_OWNER_ID,
					'bezeichnung' => $t( 'bezeichnung' ),
					'strasse'     => $t( 'strasse' ),
					'plz'         => $t( 'plz' ),
					'ort'         => $t( 'ort' ),
					'baujahr'     => $t( 'baujahr' ) !== '' ? (int) $t( 'baujahr' ) : null,
					'heizung'     => in_array( $t( 'heizung' ), [ 'zentral', 'fernwaerme', 'dezentral' ], true ) ? $t( 'heizung' ) : 'zentral',
					'created_at'  => current_time( 'mysql' ),
				];
				if ( ! empty( $stand['objekt_id'] ) ) {
					unset( $daten['created_at'] );
					$wpdb->update( mietv_table( 'objekte' ), $daten, [ 'id' => (int) $stand['objekt_id'] ] );
				} else {
					$wpdb->insert( mietv_table( 'objekte' ), $daten );
					self::merken( [ 'objekt_id' => (int) $wpdb->insert_id ] );
				}
				break;

			case 3:
				if ( empty( $stand['objekt_id'] ) ) {
					$fehler[] = __( 'Please create the property first.', 'mietfux' );
					break;
				}
				if ( $t( 'einheit' ) === '' || mietv_to_float( $t( 'flaeche' ) ) <= 0 ) {
					$fehler[] = __( 'Name and living area of the flat are required.', 'mietfux' );
					break;
				}
				$wohnung = [
					'objekt_id'   => (int) $stand['objekt_id'],
					'bezeichnung' => $t( 'einheit' ),
					'lage'        => $t( 'lage' ),
					'flaeche'     => mietv_to_float( $t( 'flaeche' ) ),
					'zimmer'      => mietv_to_float( $t( 'zimmer' ) ),
					'typ'         => 'wohnung',
				];
				if ( ! empty( $stand['einheit_id'] ) ) {
					$wpdb->update( mietv_table( 'einheiten' ), $wohnung, [ 'id' => (int) $stand['einheit_id'] ] );
				} else {
					$wpdb->insert( mietv_table( 'einheiten' ), $wohnung );
					self::merken( [ 'einheit_id' => (int) $wpdb->insert_id ] );
				}
				if ( $t( 'garage' ) !== '' ) {
					$garage = [
						'objekt_id'   => (int) $stand['objekt_id'],
						'bezeichnung' => $t( 'garage' ),
						'flaeche'     => 0,
						'zimmer'      => 0,
						'typ'         => $t( 'garage_typ' ) === 'stellplatz' ? 'stellplatz' : 'garage',
					];
					$stand  = self::stand();
					if ( ! empty( $stand['garage_id'] ) ) {
						$wpdb->update( mietv_table( 'einheiten' ), $garage, [ 'id' => (int) $stand['garage_id'] ] );
					} else {
						$wpdb->insert( mietv_table( 'einheiten' ), $garage );
						self::merken( [ 'garage_id' => (int) $wpdb->insert_id ] );
					}
					self::merken( [ 'garage_anteil' => mietv_to_float( $t( 'garage_anteil' ) ) ] );
				}
				break;

			case 4:
				if ( $t( 'nachname' ) === '' ) {
					$fehler[] = __( 'Tenant\'s last name is missing.', 'mietfux' );
					break;
				}
				$m = [
					'owner_id'       => MIETV_OWNER_ID,
					'anrede'         => $t( 'anrede' ),
					'vorname'        => $t( 'vorname' ),
					'nachname'       => $t( 'nachname' ),
					'email'          => sanitize_email( $p['email'] ?? '' ),
					'telefon'        => $t( 'telefon' ),
					'geburtsdatum'   => $t( 'geburtsdatum' ) ?: null,
					'anschrift_alt'  => $t( 'anschrift_alt' ),
					'p_anrede'       => $t( 'p_anrede' ),
					'p_vorname'      => $t( 'p_vorname' ),
					'p_nachname'     => $t( 'p_nachname' ),
					'p_geburtsdatum' => $t( 'p_geburtsdatum' ) ?: null,
					'p_email'        => sanitize_email( $p['p_email'] ?? '' ),
					'iban'           => strtoupper( str_replace( ' ', '', $t( 'iban' ) ) ),
				];
				if ( ! empty( $stand['mieter_id'] ) ) {
					$wpdb->update( mietv_table( 'mieter' ), $m, [ 'id' => (int) $stand['mieter_id'] ] );
				} else {
					$wpdb->insert( mietv_table( 'mieter' ), $m );
					self::merken( [ 'mieter_id' => (int) $wpdb->insert_id ] );
				}
				break;

			case 5:
				if ( empty( $stand['einheit_id'] ) || empty( $stand['mieter_id'] ) ) {
					$fehler[] = __( 'Unit and tenant must be created first.', 'mietfux' );
					break;
				}
				$beginn = $t( 'beginn' );
				$kalt   = mietv_to_float( $t( 'kaltmiete' ) );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $beginn ) || $kalt <= 0 ) {
					$fehler[] = __( 'Start of tenancy and base rent (greater than 0) are required.', 'mietfux' );
					break;
				}
				$zahltag = max( 1, min( 28, (int) $t( 'zahltag' ) ?: 3 ) );
				$mv      = [
					'einheit_id' => (int) $stand['einheit_id'],
					'mieter_id'  => (int) $stand['mieter_id'],
					'beginn'     => $beginn,
					'ende'       => null,
					'kaution'    => mietv_to_float( $t( 'kaution' ) ),
					'personen'   => max( 1, (int) $t( 'personen' ) ?: 1 ),
					'zahltag'    => $zahltag,
				];
				if ( ! empty( $stand['mv_id'] ) ) {
					$wpdb->update( mietv_table( 'mietverhaeltnisse' ), $mv, [ 'id' => (int) $stand['mv_id'] ] );
					$mv_id = (int) $stand['mv_id'];
					$wpdb->delete( mietv_table( 'konditionen' ), [ 'mv_id' => $mv_id ] );
					$wpdb->delete( mietv_table( 'mv_einheiten' ), [ 'mv_id' => $mv_id ] );
				} else {
					$wpdb->insert( mietv_table( 'mietverhaeltnisse' ), $mv );
					$mv_id = (int) $wpdb->insert_id;
					self::merken( [ 'mv_id' => $mv_id ] );
				}
				$wpdb->insert(
					mietv_table( 'konditionen' ),
					[
						'mv_id'      => $mv_id,
						'gueltig_ab' => $beginn,
						'kaltmiete'  => $kalt,
						'bk_vz'      => mietv_to_float( $t( 'bk_vz' ) ),
						'hk_vz'      => mietv_to_float( $t( 'hk_vz' ) ),
					]
				);
				if ( ! empty( $stand['garage_id'] ) && ! empty( $p['mit_garage'] ) ) {
					$wpdb->insert(
						mietv_table( 'mv_einheiten' ),
						[
							'mv_id'      => $mv_id,
							'einheit_id' => (int) $stand['garage_id'],
							'mietanteil' => (float) ( $stand['garage_anteil'] ?? 0 ),
						]
					);
				}
				self::merken( [ 'fertig' => 1 ] );
				break;
		}

		if ( $fehler ) {
			set_transient( 'mietv_assistent_fehler_' . get_current_user_id(), $fehler, 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=' . $schritt ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=' . ( $schritt + 1 ) ) );
		}
		exit;
	}

	/* ---------------------------------------------------------------- Anzeige */

	public static function page(): void {
		global $wpdb;
		$stand   = self::stand();
		$schritt = isset( $_GET['schritt'] ) ? max( 1, min( 6, ( isset( $_GET['schritt'] ) ? (int) $_GET['schritt'] : 0 ) ) ) : self::naechster_schritt( $stand ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$fehler  = get_transient( 'mietv_assistent_fehler_' . get_current_user_id() );
		delete_transient( 'mietv_assistent_fehler_' . get_current_user_id() );

		echo '<div class="wrap mv-wrap mv-assistent"><h1>' . esc_html__( 'Setup wizard', 'mietfux' ) . '</h1>';
		echo '<ol class="mv-schritte">';
		foreach ( self::schritte() as $n => $label ) {
			$cls = $n < $schritt ? 'erledigt' : ( $n === $schritt ? 'aktiv' : '' );
			printf( '<li class="%s"><a href="%s"><span>%d</span> %s</a></li>', esc_attr( $cls ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=' . $n ) ), (int) $n, esc_html( $label ) );
		}
		echo '</ol>';
		if ( $fehler ) {
			echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', (array) $fehler ) ) . '</p></div>';
		}

		if ( $schritt === 6 ) {
			self::zusammenfassung( $stand );
			echo '</div>';
			return;
		}

		echo '<form method="post" class="mv-assistent-form">';
		wp_nonce_field( 'mietv_assistent' );
		printf( '<input type="hidden" name="mietv_assistent_schritt" value="%d">', (int) $schritt );
		$f   = fn( string $name, string $label, string $wert = '', string $typ = 'text', string $hint = '', bool $req = false ) => printf(
			'<tr><th><label for="mietv_%1$s">%2$s%6$s</label></th><td><input type="%4$s" id="mietv_%1$s" name="%1$s" value="%3$s" class="regular-text"%7$s>%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $wert ),
			esc_attr( $typ ),
			$hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '',
			$req ? ' <span class="mv-req">*</span>' : '',
			$req ? ' required' : ''
		);
		$sel = function ( string $name, string $label, array $opts, string $wert = '', string $hint = '' ) {
			printf( '<tr><th><label for="mietv_%1$s">%2$s</label></th><td><select id="mietv_%1$s" name="%1$s">', esc_attr( $name ), esc_html( $label ) );
			foreach ( $opts as $k => $v ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $wert, $k, false ), esc_html( $v ) );
			}
			echo '</select>' . ( $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' ) . '</td></tr>';
		};

		echo '<table class="form-table">';
		switch ( $schritt ) {
			case 1:
				$o = array_merge( Mietv_Einstellungen::defaults(), get_option( Mietv_Einstellungen::OPTION, [] ) );
				echo '<tr><td colspan="2"><p>' . esc_html__( 'These details appear as sender on the tenancy agreement, statements and letters.', 'mietfux' ) . '</p></td></tr>';
				$f( 'vermieter_name', __( 'Name', 'mietfux' ), $o['vermieter_name'], 'text', '', true );
				printf( '<tr><th><label for="mietv_vermieter_adresse">%s</label></th><td><textarea id="mietv_vermieter_adresse" name="vermieter_adresse" rows="3" class="regular-text">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Address', 'mietfux' ), esc_textarea( $o['vermieter_adresse'] ), esc_html__( 'Street and town on two lines.', 'mietfux' ) );
				$f( 'vermieter_email', __( 'Email', 'mietfux' ), $o['vermieter_email'], 'email' );
				$f( 'vermieter_telefon', __( 'Phone', 'mietfux' ), $o['vermieter_telefon'] );
				$f( 'vermieter_iban', __( 'IBAN (rent account)', 'mietfux' ), $o['vermieter_iban'], 'text', __( 'For payment requests and SEPA mandate.', 'mietfux' ) );
				$f( 'vermieter_bank', __( 'Bank', 'mietfux' ), $o['vermieter_bank'] );
				$f( 'vermieter_steuernr', __( 'Tax number (optional)', 'mietfux' ), $o['vermieter_steuernr'] );
				break;

			case 2:
				$ob = ! empty( $stand['objekt_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte WHERE id = %d', $stand['objekt_id'] ) ) : null;
				echo '<tr><td colspan="2"><p>' . esc_html__( 'A property is the house or condominium. The year built determines the depreciation rate, the heating type the heating cost allocation.', 'mietfux' ) . '</p></td></tr>';
				$f( 'bezeichnung', __( 'Name', 'mietfux' ), $ob->bezeichnung ?? '', 'text', __( 'e.g. “Condo, 1 Sample Street”', 'mietfux' ), true );
				$f( 'strasse', __( 'Street / no.', 'mietfux' ), $ob->strasse ?? '', 'text', '', true );
				$f( 'plz', __( 'Postcode', 'mietfux' ), $ob->plz ?? '' );
				$f( 'ort', __( 'Town', 'mietfux' ), $ob->ort ?? '' );
				$f( 'baujahr', __( 'Year built', 'mietfux' ), (string) ( $ob->baujahr ?? '' ), 'number', __( 'Before 1925: 2.5 % depreciation, from 2023: 3 %, otherwise 2 %.', 'mietfux' ) );
				$sel(
					'heizung',
					__( 'Heating', 'mietfux' ),
					[
						'zentral'    => esc_html__( 'Central heating (HeizkostenV, consumption-based)', 'mietfux' ),
						'fernwaerme' => esc_html__( 'District heating (HeizkostenV)', 'mietfux' ),
						'dezentral'  => esc_html__( 'Decentralised / per-flat heating (tenant settles directly)', 'mietfux' ),
					],
					$ob->heizung ?? 'zentral'
				);
				break;

			case 3:
				$e = ! empty( $stand['einheit_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['einheit_id'] ) ) : null;
				$g = ! empty( $stand['garage_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['garage_id'] ) ) : null;
				echo '<tr><td colspan="2"><p>' . esc_html__( 'The flat is the main unit. A garage or parking space can be created at the same time and assigned to the same agreement in the next step.', 'mietfux' ) . '</p></td></tr>';
				$f( 'einheit', __( 'Flat – name', 'mietfux' ), $e->bezeichnung ?? __( 'Flat', 'mietfux' ), 'text', __( 'e.g. “Ground floor flat, left”', 'mietfux' ), true );
				$f( 'lage', __( 'Location', 'mietfux' ), $e->lage ?? '', 'text', __( 'e.g. “1st floor”', 'mietfux' ) );
				$f( 'flaeche', __( 'Living area (m²)', 'mietfux' ), isset( $e->flaeche ) ? number_format( (float) $e->flaeche, 2, ',', '' ) : '', 'text', __( 'Needed for the service charge statement.', 'mietfux' ), true );
				$f( 'zimmer', __( 'Rooms', 'mietfux' ), isset( $e->zimmer ) ? number_format( (float) $e->zimmer, 1, ',', '' ) : '' );
				echo '<tr><th colspan="2" style="padding-bottom:0"><h3 style="margin:10px 0 0">' . esc_html__( 'Garage / parking space (optional)', 'mietfux' ) . '</h3></th></tr>';
				$f( 'garage', __( 'Name', 'mietfux' ), $g->bezeichnung ?? '', 'text', __( 'Leave empty if no garage is let with the flat.', 'mietfux' ) );
				$sel(
					'garage_typ',
					__( 'Type', 'mietfux' ),
					[
						'garage'     => esc_html__( 'Garage', 'mietfux' ),
						'stellplatz' => esc_html__( 'Parking space', 'mietfux' ),
					],
					$g->typ ?? 'garage'
				);
				$f( 'garage_anteil', __( 'Garage rent share (€)', 'mietfux' ), isset( $stand['garage_anteil'] ) ? number_format( (float) $stand['garage_anteil'], 2, ',', '' ) : '', 'text', __( 'Shown in the agreement only; the total rent is entered in the next step.', 'mietfux' ) );
				break;

			case 4:
				$m       = ! empty( $stand['mieter_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $stand['mieter_id'] ) ) : null;
				$anreden = [
					''     => '–',
					'Frau' => esc_html__( 'Ms', 'mietfux' ),
					'Herr' => esc_html__( 'Mr', 'mietfux' ),
				];
				$sel( 'anrede', __( 'Salutation', 'mietfux' ), $anreden + [ 'Firma' => __( 'Company', 'mietfux' ) ], $m->anrede ?? '' );
				$f( 'vorname', __( 'First name', 'mietfux' ), $m->vorname ?? '' );
				$f( 'nachname', __( 'Last name', 'mietfux' ), $m->nachname ?? '', 'text', '', true );
				$f( 'geburtsdatum', __( 'Date of birth', 'mietfux' ), $m->geburtsdatum ?? '', 'date', __( 'For the tenancy agreement.', 'mietfux' ) );
				$f( 'email', __( 'Email', 'mietfux' ), $m->email ?? '', 'email' );
				$f( 'telefon', __( 'Phone', 'mietfux' ), $m->telefon ?? '' );
				$f( 'anschrift_alt', __( 'Previous address', 'mietfux' ), $m->anschrift_alt ?? '', 'text', __( 'Address before moving in – appears in the agreement.', 'mietfux' ) );
				$f( 'iban', __( 'Tenant\'s IBAN', 'mietfux' ), $m->iban ?? '', 'text', __( 'For automatic matching in the bank import.', 'mietfux' ) );
				echo '<tr><th colspan="2" style="padding-bottom:0"><h3 style="margin:10px 0 0">' . esc_html__( 'Spouse/partner as co-tenant (optional)', 'mietfux' ) . '</h3><p class="description" style="font-weight:normal">' . esc_html__( 'Appears automatically as second contracting party in the agreement, letters, SEPA mandate and handover protocol.', 'mietfux' ) . '</p></th></tr>';
				$sel( 'p_anrede', __( 'Salutation', 'mietfux' ), $anreden, $m->p_anrede ?? '' );
				$f( 'p_vorname', __( 'First name', 'mietfux' ), $m->p_vorname ?? '' );
				$f( 'p_nachname', __( 'Last name', 'mietfux' ), $m->p_nachname ?? '' );
				$f( 'p_geburtsdatum', __( 'Date of birth', 'mietfux' ), $m->p_geburtsdatum ?? '', 'date' );
				$f( 'p_email', __( 'Email', 'mietfux' ), $m->p_email ?? '', 'email' );
				break;

			case 5:
				$mv      = ! empty( $stand['mv_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $stand['mv_id'] ) ) : null;
				$k       = $mv ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_konditionen WHERE mv_id = %d ORDER BY gueltig_ab DESC LIMIT 1', $mv->id ) ) : null;
				$mieter  = ! empty( $stand['mieter_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $stand['mieter_id'] ) ) : null;
				$einheit = ! empty( $stand['einheit_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT bezeichnung FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['einheit_id'] ) ) : null;
				printf( '<tr><td colspan="2"><p>%s <strong>%s</strong> → <strong>%s</strong></p></td></tr>', esc_html__( 'Tenancy:', 'mietfux' ), esc_html( mietv_mieter_name( $mieter ) ), esc_html( $einheit->bezeichnung ?? '–' ) );
				$f( 'beginn', __( 'Start of tenancy', 'mietfux' ), $mv->beginn ?? '', 'date', '', true );
				$f( 'kaltmiete', __( 'Total base rent (€)', 'mietfux' ), isset( $k->kaltmiete ) ? number_format( (float) $k->kaltmiete, 2, ',', '' ) : '', 'text', __( 'Including garage/parking space, if let together.', 'mietfux' ), true );
				$f( 'bk_vz', __( 'Service charge advance (€)', 'mietfux' ), isset( $k->bk_vz ) ? number_format( (float) $k->bk_vz, 2, ',', '' ) : '' );
				$f( 'hk_vz', __( 'Heating advance (€)', 'mietfux' ), isset( $k->hk_vz ) ? number_format( (float) $k->hk_vz, 2, ',', '' ) : '', 'text', __( '0 for decentralised heating.', 'mietfux' ) );
				$f( 'kaution', __( 'Deposit (€)', 'mietfux' ), isset( $mv->kaution ) ? number_format( (float) $mv->kaution, 2, ',', '' ) : '', 'text', __( 'At most three months\' base rent (§ 551 BGB).', 'mietfux' ) );
				$f( 'personen', __( 'Persons in household', 'mietfux' ), (string) ( $mv->personen ?? ( mietv_hat_partner( $mieter ) ? 2 : 1 ) ), 'number' );
				$f( 'zahltag', __( 'Payment day (day of month)', 'mietfux' ), (string) ( $mv->zahltag ?? 3 ), 'number' );
				if ( ! empty( $stand['garage_id'] ) ) {
					$g = $wpdb->get_var( $wpdb->prepare( 'SELECT bezeichnung FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['garage_id'] ) );
					printf( '<tr><th>%s</th><td><label><input type="checkbox" name="mit_garage" value="1" checked> %s</label></td></tr>', esc_html__( 'Additional unit', 'mietfux' ), esc_html( sprintf( /* translators: %s: Wert */ __( '“%s” belongs to this agreement (one total rent, one booking)', 'mietfux' ), $g ) ) );
				}
				break;
		}
		echo '</table>';

		echo '<p class="submit">';
		if ( $schritt > 1 ) {
			printf( '<a class="button" href="%s">← %s</a> ', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=' . ( $schritt - 1 ) ) ), esc_html__( 'Back', 'mietfux' ) );
		}
		printf( '<button type="submit" class="button button-primary">%s →</button> ', $schritt === 5 ? esc_html__( 'Finish', 'mietfux' ) : esc_html__( 'Save and continue', 'mietfux' ) );
		printf( '<button type="submit" name="mietv_ueberspringen" value="1" class="button-link" formnovalidate>%s</button>', esc_html__( 'Close the wizard, I\'ll enter the data myself', 'mietfux' ) );
		echo '</p></form></div>';
	}

	private static function naechster_schritt( array $stand ): int {
		if ( ! empty( $stand['fertig'] ) && ! empty( $stand['mv_id'] ) ) {
			return 6;
		}
		if ( ! empty( $stand['mieter_id'] ) ) {
			return 5;
		}
		if ( ! empty( $stand['einheit_id'] ) ) {
			return 4;
		}
		if ( ! empty( $stand['objekt_id'] ) ) {
			return 3;
		}
		return Mietv_Einstellungen::get( 'vermieter_name' ) ? 2 : 1;
	}

	private static function zusammenfassung( array $stand ): void {
		global $wpdb;
		$mv = ! empty( $stand['mv_id'] ) ? Mietv_Soll::alle_mv()[0] ?? null : null;
		echo '<div class="mv-hilfe-karte" style="max-width:720px">';
		echo '<h2>✅ ' . esc_html__( 'Setup complete', 'mietfux' ) . '</h2>';
		if ( ! empty( $stand['mv_id'] ) ) {
			$label = $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(m.nachname, ', ', m.vorname) FROM " . $wpdb->prefix . 'mv_mietverhaeltnisse mv JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id WHERE mv.id = %d', $stand['mv_id'] ) );
			printf( '<p>%s <strong>%s</strong>.</p>', esc_html__( 'The following tenancy was created:', 'mietfux' ), esc_html( (string) $label ) );
		}
		echo '<p>' . esc_html__( 'Useful next steps:', 'mietfux' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		$links = [
			[ 'mietv_anlagegueter', __( 'Enter the building as an asset with purchase price, incidental costs and land share (depreciation) – let the purchase contract PDF be parsed', 'mietfux' ) ],
			[ 'mietv_darlehen', __( 'Create financing loan (interest, repayment, instalment)', 'mietfux' ) ],
			[ 'mietv_zaehler', __( 'Create the flat\'s meters and record initial readings', 'mietfux' ) ],
			[ 'mietv_mietvertrag', __( 'Generate tenancy agreement, SEPA mandate and handover protocol', 'mietfux' ) ],
			[ 'mietv_uebersicht', __( 'Tick rent receipts in the overview or import them from the bank', 'mietfux' ) ],
			[ 'mietv_hilfe', __( 'Help with a short description of all modules', 'mietfux' ) ],
		];
		foreach ( $links as [ $slug, $text ] ) {
			printf( '<li><a href="%s">%s</a></li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $text ) );
		}
		echo '</ul>';
		printf( '<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=mietv_uebersicht' ) ), esc_html__( 'Go to overview', 'mietfux' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=1' ) ), esc_html__( 'Review the entries again', 'mietfux' ) );
		echo '</div>';
	}
}
