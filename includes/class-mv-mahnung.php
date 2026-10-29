<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Mahnwesen: Zahlungserinnerung → 1. Mahnung → letzte Mahnung, als Schreiben und optional per E-Mail.
 */
class Mietv_Mahnung {

	public static function init(): void {
		add_action( 'admin_post_mietv_mahnung', [ __CLASS__, 'erzeugen' ] );
	}

	public static function stufen(): array {
		return [
			1 => __( 'Payment reminder', 'mietfux' ),
			2 => __( '1st dunning letter', 'mietfux' ),
			3 => __( 'Final dunning letter', 'mietfux' ),
		];
	}

	public static function page(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Dunning', 'mietfux' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Default occurs without a reminder if the rent is not received by the agreed payment day (§ 286 (2) no. 1 BGB). A payment reminder is nevertheless customary. Termination without notice is possible with arrears of two months\' rent (§ 543 (2) no. 3 BGB) – have this checked by a lawyer first.', 'mietfux' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Tenancy', 'mietfux' ) . '</th><th>' . esc_html__( 'Balance', 'mietfux' ) . '</th><th>' . esc_html__( 'Monthly rent', 'mietfux' ) . '</th><th>' . esc_html__( 'Arrears in months', 'mietfux' ) . '</th><th>' . esc_html__( 'Final dunning letter', 'mietfux' ) . '</th><th>' . esc_html__( 'Action', 'mietfux' ) . '</th></tr></thead><tbody>';
		$n = 0;
		foreach ( Mietv_Soll::alle_mv( true ) as $mv ) {
			$op = Mietv_Soll::op_liste( $mv );
			if ( $op['saldo'] >= -0.005 ) {
				continue;
			}
			++$n;
			$k        = Mietv_Soll::konditionen( (int) $mv->id, current_time( 'Y-m-01' ) );
			$miete    = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0;
			$letzte   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mahnungen WHERE mv_id = %d ORDER BY datum DESC, id DESC LIMIT 1', $mv->id ) );
			$naechste = $letzte ? min( 3, (int) $letzte->stufe + 1 ) : 1;
			echo '<tr>';
			printf(
				'<td>%s</td><td class="mv-neg">%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
				esc_html( Mietv_Soll::mietv_label( $mv ) ),
				esc_html( mietv_eur( $op['saldo'] ) ),
				esc_html( mietv_eur( $miete ) ),
				$miete > 0 ? esc_html( number_format( -$op['saldo'] / $miete, 1, ',', '.' ) ) . ( -$op['saldo'] >= 2 * $miete ? ' <span class="mv-neg">' . esc_html__( '⚠ ≥ 2 months\' rent', 'mietfux' ) . '</span>' : '' ) : '–',
				$letzte ? esc_html( self::stufen()[ $letzte->stufe ] . ' am ' . mietv_date( $letzte->datum ) . ( $letzte->per_email ? ' (E-Mail)' : '' ) ) : '–'
			);
			foreach ( self::stufen() as $s => $label ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_mahnung&mv=' . $mv->id . '&stufe=' . $s ), 'mietv_mahnung' );
				printf( '<a href="%s" target="_blank" class="button button-small%s">%s</a> ', esc_url( $url ), $s === $naechste ? ' button-primary' : '', esc_html( $label ) );
				printf( '<a href="%s" class="button button-small" title="per E-Mail an den Mieter senden und Schreiben öffnen" data-confirm="%s">✉</a> ', esc_url( $url . '&email=1' ), esc_attr( sprintf( /* translators: %s: Mahnstufe */ __( 'Send %s by e-mail now?', 'mietfux' ), $label ) ) );
			}
			echo '</td></tr>';
		}
		if ( ! $n ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No arrears – all rent accounts balanced.', 'mietfux' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$hist = $wpdb->get_results( 'SELECT h.*, m.nachname, m.vorname FROM ' . $wpdb->prefix . 'mv_mahnungen h JOIN ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv ON mv.id = h.mv_id JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id ORDER BY h.datum DESC LIMIT 50' );
		if ( $hist ) {
			echo '<h2>' . esc_html__( 'History', 'mietfux' ) . '</h2><table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'mietfux' ) . '</th><th>' . esc_html__( 'Tenants', 'mietfux' ) . '</th><th>' . esc_html__( 'Level', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th><th>' . esc_html__( 'Deadline', 'mietfux' ) . '</th><th>' . esc_html__( 'Email', 'mietfux' ) . '</th></tr></thead><tbody>';
			foreach ( $hist as $h ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( mietv_date( $h->datum ) ), esc_html( $h->nachname . ', ' . $h->vorname ), esc_html( self::stufen()[ $h->stufe ] ?? $h->stufe ), esc_html( mietv_eur( $h->betrag ) ), esc_html( mietv_date( $h->frist ) ), $h->per_email ? 'ja' : '–' );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function erzeugen(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		global $wpdb;
		check_admin_referer( 'mietv_mahnung' );
		$mv_id = ( isset( $_GET['mv'] ) ? (int) $_GET['mv'] : 0 );
		$stufe = max( 1, min( 3, ( isset( $_GET['stufe'] ) ? (int) $_GET['stufe'] : 0 ) ) );
		$mv    = null;
		foreach ( Mietv_Soll::alle_mv() as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( ! $mv ) {
			wp_die( esc_html__( 'Tenancy not found.', 'mietfux' ) );
		}
		$op         = Mietv_Soll::op_liste( $mv );
		$rueckstand = -$op['saldo'];
		$tage       = (int) Mietv_Einstellungen::get( 'mahn_tage_' . $stufe, 7 );
		$frist      = gmdate( 'Y-m-d', strtotime( '+' . $tage . ' days' ) );
		$k          = Mietv_Soll::konditionen( $mv_id, current_time( 'Y-m-01' ) );
		$miete      = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0;

		// Offene Monate
		$offen   = array_filter( $op['rows'], fn( $r ) => $r['diff'] < -0.005 );
		$tabelle = '<table class="liste"><thead><tr><th>' . esc_html__( 'Month', 'mietfux' ) . '</th><th>' . esc_html__( 'Due', 'mietfux' ) . '</th><th>' . esc_html__( 'Paid', 'mietfux' ) . '</th><th>' . esc_html__( 'Outstanding', 'mietfux' ) . '</th></tr></thead><tbody>';
		foreach ( $offen as $r ) {
			$tabelle .= sprintf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( mietv_monat( $r['monat'] ) ), esc_html( mietv_eur( $r['soll'] ) ), esc_html( mietv_eur( $r['ist'] ) ), esc_html( mietv_eur( -$r['diff'] ) ) );
		}
		$tabelle .= sprintf( '<tr class="summe"><td colspan="3">' . esc_html__( 'Total arrears', 'mietfux' ) . '</td><td>%s</td></tr></tbody></table>', esc_html( mietv_eur( $rueckstand ) ) );

		$anrede = '<p>' . esc_html( Mietv_Brief::anrede( $mv ) ) . '</p>';
		$objekt = $mv->objekt . ' / ' . $mv->einheit;
		switch ( $stufe ) {
			case 1:
				$betreff = 'Zahlungserinnerung – Mietverhältnis ' . $objekt;
				$text    = $anrede . '<p>bei der Durchsicht Ihres Mietkontos habe ich festgestellt, dass folgende Zahlungen noch nicht eingegangen sind:</p>' . $tabelle
					. sprintf( '<p>' . esc_html__( 'This is surely an oversight. Please transfer the outstanding amount of', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'by', 'mietfux' ) . ' <strong>%s</strong>' . esc_html__( '. If payment has already been made, please disregard this letter.', 'mietfux' ) . '</p>', esc_html( mietv_eur( $rueckstand ) ), esc_html( mietv_date( $frist ) ) );
				break;
			case 2:
				$betreff = 'Mahnung – Mietrückstand ' . $objekt;
				$text    = $anrede . '<p>trotz meiner Zahlungserinnerung ist folgender Mietrückstand weiterhin offen:</p>' . $tabelle
					. sprintf( '<p>' . esc_html__( 'I request that you transfer the amount of', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'by no later than', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( '. You are in default with your payments (§ 286 (2) no. 1 BGB); default interest is owed from the due date (§ 288 BGB).', 'mietfux' ) . '</p>', esc_html( mietv_eur( $rueckstand ) ), esc_html( mietv_date( $frist ) ) );
				break;
			default:
				$betreff = 'Letzte Mahnung vor Einleitung rechtlicher Schritte – ' . $objekt;
				$kuend   = $miete > 0 && $rueckstand >= 2 * $miete
					? '<p>' . esc_html__( 'The arrears now exceed two months\' rent. I would point out that in this case I am entitled to terminate the tenancy without notice (§ 543 (2) sentence 1 no. 3 BGB).', 'mietfux' ) . '</p>'
					: '<p>' . esc_html__( 'If the deadline passes without payment, I will pursue the arrears through the courts (order for payment). The resulting costs will be charged to you.', 'mietfux' ) . '</p>';
				$text    = $anrede . '<p>trotz Zahlungserinnerung und Mahnung ist folgender Mietrückstand weiterhin offen:</p>' . $tabelle
					. sprintf( '<p>' . esc_html__( 'I am setting you a', 'mietfux' ) . ' <strong>' . /* translators: %s: Wert */ esc_html__( 'final deadline of %s', 'mietfux' ) . '</strong>' . esc_html__( ' to pay the amount of', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'in full.', 'mietfux' ) . '</p>', esc_html( mietv_date( $frist ) ), esc_html( mietv_eur( $rueckstand ) ) ) . $kuend
					. '<p>Sollten Sie in Zahlungsschwierigkeiten sein, nehmen Sie bitte umgehend Kontakt mit mir auf, um eine Lösung zu finden.</p>';
		}
		$text .= Mietv_Brief::bankverbindung();

		$per_email = ! empty( $_GET['email'] );
		$wpdb->insert(
			mietv_table( 'mahnungen' ),
			[
				'mv_id'     => $mv_id,
				'stufe'     => $stufe,
				'datum'     => current_time( 'Y-m-d' ),
				'betrag'    => $rueckstand,
				'frist'     => $frist,
				'per_email' => $per_email ? 1 : 0,
			]
		);

		$objekt_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT objekt_id FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
		$titel     = self::stufen()[ $stufe ] . ' ' . current_time( 'Y-m-d' );
		$pdf       = Mietv_Brief::pdf( Mietv_Brief::empfaenger( $mv ), $betreff, $text );
		Mietv_PDF::ablegen( $pdf, $titel . '.pdf', $objekt_id, $mv_id, 'sonstiges', $titel . ' – ' . $mv->nachname, false );

		if ( $per_email ) {
			$mrow  = $wpdb->get_row( $wpdb->prepare( 'SELECT email, p_email FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mv->mieter_id ) );
			$email = $mrow ? array_filter( [ $mrow->email, $mrow->p_email ] ) : [];
			if ( $email ) {
				$tmp = wp_tempnam( $titel . '.pdf' );
				$fs  = Mietv_PDF::filesystem();
				if ( $fs ) {
					$fs->put_contents( $tmp, $pdf, FS_CHMOD_FILE );
				}
				$plain = wp_strip_all_tags( str_replace( [ '</p>', '</tr>' ], [ "\n\n", "\n" ], $text ) );
				wp_mail( $email, $betreff, $plain . "\n\n" . Mietv_Einstellungen::get( 'vermieter_name' ) . "\n" . Mietv_Einstellungen::get( 'vermieter_adresse' ), [ 'Reply-To: ' . Mietv_Einstellungen::get( 'vermieter_email' ) ], [ $tmp ] );
				wp_delete_file( $tmp );
			}
		}

		Mietv_Brief::ausgeben( self::stufen()[ $stufe ], Mietv_Brief::empfaenger( $mv ), $betreff, $text );
	}
}
