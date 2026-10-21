<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Druckfertige Briefe (DIN-5008-nah) als eigenständige HTML-Seite.
 * Der Browser erzeugt daraus per „Drucken → Als PDF sichern“ die Datei.
 */
class MV_Brief {

	/** Empfängerblock aus Mietverhältnis (Mieter + Objektadresse). */
	public static function empfaenger( object $mv ): string {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . mv_table( 'mieter' ) . " WHERE id = %d", $mv->mieter_id ) );
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT o.* FROM " . mv_table( 'objekte' ) . " o JOIN " . mv_table( 'einheiten' ) . " e ON e.objekt_id = o.id WHERE e.id = %d", $mv->einheit_id ) );
		$z = mv_mieter_personen( $m );
		if ( $o ) {
			$z[] = $o->strasse;
			$z[] = trim( $o->plz . ' ' . $o->ort );
		}
		return implode( "\n", array_filter( $z ) );
	}

	public static function anrede( object $mv ): string {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( "SELECT anrede, nachname, p_anrede, p_nachname FROM " . mv_table( 'mieter' ) . " WHERE id = %d", $mv->mieter_id ) );
		if ( ! $m ) {
			return __( 'Sehr geehrte Damen und Herren,', 'mietverwaltung' );
		}
		$teil = function ( $anrede, $nachname ) {
			if ( $anrede === 'Frau' ) {
				return sprintf( __( 'sehr geehrte Frau %s', 'mietverwaltung' ), $nachname );
			}
			if ( $anrede === 'Herr' ) {
				return sprintf( __( 'sehr geehrter Herr %s', 'mietverwaltung' ), $nachname );
			}
			return null;
		};
		$teile = array_filter( [ $teil( $m->anrede, $m->nachname ), mv_hat_partner( $m ) ? $teil( $m->p_anrede, $m->p_nachname ) : null ] );
		if ( ! $teile ) {
			return __( 'Sehr geehrte Damen und Herren,', 'mietverwaltung' );
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
		$zeilen = array_values( array_filter( array_map( 'trim', explode( "\n", MV_Einstellungen::get( 'vermieter_adresse' ) ) ) ) );
		$z = $zeilen[1] ?? ( $zeilen[0] ?? '' );
		return trim( preg_replace( '/^\d{5}\s*/', '', $z ) );
	}

	public static function absender_zeile(): string {
		return trim( MV_Einstellungen::get( 'vermieter_name' ) . ' · ' . str_replace( "\n", ' · ', MV_Einstellungen::get( 'vermieter_adresse' ) ), ' ·' );
	}

	/** PDF-Bytes eines Briefs. */
	public static function pdf( string $empfaenger, string $betreff, string $body, ?string $datum = null ): string {
		$datum = $datum ?: current_time( 'd.m.Y' );
		return MV_PDF::brief( self::absender_zeile(), $empfaenger, self::ort() . ', ' . $datum, $betreff, $body, MV_Einstellungen::get( 'vermieter_name' ) );
	}

	/**
	 * Ausgabe als PDF (Standard) oder als HTML-Druckseite (?format=html).
	 * $ablage = [objekt_id, mv_id, typ, bezeichnung, mieter_sichtbar] → zusätzlich in Dokumente ablegen, wenn ?ablegen=1.
	 */
	public static function ausgeben( string $titel, string $empfaenger, string $betreff, string $body, ?string $datum = null, bool $auto_print = true, ?array $ablage = null ): void {
		$E = MV_Einstellungen::class;
		$absender_zeile = self::absender_zeile();
		$datum = $datum ?: current_time( 'd.m.Y' );

		if ( ( $_GET['format'] ?? 'pdf' ) !== 'html' ) {
			$bytes = self::pdf( $empfaenger, $betreff, $body, $datum );
			if ( $ablage && ! empty( $_GET['ablegen'] ) ) {
				MV_PDF::ablegen( $bytes, $titel . '.pdf', (int) $ablage[0], $ablage[1] ? (int) $ablage[1] : null, $ablage[2], $ablage[3], ! empty( $ablage[4] ) );
			}
			MV_PDF::senden( $bytes, $titel . '.pdf' );
		}
		?>
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><title><?php echo esc_html( $titel ); ?></title>
<style>
@page { size: A4; margin: 20mm 20mm 20mm 25mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.45; color: #000; margin: 0; }
.seite { max-width: 165mm; margin: 0 auto; padding: 20px; }
.absender { font-size: 8pt; border-bottom: 1px solid #000; padding-bottom: 2px; margin-top: 25mm; width: 85mm; }
.empf { white-space: pre-line; margin-top: 4mm; height: 30mm; }
.datum { text-align: right; margin-top: -10mm; }
.betreff { font-weight: bold; margin: 12mm 0 6mm; }
table.liste { width: 100%; border-collapse: collapse; margin: 4mm 0; font-size: 10pt; }
table.liste th, table.liste td { padding: 3px 6px; border-bottom: 1px solid #ccc; text-align: right; vertical-align: top; }
table.liste th:first-child, table.liste td:first-child { text-align: left; }
table.liste th { background: #eee; }
table.liste tr.summe td { font-weight: bold; border-top: 2px solid #000; border-bottom: none; }
.hinweis { font-size: 9pt; color: #333; margin-top: 6mm; }
.toolbar { background: #1d2327; color: #fff; padding: 8px 16px; font-size: 13px; }
.toolbar button { margin-right: 8px; }
h2 { font-size: 12pt; margin: 8mm 0 2mm; }
@media print { .toolbar { display: none; } .seite { padding: 0; max-width: none; } }
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Drucken / Als PDF sichern</button><button onclick="window.close()">Schließen</button> <?php echo esc_html( $titel ); ?></div>
<div class="seite">
<div class="absender"><?php echo esc_html( $absender_zeile ); ?></div>
<div class="empf"><?php echo esc_html( $empfaenger ); ?></div>
<div class="datum"><?php echo esc_html( self::ort() . ', ' . $datum ); ?></div>
<div class="betreff"><?php echo esc_html( $betreff ); ?></div>
<?php echo $body; ?>
<p>Mit freundlichen Grüßen</p>
<p style="margin-top:14mm"><?php echo esc_html( $E::get( 'vermieter_name' ) ); ?></p>
</div>
<?php if ( $auto_print ) : ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},300);});</script><?php endif; ?>
</body></html>
		<?php
		exit;
	}

	/** Zahlungsaufforderungs-Absatz mit Bankverbindung. */
	public static function bankverbindung(): string {
		$iban = MV_Einstellungen::get( 'vermieter_iban' );
		if ( ! $iban ) {
			return '';
		}
		return '<p>Bankverbindung: ' . esc_html( MV_Einstellungen::get( 'vermieter_bank' ) ) . ', IBAN ' . esc_html( $iban ) . '</p>';
	}
}
