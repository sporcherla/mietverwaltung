<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Soll/Ist-Berechnung je Mietverhältnis und Monat.
 */
class Mietv_Soll {

	/** Gültige Konditionen für einen Monat (YYYY-MM-01). */
	public static function konditionen( int $mv_id, string $monat ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'mv_konditionen WHERE mv_id = %d AND gueltig_ab <= %s ORDER BY gueltig_ab DESC, id DESC LIMIT 1',
				$mv_id,
				$monat
			)
		);
	}

	/** Ist-Zahlungen eines Monats. */
	public static function ist( int $mv_id, string $monat ): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(betrag),0) FROM ' . $wpdb->prefix . 'mv_zahlungen WHERE mv_id = %d AND monat = %s',
				$mv_id,
				$monat
			)
		);
	}

	/**
	 * Offene-Posten-Liste: ein Eintrag je Monat von Beginn bis Ende bzw. heute.
	 * Liefert ['rows' => [...], 'soll' => x, 'ist' => y, 'saldo' => z].
	 */
	public static function op_liste( object $mv ): array {
		$rows     = [];
		$soll_sum = 0.0;
		$ist_sum  = 0.0;
		$kum      = 0.0;

		$start = new DateTime( substr( $mv->beginn, 0, 7 ) . '-01' );
		$heute = new DateTime( current_time( 'Y-m' ) . '-01' );
		$ende  = $heute;
		if ( ! empty( $mv->ende ) && $mv->ende !== '0000-00-00' ) {
			$mietv_ende = new DateTime( substr( $mv->ende, 0, 7 ) . '-01' );
			if ( $mietv_ende < $heute ) {
				$ende = $mietv_ende;
			}
		}

		$cursor = clone $start;
		while ( $cursor <= $ende ) {
			$monat = $cursor->format( 'Y-m-01' );
			$k     = self::konditionen( (int) $mv->id, $monat );
			$soll  = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0.0;
			$ist   = self::ist( (int) $mv->id, $monat );
			$kum  += $ist - $soll;

			$rows[]    = [
				'monat'            => $monat,
				'kalt'             => $k ? (float) $k->kaltmiete : 0.0,
				'bk'               => $k ? (float) $k->bk_vz : 0.0,
				'hk'               => $k ? (float) $k->hk_vz : 0.0,
				'soll'             => $soll,
				'ist'              => $ist,
				'diff'             => $ist - $soll,
				'kum'              => $kum,
				'ohne_konditionen' => ! $k,
			];
			$soll_sum += $soll;
			$ist_sum  += $ist;
			$cursor->modify( '+1 month' );
		}

		return [
			'rows'  => $rows,
			'soll'  => $soll_sum,
			'ist'   => $ist_sum,
			'saldo' => $ist_sum - $soll_sum,
		];
	}

	/** Alle Mietverhältnisse mit Mieter-, Einheits- und Objektdaten. */
	public static function alle_mv( bool $nur_aktiv = false ): array {
		global $wpdb;
		$where = $nur_aktiv ? "WHERE (mv.ende IS NULL OR mv.ende = '0000-00-00' OR mv.ende >= CURDATE())" : '';
		return $wpdb->get_results(
			'SELECT mv.*, m.vorname, m.nachname, m.p_vorname, m.p_nachname, m.wp_user_id, e.bezeichnung AS einheit, o.bezeichnung AS objekt, o.heizung,
			        (SELECT GROUP_CONCAT(ze.bezeichnung) FROM ' . $wpdb->prefix . 'mv_mv_einheiten z JOIN ' . $wpdb->prefix . 'mv_einheiten ze ON ze.id = z.einheit_id WHERE z.mv_id = mv.id) AS zusatz
			 FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv
			 JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id
			 JOIN ' . $wpdb->prefix . 'mv_einheiten e ON e.id = mv.einheit_id
			 JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = e.objekt_id '
			. $where // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Konstante Bedingung ohne Nutzereingabe.
			. ' ORDER BY mv.beginn DESC'
		);
	}

	public static function mietv_label( object $mv ): string {
		$z = ! empty( $mv->zusatz ) ? ' + ' . str_replace( ',', ', ', $mv->zusatz ) : '';
		$p = ! empty( $mv->p_nachname ) ? ' & ' . trim( $mv->p_vorname . ' ' . $mv->p_nachname, " \t\n\r\0\x0B" ) : '';
		return $mv->nachname . ', ' . $mv->vorname . $p . ' – ' . $mv->objekt . ' / ' . $mv->einheit . $z;
	}

	/** Zusatzeinheiten eines Mietverhältnisses mit Mietanteil. */
	public static function zusatzeinheiten( int $mv_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT z.*, e.bezeichnung, e.typ, e.flaeche FROM ' . $wpdb->prefix . 'mv_mv_einheiten z JOIN ' . $wpdb->prefix . 'mv_einheiten e ON e.id = z.einheit_id WHERE z.mv_id = %d ORDER BY e.typ, e.bezeichnung', $mv_id ) );
	}
}
