<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * E-Mail-Versand von Schreiben (Abrechnung, Mieterhöhung, Kautionsabrechnung, Mahnung) an den Mieter
 * mit PDF-Anhang und Versandprotokoll (wp_mv_versand).
 *
 * Die Brieftexte liefern die Fachmodule über `Klasse::body( int $id ): array`
 * (Schlüssel: betreff, body, empfaenger, titel, datum?, mv_id?, objekt_id?, mieter_sichtbar?).
 * Dieses Modul erzeugt daraus das PDF, legt es in den Dokumenten ab und verschickt es.
 */
class Mietv_Versand {

	const NONCE     = 'mietv_versand';
	const PRO_SEITE = 50;

	/** Dokumenttyp => [ Klasse, Dokument-Typ in mv_dokumente, Anzeigename ] */
	private static function typen(): array {
		return [
			'abrechnung' => [ 'Mietv_Abrechnung', 'abrechnung', __( 'Service charge statement', 'mietfux' ) ],
			'erhoehung'  => [ 'Mietv_Erhoehung', 'sonstiges', __( 'Rent increase', 'mietfux' ) ],
			'kaution'    => [ 'Mietv_Kaution', 'sonstiges', __( 'Deposit settlement', 'mietfux' ) ],
			'mahnung'    => [ 'Mietv_Mahnung', 'sonstiges', __( 'Dunning letter', 'mietfux' ) ],
			'sonstiges'  => [ '', 'sonstiges', __( 'Other', 'mietfux' ) ],
		];
	}

	public static function typ_label( string $typ ): string {
		return self::typen()[ $typ ][2] ?? $typ;
	}

	public static function init(): void {
		add_action( 'admin_post_mietv_versand', [ __CLASS__, 'handle' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Tabelle                                                            */
	/* ------------------------------------------------------------------ */

	public static function tabellen( string $charset ): array {
		global $wpdb;
		return [
			'CREATE TABLE ' . $wpdb->prefix . "mv_versand (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zeit datetime DEFAULT NULL,
  mv_id bigint(20) unsigned NOT NULL,
  mieter_id bigint(20) unsigned NOT NULL DEFAULT 0,
  empfaenger varchar(190) NOT NULL DEFAULT '',
  betreff varchar(190) NOT NULL DEFAULT '',
  typ varchar(30) NOT NULL DEFAULT 'sonstiges',
  bezug_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dokument_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'gesendet',
  fehler text,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY mv_id (mv_id),
  KEY zeit (zeit)
) $charset;",
		];
	}

	/* ------------------------------------------------------------------ */
	/* Versand                                                            */
	/* ------------------------------------------------------------------ */

	/** Gültige E-Mail-Adressen eines Mieters (Hauptmieter + Partner), ohne Dubletten. */
	public static function empfaenger( int $mieter_id ): array {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT email, p_email FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mieter_id ) );
		if ( ! $m ) {
			return [];
		}
		$out = [];
		foreach ( [ $m->email, $m->p_email ] as $adr ) {
			$adr = trim( (string) $adr, " \t\n\r\0\x0B" );
			if ( $adr !== '' && is_email( $adr ) && ! in_array( strtolower( $adr ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $adr;
			}
		}
		return $out;
	}

	/** Deutsche Anrede für die E-Mail („Sehr geehrte Frau X, sehr geehrter Herr Y,“). */
	public static function anrede( ?object $m ): string {
		if ( ! $m ) {
			return 'Sehr geehrte Damen und Herren,';
		}
		$teil  = function ( string $anrede, string $vorname, string $nachname ): string {
			$name = trim( $nachname, " \t\n\r\0\x0B" );
			if ( $anrede === 'Frau' ) {
				return 'sehr geehrte Frau ' . $name;
			}
			if ( $anrede === 'Herr' ) {
				return 'sehr geehrter Herr ' . $name;
			}
			return 'sehr geehrte/r ' . trim( $vorname . ' ' . $nachname, " \t\n\r\0\x0B" );
		};
		$teile = [ $teil( (string) ( $m->anrede ?? '' ), (string) ( $m->vorname ?? '' ), (string) ( $m->nachname ?? '' ) ) ];
		if ( mietv_hat_partner( $m ) ) {
			$teile[] = $teil( (string) ( $m->p_anrede ?? '' ), (string) ( $m->p_vorname ?? '' ), (string) ( $m->p_nachname ?? '' ) );
		}
		$text = implode( ', ', $teile ) . ',';
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/** Signatur unter der Grußformel: Einstellung „versand_signatur“, sonst Name + Anschrift + Telefon/E-Mail. */
	public static function signatur(): string {
		$s = trim( (string) Mietv_Einstellungen::get( 'versand_signatur' ), " \t\n\r\0\x0B" );
		if ( $s !== '' ) {
			return $s;
		}
		$zeilen = [
			Mietv_Einstellungen::get( 'vermieter_name' ),
			Mietv_Einstellungen::get( 'vermieter_adresse' ),
			Mietv_Einstellungen::get( 'vermieter_telefon' ) ? 'Tel. ' . Mietv_Einstellungen::get( 'vermieter_telefon' ) : '',
			Mietv_Einstellungen::get( 'vermieter_email' ),
		];
		return implode( "\n", array_filter( array_map( fn( $z ) => trim( (string) $z, " \t\n\r\0\x0B" ), $zeilen ) ) );
	}

	/** HTML-Fragment (Brief-Teilmenge) → Klartext für die Mail. */
	public static function klartext( string $html ): string {
		if ( strpos( $html, '<' ) === false ) {
			return trim( $html, " \t\n\r\0\x0B" );
		}
		$t = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$t = str_replace( [ '</p>', '</tr>', '</li>', '</h1>', '</h2>', '</h3>' ], [ "\n\n", "\n", "\n", "\n\n", "\n\n", "\n\n" ], $t );
		$t = preg_replace( '#</t[dh]>\s*<t[dh][^>]*>#i', ' | ', $t );
		$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( "/[ \t]+\n/", "\n", $t );
		$t = preg_replace( "/\n{3,}/", "\n\n", $t );
		return trim( $t, " \t\n\r\0\x0B" );
	}

	/**
	 * Schreiben mit PDF-Anhang an den Mieter senden und protokollieren.
	 *
	 * @param int      $mv_id       Mietverhältnis.
	 * @param string   $typ         abrechnung|erhoehung|kaution|mahnung|sonstiges.
	 * @param int      $bezug_id    ID des Fachdatensatzes (z. B. abrechnung_ergebnis).
	 * @param string   $betreff     Betreff der Mail.
	 * @param string   $text        Anschreiben (Klartext oder Brief-HTML), ohne Anrede und Grußformel.
	 * @param string   $pdf_bytes   PDF-Inhalt.
	 * @param string   $dateiname   Dateiname des Anhangs.
	 * @param int|null $dokument_id mv_dokumente-ID der abgelegten PDF.
	 * @return true|WP_Error
	 */
	public static function senden( int $mv_id, string $typ, int $bezug_id, string $betreff, string $text, string $pdf_bytes, string $dateiname, ?int $dokument_id = null ) {
		global $wpdb;
		$typ = array_key_exists( $typ, self::typen() ) ? $typ : 'sonstiges';
		$mv  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $mv_id ) );
		if ( ! $mv ) {
			return new WP_Error( 'mietv_versand_mv', __( 'Tenancy not found.', 'mietfux' ) );
		}
		$mieter_id = (int) $mv->mieter_id;
		$mieter    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mieter_id ) );
		$an        = self::empfaenger( $mieter_id );
		$betreff   = trim( wp_strip_all_tags( $betreff ), " \t\n\r\0\x0B" );
		if ( ! $an ) {
			$fehler = sprintf( /* translators: %s: Mietername */ __( 'No valid e-mail address stored for tenant %s. Please add one under Tenants.', 'mietfux' ), mietv_mieter_name( $mieter ) );
			self::protokoll( $mv_id, $mieter_id, [], $betreff, $typ, $bezug_id, $dokument_id, 'fehler', $fehler );
			return new WP_Error( 'mietv_versand_keine_adresse', $fehler );
		}

		// Absender, Antwortadresse, Kopie
		$von_mail = trim( (string) Mietv_Einstellungen::get( 'vermieter_email' ), " \t\n\r\0\x0B" );
		$von_name = trim( (string) Mietv_Einstellungen::get( 'vermieter_name' ), " \t\n\r\0\x0B" );
		$headers  = [ 'Content-Type: text/plain; charset=UTF-8' ];
		if ( $von_mail && is_email( $von_mail ) ) {
			$headers[] = 'From: ' . ( $von_name ? self::mail_name( $von_name ) . ' <' . $von_mail . '>' : $von_mail );
			$headers[] = 'Reply-To: ' . $von_mail;
			if ( Mietv_Einstellungen::get( 'versand_kopie' ) === '1' ) {
				$headers[] = 'Cc: ' . $von_mail;
			}
		}
		$headers = apply_filters( 'mietv_versand_headers', $headers, $typ, $mv_id );

		// Text: Anrede, Anschreiben, Grußformel, Signatur
		$body = self::anrede( $mieter ) . "\n\n" . self::klartext( $text ) . "\n\n" . 'Mit freundlichen Grüßen' . "\n\n" . self::signatur();

		// PDF als temporäre Datei anhängen
		$anhaenge = [];
		$tmp      = '';
		if ( $pdf_bytes !== '' ) {
			$fs  = Mietv_PDF::filesystem();
			$tmp = wp_tempnam( sanitize_file_name( $dateiname ) );
			if ( $fs && $tmp && $fs->put_contents( $tmp, $pdf_bytes, FS_CHMOD_FILE ) ) {
				$anhaenge[] = $tmp;
			} else {
				$fehler = __( 'The PDF attachment could not be written to a temporary file.', 'mietfux' );
				self::protokoll( $mv_id, $mieter_id, $an, $betreff, $typ, $bezug_id, $dokument_id, 'fehler', $fehler );
				return new WP_Error( 'mietv_versand_tmp', $fehler );
			}
		}

		// Fehlermeldung des Mailers einfangen
		$mail_fehler = '';
		$fang        = function ( $e ) use ( &$mail_fehler ) {
			$mail_fehler = is_wp_error( $e ) ? $e->get_error_message() : (string) $e;
		};
		add_action( 'wp_mail_failed', $fang );
		$ok = (bool) wp_mail( $an, $betreff, $body, $headers, $anhaenge );
		remove_action( 'wp_mail_failed', $fang );
		if ( $tmp !== '' ) {
			wp_delete_file( $tmp );
		}

		if ( ! $ok ) {
			$fehler = $mail_fehler !== '' ? $mail_fehler : __( 'The mail server rejected the message (wp_mail returned false).', 'mietfux' );
			self::protokoll( $mv_id, $mieter_id, $an, $betreff, $typ, $bezug_id, $dokument_id, 'fehler', $fehler );
			return new WP_Error( 'mietv_versand_mail', $fehler );
		}
		self::protokoll( $mv_id, $mieter_id, $an, $betreff, $typ, $bezug_id, $dokument_id, 'gesendet', '' );
		return true;
	}

	/** Anzeigename für den From-Header (ohne Zeichen, die den Header brechen). */
	private static function mail_name( string $name ): string {
		return '"' . str_replace( [ '"', "\r", "\n" ], '', $name ) . '"';
	}

	/** Protokollzeile schreiben; liefert die ID. */
	private static function protokoll( int $mv_id, int $mieter_id, array $an, string $betreff, string $typ, int $bezug_id, ?int $dokument_id, string $status, string $fehler ): int {
		global $wpdb;
		$wpdb->insert(
			mietv_table( 'versand' ),
			[
				'zeit'        => current_time( 'mysql' ),
				'mv_id'       => $mv_id,
				'mieter_id'   => $mieter_id,
				'empfaenger'  => mb_substr( implode( ', ', $an ), 0, 190 ),
				'betreff'     => mb_substr( $betreff, 0, 190 ),
				'typ'         => $typ,
				'bezug_id'    => $bezug_id,
				'dokument_id' => $dokument_id ?: null,
				'status'      => $status,
				'fehler'      => $fehler,
				'user_id'     => (int) get_current_user_id(),
			]
		);
		$id = (int) $wpdb->insert_id;
		do_action( 'mietv_protokoll', 'export', 'versand', $id, $betreff );
		return $id;
	}

	/* ------------------------------------------------------------------ */
	/* Handler: PDF über das Fachmodul erzeugen, ablegen, senden          */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$typ   = isset( $_GET['typ'] ) ? sanitize_key( $_GET['typ'] ) : '';
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$mv_id = isset( $_GET['mv'] ) ? (int) $_GET['mv'] : 0;

		$ergebnis = self::dokument_senden( $typ, $id, $mv_id );
		if ( is_wp_error( $ergebnis ) ) {
			mietv_flash_set( 'versand_fehler', [ 'text' => $ergebnis->get_error_message() ] );
		} else {
			mietv_flash_set( 'versand_ok', [ 'an' => $ergebnis ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_versand' . ( $mv_id ? '&mv=' . $mv_id : '' ) ) );
		exit;
	}

	/**
	 * Schreiben eines Fachmoduls erzeugen (Klasse::body), als PDF ablegen und senden.
	 * Liefert die Empfängerliste (String) oder WP_Error.
	 *
	 * @return string|WP_Error
	 */
	public static function dokument_senden( string $typ, int $id, int $mv_id = 0 ) {
		global $wpdb;
		$typen = self::typen();
		if ( ! isset( $typen[ $typ ] ) || $typen[ $typ ][0] === '' ) {
			return new WP_Error( 'mietv_versand_typ', __( 'Unknown document type.', 'mietfux' ) );
		}
		$klasse = $typen[ $typ ][0];
		if ( ! class_exists( $klasse ) || ! method_exists( $klasse, 'body' ) ) {
			return new WP_Error(
				'mietv_versand_body',
				sprintf( /* translators: %s: Klassenname */ __( 'E-mail dispatch is not available for this document yet: %s::body() is missing.', 'mietfux' ), $klasse )
			);
		}
		$b = call_user_func( [ $klasse, 'body' ], $id );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		if ( ! is_array( $b ) || empty( $b['betreff'] ) || empty( $b['body'] ) || ! isset( $b['empfaenger'] ) ) {
			return new WP_Error( 'mietv_versand_body', __( 'The document could not be generated.', 'mietfux' ) );
		}
		$mv_id = (int) ( $b['mv_id'] ?? $mv_id );
		$mv    = $mv_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $mv_id ) ) : null;
		if ( ! $mv ) {
			return new WP_Error( 'mietv_versand_mv', __( 'Tenancy not found.', 'mietfux' ) );
		}
		$objekt_id = (int) ( $b['objekt_id'] ?? $wpdb->get_var( $wpdb->prepare( 'SELECT objekt_id FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) ) );
		$titel     = (string) ( $b['titel'] ?? $b['betreff'] );
		$datum     = ! empty( $b['datum'] ) ? (string) $b['datum'] : null;
		$pdf       = Mietv_Brief::pdf( (string) $b['empfaenger'], (string) $b['betreff'], (string) $b['body'], $datum );
		$dateiname = sanitize_file_name( $titel . '.pdf' );

		// In den Dokumenten ablegen (Versandnachweis) und mv_dokumente-ID ermitteln
		$att         = Mietv_PDF::ablegen( $pdf, $dateiname, $objekt_id, $mv_id, $typen[ $typ ][1], $titel . ' – ' . __( 'sent by e-mail', 'mietfux' ), ! empty( $b['mieter_sichtbar'] ) );
		$dokument_id = self::dokument_id( $att );

		$text = $b['mailtext'] ?? self::standardtext( $typ, (string) $b['betreff'] );
		$erg  = self::senden( $mv_id, $typ, $id, (string) $b['betreff'], $text, $pdf, $dateiname, $dokument_id );
		if ( is_wp_error( $erg ) ) {
			return $erg;
		}
		return implode( ', ', self::empfaenger( (int) $mv->mieter_id ) );
	}

	/** mv_dokumente-ID zu einer Attachment-ID aus Mietv_PDF::ablegen() (null, wenn nicht abgelegt). */
	public static function dokument_id( int $attachment_id ): ?int {
		global $wpdb;
		if ( ! $attachment_id ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mv_dokumente WHERE attachment_id = %d ORDER BY id DESC LIMIT 1', $attachment_id ) );
		return $id ?: null;
	}

	/** Deutsches Standard-Anschreiben je Dokumenttyp (das Schreiben selbst liegt als PDF bei). */
	public static function standardtext( string $typ, string $betreff ): string {
		switch ( $typ ) {
			case 'abrechnung':
				return "anbei erhalten Sie die Betriebs- und Heizkostenabrechnung als PDF-Datei.\n\nBitte prüfen Sie die Abrechnung; die Belege können nach Terminvereinbarung eingesehen werden.";
			case 'erhoehung':
				return "anbei erhalten Sie mein Schreiben zur Anpassung der Miete als PDF-Datei.\n\nDas Schreiben geht Ihnen zusätzlich auf dem Postweg zu, soweit das Gesetz die Schriftform verlangt.";
			case 'kaution':
				return 'anbei erhalten Sie die Abrechnung der Mietkaution als PDF-Datei.';
			case 'mahnung':
				return "anbei erhalten Sie mein Schreiben zu den offenen Mietzahlungen als PDF-Datei.\n\nBitte beachten Sie die darin genannte Frist.";
			default:
				return 'anbei erhalten Sie das Schreiben „' . $betreff . '“ als PDF-Datei.';
		}
	}

	/* ------------------------------------------------------------------ */
	/* UI                                                                 */
	/* ------------------------------------------------------------------ */

	/** Versand-Button für die Seiten der Fachmodule. */
	public static function button( string $typ, int $id, int $mv_id, string $label = '' ): string {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mietv_versand&typ=' . rawurlencode( $typ ) . '&id=' . $id . '&mv=' . $mv_id ),
			self::NONCE
		);
		return sprintf(
			'<a href="%s" class="button mv-versand" data-confirm="%s">✉ %s</a>',
			esc_url( $url ),
			esc_attr( sprintf( /* translators: %s: Dokumenttyp */ __( 'Send “%s” to the tenant by e-mail now? The PDF is filed under Documents.', 'mietfux' ), self::typ_label( $typ ) ) ),
			esc_html( $label !== '' ? $label : __( 'Send by e-mail', 'mietfux' ) )
		);
	}

	/** Protokollseite „Versandprotokoll“. */
	public static function page(): void {
		global $wpdb;
		$mv_filter = isset( $_GET['mv'] ) ? (int) $_GET['mv'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$jahr      = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$seite     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.

		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Dispatch log', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		$code  = $flash['code'] ?? '';
		if ( $code === 'versand_ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %s: E-Mail-Adressen */ esc_html__( 'E-mail sent to %s. The PDF has been filed under Documents.', 'mietfux' ) . '</p></div>', esc_html( (string) ( $flash['an'] ?? '' ) ) );
		} elseif ( $code === 'versand_fehler' ) {
			printf( '<div class="notice notice-error is-dismissible"><p>' . /* translators: %s: Fehlermeldung */ esc_html__( 'E-mail could not be sent: %s', 'mietfux' ) . '</p></div>', esc_html( (string) ( $flash['text'] ?? '' ) ) );
		}
		echo '<p class="description">' . esc_html__( 'Every letter sent by e-mail is recorded here with recipients, status and the filed PDF. Sender and reply address are taken from the landlord settings; a copy goes to you if “Copy to me” is enabled.', 'mietfux' ) . '</p>';

		// Filter
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_versand"><label>' . esc_html__( 'Tenancy', 'mietfux' ) . ' <select name="mv" data-autosubmit="1"><option value="0">' . esc_html__( 'All', 'mietfux' ) . '</option>';
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			printf( '<option value="%d"%s>%s</option>', (int) $mv->id, selected( $mv_filter, (int) $mv->id, false ), esc_html( Mietv_Soll::mietv_label( $mv ) ) );
		}
		echo '</select></label> <label>' . esc_html__( 'Year', 'mietfux' ) . ' <select name="jahr" data-autosubmit="1"><option value="0">' . esc_html__( 'All', 'mietfux' ) . '</option>';
		$jetzt = (int) current_time( 'Y' );
		for ( $j = $jetzt; $j >= $jetzt - 10; $j-- ) {
			printf( '<option value="%d"%s>%d</option>', (int) $j, selected( $jahr, $j, false ), (int) $j );
		}
		echo '</select></label></form>';

		// Abfrage
		// Filterwerte: mv = 0 → alle; Jahr 0 → gesamter Zeitraum (1970–2999)
		$von    = $jahr ? $jahr . '-01-01 00:00:00' : '1970-01-01 00:00:00';
		$bis    = $jahr ? ( $jahr + 1 ) . '-01-01 00:00:00' : '2999-01-01 00:00:00';
		$gesamt = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_versand v WHERE (%d = 0 OR v.mv_id = %d) AND v.zeit >= %s AND v.zeit < %s', $mv_filter, $mv_filter, $von, $bis ) );
		$seiten = max( 1, (int) ceil( $gesamt / self::PRO_SEITE ) );
		$seite  = min( $seite, $seiten );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT v.*, m.vorname, m.nachname, m.p_vorname, m.p_nachname, d.attachment_id FROM ' . $wpdb->prefix . 'mv_versand v
				 LEFT JOIN ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv ON mv.id = v.mv_id
				 LEFT JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id
				 LEFT JOIN ' . $wpdb->prefix . 'mv_dokumente d ON d.id = v.dokument_id
				 WHERE (%d = 0 OR v.mv_id = %d) AND v.zeit >= %s AND v.zeit < %s
				 ORDER BY v.zeit DESC, v.id DESC LIMIT %d OFFSET %d',
				$mv_filter,
				$mv_filter,
				$von,
				$bis,
				self::PRO_SEITE,
				( $seite - 1 ) * self::PRO_SEITE
			)
		);

		echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Time', 'mietfux' ) . '</th><th>' . esc_html__( 'Tenants', 'mietfux' ) . '</th><th>' . esc_html__( 'Type', 'mietfux' ) . '</th><th>' . esc_html__( 'Subject', 'mietfux' ) . '</th><th>' . esc_html__( 'Recipients', 'mietfux' ) . '</th><th>' . esc_html__( 'Status', 'mietfux' ) . '</th><th>' . esc_html__( 'Error', 'mietfux' ) . '</th><th>' . esc_html__( 'Document', 'mietfux' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No e-mails sent yet. Use “Send by e-mail” on a statement, rent increase, deposit settlement or dunning letter.', 'mietfux' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$url = $r->attachment_id ? wp_get_attachment_url( (int) $r->attachment_id ) : '';
			$ts  = $r->zeit ? strtotime( $r->zeit ) : 0;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $ts ? date_i18n( 'd.m.Y H:i', $ts ) : '–' ),
				esc_html( $r->nachname ? mietv_mieter_name( $r ) : '–' ),
				esc_html( self::typ_label( $r->typ ) ),
				esc_html( $r->betreff ),
				esc_html( $r->empfaenger !== '' ? $r->empfaenger : '–' ),
				$r->status === 'gesendet' ? 'mv-pos' : 'mv-neg',
				esc_html( $r->status === 'gesendet' ? __( 'sent', 'mietfux' ) : __( 'failed', 'mietfux' ) ),
				esc_html( $r->fehler ?: '' ),
				$url ? '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'Open PDF', 'mietfux' ) . '</a>' : '–'
			);
		}
		echo '</tbody></table>';

		if ( $seiten > 1 ) {
			$links = paginate_links(
				[
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $seite,
					'total'     => $seiten,
					'prev_text' => '‹',
					'next_text' => '›',
				]
			);
			if ( $links ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
			}
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Hinweise / Hilfe                                                   */
	/* ------------------------------------------------------------------ */

	/** Fehlgeschlagene Sendungen der letzten 30 Tage. */
	public static function hinweise(): array {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_versand WHERE status = 'fehler' AND zeit >= %s", gmdate( 'Y-m-d 00:00:00', time() - 30 * DAY_IN_SECONDS ) ) );
		if ( ! $n ) {
			return [];
		}
		return [
			[
				'typ'  => 'warn',
				'text' => sprintf( /* translators: %d: Anzahl */ _n( '%d e-mail could not be sent in the last 30 days.', '%d e-mails could not be sent in the last 30 days.', $n, 'mietfux' ), $n ),
				'url'  => admin_url( 'admin.php?page=mietv_versand' ),
			],
		];
	}

	public static function hilfe(): array {
		return [
			'versand' => [
				__( 'E-mail dispatch', 'mietfux' ),
				[
					__( 'Statements, rent increase letters, deposit settlements and dunning letters can be sent to the tenant by e-mail with the PDF attached. The message goes to all e-mail addresses stored for the tenant (main tenant and partner).', 'mietfux' ),
					__( 'Sender and reply address are the landlord e-mail from the settings. Enable “Copy to me” to receive a copy of every message; the signature under the closing can be set there as well.', 'mietfux' ),
					__( 'Each dispatch is logged with time, recipients, status and the filed PDF. Failed deliveries are shown with the mail server’s error message. Note: letters that legally require written form (e.g. a rent increase under § 558 BGB) must additionally be sent by post.', 'mietfux' ),
				],
			],
		];
	}
}
