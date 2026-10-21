<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einstellungen (Vermieterdaten, Erinnerungen, Aufbewahrung) und Backup/Restore.
 */
class MV_Einstellungen {

	const OPTION = 'mv_einstellungen';

	public static function init(): void {
		add_action( 'admin_post_mv_backup', [ __CLASS__, 'backup' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	public static function get( string $key, $default = '' ) {
		$o = get_option( self::OPTION, [] );
		return isset( $o[ $key ] ) && $o[ $key ] !== '' ? $o[ $key ] : $default;
	}

	public static function defaults(): array {
		return [
			'vermieter_name'      => '',
			'vermieter_adresse'   => '',
			'vermieter_telefon'   => '',
			'vermieter_email'     => get_option( 'admin_email' ),
			'vermieter_iban'      => '',
			'vermieter_bank'      => '',
			'vermieter_steuernr'  => '',
			'vermieter_vertreter' => '',
			'erinnerung_aktiv'    => '1',
			'erinnerung_email'    => get_option( 'admin_email' ),
			'aufbewahrung_jahre'  => '10',
			'angespannter_markt'  => '0',
			'mahn_tage_1'         => '7',
			'mahn_tage_2'         => '10',
			'mahn_tage_3'         => '7',
			'glaeubiger_id'       => '',
			'loeschen_bei_deinstallation' => '0',
		];
	}

	public static function handle(): void {
		if ( empty( $_POST['mv_einstellungen_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( MV_Admin::CAP ) || ! wp_verify_nonce( $_POST['mv_einstellungen_nonce'], 'mv_einstellungen' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}

		// Restore
		if ( ! empty( $_FILES['mv_restore']['tmp_name'] ) ) {
			if ( empty( $_POST['mv_restore_confirm'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mv_einstellungen&msg=noconfirm' ) );
				exit;
			}
			$msg = self::restore( file_get_contents( $_FILES['mv_restore']['tmp_name'] ) ) ? 'restored' : 'restorefail';
			wp_safe_redirect( admin_url( 'admin.php?page=mv_einstellungen&msg=' . $msg ) );
			exit;
		}

		$data = [];
		foreach ( self::defaults() as $k => $d ) {
			$raw = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
			$data[ $k ] = $k === 'vermieter_adresse' ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		update_option( self::OPTION, $data );
		wp_safe_redirect( admin_url( 'admin.php?page=mv_einstellungen&msg=saved' ) );
		exit;
	}

	public static function page(): void {
		$o = array_merge( self::defaults(), get_option( self::OPTION, [] ) );
		$msg = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
		$map = [
			'saved'       => [ 'success', __( 'Einstellungen gespeichert.', 'mietverwaltung' ) ],
			'restored'    => [ 'success', __( 'Backup eingespielt. Alle Daten wurden ersetzt.', 'mietverwaltung' ) ],
			'restorefail' => [ 'error', __( 'Backup konnte nicht gelesen werden (kein gültiges Mietverwaltung-JSON).', 'mietverwaltung' ) ],
			'noconfirm'   => [ 'error', __( 'Wiederherstellung abgebrochen: Bestätigung fehlt.', 'mietverwaltung' ) ],
		];
		echo '<div class="wrap mv-wrap"><h1>' . __( 'Einstellungen &amp; Backup', 'mietverwaltung' ) . '</h1>';
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $map[ $msg ][0], esc_html( $map[ $msg ][1] ) );
		}

		echo '<form method="post" enctype="multipart/form-data" class="mv-form">';
		wp_nonce_field( 'mv_einstellungen', 'mv_einstellungen_nonce' );

		echo '<h2>' . esc_html__( 'Vermieter (für Schreiben und Abrechnungen)', 'mietverwaltung' ) . '</h2><table class="form-table"><tbody>';
		self::text( 'vermieter_name', __( 'Name', 'mietverwaltung' ), $o );
		self::area( 'vermieter_adresse', __( 'Anschrift', 'mietverwaltung' ), $o );
		self::text( 'vermieter_telefon', __( 'Telefon', 'mietverwaltung' ), $o );
		self::text( 'vermieter_email', __( 'E-Mail', 'mietverwaltung' ), $o );
		self::text( 'vermieter_iban', __( 'IBAN (für Nachzahlungen)', 'mietverwaltung' ), $o );
		self::text( 'vermieter_bank', __( 'Bank', 'mietverwaltung' ), $o );
		self::text( 'vermieter_steuernr', __( 'Steuernummer (optional, für den Mietvertrag)', 'mietverwaltung' ), $o );
		self::text( 'vermieter_vertreter', __( 'Vertreten durch (optional, z. B. Hausverwaltung)', 'mietverwaltung' ), $o );
		self::text( 'glaeubiger_id', __( 'SEPA-Gläubiger-Identifikationsnummer', 'mietverwaltung' ), $o, __( 'Für Lastschriftmandate; bei der Bundesbank beantragen (kostenlos). Leer = kein SEPA-Mandat.', 'mietverwaltung' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Erinnerungen', 'mietverwaltung' ) . '</h2><table class="form-table"><tbody>';
		self::select( 'erinnerung_aktiv', __( 'Tägliche E-Mail bei Hinweisen', 'mietverwaltung' ), $o, [ '1' => __( 'Ein', 'mietverwaltung' ), '0' => __( 'Aus', 'mietverwaltung' ) ], __( 'Fehlender Mieteingang, Abrechnungsfrist, Zinsbindung, 15-%-Grenze. Nur wenn es etwas zu melden gibt.', 'mietverwaltung' ) );
		self::text( 'erinnerung_email', __( 'Empfänger', 'mietverwaltung' ), $o );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Mietrecht', 'mietverwaltung' ) . '</h2><table class="form-table"><tbody>';
		self::select( 'angespannter_markt', __( 'Gebiet mit angespanntem Wohnungsmarkt', 'mietverwaltung' ), $o, [ '0' => __( 'Nein (Kappungsgrenze 20 %)', 'mietverwaltung' ), '1' => __( 'Ja (Kappungsgrenze 15 %)', 'mietverwaltung' ) ], __( 'Bayern: Mieterschutzverordnung – gilt u. a. für Fürth, Nürnberg, Erlangen und einige Umlandgemeinden; Zirndorf/Oberasbach/Stein prüfen.', 'mietverwaltung' ) );
		self::text( 'mahn_tage_1', __( 'Zahlungsfrist Zahlungserinnerung (Tage)', 'mietverwaltung' ), $o );
		self::text( 'mahn_tage_2', __( 'Zahlungsfrist 1. Mahnung (Tage)', 'mietverwaltung' ), $o );
		self::text( 'mahn_tage_3', __( 'Zahlungsfrist letzte Mahnung (Tage)', 'mietverwaltung' ), $o );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Datenschutz', 'mietverwaltung' ) . '</h2><table class="form-table"><tbody>';
		self::text( 'aufbewahrung_jahre', __( 'Aufbewahrungsfrist nach Mietende (Jahre)', 'mietverwaltung' ), $o, __( '10 Jahre für steuerrelevante Unterlagen (§ 147 AO), gerechnet ab Ende des Kalenderjahres.', 'mietverwaltung' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Deinstallation', 'mietverwaltung' ) . '</h2><table class="form-table"><tbody>';
		self::select( 'loeschen_bei_deinstallation', __( 'Beim Löschen des Plugins', 'mietverwaltung' ), $o, [ '0' => __( 'Alle Daten behalten (Standard)', 'mietverwaltung' ), '1' => __( 'Alle Tabellen und Einstellungen löschen', 'mietverwaltung' ) ], __( 'Gilt nur für „Löschen“ auf der Plugin-Seite, nicht für „Deaktivieren“. Mediathek-Dateien bleiben in jedem Fall.', 'mietverwaltung' ) );
		echo '</tbody></table>';

		submit_button( 'Speichern' );

		echo '<hr><h2>' . esc_html__( 'Backup', 'mietverwaltung' ) . '</h2>';
		printf( '<p><a href="%s" class="button button-secondary">' . esc_html__( 'Alle Daten als JSON exportieren', 'mietverwaltung' ) . '</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mv_backup' ), 'mv_backup' ) ) );
		echo '<p class="description">' . esc_html__( 'Enthält alle Tabellen der Mietverwaltung und die Einstellungen – nicht die Dateien der Mediathek (diese über das normale WordPress-Backup sichern).', 'mietverwaltung' ) . '</p>';
		echo '<h3>' . esc_html__( 'Wiederherstellen', 'mietverwaltung' ) . '</h3><p><input type="file" name="mv_restore" accept=".json"> <label><input type="checkbox" name="mv_restore_confirm" value="1"> ' . esc_html__( 'Ich weiß, dass', 'mietverwaltung' ) . ' <strong>' . esc_html__( 'alle vorhandenen Daten ersetzt', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'werden.', 'mietverwaltung' ) . '</label></p>';
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
		if ( ! current_user_can( MV_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		check_admin_referer( 'mv_backup' );
		global $wpdb;
		$dump = [ 'plugin' => 'mietverwaltung', 'version' => MV_VERSION, 'db_version' => MV_DB::DB_VERSION, 'erstellt' => current_time( 'mysql' ), 'einstellungen' => get_option( self::OPTION, [] ), 'tabellen' => [] ];
		foreach ( self::tabellen() as $t ) {
			$dump['tabellen'][ $t ] = $wpdb->get_results( "SELECT * FROM " . mv_table( $t ), ARRAY_A );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mietverwaltung-backup-' . current_time( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function restore( string $json ): bool {
		global $wpdb;
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) || ( $d['plugin'] ?? '' ) !== 'mietverwaltung' || empty( $d['tabellen'] ) ) {
			return false;
		}
		foreach ( self::tabellen() as $t ) {
			$wpdb->query( "DELETE FROM " . mv_table( $t ) );
			foreach ( (array) ( $d['tabellen'][ $t ] ?? [] ) as $row ) {
				$wpdb->insert( mv_table( $t ), $row );
			}
		}
		if ( isset( $d['einstellungen'] ) ) {
			update_option( self::OPTION, (array) $d['einstellungen'] );
		}
		return true;
	}
}
