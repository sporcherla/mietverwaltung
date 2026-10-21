<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AfA-Berechnung je Anlagegut und Jahr.
 * Linear: Bemessungsgrundlage × Satz, im Anschaffungsjahr zeitanteilig nach Monaten (§ 7 Abs. 1 S. 4 EStG).
 * Degressiv: Satz vom jeweiligen Restbuchwert (§ 7 Abs. 5a EStG), ebenfalls zeitanteilig im ersten Jahr.
 */
class Mietv_Afa {

	public static function alle(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT a.*, o.bezeichnung AS objekt FROM ' . $wpdb->prefix . 'mv_anlagegueter a
			 JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = a.objekt_id ORDER BY a.objekt_id, a.anschaffung'
		);
	}

	/** Abschreibbare Bemessungsgrundlage. */
	public static function basis( object $a ): float {
		$gesamt = (float) $a->ak + (float) $a->nebenkosten;
		if ( $a->typ === 'gebaeude' ) {
			$gesamt *= ( 1 - (float) $a->grundanteil / 100 );
		}
		return round( $gesamt, 2 );
	}

	/**
	 * AfA-Satz: eingetragen, sonst aus Nutzungsdauer, bei Gebäuden sonst gesetzlich (§ 7 Abs. 4 EStG)
	 * nach Baujahr des Objekts: vor 1925 → 2,5 %, Fertigstellung ab 2023 → 3 %, sonst 2 %.
	 */
	public static function satz( object $a ): float {
		$satz = (float) $a->satz;
		if ( $satz <= 0 && ! empty( $a->nutzungsdauer ) ) {
			$satz = 100 / (int) $a->nutzungsdauer;
		}
		if ( $satz <= 0 && ( $a->typ ?? '' ) === 'gebaeude' ) {
			$satz = self::gesetzlicher_satz( $a );
		}
		return $satz;
	}

	public static function gesetzlicher_satz( object $a ): float {
		global $wpdb;
		$baujahr = isset( $a->baujahr ) ? (int) $a->baujahr : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT baujahr FROM ' . $wpdb->prefix . 'mv_objekte WHERE id = %d', $a->objekt_id ?? 0 ) );
		if ( $baujahr > 0 && $baujahr < 1925 ) {
			return 2.5;
		}
		if ( $baujahr >= 2023 ) {
			return 3.0;
		}
		return 2.0;
	}

	/** Herkunft des Satzes für die Anzeige. */
	public static function satz_quelle( object $a ): string {
		if ( (float) $a->satz > 0 ) {
			return '';
		}
		if ( ! empty( $a->nutzungsdauer ) ) {
			return sprintf( __( 'aus Nutzungsdauer %d Jahre', 'mietverwaltung' ), (int) $a->nutzungsdauer );
		}
		if ( ( $a->typ ?? '' ) === 'gebaeude' ) {
			return __( 'gesetzlich nach Baujahr (§ 7 Abs. 4 EStG)', 'mietverwaltung' );
		}
		return __( 'kein Satz – bitte eintragen', 'mietverwaltung' );
	}

	/**
	 * Liefert für ein Anlagegut die AfA eines Jahres und den Restbuchwert zum 31.12.
	 * ['afa' => x, 'restwert' => y, 'monate' => n]
	 */
	public static function jahr( object $a, int $jahr ): array {
		$basis       = self::basis( $a );
		$satz        = self::satz( $a );
		$start       = (int) substr( $a->anschaffung, 0, 4 );
		$start_monat = (int) substr( $a->anschaffung, 5, 2 );
		$ende_jahr   = ( ! empty( $a->ende ) && $a->ende !== '0000-00-00' ) ? (int) substr( $a->ende, 0, 4 ) : null;
		$ende_monat  = $ende_jahr ? (int) substr( $a->ende, 5, 2 ) : 12;

		if ( $basis <= 0 || $satz <= 0 || $jahr < $start || ( $ende_jahr && $jahr > $ende_jahr ) ) {
			return [
				'afa'      => 0.0,
				'restwert' => $jahr < $start ? $basis : 0.0,
				'monate'   => 0,
			];
		}

		$restwert = $basis;
		$afa      = 0.0;
		$monate   = 0;
		for ( $j = $start; $j <= $jahr; $j++ ) {
			$monate = 12;
			if ( $j === $start ) {
				$monate = 13 - $start_monat;
			}
			if ( $ende_jahr && $j === $ende_jahr ) {
				$monate = min( $monate, $ende_monat - ( $j === $start ? $start_monat - 1 : 0 ) );
			}
			$grundlage = $a->methode === 'degressiv' ? $restwert : $basis;
			$afa       = round( $grundlage * $satz / 100 * $monate / 12, 2 );
			if ( $afa > $restwert ) {
				$afa = $restwert; // letztes Jahr: nur Restwert
			}
			$restwert = round( $restwert - $afa, 2 );
		}
		return [
			'afa'      => $afa,
			'restwert' => $restwert,
			'monate'   => $monate,
		];
	}

	/** Alle Anlagegüter mit AfA des Jahres. */
	public static function uebersicht( int $jahr ): array {
		$out = [];
		foreach ( self::alle() as $a ) {
			$r         = self::jahr( $a, $jahr );
			$ende_jahr = ( ! empty( $a->ende ) && $a->ende !== '0000-00-00' ) ? (int) substr( $a->ende, 0, 4 ) : null;
			if ( (int) substr( $a->anschaffung, 0, 4 ) > $jahr || ( $ende_jahr && $ende_jahr < $jahr ) ) {
				continue; // noch nicht angeschafft bzw. bereits ausgeschieden
			}
			$out[] = [
				'anlagegut' => $a,
				'basis'     => self::basis( $a ),
				'satz'      => self::satz( $a ),
				'quelle'    => self::satz_quelle( $a ),
				'afa'       => $r['afa'],
				'restwert'  => $r['restwert'],
				'monate'    => $r['monate'],
			];
		}
		return $out;
	}

	/**
	 * Prüfung anschaffungsnahe Herstellungskosten (§ 6 Abs. 1 Nr. 1a EStG):
	 * Erhaltungsaufwand (netto, ohne USt) innerhalb von 3 Jahren nach Anschaffung > 15 % der Gebäude-AK.
	 * Liefert je Gebäude: grenze, summe_netto, quote, bis (Ende 3-Jahres-Frist).
	 */
	public static function pruefung_15_prozent(): array {
		global $wpdb;
		$out = [];
		foreach ( self::alle() as $a ) {
			if ( $a->typ !== 'gebaeude' ) {
				continue;
			}
			$von    = $a->anschaffung;
			$bis    = date( 'Y-m-d', strtotime( $von . ' +3 years -1 day' ) );
			$brutto = (float) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(b.betrag),0) FROM ' . $wpdb->prefix . 'mv_belege b
				 JOIN ' . $wpdb->prefix . "mv_kostenarten k ON k.id = b.kostenart_id
				 WHERE b.objekt_id = %d AND k.anlage_v = 'erhaltung' AND b.datum BETWEEN %s AND %s",
					$a->objekt_id,
					$von,
					$bis
				)
			);
			$netto  = round( $brutto / 1.19, 2 );
			$grenze = round( self::basis( $a ) * 0.15, 2 );
			$out[]  = [
				'anlagegut' => $a,
				'von'       => $von,
				'bis'       => $bis,
				'netto'     => $netto,
				'grenze'    => $grenze,
				'quote'     => $grenze > 0 ? $netto / $grenze * 15 : 0,
				'aktiv'     => strtotime( $bis ) >= time(),
			];
		}
		return $out;
	}
}
