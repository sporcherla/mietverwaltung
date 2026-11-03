<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zwei-Faktor-Anmeldung (TOTP, RFC 6238) ohne Zusatz-Plugin.
 *
 * - Einstellung: aus / freiwillig / Pflicht für Vermieter und Administratoren.
 * - Einrichtung in Mietfux → Einstellungen: QR-Code für Authenticator-Apps, Bestätigungscode, Backup-Codes.
 * - Anmeldung: nach Benutzername/Passwort zweiter Schritt auf wp-login.php (6-stelliger Code oder Backup-Code),
 *   gebremst gegen Raten (5 Versuche je Anlauf), Anlauf läuft nach 5 Minuten ab.
 * - Geheimnis liegt verschlüsselt (sodium, Schlüssel aus wp_salt) in den Benutzer-Metadaten.
 * - Ist das Plugin „Two-Factor“ (WordPress-Core-Team) aktiv, hält sich Mietfux zurück.
 */
class Mietv_Zfa {

	const META_SECRET = 'mietv_zfa_secret';
	const META_AKTIV  = 'mietv_zfa_aktiv';
	const META_BACKUP = 'mietv_zfa_backup';
	const META_NEU    = 'mietv_zfa_neu';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'erzwingen' ] );
		add_action( 'admin_post_mietv_zfa', [ __CLASS__, 'handle' ] );
		add_action( 'wp_login', [ __CLASS__, 'nach_login' ], 10, 2 );
		add_action( 'login_form_mietv_zfa', [ __CLASS__, 'login_schritt' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'login_message', [ __CLASS__, 'login_message' ] );
	}

	/** Hinweis auf der Anmeldeseite nach abgebrochenem zweiten Schritt. */
	public static function login_message( $msg ) {
		if ( isset( $_GET['mietv_zfa'] ) && sanitize_key( $_GET['mietv_zfa'] ) === 'abgebrochen' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter.
			$msg .= '<div id="login_error" class="notice notice-error"><p>' . esc_html__( 'Too many wrong codes – please log in again. If your phone is unavailable, use a backup code.', 'mietfux' ) . '</p></div>';
		}
		return $msg;
	}

	/* ------------------------------------------------------------------ */
	/* Status                                                             */
	/* ------------------------------------------------------------------ */

	/** Fremdes Two-Factor-Plugin aktiv? Dann übernimmt es. */
	public static function fremd_aktiv(): bool {
		return class_exists( 'Two_Factor_Core' );
	}

	/** Modus: 0 = aus, optional, pflicht. */
	public static function modus(): string {
		$m = (string) Mietv_Einstellungen::get( 'zfa_pflicht', '0' );
		return in_array( $m, [ 'optional', 'pflicht' ], true ) ? $m : ( $m === '1' ? 'pflicht' : '0' );
	}

	/** Gilt die Pflicht für diesen Benutzer (Vermieter oder Administrator)? */
	public static function pflichtig( int $user_id ): bool {
		if ( self::modus() !== 'pflicht' ) {
			return false;
		}
		$u = get_userdata( $user_id );
		return $u && ( in_array( 'mietv_vermieter', (array) $u->roles, true ) || in_array( 'administrator', (array) $u->roles, true ) );
	}

	/** Hat der Benutzer 2FA aktiv eingerichtet? */
	public static function eingerichtet( int $user_id ): bool {
		if ( self::fremd_aktiv() && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
			return (bool) Two_Factor_Core::is_user_using_two_factor( $user_id );
		}
		return (bool) get_user_meta( $user_id, self::META_AKTIV, true ) && self::secret( $user_id ) !== '';
	}

	/* ------------------------------------------------------------------ */
	/* TOTP                                                               */
	/* ------------------------------------------------------------------ */

	/** Base32-Kodierung (RFC 4648) für Authenticator-Apps. */
	public static function base32_encode( string $bin ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits     = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alphabet[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32      = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', $b32 ) );
		$bits     = '';
		foreach ( str_split( $b32 ) as $c ) {
			$bits .= str_pad( decbin( strpos( $alphabet, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $chunk ) {
			if ( strlen( $chunk ) === 8 ) {
				$out .= chr( bindec( $chunk ) );
			}
		}
		return $out;
	}

	/** TOTP-Code für einen Zeitschritt (30 s), 6 Stellen, SHA-1 (RFC 6238). */
	public static function code( string $secret_b32, ?int $zeit = null, int $schritt = 0 ): string {
		$zeit    = $zeit ?? time();
		$counter = (int) floor( $zeit / 30 ) + $schritt;
		$msg     = pack( 'N*', 0 ) . pack( 'N*', $counter );
		$hash    = hash_hmac( 'sha1', $msg, self::base32_decode( $secret_b32 ), true );
		$offset  = ord( $hash[19] ) & 0x0F;
		$wert    = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 ) | ( ord( $hash[ $offset + 1 ] ) << 16 ) | ( ord( $hash[ $offset + 2 ] ) << 8 ) | ord( $hash[ $offset + 3 ] );
		return str_pad( (string) ( $wert % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/** Code prüfen – aktueller Zeitschritt ±1 (Uhrenabweichung). */
	public static function code_ok( string $secret_b32, string $eingabe, ?int $zeit = null ): bool {
		$eingabe = preg_replace( '/\D/', '', $eingabe );
		if ( strlen( $eingabe ) !== 6 ) {
			return false;
		}
		foreach ( [ 0, -1, 1 ] as $s ) {
			if ( hash_equals( self::code( $secret_b32, $zeit, $s ), $eingabe ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Speicherung (verschlüsselt)                                        */
	/* ------------------------------------------------------------------ */

	private static function schluessel(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|mietv_zfa', true );
	}

	public static function verschluesseln( string $klar ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $klar, $nonce, self::schluessel() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transportkodierung des verschlüsselten TOTP-Geheimnisses.
		}
		$iv = random_bytes( 16 );
		return 'o1:' . base64_encode( $iv . openssl_encrypt( $klar, 'aes-256-cbc', self::schluessel(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transportkodierung des verschlüsselten TOTP-Geheimnisses.
	}

	public static function entschluesseln( string $chiffre ): string {
		$typ = substr( $chiffre, 0, 3 );
		$roh = base64_decode( substr( $chiffre, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transportkodierung des verschlüsselten TOTP-Geheimnisses.
		if ( $roh === false ) {
			return '';
		}
		if ( $typ === 's1:' && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			$r = sodium_crypto_secretbox_open( substr( $roh, $n ), substr( $roh, 0, $n ), self::schluessel() );
			return $r === false ? '' : $r;
		}
		if ( $typ === 'o1:' ) {
			$r = openssl_decrypt( substr( $roh, 16 ), 'aes-256-cbc', self::schluessel(), OPENSSL_RAW_DATA, substr( $roh, 0, 16 ) );
			return $r === false ? '' : $r;
		}
		return '';
	}

	/** Aktives Geheimnis (Base32) oder ''. */
	public static function secret( int $user_id ): string {
		$c = (string) get_user_meta( $user_id, self::META_SECRET, true );
		return $c ? self::entschluesseln( $c ) : '';
	}

	/** Neues, noch unbestätigtes Geheimnis erzeugen bzw. das laufende liefern. */
	public static function neues_secret( int $user_id ): string {
		$c = (string) get_user_meta( $user_id, self::META_NEU, true );
		$s = $c ? self::entschluesseln( $c ) : '';
		if ( $s === '' ) {
			$s = self::base32_encode( random_bytes( 20 ) );
			update_user_meta( $user_id, self::META_NEU, self::verschluesseln( $s ) );
		}
		return $s;
	}

	/** otpauth-URI für den QR-Code. */
	public static function otpauth( int $user_id, string $secret ): string {
		$u      = get_userdata( $user_id );
		$issuer = 'Mietfux ' . wp_parse_url( home_url(), PHP_URL_HOST );
		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . ( $u ? $u->user_login : $user_id ) ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=6&period=30';
	}

	/** Backup-Codes erzeugen (gehasht speichern, Klartext einmalig zurückgeben). */
	public static function backup_codes_erzeugen( int $user_id ): array {
		$klar = [];
		$hash = [];
		for ( $i = 0; $i < 8; $i++ ) {
			$c      = strtolower( wp_generate_password( 10, false, false ) );
			$c      = substr( $c, 0, 5 ) . '-' . substr( $c, 5, 5 );
			$klar[] = $c;
			$hash[] = wp_hash_password( $c );
		}
		update_user_meta( $user_id, self::META_BACKUP, $hash );
		return $klar;
	}

	/** Backup-Code prüfen und verbrauchen. */
	public static function backup_code_ok( int $user_id, string $eingabe ): bool {
		$eingabe = strtolower( trim( $eingabe, " \t\n\r\0\x0B" ) );
		$hashes  = (array) get_user_meta( $user_id, self::META_BACKUP, true );
		foreach ( $hashes as $i => $h ) {
			if ( is_string( $h ) && wp_check_password( $eingabe, $h ) ) {
				unset( $hashes[ $i ] );
				update_user_meta( $user_id, self::META_BACKUP, array_values( $hashes ) );
				return true;
			}
		}
		return false;
	}

	public static function backup_codes_rest( int $user_id ): int {
		return count( (array) get_user_meta( $user_id, self::META_BACKUP, true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Einrichtung (Einstellungsseite)                                    */
	/* ------------------------------------------------------------------ */

	public static function assets( string $hook ): void {
		if ( strpos( $hook, 'mietv_einstellungen' ) !== false && ! self::fremd_aktiv() ) {
			wp_enqueue_script( 'mietv-qrcode', MIETV_URL . 'assets/qrcode/qrcode.js', [], '1.4.4', true );
			wp_enqueue_script( 'mietv-zfa', MIETV_URL . 'assets/zfa.js', [ 'mietv-qrcode' ], MIETV_VERSION, true );
		}
	}

	public static function handle(): void {
		check_admin_referer( 'mietv_zfa' );
		$uid = get_current_user_id();
		if ( ! $uid || ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$aktion = isset( $_POST['zfa_aktion'] ) ? sanitize_key( $_POST['zfa_aktion'] ) : '';
		if ( $aktion === 'aktivieren' ) {
			$secret = self::neues_secret( $uid );
			$code   = isset( $_POST['zfa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['zfa_code'] ) ) : '';
			if ( ! self::code_ok( $secret, $code ) ) {
				mietv_flash_set( 'zfa_code_falsch' );
			} else {
				update_user_meta( $uid, self::META_SECRET, self::verschluesseln( $secret ) );
				update_user_meta( $uid, self::META_AKTIV, 1 );
				delete_user_meta( $uid, self::META_NEU );
				$codes = self::backup_codes_erzeugen( $uid );
				set_transient( 'mietv_zfa_codes_' . $uid, $codes, 300 );
				mietv_flash_set( 'zfa_aktiv' );
				do_action( 'mietv_protokoll', 'aendern', 'benutzer', $uid, __( 'Two-factor authentication enabled', 'mietfux' ), [] );
			}
		} elseif ( $aktion === 'deaktivieren' ) {
			delete_user_meta( $uid, self::META_SECRET );
			delete_user_meta( $uid, self::META_AKTIV );
			delete_user_meta( $uid, self::META_BACKUP );
			delete_user_meta( $uid, self::META_NEU );
			mietv_flash_set( 'zfa_aus' );
			do_action( 'mietv_protokoll', 'aendern', 'benutzer', $uid, __( 'Two-factor authentication disabled', 'mietfux' ), [] );
		} elseif ( $aktion === 'codes' ) {
			$codes = self::backup_codes_erzeugen( $uid );
			set_transient( 'mietv_zfa_codes_' . $uid, $codes, 300 );
			mietv_flash_set( 'zfa_codes' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen#mietv-zfa' ) );
		exit;
	}

	/** Abschnitt „Meine Zwei-Faktor-Anmeldung“ auf der Einstellungsseite. */
	public static function box(): void {
		$uid = get_current_user_id();
		echo '<h3 id="mietv-zfa">' . esc_html__( 'My two-factor login', 'mietfux' ) . '</h3>';
		if ( self::fremd_aktiv() ) {
			echo '<p>' . esc_html__( 'The “Two-Factor” plugin is active and handles the second login step; set it up in your profile under “Two-Factor Options”.', 'mietfux' ) . '</p>';
			return;
		}
		if ( self::modus() === '0' ) {
			echo '<p class="description">' . esc_html__( 'Two-factor login is switched off above. Set it to “Optional” or “Required” and save – then you can set up your authenticator app here.', 'mietfux' ) . '</p>';
			return;
		}
		$codes = get_transient( 'mietv_zfa_codes_' . $uid );
		if ( $codes ) {
			delete_transient( 'mietv_zfa_codes_' . $uid );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Backup codes – shown only once. Store them safely (password manager, printout). Each code works once if your phone is unavailable.', 'mietfux' ) . '</strong></p><p><code style="font-size:15px;line-height:2">' . esc_html( implode( ' · ', (array) $codes ) ) . '</code></p></div>';
		}
		if ( self::eingerichtet( $uid ) ) {
			printf(
				'<p>%s <span class="mv-pos">✓</span> · %s</p><form method="post" action="%s">%s<input type="hidden" name="action" value="mietv_zfa"><button name="zfa_aktion" value="codes" class="button">%s</button> <button name="zfa_aktion" value="deaktivieren" class="button-link mv-delete" data-confirm="%s">%s</button></form>',
				esc_html__( 'Two-factor login is active for your account.', 'mietfux' ),
				esc_html( sprintf( /* translators: %d: Anzahl */ __( '%d backup codes left', 'mietfux' ), self::backup_codes_rest( $uid ) ) ),
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_kses( wp_nonce_field( 'mietv_zfa', '_wpnonce', true, false ), mietv_kses_html_tags() ),
				esc_html__( 'New backup codes', 'mietfux' ),
				esc_attr__( 'Disable two-factor login for your account?', 'mietfux' ),
				esc_html__( 'Disable', 'mietfux' )
			);
			return;
		}
		$secret = self::neues_secret( $uid );
		printf(
			'<p>%s</p><div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start"><div id="mv-zfa-qr" data-otpauth="%s" style="width:180px;height:180px;background:#fff;padding:8px;border:1px solid #ddd"></div><div><p><strong>%s</strong><br><code style="font-size:14px;letter-spacing:1px">%s</code></p><form method="post" action="%s">%s<input type="hidden" name="action" value="mietv_zfa"><p><label>%s<br><input type="text" name="zfa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" class="regular-text" style="max-width:160px;font-size:18px;letter-spacing:4px"></label></p><p><button name="zfa_aktion" value="aktivieren" class="button button-primary">%s</button></p></form></div></div>',
			esc_html__( 'Scan the QR code with an authenticator app (e.g. Aegis, FreeOTP, Microsoft or Google Authenticator, 1Password) or enter the key manually, then confirm with the current 6-digit code.', 'mietfux' ),
			esc_attr( self::otpauth( $uid, $secret ) ),
			esc_html__( 'Key for manual entry', 'mietfux' ),
			esc_html( chunk_split( $secret, 4, ' ' ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_kses( wp_nonce_field( 'mietv_zfa', '_wpnonce', true, false ), mietv_kses_html_tags() ),
			esc_html__( 'Code from the app', 'mietfux' ),
			esc_html__( 'Enable two-factor login', 'mietfux' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Anmeldung – zweiter Schritt                                        */
	/* ------------------------------------------------------------------ */

	/** Nach erfolgreichem Passwort-Login: Sitzung zurücknehmen und Code verlangen. */
	public static function nach_login( string $user_login, $user ): void {
		if ( self::fremd_aktiv() || ! $user instanceof WP_User || self::modus() === '0' || ! self::eingerichtet( (int) $user->ID ) ) {
			return;
		}
		wp_clear_auth_cookie();
		$token    = wp_generate_password( 32, false, false );
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Weiterleitungsziel aus dem Login-Formular, wird mit wp_safe_redirect geprüft.
		$remember = ! empty( $_POST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Login-Formular von WordPress.
		set_transient(
			'mietv_zfa_login_' . $token,
			[
				'user_id'  => (int) $user->ID,
				'versuche' => 0,
				'redirect' => $redirect,
				'remember' => $remember,
			],
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				[
					'action' => 'mietv_zfa',
					'token'  => $token,
				],
				wp_login_url()
			)
		);
		exit;
	}

	/** wp-login.php?action=mietv_zfa – Formular anzeigen und Code prüfen. */
	public static function login_schritt(): void {
		$token  = isset( $_REQUEST['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Einmal-Token aus dem Login-Ablauf, selbst die Prüfgröße.
		$daten  = $token ? get_transient( 'mietv_zfa_login_' . $token ) : false;
		$fehler = '';
		if ( ! $daten ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		if ( isset( $_POST['zfa_code'] ) ) {
			check_admin_referer( 'mietv_zfa_login_' . $token );
			$code = sanitize_text_field( wp_unslash( $_POST['zfa_code'] ) );
			$uid  = (int) $daten['user_id'];
			$ok   = self::code_ok( self::secret( $uid ), $code ) || self::backup_code_ok( $uid, $code );
			if ( $ok ) {
				delete_transient( 'mietv_zfa_login_' . $token );
				wp_set_auth_cookie( $uid, (bool) $daten['remember'] );
				do_action( 'mietv_protokoll', 'login_portal', 'benutzer', $uid, __( 'Login with second factor', 'mietfux' ), [] );
				wp_safe_redirect( $daten['redirect'] ?: admin_url() );
				exit;
			}
			++$daten['versuche'];
			if ( $daten['versuche'] >= 5 ) {
				delete_transient( 'mietv_zfa_login_' . $token );
				wp_safe_redirect( add_query_arg( 'mietv_zfa', 'abgebrochen', wp_login_url() ) );
				exit;
			}
			set_transient( 'mietv_zfa_login_' . $token, $daten, 5 * MINUTE_IN_SECONDS );
			$fehler = __( 'The code is not valid. Please try again.', 'mietfux' );
		}
		self::login_formular( $token, $fehler, 5 - (int) $daten['versuche'] );
		exit;
	}

	private static function login_formular( string $token, string $fehler, int $rest ): void {
		login_header( __( 'Second login step', 'mietfux' ), '', $fehler ? new WP_Error( 'mietv_zfa', $fehler ) : null );
		?>
		<form name="mietv_zfa_form" id="loginform" method="post" action="
		<?php
		echo esc_url(
			add_query_arg(
				[
					'action' => 'mietv_zfa',
					'token'  => $token,
				],
				wp_login_url()
			)
		);
		?>
																			">
			<p><?php esc_html_e( 'Enter the 6-digit code from your authenticator app – or one of your backup codes.', 'mietfux' ); ?></p>
			<p>
				<label for="zfa_code"><?php esc_html_e( 'Code', 'mietfux' ); ?></label>
				<input type="text" name="zfa_code" id="zfa_code" class="input" inputmode="numeric" autocomplete="one-time-code" autofocus>
			</p>
			<?php wp_nonce_field( 'mietv_zfa_login_' . $token ); ?>
			<p class="submit"><input type="submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Log in', 'mietfux' ); ?>"></p>
			<?php if ( $rest < 5 ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: Anzahl */ __( '%d attempts left', 'mietfux' ), $rest ) ); ?></p>
			<?php endif; ?>
		</form>
		<p id="nav"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Back to login', 'mietfux' ); ?></a></p>
		<?php
		login_footer( 'zfa_code' );
	}

	/* ------------------------------------------------------------------ */
	/* Pflicht durchsetzen, Hinweise, Statuszeile                         */
	/* ------------------------------------------------------------------ */

	/** Vermieter ohne eingerichtete 2FA werden im Backend auf die Einrichtung geleitet. */
	public static function erzwingen(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! $uid || ! self::pflichtig( $uid ) || self::eingerichtet( $uid ) ) {
			return;
		}
		global $pagenow;
		$ziel = self::fremd_aktiv() ? 'profile.php' : 'admin.php';
		if ( $pagenow === $ziel || in_array( $pagenow, [ 'admin-ajax.php', 'admin-post.php', 'profile.php' ], true ) ) {
			if ( ! self::fremd_aktiv() && $pagenow === 'admin.php' && ( isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '' ) !== 'mietv_einstellungen' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter.
				wp_safe_redirect( admin_url( 'admin.php?page=mietv_einstellungen#mietv-zfa' ) );
				exit;
			}
			add_action( 'admin_notices', [ __CLASS__, 'notice_pflicht' ] );
			return;
		}
		wp_safe_redirect( self::fremd_aktiv() ? admin_url( 'profile.php#two-factor-options' ) : admin_url( 'admin.php?page=mietv_einstellungen#mietv-zfa' ) );
		exit;
	}

	public static function notice_pflicht(): void {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Mietfux requires two-factor login for landlords. Please set it up below under “My two-factor login” – the other pages are available again afterwards.', 'mietfux' ) . '</p></div>';
	}

	/** Statuszeile für die Einstellungsseite. */
	public static function status_text(): string {
		if ( self::fremd_aktiv() ) {
			return __( 'The “Two-Factor” plugin is active and takes precedence; Mietfux only enforces it for landlords when “Required” is selected.', 'mietfux' );
		}
		return __( 'Built in, no extra plugin: authenticator app (TOTP) plus backup codes. “Optional” lets every landlord set it up; “Required” enforces it for landlords and administrators. Tenants in the portal are not affected.', 'mietfux' );
	}

	/** Hinweise für die Übersicht. */
	public static function hinweise(): array {
		$uid = get_current_user_id();
		if ( ! $uid || ! current_user_can( Mietv_Admin::CAP ) ) {
			return [];
		}
		if ( self::modus() === '0' && ! self::fremd_aktiv() ) {
			return [
				[
					'typ'  => 'info',
					'text' => __( 'Protect the landlord login with two-factor authentication – switch it on under Settings → Security', 'mietfux' ),
					'url'  => admin_url( 'admin.php?page=mietv_einstellungen' ),
				],
			];
		}
		if ( ! self::eingerichtet( $uid ) ) {
			return [
				[
					'typ'  => 'warn',
					'text' => __( 'Two-factor login is not yet set up for your account', 'mietfux' ),
					'url'  => self::fremd_aktiv() ? admin_url( 'profile.php#two-factor-options' ) : admin_url( 'admin.php?page=mietv_einstellungen#mietv-zfa' ),
				],
			];
		}
		return [];
	}
}
