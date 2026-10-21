<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Darlehenskonto: monatlicher Tilgungsplan (Annuität), Raten per Häkchen buchen,
 * Sondertilgungen, Zins-/Tilgungs-/Restschuldverlauf, Jahreswerte für die Steuer.
 */
class MV_Darlehen {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	/** Gebuchte Raten eines Darlehens, chronologisch. */
	public static function raten( int $darlehen_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . mv_table( 'darlehen_raten' ) . " WHERE darlehen_id = %d ORDER BY datum, id", $darlehen_id ) );
	}

	/** Restschuld nach allen gebuchten Raten. */
	public static function restschuld( object $d ): float {
		$rest = (float) $d->betrag;
		foreach ( self::raten( (int) $d->id ) as $r ) {
			$rest -= (float) $r->tilgung;
		}
		return round( $rest, 2 );
	}

	/**
	 * Plan: gebuchte Monate + offene Monate bis heute (ab Monat nach Auszahlung).
	 * Jede Zeile: monat, rate, zins, tilgung, restschuld, gebucht(bool), id, art
	 */
	public static function plan( object $d, ?string $bis = null ): array {
		$bis  = $bis ?: current_time( 'Y-m-01' );
		$satz = (float) $d->zinssatz / 100;
		$rows = [];
		$rest = (float) $d->betrag;
		$gebucht = [];
		foreach ( self::raten( (int) $d->id ) as $r ) {
			$gebucht[ substr( $r->datum, 0, 7 ) ][] = $r;
		}
		if ( empty( $d->auszahlung ) || $d->auszahlung === '0000-00-00' ) {
			return [];
		}
		$cursor = new DateTime( substr( $d->auszahlung, 0, 7 ) . '-01' );
		$cursor->modify( '+1 month' );
		$ende = new DateTime( $bis );
		// Zusätzlich: gebuchte Monate, die vor dem Cursor liegen (z. B. Rate im Auszahlungsmonat)
		$alle_monate = array_keys( $gebucht );
		sort( $alle_monate );
		if ( $alle_monate && $alle_monate[0] < $cursor->format( 'Y-m' ) ) {
			$cursor = new DateTime( $alle_monate[0] . '-01' );
		}
		$guard = 0;
		while ( ( $cursor <= $ende || isset( $gebucht[ $cursor->format( 'Y-m' ) ] ) ) && $guard++ < 1200 && $rest > 0.005 ) {
			$m = $cursor->format( 'Y-m' );
			if ( isset( $gebucht[ $m ] ) ) {
				foreach ( $gebucht[ $m ] as $r ) {
					$rest = round( $rest - (float) $r->tilgung, 2 );
					$rows[] = [ 'monat' => $m . '-01', 'datum' => $r->datum, 'rate' => (float) $r->rate, 'zins' => (float) $r->zins, 'tilgung' => (float) $r->tilgung, 'restschuld' => $rest, 'gebucht' => true, 'id' => (int) $r->id, 'art' => $r->art ];
				}
			} else {
				$zins = round( $rest * $satz / 12, 2 );
				$rate = (float) $d->rate;
				$tilg = min( $rest, round( $rate - $zins, 2 ) );
				if ( $rate <= 0 ) {
					$tilg = 0;
				}
				$rows[] = [ 'monat' => $m . '-01', 'datum' => $m . '-01', 'rate' => $rate, 'zins' => $zins, 'tilgung' => $tilg, 'restschuld' => round( $rest - $tilg, 2 ), 'gebucht' => false, 'id' => 0, 'art' => 'rate' ];
			}
			$cursor->modify( '+1 month' );
		}
		return $rows;
	}

	/** Jahreswerte aus gebuchten Raten: ['zinsen','tilgung','restschuld','anzahl'] oder null. */
	public static function jahreswerte( int $darlehen_id, int $jahr ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(zins) AS zinsen, SUM(tilgung) AS tilgung, COUNT(*) AS anzahl FROM " . mv_table( 'darlehen_raten' ) . " WHERE darlehen_id = %d AND datum BETWEEN %s AND %s", $darlehen_id, $jahr . '-01-01', $jahr . '-12-31' ) );
		if ( ! $r || ! (int) $r->anzahl ) {
			return null;
		}
		$d = $wpdb->get_row( $wpdb->prepare( "SELECT betrag FROM " . mv_table( 'darlehen' ) . " WHERE id = %d", $darlehen_id ) );
		$getilgt = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tilgung),0) FROM " . mv_table( 'darlehen_raten' ) . " WHERE darlehen_id = %d AND datum <= %s", $darlehen_id, $jahr . '-12-31' ) );
		return [ 'zinsen' => round( (float) $r->zinsen, 2 ), 'tilgung' => round( (float) $r->tilgung, 2 ), 'restschuld' => round( (float) $d->betrag - $getilgt, 2 ), 'anzahl' => (int) $r->anzahl ];
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mv_darlehen_nonce'] ) || ! current_user_can( MV_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['mv_darlehen_nonce'], 'mv_darlehen' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$did = (int) $_POST['darlehen_id'];
		$d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . mv_table( 'darlehen' ) . " WHERE id = %d", $did ) );
		if ( ! $d ) {
			return;
		}
		$n = 0;
		if ( ! empty( $_POST['loeschen'] ) ) {
			$wpdb->delete( mv_table( 'darlehen_raten' ), [ 'id' => (int) $_POST['loeschen'], 'darlehen_id' => $did ] );
		} elseif ( ! empty( $_POST['sondertilgung'] ) ) {
			$betrag = mv_to_float( $_POST['st_betrag'] ?? '0' );
			$datum  = sanitize_text_field( $_POST['st_datum'] ?: current_time( 'Y-m-d' ) );
			if ( $betrag > 0 && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
				$wpdb->insert( mv_table( 'darlehen_raten' ), [ 'darlehen_id' => $did, 'datum' => $datum, 'rate' => $betrag, 'zins' => 0, 'tilgung' => min( $betrag, self::restschuld( $d ) ), 'art' => 'sondertilgung' ] );
				$n = 1;
			}
		} else {
			// Markierte Monate in Planreihenfolge buchen (Restschuld baut aufeinander auf)
			$markiert = array_map( 'sanitize_text_field', (array) ( $_POST['monat'] ?? [] ) );
			sort( $markiert );
			foreach ( $markiert as $monat ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-01$/', $monat ) ) {
					continue;
				}
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . mv_table( 'darlehen_raten' ) . " WHERE darlehen_id = %d AND art = 'rate' AND datum BETWEEN %s AND %s", $did, $monat, date( 'Y-m-t', strtotime( $monat ) ) ) );
				if ( $exists ) {
					continue;
				}
				$rest = self::restschuld( $d );
				if ( $rest <= 0.005 ) {
					break;
				}
				$zins = round( $rest * (float) $d->zinssatz / 100 / 12, 2 );
				$rate = (float) $d->rate;
				$tilg = max( 0, min( $rest, round( $rate - $zins, 2 ) ) );
				$wpdb->insert( mv_table( 'darlehen_raten' ), [ 'darlehen_id' => $did, 'datum' => $monat, 'rate' => $rate, 'zins' => $zins, 'tilgung' => $tilg, 'art' => 'rate' ] );
				$n++;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mv_darlehenskonto&darlehen_id=' . $did . '&msg=ok&n=' . $n ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		global $wpdb;
		$alle = $wpdb->get_results( "SELECT * FROM " . mv_table( 'darlehen' ) . " ORDER BY status, bank" );
		$did = (int) ( $_GET['darlehen_id'] ?? ( $alle[0]->id ?? 0 ) );
		$d = null;
		foreach ( $alle as $x ) {
			if ( (int) $x->id === $did ) {
				$d = $x;
			}
		}
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Darlehenskonto', 'mietverwaltung' ) . '</h1>';
		if ( ( $_GET['msg'] ?? '' ) === 'ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '%d Buchung(en) angelegt.', 'mietverwaltung' ) . '</p></div>', (int) ( $_GET['n'] ?? 0 ) );
		}
		if ( ! $alle ) {
			printf( '<p>' . esc_html__( 'Noch kein Darlehen angelegt –', 'mietverwaltung' ) . ' <a href="%s">' . esc_html__( 'Darlehen anlegen', 'mietverwaltung' ) . '</a></p></div>', esc_url( admin_url( 'admin.php?page=mv_darlehen&action=new' ) ) );
			return;
		}
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mv_darlehenskonto"><label>' . esc_html__( 'Darlehen', 'mietverwaltung' ) . ' <select name="darlehen_id" onchange="this.form.submit()">';
		foreach ( $alle as $x ) {
			printf( '<option value="%d"%s>%s</option>', $x->id, selected( $did, $x->id, false ), esc_html( $x->bank . ( $x->bezeichnung ? ' – ' . $x->bezeichnung : '' ) . ' (' . mv_eur( $x->betrag ) . ')' ) );
		}
		echo '</select></label></form>';
		if ( ! $d ) {
			echo '</div>';
			return;
		}
		if ( empty( $d->auszahlung ) || $d->auszahlung === '0000-00-00' || (float) $d->rate <= 0 ) {
			printf( '<div class="notice notice-warning inline"><p>' . esc_html__( 'Für den Tilgungsplan müssen Auszahlungsdatum, Sollzins und Monatsrate beim Darlehen hinterlegt sein.', 'mietverwaltung' ) . ' <a href="%s">' . esc_html__( 'Darlehen bearbeiten', 'mietverwaltung' ) . '</a></p></div></div>', esc_url( admin_url( 'admin.php?page=mv_darlehen&action=edit&id=' . $d->id ) ) );
			return;
		}

		$plan = self::plan( $d );
		$rest = self::restschuld( $d );
		$gebucht = array_filter( $plan, fn( $r ) => $r['gebucht'] );
		$offen   = array_filter( $plan, fn( $r ) => ! $r['gebucht'] );
		$zins_gesamt = array_sum( array_column( $gebucht, 'zins' ) );
		$tilg_gesamt = array_sum( array_column( $gebucht, 'tilgung' ) );
		// Restlaufzeit-Schätzung
		$monate_rest = 0;
		$r = $rest;
		while ( $r > 0.005 && $monate_rest < 1200 && (float) $d->rate > 0 ) {
			$r -= (float) $d->rate - $r * (float) $d->zinssatz / 100 / 12;
			$monate_rest++;
		}
		printf( '<div class="mv-kacheln"><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s %s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%d %s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div></div>',
			esc_html__( 'Darlehensbetrag', 'mietverwaltung' ), esc_html( mv_eur( $d->betrag ) ), esc_html__( 'ausgezahlt', 'mietverwaltung' ), esc_html( mv_date( $d->auszahlung ) ),
			esc_html__( 'Restschuld heute', 'mietverwaltung' ), esc_html( mv_eur( $rest ) ),
			esc_html__( 'Gezahlte Zinsen', 'mietverwaltung' ), esc_html( mv_eur( $zins_gesamt ) ), count( $gebucht ), esc_html__( 'Buchungen', 'mietverwaltung' ),
			esc_html__( 'Getilgt', 'mietverwaltung' ), esc_html( mv_eur( $tilg_gesamt ) ),
			esc_html__( 'Rate / Sollzins', 'mietverwaltung' ), esc_html( mv_eur( $d->rate ) . ' / ' . number_format( (float) $d->zinssatz, 2, ',', '.' ) . ' %' ), $monate_rest ? sprintf( esc_html__( 'Restlaufzeit ca. %d Monate', 'mietverwaltung' ), $monate_rest ) : '' );

		echo '<p class="description">' . esc_html__( 'Offene Monate abhaken und buchen: Zins = Restschuld × Sollzins / 12, Tilgung = Rate − Zins. Die Jahressummen fließen als Schuldzinsen in die Anlage V, solange keine Zinsbescheinigung der Bank für das Jahr erfasst ist – diese hat Vorrang (taggenaue Bankrechnung).', 'mietverwaltung' ) . '</p>';

		echo '<form method="post" id="mv-dl-form">';
		wp_nonce_field( 'mv_darlehen', 'mv_darlehen_nonce' );
		printf( '<input type="hidden" name="darlehen_id" value="%d">', $d->id );
		if ( $offen ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Markierte Raten buchen', 'mietverwaltung' ) . '</button></p>';
		}
		echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" onclick="document.querySelectorAll(\'#mv-dl-form tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)"></th><th>' . esc_html__( 'Monat', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Art', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Rate', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Zins', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Tilgung', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Restschuld', 'mietverwaltung' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_reverse( $plan ) as $r ) {
			$art = $r['art'] === 'sondertilgung' ? __( 'Sondertilgung', 'mietverwaltung' ) : ( $r['gebucht'] ? __( 'Rate', 'mietverwaltung' ) : __( 'Rate (offen)', 'mietverwaltung' ) );
			printf( '<tr%s><td class="mv-op-check">%s</td><td>%s</td><td style="text-align:left">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				$r['gebucht'] ? '' : ' style="opacity:.75"',
				$r['gebucht'] ? '✓' : '<input type="checkbox" name="monat[]" value="' . esc_attr( $r['monat'] ) . '">',
				esc_html( $r['art'] === 'sondertilgung' ? mv_date( $r['datum'] ) : mv_monat( $r['monat'] ) ), esc_html( $art ),
				esc_html( mv_eur( $r['rate'] ) ), esc_html( mv_eur( $r['zins'] ) ), esc_html( mv_eur( $r['tilgung'] ) ), esc_html( mv_eur( $r['restschuld'] ) ),
				$r['gebucht'] ? '<button name="loeschen" value="' . (int) $r['id'] . '" class="button-link mv-delete" onclick="return confirm(\'' . esc_js( __( 'Buchung löschen? Nachfolgende Restschulden werden neu berechnet.', 'mietverwaltung' ) ) . '\')">×</button>' : '' );
		}
		if ( ! $plan ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Noch keine Raten im Zeitraum.', 'mietverwaltung' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $offen ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Markierte Raten buchen', 'mietverwaltung' ) . '</button></p>';
		}
		echo '</form>';

		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Sondertilgung buchen', 'mietverwaltung' ) . '</h3><form method="post">';
		wp_nonce_field( 'mv_darlehen', 'mv_darlehen_nonce' );
		printf( '<input type="hidden" name="darlehen_id" value="%d"><p><input type="date" name="st_datum" value="%s"> <input type="text" name="st_betrag" class="mv-num" placeholder="%s" inputmode="decimal"> <button name="sondertilgung" value="1" class="button">%s</button></p><p class="description">%s</p></form></div>',
			$d->id, esc_attr( current_time( 'Y-m-d' ) ), esc_attr__( 'Betrag', 'mietverwaltung' ), esc_html__( 'Buchen', 'mietverwaltung' ), esc_html__( 'Reduziert die Restschuld; die Folgeraten werden mit der niedrigeren Restschuld berechnet.', 'mietverwaltung' ) );

		// Jahreswerte
		echo '<div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Jahreswerte', 'mietverwaltung' ) . '</h3><table class="widefat striped mv-op"><thead><tr><th>' . esc_html__( 'Jahr', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Zinsen', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Tilgung', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Restschuld 31.12.', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Quelle', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
		$jahre = array_unique( array_map( fn( $r ) => (int) substr( $r['monat'], 0, 4 ), $gebucht ) );
		rsort( $jahre );
		foreach ( $jahre as $j ) {
			$besch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . mv_table( 'darlehen_jahre' ) . " WHERE darlehen_id = %d AND jahr = %d", $d->id, $j ) );
			$jw = self::jahreswerte( (int) $d->id, $j );
			if ( $besch ) {
				printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:left">%s%s</td></tr>', $j, esc_html( mv_eur( $besch->zinsen ) ), esc_html( mv_eur( $besch->tilgung ) ), esc_html( mv_eur( $besch->restschuld ) ), esc_html__( 'Zinsbescheinigung', 'mietverwaltung' ), $jw && abs( $jw['zinsen'] - (float) $besch->zinsen ) > 5 ? ' <span class="mv-warn" title="' . esc_attr( sprintf( __( 'Berechnet: %s', 'mietverwaltung' ), mv_eur( $jw['zinsen'] ) ) ) . '">⚠</span>' : '' );
			} elseif ( $jw ) {
				printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:left">%s</td></tr>', $j, esc_html( mv_eur( $jw['zinsen'] ) ), esc_html( mv_eur( $jw['tilgung'] ) ), esc_html( mv_eur( $jw['restschuld'] ) ), esc_html__( 'berechnet aus Raten', 'mietverwaltung' ) );
			}
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Liegt die Zinsbescheinigung der Bank vor, unter „Zinsbescheinigungen“ erfassen – sie ersetzt die berechneten Werte in der Steuer-Auswertung.', 'mietverwaltung' ) . '</p></div></div></div>';
	}
}
