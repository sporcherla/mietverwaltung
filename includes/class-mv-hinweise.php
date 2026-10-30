<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Hinweise: Dashboard-Widget und tägliche E-Mail (WP-Cron).
 */
class Mietv_Hinweise {

	const CRON = 'mietv_taeglich';

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
		$h     = [];
		$heute = current_time( 'Y-m-d' );
		$jahr  = (int) current_time( 'Y' );
		$tag   = (int) current_time( 'j' );
		$monat = current_time( 'Y-m-01' );

		// Fehlende Mieteingänge (Zahltag + 5 Tage)
		foreach ( Mietv_Soll::alle_mv( true ) as $mv ) {
			$k = Mietv_Soll::konditionen( (int) $mv->id, $monat );
			if ( ! $k ) {
				$h[] = [
					'typ'  => 'warn',
					'text' => sprintf( /* translators: %1$s: Monat, %2$s: Mietverhältnis */ __( 'No rent conditions for %1$s at %2$s', 'mietfux' ), mietv_monat( $monat ), Mietv_Soll::mietv_label( $mv ) ),
					'url'  => admin_url( 'admin.php?page=mietv_konditionen' ),
				];
				continue;
			}
			$soll = (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz;
			$ist  = Mietv_Soll::ist( (int) $mv->id, $monat );
			if ( $tag > (int) $mv->zahltag + 5 && $ist + 0.005 < $soll ) {
				$h[] = [
					'typ'  => 'error',
					'text' => sprintf( /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert, %4$s: Wert */ __( 'Rent %1$s missing or incomplete: %2$s (due %3$s, paid %4$s)', 'mietfux' ), mietv_monat( $monat ), Mietv_Soll::mietv_label( $mv ), mietv_eur( $soll ), mietv_eur( $ist ) ),
					'url'  => admin_url( 'admin.php?page=mietv_uebersicht' ),
				];
			}
			$op = Mietv_Soll::op_liste( $mv );
			if ( $op['saldo'] < -0.005 ) {
				$h[] = [
					'typ'  => 'error',
					'text' => sprintf( /* translators: %1$s: Wert, %2$s: Wert */ __( 'Arrears %1$s for %2$s', 'mietfux' ), mietv_eur( -$op['saldo'] ), Mietv_Soll::mietv_label( $mv ) ),
					'url'  => admin_url( 'admin.php?page=mietv_mahnung' ),
				];
			}
		}

		// Betriebskostenabrechnung Vorjahr (Frist 31.12.)
		$vorjahr = $jahr - 1;
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte' ) as $o ) {
			$hat_mv = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv JOIN ' . $wpdb->prefix . "mv_einheiten e ON e.id = mv.einheit_id WHERE e.objekt_id = %d AND mv.beginn <= %s AND (mv.ende IS NULL OR mv.ende = '0000-00-00' OR mv.ende >= %s)", $o->id, $vorjahr . '-12-31', $vorjahr . '-01-01' ) );
			if ( ! $hat_mv ) {
				continue;
			}
			$fertig = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_abrechnungen WHERE objekt_id = %d AND status = 'fertig' AND bis >= %s AND bis <= %s", $o->id, $vorjahr . '-06-30', $vorjahr . '-12-31' ) );
			if ( ! $fertig ) {
				$rest = (int) ( ( strtotime( $jahr . '-12-31' ) - strtotime( $heute ) ) / 86400 );
				$h[]  = [
					'typ'  => $rest <= 60 ? 'error' : 'info',
					'text' => sprintf( /* translators: %1$d: Wert, %2$s: Wert, %3$d: Wert, %4$d: Wert */ __( 'Service charge statement %1$d for %2$s not yet finalised – deadline 31 Dec %3$d (§ 556 (3) BGB), %4$d days left', 'mietfux' ), $vorjahr, $o->bezeichnung, $jahr, $rest ),
					'url'  => admin_url( 'admin.php?page=mietv_abrechnung' ),
				];
			}
		}

		// Zinsbindung läuft in 12 Monaten aus
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . "mv_darlehen WHERE status = 'aktiv' AND zinsbindung_bis IS NOT NULL" ) as $d ) {
			$rest = (int) ( ( strtotime( $d->zinsbindung_bis ) - strtotime( $heute ) ) / 86400 );
			if ( $rest <= 365 ) {
				$h[] = [
					'typ'  => $rest <= 90 ? 'error' : 'warn',
					'text' => sprintf( /* translators: %1$s: Wert, %2$s: Wert, %3$d: Wert */ __( 'Fixed-rate period %1$s ends on %2$s (%3$d days) – check follow-up financing', 'mietfux' ), $d->bank, mietv_date( $d->zinsbindung_bis ), $rest ),
					'url'  => admin_url( 'admin.php?page=mietv_darlehen' ),
				];
			}
			$besch = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_darlehen_jahre WHERE darlehen_id = %d AND jahr = %d', $d->id, $vorjahr ) );
			$raten = class_exists( 'Mietv_Darlehen' ) ? Mietv_Darlehen::jahreswerte( (int) $d->id, $vorjahr ) : null;
			if ( ! $besch && ! $raten && (int) current_time( 'n' ) >= 2 ) {
				$h[] = [
					'typ'  => 'info',
					'text' => sprintf( /* translators: %1$d: Wert, %2$s: Wert */ __( 'Interest certificate %1$d for loan %2$s not yet entered', 'mietfux' ), $vorjahr, $d->bank ),
					'url'  => admin_url( 'admin.php?page=mietv_darlehen_jahre' ),
				];
			}
		}

		// 15-%-Grenze
		if ( class_exists( 'Mietv_Afa' ) ) {
			foreach ( Mietv_Afa::pruefung_15_prozent() as $p ) {
				if ( $p['netto'] > $p['grenze'] ) {
					$h[] = [
						'typ'  => 'error',
						'text' => sprintf( /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert */ __( 'Acquisition-related construction cost limit exceeded for %1$s (%2$s net > %3$s)', 'mietfux' ), $p['anlagegut']->bezeichnung, mietv_eur( $p['netto'] ), mietv_eur( $p['grenze'] ) ),
						'url'  => admin_url( 'admin.php?page=mietv_steuer' ),
					];
				} elseif ( $p['aktiv'] && $p['quote'] >= 10 ) {
					$h[] = [
						'typ'  => 'warn',
						'text' => sprintf( /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert */ __( '15 %% limit for %1$s used up to %2$s %% (deadline %3$s)', 'mietfux' ), $p['anlagegut']->bezeichnung, number_format( $p['quote'], 1, ',', '.' ), mietv_date( $p['bis'] ) ),
						'url'  => admin_url( 'admin.php?page=mietv_steuer' ),
					];
				}
			}
		}

		// Offene Meldungen
		$offen = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_meldungen WHERE status = 'offen'" );
		if ( $offen ) {
			$h[] = [
				'typ'  => 'warn',
				'text' => $offen . ' offene Meldung(en) aus dem Mieterportal',
				'url'  => admin_url( 'admin.php?page=mietv_meldungen' ),
			];
		}

		// Löschfristen (Datenschutz)
		if ( class_exists( 'Mietv_Datenschutz' ) ) {
			foreach ( Mietv_Datenschutz::faellig() as $f ) {
				$h[] = [
					'typ'  => 'info',
					'text' => sprintf( /* translators: %s: Mietername */ __( 'Retention period expired: tenant data “%s” can be anonymised', 'mietfux' ), $f['label'] ),
					'url'  => admin_url( 'admin.php?page=mietv_datenschutz' ),
				];
			}
		}

		// Befristete Mietverhältnisse
		foreach ( Mietv_Soll::alle_mv( true ) as $mv ) {
			if ( $mv->ende && $mv->ende !== '0000-00-00' ) {
				$rest = (int) ( ( strtotime( $mv->ende ) - strtotime( $heute ) ) / 86400 );
				if ( $rest <= 90 ) {
					$h[] = [
						'typ'  => 'info',
						'text' => sprintf( /* translators: %1$s: Wert, %2$s: Wert */ __( 'Tenancy %1$s ends on %2$s – prepare handover, deposit, meter readings', 'mietfux' ), Mietv_Soll::mietv_label( $mv ), mietv_date( $mv->ende ) ),
						'url'  => admin_url( 'admin.php?page=mietv_mietverhaeltnisse' ),
					];
				}
			}
		}

		return $h;
	}

	public static function widget(): void {
		if ( current_user_can( Mietv_Admin::CAP ) ) {
			wp_add_dashboard_widget( 'mietv_dashboard', __( 'Mietfux', 'mietfux' ), [ __CLASS__, 'widget_inhalt' ] );
		}
	}

	public static function widget_inhalt(): void {
		$mvs = Mietv_Soll::alle_mv( true );
		echo '<ul>';
		foreach ( $mvs as $mv ) {
			$op = Mietv_Soll::op_liste( $mv );
			printf( '<li><strong>%s</strong>' . esc_html__( ': balance', 'mietfux' ) . ' <span style="color:%s">%s</span></li>', esc_html( Mietv_Soll::mietv_label( $mv ) ), $op['saldo'] < -0.005 ? '#b32d2e' : '#1a7f37', esc_html( mietv_eur( $op['saldo'] ) ) );
		}
		echo '</ul>';
		$h = self::alle();
		if ( ! $h ) {
			echo '<p>' . esc_html__( '✅ No open notices.', 'mietfux' ) . '</p>';
		} else {
			echo '<ul style="margin-left:1em">';
			foreach ( $h as $x ) {
				$icon = [
					'error' => '🔴',
					'warn'  => '🟡',
					'info'  => 'ℹ️',
				][ $x['typ'] ] ?? '';
				printf( '<li>%s <a href="%s">%s</a></li>', esc_html( $icon ), esc_url( $x['url'] ), esc_html( $x['text'] ) );
			}
			echo '</ul>';
		}
		printf( '<p><a href="%s" class="button">' . esc_html__( 'Go to Mietfux', 'mietfux' ) . '</a></p>', esc_url( admin_url( 'admin.php?page=mietv_uebersicht' ) ) );
	}

	public static function cron(): void {
		if ( Mietv_Einstellungen::get( 'erinnerung_aktiv', '1' ) !== '1' ) {
			return;
		}
		$h = array_filter( self::alle(), fn( $x ) => $x['typ'] !== 'info' );
		if ( ! $h ) {
			return;
		}
		$hash = md5( wp_json_encode( array_column( $h, 'text' ) ) );
		if ( get_option( 'mietv_hinweis_hash' ) === $hash && get_option( 'mietv_hinweis_datum' ) > gmdate( 'Y-m-d', strtotime( '-7 days' ) ) ) {
			return; // gleiche Hinweise: höchstens wöchentlich wiederholen
		}
		$text = sprintf( /* translators: %s: Datum */ __( 'Notices from Mietfux (%s):', 'mietfux' ), current_time( 'd.m.Y' ) ) . "\n\n";
		foreach ( $h as $x ) {
			$text .= ( $x['typ'] === 'error' ? '!! ' : ' - ' ) . $x['text'] . "\n   " . $x['url'] . "\n";
		}
		wp_mail( Mietv_Einstellungen::get( 'erinnerung_email', get_option( 'admin_email' ) ), '[Mietfux] ' . count( $h ) . ' Hinweis(e)', $text );
		update_option( 'mietv_hinweis_hash', $hash );
		update_option( 'mietv_hinweis_datum', current_time( 'Y-m-d' ) );
	}
}
