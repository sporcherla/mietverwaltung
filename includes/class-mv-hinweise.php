<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hinweise: Dashboard-Widget und tägliche E-Mail (WP-Cron).
 */
class MV_Hinweise {

	const CRON = 'mv_taeglich';

	public static function init(): void {
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'widget' ] );
		add_action( self::CRON, [ __CLASS__, 'cron' ] );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 07:00' ), 'daily', self::CRON );
		}
	}

	public static function deaktivieren(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Alle aktuellen Hinweise: [ ['typ' => warn|error|info, 'text' => ..., 'url' => ...] ] */
	public static function alle(): array {
		global $wpdb;
		$h = [];
		$heute = current_time( 'Y-m-d' );
		$jahr  = (int) current_time( 'Y' );
		$tag   = (int) current_time( 'j' );
		$monat = current_time( 'Y-m-01' );

		// Fehlende Mieteingänge (Zahltag + 5 Tage)
		foreach ( MV_Soll::alle_mv( true ) as $mv ) {
			$k = MV_Soll::konditionen( (int) $mv->id, $monat );
			if ( ! $k ) {
				$h[] = [ 'typ' => 'warn', 'text' => __( 'Keine Konditionen für ', 'mietverwaltung' ) . mv_monat( $monat ) . ' bei ' . MV_Soll::mv_label( $mv ), 'url' => admin_url( 'admin.php?page=mv_konditionen' ) ];
				continue;
			}
			$soll = (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz;
			$ist  = MV_Soll::ist( (int) $mv->id, $monat );
			if ( $tag > (int) $mv->zahltag + 5 && $ist + 0.005 < $soll ) {
				$h[] = [ 'typ' => 'error', 'text' => sprintf( __( 'Miete %1$s fehlt oder unvollständig: %2$s (Soll %3$s, Ist %4$s)', 'mietverwaltung' ), mv_monat( $monat ), MV_Soll::mv_label( $mv ), mv_eur( $soll ), mv_eur( $ist ) ), 'url' => admin_url( 'admin.php?page=mv_uebersicht' ) ];
			}
			$op = MV_Soll::op_liste( $mv );
			if ( $op['saldo'] < -0.005 ) {
				$h[] = [ 'typ' => 'error', 'text' => sprintf( __( 'Rückstand %1$s bei %2$s', 'mietverwaltung' ), mv_eur( -$op['saldo'] ), MV_Soll::mv_label( $mv ) ), 'url' => admin_url( 'admin.php?page=mv_mahnung' ) ];
			}
		}

		// Betriebskostenabrechnung Vorjahr (Frist 31.12.)
		$vorjahr = $jahr - 1;
		foreach ( $wpdb->get_results( "SELECT * FROM " . mv_table( 'objekte' ) ) as $o ) {
			$hat_mv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . mv_table( 'mietverhaeltnisse' ) . " mv JOIN " . mv_table( 'einheiten' ) . " e ON e.id = mv.einheit_id WHERE e.objekt_id = %d AND mv.beginn <= %s AND (mv.ende IS NULL OR mv.ende = '0000-00-00' OR mv.ende >= %s)", $o->id, $vorjahr . '-12-31', $vorjahr . '-01-01' ) );
			if ( ! $hat_mv ) {
				continue;
			}
			$fertig = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . mv_table( 'abrechnungen' ) . " WHERE objekt_id = %d AND status = 'fertig' AND bis >= %s AND bis <= %s", $o->id, $vorjahr . '-06-30', $vorjahr . '-12-31' ) );
			if ( ! $fertig ) {
				$rest = (int) ( ( strtotime( $jahr . '-12-31' ) - strtotime( $heute ) ) / 86400 );
				$h[] = [ 'typ' => $rest <= 60 ? 'error' : 'info', 'text' => sprintf( __( 'Betriebskostenabrechnung %1$d für %2$s noch nicht fertiggestellt – Frist 31.12.%3$d (§ 556 Abs. 3 BGB), noch %4$d Tage', 'mietverwaltung' ), $vorjahr, $o->bezeichnung, $jahr, $rest ), 'url' => admin_url( 'admin.php?page=mv_abrechnung' ) ];
			}
		}

		// Zinsbindung läuft in 12 Monaten aus
		foreach ( $wpdb->get_results( "SELECT * FROM " . mv_table( 'darlehen' ) . " WHERE status = 'aktiv' AND zinsbindung_bis IS NOT NULL" ) as $d ) {
			$rest = (int) ( ( strtotime( $d->zinsbindung_bis ) - strtotime( $heute ) ) / 86400 );
			if ( $rest <= 365 ) {
				$h[] = [ 'typ' => $rest <= 90 ? 'error' : 'warn', 'text' => sprintf( __( 'Zinsbindung %1$s endet am %2$s (%3$d Tage) – Anschlussfinanzierung prüfen', 'mietverwaltung' ), $d->bank, mv_date( $d->zinsbindung_bis ), $rest ), 'url' => admin_url( 'admin.php?page=mv_darlehen' ) ];
			}
			$besch = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . mv_table( 'darlehen_jahre' ) . " WHERE darlehen_id = %d AND jahr = %d", $d->id, $vorjahr ) );
			$raten = class_exists( 'MV_Darlehen' ) ? MV_Darlehen::jahreswerte( (int) $d->id, $vorjahr ) : null;
			if ( ! $besch && ! $raten && (int) current_time( 'n' ) >= 2 ) {
				$h[] = [ 'typ' => 'info', 'text' => sprintf( __( 'Zinsbescheinigung %1$d für Darlehen %2$s noch nicht erfasst', 'mietverwaltung' ), $vorjahr, $d->bank ), 'url' => admin_url( 'admin.php?page=mv_darlehen_jahre' ) ];
			}
		}

		// 15-%-Grenze
		if ( class_exists( 'MV_Afa' ) ) {
			foreach ( MV_Afa::pruefung_15_prozent() as $p ) {
				if ( $p['netto'] > $p['grenze'] ) {
					$h[] = [ 'typ' => 'error', 'text' => sprintf( __( 'Anschaffungsnahe Herstellungskosten überschritten bei %1$s (%2$s netto > %3$s)', 'mietverwaltung' ), $p['anlagegut']->bezeichnung, mv_eur( $p['netto'] ), mv_eur( $p['grenze'] ) ), 'url' => admin_url( 'admin.php?page=mv_steuer' ) ];
				} elseif ( $p['aktiv'] && $p['quote'] >= 10 ) {
					$h[] = [ 'typ' => 'warn', 'text' => sprintf( __( '15-%%-Grenze bei %1$s zu %2$s %% ausgeschöpft (Frist bis %3$s)', 'mietverwaltung' ), $p['anlagegut']->bezeichnung, number_format( $p['quote'], 1, ',', '.' ), mv_date( $p['bis'] ) ), 'url' => admin_url( 'admin.php?page=mv_steuer' ) ];
				}
			}
		}

		// Offene Meldungen
		$offen = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . mv_table( 'meldungen' ) . " WHERE status = 'offen'" );
		if ( $offen ) {
			$h[] = [ 'typ' => 'warn', 'text' => $offen . ' offene Meldung(en) aus dem Mieterportal', 'url' => admin_url( 'admin.php?page=mv_meldungen' ) ];
		}

		// Löschfristen (Datenschutz)
		if ( class_exists( 'MV_Datenschutz' ) ) {
			foreach ( MV_Datenschutz::faellig() as $f ) {
				$h[] = [ 'typ' => 'info', 'text' => __( 'Aufbewahrungsfrist abgelaufen: Mieterdaten „', 'mietverwaltung' ) . $f['label'] . '“ können anonymisiert werden', 'url' => admin_url( 'admin.php?page=mv_datenschutz' ) ];
			}
		}

		// Befristete Mietverhältnisse
		foreach ( MV_Soll::alle_mv( true ) as $mv ) {
			if ( $mv->ende && $mv->ende !== '0000-00-00' ) {
				$rest = (int) ( ( strtotime( $mv->ende ) - strtotime( $heute ) ) / 86400 );
				if ( $rest <= 90 ) {
					$h[] = [ 'typ' => 'info', 'text' => sprintf( __( 'Mietverhältnis %1$s endet am %2$s – Übergabe, Kaution, Zählerstände vorbereiten', 'mietverwaltung' ), MV_Soll::mv_label( $mv ), mv_date( $mv->ende ) ), 'url' => admin_url( 'admin.php?page=mv_mietverhaeltnisse' ) ];
				}
			}
		}

		return $h;
	}

	public static function widget(): void {
		if ( current_user_can( MV_Admin::CAP ) ) {
			wp_add_dashboard_widget( 'mv_dashboard', __( 'Mietverwaltung', 'mietverwaltung' ), [ __CLASS__, 'widget_inhalt' ] );
		}
	}

	public static function widget_inhalt(): void {
		$mvs = MV_Soll::alle_mv( true );
		echo '<ul>';
		foreach ( $mvs as $mv ) {
			$op = MV_Soll::op_liste( $mv );
			printf( '<li><strong>%s</strong>' . esc_html__( ': Saldo', 'mietverwaltung' ) . ' <span style="color:%s">%s</span></li>', esc_html( MV_Soll::mv_label( $mv ) ), $op['saldo'] < -0.005 ? '#b32d2e' : '#1a7f37', esc_html( mv_eur( $op['saldo'] ) ) );
		}
		echo '</ul>';
		$h = self::alle();
		if ( ! $h ) {
			echo '<p>' . esc_html__( '✅ Keine offenen Hinweise.', 'mietverwaltung' ) . '</p>';
		} else {
			echo '<ul style="margin-left:1em">';
			foreach ( $h as $x ) {
				$icon = [ 'error' => '🔴', 'warn' => '🟡', 'info' => 'ℹ️' ][ $x['typ'] ] ?? '';
				printf( '<li>%s <a href="%s">%s</a></li>', $icon, esc_url( $x['url'] ), esc_html( $x['text'] ) );
			}
			echo '</ul>';
		}
		printf( '<p><a href="%s" class="button">' . esc_html__( 'Zur Mietverwaltung', 'mietverwaltung' ) . '</a></p>', esc_url( admin_url( 'admin.php?page=mv_uebersicht' ) ) );
	}

	public static function cron(): void {
		if ( MV_Einstellungen::get( 'erinnerung_aktiv', '1' ) !== '1' ) {
			return;
		}
		$h = array_filter( self::alle(), fn( $x ) => $x['typ'] !== 'info' );
		if ( ! $h ) {
			return;
		}
		$hash = md5( wp_json_encode( array_column( $h, 'text' ) ) );
		if ( get_option( 'mv_hinweis_hash' ) === $hash && get_option( 'mv_hinweis_datum' ) > date( 'Y-m-d', strtotime( '-7 days' ) ) ) {
			return; // gleiche Hinweise: höchstens wöchentlich wiederholen
		}
		$text = "Hinweise aus der Mietverwaltung (" . current_time( 'd.m.Y' ) . "):\n\n";
		foreach ( $h as $x ) {
			$text .= ( $x['typ'] === 'error' ? '!! ' : ' - ' ) . $x['text'] . "\n   " . $x['url'] . "\n";
		}
		wp_mail( MV_Einstellungen::get( 'erinnerung_email', get_option( 'admin_email' ) ), '[Mietverwaltung] ' . count( $h ) . ' Hinweis(e)', $text );
		update_option( 'mv_hinweis_hash', $hash );
		update_option( 'mv_hinweis_datum', current_time( 'Y-m-d' ) );
	}
}
