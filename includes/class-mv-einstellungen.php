<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Einstellungen (Vermieterdaten, Erinnerungen, Aufbewahrung) und Backup/Restore.
 */
class Mietv_Einstellungen {

	const OPTION = 'mietv_einstellungen';

	public static function init(): void {
		add_action( 'admin_post_mietv_backup', [ __CLASS__, 'backup' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	public static function get( string $key, $fallback = '' ) {
		$o = get_option( self::OPTION, [] );
		return isset( $o[ $key ] ) && $o[ $key ] !== '' ? $o[ $key ] : $fallback;
	}

	public static function defaults(): array {
		return [
			'vermieter_name'              => '',
			'vermieter_adresse'           => '',
			'vermieter_telefon'           => '',
			'vermieter_email'             => get_option( 'admin_email' ),
			'vermieter_iban'              => '',
			'vermieter_bank'              => '',
			'vermieter_steuernr'          => '',
			'vermieter_vertreter'         => '',
			'erinnerung_aktiv'            => '1',
			'erinnerung_email'            => get_option( 'admin_email' ),
			'aufbewahrung_jahre'          => '10',
			'angespannter_markt'          => '0',
			'mahn_tage_1'                 => '7',
			'mahn_tage_2'                 => '10',
			'mahn_tage_3'                 => '7',
			'glaeubiger_id'               => '',
			'loeschen_bei_deinstallation' => '0',
		];
	}

	public static function handle(): void {
		if ( empty( $_POST['mietv_einstellungen_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( Mietv_Admin::CAP ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_einstellungen_nonce'] ?? '' ) ), 'mietv_einstellungen' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietfux' ) );
		}

		// Restore
		if ( ! empty( $_FILES['mietv_restore']['tmp_name'] ) ) {
			if ( empty( $_POST['mietv_restore_confirm'] ) ) {
				mietv_flash_set( 'noconfirm' );
			wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen' ) );
				exit;
			}
			$msg = self::restore( (string) file_get_contents( sanitize_text_field( $_FILES['mietv_restore']['tmp_name'] ) ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Hochgeladene Datei lokal lesen.
			? 'restored' : 'restorefail';
			mietv_flash_set( $msg );
			wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen' ) );
			exit;
		}

		$data = [];
		foreach ( self::defaults() as $k => $d ) {
			$raw        = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- in der Folgezeile bereinigt.
			$data[ $k ] = $k === 'vermieter_adresse' ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		update_option( self::OPTION, $data );
		mietv_flash_set( 'saved' );
			wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen' ) );
		exit;
	}

	public static function page(): void {
		$o   = array_merge( self::defaults(), get_option( self::OPTION, [] ) );
		$msg = mietv_flash_get()['code'] ?? '';
		$map = [
			'saved'       => [ 'success', esc_html__( 'Einstellungen gespeichert.', 'mietfux' ) ],
			'restored'    => [ 'success', esc_html__( 'Backup eingespielt. Alle Daten wurden ersetzt.', 'mietfux' ) ],
			'restorefail' => [ 'error', esc_html__( 'Backup konnte nicht gelesen werden (kein gültiges Mietverwaltung-JSON).', 'mietfux' ) ],
			'noconfirm'   => [ 'error', esc_html__( 'Wiederherstellung abgebrochen: Bestätigung fehlt.', 'mietfux' ) ],
		];
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Einstellungen & Backup', 'mietfux' ) . '</h1>';
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $map[ $msg ][0], esc_html( $map[ $msg ][1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		}

		echo '<form method="post" enctype="multipart/form-data" class="mv-form">';
		wp_nonce_field( 'mietv_einstellungen', 'mietv_einstellungen_nonce' );

		echo '<h2>' . esc_html__( 'Vermieter (für Schreiben und Abrechnungen)', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::text( 'vermieter_name', __( 'Name', 'mietfux' ), $o );
		self::area( 'vermieter_adresse', __( 'Anschrift', 'mietfux' ), $o );
		self::text( 'vermieter_telefon', __( 'Telefon', 'mietfux' ), $o );
		self::text( 'vermieter_email', __( 'E-Mail', 'mietfux' ), $o );
		self::text( 'vermieter_iban', __( 'IBAN (für Nachzahlungen)', 'mietfux' ), $o );
		self::text( 'vermieter_bank', __( 'Bank', 'mietfux' ), $o );
		self::text( 'vermieter_steuernr', __( 'Steuernummer (optional, für den Mietvertrag)', 'mietfux' ), $o );
		self::text( 'vermieter_vertreter', __( 'Vertreten durch (optional, z. B. Hausverwaltung)', 'mietfux' ), $o );
		self::text( 'glaeubiger_id', __( 'SEPA-Gläubiger-Identifikationsnummer', 'mietfux' ), $o, __( 'Für Lastschriftmandate; bei der Bundesbank beantragen (kostenlos). Leer = kein SEPA-Mandat.', 'mietfux' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Erinnerungen', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::select(
			'erinnerung_aktiv',
			__( 'Tägliche E-Mail bei Hinweisen', 'mietfux' ),
			$o,
			[
				'1' => esc_html__( 'Ein', 'mietfux' ),
				'0' => esc_html__( 'Aus', 'mietfux' ),
			],
			__( 'Fehlender Mieteingang, Abrechnungsfrist, Zinsbindung, 15-Prozent-Grenze. Nur wenn es etwas zu melden gibt.', 'mietfux' )
		);
		self::text( 'erinnerung_email', __( 'Empfänger', 'mietfux' ), $o );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Mietrecht', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::select(
			'angespannter_markt',
			__( 'Gebiet mit angespanntem Wohnungsmarkt', 'mietfux' ),
			$o,
			[
				'0' => esc_html__( 'Nein (Kappungsgrenze 20 %)', 'mietfux' ),
				'1' => esc_html__( 'Ja (Kappungsgrenze 15 %)', 'mietfux' ),
			],
			__( 'Bayern: Mieterschutzverordnung – gilt u. a. für Fürth, Nürnberg, Erlangen und einige Umlandgemeinden; Zirndorf/Oberasbach/Stein prüfen.', 'mietfux' )
		);
		self::text( 'mahn_tage_1', __( 'Zahlungsfrist Zahlungserinnerung (Tage)', 'mietfux' ), $o );
		self::text( 'mahn_tage_2', __( 'Zahlungsfrist 1. Mahnung (Tage)', 'mietfux' ), $o );
		self::text( 'mahn_tage_3', __( 'Zahlungsfrist letzte Mahnung (Tage)', 'mietfux' ), $o );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Datenschutz', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::text( 'aufbewahrung_jahre', __( 'Aufbewahrungsfrist nach Mietende (Jahre)', 'mietfux' ), $o, __( '10 Jahre für steuerrelevante Unterlagen (§ 147 AO), gerechnet ab Ende des Kalenderjahres.', 'mietfux' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Deinstallation', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::select(
			'loeschen_bei_deinstallation',
			__( 'Beim Löschen des Plugins', 'mietfux' ),
			$o,
			[
				'0' => esc_html__( 'Alle Daten behalten (Standard)', 'mietfux' ),
				'1' => esc_html__( 'Alle Tabellen und Einstellungen löschen', 'mietfux' ),
			],
			__( 'Gilt nur für „Löschen“ auf der Plugin-Seite, nicht für „Deaktivieren“. Mediathek-Dateien bleiben in jedem Fall.', 'mietfux' )
		);
		echo '</tbody></table>';

		submit_button( 'Speichern' );

		echo '<hr><h2>' . esc_html__( 'Backup', 'mietfux' ) . '</h2>';
		printf( '<p><a href="%s" class="button button-secondary">' . esc_html__( 'Alle Daten als JSON exportieren', 'mietfux' ) . '</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_backup' ), 'mietv_backup' ) ) );
		echo '<p class="description">' . esc_html__( 'Enthält alle Tabellen der Mietverwaltung und die Einstellungen – nicht die Dateien der Mediathek (diese über das normale WordPress-Backup sichern).', 'mietfux' ) . '</p>';
		echo '<h3>' . esc_html__( 'Wiederherstellen', 'mietfux' ) . '</h3><p><input type="file" name="mietv_restore" accept=".json"> <label><input type="checkbox" name="mietv_restore_confirm" value="1"> ' . esc_html__( 'Ich weiß, dass', 'mietfux' ) . ' <strong>' . esc_html__( 'alle vorhandenen Daten ersetzt', 'mietfux' ) . '</strong> ' . esc_html__( 'werden.', 'mietfux' ) . '</label></p>';
		submit_button( 'Backup einspielen', 'delete' );
		echo '</form></div>';
	}

	private static function text( $k, $label, $o, $hint = '' ): void {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%1$s" value="%3$s" class="regular-text">%4$s</td></tr>', esc_attr( $k ), esc_html( $label ), esc_attr( $o[ $k ] ), $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' );
	}
	private static function area( $k, $label, $o ): void {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%1$s" rows="3" class="regular-text">%3$s</textarea></td></tr>', esc_attr( $k ), esc_html( $label ), esc_textarea( $o[ $k ] ) );
	}
	private static function select( $k, $label, $o, $opts, $hint = '' ): void {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%1$s">', esc_attr( $k ), esc_html( $label ) );
		foreach ( $opts as $v => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( (string) $o[ $k ], (string) $v, false ), esc_html( $l ) );
		}
		echo '</select>' . ( $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' ) . '</td></tr>';
	}

	/* ------------------------------------------------------------------ */
	/* Backup / Restore                                                   */
	/* ------------------------------------------------------------------ */

	public static function tabellen(): array {
		return [ 'objekte', 'einheiten', 'mieter', 'mietverhaeltnisse', 'konditionen', 'zahlungen', 'meldungen', 'kostenarten', 'belege', 'anlagegueter', 'darlehen', 'darlehen_jahre', 'dokumente', 'zaehler', 'zaehlerstaende', 'abrechnungen', 'abrechnung_ergebnis', 'bank_regeln', 'bank_import', 'mahnungen', 'erhoehungen', 'vertraege', 'kaution', 'mv_einheiten', 'darlehen_raten' ];
	}

	public static function backup(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietfux' ) );
		}
		check_admin_referer( 'mietv_backup' );
		global $wpdb;
		$dump = [
			'plugin'        => 'mietfux',
			'version'       => MIETV_VERSION,
			'db_version'    => Mietv_DB::DB_VERSION,
			'erstellt'      => current_time( 'mysql' ),
			'einstellungen' => get_option( self::OPTION, [] ),
			'tabellen'      => [],
		];
		foreach ( self::tabellen() as $t ) {
			$dump['tabellen'][ $t ] = $wpdb->get_results( 'SELECT * FROM ' . mietv_table( $t ), ARRAY_A );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellenname aus fester Liste.
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mietfux-backup-' . current_time( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function restore( string $json ): bool {
		global $wpdb;
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) || ! in_array( $d['plugin'] ?? '', [ 'mietfux', 'mietverwaltung' ], true ) || empty( $d['tabellen'] ) ) {
			return false;
		}
		foreach ( self::tabellen() as $t ) {
			$wpdb->query( 'DELETE FROM ' . mietv_table( $t ) );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellenname aus fester Liste.
			foreach ( (array) ( $d['tabellen'][ $t ] ?? [] ) as $row ) {
				$wpdb->insert( mietv_table( $t ), $row );
			}
		}
		if ( isset( $d['einstellungen'] ) ) {
			update_option( self::OPTION, (array) $d['einstellungen'] );
		}
		return true;
	}
}
