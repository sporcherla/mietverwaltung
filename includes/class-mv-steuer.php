<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Steuer-Auswertung je Kalenderjahr (Anlage V, Zufluss-/Abflussprinzip § 11 EStG).
 */
class Mietv_Steuer {

	public static function init(): void {
		add_action( 'admin_post_mv_steuer_csv', [ __CLASS__, 'csv' ] );
		add_action( 'admin_init', [ __CLASS__, 'zeilen_speichern' ] );
	}

	/** Zeilennummern der Anlage V je Steuerjahr (vom Nutzer gepflegt, da jährlich wechselnd). */
	public static function zeilen( int $jahr ): array {
		$alle = get_option( 'mietv_anlagev_zeilen', [] );
		if ( isset( $alle[ $jahr ] ) ) {
			return $alle[ $jahr ];
		}
		// Vorjahr übernehmen, falls vorhanden
		return $alle[ $jahr - 1 ] ?? [];
	}

	public static function zeilen_speichern(): void {
		if ( empty( $_POST['mietv_zeilen_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_zeilen_nonce'] ?? '' ) ), 'mietv_zeilen' ) ) {
			return;
		}
		$jahr          = ( isset( $_POST['jahr'] ) ? (int) $_POST['jahr'] : 0 );
		$alle          = get_option( 'mietv_anlagev_zeilen', [] );
		$alle[ $jahr ] = [];
		foreach ( map_deep( wp_unslash( (array) ( $_POST['zeile'] ?? [] ) ), 'sanitize_text_field' ) as $g => $z ) {
			$alle[ $jahr ][ sanitize_key( $g ) ] = sanitize_text_field( $z );
		}
		update_option( 'mietv_anlagev_zeilen', $alle );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_steuer&jahr=' . $jahr . '&msg=zeilen' ) );
		exit;
	}

	/** Entwicklung über die letzten Jahre als Inline-SVG (Einnahmen, Werbungskosten, Ergebnis). */
	private static function chart( int $jahr ): string {
		$jahre = [];
		for ( $j = $jahr - 4; $j <= $jahr; $j++ ) {
			$ein         = self::einnahmen( $j );
			$e           = array_sum( array_column( $ein, 'miete' ) ) + array_sum( array_column( $ein, 'umlagen' ) );
			$w           = array_sum( array_column( self::werbungskosten( $j ), 'summe' ) );
			$jahre[ $j ] = [ $e, $w, $e - $w ];
		}
		$werte = array_merge( ...array_values( $jahre ) );
		$max   = max( 1, max( array_map( 'abs', $werte ) ) );
		if ( array_sum( array_map( 'abs', $werte ) ) < 0.005 ) {
			return '';
		}
		$breite = 720;
		$hoehe  = 220;
		$pad    = 40;
		$base   = $hoehe - 30;
		$scale  = ( $base - 20 ) / $max;
		$gw     = ( $breite - 2 * $pad ) / count( $jahre );
		$svg    = '<svg viewBox="0 0 ' . $breite . ' ' . $hoehe . '" width="100%" style="max-width:' . $breite . 'px;font-family:sans-serif;font-size:11px">';
		$svg   .= '<line x1="' . $pad . '" y1="' . $base . '" x2="' . ( $breite - $pad ) . '" y2="' . $base . '" stroke="#999"/>';
		$farben = [ '#2271b1', '#b32d2e', '#1a7f37' ];
		$labels = [ 'Einnahmen', 'Werbungskosten', 'Ergebnis' ];
		$i      = 0;
		foreach ( $jahre as $j => [ $e, $w, $erg ] ) {
			$x0 = $pad + $i * $gw + 8;
			$bw = ( $gw - 16 ) / 3;
			foreach ( [ $e, $w, $erg ] as $k => $v ) {
				$h    = abs( $v ) * $scale;
				$y    = $v >= 0 ? $base - $h : $base;
				$svg .= sprintf( '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" opacity="%s"><title>%s %d: %s</title></rect>', $x0 + $k * $bw, $y, $bw - 2, $h, $farben[ $k ], $j === $jahr ? '1' : '0.55', $labels[ $k ], $j, mietv_eur( $v ) );
			}
			$svg .= sprintf( '<text x="%.1f" y="%d" text-anchor="middle">%d</text>', $x0 + 1.5 * $bw, $hoehe - 10, $j );
			$svg .= sprintf( '<text x="%.1f" y="%.1f" text-anchor="middle" font-size="10" fill="#333">%s</text>', $x0 + 2.5 * $bw, ( $erg >= 0 ? $base - abs( $erg ) * $scale - 4 : $base + abs( $erg ) * $scale + 12 ), number_format( $erg, 0, ',', '.' ) );
			++$i;
		}
		foreach ( $labels as $k => $l ) {
			$svg .= sprintf( '<rect x="%d" y="6" width="10" height="10" fill="%s"/><text x="%d" y="15">%s</text>', $pad + $k * 130, $farben[ $k ], $pad + 14 + $k * 130, $l );
		}
		return $svg . '</svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Berechnung                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Einnahmen des Jahres je Mietverhältnis.
	 * Zahlungen werden anhand der Konditionen des zugeordneten Monats
	 * in Miete (Kalt) und vereinnahmte Umlagen (BK+HK) aufgeteilt.
	 */
	public static function einnahmen( int $jahr ): array {
		global $wpdb;
		$out       = [];
		$zahlungen = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'mv_zahlungen WHERE YEAR(datum) = %d ORDER BY datum',
				$jahr
			)
		);
		$mvs       = [];
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			$mvs[ $mv->id ] = $mv;
		}
		foreach ( $zahlungen as $z ) {
			$mv = $mvs[ $z->mv_id ] ?? null;
			if ( ! $mv ) {
				continue;
			}
			$k          = Mietv_Soll::konditionen( (int) $z->mv_id, $z->monat );
			$kalt       = $k ? (float) $k->kaltmiete : 0.0;
			$uml        = $k ? (float) $k->bk_vz + (float) $k->hk_vz : 0.0;
			$sum        = $kalt + $uml;
			$anteil_uml = $sum > 0 ? $uml / $sum : 0.0;

			if ( ! isset( $out[ $z->mv_id ] ) ) {
				$out[ $z->mv_id ] = [
					'label'   => Mietv_Soll::mietv_label( $mv ),
					'objekt'  => $mv->objekt,
					'miete'   => 0.0,
					'umlagen' => 0.0,
					'gesamt'  => 0.0,
				];
			}
			$u                            = (float) $z->betrag * $anteil_uml;
			$out[ $z->mv_id ]['umlagen'] += $u;
			$out[ $z->mv_id ]['miete']   += (float) $z->betrag - $u;
			$out[ $z->mv_id ]['gesamt']  += (float) $z->betrag;
		}
		return $out;
	}

	/** Belege, die im Jahr wirksam sind: direkt (steuerjahr) oder als Verteilungsanteil (§ 82b EStDV). */
	public static function belege( int $jahr ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, k.name AS kostenart, k.anlage_v, k.umlagefaehig, o.bezeichnung AS objekt
			 FROM ' . $wpdb->prefix . 'mv_belege b
			 JOIN ' . $wpdb->prefix . 'mv_kostenarten k ON k.id = b.kostenart_id
			 JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = b.objekt_id
			 WHERE b.steuerjahr <= %d AND b.steuerjahr + b.verteilung_jahre > %d
			 ORDER BY b.datum, b.id',
				$jahr,
				$jahr
			)
		);
		foreach ( $rows as $r ) {
			$n            = max( 1, (int) $r->verteilung_jahre );
			$r->anteil    = round( (float) $r->betrag / $n, 2 );
			$r->anteil_nr = $jahr - (int) $r->steuerjahr + 1;
			$r->verteilt  = $n > 1;
		}
		return $rows;
	}

	/** Werbungskosten gruppiert nach Anlage-V-Gruppe (Belege + AfA + Darlehenszinsen). */
	public static function werbungskosten( int $jahr ): array {
		$gruppen = [];
		foreach ( Mietv_DB::anlage_v_gruppen() as $g => $label ) {
			if ( $g === 'einnahmen' ) {
				continue;
			}
			$gruppen[ $g ] = [
				'label'        => $label,
				'summe'        => 0.0,
				'umlagefaehig' => 0.0,
				'arten'        => [],
			];
		}

		// Belege je Kostenart
		$arten = [];
		foreach ( self::belege( $jahr ) as $b ) {
			$g   = isset( $gruppen[ $b->anlage_v ] ) ? $b->anlage_v : 'sonstiges';
			$key = $g . '|' . $b->kostenart_id;
			if ( ! isset( $arten[ $key ] ) ) {
				$arten[ $key ] = (object) [
					'gruppe'       => $g,
					'name'         => $b->kostenart,
					'anzahl'       => 0,
					'summe'        => 0.0,
					'umlagefaehig' => (int) $b->umlagefaehig,
					'quelle'       => 'Belege',
				];
			}
			++$arten[ $key ]->anzahl;
			$arten[ $key ]->summe += $b->anteil;
		}

		// AfA aus Anlagegütern
		foreach ( Mietv_Afa::uebersicht( $jahr ) as $r ) {
			if ( $r['afa'] <= 0 ) {
				continue;
			}
			$arten[ 'afa|a' . $r['anlagegut']->id ] = (object) [
				'gruppe'       => 'afa',
				'name'         => esc_html__( 'AfA', 'mietverwaltung' ) . ' ' . $r['anlagegut']->bezeichnung,
				'anzahl'       => 1,
				'summe'        => $r['afa'],
				'umlagefaehig' => 0,
				'quelle'       => 'Anlagegut',
			];
		}

		// Zinsen aus Darlehensjahren
		foreach ( self::darlehen_jahr( $jahr ) as $d ) {
			if ( (float) $d->zinsen <= 0 ) {
				continue;
			}
			$arten[ 'schuldzinsen|d' . $d->darlehen_id ] = (object) [
				'gruppe'       => 'schuldzinsen',
				'name'         => esc_html__( 'Zinsen', 'mietverwaltung' ) . ' ' . $d->bank . ( $d->bezeichnung ? ' – ' . $d->bezeichnung : '' ),
				'anzahl'       => 1,
				'summe'        => (float) $d->zinsen,
				'umlagefaehig' => 0,
				'quelle'       => 'Darlehen',
			];
		}

		foreach ( $arten as $a ) {
			$gruppen[ $a->gruppe ]['arten'][] = $a;
			$gruppen[ $a->gruppe ]['summe']  += $a->summe;
			if ( $a->umlagefaehig ) {
				$gruppen[ $a->gruppe ]['umlagefaehig'] += $a->summe;
			}
		}
		return $gruppen;
	}

	/** Zinsbescheinigung der Bank; fehlt sie, berechnete Jahreswerte aus den gebuchten Raten. */
	public static function darlehen_jahr( int $jahr ): array {
		global $wpdb;
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.*, d.bank, d.bezeichnung, d.betrag, d.zinssatz, d.zinsbindung_bis, 'bescheinigung' AS quelle
			 FROM " . $wpdb->prefix . 'mv_darlehen_jahre j
			 JOIN ' . $wpdb->prefix . 'mv_darlehen d ON d.id = j.darlehen_id
			 WHERE j.jahr = %d ORDER BY d.bank',
				$jahr
			)
		);
		$vorhanden = array_map( fn( $r ) => (int) $r->darlehen_id, $rows );
		if ( class_exists( 'Mietv_Darlehen' ) ) {
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_darlehen' ) as $d ) {
				if ( in_array( (int) $d->id, $vorhanden, true ) ) {
					continue;
				}
				$jw = Mietv_Darlehen::jahreswerte( (int) $d->id, $jahr );
				if ( $jw ) {
					$rows[] = (object) [
						'id'              => 0,
						'darlehen_id'     => $d->id,
						'jahr'            => $jahr,
						'zinsen'          => $jw['zinsen'],
						'tilgung'         => $jw['tilgung'],
						'restschuld'      => $jw['restschuld'],
						'attachment_id'   => null,
						'bank'            => $d->bank,
						'bezeichnung'     => $d->bezeichnung,
						'betrag'          => $d->betrag,
						'zinssatz'        => $d->zinssatz,
						'zinsbindung_bis' => $d->zinsbindung_bis,
						'quelle'          => 'berechnet',
					];
				}
			}
		}
		return $rows;
	}

	private static function jahre(): array {
		global $wpdb;
		$a = $wpdb->get_col( 'SELECT DISTINCT steuerjahr FROM ' . $wpdb->prefix . 'mv_belege' );
		$b = $wpdb->get_col( 'SELECT DISTINCT YEAR(datum) FROM ' . $wpdb->prefix . 'mv_zahlungen' );
		$c = $wpdb->get_col( 'SELECT DISTINCT jahr FROM ' . $wpdb->prefix . 'mv_darlehen_jahre' );
		$j = array_unique( array_map( 'intval', array_merge( $a, $b, $c, [ (int) current_time( 'Y' ) ] ) ) );
		rsort( $j );
		return $j;
	}

	private static function jahr_aus_request(): int {
		$jahre = self::jahre();
		$j     = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		return $j > 1990 ? $j : (int) $jahre[0];
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		$jahr = self::jahr_aus_request();
		$ein  = self::einnahmen( $jahr );
		$wk   = self::werbungskosten( $jahr );

		$ein_miete = array_sum( array_column( $ein, 'miete' ) );
		$ein_uml   = array_sum( array_column( $ein, 'umlagen' ) );
		$ein_sum   = $ein_miete + $ein_uml;
		$wk_sum    = array_sum( array_column( $wk, 'summe' ) );
		$ergebnis  = $ein_sum - $wk_sum;

		$csv = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_steuer_csv&jahr=' . $jahr ), 'mietv_steuer_csv_' . $jahr );

		echo '<div class="wrap mv-wrap"><h1>Steuer – Anlage V ' . (int) $jahr . '</h1>';
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_steuer"><label>' . esc_html__( 'Steuerjahr:', 'mietverwaltung' ) . ' <select name="jahr" onchange="this.form.submit()">';
		foreach ( self::jahre() as $j ) {
			printf( '<option value="%1$d"%2$s>%1$d</option>', (int) $j, selected( (int) $jahr, (int) $j, false ) );
		}
		echo '</select></label> <a href="' . esc_url( $csv ) . '" class="button">CSV exportieren</a> <button type="button" class="button" onclick="window.print()">Drucken</button></form>';

		if ( ( isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '' ) === 'zeilen' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zeilennummern gespeichert.', 'mietverwaltung' ) . '</p></div>';
		}
		$chart = self::chart( $jahr );
		if ( $chart ) {
			echo '<h2>' . esc_html__( 'Entwicklung', 'mietverwaltung' ) . '</h2>' . $chart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		}
		echo '<p class="description">' . esc_html__( 'Zufluss-/Abflussprinzip (§ 11 EStG): Einnahmen nach Zahlungseingang, Werbungskosten nach Zahlungsdatum des Belegs. Mieteingänge werden anteilig nach den Konditionen des jeweiligen Monats in Miete und Umlagen getrennt. AfA und Darlehenszinsen fließen automatisch aus „Abschreibung“ und „Zinsbescheinigungen“ ein. Zeilennummern der Anlage V ändern sich jährlich – bitte das aktuelle Formular beachten.', 'mietverwaltung' ) . '</p>';

		// Hinweise
		self::hinweise();

		// Vorjahr
		$vj         = $jahr - 1;
		$ein_vj     = self::einnahmen( $vj );
		$wk_vj      = self::werbungskosten( $vj );
		$ein_vj_sum = array_sum( array_column( $ein_vj, 'miete' ) ) + array_sum( array_column( $ein_vj, 'umlagen' ) );
		$wk_vj_sum  = array_sum( array_column( $wk_vj, 'summe' ) );
		$delta      = function ( $neu, $alt ) {
			if ( abs( $alt ) < 0.005 ) {
				return '';
			}
			$p = ( $neu / $alt - 1 ) * 100;
			return sprintf( '<small>' . /* translators: %1$s: Wert, %2$+.1f: Wert */ esc_html__( 'Vorjahr %1$s (%2$+.1f %%)', 'mietverwaltung' ) . '</small>', mietv_eur( $alt ), $p );
		};

		// Ergebnis
		$cls = $ergebnis < 0 ? 'mv-neg' : 'mv-pos';
		printf(
			'<div class="mv-kacheln"><div class="mv-kachel"><span>' . esc_html__( 'Einnahmen gesamt', 'mietverwaltung' ) . '</span><strong>%s</strong>%s</div><div class="mv-kachel"><span>' . esc_html__( 'Werbungskosten gesamt', 'mietverwaltung' ) . '</span><strong>%s</strong>%s</div><div class="mv-kachel"><span>' . esc_html__( 'Überschuss / Verlust', 'mietverwaltung' ) . '</span><strong class="%s">%s</strong>%s</div></div>',
			esc_html( mietv_eur( $ein_sum ) ),
			$delta( $ein_sum, $ein_vj_sum ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
			esc_html( mietv_eur( $wk_sum ) ),
			$delta( $wk_sum, $wk_vj_sum ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
			esc_attr( $cls ),
			esc_html( mietv_eur( $ergebnis ) ),
			$delta( $ergebnis, $ein_vj_sum - $wk_vj_sum ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		);

		// Einnahmen
		echo '<h2>' . esc_html__( 'Einnahmen', 'mietverwaltung' ) . '</h2><table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Mietverhältnis', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Mieteinnahmen (ohne Umlagen)', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Vereinnahmte Umlagen', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Gesamt', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
		if ( ! $ein ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Keine Zahlungseingänge in diesem Jahr.', 'mietverwaltung' ) . '</td></tr>';
		}
		foreach ( $ein as $e ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $e['label'] ), esc_html( mietv_eur( $e['miete'] ) ), esc_html( mietv_eur( $e['umlagen'] ) ), esc_html( mietv_eur( $e['gesamt'] ) ) );
		}
		printf( '</tbody><tfoot><tr><th>' . esc_html__( 'Summe', 'mietverwaltung' ) . '</th><th>%s</th><th>%s</th><th>%s</th></tr></tfoot></table>', esc_html( mietv_eur( $ein_miete ) ), esc_html( mietv_eur( $ein_uml ) ), esc_html( mietv_eur( $ein_sum ) ) );

		// Werbungskosten
		$zeilen = self::zeilen( $jahr );
		echo '<h2>' . esc_html__( 'Werbungskosten', 'mietverwaltung' ) . '</h2><table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Anlage-V-Gruppe / Position', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Zeile Anlage V', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Quelle', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Belege', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'davon umlagefähig', 'mietverwaltung' ) . '</th><th>Vorjahr ' . (int) $vj . '</th><th>Betrag ' . (int) $jahr . '</th></tr></thead><tbody>';
		foreach ( $wk as $gk => $g ) {
			if ( ! $g['arten'] && empty( $wk_vj[ $gk ]['arten'] ) ) {
				continue;
			}
			printf( '<tr class="mv-gruppe"><td><strong>%s</strong></td><td><strong>%s</strong></td><td></td><td></td><td>%s</td><td>%s</td><td><strong>%s</strong></td></tr>', esc_html( $g['label'] ), esc_html( $zeilen[ $gk ] ?? '–' ), esc_html( mietv_eur( $g['umlagefaehig'] ) ), esc_html( mietv_eur( $wk_vj[ $gk ]['summe'] ?? 0 ) ), esc_html( mietv_eur( $g['summe'] ) ) );
			$vj_arten = [];
			foreach ( $wk_vj[ $gk ]['arten'] ?? [] as $a ) {
				$vj_arten[ $a->name ] = $a->summe;
			}
			foreach ( $g['arten'] as $a ) {
				printf( '<tr><td class="mv-indent">%s</td><td></td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $a->name ), esc_html( $a->quelle ), (int) $a->anzahl, $a->umlagefaehig ? 'ja' : '–', esc_html( isset( $vj_arten[ $a->name ] ) ? mietv_eur( $vj_arten[ $a->name ] ) : '–' ), esc_html( mietv_eur( $a->summe ) ) );
			}
		}
		if ( abs( $wk_sum ) < 0.005 ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Keine Werbungskosten in diesem Jahr.', 'mietverwaltung' ) . '</td></tr>';
		}
		printf( '</tbody><tfoot><tr><th>' . esc_html__( 'Summe Werbungskosten', 'mietverwaltung' ) . '</th><th>%s</th><th></th><th></th><th></th><th>%s</th><th>%s</th></tr></tfoot></table>', esc_html( $zeilen['summe_wk'] ?? '' ), esc_html( mietv_eur( $wk_vj_sum ) ), esc_html( mietv_eur( $wk_sum ) ) );

		// Zeilen-Zuordnung pflegen
		echo '<details class="mv-details"><summary>Zeilennummern der Anlage V ' . (int) $jahr . ' pflegen</summary><form method="post" class="mv-form">';
		wp_nonce_field( 'mietv_zeilen', 'mietv_zeilen_nonce' );
		printf( '<input type="hidden" name="jahr" value="%d"><table class="form-table"><tbody>', (int) $jahr );
		$felder = [
			'einnahmen_miete'   => esc_html__( 'Mieteinnahmen (ohne Umlagen)', 'mietverwaltung' ),
			'einnahmen_umlagen' => esc_html__( 'Vereinnahmte Umlagen', 'mietverwaltung' ),
		] + array_filter( Mietv_DB::anlage_v_gruppen(), fn( $k ) => $k !== 'einnahmen', ARRAY_FILTER_USE_KEY ) + [
			'summe_wk' => 'Summe Werbungskosten',
			'ergebnis' => 'Überschuss / Verlust',
		];
		foreach ( $felder as $g => $label ) {
			printf( '<tr><th>%s</th><td><input type="text" name="zeile[%s]" value="%s" class="small-text" placeholder="Zeile"></td></tr>', esc_html( $label ), esc_attr( $g ), esc_attr( $zeilen[ $g ] ?? '' ) );
		}
		echo '</tbody></table>';
		submit_button( 'Zeilen speichern', 'secondary' );
		echo '</form><p class="description">' . esc_html__( 'Die Zeilennummern stehen auf dem amtlichen Formular „Anlage V“ des jeweiligen Jahres (ELSTER). Einmal eingetragen, werden sie für Folgejahre vorbelegt, bis sich das Formular ändert.', 'mietverwaltung' ) . '</p></details>';

		// AfA
		$afa = Mietv_Afa::uebersicht( $jahr );
		echo '<h2>' . esc_html__( 'Abschreibung (AfA)', 'mietverwaltung' ) . '</h2>';
		if ( $afa ) {
			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Anlagegut', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Anschaffung', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Bemessungsgrundlage', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Methode / Satz', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Monate', 'mietverwaltung' ) . '</th><th>AfA ' . (int) $jahr . '</th><th>Restbuchwert 31.12.</th></tr></thead><tbody>';
			foreach ( $afa as $r ) {
				$a  = $r['anlagegut'];
				$nk = array_filter(
					[
						__( 'Grunderwerbsteuer', 'mietverwaltung' ) => (float) ( $a->nk_grunderwerbsteuer ?? 0 ),
						__( 'Notar', 'mietverwaltung' )    => (float) ( $a->nk_notar ?? 0 ),
						__( 'Grundbuch', 'mietverwaltung' ) => (float) ( $a->nk_grundbuch ?? 0 ),
						__( 'Makler', 'mietverwaltung' )   => (float) ( $a->nk_makler ?? 0 ),
						__( 'Sonstige', 'mietverwaltung' ) => (float) ( $a->nk_sonstige ?? 0 ),
					]
				);
				if ( $nk ) {
					$teile = implode( ' · ', array_map( fn( $l, $v ) => $l . ' ' . mietv_eur( $v ), array_keys( $nk ), $nk ) );
					printf( '<tr><td colspan="7" class="mv-indent"><small>%s: %s %s = %s %s %s</small></td></tr>', esc_html( $a->bezeichnung ), esc_html__( 'Kaufpreis', 'mietverwaltung' ), esc_html( mietv_eur( $a->ak ) ), esc_html( $teile ), esc_html__( '→ Anschaffungskosten', 'mietverwaltung' ), esc_html( mietv_eur( (float) $a->ak + (float) $a->nebenkosten ) ) );
				}
				if ( $a->typ === 'gebaeude' && (float) $a->grundanteil <= 0 ) {
					echo '<tr><td colspan="7" class="mv-warn">⚠ ' . esc_html( sprintf( /* translators: %s: Wert */ __( '%s: Anteil Grund und Boden ist 0 %% – das Finanzamt verlangt eine Aufteilung (Kaufvertrag oder BMF-Arbeitshilfe).', 'mietverwaltung' ), $a->bezeichnung ) ) . '</td></tr>';
				}
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s %s %%</td><td>%d</td><td><strong>%s</strong></td><td>%s</td></tr>',
					esc_html( $a->bezeichnung ),
					esc_html( mietv_date( $a->anschaffung ) ),
					esc_html( mietv_eur( $r['basis'] ) ),
					$a->methode === 'degressiv' ? esc_html__( 'degressiv', 'mietverwaltung' ) : esc_html__( 'linear', 'mietverwaltung' ),
					esc_html( number_format( $r['satz'], 2, ',', '.' ) ),
					(int) $r['monate'],
					esc_html( mietv_eur( $r['afa'] ) ),
					esc_html( mietv_eur( $r['restwert'] ) )
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Keine Anlagegüter erfasst. Gebäude und Anschaffungsnebenkosten unter', 'mietverwaltung' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=mietv_anlagegueter' ) ) . '">Abschreibung (AfA)</a> anlegen.</p>';
		}

		// Finanzierung
		$dj = self::darlehen_jahr( $jahr );
		echo '<h2>' . esc_html__( 'Finanzierung', 'mietverwaltung' ) . '</h2>';
		if ( $dj ) {
			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Darlehen', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Sollzins', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Zinsbindung bis', 'mietverwaltung' ) . '</th><th>Zinsen ' . (int) $jahr . ' (Werbungskosten)</th><th>Tilgung</th><th>Restschuld 31.12.</th><th>Bescheinigung</th></tr></thead><tbody>';
			foreach ( $dj as $d ) {
				$url = $d->attachment_id ? wp_get_attachment_url( (int) $d->attachment_id ) : '';
				printf(
					'<tr><td>%s</td><td>%s %%</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $d->bank . ( $d->bezeichnung ? ' – ' . $d->bezeichnung : '' ) ),
					esc_html( number_format( (float) $d->zinssatz, 2, ',', '.' ) ),
					esc_html( mietv_date( $d->zinsbindung_bis ) ),
					esc_html( mietv_eur( $d->zinsen ) ),
					esc_html( mietv_eur( $d->tilgung ) ),
					esc_html( mietv_eur( $d->restschuld ) ),
					$url ? '<a href="' . esc_url( $url ) . '" target="_blank">öffnen</a>' : '–'
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Keine Zinsbescheinigung für ' . (int) $jahr . ' erfasst. Jahreswerte unter <a href="' . esc_url( admin_url( 'admin.php?page=mietv_darlehen_jahre' ) ) . '">Zinsbescheinigungen</a> eintragen.</p>';
		}

		// Belegliste
		$belege = self::belege( $jahr );
		echo '<h2>Belegliste ' . (int) $jahr . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>Datum</th><th>Kostenart</th><th>Rechnungssteller</th><th>Beleg-Nr.</th><th>Betrag</th><th>Anteil ' . (int) $jahr . '</th><th>Datei</th></tr></thead><tbody>';
		foreach ( $belege as $b ) {
			$url = $b->attachment_id ? wp_get_attachment_url( (int) $b->attachment_id ) : '';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td></tr>',
				esc_html( mietv_date( $b->datum ) ),
				esc_html( $b->kostenart ),
				esc_html( $b->lieferant ),
				esc_html( $b->belegnr ),
				esc_html( mietv_eur( $b->betrag ) ),
				esc_html( mietv_eur( $b->anteil ) ),
				$b->verteilt ? ' <small>(' . (int) $b->anteil_nr . '/' . (int) $b->verteilung_jahre . ')</small>' : '',
				$url ? '<a href="' . esc_url( $url ) . '" target="_blank">öffnen</a>' : '–'
			);
		}
		if ( ! $belege ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Keine Belege.', 'mietverwaltung' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function hinweise(): void {
		foreach ( Mietv_Afa::pruefung_15_prozent() as $p ) {
			if ( $p['netto'] <= 0 ) {
				continue;
			}
			$a   = $p['anlagegut'];
			$txt = sprintf(
				/* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert, %4$s: Wert, %5$s: Wert, %6$s: Wert */                __( 'Anschaffungsnahe Herstellungskosten (§ 6 Abs. 1 Nr. 1a EStG) – %1$s: Erhaltungsaufwand %2$s bis %3$s bisher %4$s netto, Grenze 15 %% = %5$s (%6$s %%).', 'mietverwaltung' ),
				$a->bezeichnung,
				mietv_date( $p['von'] ),
				mietv_date( $p['bis'] ),
				mietv_eur( $p['netto'] ),
				mietv_eur( $p['grenze'] ),
				number_format( $p['quote'], 1, ',', '.' )
			);
			if ( $p['netto'] > $p['grenze'] ) {
				printf( '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Grenze überschritten!', 'mietverwaltung' ) . '</strong> ' . /* translators: %s: Wert */ esc_html__( '%s Die Aufwendungen gelten als Herstellungskosten und sind nur über die AfA abziehbar – bitte mit dem Steuerberater klären.', 'mietverwaltung' ) . '</p></div>', esc_html( $txt ) );
			} elseif ( $p['aktiv'] && $p['quote'] >= 10 ) {
				printf( '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Achtung:', 'mietverwaltung' ) . '</strong> ' . /* translators: %s: Wert */ esc_html__( '%s Weitere Renovierungen in der Frist könnten die Grenze reißen.', 'mietverwaltung' ) . '</p></div>', esc_html( $txt ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* CSV                                                                */
	/* ------------------------------------------------------------------ */

	public static function csv(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		$jahr = ( isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		check_admin_referer( 'mietv_steuer_csv_' . $jahr );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="anlage-v-' . $jahr . '.csv"' );
		echo "\xEF\xBB\xBF"; // BOM für Excel
		$out = fopen( 'php://output', 'w' );
		$sep = ';';
		$num = fn( $v ) => number_format( (float) $v, 2, ',', '' );

		$zeilen = self::zeilen( $jahr );
		fputcsv( $out, [ 'Bereich', 'Gruppe', 'Zeile Anlage V', 'Position', 'Quelle', 'Datum', 'Rechnungssteller', 'Beleg-Nr.', 'Umlagefähig', 'Betrag' ], $sep, '\"', '\\' );

		foreach ( self::einnahmen( $jahr ) as $e ) {
			fputcsv( $out, [ 'Einnahmen', 'Mieteinnahmen', $zeilen['einnahmen_miete'] ?? '', $e['label'], 'Zahlungen', '', '', '', '', $num( $e['miete'] ) ], $sep, '\"', '\\' );
			fputcsv( $out, [ 'Einnahmen', 'Umlagen', $zeilen['einnahmen_umlagen'] ?? '', $e['label'], 'Zahlungen', '', '', '', '', $num( $e['umlagen'] ) ], $sep, '\"', '\\' );
		}
		foreach ( self::werbungskosten( $jahr ) as $gk => $g ) {
			foreach ( $g['arten'] as $a ) {
				fputcsv( $out, [ 'Werbungskosten (Summe)', $g['label'], $zeilen[ $gk ] ?? '', $a->name, $a->quelle, '', '', '', $a->umlagefaehig ? 'ja' : 'nein', $num( $a->summe ) ], $sep, '\"', '\\' );
			}
		}
		foreach ( Mietv_Afa::uebersicht( $jahr ) as $r ) {
			fputcsv( $out, [ 'AfA', 'Absetzung für Abnutzung', $zeilen['afa'] ?? '', $r['anlagegut']->bezeichnung, 'Anlagegut', mietv_date( $r['anlagegut']->anschaffung ), '', 'Restwert ' . $num( $r['restwert'] ), 'nein', $num( $r['afa'] ) ], $sep, '\"', '\\' );
		}
		foreach ( self::darlehen_jahr( $jahr ) as $d ) {
			fputcsv( $out, [ 'Finanzierung', 'Schuldzinsen', $zeilen['schuldzinsen'] ?? '', $d->bank . ( $d->bezeichnung ? ' – ' . $d->bezeichnung : '' ), 'Darlehen', '', $d->bank, 'Restschuld ' . $num( $d->restschuld ), 'nein', $num( $d->zinsen ) ], $sep, '\"', '\\' );
		}
		foreach ( self::belege( $jahr ) as $b ) {
			fputcsv( $out, [ 'Beleg', Mietv_DB::anlage_v_gruppen()[ $b->anlage_v ] ?? $b->anlage_v, $zeilen[ $b->anlage_v ] ?? '', $b->kostenart . ( $b->verteilt ? ' (Anteil ' . $b->anteil_nr . '/' . $b->verteilung_jahre . ')' : '' ), 'Belege', mietv_date( $b->datum ), $b->lieferant, $b->belegnr, $b->umlagefaehig ? 'ja' : 'nein', $num( $b->anteil ) ], $sep, '\"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output
		exit;
	}
}
