<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Druckfertige Briefe (DIN-5008-nah) als eigenständige HTML-Seite.
 * Der Browser erzeugt daraus per „Drucken → Als PDF sichern“ die Datei.
 */
class Mietv_Brief {

	/** Empfängerblock aus Mietverhältnis (Mieter + Objektadresse). */
	public static function empfaenger( object $mv ): string {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mv->mieter_id ) );
		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT o.* FROM ' . $wpdb->prefix . 'mv_objekte o JOIN ' . $wpdb->prefix . 'mv_einheiten e ON e.objekt_id = o.id WHERE e.id = %d', $mv->einheit_id ) );
		$z = mietv_mieter_personen( $m );
		if ( $o ) {
			$z[] = $o->strasse;
			$z[] = trim( $o->plz . ' ' . $o->ort, " \t\n\r\0\x0B" );
		}
		return implode( "\n", array_filter( $z ) );
	}

	public static function anrede( object $mv ): string {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT anrede, nachname, p_anrede, p_nachname FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mv->mieter_id ) );
		if ( ! $m ) {
			return __( 'Sehr geehrte Damen und Herren,', 'mietfux' );
		}
		$teil  = function ( $anrede, $nachname ) {
			if ( $anrede === 'Frau' ) {
				return sprintf( /* translators: %s: Wert */ __( 'sehr geehrte Frau %s', 'mietfux' ), $nachname );
			}
			if ( $anrede === 'Herr' ) {
				return sprintf( /* translators: %s: Wert */ __( 'sehr geehrter Herr %s', 'mietfux' ), $nachname );
			}
			return null;
		};
		$teile = array_filter( [ $teil( $m->anrede, $m->nachname ), mietv_hat_partner( $m ) ? $teil( $m->p_anrede, $m->p_nachname ) : null ] );
		if ( ! $teile ) {
			return __( 'Sehr geehrte Damen und Herren,', 'mietfux' );
		}
		$text = implode( ', ', $teile ) . ',';
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * Gibt die komplette HTML-Seite aus und beendet.
	 * $body ist bereits escaptes HTML.
	 */
	/** Ort aus der Vermieteradresse (zweite Zeile „PLZ Ort“). */
	public static function ort(): string {
		$zeilen = array_values( array_filter( array_map( 'trim', explode( "\n", Mietv_Einstellungen::get( 'vermieter_adresse' ) ) ) ) );
		$z      = $zeilen[1] ?? ( $zeilen[0] ?? '' );
		return trim( preg_replace( '/^\d{5}\s*/', '', $z ), " \t\n\r\0\x0B" );
	}

	public static function absender_zeile(): string {
		return trim( Mietv_Einstellungen::get( 'vermieter_name' ) . ' · ' . str_replace( "\n", ' · ', Mietv_Einstellungen::get( 'vermieter_adresse' ) ), ' ·' );
	}

	/** PDF-Bytes eines Briefs. */
	public static function pdf( string $empfaenger, string $betreff, string $body, ?string $datum = null ): string {
		$datum = $datum ?: current_time( 'd.m.Y' );
		return Mietv_PDF::brief( self::absender_zeile(), $empfaenger, self::ort() . ', ' . $datum, $betreff, $body, Mietv_Einstellungen::get( 'vermieter_name' ) );
	}

	/**
	 * Ausgabe als PDF (Standard) oder als HTML-Druckseite (?format=html).
	 * $ablage = [objekt_id, mv_id, typ, bezeichnung, mieter_sichtbar] → zusätzlich in Dokumente ablegen, wenn ?ablegen=1.
	 */
	public static function ausgeben( string $titel, string $empfaenger, string $betreff, string $body, ?string $datum = null, bool $auto_print = true, ?array $ablage = null ): void {
		$einst          = Mietv_Einstellungen::class;
		$absender_zeile = self::absender_zeile();
		$datum          = $datum ?: current_time( 'd.m.Y' );

		if ( ( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'pdf' ) !== 'html' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			$bytes = self::pdf( $empfaenger, $betreff, $body, $datum );
			if ( $ablage && Mietv_PDF::ablegen_gewuenscht() ) {
				Mietv_PDF::ablegen( $bytes, $titel . '.pdf', (int) $ablage[0], $ablage[1] ? (int) $ablage[1] : null, $ablage[2], $ablage[3], ! empty( $ablage[4] ) );
			}
			Mietv_PDF::senden( $bytes, $titel . '.pdf' );
		}
		?>
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><title><?php echo esc_html( $titel ); ?></title>
		<?php
		wp_enqueue_style( 'mietv-dokument-brief', MIETV_URL . 'assets/dokument-brief.css', [], MIETV_VERSION );
		wp_enqueue_script( 'mietv-dokument', MIETV_URL . 'assets/dokument.js', [], MIETV_VERSION, true );
		if ( $auto_print ) {
			wp_enqueue_script( 'mietv-druck', MIETV_URL . 'assets/druck.js', [], MIETV_VERSION, true );
		}
		wp_print_styles( 'mietv-dokument-brief' );
		?>
</head><body>
<div class="toolbar"><button type="button" data-action="print">Drucken / Als PDF sichern</button><button type="button" data-action="close">Schließen</button> <?php echo esc_html( $titel ); ?></div>
<div class="seite">
<div class="absender"><?php echo esc_html( $absender_zeile ); ?></div>
<div class="empf"><?php echo esc_html( $empfaenger ); ?></div>
<div class="datum"><?php echo esc_html( self::ort() . ', ' . $datum ); ?></div>
<div class="betreff"><?php echo esc_html( $betreff ); ?></div>
		<?php echo wp_kses_post( $body ); ?>
<p>Mit freundlichen Grüßen</p>
<p style="margin-top:14mm"><?php echo esc_html( $einst::get( 'vermieter_name' ) ); ?></p>
</div>
		<?php
		wp_print_scripts( 'mietv-dokument' );
		if ( $auto_print ) {
			wp_print_scripts( 'mietv-druck' );
		}
		?>
</body></html>
		<?php
		exit;
	}

	/** Zahlungsaufforderungs-Absatz mit Bankverbindung. */
	public static function bankverbindung(): string {
		$iban = Mietv_Einstellungen::get( 'vermieter_iban' );
		if ( ! $iban ) {
			return '';
		}
		return '<p>Bankverbindung: ' . esc_html( Mietv_Einstellungen::get( 'vermieter_bank' ) ) . ', IBAN ' . esc_html( $iban ) . '</p>';
	}
}
