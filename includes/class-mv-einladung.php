<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Portal-Einladung: legt für einen Mieter den WordPress-Benutzer an (Rolle Abonnent), verknüpft ihn
 * mit dem Mieterdatensatz und verschickt eine E-Mail mit Link zum Setzen des Passworts und zur Portalseite.
 */
class Mietv_Einladung {

	public static function init(): void {
		add_action( 'admin_post_mietv_portal_einladen', [ __CLASS__, 'handle' ] );
	}

	/** Zeitpunkt der letzten Einladung (user meta), '' wenn nie. */
	public static function letzte( int $user_id ): string {
		return $user_id ? (string) get_user_meta( $user_id, 'mietv_einladung', true ) : '';
	}

	/**
	 * Benutzer anlegen/verknüpfen und Einladung senden.
	 *
	 * @return true|string true bei Erfolg, sonst Fehlercode (keine_email, keine_portalseite, user_fehler, mail_fehler).
	 */
	public static function einladen( int $mieter_id ) {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mieter_id ) );
		if ( ! $m ) {
			return 'user_fehler';
		}
		$email = trim( (string) $m->email, " \t\n\r\0\x0B" );
		if ( ! is_email( $email ) ) {
			return 'keine_email';
		}
		$portal = class_exists( 'Mietv_Portal' ) ? Mietv_Portal::seiten_url() : '';
		if ( ! $portal ) {
			return 'keine_portalseite';
		}

		$user = $m->wp_user_id ? get_user_by( 'id', (int) $m->wp_user_id ) : null;
		if ( ! $user ) {
			$user = get_user_by( 'email', $email );
		}
		if ( ! $user ) {
			$login = sanitize_user( strtolower( strstr( $email, '@', true ) ?: 'mieter' ), true ) ?: 'mieter';
			$basis = $login;
			$i     = 1;
			while ( username_exists( $login ) ) {
				++$i;
				$login = $basis . '-' . $i;
			}
			$uid = wp_insert_user(
				[
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'first_name'   => (string) $m->vorname,
					'last_name'    => (string) $m->nachname,
					'display_name' => trim( $m->vorname . ' ' . $m->nachname, ' ' ),
					'role'         => 'subscriber',
				]
			);
			if ( is_wp_error( $uid ) ) {
				return 'user_fehler';
			}
			$user = get_user_by( 'id', (int) $uid );
		}
		if ( (int) $m->wp_user_id !== (int) $user->ID ) {
			$wpdb->update( mietv_table( 'mieter' ), [ 'wp_user_id' => (int) $user->ID ], [ 'id' => $mieter_id ] );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return 'user_fehler';
		}
		$reset = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );

		$anrede    = class_exists( 'Mietv_Versand' ) ? Mietv_Versand::anrede( $m ) : 'Guten Tag,';
		$signatur  = class_exists( 'Mietv_Versand' ) ? Mietv_Versand::signatur() : (string) Mietv_Einstellungen::get( 'vermieter_name' );
		$vermieter = (string) Mietv_Einstellungen::get( 'vermieter_name' ) ?: get_bloginfo( 'name' );
		$text      = $anrede . "\n\n"
			. "für Ihr Mietverhältnis steht Ihnen ab sofort ein Mieterportal zur Verfügung. Dort sehen Sie Ihr Mietkonto, freigegebene Dokumente (z. B. Betriebskostenabrechnungen), können Zählerstände melden und uns Schäden oder Anliegen mitteilen.\n\n"
			. 'Ihr Benutzername: ' . $user->user_login . "\n"
			. "Passwort festlegen (Link 24 Stunden gültig):\n" . $reset . "\n\n"
			. "Mieterportal:\n" . $portal . "\n\n"
			. "Sollte der Link abgelaufen sein, nutzen Sie bitte auf der Anmeldeseite „Passwort vergessen“.\n\n"
			. "Mit freundlichen Grüßen\n" . $signatur;

		$headers = [];
		$von     = (string) Mietv_Einstellungen::get( 'vermieter_email' );
		if ( is_email( $von ) ) {
			$headers[] = 'Reply-To: ' . $von;
		}
		$ok = wp_mail( $email, sprintf( 'Ihr Zugang zum Mieterportal – %s', $vermieter ), $text, $headers );
		if ( ! $ok ) {
			return 'mail_fehler';
		}
		update_user_meta( $user->ID, 'mietv_einladung', current_time( 'mysql' ) );
		if ( class_exists( 'Mietv_Protokoll' ) ) {
			Mietv_Protokoll::log( 'einladung', 'mieter', $mieter_id, trim( $m->vorname . ' ' . $m->nachname, ' ' ) . ' – Portal-Einladung an ' . $email );
		}
		return true;
	}

	public static function handle(): void {
		check_admin_referer( 'mietv_einladen' );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$mieter_id = isset( $_GET['mieter_id'] ) ? (int) $_GET['mieter_id'] : 0;
		$erg       = self::einladen( $mieter_id );
		mietv_flash_set( $erg === true ? 'einladung_ok' : 'einladung_' . $erg );
		$zurueck = wp_get_referer() ?: admin_url( 'admin.php?page=mietv_uebersicht' );
		wp_safe_redirect( $zurueck );
		exit;
	}

	/** Flash-Texte für Mietv_Admin::notices(). */
	public static function flash_texte(): array {
		return [
			'einladung_ok'                => [ 'success', __( 'Portal invitation sent. The tenant received a link to set a password.', 'mietfux' ) ],
			'einladung_keine_email'       => [ 'error', __( 'No valid e-mail address is stored for this tenant.', 'mietfux' ) ],
			'einladung_keine_portalseite' => [ 'error', __( 'No published page with the [mietfux_portal] shortcode found.', 'mietfux' ) ],
			'einladung_user_fehler'       => [ 'error', __( 'The WordPress user could not be created.', 'mietfux' ) ],
			'einladung_mail_fehler'       => [ 'error', __( 'The invitation e-mail could not be sent (check the mail configuration of your site).', 'mietfux' ) ],
		];
	}

	/** Button „Zum Portal einladen“ / „Einladung erneut senden“ für ein Mietverhältnis (alle_mv-Zeile). */
	public static function button( object $mv, bool $icon = false ): string {
		$url   = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_portal_einladen&mieter_id=' . (int) $mv->mieter_id ), 'mietv_einladen' );
		$schon = (int) $mv->wp_user_id > 0;
		$label = $schon ? __( 'Resend portal invitation', 'mietfux' ) : __( 'Invite to portal', 'mietfux' );
		$conf  = $schon ? esc_attr__( 'Send the portal invitation again?', 'mietfux' ) : esc_attr__( 'Create a WordPress user for this tenant and send the invitation e-mail?', 'mietfux' );
		if ( $icon ) {
			return '<a href="' . esc_url( $url ) . '" class="mv-icon-btn" title="' . esc_attr( $label ) . '" data-confirm="' . $conf . '"><span class="dashicons dashicons-email-alt"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span></a>';
		}
		return '<a href="' . esc_url( $url ) . '" class="button' . ( $schon ? ' mv-icon-btn' : '' ) . '" data-confirm="' . $conf . '" title="' . esc_attr( $label ) . '">' . ( $schon ? '<span class="dashicons dashicons-email-alt"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span>' : esc_html( $label ) ) . '</a> ';
	}
}
