<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Hausgeld (Eigentumswohnung / WEG): monatliche Zahlung an die Hausverwaltung,
 * aufgeteilt nach Wirtschaftsplan auf Kostenarten (umlagefähig, Werbungskosten,
 * Erhaltungsrücklage). Jede Zahlung erzeugt Belege je Kostenart, die in die
 * Betriebskostenabrechnung (Leistungszeitraum) und die Anlage V (Abflussjahr) einfließen.
 * Die WEG-Jahresabrechnung erzeugt Korrekturbelege (Nachzahlung/Guthaben) je Kostenart.
 */
class Mietv_Hausgeld {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Daten                                                              */
	/* ------------------------------------------------------------------ */

	/** Alle Pläne eines Objekts, neueste zuerst. */
	public static function plaene( int $objekt_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_hausgeld WHERE objekt_id = %d ORDER BY gueltig_ab DESC, id DESC', $objekt_id ) );
		foreach ( $rows as $r ) {
			$r->verteilung = json_decode( (string) $r->verteilung, true ) ?: [];
		}
		return $rows;
	}

	/** Plan, der für einen Monat (YYYY-MM-01) gilt. */
	public static function plan_fuer( int $objekt_id, string $monat ): ?object {
		foreach ( self::plaene( $objekt_id ) as $p ) {
			if ( $p->gueltig_ab <= $monat ) {
				return $p;
			}
		}
		return null;
	}

	/** Alle Pläne aller Objekte (für Bankimport). */
	public static function alle_plaene(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_hausgeld ORDER BY objekt_id, gueltig_ab DESC' );
		foreach ( $rows as $r ) {
			$r->verteilung = json_decode( (string) $r->verteilung, true ) ?: [];
		}
		return $rows;
	}

	/** Buchungen eines Objekts in einem Jahr: [ 'YYYY-MM-01' => row ] für Monatszahlungen, 'abrechnung' => row. */
	public static function buchungen( int $objekt_id, int $jahr ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_hausgeld_buchungen WHERE objekt_id = %d AND jahr = %d ORDER BY monat', $objekt_id, $jahr ) ) as $b ) {
			$out[ $b->art === 'abrechnung' ? 'abrechnung' : $b->monat ] = $b;
		}
		return $out;
	}

	/** Summe der gebuchten Hausgeld-Belege je Kostenart für ein Jahr (Leistungszeitraum). */
	public static function gebucht_je_kostenart( int $objekt_id, int $jahr ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.kostenart_id, SUM(b.betrag) AS summe FROM ' . $wpdb->prefix . 'mv_belege b
				 INNER JOIN ' . $wpdb->prefix . "mv_hausgeld_buchungen h ON h.id = b.hausgeld_id
				 WHERE b.objekt_id = %d AND h.jahr = %d AND h.art = 'plan' GROUP BY b.kostenart_id",
				$objekt_id,
				$jahr
			)
		);
		$out  = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->kostenart_id ] = (float) $r->summe;
		}
		return $out;
	}

	/** Kostenarten als id => Objekt. */
	public static function kostenarten(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_kostenarten ORDER BY sortierung, name', OBJECT_K );
	}

	/** Aufteilung eines Plans in [umlagefaehig, werbungskosten, ruecklage]. */
	public static function aufteilung( object $plan ): array {
		$ka  = self::kostenarten();
		$out = [
			'umlagefaehig'   => 0.0,
			'werbungskosten' => 0.0,
			'ruecklage'      => 0.0,
		];
		foreach ( $plan->verteilung as $kid => $betrag ) {
			$k = $ka[ (int) $kid ] ?? null;
			if ( ! $k ) {
				continue;
			}
			if ( $k->anlage_v === 'ruecklage' ) {
				$out['ruecklage'] += (float) $betrag;
			} elseif ( (int) $k->umlagefaehig ) {
				$out['umlagefaehig'] += (float) $betrag;
			} else {
				$out['werbungskosten'] += (float) $betrag;
			}
		}
		return $out;
	}

	/**
	 * Monatszahlung buchen: erzeugt die Buchung und je Planzeile einen Beleg.
	 * Liefert die Buchungs-ID oder 0 (bereits gebucht / kein Plan).
	 */
	public static function buchen_monat( int $objekt_id, string $monat, string $datum, ?float $betrag = null ): int {
		global $wpdb;
		if ( ! preg_match( '/^\d{4}-\d{2}-01$/', $monat ) ) {
			return 0;
		}
		$plan = self::plan_fuer( $objekt_id, $monat );
		if ( ! $plan ) {
			return 0;
		}
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . "mv_hausgeld_buchungen WHERE objekt_id = %d AND monat = %s AND art = 'plan'", $objekt_id, $monat ) );
		if ( $exists ) {
			return 0;
		}
		$plan_summe = array_sum( array_map( 'floatval', $plan->verteilung ) );
		$betrag     = $betrag !== null && $betrag > 0 ? $betrag : (float) $plan->betrag;
		// Abweichender Zahlbetrag (z. B. aus dem Bankimport): anteilig auf die Planzeilen verteilen.
		$faktor = $plan_summe > 0.005 ? $betrag / $plan_summe : 1;
		$wpdb->insert(
			mietv_table( 'hausgeld_buchungen' ),
			[
				'objekt_id' => $objekt_id,
				'plan_id'   => (int) $plan->id,
				'monat'     => $monat,
				'datum'     => $datum,
				'betrag'    => $betrag,
				'art'       => 'plan',
				'jahr'      => (int) substr( $monat, 0, 4 ),
			]
		);
		$bid  = (int) $wpdb->insert_id;
		$ende = gmdate( 'Y-m-t', strtotime( $monat ) );
		$rest = round( $betrag, 2 );
		$n    = count( $plan->verteilung );
		$i    = 0;
		foreach ( $plan->verteilung as $kid => $anteil ) {
			++$i;
			$zeile = $i === $n ? $rest : round( (float) $anteil * $faktor, 2 );
			$rest  = round( $rest - $zeile, 2 );
			if ( abs( $zeile ) < 0.005 ) {
				continue;
			}
			$wpdb->insert(
				mietv_table( 'belege' ),
				[
					'owner_id'         => MIETV_OWNER_ID,
					'objekt_id'        => $objekt_id,
					'kostenart_id'     => (int) $kid,
					'datum'            => $datum,
					'steuerjahr'       => (int) substr( $datum, 0, 4 ),
					'betrag'           => $zeile,
					'zeitraum_von'     => $monat,
					'zeitraum_bis'     => $ende,
					'lieferant'        => $plan->verwalter,
					'belegnr'          => 'HG-' . substr( $monat, 0, 7 ),
					'beschreibung'     => sprintf( /* translators: %s: Monat */ __( 'Owners’ association fee %s', 'mietfux' ), mietv_monat( $monat ) ),
					'verteilung_jahre' => 1,
					'hausgeld_id'      => $bid,
				]
			);
		}
		return $bid;
	}

	/**
	 * WEG-Jahresabrechnung buchen: je Kostenart Differenz (Ist lt. Abrechnung − gebuchte Vorauszahlungen)
	 * als Korrekturbeleg mit Leistungszeitraum = Abrechnungsjahr und Zahlungsdatum = Abflussdatum.
	 */
	public static function buchen_abrechnung( int $objekt_id, int $jahr, array $ist, string $datum ): int {
		global $wpdb;
		if ( isset( self::buchungen( $objekt_id, $jahr )['abrechnung'] ) ) {
			return 0;
		}
		$plan = self::plan_fuer( $objekt_id, $jahr . '-12-01' ) ?: self::plan_fuer( $objekt_id, $jahr . '-01-01' );
		if ( ! $plan ) {
			return 0;
		}
		$gebucht = self::gebucht_je_kostenart( $objekt_id, $jahr );
		$diffs   = [];
		foreach ( $ist as $kid => $wert ) {
			$kid  = (int) $kid;
			$diff = round( (float) $wert - ( $gebucht[ $kid ] ?? 0.0 ), 2 );
			if ( abs( $diff ) >= 0.005 ) {
				$diffs[ $kid ] = $diff;
			}
		}
		if ( ! $diffs ) {
			return 0;
		}
		$wpdb->insert(
			mietv_table( 'hausgeld_buchungen' ),
			[
				'objekt_id' => $objekt_id,
				'plan_id'   => (int) $plan->id,
				'monat'     => $jahr . '-12-01',
				'datum'     => $datum,
				'betrag'    => round( array_sum( $diffs ), 2 ),
				'art'       => 'abrechnung',
				'jahr'      => $jahr,
			]
		);
		$bid = (int) $wpdb->insert_id;
		foreach ( $diffs as $kid => $diff ) {
			$wpdb->insert(
				mietv_table( 'belege' ),
				[
					'owner_id'         => MIETV_OWNER_ID,
					'objekt_id'        => $objekt_id,
					'kostenart_id'     => $kid,
					'datum'            => $datum,
					'steuerjahr'       => (int) substr( $datum, 0, 4 ),
					'betrag'           => $diff,
					'zeitraum_von'     => $jahr . '-01-01',
					'zeitraum_bis'     => $jahr . '-12-31',
					'lieferant'        => $plan->verwalter,
					'belegnr'          => 'HG-ABR-' . $jahr,
					'beschreibung'     => sprintf( /* translators: %d: Jahr */ __( 'Owners’ association annual statement %d – adjustment', 'mietfux' ), $jahr ),
					'verteilung_jahre' => 1,
					'hausgeld_id'      => $bid,
				]
			);
		}
		return $bid;
	}

	/** Buchung samt Belegen löschen. */
	public static function loeschen( int $buchung_id ): void {
		global $wpdb;
		$wpdb->delete( mietv_table( 'belege' ), [ 'hausgeld_id' => $buchung_id ] );
		$wpdb->delete( mietv_table( 'hausgeld_buchungen' ), [ 'id' => $buchung_id ] );
	}

	/** Offene (nicht gebuchte) Monate bis heute für alle Objekte mit Plan – für Hinweise. */
	public static function offene_monate(): array {
		$out   = [];
		$heute = current_time( 'Y-m-01' );
		foreach ( self::alle_plaene() as $p ) {
			if ( isset( $out[ $p->objekt_id ] ) ) {
				continue;
			}
			$jahr    = (int) substr( $heute, 0, 4 );
			$gebucht = self::buchungen( (int) $p->objekt_id, $jahr );
			$start   = max( $p->gueltig_ab, $jahr . '-01-01' );
			$cursor  = new DateTime( substr( $start, 0, 7 ) . '-01' );
			$fehlt   = [];
			while ( $cursor->format( 'Y-m-01' ) <= $heute ) {
				$m = $cursor->format( 'Y-m-01' );
				if ( ! isset( $gebucht[ $m ] ) ) {
					$fehlt[] = $m;
				}
				$cursor->modify( '+1 month' );
			}
			if ( $fehlt ) {
				$out[ $p->objekt_id ] = $fehlt;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mietv_hausgeld_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_hausgeld_nonce'] ?? '' ) ), 'mietv_hausgeld' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		global $wpdb;
		$objekt_id = isset( $_POST['objekt_id'] ) ? (int) $_POST['objekt_id'] : 0;
		$jahr      = isset( $_POST['jahr'] ) ? (int) $_POST['jahr'] : (int) current_time( 'Y' );
		if ( ! $objekt_id ) {
			return;
		}
		$n = 0;
		if ( ! empty( $_POST['plan_speichern'] ) ) {
			$verteilung = [];
			foreach ( map_deep( wp_unslash( (array) ( $_POST['vt'] ?? [] ) ), 'sanitize_text_field' ) as $kid => $wert ) {
				$wert = mietv_to_float( $wert );
				if ( $wert > 0.005 ) {
					$verteilung[ (int) $kid ] = round( $wert, 2 );
				}
			}
			$daten   = [
				'owner_id'   => MIETV_OWNER_ID,
				'objekt_id'  => $objekt_id,
				'verwalter'  => sanitize_text_field( wp_unslash( $_POST['verwalter'] ?? '' ) ),
				'iban'       => strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['iban'] ?? '' ) ) ) ),
				'betrag'     => mietv_to_float( sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '' ) ) ),
				'gueltig_ab' => preg_match( '/^\d{4}-\d{2}/', sanitize_text_field( wp_unslash( $_POST['gueltig_ab'] ?? '' ) ), $m ) ? $m[0] . '-01' : current_time( 'Y-m-01' ),
				'verteilung' => wp_json_encode( $verteilung ),
			];
			$plan_id = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
			$vorh    = $plan_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_hausgeld WHERE id = %d AND objekt_id = %d', $plan_id, $objekt_id ) ) : null;
			if ( $vorh && $vorh->gueltig_ab === $daten['gueltig_ab'] ) {
				$wpdb->update( mietv_table( 'hausgeld' ), $daten, [ 'id' => $plan_id ] );
			} else {
				$wpdb->insert( mietv_table( 'hausgeld' ), $daten );
			}
			mietv_flash_set( 'plan' );
		} elseif ( ! empty( $_POST['plan_loeschen'] ) ) {
			$wpdb->delete(
				mietv_table( 'hausgeld' ),
				[
					'id'        => (int) $_POST['plan_loeschen'],
					'objekt_id' => $objekt_id,
				]
			);
			mietv_flash_set( 'plan' );
		} elseif ( ! empty( $_POST['loeschen'] ) ) {
			$bid = (int) $_POST['loeschen'];
			$b   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_hausgeld_buchungen WHERE id = %d AND objekt_id = %d', $bid, $objekt_id ) );
			if ( $b ) {
				self::loeschen( $bid );
			}
			mietv_flash_set( 'ok', [ 'n' => 0 ] );
		} elseif ( ! empty( $_POST['abrechnung_buchen'] ) ) {
			$ist = [];
			foreach ( map_deep( wp_unslash( (array) ( $_POST['ist'] ?? [] ) ), 'sanitize_text_field' ) as $kid => $wert ) {
				if ( $wert !== '' ) {
					$ist[ (int) $kid ] = mietv_to_float( $wert );
				}
			}
			$datum = sanitize_text_field( wp_unslash( $_POST['abr_datum'] ?? '' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
				$datum = current_time( 'Y-m-d' );
			}
			$n = self::buchen_abrechnung( $objekt_id, $jahr, $ist, $datum ) ? 1 : 0;
			mietv_flash_set( $n ? 'abr' : 'abr_leer' );
		} else {
			$tag      = max( 1, min( 28, isset( $_POST['tag'] ) ? (int) $_POST['tag'] : 1 ) );
			$markiert = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['monat'] ?? [] ) ) );
			sort( $markiert );
			foreach ( $markiert as $monat ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-01$/', $monat ) ) {
					continue;
				}
				$datum = substr( $monat, 0, 8 ) . sprintf( '%02d', $tag );
				if ( self::buchen_monat( $objekt_id, $monat, $datum ) ) {
					++$n;
				}
			}
			mietv_flash_set( 'ok', [ 'n' => $n ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_hausgeld&objekt_id=' . $objekt_id . '&jahr=' . $jahr ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		global $wpdb;
		$objekte   = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte ORDER BY bezeichnung' );
		$objekt_id = isset( $_GET['objekt_id'] ) ? (int) $_GET['objekt_id'] : (int) ( $objekte[0]->id ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$jahr      = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : (int) current_time( 'Y' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$ob        = null;
		foreach ( $objekte as $o ) {
			if ( (int) $o->id === $objekt_id ) {
				$ob = $o;
			}
		}
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Owners’ association fee', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		$code  = $flash['code'] ?? '';
		if ( $code === 'ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %d: Wert */ esc_html__( '%d entry/entries created.', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ) );
		} elseif ( $code === 'plan' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Budget plan saved.', 'mietfux' ) . '</p></div>';
		} elseif ( $code === 'abr' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Annual statement booked – adjustment receipts created per cost type.', 'mietfux' ) . '</p></div>';
		} elseif ( $code === 'abr_leer' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing to book: no differences to the advance payments, or a statement for this year already exists.', 'mietfux' ) . '</p></div>';
		}
		if ( ! $objekte ) {
			printf( '<p>' . esc_html__( 'No property created yet –', 'mietfux' ) . ' <a href="%s">' . esc_html__( 'Create property', 'mietfux' ) . '</a></p></div>', esc_url( admin_url( 'admin.php?page=mietv_objekte&action=new' ) ) );
			return;
		}
		echo '<p class="description">' . esc_html__( 'For condominiums: the monthly fee paid to the property manager is split according to the budget plan into recoverable service charges (passed on in the service charge statement), non-recoverable expenses (management, maintenance – deductible in Anlage V) and the contribution to the maintenance reserve (not deductible until spent). Each booked month creates one receipt per cost type; the annual statement of the owners’ association books the differences.', 'mietfux' ) . '</p>';

		// Filter
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_hausgeld"><label>' . esc_html__( 'Property', 'mietfux' ) . ' <select name="objekt_id" data-autosubmit="1">';
		foreach ( $objekte as $o ) {
			printf( '<option value="%d"%s>%s</option>', (int) $o->id, selected( $objekt_id, (int) $o->id, false ), esc_html( $o->bezeichnung ) );
		}
		echo '</select></label> <label>' . esc_html__( 'Year', 'mietfux' ) . ' <select name="jahr" data-autosubmit="1">';
		$jetzt = (int) current_time( 'Y' );
		for ( $j = $jetzt + 1; $j >= $jetzt - 6; $j-- ) {
			printf( '<option value="%d"%s>%d</option>', (int) $j, selected( $jahr, $j, false ), (int) $j );
		}
		echo '</select></label></form>';
		if ( ! $ob ) {
			echo '</div>';
			return;
		}

		$plaene  = self::plaene( $objekt_id );
		$aktuell = self::plan_fuer( $objekt_id, current_time( 'Y-m-01' ) ) ?: ( $plaene[0] ?? null );
		$ka      = self::kostenarten();

		if ( $aktuell ) {
			$teile = self::aufteilung( $aktuell );
			printf(
				'<div class="mv-kacheln"><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s %s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div></div>',
				esc_html__( 'Monthly fee', 'mietfux' ),
				esc_html( mietv_eur( $aktuell->betrag ) ),
				esc_html__( 'valid from', 'mietfux' ),
				esc_html( mietv_monat( $aktuell->gueltig_ab ) ),
				esc_html__( 'Recoverable', 'mietfux' ),
				esc_html( mietv_eur( $teile['umlagefaehig'] ) ),
				esc_html__( 'passed on to tenants', 'mietfux' ),
				esc_html__( 'Deductible expenses', 'mietfux' ),
				esc_html( mietv_eur( $teile['werbungskosten'] ) ),
				esc_html__( 'management, maintenance', 'mietfux' ),
				esc_html__( 'Maintenance reserve', 'mietfux' ),
				esc_html( mietv_eur( $teile['ruecklage'] ) ),
				esc_html__( 'not deductible', 'mietfux' )
			);
		}

		// Monatsübersicht
		$gebucht = self::buchungen( $objekt_id, $jahr );
		if ( $aktuell ) {
			echo '<form method="post" id="mv-hg-form">';
			wp_nonce_field( 'mietv_hausgeld', 'mietv_hausgeld_nonce' );
			printf( '<input type="hidden" name="objekt_id" value="%d"><input type="hidden" name="jahr" value="%d">', (int) $objekt_id, (int) $jahr );
			echo '<h2>' . esc_html( sprintf( /* translators: %d: Jahr */ __( 'Payments %d', 'mietfux' ), $jahr ) ) . '</h2>';
			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" data-check-all="tbody input[type=checkbox]"></th><th>' . esc_html__( 'Month', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th><th>' . esc_html__( 'Payment date', 'mietfux' ) . '</th><th>' . esc_html__( 'Status', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
			$heute  = current_time( 'Y-m-01' );
			$summe  = 0.0;
			$offene = 0;
			for ( $m = 1; $m <= 12; $m++ ) {
				$monat = sprintf( '%04d-%02d-01', $jahr, $m );
				$plan  = self::plan_fuer( $objekt_id, $monat );
				$b     = $gebucht[ $monat ] ?? null;
				if ( ! $plan && ! $b ) {
					continue;
				}
				$offen = ! $b && $monat <= $heute;
				if ( $offen ) {
					++$offene;
				}
				if ( $b ) {
					$summe += (float) $b->betrag;
				}
				printf(
					'<tr%s><td class="mv-op-check">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					$b ? '' : ' style="opacity:.75"',
					$b ? '✓' : ( $offen ? '<input type="checkbox" name="monat[]" value="' . esc_attr( $monat ) . '">' : '' ),
					esc_html( mietv_monat( $monat ) ),
					esc_html( mietv_eur( $b ? $b->betrag : $plan->betrag ) ),
					esc_html( $b ? mietv_date( $b->datum ) : '–' ),
					esc_html( $b ? __( 'booked', 'mietfux' ) : ( $offen ? __( 'open', 'mietfux' ) : __( 'future', 'mietfux' ) ) ),
					$b ? '<button name="loeschen" value="' . (int) $b->id . '" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete payment and its receipts?', 'mietfux' ) . '">×</button>' : ''
				);
			}
			if ( isset( $gebucht['abrechnung'] ) ) {
				$a = $gebucht['abrechnung'];
				printf(
					'<tr><td class="mv-op-check">✓</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( sprintf( /* translators: %d: Jahr */ __( 'Annual statement %d', 'mietfux' ), $jahr ) ),
					esc_html( mietv_eur( $a->betrag ) ),
					esc_html( mietv_date( $a->datum ) ),
					esc_html( (float) $a->betrag >= 0 ? __( 'additional payment', 'mietfux' ) : __( 'refund', 'mietfux' ) ),
					'<button name="loeschen" value="' . (int) $a->id . '" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete annual statement and its adjustment receipts?', 'mietfux' ) . '">×</button>'
				);
				$summe += (float) $a->betrag;
			}
			echo '</tbody><tfoot><tr><th></th><th>' . esc_html__( 'Total', 'mietfux' ) . '</th><th>' . esc_html( mietv_eur( $summe ) ) . '</th><th colspan="3"></th></tr></tfoot></table>';
			if ( $offene ) {
				printf(
					'<p><label>' . esc_html__( 'Payment day', 'mietfux' ) . ' <input type="number" name="tag" value="1" min="1" max="28" class="small-text"></label> <button class="button button-primary" data-require-checked="input[name=\'monat[]\']" data-require-msg="%s">%s</button></p>',
					esc_attr__( 'Please tick at least one month.', 'mietfux' ),
					esc_html__( 'Book selected months', 'mietfux' )
				);
			}
			echo '</form>';
		}

		// Plan
		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:420px;flex:1"><h3>' . esc_html__( 'Budget plan (monthly split)', 'mietfux' ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_hausgeld', 'mietv_hausgeld_nonce' );
		printf( '<input type="hidden" name="objekt_id" value="%d"><input type="hidden" name="jahr" value="%d"><input type="hidden" name="plan_id" value="%d">', (int) $objekt_id, (int) $jahr, (int) ( $aktuell->id ?? 0 ) );
		printf(
			'<p><label>%s<br><input type="text" name="verwalter" value="%s" class="regular-text"></label></p><p><label>%s<br><input type="text" name="iban" value="%s" class="regular-text"></label><br><span class="description">%s</span></p><p><label>%s <input type="text" name="betrag" value="%s" class="mv-num" inputmode="decimal" style="width:110px"></label> &nbsp; <label>%s <input type="month" name="gueltig_ab" value="%s"></label></p>',
			esc_html__( 'Property manager', 'mietfux' ),
			esc_attr( $aktuell->verwalter ?? '' ),
			esc_html__( 'Manager’s IBAN', 'mietfux' ),
			esc_attr( $aktuell->iban ?? '' ),
			esc_html__( 'Used by the bank import to recognise fee payments automatically.', 'mietfux' ),
			esc_html__( 'Monthly fee (€)', 'mietfux' ),
			esc_attr( $aktuell ? number_format( (float) $aktuell->betrag, 2, ',', '' ) : '' ),
			esc_html__( 'Valid from', 'mietfux' ),
			esc_attr( substr( $aktuell->gueltig_ab ?? current_time( 'Y-m-01' ), 0, 7 ) )
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Cost type', 'mietfux' ) . '</th><th>' . esc_html__( 'Treatment', 'mietfux' ) . '</th><th>' . esc_html__( 'Monthly (€)', 'mietfux' ) . '</th></tr></thead><tbody>';
		$vsum = 0.0;
		foreach ( $ka as $k ) {
			$wert  = $aktuell->verteilung[ (int) $k->id ] ?? 0;
			$vsum += (float) $wert;
			if ( $k->anlage_v === 'ruecklage' ) {
				$art = __( 'reserve – not deductible', 'mietfux' );
			} elseif ( (int) $k->umlagefaehig ) {
				$art = __( 'recoverable', 'mietfux' );
			} elseif ( $k->anlage_v === 'einnahmen' ) {
				continue;
			} else {
				$art = __( 'expenses (Anlage V)', 'mietfux' );
			}
			printf(
				'<tr><td>%s</td><td><small>%s</small></td><td><input type="text" name="vt[%d]" value="%s" class="mv-num" inputmode="decimal" style="width:110px"></td></tr>',
				esc_html( $k->name ),
				esc_html( $art ),
				(int) $k->id,
				esc_attr( $wert ? number_format( (float) $wert, 2, ',', '' ) : '' )
			);
		}
		echo '</tbody></table>';
		if ( $aktuell && abs( $vsum - (float) $aktuell->betrag ) > 0.005 ) {
			printf( '<p class="mv-neg">' . /* translators: %1$s: Betrag, %2$s: Betrag */ esc_html__( 'The split (%1$s) does not match the monthly fee (%2$s). Payments are split proportionally.', 'mietfux' ) . '</p>', esc_html( mietv_eur( $vsum ) ), esc_html( mietv_eur( $aktuell->betrag ) ) );
		}
		echo '<p><button name="plan_speichern" value="1" class="button button-primary">' . esc_html__( 'Save budget plan', 'mietfux' ) . '</button>';
		if ( $aktuell ) {
			echo ' <button name="plan_loeschen" value="' . (int) $aktuell->id . '" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete this budget plan? Booked payments remain.', 'mietfux' ) . '">' . esc_html__( 'Delete plan', 'mietfux' ) . '</button>';
		}
		echo '</p><p class="description">' . esc_html__( 'A changed fee from a new budget plan: enter the new amounts with “valid from” – the previous plan stays in effect for earlier months.', 'mietfux' ) . '</p>';
		if ( count( $plaene ) > 1 ) {
			echo '<p class="description">' . esc_html__( 'Earlier plans:', 'mietfux' ) . ' ';
			$teile = [];
			foreach ( $plaene as $p ) {
				$teile[] = esc_html( mietv_monat( $p->gueltig_ab ) . ' – ' . mietv_eur( $p->betrag ) );
			}
			echo implode( ' · ', $teile ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Teile sind oben einzeln escaped.
		}
		echo '</form></div>';

		// Jahresabrechnung
		if ( $aktuell ) {
			echo '<div class="mv-kachel" style="min-width:420px;flex:1"><h3>' . esc_html( sprintf( /* translators: %d: Jahr */ __( 'Owners’ association annual statement %d', 'mietfux' ), $jahr ) ) . '</h3>';
			if ( isset( $gebucht['abrechnung'] ) ) {
				printf( '<p>%s <strong>%s</strong> (%s)</p>', esc_html__( 'Booked:', 'mietfux' ), esc_html( mietv_eur( $gebucht['abrechnung']->betrag ) ), esc_html( mietv_date( $gebucht['abrechnung']->datum ) ) );
			} else {
				echo '<form method="post">';
				wp_nonce_field( 'mietv_hausgeld', 'mietv_hausgeld_nonce' );
				printf( '<input type="hidden" name="objekt_id" value="%d"><input type="hidden" name="jahr" value="%d">', (int) $objekt_id, (int) $jahr );
				echo '<p class="description">' . esc_html__( 'Enter the actual annual amounts per cost type from the individual statement. The difference to the advance payments is booked as an adjustment receipt (additional payment or refund) with the payment date, allocated to the statement year.', 'mietfux' ) . '</p>';
				$vorz = self::gebucht_je_kostenart( $objekt_id, $jahr );
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Cost type', 'mietfux' ) . '</th><th>' . esc_html__( 'Advance payments', 'mietfux' ) . '</th><th>' . esc_html__( 'Per statement (€)', 'mietfux' ) . '</th></tr></thead><tbody>';
				foreach ( $ka as $k ) {
					if ( $k->anlage_v === 'einnahmen' ) {
						continue;
					}
					$v = $vorz[ (int) $k->id ] ?? 0.0;
					if ( $v < 0.005 && empty( $aktuell->verteilung[ (int) $k->id ] ) ) {
						continue;
					}
					printf( '<tr><td>%s</td><td>%s</td><td><input type="text" name="ist[%d]" value="" placeholder="%s" class="mv-num" inputmode="decimal" style="width:110px"></td></tr>', esc_html( $k->name ), esc_html( mietv_eur( $v ) ), (int) $k->id, esc_attr( number_format( $v, 2, ',', '' ) ) );
				}
				echo '</tbody></table>';
				printf( '<p><label>%s <input type="date" name="abr_datum" value="%s"></label> <button name="abrechnung_buchen" value="1" class="button button-primary" data-confirm="%s">%s</button></p>', esc_html__( 'Payment date', 'mietfux' ), esc_attr( current_time( 'Y-m-d' ) ), esc_attr__( 'Book the annual statement? Adjustment receipts are created per cost type.', 'mietfux' ), esc_html__( 'Book annual statement', 'mietfux' ) );
				echo '<p class="description">' . esc_html__( 'Empty fields keep the advance payments unchanged. Amounts spent from the maintenance reserve (e.g. repairs) belong to “Maintenance expenses” – they become deductible when spent.', 'mietfux' ) . '</p></form>';
			}
			echo '</div>';
		}
		echo '</div></div>';
	}
}
