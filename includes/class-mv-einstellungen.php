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
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
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
			'saved'       => [ 'success', esc_html__( 'Settings saved.', 'mietfux' ) ],
			'restored'    => [ 'success', esc_html__( 'Backup restored. All data has been replaced.', 'mietfux' ) ],
			'restorefail' => [ 'error', esc_html__( 'Backup could not be read (not a valid Rental Management JSON file).', 'mietfux' ) ],
			'noconfirm'   => [ 'error', esc_html__( 'Restore cancelled: confirmation missing.', 'mietfux' ) ],
		];
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Settings & backup', 'mietfux' ) . '</h1>';
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}

		echo '<form method="post" enctype="multipart/form-data" class="mv-form">';
		wp_nonce_field( 'mietv_einstellungen', 'mietv_einstellungen_nonce' );

		echo '<h2>' . esc_html__( 'Landlord (for letters and statements)', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::text( 'vermieter_name', __( 'Name', 'mietfux' ), $o );
		self::area( 'vermieter_adresse', __( 'Address', 'mietfux' ), $o );
		self::text( 'vermieter_telefon', __( 'Phone', 'mietfux' ), $o );
		self::text( 'vermieter_email', __( 'Email', 'mietfux' ), $o );
		self::text( 'vermieter_iban', __( 'IBAN (for amounts due)', 'mietfux' ), $o );
		self::text( 'vermieter_bank', __( 'Bank', 'mietfux' ), $o );
		self::text( 'vermieter_steuernr', __( 'Tax number (optional, for the tenancy agreement)', 'mietfux' ), $o );
		self::text( 'vermieter_vertreter', __( 'Represented by (optional, e.g. property manager)', 'mietfux' ), $o );
		self::text( 'glaeubiger_id', __( 'SEPA creditor identifier', 'mietfux' ), $o, __( 'For direct debit mandates; apply at the Bundesbank (free of charge). Empty = no SEPA mandate.', 'mietfux' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Reminders', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::select(
			'erinnerung_aktiv',
			__( 'Daily email for notices', 'mietfux' ),
			$o,
			[
				'1' => esc_html__( 'On', 'mietfux' ),
				'0' => esc_html__( 'Off', 'mietfux' ),
			],
			__( 'Missing rent, statement deadline, fixed-rate period, 15 percent limit. Only when there is something to report.', 'mietfux' )
		);
		self::text( 'erinnerung_email', __( 'Recipient', 'mietfux' ), $o );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Tenancy law', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::select(
			'angespannter_markt',
			__( 'Area with a tight housing market', 'mietfux' ),
			$o,
			[
				'0' => esc_html__( 'No (cap 20 %)', 'mietfux' ),
				'1' => esc_html__( 'Yes (cap 15 %)', 'mietfux' ),
			],
			__( 'Bavaria: Tenant Protection Ordinance – applies e.g. to Fürth, Nuremberg, Erlangen and some surrounding municipalities; check Zirndorf/Oberasbach/Stein.', 'mietfux' )
		);
		self::text( 'mahn_tage_1', __( 'Payment deadline, payment reminder (days)', 'mietfux' ), $o );
		self::text( 'mahn_tage_2', __( 'Payment deadline, 1st dunning letter (days)', 'mietfux' ), $o );
		self::text( 'mahn_tage_3', __( 'Payment deadline, final dunning letter (days)', 'mietfux' ), $o );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Data protection', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::text( 'aufbewahrung_jahre', __( 'Retention period after end of tenancy (years)', 'mietfux' ), $o, __( '10 years for tax-relevant records (§ 147 AO), counted from the end of the calendar year.', 'mietfux' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Uninstall', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
		self::select(
			'loeschen_bei_deinstallation',
			__( 'When deleting the plugin', 'mietfux' ),
			$o,
			[
				'0' => esc_html__( 'Keep all data (default)', 'mietfux' ),
				'1' => esc_html__( 'Delete all tables and settings', 'mietfux' ),
			],
			__( 'Applies only to “Delete” on the plugins page, not to “Deactivate”. Media library files are always kept.', 'mietfux' )
		);
		echo '</tbody></table>';

		submit_button( 'Speichern' );

		echo '<hr><h2>' . esc_html__( 'Backup', 'mietfux' ) . '</h2>';
		printf( '<p><a href="%s" class="button button-secondary">' . esc_html__( 'Export all data as JSON', 'mietfux' ) . '</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_backup' ), 'mietv_backup' ) ) );
		echo '<p class="description">' . esc_html__( 'Contains all Rental Management tables and settings – not the media library files (back those up with your regular WordPress backup).', 'mietfux' ) . '</p>';
		echo '<h3>' . esc_html__( 'Restore', 'mietfux' ) . '</h3><p><input type="file" name="mietv_restore" accept=".json"> <label><input type="checkbox" name="mietv_restore_confirm" value="1"> ' . esc_html__( 'I understand that', 'mietfux' ) . ' <strong>' . esc_html__( 'all existing data will be replaced', 'mietfux' ) . '</strong> ' . esc_html__( '.', 'mietfux' ) . '</label></p>';
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
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
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
