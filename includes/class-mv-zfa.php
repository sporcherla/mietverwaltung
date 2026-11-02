<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zwei-Faktor-Anmeldung: keine eigene Implementierung, sondern Anbindung des
 * Plugins „Two-Factor“ (WordPress-Core-Team, GPL, ohne Cloud). Mietfux erkennt es,
 * empfiehlt es und kann es für Vermieter (Rolle mietv_vermieter, Administratoren)
 * verpflichtend machen.
 */
class Mietv_Zfa {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'erzwingen' ] );
	}

	/** Ist das Two-Factor-Plugin aktiv? */
	public static function aktiv(): bool {
		return class_exists( 'Two_Factor_Core' );
	}

	/** Gilt die Pflicht für diesen Benutzer (Vermieter oder Administrator)? */
	public static function pflichtig( int $user_id ): bool {
		if ( Mietv_Einstellungen::get( 'zfa_pflicht', '0' ) !== '1' ) {
			return false;
		}
		$u = get_userdata( $user_id );
		return $u && ( in_array( 'mietv_vermieter', (array) $u->roles, true ) || in_array( 'administrator', (array) $u->roles, true ) );
	}

	/** Hat der Benutzer 2FA eingerichtet? */
	public static function eingerichtet( int $user_id ): bool {
		if ( ! self::aktiv() || ! method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
			return false;
		}
		return (bool) Two_Factor_Core::is_user_using_two_factor( $user_id );
	}

	/**
	 * Pflicht durchsetzen: Vermieter ohne eingerichtete 2FA werden im Backend auf ihr
	 * Profil geleitet, bis ein Verfahren aktiviert ist (Two-Factor-Einstellungen sitzen dort).
	 */
	public static function erzwingen(): void {
		if ( ! self::aktiv() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! $uid || ! self::pflichtig( $uid ) || self::eingerichtet( $uid ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, [ 'profile.php', 'admin-ajax.php', 'admin-post.php' ], true ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'notice_profil' ] );
			return;
		}
		wp_safe_redirect( admin_url( 'profile.php#two-factor-options' ) );
		exit;
	}

	public static function notice_profil(): void {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Mietfux requires two-factor authentication for landlords. Please enable a method under “Two-Factor Options” below – the Mietfux pages are available again afterwards.', 'mietfux' ) . '</p></div>';
	}

	/** Statuszeile für die Einstellungsseite. */
	public static function status_text(): string {
		if ( ! self::aktiv() ) {
			return __( 'The free “Two-Factor” plugin (by the WordPress core team) is not installed. Install and activate it under Plugins → Add New; Mietfux then enforces it for landlords when “Required” is selected.', 'mietfux' );
		}
		$uid = get_current_user_id();
		return self::eingerichtet( $uid )
			? __( 'Two-Factor is active and set up for your account.', 'mietfux' )
			: __( 'Two-Factor is active, but not yet set up for your account – see your profile, “Two-Factor Options”.', 'mietfux' );
	}

	/** Hinweise für die Übersicht. */
	public static function hinweise(): array {
		$uid = get_current_user_id();
		if ( ! $uid || ! current_user_can( Mietv_Admin::CAP ) ) {
			return [];
		}
		if ( ! self::aktiv() ) {
			return [
				[
					'typ'  => 'info',
					'text' => __( 'Protect the landlord login with two-factor authentication – install the “Two-Factor” plugin (recommended in Settings → Security)', 'mietfux' ),
					'url'  => current_user_can( 'install_plugins' ) ? admin_url( 'plugin-install.php?s=two-factor&tab=search&type=term' ) : admin_url( 'admin.php?page=mietv_einstellungen' ),
				],
			];
		}
		if ( ! self::eingerichtet( $uid ) ) {
			return [
				[
					'typ'  => 'warn',
					'text' => __( 'Two-factor authentication is not yet set up for your account', 'mietfux' ),
					'url'  => admin_url( 'profile.php#two-factor-options' ),
				],
			];
		}
		return [];
	}
}
