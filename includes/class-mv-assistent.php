<?php
/**
 * Einrichtungsassistent: fünf Schritte vom leeren Plugin zum ersten Mietverhältnis mit Konditionen.
 * Jeder Schritt speichert sofort; Fortschritt und angelegte IDs liegen in der Option mietv_assistent.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			esc_html__( 'Willkommen in der Mietverwaltung.', 'mietverwaltung' ),
			esc_html__( 'Der Einrichtungsassistent legt in fünf Schritten Vermieter, Objekt, Einheit, Mieter und Mietverhältnis an.', 'mietverwaltung' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Assistent starten', 'mietverwaltung' )
		);
	}

	public static function schritte(): array {
		return [
			1 => __( 'Vermieter', 'mietverwaltung' ),
			2 => __( 'Objekt', 'mietverwaltung' ),
			3 => __( 'Einheiten', 'mietverwaltung' ),
			4 => __( 'Mieter', 'mietverwaltung' ),
			5 => __( 'Mietverhältnis', 'mietverwaltung' ),
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
					$fehler[] = __( 'Name des Vermieters fehlt.', 'mietverwaltung' );
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
					$fehler[] = __( 'Bezeichnung und Straße sind Pflicht.', 'mietverwaltung' );
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
					$fehler[] = __( 'Bitte zuerst das Objekt anlegen.', 'mietverwaltung' );
					break;
				}
				if ( $t( 'einheit' ) === '' || mietv_to_float( $t( 'flaeche' ) ) <= 0 ) {
					$fehler[] = __( 'Bezeichnung und Wohnfläche der Wohnung sind Pflicht.', 'mietverwaltung' );
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
					$fehler[] = __( 'Nachname des Mieters fehlt.', 'mietverwaltung' );
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
					$fehler[] = __( 'Einheit und Mieter müssen vorher angelegt sein.', 'mietverwaltung' );
					break;
				}
				$beginn = $t( 'beginn' );
				$kalt   = mietv_to_float( $t( 'kaltmiete' ) );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $beginn ) || $kalt <= 0 ) {
					$fehler[] = __( 'Mietbeginn und Kaltmiete (größer 0) sind Pflicht.', 'mietverwaltung' );
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

		echo '<div class="wrap mv-wrap mv-assistent"><h1>' . esc_html__( 'Einrichtungsassistent', 'mietverwaltung' ) . '</h1>';
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
				echo '<tr><td colspan="2"><p>' . esc_html__( 'Diese Angaben erscheinen als Absender auf Mietvertrag, Abrechnungen und Schreiben.', 'mietverwaltung' ) . '</p></td></tr>';
				$f( 'vermieter_name', __( 'Name', 'mietverwaltung' ), $o['vermieter_name'], 'text', '', true );
				printf( '<tr><th><label for="mietv_vermieter_adresse">%s</label></th><td><textarea id="mietv_vermieter_adresse" name="vermieter_adresse" rows="3" class="regular-text">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Anschrift', 'mietverwaltung' ), esc_textarea( $o['vermieter_adresse'] ), esc_html__( 'Straße und Ort in zwei Zeilen.', 'mietverwaltung' ) );
				$f( 'vermieter_email', __( 'E-Mail', 'mietverwaltung' ), $o['vermieter_email'], 'email' );
				$f( 'vermieter_telefon', __( 'Telefon', 'mietverwaltung' ), $o['vermieter_telefon'] );
				$f( 'vermieter_iban', __( 'IBAN (Mietkonto)', 'mietverwaltung' ), $o['vermieter_iban'], 'text', __( 'Für Zahlungsaufforderungen und SEPA-Mandat.', 'mietverwaltung' ) );
				$f( 'vermieter_bank', __( 'Bank', 'mietverwaltung' ), $o['vermieter_bank'] );
				$f( 'vermieter_steuernr', __( 'Steuernummer (optional)', 'mietverwaltung' ), $o['vermieter_steuernr'] );
				break;

			case 2:
				$ob = ! empty( $stand['objekt_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte WHERE id = %d', $stand['objekt_id'] ) ) : null;
				echo '<tr><td colspan="2"><p>' . esc_html__( 'Ein Objekt ist das Haus oder die Eigentumswohnung. Das Baujahr bestimmt den AfA-Satz, die Heizungsart die Heizkostenverteilung.', 'mietverwaltung' ) . '</p></td></tr>';
				$f( 'bezeichnung', __( 'Bezeichnung', 'mietverwaltung' ), $ob->bezeichnung ?? '', 'text', __( 'z. B. „ETW Musterstraße 1“', 'mietverwaltung' ), true );
				$f( 'strasse', __( 'Straße / Nr.', 'mietverwaltung' ), $ob->strasse ?? '', 'text', '', true );
				$f( 'plz', __( 'PLZ', 'mietverwaltung' ), $ob->plz ?? '' );
				$f( 'ort', __( 'Ort', 'mietverwaltung' ), $ob->ort ?? '' );
				$f( 'baujahr', __( 'Baujahr', 'mietverwaltung' ), (string) ( $ob->baujahr ?? '' ), 'number', __( 'Vor 1925: 2,5 % AfA, ab 2023: 3 %, sonst 2 %.', 'mietverwaltung' ) );
				$sel(
					'heizung',
					__( 'Heizung', 'mietverwaltung' ),
					[
						'zentral'    => esc_html__( 'Zentralheizung (HeizkostenV, verbrauchsabhängig)', 'mietverwaltung' ),
						'fernwaerme' => esc_html__( 'Fernwärme (HeizkostenV)', 'mietverwaltung' ),
						'dezentral'  => esc_html__( 'Dezentral / Etagenheizung (Mieter rechnet selbst ab)', 'mietverwaltung' ),
					],
					$ob->heizung ?? 'zentral'
				);
				break;

			case 3:
				$e = ! empty( $stand['einheit_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['einheit_id'] ) ) : null;
				$g = ! empty( $stand['garage_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['garage_id'] ) ) : null;
				echo '<tr><td colspan="2"><p>' . esc_html__( 'Die Wohnung ist die Haupteinheit. Garage oder Stellplatz können gleich mit angelegt und im nächsten Schritt demselben Vertrag zugeordnet werden.', 'mietverwaltung' ) . '</p></td></tr>';
				$f( 'einheit', __( 'Wohnung – Bezeichnung', 'mietverwaltung' ), $e->bezeichnung ?? __( 'Wohnung', 'mietverwaltung' ), 'text', __( 'z. B. „Wohnung EG links“', 'mietverwaltung' ), true );
				$f( 'lage', __( 'Lage', 'mietverwaltung' ), $e->lage ?? '', 'text', __( 'z. B. „1. OG“', 'mietverwaltung' ) );
				$f( 'flaeche', __( 'Wohnfläche (m²)', 'mietverwaltung' ), isset( $e->flaeche ) ? number_format( (float) $e->flaeche, 2, ',', '' ) : '', 'text', __( 'Wird für die Betriebskostenabrechnung benötigt.', 'mietverwaltung' ), true );
				$f( 'zimmer', __( 'Zimmer', 'mietverwaltung' ), isset( $e->zimmer ) ? number_format( (float) $e->zimmer, 1, ',', '' ) : '' );
				echo '<tr><th colspan="2" style="padding-bottom:0"><h3 style="margin:10px 0 0">' . esc_html__( 'Garage / Stellplatz (optional)', 'mietverwaltung' ) . '</h3></th></tr>';
				$f( 'garage', __( 'Bezeichnung', 'mietverwaltung' ), $g->bezeichnung ?? '', 'text', __( 'Leer lassen, wenn keine Garage mitvermietet wird.', 'mietverwaltung' ) );
				$sel(
					'garage_typ',
					__( 'Typ', 'mietverwaltung' ),
					[
						'garage'     => esc_html__( 'Garage', 'mietverwaltung' ),
						'stellplatz' => esc_html__( 'Stellplatz', 'mietverwaltung' ),
					],
					$g->typ ?? 'garage'
				);
				$f( 'garage_anteil', __( 'Mietanteil Garage (€)', 'mietverwaltung' ), isset( $stand['garage_anteil'] ) ? number_format( (float) $stand['garage_anteil'], 2, ',', '' ) : '', 'text', __( 'Nur zur Ausweisung im Vertrag; die Gesamtmiete wird im nächsten Schritt erfasst.', 'mietverwaltung' ) );
				break;

			case 4:
				$m       = ! empty( $stand['mieter_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $stand['mieter_id'] ) ) : null;
				$anreden = [
					''     => '–',
					'Frau' => esc_html__( 'Frau', 'mietverwaltung' ),
					'Herr' => esc_html__( 'Herr', 'mietverwaltung' ),
				];
				$sel( 'anrede', __( 'Anrede', 'mietverwaltung' ), $anreden + [ 'Firma' => __( 'Firma', 'mietverwaltung' ) ], $m->anrede ?? '' );
				$f( 'vorname', __( 'Vorname', 'mietverwaltung' ), $m->vorname ?? '' );
				$f( 'nachname', __( 'Nachname', 'mietverwaltung' ), $m->nachname ?? '', 'text', '', true );
				$f( 'geburtsdatum', __( 'Geburtsdatum', 'mietverwaltung' ), $m->geburtsdatum ?? '', 'date', __( 'Für den Mietvertrag.', 'mietverwaltung' ) );
				$f( 'email', __( 'E-Mail', 'mietverwaltung' ), $m->email ?? '', 'email' );
				$f( 'telefon', __( 'Telefon', 'mietverwaltung' ), $m->telefon ?? '' );
				$f( 'anschrift_alt', __( 'Bisherige Anschrift', 'mietverwaltung' ), $m->anschrift_alt ?? '', 'text', __( 'Anschrift vor Einzug – erscheint im Vertrag.', 'mietverwaltung' ) );
				$f( 'iban', __( 'IBAN des Mieters', 'mietverwaltung' ), $m->iban ?? '', 'text', __( 'Für die automatische Zuordnung beim Bankimport.', 'mietverwaltung' ) );
				echo '<tr><th colspan="2" style="padding-bottom:0"><h3 style="margin:10px 0 0">' . esc_html__( 'Ehe-/Lebenspartner als Mitmieter (optional)', 'mietverwaltung' ) . '</h3><p class="description" style="font-weight:normal">' . esc_html__( 'Erscheint automatisch als zweite Vertragspartei in Vertrag, Schreiben, SEPA-Mandat und Übergabeprotokoll.', 'mietverwaltung' ) . '</p></th></tr>';
				$sel( 'p_anrede', __( 'Anrede', 'mietverwaltung' ), $anreden, $m->p_anrede ?? '' );
				$f( 'p_vorname', __( 'Vorname', 'mietverwaltung' ), $m->p_vorname ?? '' );
				$f( 'p_nachname', __( 'Nachname', 'mietverwaltung' ), $m->p_nachname ?? '' );
				$f( 'p_geburtsdatum', __( 'Geburtsdatum', 'mietverwaltung' ), $m->p_geburtsdatum ?? '', 'date' );
				$f( 'p_email', __( 'E-Mail', 'mietverwaltung' ), $m->p_email ?? '', 'email' );
				break;

			case 5:
				$mv      = ! empty( $stand['mv_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $stand['mv_id'] ) ) : null;
				$k       = $mv ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_konditionen WHERE mv_id = %d ORDER BY gueltig_ab DESC LIMIT 1', $mv->id ) ) : null;
				$mieter  = ! empty( $stand['mieter_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $stand['mieter_id'] ) ) : null;
				$einheit = ! empty( $stand['einheit_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT bezeichnung FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['einheit_id'] ) ) : null;
				printf( '<tr><td colspan="2"><p>%s <strong>%s</strong> → <strong>%s</strong></p></td></tr>', esc_html__( 'Mietverhältnis:', 'mietverwaltung' ), esc_html( mietv_mieter_name( $mieter ) ), esc_html( $einheit->bezeichnung ?? '–' ) );
				$f( 'beginn', __( 'Mietbeginn', 'mietverwaltung' ), $mv->beginn ?? '', 'date', '', true );
				$f( 'kaltmiete', __( 'Kaltmiete gesamt (€)', 'mietverwaltung' ), isset( $k->kaltmiete ) ? number_format( (float) $k->kaltmiete, 2, ',', '' ) : '', 'text', __( 'Inklusive Garage/Stellplatz, falls mitvermietet.', 'mietverwaltung' ), true );
				$f( 'bk_vz', __( 'Betriebskosten-Vorauszahlung (€)', 'mietverwaltung' ), isset( $k->bk_vz ) ? number_format( (float) $k->bk_vz, 2, ',', '' ) : '' );
				$f( 'hk_vz', __( 'Heizkosten-Vorauszahlung (€)', 'mietverwaltung' ), isset( $k->hk_vz ) ? number_format( (float) $k->hk_vz, 2, ',', '' ) : '', 'text', __( 'Bei dezentraler Heizung 0.', 'mietverwaltung' ) );
				$f( 'kaution', __( 'Kaution (€)', 'mietverwaltung' ), isset( $mv->kaution ) ? number_format( (float) $mv->kaution, 2, ',', '' ) : '', 'text', __( 'Höchstens drei Kaltmieten (§ 551 BGB).', 'mietverwaltung' ) );
				$f( 'personen', __( 'Personen im Haushalt', 'mietverwaltung' ), (string) ( $mv->personen ?? ( mietv_hat_partner( $mieter ) ? 2 : 1 ) ), 'number' );
				$f( 'zahltag', __( 'Zahltag (Tag im Monat)', 'mietverwaltung' ), (string) ( $mv->zahltag ?? 3 ), 'number' );
				if ( ! empty( $stand['garage_id'] ) ) {
					$g = $wpdb->get_var( $wpdb->prepare( 'SELECT bezeichnung FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $stand['garage_id'] ) );
					printf( '<tr><th>%s</th><td><label><input type="checkbox" name="mit_garage" value="1" checked> %s</label></td></tr>', esc_html__( 'Zusatzeinheit', 'mietverwaltung' ), esc_html( sprintf( /* translators: %s: Wert */ __( '„%s“ gehört zu diesem Vertrag (eine Gesamtmiete, eine Buchung)', 'mietverwaltung' ), $g ) ) );
				}
				break;
		}
		echo '</table>';

		echo '<p class="submit">';
		if ( $schritt > 1 ) {
			printf( '<a class="button" href="%s">← %s</a> ', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=' . ( $schritt - 1 ) ) ), esc_html__( 'Zurück', 'mietverwaltung' ) );
		}
		printf( '<button type="submit" class="button button-primary">%s →</button> ', $schritt === 5 ? esc_html__( 'Abschließen', 'mietverwaltung' ) : esc_html__( 'Speichern und weiter', 'mietverwaltung' ) );
		printf( '<button type="submit" name="mietv_ueberspringen" value="1" class="button-link" formnovalidate>%s</button>', esc_html__( 'Assistent beenden, ich lege die Daten selbst an', 'mietverwaltung' ) );
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
		echo '<h2>✅ ' . esc_html__( 'Einrichtung abgeschlossen', 'mietverwaltung' ) . '</h2>';
		if ( ! empty( $stand['mv_id'] ) ) {
			$label = $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(m.nachname, ', ', m.vorname) FROM " . $wpdb->prefix . 'mv_mietverhaeltnisse mv JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id WHERE mv.id = %d', $stand['mv_id'] ) );
			printf( '<p>%s <strong>%s</strong>.</p>', esc_html__( 'Angelegt wurde das Mietverhältnis', 'mietverwaltung' ), esc_html( (string) $label ) );
		}
		echo '<p>' . esc_html__( 'Sinnvolle nächste Schritte:', 'mietverwaltung' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		$links = [
			[ 'mietv_anlagegueter', __( 'Gebäude als Anlagegut mit Kaufpreis, Nebenkosten und Grundanteil erfassen (AfA) – Kaufvertrag-PDF auslesen lassen', 'mietverwaltung' ) ],
			[ 'mietv_darlehen', __( 'Finanzierungsdarlehen anlegen (Zins, Tilgung, Rate)', 'mietverwaltung' ) ],
			[ 'mietv_zaehler', __( 'Zähler der Wohnung anlegen und Anfangsstände erfassen', 'mietverwaltung' ) ],
			[ 'mietv_mietvertrag', __( 'Mietvertrag, SEPA-Mandat und Übergabeprotokoll erzeugen', 'mietverwaltung' ) ],
			[ 'mietv_uebersicht', __( 'Mieteingänge in der Übersicht abhaken oder per Bankimport einlesen', 'mietverwaltung' ) ],
			[ 'mietv_hilfe', __( 'Hilfe mit Kurzbeschreibung aller Module', 'mietverwaltung' ) ],
		];
		foreach ( $links as [ $slug, $text ] ) {
			printf( '<li><a href="%s">%s</a></li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $text ) );
		}
		echo '</ul>';
		printf( '<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=mietv_uebersicht' ) ), esc_html__( 'Zur Übersicht', 'mietverwaltung' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&schritt=1' ) ), esc_html__( 'Angaben noch einmal durchgehen', 'mietverwaltung' ) );
		echo '</div>';
	}
}
