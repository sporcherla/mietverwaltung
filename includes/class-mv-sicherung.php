<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Automatische Datensicherung: JSON-Export aller Plugin-Tabellen per WP-Cron
 * (täglich/wöchentlich) in einen geschützten Upload-Ordner, rotierend, optional per E-Mail.
 */
class Mietv_Sicherung {

	public static function init(): void {
		add_action( Mietv_Hinweise::CRON, [ __CLASS__, 'cron' ], 20 );
		add_action( 'admin_post_mietv_sicherung_jetzt', [ __CLASS__, 'jetzt' ] );
		add_action( 'admin_post_mietv_sicherung_laden', [ __CLASS__, 'laden' ] );
		add_action( 'admin_post_mietv_sicherung_loeschen', [ __CLASS__, 'loeschen_handler' ] );
	}

	/** Zielordner (uploads/mietfux-backups), gegen direkten Zugriff geschützt. */
	public static function ordner(): string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mietfux-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$fs = Mietv_PDF::filesystem();
			if ( $fs ) {
				$fs->put_contents( $dir . '/index.php', "<?php // Silence is golden.\n", FS_CHMOD_FILE );
				$fs->put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n", FS_CHMOD_FILE );
			}
		}
		return $dir;
	}

	/** Vorhandene Sicherungen, neueste zuerst: [ ['datei' => …, 'zeit' => ts, 'groesse' => bytes] ]. */
	public static function liste(): array {
		$out = [];
		foreach ( glob( self::ordner() . '/mietfux-backup-*.json' ) ?: [] as $f ) {
			$out[] = [
				'datei'   => basename( $f ),
				'zeit'    => (int) filemtime( $f ),
				'groesse' => (int) filesize( $f ),
			];
		}
		usort( $out, fn( $a, $b ) => $b['zeit'] <=> $a['zeit'] );
		return $out;
	}

	/** Sicherung schreiben; liefert Dateinamen oder ''. */
	public static function erstellen( bool $per_email = false ): string {
		$fs = Mietv_PDF::filesystem();
		if ( ! $fs ) {
			return '';
		}
		$json = wp_json_encode( Mietv_Einstellungen::dump(), JSON_UNESCAPED_UNICODE );
		$name = 'mietfux-backup-' . current_time( 'Y-m-d-His' ) . '-' . wp_generate_password( 6, false ) . '.json';
		$pfad = self::ordner() . '/' . $name;
		if ( ! $fs->put_contents( $pfad, $json, FS_CHMOD_FILE ) ) {
			return '';
		}
		update_option( 'mietv_sicherung_letzte', current_time( 'mysql' ) );
		self::rotieren();
		if ( $per_email ) {
			$an = Mietv_Einstellungen::get( 'erinnerung_email' ) ?: get_option( 'admin_email' );
			wp_mail(
				$an,
				sprintf( '[%s] Mietfux-Datensicherung %s', get_bloginfo( 'name' ), current_time( 'd.m.Y' ) ),
				"Anbei die automatische Datensicherung von Mietfux (alle Tabellen und Einstellungen als JSON).\nEinspielen unter Mietfux → Einstellungen & Backup → Wiederherstellen.",
				[],
				[ $pfad ]
			);
		}
		return $name;
	}

	/** Nur die neuesten N Sicherungen behalten. */
	public static function rotieren(): void {
		$max   = max( 1, (int) Mietv_Einstellungen::get( 'backup_anzahl', '8' ) );
		$liste = self::liste();
		foreach ( array_slice( $liste, $max ) as $alt ) {
			wp_delete_file( self::ordner() . '/' . $alt['datei'] );
		}
	}

	/** Täglicher Cron: je nach Einstellung sichern. */
	public static function cron(): void {
		$modus = Mietv_Einstellungen::get( 'backup_auto', '0' );
		if ( $modus === '0' ) {
			return;
		}
		$letzte = get_option( 'mietv_sicherung_letzte', '' );
		$alter  = $letzte ? ( current_time( 'timestamp' ) - strtotime( $letzte ) ) / DAY_IN_SECONDS : 999; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Vergleich in Site-Zeit.
		if ( ( $modus === 'taeglich' && $alter >= 0.9 ) || ( $modus === 'woechentlich' && $alter >= 6.9 ) ) {
			self::erstellen( Mietv_Einstellungen::get( 'backup_email', '0' ) === '1' );
		}
	}

	/** Dateiname prüfen (nur eigene Sicherungen). */
	private static function datei_aus_request(): string {
		$d = isset( $_GET['datei'] ) ? sanitize_file_name( wp_unslash( $_GET['datei'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce wird vom Aufrufer geprüft.
		return preg_match( '/^mietfux-backup-[\w-]+\.json$/', $d ) && file_exists( self::ordner() . '/' . $d ) ? $d : '';
	}

	public static function jetzt(): void {
		check_admin_referer( 'mietv_sicherung' );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		mietv_flash_set( self::erstellen() ? 'backup_ok' : 'backup_fehler' );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen' ) );
		exit;
	}

	public static function laden(): void {
		check_admin_referer( 'mietv_sicherung' );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$d = self::datei_aus_request();
		if ( ! $d ) {
			wp_die( esc_html__( 'File not found.', 'mietfux' ) );
		}
		$fs = Mietv_PDF::filesystem();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $d . '"' );
		echo $fs ? $fs->get_contents( self::ordner() . '/' . $d ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-Download einer eigenen Sicherungsdatei.
		exit;
	}

	public static function loeschen_handler(): void {
		check_admin_referer( 'mietv_sicherung' );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$d = self::datei_aus_request();
		if ( $d ) {
			wp_delete_file( self::ordner() . '/' . $d );
		}
		mietv_flash_set( 'backup_geloescht' );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen' ) );
		exit;
	}

	/** Abschnitt auf der Einstellungsseite. */
	public static function box(): void {
		$liste  = self::liste();
		$letzte = get_option( 'mietv_sicherung_letzte', '' );
		echo '<h3>' . esc_html__( 'Automatic backups', 'mietfux' ) . '</h3><p>';
		if ( $letzte ) {
			printf( /* translators: %s: Datum */ esc_html__( 'Last automatic backup: %s', 'mietfux' ), esc_html( mietv_date( substr( $letzte, 0, 10 ) ) . ' ' . substr( $letzte, 11, 5 ) ) );
		} else {
			esc_html_e( 'No automatic backup yet.', 'mietfux' );
		}
		printf( ' <a href="%s" class="button">%s</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_sicherung_jetzt' ), 'mietv_sicherung' ) ), esc_html__( 'Create backup now', 'mietfux' ) );
		if ( $liste ) {
			echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Date', 'mietfux' ) . '</th><th>' . esc_html__( 'Size', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $liste as $b ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td><a href="%s">%s</a> · <a href="%s" class="mv-delete" data-confirm="%s">%s</a></td></tr>',
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['zeit'] ) ),
					esc_html( size_format( $b['groesse'] ) ),
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_sicherung_laden&datei=' . rawurlencode( $b['datei'] ) ), 'mietv_sicherung' ) ),
					esc_html__( 'Download', 'mietfux' ),
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_sicherung_loeschen&datei=' . rawurlencode( $b['datei'] ) ), 'mietv_sicherung' ) ),
					esc_attr__( 'Delete this backup?', 'mietfux' ),
					esc_html__( 'Delete', 'mietfux' )
				);
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . esc_html__( 'Backups are stored in the uploads folder (mietfux-backups, blocked for direct access) and rotated automatically. To restore, download the file and use “Restore” above.', 'mietfux' ) . '</p>';
	}
}
