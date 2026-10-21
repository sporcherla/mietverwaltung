<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mahnwesen: Zahlungserinnerung → 1. Mahnung → letzte Mahnung, als Schreiben und optional per E-Mail.
 */
class Mietv_Mahnung {

	public static function init(): void {
		add_action( 'admin_post_mv_mahnung', [ __CLASS__, 'erzeugen' ] );
	}

	public static function stufen(): array {
		return [
			1 => __( 'Zahlungserinnerung', 'mietverwaltung' ),
			2 => __( '1. Mahnung', 'mietverwaltung' ),
			3 => __( 'Letzte Mahnung', 'mietverwaltung' ),
		];
	}

	public static function page(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Mahnwesen', 'mietverwaltung' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Verzug tritt ohne Mahnung ein, wenn die Miete zum vereinbarten Zahltag nicht eingeht (§ 286 Abs. 2 Nr. 1 BGB). Eine Zahlungserinnerung ist dennoch üblich. Fristlose Kündigung ist bei Rückstand von zwei Monatsmieten möglich (§ 543 Abs. 2 Nr. 3 BGB) – vorher anwaltlich prüfen lassen.', 'mietverwaltung' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Mietverhältnis', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Saldo', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Monatsmiete', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Rückstand in Monaten', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Letzte Mahnung', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Aktion', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
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
				$miete > 0 ? esc_html( number_format( -$op['saldo'] / $miete, 1, ',', '.' ) ) . ( -$op['saldo'] >= 2 * $miete ? ' <span class="mv-neg">' . esc_html__( '⚠ ≥ 2 Monatsmieten', 'mietverwaltung' ) . '</span>' : '' ) : '–',
				$letzte ? esc_html( self::stufen()[ $letzte->stufe ] . ' am ' . mietv_date( $letzte->datum ) . ( $letzte->per_email ? ' (E-Mail)' : '' ) ) : '–'
			);
			foreach ( self::stufen() as $s => $label ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_mahnung&mv=' . $mv->id . '&stufe=' . $s ), 'mietv_mahnung_' . $mv->id );
				printf( '<a href="%s" target="_blank" class="button button-small%s">%s</a> ', esc_url( $url ), $s === $naechste ? ' button-primary' : '', esc_html( $label ) );
				printf( '<a href="%s" class="button button-small" title="per E-Mail an den Mieter senden und Schreiben öffnen" onclick="return confirm(\'%s jetzt per E-Mail senden?\')">✉</a> ', esc_url( $url . '&email=1' ), esc_attr( $label ) );
			}
			echo '</td></tr>';
		}
		if ( ! $n ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Keine Rückstände – alle Mietkonten ausgeglichen.', 'mietverwaltung' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$hist = $wpdb->get_results( 'SELECT h.*, m.nachname, m.vorname FROM ' . $wpdb->prefix . 'mv_mahnungen h JOIN ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv ON mv.id = h.mv_id JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id ORDER BY h.datum DESC LIMIT 50' );
		if ( $hist ) {
			echo '<h2>' . esc_html__( 'Verlauf', 'mietverwaltung' ) . '</h2><table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Datum', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Mieter', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Stufe', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Betrag', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Frist', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'E-Mail', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
			foreach ( $hist as $h ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( mietv_date( $h->datum ) ), esc_html( $h->nachname . ', ' . $h->vorname ), esc_html( self::stufen()[ $h->stufe ] ?? $h->stufe ), esc_html( mietv_eur( $h->betrag ) ), esc_html( mietv_date( $h->frist ) ), $h->per_email ? 'ja' : '–' );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function erzeugen(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$mv_id = ( isset( $_GET['mv'] ) ? (int) $_GET['mv'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		check_admin_referer( 'mietv_mahnung_' . $mv_id );
		$stufe = max( 1, min( 3, ( isset( $_GET['stufe'] ) ? (int) $_GET['stufe'] : 0 ) ) );
		$mv    = null;
		foreach ( Mietv_Soll::alle_mv() as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( ! $mv ) {
			wp_die( esc_html__( 'Mietverhältnis nicht gefunden.', 'mietverwaltung' ) );
		}
		$op         = Mietv_Soll::op_liste( $mv );
		$rueckstand = -$op['saldo'];
		$tage       = (int) Mietv_Einstellungen::get( 'mahn_tage_' . $stufe, 7 );
		$frist      = gmdate( 'Y-m-d', strtotime( '+' . $tage . ' days' ) );
		$k          = Mietv_Soll::konditionen( $mv_id, current_time( 'Y-m-01' ) );
		$miete      = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0;

		// Offene Monate
		$offen   = array_filter( $op['rows'], fn( $r ) => $r['diff'] < -0.005 );
		$tabelle = '<table class="liste"><thead><tr><th>' . esc_html__( 'Monat', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Soll', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Gezahlt', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Offen', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
		foreach ( $offen as $r ) {
			$tabelle .= sprintf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( mietv_monat( $r['monat'] ) ), esc_html( mietv_eur( $r['soll'] ) ), esc_html( mietv_eur( $r['ist'] ) ), esc_html( mietv_eur( -$r['diff'] ) ) );
		}
		$tabelle .= sprintf( '<tr class="summe"><td colspan="3">' . esc_html__( 'Gesamtrückstand', 'mietverwaltung' ) . '</td><td>%s</td></tr></tbody></table>', esc_html( mietv_eur( $rueckstand ) ) );

		$anrede = '<p>' . esc_html( Mietv_Brief::anrede( $mv ) ) . '</p>';
		$objekt = $mv->objekt . ' / ' . $mv->einheit;
		switch ( $stufe ) {
			case 1:
				$betreff = 'Zahlungserinnerung – Mietverhältnis ' . $objekt;
				$text    = $anrede . '<p>bei der Durchsicht Ihres Mietkontos habe ich festgestellt, dass folgende Zahlungen noch nicht eingegangen sind:</p>' . $tabelle
					. sprintf( '<p>' . esc_html__( 'Sicher handelt es sich um ein Versehen. Bitte überweisen Sie den offenen Betrag von', 'mietverwaltung' ) . ' <strong>%s</strong> ' . esc_html__( 'bis zum', 'mietverwaltung' ) . ' <strong>%s</strong>' . esc_html__( '. Sollte die Zahlung bereits erfolgt sein, betrachten Sie dieses Schreiben als gegenstandslos.', 'mietverwaltung' ) . '</p>', esc_html( mietv_eur( $rueckstand ) ), esc_html( mietv_date( $frist ) ) );
				break;
			case 2:
				$betreff = 'Mahnung – Mietrückstand ' . $objekt;
				$text    = $anrede . '<p>trotz meiner Zahlungserinnerung ist folgender Mietrückstand weiterhin offen:</p>' . $tabelle
					. sprintf( '<p>' . esc_html__( 'Ich fordere Sie auf, den Betrag von', 'mietverwaltung' ) . ' <strong>%s</strong> ' . esc_html__( 'bis spätestens', 'mietverwaltung' ) . ' <strong>%s</strong> ' . esc_html__( 'zu überweisen. Sie befinden sich mit den Zahlungen in Verzug (§ 286 Abs. 2 Nr. 1 BGB); ab Fälligkeit sind Verzugszinsen geschuldet (§ 288 BGB).', 'mietverwaltung' ) . '</p>', esc_html( mietv_eur( $rueckstand ) ), esc_html( mietv_date( $frist ) ) );
				break;
			default:
				$betreff = 'Letzte Mahnung vor Einleitung rechtlicher Schritte – ' . $objekt;
				$kuend   = $miete > 0 && $rueckstand >= 2 * $miete
					? '<p>' . esc_html__( 'Der Rückstand beträgt inzwischen mehr als zwei Monatsmieten. Ich weise Sie darauf hin, dass ich in diesem Fall berechtigt bin, das Mietverhältnis fristlos zu kündigen (§ 543 Abs. 2 Satz 1 Nr. 3 BGB).', 'mietverwaltung' ) . '</p>'
					: '<p>' . esc_html__( 'Nach fruchtlosem Ablauf der Frist werde ich den Rückstand gerichtlich geltend machen (Mahnbescheid). Die dadurch entstehenden Kosten gehen zu Ihren Lasten.', 'mietverwaltung' ) . '</p>';
				$text    = $anrede . '<p>trotz Zahlungserinnerung und Mahnung ist folgender Mietrückstand weiterhin offen:</p>' . $tabelle
					. sprintf( '<p>' . esc_html__( 'Ich setze Ihnen eine', 'mietverwaltung' ) . ' <strong>' . esc_html__( 'letzte Frist bis zum %s', 'mietverwaltung' ) . '</strong>' . esc_html__( ', den Betrag von', 'mietverwaltung' ) . ' <strong>%s</strong> ' . esc_html__( 'vollständig zu zahlen.', 'mietverwaltung' ) . '</p>', esc_html( mietv_date( $frist ) ), esc_html( mietv_eur( $rueckstand ) ) ) . $kuend
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
