<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Kautionskonto (§ 551 BGB): Einzahlungen, Zinsgutschriften, Verrechnung, Rückzahlung mit Abrechnung.
 */
class Mietv_Kaution {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mietv_kaution_abrechnung', [ __CLASS__, 'abrechnung' ] );
	}

	public static function arten(): array {
		return [
			'einzahlung'  => esc_html__( 'Payment by tenant', 'mietfux' ),
			'zins'        => esc_html__( 'Interest credit', 'mietfux' ),
			'verrechnung' => esc_html__( 'Offset against claim', 'mietfux' ),
			'auszahlung'  => esc_html__( 'Payout to tenant', 'mietfux' ),
		];
	}

	/** Stand und Summen je Mietverhältnis. */
	public static function konto( int $mv_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_kaution WHERE mv_id = %d ORDER BY datum, id', $mv_id ) );
		$s    = [
			'einzahlung'   => 0.0,
			'zins'         => 0.0,
			'verrechnung'  => 0.0,
			'auszahlung'   => 0.0,
			'stand'        => 0.0,
			'buchungen'    => $rows,
			'letzter_zins' => null,
		];
		foreach ( $rows as $r ) {
			$s[ $r->art ] += (float) $r->betrag;
			if ( $r->art === 'zins' ) {
				$s['letzter_zins'] = $r->datum;
			}
		}
		$s['stand'] = $s['einzahlung'] + $s['zins'] - $s['verrechnung'] - $s['auszahlung'];
		return $s;
	}

	public static function handle(): void {
		if ( empty( $_POST['mietv_kaution_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_kaution_nonce'] ?? '' ) ), 'mietv_kaution' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		global $wpdb;
		$mv_id = ( isset( $_POST['mv_id'] ) ? (int) $_POST['mv_id'] : 0 );
		if ( ! empty( $_POST['loeschen'] ) ) {
			$wpdb->delete(
				mietv_table( 'kaution' ),
				[
					'id'    => ( isset( $_POST['loeschen'] ) ? (int) $_POST['loeschen'] : 0 ),
					'mv_id' => $mv_id,
				]
			);
		} elseif ( ! empty( $_POST['zins_berechnen'] ) ) {
			// Zinsen seit letzter Gutschrift (bzw. erster Einzahlung) bis Stichtag, taggenau auf den jeweiligen Stand
			$k     = self::konto( $mv_id );
			$satz  = mietv_to_float( sanitize_text_field( wp_unslash( $_POST['zinssatz'] ?? '' ) ) );
			$bis   = ( sanitize_text_field( wp_unslash( $_POST['stichtag'] ?? '' ) ) ?: current_time( 'Y-m-d' ) );
			$zins  = 0.0;
			$stand = 0.0;
			$von   = null;
			foreach ( $k['buchungen'] as $b ) {
				if ( $von !== null && $b->datum > $von ) {
					$zins += $stand * $satz / 100 * ( ( strtotime( min( $b->datum, $bis ) ) - strtotime( $von ) ) / 86400 ) / 365;
				}
				$stand += in_array( $b->art, [ 'einzahlung', 'zins' ], true ) ? (float) $b->betrag : - (float) $b->betrag;
				$von    = $b->art === 'zins' ? $b->datum : ( $von ?? $b->datum );
				if ( $b->art === 'zins' ) {
					$zins = 0.0; // nur seit letzter Gutschrift
				}
			}
			if ( $von !== null && $bis > $von ) {
				$zins += $stand * $satz / 100 * ( ( strtotime( $bis ) - strtotime( $von ) ) / 86400 ) / 365;
			}
			$zins = round( $zins, 2 );
			if ( $zins > 0 ) {
				$wpdb->insert(
					mietv_table( 'kaution' ),
					[
						'mv_id'  => $mv_id,
						'datum'  => $bis,
						'art'    => 'zins',
						'betrag' => $zins,
						'notiz'  => 'Zinsen ' . number_format( $satz, 2, ',', '.' ) . ' % p. a. bis ' . mietv_date( $bis ),
					]
				);
			}
		} else {
			$betrag = mietv_to_float( sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '' ) ) );
			$art    = sanitize_key( $_POST['art'] ?? '' );
			if ( $betrag > 0 && isset( self::arten()[ $art ] ) ) {
				$wpdb->insert(
					mietv_table( 'kaution' ),
					[
						'mv_id'  => $mv_id,
						'datum'  => ( sanitize_text_field( wp_unslash( $_POST['datum'] ?? '' ) ) ?: current_time( 'Y-m-d' ) ),
						'art'    => $art,
						'betrag' => $betrag,
						'notiz'  => sanitize_text_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
					]
				);
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_kaution&mv_id=' . $mv_id ) );
		exit;
	}

	public static function page(): void {
		global $wpdb;
		$mvs   = Mietv_Soll::alle_mv();
		$mv_id = ( isset( $_GET['mv_id'] ) ? (int) $_GET['mv_id'] : (int) ( $mvs[0]->id ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$mv    = null;
		foreach ( $mvs as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Deposit account', 'mietfux' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'The deposit must be invested separately from the landlord\'s assets at the usual rate for savings deposits with three months\' notice; the interest belongs to the tenant (§ 551 (3) BGB). Repayment after the end of the tenancy and a reasonable review period (usually 3–6 months), less justified claims.', 'mietfux' ) . '</p>';
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_kaution"><label>' . esc_html__( 'Tenancy', 'mietfux' ) . ' <select name="mv_id" data-autosubmit="1">';
		foreach ( $mvs as $m ) {
			printf( '<option value="%d"%s>%s</option>', (int) $m->id, selected( (int) $mv_id, (int) $m->id, false ), esc_html( Mietv_Soll::mietv_label( $m ) ) );
		}
		echo '</select></label></form>';
		if ( ! $mv ) {
			echo '</div>';
			return;
		}
		$k    = self::konto( $mv_id );
		$soll = (float) $mv->kaution;
		$op   = Mietv_Soll::op_liste( $mv );
		$cls  = $k['stand'] + 0.005 < $soll ? 'mv-neg' : 'mv-pos';
		printf(
			'<div class="mv-kacheln"><div class="mv-kachel"><span>' . esc_html__( 'Agreed (§ 551)', 'mietfux' ) . '</span><strong>%s</strong></div><div class="mv-kachel"><span>' . esc_html__( 'Paid in', 'mietfux' ) . '</span><strong class="%s">%s</strong><small>%s</small></div><div class="mv-kachel"><span>' . esc_html__( 'Interest credited', 'mietfux' ) . '</span><strong>%s</strong><small>%s</small></div><div class="mv-kachel"><span>' . esc_html__( 'Account balance', 'mietfux' ) . '</span><strong>%s</strong></div><div class="mv-kachel"><span>' . esc_html__( 'Rent account balance', 'mietfux' ) . '</span><strong class="%s">%s</strong></div></div>',
			esc_html( mietv_eur( $soll ) ),
			esc_attr( $cls ),
			esc_html( mietv_eur( $k['einzahlung'] ) ),
			$k['einzahlung'] + 0.005 < $soll ? esc_html__( 'still outstanding:', 'mietfux' ) . ' ' . esc_html( mietv_eur( $soll - $k['einzahlung'] ) ) : esc_html__( 'complete', 'mietfux' ),
			esc_html( mietv_eur( $k['zins'] ) ),
			$k['letzter_zins'] ? 'zuletzt ' . esc_html( mietv_date( $k['letzter_zins'] ) ) : '–',
			esc_html( mietv_eur( $k['stand'] ) ),
			$op['saldo'] < -0.005 ? 'mv-neg' : 'mv-pos',
			esc_html( mietv_eur( $op['saldo'] ) )
		);

		echo '<h2>' . esc_html__( 'Entries', 'mietfux' ) . '</h2><table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Date', 'mietfux' ) . '</th><th>' . esc_html__( 'Type', 'mietfux' ) . '</th><th>' . esc_html__( 'Note', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $k['buchungen'] as $b ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:left">%s</td><td class="%s">%s%s</td><td><form method="post" style="display:inline">%s<input type="hidden" name="mv_id" value="%d"><button name="loeschen" value="%d" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete booking?', 'mietfux' ) . '">×</button></form></td></tr>',
				esc_html( mietv_date( $b->datum ) ),
				esc_html( self::arten()[ $b->art ] ?? $b->art ),
				esc_html( $b->notiz ),
				in_array( $b->art, [ 'einzahlung', 'zins' ], true ) ? 'mv-pos' : 'mv-neg',
				in_array( $b->art, [ 'einzahlung', 'zins' ], true ) ? '+' : '–',
				esc_html( mietv_eur( $b->betrag ) ),
				wp_kses( wp_nonce_field( 'mietv_kaution', 'mietv_kaution_nonce', true, false ), mietv_kses_html_tags() ),
				(int) $mv_id,
				(int) $b->id
			);
		}
		if ( ! $k['buchungen'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No entries yet.', 'mietfux' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Record entry', 'mietfux' ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_kaution', 'mietv_kaution_nonce' );
		printf( '<input type="hidden" name="mv_id" value="%d"><p><input type="date" name="datum" value="%s"> <select name="art">', (int) $mv_id, esc_attr( current_time( 'Y-m-d' ) ) );
		foreach ( self::arten() as $a => $l ) {
			printf( '<option value="%s">%s</option>', esc_attr( $a ), esc_html( $l ) );
		}
		printf( '</select></p><p><input type="text" name="betrag" class="mv-num" placeholder="' . esc_attr__( 'Amount', 'mietfux' ) . '" value="%s"> <input type="text" name="notiz" placeholder="' . esc_attr__( 'Note', 'mietfux' ) . '" class="regular-text"></p><p><button class="button button-primary">' . esc_html__( 'Book', 'mietfux' ) . '</button></p></form></div>', $k['einzahlung'] + 0.005 < $soll ? esc_attr( number_format( round( $soll / 3, 2 ), 2, ',', '' ) ) : '' );

		echo '<div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Credit interest', 'mietfux' ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_kaution', 'mietv_kaution_nonce' );
		printf( '<input type="hidden" name="mv_id" value="%d"><p>' . esc_html__( 'Interest rate', 'mietfux' ) . ' <input type="text" name="zinssatz" class="small-text mv-num" value="%s"> ' . esc_html__( '%% p.a. up to reference date', 'mietfux' ) . ' <input type="date" name="stichtag" value="%s"></p><p><button name="zins_berechnen" value="1" class="button">' . esc_html__( 'Calculate daily and book', 'mietfux' ) . '</button></p><p class="description">' . esc_html__( 'Interest rate per the bank\'s deposit account/savings book. Calculated since the last interest credit on the respective balance (act/365).', 'mietfux' ) . '</p></form></div></div>', (int) $mv_id, esc_attr( '0,50' ), esc_attr( current_time( 'Y' ) . '-12-31' ) );

		// Rückzahlungsabrechnung
		$beendet = $mv->ende && $mv->ende !== '0000-00-00' && $mv->ende <= current_time( 'Y-m-d' );
		echo '<h2>' . esc_html__( 'Repayment settlement', 'mietfux' ) . '</h2>';
		$offene_bk   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(CASE WHEN saldo < 0 THEN -saldo ELSE 0 END),0) FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis WHERE mv_id = %d AND freigegeben = 1', $mv_id ) );
		$forderungen = max( 0, -$op['saldo'] );
		$auszahlung  = $k['stand'] - $forderungen;
		$kopf        = sprintf( /* translators: %1$s: Kontostand, %2$s: Rückstand */ __( 'Balance %1$s − open rent arrears %2$s =', 'mietfux' ), mietv_eur( $k['stand'] ), mietv_eur( $forderungen ) );
		$rest        = sprintf( /* translators: %1$s: Auszahlung/Restforderung, %2$s: Betrag aus Betriebskostenabrechnung */ __( '%1$s. Book further deductions (damage, decorative repairs not carried out, amount due from the last service charge statement – released so far %2$s) as “Offset”, then generate the settlement.', 'mietfux' ), $auszahlung < 0 ? __( ' (remaining claim against the tenant)', 'mietfux' ) : __( ' Payout amount', 'mietfux' ), mietv_eur( $offene_bk ) );
		printf(
			'<p>%s <strong class="%s">%s</strong>%s</p>',
			esc_html( $kopf ),
			$auszahlung < 0 ? 'mv-neg' : 'mv-pos',
			esc_html( mietv_eur( $auszahlung ) ),
			esc_html( $rest )
		);

		$u = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_kaution_abrechnung&mv_id=' . $mv_id ), 'mietv_kaution_abr' );
		printf( '<p><a href="%s" target="_blank" class="button%s">' . esc_html__( 'Deposit settlement (PDF)', 'mietfux' ) . '</a> <a href="%s&ablegen=1" target="_blank" class="button">' . esc_html__( '… file', 'mietfux' ) . '</a>%s</p>', esc_url( $u ), $beendet ? ' button-primary' : '', esc_url( $u ), $beendet ? '' : ' <span class="description">' . esc_html__( 'Tenancy still running – settlement only after return of the flat.', 'mietfux' ) . '</span>' );
		echo '</div>';
	}

	public static function abrechnung(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		global $wpdb;
		check_admin_referer( 'mietv_kaution_abr' );
		Mietv_PDF::ablegen_aus_request( 'mietv_kaution_abr' );
		$mv_id = ( isset( $_GET['mv_id'] ) ? (int) $_GET['mv_id'] : 0 );
		$mv    = null;
		foreach ( Mietv_Soll::alle_mv() as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( ! $mv ) {
			wp_die( esc_html__( 'Not found.', 'mietfux' ) );
		}
		$k          = self::konto( $mv_id );
		$op         = Mietv_Soll::op_liste( $mv );
		$forderung  = max( 0, -$op['saldo'] );
		$auszahlung = $k['stand'] - $forderung;

		$b  = '<p>' . esc_html( Mietv_Brief::anrede( $mv ) ) . '</p>';
		$b .= sprintf( '<p>' . /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert */ esc_html__( 'the tenancy of the flat %1$s / %2$s %3$s. I hereby settle the deposit you provided:', 'mietfux' ) . '</p>', esc_html( $mv->objekt ), esc_html( $mv->einheit ), $mv->ende && $mv->ende !== '0000-00-00' ? 'endete am ' . esc_html( mietv_date( $mv->ende ) ) : 'ist beendet' );
		$b .= '<table class="liste"><thead><tr><th>' . esc_html__( 'Date', 'mietfux' ) . '</th><th>' . esc_html__( 'Entry', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th></tr></thead><tbody>';
		foreach ( $k['buchungen'] as $x ) {
			$b .= sprintf( '<tr><td>%s</td><td>%s%s</td><td>%s%s</td></tr>', esc_html( mietv_date( $x->datum ) ), esc_html( self::arten()[ $x->art ] ), $x->notiz ? ' – ' . esc_html( $x->notiz ) : '', in_array( $x->art, [ 'einzahlung', 'zins' ], true ) ? '' : '– ', esc_html( mietv_eur( $x->betrag ) ) );
		}
		$b .= sprintf( '<tr class="summe"><td colspan="2">' . esc_html__( 'Balance including interest', 'mietfux' ) . '</td><td>%s</td></tr>', esc_html( mietv_eur( $k['stand'] ) ) );
		if ( $forderung > 0 ) {
			$b .= sprintf( '<tr><td colspan="2">' . esc_html__( 'less open rent claims per rent account', 'mietfux' ) . '</td><td>– %s</td></tr>', esc_html( mietv_eur( $forderung ) ) );
		}
		$b .= sprintf( '<tr class="summe"><td colspan="2">%s</td><td>%s</td></tr></tbody></table>', $auszahlung >= 0 ? __( 'Payout amount', 'mietfux' ) : __( 'Remaining claim against you', 'mietfux' ), esc_html( mietv_eur( abs( $auszahlung ) ) ) );
		if ( $auszahlung >= 0 ) {
			$b .= '<p>' . esc_html__( 'The amount will be transferred within 14 days to the account you have given me. If I do not yet have your bank details, please let me know.', 'mietfux' ) . '</p>';
		} else {
			$b .= sprintf( '<p>' . /* translators: %s: Wert */ esc_html__( 'Please transfer the remaining amount of %s within 14 days.', 'mietfux' ) . '</p>', esc_html( mietv_eur( abs( $auszahlung ) ) ) ) . Mietv_Brief::bankverbindung();
		}
		$b        .= '<p class="hinweis">' . esc_html__( 'This settlement discharges all claims arising from the deposit. An outstanding service charge statement for the last year of use remains reserved unless already taken into account.', 'mietfux' ) . '</p>';
		$objekt_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT objekt_id FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
		Mietv_Brief::ausgeben( 'Kautionsabrechnung ' . $mv->nachname, Mietv_Brief::empfaenger( $mv ), 'Abrechnung der Mietkaution', $b, null, true, [ $objekt_id, $mv_id, 'sonstiges', 'Kautionsabrechnung', true ] );
	}
}
