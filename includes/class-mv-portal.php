<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Mieterportal: Shortcode [mietverwaltung_portal]
 * Zeigt dem eingeloggten Mieter sein Mietverhältnis, Konditionen, Zahlungen, Saldo
 * und erlaubt Schadens-/Mängelmeldungen.
 */
class Mietv_Portal {

	public static function init(): void {
		add_shortcode( 'mietfux_portal', [ __CLASS__, 'shortcode' ] );
		add_shortcode( 'mietverwaltung_portal', [ __CLASS__, 'shortcode' ] ); // Alias aus Vorversionen.
		add_action( 'template_redirect', [ __CLASS__, 'handle_meldung' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_abrechnung' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_zaehler' ] );
	}

	/** Zählerstand aus dem Portal (nur eigene Zähler, mit Plausibilität). */
	public static function handle_zaehler(): void {
		if ( ! isset( $_POST['mietv_zaehler_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_zaehler_nonce'] ) ), 'mietv_zaehler' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietfux' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Kein Zugriff.', 'mietfux' ) );
		}
		global $wpdb;
		$erlaubt = array_map( fn( $mv ) => (int) $mv->einheit_id, self::eigene_mv() );
		$z       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_zaehler WHERE id = %d', ( isset( $_POST['zaehler_id'] ) ? (int) $_POST['zaehler_id'] : 0 ) ) );
		$stand   = mietv_to_float( sanitize_text_field( wp_unslash( $_POST['stand'] ?? '' ) ) );
		$datum   = ( sanitize_text_field( wp_unslash( $_POST['datum'] ?? '' ) ) ?: current_time( 'Y-m-d' ) );
		$flag    = 'fehler';
		if ( $z && in_array( (int) $z->einheit_id, $erlaubt, true ) && $stand > 0 && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) && $datum <= current_time( 'Y-m-d' ) ) {
			$vorher = $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum <= %s ORDER BY datum DESC LIMIT 1', $z->id, $datum ) );
			if ( $vorher === null || $stand >= (float) $vorher ) {
				$att = null;
				if ( ! empty( $_FILES['foto']['name'] ) && current_user_can( 'read' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					$r   = media_handle_upload( 'foto', 0 );
					$att = is_wp_error( $r ) ? null : (int) $r;
				}
				$wpdb->insert(
					mietv_table( 'zaehlerstaende' ),
					[
						'zaehler_id'    => $z->id,
						'datum'         => $datum,
						'stand'         => $stand,
						'quelle'        => 'mieter',
						'attachment_id' => $att,
					]
				);
				wp_mail( get_option( 'admin_email' ), __( '[Mietfux] Zählerstand gemeldet', 'mietfux' ), sprintf( 'Zähler %s (%s): %s am %s', $z->typ, $z->nummer, number_format( $stand, 2, ',', '.' ), mietv_date( $datum ) ) );
				$flag = 'ok';
			} else {
				$flag = 'kleiner';
			}
		}
		wp_safe_redirect( add_query_arg( 'mietv_zaehler', $flag, remove_query_arg( [ 'mietv_zaehler', 'mietv_meldung' ] ) ) );
		exit;
	}

	/** Freigegebene Abrechnung als Schreiben – nur für den eigenen Mieter. */
	public static function handle_abrechnung(): void {
		if ( ! isset( $_GET['mietv_abrechnung'], $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mietv_portal_abrechnung' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietfux' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Kein Zugriff.', 'mietfux' ) );
		}
		global $wpdb;
		$erg_id  = (int) $_GET['mietv_abrechnung'];
		$erlaubt = array_map( fn( $mv ) => (int) $mv->id, self::eigene_mv() );
		$mv_id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT mv_id FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis WHERE id = %d AND freigegeben = 1', $erg_id ) );
		if ( ! $mv_id || ! in_array( $mv_id, $erlaubt, true ) ) {
			wp_die( esc_html__( 'Kein Zugriff.', 'mietfux' ) );
		}
		Mietv_Abrechnung::brief( $erg_id, true );
	}

	/** Mietverhältnisse des eingeloggten Mieters. */
	private static function eigene_mv(): array {
		if ( ! is_user_logged_in() ) {
			return [];
		}
		$uid = get_current_user_id();
		return array_values( array_filter( Mietv_Soll::alle_mv(), fn( $mv ) => (int) $mv->wp_user_id === $uid ) );
	}

	public static function handle_meldung(): void {
		if ( ! isset( $_POST['mietv_meldung_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_meldung_nonce'] ) ), 'mietv_meldung' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietfux' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Kein Zugriff.', 'mietfux' ) );
		}
		global $wpdb;
		$mv_id   = ( isset( $_POST['mv_id'] ) ? (int) $_POST['mv_id'] : 0 );
		$betreff = sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) );
		$text    = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );

		$erlaubt = array_map( fn( $mv ) => (int) $mv->id, self::eigene_mv() );
		if ( ! in_array( $mv_id, $erlaubt, true ) || $betreff === '' ) {
			wp_safe_redirect( add_query_arg( 'mietv_meldung', 'fehler', remove_query_arg( 'mietv_meldung' ) ) );
			exit;
		}

		$wpdb->insert(
			mietv_table( 'meldungen' ),
			[
				'mv_id'   => $mv_id,
				'datum'   => current_time( 'mysql' ),
				'betreff' => $betreff,
				'text'    => $text,
				'status'  => 'offen',
			]
		);

		// Vermieter benachrichtigen
		$mv = null;
		foreach ( self::eigene_mv() as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( $mv ) {
			wp_mail(
				get_option( 'admin_email' ),
				__( '[Mietfux] Neue Meldung: ', 'mietfux' ) . $betreff,
				'Mieter: ' . Mietv_Soll::mietv_label( $mv ) . "\n\n" . $text . "\n\nBearbeiten: " . admin_url( 'admin.php?page=mietv_meldungen' )
			);
		}

		wp_safe_redirect( add_query_arg( 'mietv_meldung', 'ok', remove_query_arg( 'mietv_meldung' ) ) );
		exit;
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="mv-portal"><p>' . esc_html__( 'Bitte melden Sie sich an, um Ihr Mieterportal zu sehen.', 'mietfux' ) . '</p><p><a class="mv-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Anmelden</a></p></div>';
		}

		$mvs = self::eigene_mv();
		if ( ! $mvs ) {
			return '<div class="mv-portal"><p>' . esc_html__( 'Ihrem Benutzerkonto ist noch kein Mietverhältnis zugeordnet. Bitte wenden Sie sich an Ihren Vermieter.', 'mietfux' ) . '</p></div>';
		}

		global $wpdb;
		ob_start();
		wp_enqueue_style( 'mietv-portal', MIETV_URL . 'assets/portal.css', [], MIETV_VERSION );
		echo '<div class="mv-portal">';

		$zf = isset( $_GET['mietv_zaehler'] ) ? sanitize_key( $_GET['mietv_zaehler'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		if ( $zf === 'ok' ) {
			echo '<p class="mv-notice mv-ok">' . esc_html__( 'Zählerstand gespeichert. Vielen Dank.', 'mietfux' ) . '</p>';
		} elseif ( $zf === 'kleiner' ) {
			echo '<p class="mv-notice mv-err">' . esc_html__( 'Der Stand ist kleiner als der zuletzt erfasste – bitte prüfen.', 'mietfux' ) . '</p>';
		} elseif ( $zf === 'fehler' ) {
			echo '<p class="mv-notice mv-err">' . esc_html__( 'Zählerstand konnte nicht gespeichert werden.', 'mietfux' ) . '</p>';
		}
		$flag = isset( $_GET['mietv_meldung'] ) ? sanitize_key( $_GET['mietv_meldung'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		if ( $flag === 'ok' ) {
			echo '<p class="mv-notice mv-ok">' . esc_html__( 'Ihre Meldung wurde übermittelt. Vielen Dank.', 'mietfux' ) . '</p>';
		} elseif ( $flag === 'fehler' ) {
			echo '<p class="mv-notice mv-err">' . esc_html__( 'Die Meldung konnte nicht gespeichert werden. Bitte Betreff angeben.', 'mietfux' ) . '</p>';
		}

		foreach ( $mvs as $mv ) {
			$op = Mietv_Soll::op_liste( $mv );
			$k  = Mietv_Soll::konditionen( (int) $mv->id, current_time( 'Y-m' ) . '-01' );

			printf( '<h3>%s – %s</h3>', esc_html( $mv->objekt ), esc_html( $mv->einheit ) );
			echo '<div class="mv-grid">';
			printf( '<div class="mv-card"><span>' . esc_html__( 'Mietbeginn', 'mietfux' ) . '</span><strong>%s</strong></div>', esc_html( mietv_date( $mv->beginn ) ) );
			printf( '<div class="mv-card"><span>' . esc_html__( 'Kaltmiete', 'mietfux' ) . '</span><strong>%s</strong></div>', esc_html( $k ? mietv_eur( $k->kaltmiete ) : '–' ) );
			printf( '<div class="mv-card"><span>' . esc_html__( 'Vorauszahlungen BK / HK', 'mietfux' ) . '</span><strong>%s / %s</strong></div>', esc_html( $k ? mietv_eur( $k->bk_vz ) : '–' ), esc_html( $k ? mietv_eur( $k->hk_vz ) : '–' ) );
			printf( '<div class="mv-card"><span>' . esc_html__( 'Gesamtmiete / Monat', 'mietfux' ) . '</span><strong>%s</strong></div>', esc_html( $k ? mietv_eur( (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz ) : '–' ) );
			printf( '<div class="mv-card"><span>' . esc_html__( 'Kaution', 'mietfux' ) . '</span><strong>%s</strong></div>', esc_html( mietv_eur( $mv->kaution ) ) );
			$cls = $op['saldo'] < -0.005 ? 'mv-neg' : 'mv-pos';
			printf(
				'<div class="mv-card"><span>' . esc_html__( 'Kontostand', 'mietfux' ) . '</span><strong class="%s">%s</strong><small>%s</small></div>',
				esc_attr( $cls ),
				esc_html( mietv_eur( $op['saldo'] ) ),
				$op['saldo'] < -0.005 ? esc_html__( 'Rückstand', 'mietfux' ) : esc_html__( 'ausgeglichen / Guthaben', 'mietfux' )
			);
			echo '</div>';

			// Letzte 12 Monate
			echo '<h4>' . esc_html__( 'Mietkonto (letzte 12 Monate)', 'mietfux' ) . '</h4><table class="mv-table"><thead><tr><th>' . esc_html__( 'Monat', 'mietfux' ) . '</th><th>' . esc_html__( 'Soll', 'mietfux' ) . '</th><th>' . esc_html__( 'Gezahlt', 'mietfux' ) . '</th><th>' . esc_html__( 'Differenz', 'mietfux' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( array_reverse( $op['rows'] ), 0, 12 ) as $r ) {
				$c = $r['diff'] < -0.005 ? 'mv-neg' : '';
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td></tr>',
					esc_html( mietv_monat( $r['monat'] ) ),
					esc_html( mietv_eur( $r['soll'] ) ),
					esc_html( mietv_eur( $r['ist'] ) ),
					esc_attr( $c ),
					esc_html( mietv_eur( $r['diff'] ) )
				);
			}
			echo '</tbody></table>';

			// Meldungen
			$meldungen = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_meldungen WHERE mv_id = %d ORDER BY datum DESC LIMIT 20', $mv->id ) );
			echo '<h4>' . esc_html__( 'Meldungen', 'mietfux' ) . '</h4>';
			if ( $meldungen ) {
				$status = [
					'offen'          => esc_html__( 'Offen', 'mietfux' ),
					'in_bearbeitung' => esc_html__( 'In Bearbeitung', 'mietfux' ),
					'erledigt'       => esc_html__( 'Erledigt', 'mietfux' ),
				];
				echo '<table class="mv-table"><thead><tr><th>' . esc_html__( 'Datum', 'mietfux' ) . '</th><th>' . esc_html__( 'Betreff', 'mietfux' ) . '</th><th>' . esc_html__( 'Status', 'mietfux' ) . '</th></tr></thead><tbody>';
				foreach ( $meldungen as $m ) {
					printf(
						'<tr><td>%s</td><td>%s</td><td><span class="mv-status mv-status-%s">%s</span></td></tr>',
						esc_html( date_i18n( 'd.m.Y', strtotime( $m->datum ) ) ),
						esc_html( $m->betreff ),
						esc_attr( $m->status ),
						esc_html( $status[ $m->status ] ?? $m->status )
					);
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . esc_html__( 'Keine Meldungen.', 'mietfux' ) . '</p>';
			}

			// Freigegebene Abrechnungen
			$abrs = $wpdb->get_results( $wpdb->prepare( 'SELECT e.*, a.von, a.bis FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis e JOIN ' . $wpdb->prefix . 'mv_abrechnungen a ON a.id = e.abrechnung_id WHERE e.mv_id = %d AND e.freigegeben = 1 ORDER BY a.bis DESC', $mv->id ) );
			if ( $abrs ) {
				echo '<h4>' . esc_html__( 'Betriebskostenabrechnungen', 'mietfux' ) . '</h4><table class="mv-table"><thead><tr><th>' . esc_html__( 'Zeitraum', 'mietfux' ) . '</th><th>' . esc_html__( 'Kosten', 'mietfux' ) . '</th><th>' . esc_html__( 'Vorauszahlungen', 'mietfux' ) . '</th><th>' . esc_html__( 'Ergebnis', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $abrs as $a ) {
					printf(
						'<tr><td>%s – %s</td><td>%s</td><td>%s</td><td class="%s">%s %s</td><td><a href="%s" target="_blank">' . esc_html__( 'Abrechnung öffnen', 'mietfux' ) . '</a></td></tr>',
						esc_html( mietv_date( $a->von ) ),
						esc_html( mietv_date( $a->bis ) ),
						esc_html( mietv_eur( $a->kosten_bk + $a->kosten_hk ) ),
						esc_html( mietv_eur( $a->vz_bk + $a->vz_hk ) ),
						$a->saldo < 0 ? 'mv-neg' : 'mv-pos',
						$a->saldo < 0 ? esc_html__( 'Nachzahlung', 'mietfux' ) : esc_html__( 'Guthaben', 'mietfux' ),
						esc_html( mietv_eur( abs( $a->saldo ) ) ),
						esc_url( wp_nonce_url( add_query_arg( 'mietv_abrechnung', (int) $a->id, get_permalink() ), 'mietv_portal_abrechnung' ) )
					);
				}
				echo '</tbody></table>';
			}

			// Freigegebene Dokumente
			$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_dokumente WHERE mv_id = %d AND mieter_sichtbar = 1 AND attachment_id IS NOT NULL ORDER BY datum DESC', $mv->id ) );
			if ( $docs ) {
				echo '<h4>' . esc_html__( 'Dokumente', 'mietfux' ) . '</h4><table class="mv-table"><thead><tr><th>' . esc_html__( 'Datum', 'mietfux' ) . '</th><th>' . esc_html__( 'Dokument', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $docs as $d ) {
					$url = wp_get_attachment_url( (int) $d->attachment_id );
					printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( mietv_date( $d->datum ) ), esc_html( $d->bezeichnung ), $url ? '<a href="' . esc_url( $url ) . '" target="_blank">öffnen</a>' : '' );
				}
				echo '</tbody></table>';
			}

			// Zählerstände melden
			$zaehler = $wpdb->get_results( $wpdb->prepare( "SELECT z.*, (SELECT CONCAT(stand, '|', datum) FROM " . $wpdb->prefix . 'mv_zaehlerstaende s WHERE s.zaehler_id = z.id ORDER BY datum DESC LIMIT 1) AS letzter FROM ' . $wpdb->prefix . 'mv_zaehler z WHERE z.einheit_id = %d ORDER BY z.typ', $mv->einheit_id ) );
			if ( $zaehler ) {
				$typen = [
					'kaltwasser' => esc_html__( 'Kaltwasser (m³)', 'mietfux' ),
					'warmwasser' => esc_html__( 'Warmwasser (m³)', 'mietfux' ),
					'heizung'    => esc_html__( 'Heizung', 'mietfux' ),
					'strom'      => esc_html__( 'Strom (kWh)', 'mietfux' ),
				];
				echo '<h4>' . esc_html__( 'Zählerstand melden', 'mietfux' ) . '</h4><form method="post" enctype="multipart/form-data" class="mv-form">';
				wp_nonce_field( 'mietv_zaehler', 'mietv_zaehler_nonce' );
				echo '<p><label>' . esc_html__( 'Zähler', 'mietfux' ) . '<br><select name="zaehler_id">';
				foreach ( $zaehler as $z ) {
					[ $ls, $ld ] = $z->letzter ? explode( '|', $z->letzter ) : [ null, null ];
					printf( '<option value="%d">%s %s%s</option>', (int) $z->id, esc_html( $typen[ $z->typ ] ?? $z->typ ), esc_html( $z->nummer ), $ls !== null ? ' – zuletzt ' . esc_html( number_format( (float) $ls, 2, ',', '.' ) . ' am ' . mietv_date( $ld ) ) : '' );
				}
				printf( '</select></label></p><p><label>' . esc_html__( 'Datum', 'mietfux' ) . '<br><input type="date" name="datum" value="%s" max="%s"></label></p><p><label>' . esc_html__( 'Stand', 'mietfux' ) . '<br><input type="text" name="stand" inputmode="decimal" required></label></p><p><label>' . esc_html__( 'Foto (optional)', 'mietfux' ) . '<br><input type="file" name="foto" accept="image/*" capture="environment"></label></p><p><button type="submit" class="mv-btn">' . esc_html__( 'Zählerstand senden', 'mietfux' ) . '</button></p></form>', esc_attr( current_time( 'Y-m-d' ) ), esc_attr( current_time( 'Y-m-d' ) ) );
			}

			echo '<form method="post" class="mv-form"><h4>' . esc_html__( 'Schaden oder Mangel melden', 'mietfux' ) . '</h4>';
			wp_nonce_field( 'mietv_meldung', 'mietv_meldung_nonce' );
			printf( '<input type="hidden" name="mv_id" value="%d">', (int) $mv->id );
			echo '<p><label>' . esc_html__( 'Betreff', 'mietfux' ) . '<br><input type="text" name="betreff" required maxlength="190"></label></p>';
			echo '<p><label>' . esc_html__( 'Beschreibung', 'mietfux' ) . '<br><textarea name="text" rows="5"></textarea></label></p>';
			echo '<p><button type="submit" class="mv-btn">' . esc_html__( 'Meldung absenden', 'mietfux' ) . '</button></p></form>';
		}

		echo '</div>';
		return ob_get_clean();
	}
}
