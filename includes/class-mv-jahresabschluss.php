<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Jahresabschluss-Assistent: Checkliste für ein Jahr (Default: Vorjahr) mit Status je Schritt
 * (Dauerbelege, Hausgeld, Rückstände, Zählerstände, Betriebskostenabrechnung, Zinsbescheinigung,
 * Anlage V, Sicherung). Direkt ausführbare Schritte werden über admin-post gebucht, alle anderen
 * verlinken auf die Zielseite. Die Statusermittlung (schritte()) ist frei von Ausgabe und testbar.
 */
class Mietv_Jahresabschluss {

	const NONCE = 'mietv_jahresabschluss';

	public static function init(): void {
		add_action( 'admin_post_' . self::NONCE, [ __CLASS__, 'handle' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Hilfsfunktionen                                                    */
	/* ------------------------------------------------------------------ */

	public static function url( int $jahr ): string {
		return admin_url( 'admin.php?page=mietv_jahresabschluss&jahr=' . $jahr );
	}

	private static function jahr_aus_request(): int {
		$j = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		return $j > 1990 && $j < 2200 ? $j : (int) current_time( 'Y' ) - 1;
	}

	/** Mietverhältnisse, die am Stichtag (31.12. des Jahres) bestehen. */
	private static function mv_am_stichtag( int $jahr ): array {
		$stichtag = $jahr . '-12-31';
		$out      = [];
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			$ende = ( empty( $mv->ende ) || $mv->ende === '0000-00-00' ) ? null : $mv->ende;
			if ( $mv->beginn <= $stichtag && ( ! $ende || $ende >= $stichtag ) ) {
				$out[] = $mv;
			}
		}
		return $out;
	}

	/** Mietverhältnisse, die das Jahr berühren. */
	private static function mv_im_jahr( int $jahr ): array {
		$out = [];
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			$ende = ( empty( $mv->ende ) || $mv->ende === '0000-00-00' ) ? null : $mv->ende;
			if ( $mv->beginn <= $jahr . '-12-31' && ( ! $ende || $ende >= $jahr . '-01-01' ) ) {
				$out[] = $mv;
			}
		}
		return $out;
	}

	/** Objekte als id => Objekt. */
	private static function objekte(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte ORDER BY bezeichnung', OBJECT_K ) ?: [];
	}

	/** Grundgerüst eines Schritts. */
	private static function schritt( string $key, string $titel, string $status, string $text, array $extra = [] ): array {
		return array_merge(
			[
				'key'     => $key,
				'titel'   => $titel,
				'status'  => $status, // erledigt | offen | na
				'text'    => $text,
				'zahl'    => '',
				'details' => [],
				'pills'   => [],
				'aktion'  => [],
				'link'    => [],
			],
			$extra
		);
	}

	/* ------------------------------------------------------------------ */
	/* Statusermittlung                                                   */
	/* ------------------------------------------------------------------ */

	/** Offene Dauerbeleg-Fälligkeiten des Jahres: [ dauerbeleg_id => [ 'Y-m-d', … ] ]. */
	public static function dauerbelege_offen( int $jahr ): array {
		$out = [];
		foreach ( Mietv_Dauerbelege::offene() as $id => $termine ) {
			$t = array_values( array_filter( $termine, fn( $d ) => (int) substr( $d, 0, 4 ) === $jahr ) );
			if ( $t ) {
				$out[ (int) $id ] = $t;
			}
		}
		return $out;
	}

	/**
	 * Offene Hausgeld-Monate des Jahres (bis zum aktuellen Monat) und fehlende Jahresabrechnung:
	 * [ 'monate' => [ objekt_id => [ 'Y-m-01', … ] ], 'abrechnung_fehlt' => [ objekt_id, … ], 'objekte' => [ objekt_id, … ] ].
	 */
	public static function hausgeld_offen( int $jahr ): array {
		$out   = [
			'monate'           => [],
			'abrechnung_fehlt' => [],
			'objekte'          => [],
		];
		$heute = current_time( 'Y-m-01' );
		foreach ( Mietv_Hausgeld::alle_plaene() as $p ) {
			$oid = (int) $p->objekt_id;
			if ( in_array( $oid, $out['objekte'], true ) ) {
				continue;
			}
			$out['objekte'][] = $oid;
			$gebucht          = Mietv_Hausgeld::buchungen( $oid, $jahr );
			$fehlt            = [];
			for ( $m = 1; $m <= 12; $m++ ) {
				$monat = sprintf( '%04d-%02d-01', $jahr, $m );
				if ( $monat > $heute || isset( $gebucht[ $monat ] ) || ! Mietv_Hausgeld::plan_fuer( $oid, $monat ) ) {
					continue;
				}
				$fehlt[] = $monat;
			}
			if ( $fehlt ) {
				$out['monate'][ $oid ] = $fehlt;
			}
			if ( ! isset( $gebucht['abrechnung'] ) ) {
				$out['abrechnung_fehlt'][] = $oid;
			}
		}
		return $out;
	}

	/** Rückstände zum 31.12. je Mietverhältnis: [ mv_id => [ 'label' => …, 'saldo' => negativ ] ]. */
	public static function rueckstaende( int $jahr ): array {
		$out = [];
		foreach ( self::mv_im_jahr( $jahr ) as $mv ) {
			$ende = ( empty( $mv->ende ) || $mv->ende === '0000-00-00' ) ? $jahr . '-12-01' : min( substr( $mv->ende, 0, 7 ) . '-01', $jahr . '-12-01' );
			$kum  = 0.0;
			$c    = new DateTime( substr( $mv->beginn, 0, 7 ) . '-01' );
			while ( $c->format( 'Y-m-01' ) <= $ende ) {
				$monat = $c->format( 'Y-m-01' );
				$k     = Mietv_Soll::konditionen( (int) $mv->id, $monat );
				$soll  = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0.0;
				$kum  += Mietv_Soll::ist( (int) $mv->id, $monat ) - $soll;
				$c->modify( '+1 month' );
			}
			if ( $kum < -0.005 ) {
				$out[ (int) $mv->id ] = [
					'label' => Mietv_Soll::mietv_label( $mv ),
					'saldo' => round( $kum, 2 ),
				];
			}
		}
		return $out;
	}

	/**
	 * Fehlende Jahresend-Zählerstände (1.12. des Jahres bis 31.1. des Folgejahres) für selbst
	 * abzulesende Zähler: [ 'fehlt' => [ 'Text', … ], 'anzahl' => geprüfte Zähler, 'ablesedienst' => [ Objektname, … ] ].
	 */
	public static function zaehler_fehlend( int $jahr ): array {
		global $wpdb;
		$objekte = self::objekte();
		$out     = [
			'fehlt'        => [],
			'anzahl'       => 0,
			'ablesedienst' => [],
		];
		$von     = $jahr . '-12-01';
		$bis     = ( $jahr + 1 ) . '-01-31';
		$gesehen = [];
		foreach ( self::mv_am_stichtag( $jahr ) as $mv ) {
			$einheit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
			if ( ! $einheit ) {
				continue;
			}
			$oid = (int) $einheit->objekt_id;
			$ob  = $objekte[ $oid ] ?? null;
			if ( $ob && ( $ob->heizung_ablesung ?? 'eigen' ) === 'ablesedienst' && ! in_array( $ob->bezeichnung, $out['ablesedienst'], true ) ) {
				$out['ablesedienst'][] = $ob->bezeichnung;
			}
			$zaehler = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . "mv_zaehler WHERE objekt_id = %d AND (einheit_id = %d OR einheit_id IS NULL) AND ablesung <> 'ablesedienst' ORDER BY einheit_id, typ", $oid, $mv->einheit_id ) );
			foreach ( $zaehler as $z ) {
				if ( isset( $gesehen[ (int) $z->id ] ) ) {
					continue;
				}
				$gesehen[ (int) $z->id ] = true;
				++$out['anzahl'];
				$stand = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum BETWEEN %s AND %s LIMIT 1', $z->id, $von, $bis ) );
				if ( ! $stand ) {
					$out['fehlt'][] = ( $ob->bezeichnung ?? '#' . $oid ) . ' / ' . ( $z->einheit_id ? $einheit->bezeichnung : __( 'Building', 'mietfux' ) ) . ' – ' . $z->typ . ( $z->nummer ? ' ' . $z->nummer : '' );
				}
			}
		}
		return $out;
	}

	/**
	 * Betriebskostenabrechnungen je Objekt mit Mietverhältnissen im Jahr:
	 * [ objekt_id => [ 'objekt' => Name, 'abrechnung' => row|null, 'versendet' => bool ] ].
	 */
	public static function abrechnungen( int $jahr ): array {
		global $wpdb;
		$objekte = self::objekte();
		$out     = [];
		foreach ( self::mv_im_jahr( $jahr ) as $mv ) {
			$oid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT objekt_id FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
			if ( ! $oid || isset( $out[ $oid ] ) ) {
				continue;
			}
			$abr       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_abrechnungen WHERE objekt_id = %d AND von = %s AND bis = %s ORDER BY id DESC LIMIT 1', $oid, $jahr . '-01-01', $jahr . '-12-31' ) );
			$versendet = false;
			if ( $abr && class_exists( 'Mietv_Versand' ) ) {
				$versendet = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_versand WHERE typ = 'abrechnung' AND status = 'gesendet' AND bezug_id IN (SELECT id FROM " . $wpdb->prefix . 'mv_abrechnung_ergebnis WHERE abrechnung_id = %d)', $abr->id ) ) > 0;
			}
			$out[ $oid ] = [
				'objekt'     => $objekte[ $oid ]->bezeichnung ?? '#' . $oid,
				'abrechnung' => $abr,
				'versendet'  => $versendet,
			];
		}
		return $out;
	}

	/**
	 * Zinsbescheinigungen je aktivem Darlehen:
	 * [ darlehen_id => [ 'label' => …, 'bescheinigung' => row|null, 'berechnet' => array|null ] ].
	 */
	public static function zinsbescheinigungen( int $jahr ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . "mv_darlehen WHERE status = 'aktiv' ORDER BY bank, bezeichnung" ) as $d ) {
			if ( ! empty( $d->auszahlung ) && $d->auszahlung !== '0000-00-00' && $d->auszahlung > $jahr . '-12-31' ) {
				continue;
			}
			$b                   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_darlehen_jahre WHERE darlehen_id = %d AND jahr = %d', $d->id, $jahr ) );
			$out[ (int) $d->id ] = [
				'label'         => $d->bank . ( $d->bezeichnung ? ' – ' . $d->bezeichnung : '' ),
				'bescheinigung' => $b,
				'berechnet'     => ( ! $b && class_exists( 'Mietv_Darlehen' ) ) ? Mietv_Darlehen::jahreswerte( (int) $d->id, $jahr ) : null,
			];
		}
		return $out;
	}

	/** Alle Schritte des Jahres mit Status. */
	public static function schritte( int $jahr ): array {
		$s = [];

		// 1. Dauerbelege
		if ( class_exists( 'Mietv_Dauerbelege' ) ) {
			$offen = self::dauerbelege_offen( $jahr );
			$n     = array_sum( array_map( 'count', $offen ) );
			$namen = [];
			foreach ( Mietv_Dauerbelege::alle( true ) as $d ) {
				$namen[ (int) $d->id ] = $d->bezeichnung;
			}
			$details = [];
			foreach ( $offen as $id => $termine ) {
				$details[] = ( $namen[ $id ] ?? '#' . $id ) . ': ' . implode( ', ', array_map( 'mietv_date', $termine ) );
			}
			if ( ! $namen ) {
				$s[] = self::schritt( 'dauerbelege', __( 'Recurring expenses', 'mietfux' ), 'na', __( 'No active recurring expenses.', 'mietfux' ) );
			} else {
				$s[] = self::schritt(
					'dauerbelege',
					__( 'Recurring expenses', 'mietfux' ),
					$n ? 'offen' : 'erledigt',
					$n ? __( 'Due dates of the year that have not been booked yet. Booking creates one receipt per due date with the due date as payment date and the standard amount.', 'mietfux' ) : __( 'All due dates of the year are booked.', 'mietfux' ),
					[
						'zahl'    => $n ? sprintf( /* translators: %d: Anzahl */ _n( '%d open due date', '%d open due dates', $n, 'mietfux' ), $n ) : '',
						'details' => $details,
						'aktion'  => $n ? [
							'name'    => 'dauerbelege',
							'label'   => __( 'Book all due dates of the year', 'mietfux' ),
							'confirm' => sprintf( /* translators: %d: Anzahl */ __( 'Book %d due date(s) with the standard amount?', 'mietfux' ), $n ),
						] : [],
						'link'    => [ __( 'Recurring expenses', 'mietfux' ), admin_url( 'admin.php?page=mietv_dauerbelege' ) ],
					]
				);
			}
		}

		// 2. Hausgeld
		if ( class_exists( 'Mietv_Hausgeld' ) ) {
			$hg = self::hausgeld_offen( $jahr );
			if ( ! $hg['objekte'] ) {
				$s[] = self::schritt( 'hausgeld', __( 'Owners’ association fee', 'mietfux' ), 'na', __( 'No budget plan entered – only relevant for condominiums.', 'mietfux' ) );
			} else {
				$objekte = self::objekte();
				$n       = array_sum( array_map( 'count', $hg['monate'] ) );
				$details = [];
				foreach ( $hg['monate'] as $oid => $monate ) {
					$details[] = ( $objekte[ $oid ]->bezeichnung ?? '#' . $oid ) . ': ' . implode( ', ', array_map( 'mietv_monat', $monate ) );
				}
				$pills = [];
				foreach ( $hg['objekte'] as $oid ) {
					$fehlt   = in_array( $oid, $hg['abrechnung_fehlt'], true );
					$pills[] = [
						'label' => ( $objekte[ $oid ]->bezeichnung ?? '#' . $oid ) . ': ' . ( $fehlt ? sprintf( /* translators: %d: Jahr */ __( 'annual statement %d not booked', 'mietfux' ), $jahr ) : sprintf( /* translators: %d: Jahr */ __( 'annual statement %d booked', 'mietfux' ), $jahr ) ),
						'typ'   => $fehlt ? 'warn' : 'ok',
						'url'   => admin_url( 'admin.php?page=mietv_hausgeld&objekt_id=' . $oid . '&jahr=' . $jahr ),
					];
				}
				if ( $n ) {
					$text = __( 'Monthly fee payments of the year that have not been booked yet. Booking creates the receipts per cost type from the budget plan.', 'mietfux' );
				} elseif ( $hg['abrechnung_fehlt'] ) {
					$text = __( 'All monthly payments are booked. Book the owners’ association annual statement as soon as it arrives – it corrects the advance payments.', 'mietfux' );
				} else {
					$text = __( 'All monthly payments and the annual statement are booked.', 'mietfux' );
				}
				$s[] = self::schritt(
					'hausgeld',
					__( 'Owners’ association fee', 'mietfux' ),
					( $n || $hg['abrechnung_fehlt'] ) ? 'offen' : 'erledigt',
					$text,
					[
						'zahl'    => $n ? sprintf( /* translators: %d: Anzahl */ _n( '%d open month', '%d open months', $n, 'mietfux' ), $n ) : '',
						'details' => $details,
						'pills'   => $pills,
						'aktion'  => $n ? [
							'name'    => 'hausgeld',
							'label'   => __( 'Book open months', 'mietfux' ),
							'confirm' => sprintf( /* translators: %d: Anzahl */ __( 'Book %d month(s) with the 1st as payment date?', 'mietfux' ), $n ),
						] : [],
						'link'    => [ __( 'Owners’ association fee', 'mietfux' ), admin_url( 'admin.php?page=mietv_hausgeld&jahr=' . $jahr ) ],
					]
				);
			}
		}

		// 3. Zahlungen / Rückstände
		$rs      = self::rueckstaende( $jahr );
		$summe   = array_sum( array_column( $rs, 'saldo' ) );
		$details = [];
		foreach ( $rs as $r ) {
			$details[] = $r['label'] . ': ' . mietv_eur( -$r['saldo'] );
		}
		$s[] = self::schritt(
			'rueckstaende',
			__( 'Payments & arrears', 'mietfux' ),
			$rs ? 'offen' : 'erledigt',
			$rs ? __( 'Open rent as of 31 December – check payments, assign bank transactions or send a dunning letter. Arrears reduce the income of the year, not the expenses.', 'mietfux' ) : __( 'No arrears as of 31 December – all rent of the year is paid.', 'mietfux' ),
			[
				'zahl'    => $rs ? sprintf( /* translators: %s: Betrag */ __( 'Arrears %s', 'mietfux' ), mietv_eur( -$summe ) ) : '',
				'details' => $details,
				'link'    => [ $rs ? __( 'Overview', 'mietfux' ) : __( 'Payments', 'mietfux' ), admin_url( $rs ? 'admin.php?page=mietv_uebersicht' : 'admin.php?page=mietv_zahlungen' ) ],
			]
		);

		// 4. Zählerstände
		$zf      = self::zaehler_fehlend( $jahr );
		$details = $zf['fehlt'];
		foreach ( $zf['ablesedienst'] as $name ) {
			$details[] = sprintf( /* translators: %s: Objekt */ __( '%s: heating is read by a reading service – wait for its statement.', 'mietfux' ), $name );
		}
		if ( ! $zf['anzahl'] && ! $zf['ablesedienst'] ) {
			$s[] = self::schritt( 'zaehler', __( 'Meter readings', 'mietfux' ), 'na', __( 'No meters to read for current tenancies.', 'mietfux' ), [ 'link' => [ __( 'Meters', 'mietfux' ), admin_url( 'admin.php?page=mietv_zaehler' ) ] ] );
		} else {
			$n   = count( $zf['fehlt'] );
			$s[] = self::schritt(
				'zaehler',
				__( 'Meter readings', 'mietfux' ),
				$n ? 'offen' : 'erledigt',
				$n ? __( 'Year-end readings (1 December to 31 January) are missing for meters read by the tenant or landlord. Without them, consumption-based allocation is estimated.', 'mietfux' ) : __( 'Year-end readings are recorded for all meters read by tenant or landlord.', 'mietfux' ),
				[
					'zahl'    => $n ? sprintf( /* translators: %1$d: Anzahl, %2$d: Anzahl */ __( '%1$d of %2$d meters without reading', 'mietfux' ), $n, $zf['anzahl'] ) : '',
					'details' => $details,
					'link'    => [ __( 'Meter readings', 'mietfux' ), admin_url( 'admin.php?page=mietv_zaehlerstaende' ) ],
				]
			);
		}

		// 5. Betriebskostenabrechnung
		$abr = self::abrechnungen( $jahr );
		if ( ! $abr ) {
			$s[] = self::schritt( 'abrechnung', __( 'Service charge statement', 'mietfux' ), 'na', __( 'No tenancies in this year.', 'mietfux' ) );
		} else {
			$pills    = [];
			$aktionen = [];
			$fertig   = 0;
			foreach ( $abr as $oid => $a ) {
				if ( ! $a['abrechnung'] ) {
					$pills[]    = [
						'label' => $a['objekt'] . ': ' . __( 'no statement', 'mietfux' ),
						'typ'   => 'neg',
					];
					$aktionen[] = [
						'name'      => 'abrechnung',
						'objekt_id' => $oid,
						'label'     => sprintf( /* translators: %s: Objekt */ __( 'Create statement for %s', 'mietfux' ), $a['objekt'] ),
						'confirm'   => sprintf( /* translators: %1$s: Objekt, %2$d: Jahr */ __( 'Create and calculate the service charge statement %2$d for %1$s?', 'mietfux' ), $a['objekt'], $jahr ),
					];
					continue;
				}
				$st = $a['abrechnung']->status;
				if ( $a['versendet'] ) {
					$label = __( 'sent', 'mietfux' );
					$typ   = 'ok';
					++$fertig;
				} elseif ( $st === 'fertig' ) {
					$label = __( 'finalised', 'mietfux' );
					$typ   = 'ok';
					++$fertig;
				} else {
					$label = __( 'draft', 'mietfux' );
					$typ   = 'warn';
				}
				$pills[] = [
					'label' => $a['objekt'] . ': ' . $label,
					'typ'   => $typ,
					'url'   => admin_url( 'admin.php?page=mietv_abrechnung&id=' . (int) $a['abrechnung']->id ),
				];
			}
			$n   = count( $abr );
			$s[] = self::schritt(
				'abrechnung',
				__( 'Service charge statement', 'mietfux' ),
				$fertig === $n ? 'erledigt' : 'offen',
				sprintf( /* translators: %s: Datum */ __( 'One statement per property for the calendar year. Deadline: it must reach the tenants by %s (§ 556 (3) BGB) – afterwards additional claims are lost.', 'mietfux' ), mietv_date( ( $jahr + 1 ) . '-12-31' ) ),
				[
					'zahl'     => sprintf( /* translators: %1$d: Anzahl, %2$d: Anzahl */ __( '%1$d of %2$d properties finalised', 'mietfux' ), $fertig, $n ),
					'pills'    => $pills,
					'aktionen' => $aktionen,
					'link'     => [ __( 'Service charge statement', 'mietfux' ), admin_url( 'admin.php?page=mietv_abrechnung' ) ],
				]
			);
		}

		// 6. Zinsbescheinigung
		$zb = self::zinsbescheinigungen( $jahr );
		if ( ! $zb ) {
			$s[] = self::schritt( 'zinsen', __( 'Interest certificates', 'mietfux' ), 'na', __( 'No active loans.', 'mietfux' ) );
		} else {
			$pills    = [];
			$aktionen = [];
			$fertig   = 0;
			foreach ( $zb as $did => $z ) {
				if ( $z['bescheinigung'] ) {
					++$fertig;
					$pills[] = [
						'label' => $z['label'] . ': ' . sprintf( /* translators: %s: Betrag */ __( 'interest %s', 'mietfux' ), mietv_eur( $z['bescheinigung']->zinsen ) ),
						'typ'   => 'ok',
					];
				} elseif ( $z['berechnet'] ) {
					$pills[]    = [
						'label' => $z['label'] . ': ' . sprintf( /* translators: %s: Betrag */ __( 'calculated %s – no certificate yet', 'mietfux' ), mietv_eur( $z['berechnet']['zinsen'] ) ),
						'typ'   => 'warn',
					];
					$aktionen[] = [
						'name'        => 'zinsen',
						'darlehen_id' => $did,
						'label'       => sprintf( /* translators: %s: Darlehen */ __( 'Apply calculated values (provisional): %s', 'mietfux' ), $z['label'] ),
						'confirm'     => __( 'Apply the values calculated from the booked instalments as a provisional certificate? Replace them with the bank’s certificate when it arrives.', 'mietfux' ),
					];
				} else {
					$pills[] = [
						'label' => $z['label'] . ': ' . __( 'no certificate', 'mietfux' ),
						'typ'   => 'neg',
					];
				}
			}
			$n   = count( $zb );
			$s[] = self::schritt(
				'zinsen',
				__( 'Interest certificates', 'mietfux' ),
				$fertig === $n ? 'erledigt' : 'offen',
				$fertig === $n ? __( 'Interest, repayment and remaining debt are entered for all loans.', 'mietfux' ) : __( 'Enter interest, repayment and remaining debt from the bank’s annual certificate – the interest is deducted in Anlage V. Values applied from the loan account are provisional: replace them with the bank certificate.', 'mietfux' ),
				[
					'zahl'     => sprintf( /* translators: %1$d: Anzahl, %2$d: Anzahl */ __( '%1$d of %2$d loans certified', 'mietfux' ), $fertig, $n ),
					'pills'    => $pills,
					'aktionen' => $aktionen,
					'link'     => [ __( 'Interest certificate', 'mietfux' ), admin_url( 'admin.php?page=mietv_darlehen_jahre' ) ],
				]
			);
		}

		// 7. Anlage V
		if ( class_exists( 'Mietv_Steuer' ) ) {
			$offen_vorher = count( array_filter( $s, fn( $x ) => $x['status'] === 'offen' ) );
			$ein          = Mietv_Steuer::einnahmen( $jahr );
			$wk           = Mietv_Steuer::werbungskosten( $jahr );
			$ein_sum      = array_sum( array_column( $ein, 'miete' ) ) + array_sum( array_column( $ein, 'umlagen' ) );
			$wk_sum       = array_sum( array_column( $wk, 'summe' ) );
			$s[]          = self::schritt(
				'anlage_v',
				__( 'Tax (Anlage V)', 'mietfux' ),
				$offen_vorher ? 'offen' : 'erledigt',
				$offen_vorher ? __( 'Figures are provisional until the previous steps are completed.', 'mietfux' ) : __( 'All previous steps are completed – the figures are ready for the tax return.', 'mietfux' ),
				[
					'kpi'  => [
						[ __( 'Income', 'mietfux' ), mietv_eur( $ein_sum ), '' ],
						[ __( 'Expenses', 'mietfux' ), mietv_eur( $wk_sum ), '' ],
						[ __( 'Result', 'mietfux' ), mietv_eur( $ein_sum - $wk_sum ), $ein_sum - $wk_sum >= 0 ? 'mv-pos' : 'mv-neg' ],
					],
					'link' => [ __( 'Tax (Anlage V)', 'mietfux' ), admin_url( 'admin.php?page=mietv_steuer&jahr=' . $jahr ) ],
				]
			);
		}

		// 8. Sicherung
		if ( class_exists( 'Mietv_Sicherung' ) ) {
			$liste  = Mietv_Sicherung::liste();
			$letzte = $liste[0] ?? null;
			$ok     = $letzte && $letzte['zeit'] >= strtotime( ( $jahr + 1 ) . '-01-01' );
			$s[]    = self::schritt(
				'sicherung',
				__( 'Backup', 'mietfux' ),
				$ok ? 'erledigt' : 'offen',
				$ok ? __( 'A backup created after the end of the year exists.', 'mietfux' ) : __( 'Create a backup of all data after closing the year and keep it with the tax documents (retention period 10 years).', 'mietfux' ),
				[
					'zahl'   => $letzte ? sprintf( /* translators: %s: Datum */ __( 'Last backup: %s', 'mietfux' ), date_i18n( 'd.m.Y H:i', $letzte['zeit'] ) ) : __( 'No backup yet', 'mietfux' ),
					'aktion' => [
						'name'  => 'sicherung',
						'label' => __( 'Create backup now', 'mietfux' ),
					],
					'link'   => [ __( 'Settings & backup', 'mietfux' ), admin_url( 'admin.php?page=mietv_einstellungen' ) ],
				]
			);
		}

		return $s;
	}

	/** Fortschritt: [ erledigt (inkl. nicht zutreffend), gesamt, offen ]. */
	public static function fortschritt( array $schritte ): array {
		$offen = count( array_filter( $schritte, fn( $x ) => $x['status'] === 'offen' ) );
		return [ count( $schritte ) - $offen, count( $schritte ), $offen ];
	}

	/* ------------------------------------------------------------------ */
	/* Hinweise / Hilfe                                                   */
	/* ------------------------------------------------------------------ */

	public static function hinweise(): array {
		if ( (int) current_time( 'n' ) > 6 ) {
			return [];
		}
		$jahr  = (int) current_time( 'Y' ) - 1;
		$offen = self::fortschritt( self::schritte( $jahr ) )[2];
		if ( ! $offen ) {
			return [];
		}
		return [
			[
				'typ'  => 'info',
				'text' => sprintf( /* translators: %1$d: Jahr, %2$d: Anzahl */ _n( 'Year-end %1$d: %2$d step open', 'Year-end %1$d: %2$d steps open', $offen, 'mietfux' ), $jahr, $offen ),
				'url'  => self::url( $jahr ),
			],
		];
	}

	public static function hilfe(): array {
		return [
			'jahresabschluss' => [
				__( 'Year-end', 'mietfux' ),
				[
					__( 'Checklist for closing a year: book open recurring expenses and owners’ fee months, check arrears and year-end meter readings, create the service charge statements, enter the interest certificates, check Anlage V and create a backup. Each step shows its status and can be executed directly or opens the relevant page.', 'mietfux' ),
					__( 'The year selector defaults to the previous year. From January to June, the overview shows a notice while steps of the previous year are still open.', 'mietfux' ),
				],
			],
		];
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		global $wpdb;
		$jahr   = isset( $_POST['jahr'] ) ? (int) $_POST['jahr'] : (int) current_time( 'Y' ) - 1;
		$aktion = isset( $_POST['aktion'] ) ? sanitize_key( $_POST['aktion'] ) : '';
		$n      = 0;

		if ( $aktion === 'dauerbelege' && class_exists( 'Mietv_Dauerbelege' ) ) {
			foreach ( self::dauerbelege_offen( $jahr ) as $id => $termine ) {
				foreach ( $termine as $t ) {
					if ( Mietv_Dauerbelege::buchen( (int) $id, $t, $t ) ) {
						++$n;
					}
				}
			}
			mietv_flash_set( 'ja_dauerbelege', [ 'n' => $n ] );
		} elseif ( $aktion === 'hausgeld' && class_exists( 'Mietv_Hausgeld' ) ) {
			foreach ( self::hausgeld_offen( $jahr )['monate'] as $oid => $monate ) {
				foreach ( $monate as $monat ) {
					if ( Mietv_Hausgeld::buchen_monat( (int) $oid, $monat, $monat ) ) {
						++$n;
					}
				}
			}
			mietv_flash_set( 'ja_hausgeld', [ 'n' => $n ] );
		} elseif ( $aktion === 'abrechnung' && class_exists( 'Mietv_Abrechnung' ) ) {
			$oid  = isset( $_POST['objekt_id'] ) ? (int) $_POST['objekt_id'] : 0;
			$vorh = $oid ? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mv_abrechnungen WHERE objekt_id = %d AND von = %s AND bis = %s', $oid, $jahr . '-01-01', $jahr . '-12-31' ) ) : 0;
			$id   = 0;
			if ( $oid && ! $vorh ) {
				$id = $wpdb->insert(
					mietv_table( 'abrechnungen' ),
					[
						'owner_id'  => MIETV_OWNER_ID,
						'objekt_id' => $oid,
						'von'       => $jahr . '-01-01',
						'bis'       => $jahr . '-12-31',
						'status'    => 'entwurf',
						'erstellt'  => current_time( 'mysql' ),
					]
				) ? (int) $wpdb->insert_id : 0;
				if ( $id ) {
					Mietv_Abrechnung::berechnen( $id );
				}
			}
			mietv_flash_set( $id ? 'ja_abrechnung' : 'ja_nichts', [ 'id' => $id ] );
		} elseif ( $aktion === 'zinsen' && class_exists( 'Mietv_Darlehen' ) ) {
			$did = isset( $_POST['darlehen_id'] ) ? (int) $_POST['darlehen_id'] : 0;
			$z   = $did ? ( self::zinsbescheinigungen( $jahr )[ $did ] ?? null ) : null;
			if ( $z && ! $z['bescheinigung'] && $z['berechnet'] ) {
				$wpdb->insert(
					mietv_table( 'darlehen_jahre' ),
					[
						'darlehen_id' => $did,
						'jahr'        => $jahr,
						'zinsen'      => $z['berechnet']['zinsen'],
						'tilgung'     => $z['berechnet']['tilgung'],
						'restschuld'  => $z['berechnet']['restschuld'],
					]
				);
				$n = 1;
			}
			mietv_flash_set( $n ? 'ja_zinsen' : 'ja_nichts' );
		} elseif ( $aktion === 'sicherung' && class_exists( 'Mietv_Sicherung' ) ) {
			$datei = Mietv_Sicherung::erstellen();
			mietv_flash_set( $datei ? 'ja_sicherung' : 'ja_sicherung_fehler' );
		} else {
			mietv_flash_set( 'ja_nichts' );
		}
		wp_safe_redirect( self::url( $jahr ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	private static function flash(): void {
		$flash = mietv_flash_get();
		$code  = $flash['code'] ?? '';
		$n     = (int) ( $flash['n'] ?? 0 );
		$texte = [
			'ja_dauerbelege'      => [ 'success', sprintf( /* translators: %d: Anzahl */ _n( '%d recurring expense due date booked.', '%d recurring expense due dates booked.', $n, 'mietfux' ), $n ) ],
			'ja_hausgeld'         => [ 'success', sprintf( /* translators: %d: Anzahl */ _n( '%d owners’ fee month booked.', '%d owners’ fee months booked.', $n, 'mietfux' ), $n ) ],
			'ja_abrechnung'       => [ 'success', __( 'Service charge statement created and calculated (draft).', 'mietfux' ) ],
			'ja_zinsen'           => [ 'success', __( 'Provisional interest certificate saved – replace it with the bank’s certificate.', 'mietfux' ) ],
			'ja_sicherung'        => [ 'success', __( 'Backup created.', 'mietfux' ) ],
			'ja_sicherung_fehler' => [ 'error', __( 'Backup could not be written.', 'mietfux' ) ],
			'ja_nichts'           => [ 'warning', __( 'Nothing to do – the step is already completed.', 'mietfux' ) ],
		];
		if ( isset( $texte[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $texte[ $code ][0] ), esc_html( $texte[ $code ][1] ) );
			if ( $code === 'ja_abrechnung' && ! empty( $flash['id'] ) ) {
				printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( admin_url( 'admin.php?page=mietv_abrechnung&id=' . (int) $flash['id'] ) ), esc_html__( 'Open statement', 'mietfux' ) );
			}
		}
	}

	/** POST-Formular (admin-post) für eine direkt ausführbare Aktion. */
	private static function aktion_form( int $jahr, array $a, bool $primary ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mv-ja-form">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="aktion" value="%s"><input type="hidden" name="jahr" value="%d">', esc_attr( self::NONCE ), esc_attr( $a['name'] ), (int) $jahr );
		foreach ( [ 'objekt_id', 'darlehen_id' ] as $f ) {
			if ( ! empty( $a[ $f ] ) ) {
				printf( '<input type="hidden" name="%s" value="%d">', esc_attr( $f ), (int) $a[ $f ] );
			}
		}
		printf(
			'<button class="button%s"%s>%s</button></form>',
			$primary ? ' button-primary' : '',
			! empty( $a['confirm'] ) ? ' data-confirm="' . esc_attr( $a['confirm'] ) . '"' : '',
			esc_html( $a['label'] )
		);
	}

	public static function page(): void {
		$jahr                        = self::jahr_aus_request();
		$schritte                    = self::schritte( $jahr );
		[ $fertig, $gesamt, $offen ] = self::fortschritt( $schritte );
		$jetzt                       = (int) current_time( 'Y' );

		echo '<div class="wrap mv-wrap mv-ja"><h1>' . esc_html( sprintf( /* translators: %d: Jahr */ __( 'Year-end %d', 'mietfux' ), $jahr ) ) . '</h1>';
		self::flash();

		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_jahresabschluss"><label>' . esc_html__( 'Year', 'mietfux' ) . ' <select name="jahr" data-autosubmit="1">';
		for ( $j = $jetzt; $j >= $jetzt - 6; $j-- ) {
			printf( '<option value="%d"%s>%d</option>', (int) $j, selected( $jahr, $j, false ), (int) $j );
		}
		echo '</select></label></form>';
		echo '<p class="description">' . esc_html__( 'Work through the steps from top to bottom. Steps with a button can be executed directly; the others open the relevant page. Not applicable steps count as completed.', 'mietfux' ) . '</p>';

		// Fortschritt
		$prozent = $gesamt ? (int) round( $fertig / $gesamt * 100 ) : 0;
		printf(
			'<section class="mv-card mv-ja-progress"><div class="mv-ja-progress-head"><strong>%s</strong><span class="mv-pill mv-pill-%s">%s</span></div><div class="mv-ja-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%d"><span class="mv-ja-bar-fill mv-ja-bar-w%d"></span></div></section>',
			esc_html( sprintf( /* translators: %1$d: Anzahl, %2$d: Anzahl */ __( '%1$d of %2$d steps completed', 'mietfux' ), $fertig, $gesamt ) ),
			$offen ? 'warn' : 'ok',
			esc_html( $offen ? sprintf( /* translators: %d: Anzahl */ _n( '%d open', '%d open', $offen, 'mietfux' ), $offen ) : __( 'All done', 'mietfux' ) ),
			(int) $prozent,
			(int) ( round( $prozent / 5 ) * 5 )
		);

		$status_label = [
			'erledigt' => [ 'ok', __( 'Done', 'mietfux' ) ],
			'offen'    => [ 'warn', __( 'Still open', 'mietfux' ) ],
			'na'       => [ 'neutral', __( 'Not applicable', 'mietfux' ) ],
		];
		echo '<ol class="mv-ja-schritte">';
		foreach ( $schritte as $i => $st ) {
			[ $ptyp, $plabel ] = $status_label[ $st['status'] ] ?? $status_label['na'];
			printf( '<li class="mv-card mv-ja-schritt mv-ja-%s"><span class="mv-ja-nr">%d</span><div class="mv-ja-body"><header class="mv-ja-head"><h2>%s</h2><span class="mv-pill mv-pill-%s">%s</span>', esc_attr( $st['status'] ), (int) $i + 1, esc_html( $st['titel'] ), esc_attr( $ptyp ), esc_html( $plabel ) );
			if ( $st['zahl'] !== '' ) {
				echo '<span class="mv-ja-zahl">' . esc_html( $st['zahl'] ) . '</span>';
			}
			echo '</header><p class="mv-ja-text">' . esc_html( $st['text'] ) . '</p>';
			if ( ! empty( $st['kpi'] ) ) {
				echo '<div class="mv-ja-kpis">';
				foreach ( $st['kpi'] as [ $label, $wert, $cls ] ) {
					printf( '<div class="mv-ja-kpi"><span>%s</span><strong class="%s">%s</strong></div>', esc_html( $label ), esc_attr( $cls ), esc_html( $wert ) );
				}
				echo '</div>';
			}
			if ( ! empty( $st['pills'] ) ) {
				echo '<div class="mv-ja-pills">';
				foreach ( $st['pills'] as $p ) {
					$html = '<span class="mv-pill mv-pill-' . esc_attr( $p['typ'] ) . '">' . esc_html( $p['label'] ) . '</span>';
					if ( ! empty( $p['url'] ) ) {
						$html = '<a href="' . esc_url( $p['url'] ) . '" class="mv-ja-pill-link">' . $html . '</a>';
					}
					echo wp_kses( $html, mietv_kses_html_tags() );
				}
				echo '</div>';
			}
			if ( ! empty( $st['details'] ) ) {
				echo '<ul class="mv-ja-details">';
				foreach ( array_slice( $st['details'], 0, 12 ) as $d ) {
					echo '<li>' . esc_html( $d ) . '</li>';
				}
				if ( count( $st['details'] ) > 12 ) {
					echo '<li>' . esc_html( sprintf( /* translators: %d: Anzahl */ __( '… and %d more', 'mietfux' ), count( $st['details'] ) - 12 ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<div class="mv-ja-actions">';
			if ( ! empty( $st['aktion'] ) ) {
				self::aktion_form( $jahr, $st['aktion'], $st['status'] === 'offen' );
			}
			foreach ( $st['aktionen'] ?? [] as $a ) {
				self::aktion_form( $jahr, $a, true );
			}
			if ( ! empty( $st['link'] ) ) {
				printf( '<a href="%s" class="button">%s</a>', esc_url( $st['link'][1] ), esc_html( $st['link'][0] ) );
			}
			echo '</div></div></li>';
		}
		echo '</ol></div>';
	}
}
