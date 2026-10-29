<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Darlehenskonto: monatlicher Tilgungsplan (Annuität), Raten per Häkchen buchen,
 * Sondertilgungen, Zins-/Tilgungs-/Restschuldverlauf, Jahreswerte für die Steuer.
 */
class Mietv_Darlehen {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	/** Gebuchte Raten eines Darlehens, chronologisch. */
	public static function raten( int $darlehen_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_darlehen_raten WHERE darlehen_id = %d ORDER BY datum, id', $darlehen_id ) );
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
		$bis     = $bis ?: current_time( 'Y-m-01' );
		$satz    = (float) $d->zinssatz / 100;
		$rows    = [];
		$rest    = (float) $d->betrag;
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
					$rest   = round( $rest - (float) $r->tilgung, 2 );
					$rows[] = [
						'monat'      => $m . '-01',
						'datum'      => $r->datum,
						'rate'       => (float) $r->rate,
						'zins'       => (float) $r->zins,
						'tilgung'    => (float) $r->tilgung,
						'restschuld' => $rest,
						'gebucht'    => true,
						'id'         => (int) $r->id,
						'art'        => $r->art,
					];
				}
			} else {
				$zins = round( $rest * $satz / 12, 2 );
				$rate = (float) $d->rate;
				$tilg = min( $rest, round( $rate - $zins, 2 ) );
				if ( $rate <= 0 ) {
					$tilg = 0;
				}
				$rows[] = [
					'monat'      => $m . '-01',
					'datum'      => $m . '-01',
					'rate'       => $rate,
					'zins'       => $zins,
					'tilgung'    => $tilg,
					'restschuld' => round( $rest - $tilg, 2 ),
					'gebucht'    => false,
					'id'         => 0,
					'art'        => 'rate',
				];
			}
			$cursor->modify( '+1 month' );
		}
		return $rows;
	}

	/** Jahreswerte aus gebuchten Raten: ['zinsen','tilgung','restschuld','anzahl'] oder null. */
	public static function jahreswerte( int $darlehen_id, int $jahr ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(zins) AS zinsen, SUM(tilgung) AS tilgung, COUNT(*) AS anzahl FROM ' . $wpdb->prefix . 'mv_darlehen_raten WHERE darlehen_id = %d AND datum BETWEEN %s AND %s', $darlehen_id, $jahr . '-01-01', $jahr . '-12-31' ) );
		if ( ! $r || ! (int) $r->anzahl ) {
			return null;
		}
		$d       = $wpdb->get_row( $wpdb->prepare( 'SELECT betrag FROM ' . $wpdb->prefix . 'mv_darlehen WHERE id = %d', $darlehen_id ) );
		$getilgt = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(tilgung),0) FROM ' . $wpdb->prefix . 'mv_darlehen_raten WHERE darlehen_id = %d AND datum <= %s', $darlehen_id, $jahr . '-12-31' ) );
		return [
			'zinsen'     => round( (float) $r->zinsen, 2 ),
			'tilgung'    => round( (float) $r->tilgung, 2 ),
			'restschuld' => round( (float) $d->betrag - $getilgt, 2 ),
			'anzahl'     => (int) $r->anzahl,
		];
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mietv_darlehen_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_darlehen_nonce'] ?? '' ) ), 'mietv_darlehen' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		global $wpdb;
		$did = ( isset( $_POST['darlehen_id'] ) ? (int) $_POST['darlehen_id'] : 0 );
		$d   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_darlehen WHERE id = %d', $did ) );
		if ( ! $d ) {
			return;
		}
		$n = 0;
		if ( ! empty( $_POST['loeschen'] ) ) {
			$wpdb->delete(
				mietv_table( 'darlehen_raten' ),
				[
					'id'          => ( isset( $_POST['loeschen'] ) ? (int) $_POST['loeschen'] : 0 ),
					'darlehen_id' => $did,
				]
			);
		} elseif ( ! empty( $_POST['sondertilgung'] ) ) {
			$betrag = mietv_to_float( sanitize_text_field( wp_unslash( $_POST['st_betrag'] ?? '' ) ) );
			$datum  = ( sanitize_text_field( wp_unslash( $_POST['st_datum'] ?? '' ) ) ?: current_time( 'Y-m-d' ) );
			if ( $betrag > 0 && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
				$wpdb->insert(
					mietv_table( 'darlehen_raten' ),
					[
						'darlehen_id' => $did,
						'datum'       => $datum,
						'rate'        => $betrag,
						'zins'        => 0,
						'tilgung'     => min( $betrag, self::restschuld( $d ) ),
						'art'         => 'sondertilgung',
					]
				);
				$n = 1;
			}
		} else {
			// Markierte Monate in Planreihenfolge buchen (Restschuld baut aufeinander auf)
			$markiert = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['monat'] ?? [] ) ) );
			sort( $markiert );
			foreach ( $markiert as $monat ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-01$/', $monat ) ) {
					continue;
				}
				$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_darlehen_raten WHERE darlehen_id = %d AND art = 'rate' AND datum BETWEEN %s AND %s", $did, $monat, gmdate( 'Y-m-t', strtotime( $monat ) ) ) );
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
				$wpdb->insert(
					mietv_table( 'darlehen_raten' ),
					[
						'darlehen_id' => $did,
						'datum'       => $monat,
						'rate'        => $rate,
						'zins'        => $zins,
						'tilgung'     => $tilg,
						'art'         => 'rate',
					]
				);
				++$n;
			}
		}
		mietv_flash_set( 'ok', [ 'n' => $n ] );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_darlehenskonto&darlehen_id=' . $did ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		global $wpdb;
		$alle = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_darlehen ORDER BY status, bank' );
		$did  = ( isset( $_GET['darlehen_id'] ) ? (int) $_GET['darlehen_id'] : (int) ( $alle[0]->id ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$d    = null;
		foreach ( $alle as $x ) {
			if ( (int) $x->id === $did ) {
				$d = $x;
			}
		}
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Loan account', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		if ( ( $flash['code'] ?? '' ) === 'ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %d: Wert */ esc_html__( '%d entry/entries created.', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ) );
		}
		if ( ! $alle ) {
			printf( '<p>' . esc_html__( 'No loan created yet –', 'mietfux' ) . ' <a href="%s">' . esc_html__( 'Create loan', 'mietfux' ) . '</a></p></div>', esc_url( admin_url( 'admin.php?page=mietv_darlehen&action=new' ) ) );
			return;
		}
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_darlehenskonto"><label>' . esc_html__( 'Loans', 'mietfux' ) . ' <select name="darlehen_id" onchange="this.form.submit()">';
		foreach ( $alle as $x ) {
			printf( '<option value="%d"%s>%s</option>', (int) $x->id, selected( $did, (int) $x->id, false ), esc_html( $x->bank . ( $x->bezeichnung ? ' – ' . $x->bezeichnung : '' ) . ' (' . mietv_eur( $x->betrag ) . ')' ) );
		}
		echo '</select></label></form>';
		if ( ! $d ) {
			echo '</div>';
			return;
		}
		if ( empty( $d->auszahlung ) || $d->auszahlung === '0000-00-00' || (float) $d->rate <= 0 ) {
			printf( '<div class="notice notice-warning inline"><p>' . esc_html__( 'The repayment schedule requires disbursement date, interest rate and monthly instalment on the loan.', 'mietfux' ) . ' <a href="%s">' . esc_html__( 'Edit loan', 'mietfux' ) . '</a></p></div></div>', esc_url( admin_url( 'admin.php?page=mietv_darlehen&action=edit&id=' . $d->id ) ) );
			return;
		}

		$plan        = self::plan( $d );
		$rest        = self::restschuld( $d );
		$gebucht     = array_filter( $plan, fn( $r ) => $r['gebucht'] );
		$offen       = array_filter( $plan, fn( $r ) => ! $r['gebucht'] );
		$zins_gesamt = array_sum( array_column( $gebucht, 'zins' ) );
		$tilg_gesamt = array_sum( array_column( $gebucht, 'tilgung' ) );
		// Restlaufzeit-Schätzung
		$monate_rest = 0;
		$r           = $rest;
		while ( $r > 0.005 && $monate_rest < 1200 && (float) $d->rate > 0 ) {
			$r -= (float) $d->rate - $r * (float) $d->zinssatz / 100 / 12;
			++$monate_rest;
		}
		printf(
			'<div class="mv-kacheln"><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s %s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%d %s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div></div>',
			esc_html__( 'Loan amount', 'mietfux' ),
			esc_html( mietv_eur( $d->betrag ) ),
			esc_html__( 'disbursed', 'mietfux' ),
			esc_html( mietv_date( $d->auszahlung ) ),
			esc_html__( 'Outstanding balance today', 'mietfux' ),
			esc_html( mietv_eur( $rest ) ),
			esc_html__( 'Interest paid', 'mietfux' ),
			esc_html( mietv_eur( $zins_gesamt ) ),
			count( $gebucht ),
			esc_html__( 'Entries', 'mietfux' ),
			esc_html__( 'Repaid', 'mietfux' ),
			esc_html( mietv_eur( $tilg_gesamt ) ),
			esc_html__( 'Instalment / interest rate', 'mietfux' ),
			esc_html( mietv_eur( $d->rate ) . ' / ' . number_format( (float) $d->zinssatz, 2, ',', '.' ) . ' %' ),
			$monate_rest ? sprintf( /* translators: %d: Wert */ esc_html__( 'Remaining term approx. %d months', 'mietfux' ), (int) $monate_rest ) : ''
		);

		echo '<p class="description">' . esc_html__( 'Tick and book open months: interest = balance × rate / 12, repayment = instalment − interest. The annual totals flow into Anlage V as loan interest unless a bank interest certificate is entered for the year – that takes precedence (exact bank calculation).', 'mietfux' ) . '</p>';

		echo '<form method="post" id="mv-dl-form">';
		wp_nonce_field( 'mietv_darlehen', 'mietv_darlehen_nonce' );
		printf( '<input type="hidden" name="darlehen_id" value="%d">', (int) $d->id );
		if ( $offen ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Book selected instalments', 'mietfux' ) . '</button></p>';
		}
		echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" data-check-all="tbody input[type=checkbox]"></th><th>' . esc_html__( 'Month', 'mietfux' ) . '</th><th>' . esc_html__( 'Type', 'mietfux' ) . '</th><th>' . esc_html__( 'Instalment', 'mietfux' ) . '</th><th>' . esc_html__( 'Interest', 'mietfux' ) . '</th><th>' . esc_html__( 'Repayment', 'mietfux' ) . '</th><th>' . esc_html__( 'Outstanding balance', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_reverse( $plan ) as $r ) {
			$art = $r['art'] === 'sondertilgung' ? __( 'Extra repayment', 'mietfux' ) : ( $r['gebucht'] ? __( 'Instalment', 'mietfux' ) : __( 'Instalment (open)', 'mietfux' ) );
			printf(
				'<tr%s><td class="mv-op-check">%s</td><td>%s</td><td style="text-align:left">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				$r['gebucht'] ? '' : ' style="opacity:.75"',
				$r['gebucht'] ? '✓' : '<input type="checkbox" name="monat[]" value="' . esc_attr( $r['monat'] ) . '">',
				esc_html( $r['art'] === 'sondertilgung' ? mietv_date( $r['datum'] ) : mietv_monat( $r['monat'] ) ),
				esc_html( $art ),
				esc_html( mietv_eur( $r['rate'] ) ),
				esc_html( mietv_eur( $r['zins'] ) ),
				esc_html( mietv_eur( $r['tilgung'] ) ),
				esc_html( mietv_eur( $r['restschuld'] ) ),
				$r['gebucht'] ? '<button name="loeschen" value="' . (int) $r['id'] . '" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete entry? Subsequent balances will be recalculated.', 'mietfux' ) . '">×</button>' : ''
			);
		}
		if ( ! $plan ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No instalments in the period yet.', 'mietfux' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $offen ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Book selected instalments', 'mietfux' ) . '</button></p>';
		}
		echo '</form>';

		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Book extra repayment', 'mietfux' ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_darlehen', 'mietv_darlehen_nonce' );
		printf(
			'<input type="hidden" name="darlehen_id" value="%d"><p><input type="date" name="st_datum" value="%s"> <input type="text" name="st_betrag" class="mv-num" placeholder="%s" inputmode="decimal"> <button name="sondertilgung" value="1" class="button">%s</button></p><p class="description">%s</p></form></div>',
			(int) $d->id,
			esc_attr( current_time( 'Y-m-d' ) ),
			esc_attr__( 'Amount', 'mietfux' ),
			esc_html__( 'Book', 'mietfux' ),
			esc_html__( 'Reduces the outstanding balance; subsequent instalments are calculated on the lower balance.', 'mietfux' )
		);

		// Jahreswerte
		echo '<div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Annual figures', 'mietfux' ) . '</h3><table class="widefat striped mv-op"><thead><tr><th>' . esc_html__( 'Year', 'mietfux' ) . '</th><th>' . esc_html__( 'Interest', 'mietfux' ) . '</th><th>' . esc_html__( 'Repayment', 'mietfux' ) . '</th><th>' . esc_html__( 'Balance 31 Dec', 'mietfux' ) . '</th><th>' . esc_html__( 'Source', 'mietfux' ) . '</th></tr></thead><tbody>';
		$jahre = array_unique( array_map( fn( $r ) => (int) substr( $r['monat'], 0, 4 ), $gebucht ) );
		rsort( $jahre );
		foreach ( $jahre as $j ) {
			$besch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_darlehen_jahre WHERE darlehen_id = %d AND jahr = %d', $d->id, $j ) );
			$jw    = self::jahreswerte( (int) $d->id, $j );
			if ( $besch ) {
				printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:left">%s%s</td></tr>', (int) $j, esc_html( mietv_eur( $besch->zinsen ) ), esc_html( mietv_eur( $besch->tilgung ) ), esc_html( mietv_eur( $besch->restschuld ) ), esc_html__( 'Interest certificate', 'mietfux' ), $jw && abs( $jw['zinsen'] - (float) $besch->zinsen ) > 5 ? ' <span class="mv-warn" title="' . esc_attr( sprintf( /* translators: %s: Wert */ esc_html__( 'Calculated: %s', 'mietfux' ), mietv_eur( $jw['zinsen'] ) ) ) . '">⚠</span>' : '' );
			} elseif ( $jw ) {
				printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:left">%s</td></tr>', (int) $j, esc_html( mietv_eur( $jw['zinsen'] ) ), esc_html( mietv_eur( $jw['tilgung'] ) ), esc_html( mietv_eur( $jw['restschuld'] ) ), esc_html__( 'calculated from instalments', 'mietfux' ) );
			}
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Once the bank\'s interest certificate is available, enter it under “Interest certificates” – it replaces the calculated values in the tax report.', 'mietfux' ) . '</p></div></div></div>';
	}
}
