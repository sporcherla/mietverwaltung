<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kautionskonto (§ 551 BGB): Einzahlungen, Zinsgutschriften, Verrechnung, Rückzahlung mit Abrechnung.
 */
class MV_Kaution {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mv_kaution_abrechnung', [ __CLASS__, 'abrechnung' ] );
	}

	public static function arten(): array {
		return [
			'einzahlung'  => esc_html__( 'Einzahlung Mieter', 'mietverwaltung' ),
			'zins'        => esc_html__( 'Zinsgutschrift', 'mietverwaltung' ),
			'verrechnung' => esc_html__( 'Verrechnung mit Forderung', 'mietverwaltung' ),
			'auszahlung'  => esc_html__( 'Auszahlung an Mieter', 'mietverwaltung' ),
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
		if ( empty( $_POST['mv_kaution_nonce'] ) || ! current_user_can( MV_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['mv_kaution_nonce'], 'mv_kaution' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$mv_id = (int) $_POST['mv_id'];
		if ( ! empty( $_POST['loeschen'] ) ) {
			$wpdb->delete(
				mv_table( 'kaution' ),
				[
					'id'    => (int) $_POST['loeschen'],
					'mv_id' => $mv_id,
				]
			);
		} elseif ( ! empty( $_POST['zins_berechnen'] ) ) {
			// Zinsen seit letzter Gutschrift (bzw. erster Einzahlung) bis Stichtag, taggenau auf den jeweiligen Stand
			$k     = self::konto( $mv_id );
			$satz  = mv_to_float( $_POST['zinssatz'] ?? '0' );
			$bis   = sanitize_text_field( $_POST['stichtag'] ?: current_time( 'Y-m-d' ) );
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
					mv_table( 'kaution' ),
					[
						'mv_id'  => $mv_id,
						'datum'  => $bis,
						'art'    => 'zins',
						'betrag' => $zins,
						'notiz'  => 'Zinsen ' . number_format( $satz, 2, ',', '.' ) . ' % p. a. bis ' . mv_date( $bis ),
					]
				);
			}
		} else {
			$betrag = mv_to_float( $_POST['betrag'] ?? '0' );
			$art    = sanitize_key( $_POST['art'] );
			if ( $betrag > 0 && isset( self::arten()[ $art ] ) ) {
				$wpdb->insert(
					mv_table( 'kaution' ),
					[
						'mv_id'  => $mv_id,
						'datum'  => sanitize_text_field( $_POST['datum'] ?: current_time( 'Y-m-d' ) ),
						'art'    => $art,
						'betrag' => $betrag,
						'notiz'  => sanitize_text_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
					]
				);
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mv_kaution&mv_id=' . $mv_id ) );
		exit;
	}

	public static function page(): void {
		global $wpdb;
		$mvs   = MV_Soll::alle_mv();
		$mv_id = (int) ( $_GET['mv_id'] ?? ( $mvs[0]->id ?? 0 ) );
		$mv    = null;
		foreach ( $mvs as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Kautionskonto', 'mietverwaltung' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Die Kaution ist getrennt vom Vermögen des Vermieters zum üblichen Zinssatz für Spareinlagen mit dreimonatiger Kündigungsfrist anzulegen; die Zinsen stehen dem Mieter zu (§ 551 Abs. 3 BGB). Rückzahlung nach Mietende und angemessener Prüffrist (i. d. R. 3–6 Monate), abzüglich berechtigter Forderungen.', 'mietverwaltung' ) . '</p>';
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mv_kaution"><label>' . esc_html__( 'Mietverhältnis', 'mietverwaltung' ) . ' <select name="mv_id" onchange="this.form.submit()">';
		foreach ( $mvs as $m ) {
			printf( '<option value="%d"%s>%s</option>', (int) $m->id, selected( (int) $mv_id, (int) $m->id, false ), esc_html( MV_Soll::mv_label( $m ) ) );
		}
		echo '</select></label></form>';
		if ( ! $mv ) {
			echo '</div>';
			return;
		}
		$k    = self::konto( $mv_id );
		$soll = (float) $mv->kaution;
		$op   = MV_Soll::op_liste( $mv );
		$cls  = $k['stand'] + 0.005 < $soll ? 'mv-neg' : 'mv-pos';
		printf(
			'<div class="mv-kacheln"><div class="mv-kachel"><span>' . esc_html__( 'Vereinbart (§ 551)', 'mietverwaltung' ) . '</span><strong>%s</strong></div><div class="mv-kachel"><span>' . esc_html__( 'Eingezahlt', 'mietverwaltung' ) . '</span><strong class="%s">%s</strong><small>%s</small></div><div class="mv-kachel"><span>' . esc_html__( 'Zinsen gutgeschrieben', 'mietverwaltung' ) . '</span><strong>%s</strong><small>%s</small></div><div class="mv-kachel"><span>' . esc_html__( 'Kontostand', 'mietverwaltung' ) . '</span><strong>%s</strong></div><div class="mv-kachel"><span>' . esc_html__( 'Mietkonto-Saldo', 'mietverwaltung' ) . '</span><strong class="%s">%s</strong></div></div>',
			esc_html( mv_eur( $soll ) ),
			$cls, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
			esc_html( mv_eur( $k['einzahlung'] ) ),
			$k['einzahlung'] + 0.005 < $soll ? 'noch offen: ' . esc_html( mv_eur( $soll - $k['einzahlung'] ) ) : 'vollständig',
			esc_html( mv_eur( $k['zins'] ) ),
			$k['letzter_zins'] ? 'zuletzt ' . esc_html( mv_date( $k['letzter_zins'] ) ) : '–',
			esc_html( mv_eur( $k['stand'] ) ),
			$op['saldo'] < -0.005 ? 'mv-neg' : 'mv-pos',
			esc_html( mv_eur( $op['saldo'] ) )
		);

		echo '<h2>' . esc_html__( 'Buchungen', 'mietverwaltung' ) . '</h2><table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Datum', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Art', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Notiz', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Betrag', 'mietverwaltung' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $k['buchungen'] as $b ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:left">%s</td><td class="%s">%s%s</td><td><form method="post" style="display:inline">%s<input type="hidden" name="mv_id" value="%d"><button name="loeschen" value="%d" class="button-link mv-delete" onclick="return confirm(\'Buchung löschen?\')">×</button></form></td></tr>',
				esc_html( mv_date( $b->datum ) ),
				esc_html( self::arten()[ $b->art ] ?? $b->art ),
				esc_html( $b->notiz ),
				in_array( $b->art, [ 'einzahlung', 'zins' ], true ) ? 'mv-pos' : 'mv-neg',
				in_array( $b->art, [ 'einzahlung', 'zins' ], true ) ? '+' : '–',
				esc_html( mv_eur( $b->betrag ) ),
				wp_nonce_field( 'mv_kaution', 'mv_kaution_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
				(int) $mv_id,
				(int) $b->id
			);
		}
		if ( ! $k['buchungen'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Noch keine Buchung.', 'mietverwaltung' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Buchung erfassen', 'mietverwaltung' ) . '</h3><form method="post">';
		wp_nonce_field( 'mv_kaution', 'mv_kaution_nonce' );
		printf( '<input type="hidden" name="mv_id" value="%d"><p><input type="date" name="datum" value="%s"> <select name="art">', (int) $mv_id, esc_attr( current_time( 'Y-m-d' ) ) );
		foreach ( self::arten() as $a => $l ) {
			printf( '<option value="%s">%s</option>', $a, esc_html( $l ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		}
		printf( '</select></p><p><input type="text" name="betrag" class="mv-num" placeholder="Betrag" value="%s"> <input type="text" name="notiz" placeholder="Notiz" class="regular-text"></p><p><button class="button button-primary">' . esc_html__( 'Buchen', 'mietverwaltung' ) . '</button></p></form></div>', $k['einzahlung'] + 0.005 < $soll ? esc_attr( number_format( round( $soll / 3, 2 ), 2, ',', '' ) ) : '' );

		echo '<div class="mv-kachel" style="min-width:380px"><h3>' . esc_html__( 'Zinsen gutschreiben', 'mietverwaltung' ) . '</h3><form method="post">';
		wp_nonce_field( 'mv_kaution', 'mv_kaution_nonce' );
		printf( '<input type="hidden" name="mv_id" value="%d"><p>' . esc_html__( 'Zinssatz', 'mietverwaltung' ) . ' <input type="text" name="zinssatz" class="small-text mv-num" value="%s"> ' . esc_html__( '%% p. a. bis Stichtag', 'mietverwaltung' ) . ' <input type="date" name="stichtag" value="%s"></p><p><button name="zins_berechnen" value="1" class="button">' . esc_html__( 'Taggenau berechnen und buchen', 'mietverwaltung' ) . '</button></p><p class="description">' . esc_html__( 'Zinssatz laut Kautionskonto/Sparbuch der Bank. Berechnung seit der letzten Zinsgutschrift auf den jeweiligen Kontostand (act/365).', 'mietverwaltung' ) . '</p></form></div></div>', (int) $mv_id, esc_attr( '0,50' ), esc_attr( current_time( 'Y' ) . '-12-31' ) );

		// Rückzahlungsabrechnung
		$beendet = $mv->ende && $mv->ende !== '0000-00-00' && $mv->ende <= current_time( 'Y-m-d' );
		echo '<h2>' . esc_html__( 'Rückzahlungsabrechnung', 'mietverwaltung' ) . '</h2>';
		$offene_bk   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(CASE WHEN saldo < 0 THEN -saldo ELSE 0 END),0) FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis WHERE mv_id = %d AND freigegeben = 1', $mv_id ) );
		$forderungen = max( 0, -$op['saldo'] );
		$auszahlung  = $k['stand'] - $forderungen;
		printf(
			'<p>' . esc_html__( 'Kontostand %1$s − offener Mietrückstand %2$s =', 'mietverwaltung' ) . ' <strong class="%s">%s</strong>' . esc_html__( '%1$s. Weitere Abzüge (Schäden, nicht ausgeführte Schönheitsreparaturen, Nachzahlung aus der letzten Betriebskostenabrechnung – bisher freigegeben %2$s) als „Verrechnung“ buchen, dann Abrechnung erzeugen.', 'mietverwaltung' ) . '</p>',
			esc_html( mv_eur( $k['stand'] ) ),
			esc_html( mv_eur( $forderungen ) ),
			$auszahlung < 0 ? 'mv-neg' : 'mv-pos',
			esc_html( mv_eur( $auszahlung ) ),
			$auszahlung < 0 ? esc_html__( ' (Restforderung gegen den Mieter)', 'mietverwaltung' ) : esc_html__( ' Auszahlungsbetrag', 'mietverwaltung' ),
			esc_html( mv_eur( $offene_bk ) )
		);
		$u = wp_nonce_url( admin_url( 'admin-post.php?action=mv_kaution_abrechnung&mv_id=' . $mv_id ), 'mv_kaution_abr_' . $mv_id );
		printf( '<p><a href="%s" target="_blank" class="button%s">' . esc_html__( 'Kautionsabrechnung (PDF)', 'mietverwaltung' ) . '</a> <a href="%s&ablegen=1" target="_blank" class="button">' . esc_html__( '… ablegen', 'mietverwaltung' ) . '</a>%s</p>', esc_url( $u ), $beendet ? ' button-primary' : '', esc_url( $u ), $beendet ? '' : ' <span class="description">' . esc_html__( 'Mietverhältnis läuft noch – Abrechnung erst nach Rückgabe.', 'mietverwaltung' ) . '</span>' );
		echo '</div>';
	}

	public static function abrechnung(): void {
		if ( ! current_user_can( MV_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$mv_id = (int) $_GET['mv_id'];
		check_admin_referer( 'mv_kaution_abr_' . $mv_id );
		$mv = null;
		foreach ( MV_Soll::alle_mv() as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( ! $mv ) {
			wp_die( esc_html__( 'Nicht gefunden.', 'mietverwaltung' ) );
		}
		$k          = self::konto( $mv_id );
		$op         = MV_Soll::op_liste( $mv );
		$forderung  = max( 0, -$op['saldo'] );
		$auszahlung = $k['stand'] - $forderung;

		$b  = '<p>' . esc_html( MV_Brief::anrede( $mv ) ) . '</p>';
		$b .= sprintf( '<p>' . esc_html__( 'das Mietverhältnis über die Wohnung %1$s / %2$s %3$s. Hiermit rechne ich die von Ihnen geleistete Mietsicherheit ab:', 'mietverwaltung' ) . '</p>', esc_html( $mv->objekt ), esc_html( $mv->einheit ), $mv->ende && $mv->ende !== '0000-00-00' ? 'endete am ' . esc_html( mv_date( $mv->ende ) ) : 'ist beendet' );
		$b .= '<table class="liste"><thead><tr><th>' . esc_html__( 'Datum', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Buchung', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Betrag', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
		foreach ( $k['buchungen'] as $x ) {
			$b .= sprintf( '<tr><td>%s</td><td>%s%s</td><td>%s%s</td></tr>', esc_html( mv_date( $x->datum ) ), esc_html( self::arten()[ $x->art ] ), $x->notiz ? ' – ' . esc_html( $x->notiz ) : '', in_array( $x->art, [ 'einzahlung', 'zins' ], true ) ? '' : '– ', esc_html( mv_eur( $x->betrag ) ) );
		}
		$b .= sprintf( '<tr class="summe"><td colspan="2">' . esc_html__( 'Kontostand einschließlich Zinsen', 'mietverwaltung' ) . '</td><td>%s</td></tr>', esc_html( mv_eur( $k['stand'] ) ) );
		if ( $forderung > 0 ) {
			$b .= sprintf( '<tr><td colspan="2">' . esc_html__( 'abzüglich offener Mietforderungen laut Mietkonto', 'mietverwaltung' ) . '</td><td>– %s</td></tr>', esc_html( mv_eur( $forderung ) ) );
		}
		$b .= sprintf( '<tr class="summe"><td colspan="2">%s</td><td>%s</td></tr></tbody></table>', $auszahlung >= 0 ? __( 'Auszahlungsbetrag', 'mietverwaltung' ) : __( 'Verbleibende Forderung gegen Sie', 'mietverwaltung' ), esc_html( mv_eur( abs( $auszahlung ) ) ) );
		if ( $auszahlung >= 0 ) {
			$b .= '<p>' . esc_html__( 'Der Betrag wird innerhalb von 14 Tagen auf das mir mitgeteilte Konto überwiesen. Sollte mir noch keine Bankverbindung vorliegen, bitte ich um Mitteilung.', 'mietverwaltung' ) . '</p>';
		} else {
			$b .= sprintf( '<p>' . esc_html__( 'Bitte überweisen Sie den verbleibenden Betrag von %s innerhalb von 14 Tagen.', 'mietverwaltung' ) . '</p>', esc_html( mv_eur( abs( $auszahlung ) ) ) ) . MV_Brief::bankverbindung();
		}
		$b        .= '<p class="hinweis">' . esc_html__( 'Mit dieser Abrechnung sind alle Ansprüche aus der Mietsicherheit abgegolten. Eine ausstehende Betriebskostenabrechnung für das letzte Nutzungsjahr bleibt vorbehalten, soweit nicht bereits berücksichtigt.', 'mietverwaltung' ) . '</p>';
		$objekt_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT objekt_id FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
		MV_Brief::ausgeben( 'Kautionsabrechnung ' . $mv->nachname, MV_Brief::empfaenger( $mv ), 'Abrechnung der Mietkaution', $b, null, true, [ $objekt_id, $mv_id, 'sonstiges', 'Kautionsabrechnung', true ] );
	}
}
