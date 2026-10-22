<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Betriebskostenabrechnung (§ 556 BGB, BetrKV, HeizkostenV).
 *
 * Verteilung umlagefähiger Belege eines Objekts auf die Mietverhältnisse des Zeitraums:
 *  - Belege zeitanteilig nach Leistungszeitraum (Tage innerhalb des Abrechnungszeitraums)
 *  - Schlüssel: Wohnfläche, Personen(-tage), je Einheit, Verbrauch (Zähler)
 *  - Heizkosten bei Zentralheizung: Verbrauchsanteil (50–70 %) nach Zähler, Rest nach Fläche (§ 7 HeizkostenV)
 *  - Leerstand trägt der Vermieter (Flächen-/Einheitenschlüssel rechnen mit allen Einheiten)
 */
class Mietv_Abrechnung {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mv_abrechnung_brief', [ __CLASS__, 'brief' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Hilfen                                                             */
	/* ------------------------------------------------------------------ */

	private static function tage( string $von, string $bis ): int {
		$a = new DateTime( $von );
		$b = new DateTime( $bis );
		return $b < $a ? 0 : $a->diff( $b )->days + 1;
	}

	/** Überlappung zweier Zeiträume in Tagen. */
	private static function overlap( string $a_von, string $a_bis, string $b_von, string $b_bis ): int {
		$von = max( $a_von, $b_von );
		$bis = min( $a_bis, $b_bis );
		return $bis < $von ? 0 : self::tage( $von, $bis );
	}

	/** Zählertyp(en) je Kostenart anhand des Namens. */
	private static function zaehler_typen( string $kostenart ): array {
		$n = mb_strtolower( $kostenart );
		if ( strpos( $n, 'warmwasser' ) !== false ) {
			return [ 'warmwasser' ];
		}
		if ( strpos( $n, 'wasser' ) !== false || strpos( $n, 'abwasser' ) !== false ) {
			return [ 'kaltwasser', 'warmwasser' ];
		}
		if ( strpos( $n, 'heiz' ) !== false || strpos( $n, 'wärme' ) !== false || strpos( $n, 'gas' ) !== false || strpos( $n, 'öl' ) !== false ) {
			return [ 'heizung' ];
		}
		if ( strpos( $n, 'strom' ) !== false ) {
			return [ 'strom' ];
		}
		return [];
	}

	/** Verbrauch einer Einheit im Zeitraum: Stand am/nach Ende minus Stand am/vor Beginn, je Zähler der Typen. */
	private static function verbrauch( int $einheit_id, array $typen, string $von, string $bis ): ?float {
		global $wpdb;
		if ( ! $typen ) {
			return null;
		}
		$ph      = implode( ',', array_fill( 0, count( $typen ), '%s' ) );
		$zaehler = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . "mv_zaehler WHERE einheit_id = %d AND typ IN ($ph)", array_merge( [ $einheit_id ], $typen ) ) );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Platzhalterliste wird aus count() erzeugt.
		if ( ! $zaehler ) {
			return null;
		}
		$sum      = 0.0;
		$gefunden = false;
		foreach ( $zaehler as $z ) {
			$start = $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum <= %s ORDER BY datum DESC LIMIT 1', $z->id, $von ) );
			$ende  = $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum >= %s ORDER BY datum ASC LIMIT 1', $z->id, $bis ) );
			if ( $start === null || $ende === null ) {
				// Toleranz: Ablesung bis 14 Tage um den Stichtag
				$start = $start ?? $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum <= DATE_ADD(%s, INTERVAL 14 DAY) ORDER BY datum ASC LIMIT 1', $z->id, $von ) );
				$ende  = $ende ?? $wpdb->get_var( $wpdb->prepare( 'SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende WHERE zaehler_id = %d AND datum >= DATE_SUB(%s, INTERVAL 14 DAY) ORDER BY datum DESC LIMIT 1', $z->id, $bis ) );
			}
			if ( $start !== null && $ende !== null ) {
				$sum     += max( 0, (float) $ende - (float) $start );
				$gefunden = true;
			}
		}
		return $gefunden ? $sum : null;
	}

	/* ------------------------------------------------------------------ */
	/* Berechnung                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Berechnet die Abrechnung und speichert Ergebnisse je Mietverhältnis.
	 * Liefert ['ergebnisse' => [...], 'hinweise' => [...], 'positionen' => [...]]
	 */
	public static function berechnen( int $abrechnung_id ): array {
		global $wpdb;
		$abr = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_abrechnungen WHERE id = %d', $abrechnung_id ) );
		if ( ! $abr ) {
			return [
				'ergebnisse' => [],
				'hinweise'   => [ 'Abrechnung nicht gefunden.' ],
				'positionen' => [],
			];
		}
		$objekt               = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte WHERE id = %d', $abr->objekt_id ) );
		$von                  = $abr->von;
		$bis                  = $abr->bis;
		$tage_gesamt          = self::tage( $von, $bis );
		$hinweise             = [];
		$zentral              = $objekt->heizung !== 'dezentral';
		$hk_verbrauch_prozent = max( 50, min( 70, (int) $objekt->hk_verbrauchsanteil ?: 70 ) );

		// Einheiten des Objekts
		$einheiten        = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE objekt_id = %d', $abr->objekt_id ), OBJECT_K );
		$wohn             = array_filter( $einheiten, fn( $e ) => in_array( $e->typ, [ 'wohnung', 'gewerbe' ], true ) );
		$flaeche_gesamt   = array_sum( array_map( fn( $e ) => (float) $e->flaeche, $wohn ) );
		$anzahl_einheiten = count( $wohn ) ?: count( $einheiten );
		if ( $flaeche_gesamt <= 0 ) {
			$hinweise[] = __( 'Keine Wohnflächen bei den Einheiten hinterlegt – Flächenschlüssel nicht möglich.', 'mietverwaltung' );
		}

		// Mietverhältnisse im Zeitraum
		$mvs = [];
		foreach ( Mietv_Soll::alle_mv() as $mv ) {
			if ( ! isset( $einheiten[ $mv->einheit_id ] ) ) {
				continue;
			}
			$mietv_bis = ( $mv->ende && $mv->ende !== '0000-00-00' ) ? $mv->ende : '9999-12-31';
			$t         = self::overlap( $von, $bis, $mv->beginn, $mietv_bis );
			if ( $t > 0 ) {
				$mv->tage       = $t;
				$mv->mietv_von  = max( $von, $mv->beginn );
				$mv->mietv_bis  = min( $bis, $mietv_bis );
				$mvs[ $mv->id ] = $mv;
			}
		}
		if ( ! $mvs ) {
			$hinweise[] = __( 'Kein Mietverhältnis im Abrechnungszeitraum.', 'mietverwaltung' );
		}
		$personentage_gesamt = array_sum( array_map( fn( $m ) => max( 1, (int) $m->personen ) * $m->tage, $mvs ) );

		// Umlagefähige Belege mit Leistungszeitraum im Abrechnungszeitraum
		$belege = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, k.name AS kostenart, k.schluessel, k.anlage_v, k.betrkv
			 FROM ' . $wpdb->prefix . 'mv_belege b JOIN ' . $wpdb->prefix . 'mv_kostenarten k ON k.id = b.kostenart_id
			 WHERE b.objekt_id = %d AND k.umlagefaehig = 1
			   AND COALESCE(b.zeitraum_von, b.datum) <= %s AND COALESCE(b.zeitraum_bis, b.zeitraum_von, b.datum) >= %s
			 ORDER BY k.sortierung, b.datum',
				$abr->objekt_id,
				$bis,
				$von
			)
		);

		// Kosten je Kostenart (zeitanteilig)
		$arten = [];
		foreach ( $belege as $b ) {
			$lz_von  = $b->zeitraum_von ?: $b->datum;
			$lz_bis  = $b->zeitraum_bis ?: $lz_von;
			$lz_tage = max( 1, self::tage( $lz_von, $lz_bis ) );
			$faktor  = self::overlap( $von, $bis, $lz_von, $lz_bis ) / $lz_tage;
			$k       = $b->kostenart_id;
			if ( ! isset( $arten[ $k ] ) ) {
				$arten[ $k ] = [
					'name'       => $b->kostenart,
					'schluessel' => $b->schluessel ?: 'flaeche',
					'heizung'    => $b->anlage_v === 'heizung',
					'betrkv'     => $b->betrkv,
					'gesamt'     => 0.0,
					'lohn'       => 0.0,
					'par35a'     => $b->par35a,
					'belege'     => [],
				];
			}
			$anteil                 = round( (float) $b->betrag * $faktor, 2 );
			$arten[ $k ]['gesamt'] += $anteil;
			$arten[ $k ]['lohn']   += round( (float) $b->lohnanteil * $faktor, 2 );
			if ( $b->par35a && ! $arten[ $k ]['par35a'] ) {
				$arten[ $k ]['par35a'] = $b->par35a;
			}
			$arten[ $k ]['belege'][] = [
				'datum'     => $b->datum,
				'lieferant' => $b->lieferant,
				'betrag'    => (float) $b->betrag,
				'anteil'    => $anteil,
				'faktor'    => $faktor,
			];
		}

		// Ergebnis je Mietverhältnis
		$ergebnisse = [];
		foreach ( $mvs as $mv ) {
			$ergebnisse[ $mv->id ] = [
				'mv'                => $mv,
				'positionen'        => [],
				'kosten_bk'         => 0.0,
				'kosten_hk'         => 0.0,
				'vz_bk'             => 0.0,
				'vz_hk'             => 0.0,
				'par35a_handwerker' => 0.0,
				'par35a_haushalt'   => 0.0,
			];
		}

		$positionen = [];
		foreach ( $arten as $kid => $art ) {
			$gesamt = $art['gesamt'];
			if ( abs( $gesamt ) < 0.005 ) {
				continue;
			}

			// Heizkosten
			if ( $art['heizung'] ) {
				if ( ! $zentral ) {
					$hinweise[] = sprintf( /* translators: %1$s: Wert, %2$s: Wert */ __( '„%1$s“ (%2$s) nicht umgelegt: Objekt hat dezentrale Heizung – Mieter rechnen direkt mit dem Versorger ab.', 'mietverwaltung' ), $art['name'], mietv_eur( $gesamt ) );
					continue;
				}
				$teile = [ [ 'verbrauch', $gesamt * $hk_verbrauch_prozent / 100, 'Verbrauch (' . $hk_verbrauch_prozent . ' %)' ], [ 'flaeche', $gesamt * ( 100 - $hk_verbrauch_prozent ) / 100, 'Wohnfläche (' . ( 100 - $hk_verbrauch_prozent ) . ' %)' ] ];
			} else {
				$teile = [ [ $art['schluessel'], $gesamt, null ] ];
			}

			foreach ( $teile as [ $schluessel, $betrag, $label_zusatz ] ) {
				$basis_label  = '';
				$basis_gesamt = 0.0;
				$anteile      = [];

				if ( $schluessel === 'verbrauch' ) {
					$typen = self::zaehler_typen( $art['name'] );
					$vb    = [];
					foreach ( $mvs as $mv ) {
						$v = self::verbrauch( (int) $mv->einheit_id, $typen, $mv->mietv_von, $mv->mietv_bis );
						if ( $v !== null ) {
							$vb[ $mv->id ] = $v;
						}
					}
					$vb_gesamt = array_sum( $vb );
					if ( count( $vb ) < count( $mvs ) || $vb_gesamt <= 0 ) {
						$hinweise[] = sprintf( /* translators: %1$s: Wert, %2$s: Wert */ __( '„%1$s“: Keine (vollständigen) Zählerstände für den Zeitraum – ersatzweise nach Wohnfläche verteilt%2$s.', 'mietverwaltung' ), $art['name'], $art['heizung'] ? ' (Kürzungsrecht des Mieters 15 %, § 12 HeizkostenV, beachten)' : '' );
						$schluessel = 'flaeche';
					} else {
						$basis_label  = 'Verbrauch';
						$basis_gesamt = $vb_gesamt;
						foreach ( $mvs as $mv ) {
							$anteile[ $mv->id ] = [ $vb[ $mv->id ], $betrag * $vb[ $mv->id ] / $vb_gesamt ];
						}
					}
				}

				if ( $schluessel === 'flaeche' ) {
					if ( $flaeche_gesamt <= 0 ) {
						continue;
					}
					$basis_label  = 'Wohnfläche × Tage';
					$basis_gesamt = $flaeche_gesamt * $tage_gesamt;
					foreach ( $mvs as $mv ) {
						$f                  = (float) $einheiten[ $mv->einheit_id ]->flaeche;
						$anteile[ $mv->id ] = [ $f * $mv->tage, $betrag * ( $f * $mv->tage ) / $basis_gesamt ];
					}
				} elseif ( $schluessel === 'personen' ) {
					$basis_label  = 'Personen × Tage';
					$basis_gesamt = $personentage_gesamt;
					foreach ( $mvs as $mv ) {
						$pt                 = max( 1, (int) $mv->personen ) * $mv->tage;
						$anteile[ $mv->id ] = [ $pt, $personentage_gesamt > 0 ? $betrag * $pt / $personentage_gesamt : 0 ];
					}
				} elseif ( $schluessel === 'einheit' ) {
					$basis_label  = 'Einheiten × Tage';
					$basis_gesamt = $anzahl_einheiten * $tage_gesamt;
					foreach ( $mvs as $mv ) {
						$anteile[ $mv->id ] = [ $mv->tage, $betrag * $mv->tage / $basis_gesamt ];
					}
				}

				$label        = $art['name'] . ( $label_zusatz ? ' – ' . $label_zusatz : '' );
				$positionen[] = [
					'name'       => $label,
					'gesamt'     => round( $betrag, 2 ),
					'schluessel' => $basis_label,
					'basis'      => $basis_gesamt,
					'betrkv'     => $art['betrkv'],
				];
				foreach ( $anteile as $mv_id => [ $basis_mv, $anteil ] ) {
					$anteil                               = round( $anteil, 2 );
					$ergebnisse[ $mv_id ]['positionen'][] = [
						'name'         => $label,
						'gesamt'       => round( $betrag, 2 ),
						'schluessel'   => $basis_label,
						'basis_gesamt' => $basis_gesamt,
						'basis_mv'     => $basis_mv,
						'anteil'       => $anteil,
						'betrkv'       => $art['betrkv'],
					];
					if ( $art['heizung'] ) {
						$ergebnisse[ $mv_id ]['kosten_hk'] += $anteil;
					} else {
						$ergebnisse[ $mv_id ]['kosten_bk'] += $anteil;
					}
					// § 35a: Lohnanteil im gleichen Verhältnis
					if ( $art['par35a'] && $art['lohn'] > 0 && $gesamt > 0 ) {
						$lohn_anteil = round( $art['lohn'] * ( $anteil / $gesamt ), 2 );
						$ergebnisse[ $mv_id ][ $art['par35a'] === 'handwerker' ? 'par35a_handwerker' : 'par35a_haushalt' ] += $lohn_anteil;
					}
				}
			}
		}

		// Vorauszahlungen je Monat im Zeitraum
		foreach ( $ergebnisse as $mv_id => &$e ) {
			$cursor = new DateTime( substr( $e['mv']->mietv_von, 0, 7 ) . '-01' );
			$ende   = new DateTime( substr( $e['mv']->mietv_bis, 0, 7 ) . '-01' );
			while ( $cursor <= $ende ) {
				$k = Mietv_Soll::konditionen( (int) $mv_id, $cursor->format( 'Y-m-01' ) );
				if ( $k ) {
					$e['vz_bk'] += (float) $k->bk_vz;
					$e['vz_hk'] += (float) $k->hk_vz;
				}
				$cursor->modify( '+1 month' );
			}
			$e['saldo'] = round( ( $e['vz_bk'] + $e['vz_hk'] ) - ( $e['kosten_bk'] + $e['kosten_hk'] ), 2 ); // >0 Guthaben, <0 Nachzahlung
		}
		unset( $e );

		// Frist § 556 Abs. 3 BGB
		$frist = gmdate( 'Y-m-d', strtotime( $bis . ' +12 months' ) );
		if ( current_time( 'Y-m-d' ) > $frist ) {
			$hinweise[] = 'Abrechnungsfrist (12 Monate nach Ende des Zeitraums, § 556 Abs. 3 BGB) ist am ' . mietv_date( $frist ) . ' abgelaufen – Nachforderungen sind ausgeschlossen, Guthaben sind auszuzahlen.';
		}

		// Speichern
		$wpdb->delete( mietv_table( 'abrechnung_ergebnis' ), [ 'abrechnung_id' => $abrechnung_id ] );
		foreach ( $ergebnisse as $mv_id => $e ) {
			$wpdb->insert(
				mietv_table( 'abrechnung_ergebnis' ),
				[
					'abrechnung_id'     => $abrechnung_id,
					'mv_id'             => $mv_id,
					'tage'              => $e['mv']->tage,
					'kosten_bk'         => round( $e['kosten_bk'], 2 ),
					'kosten_hk'         => round( $e['kosten_hk'], 2 ),
					'vz_bk'             => round( $e['vz_bk'], 2 ),
					'vz_hk'             => round( $e['vz_hk'], 2 ),
					'saldo'             => $e['saldo'],
					'par35a_handwerker' => round( $e['par35a_handwerker'], 2 ),
					'par35a_haushalt'   => round( $e['par35a_haushalt'], 2 ),
					'details'           => wp_json_encode(
						[
							'positionen' => $e['positionen'],
							'von'        => $e['mv']->mietv_von,
							'bis'        => $e['mv']->mietv_bis,
							'hinweise'   => $hinweise,
							'flaeche'    => (float) $einheiten[ $e['mv']->einheit_id ]->flaeche,
							'personen'   => (int) $e['mv']->personen,
						],
						JSON_UNESCAPED_UNICODE
					),
				]
			);
		}
		$wpdb->update( mietv_table( 'abrechnungen' ), [ 'erstellt' => current_time( 'mysql' ) ], [ 'id' => $abrechnung_id ] );

		return [
			'ergebnisse' => $ergebnisse,
			'hinweise'   => $hinweise,
			'positionen' => $positionen,
			'abrechnung' => $abr,
			'objekt'     => $objekt,
		];
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_REQUEST['mietv_abr_action'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		global $wpdb;
		$a = sanitize_key( $_REQUEST['mietv_abr_action'] );
		check_admin_referer( 'mietv_abr_' . $a );
		$base = admin_url( 'admin.php?page=mietv_abrechnung' );

		if ( $a === 'neu' ) {
			$jahr = isset( $_POST['jahr'] ) ? (int) $_POST['jahr'] : (int) current_time( 'Y' ) - 1;
			$id   = $wpdb->insert(
				mietv_table( 'abrechnungen' ),
				[
					'owner_id'  => MIETV_OWNER_ID,
					'objekt_id' => ( isset( $_POST['objekt_id'] ) ? (int) $_POST['objekt_id'] : 0 ),
					'von'       => ( sanitize_text_field( wp_unslash( $_POST['von'] ?? '' ) ) ?: $jahr . '-01-01' ),
					'bis'       => ( sanitize_text_field( wp_unslash( $_POST['bis'] ?? '' ) ) ?: $jahr . '-12-31' ),
					'status'    => 'entwurf',
				]
			) ? $wpdb->insert_id : 0;
			if ( $id ) {
				self::berechnen( $id );
			}
			wp_safe_redirect( $base . '&id=' . $id );
			exit;
		}
		$id = ( isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0 );
		if ( $a === 'neu_berechnen' ) {
			self::berechnen( $id );
			wp_safe_redirect( $base . '&id=' . $id . '&msg=berechnet' );
			exit;
		}
		if ( $a === 'fertig' || $a === 'entwurf' ) {
			$wpdb->update( mietv_table( 'abrechnungen' ), [ 'status' => $a ], [ 'id' => $id ] );
			$wpdb->update( mietv_table( 'abrechnung_ergebnis' ), [ 'freigegeben' => $a === 'fertig' ? 1 : 0 ], [ 'abrechnung_id' => $id ] );
			wp_safe_redirect( $base . '&id=' . $id );
			exit;
		}
		if ( $a === 'loeschen' ) {
			$wpdb->delete( mietv_table( 'abrechnung_ergebnis' ), [ 'abrechnung_id' => $id ] );
			$wpdb->delete( mietv_table( 'abrechnungen' ), [ 'id' => $id ] );
			wp_safe_redirect( $base );
			exit;
		}
		if ( $a === 'vz_anpassen' ) {
			$erg = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis WHERE id = %d', ( isset( $_GET['erg'] ) ? (int) $_GET['erg'] : 0 ) ) );
			if ( $erg ) {
				$monate = max( 1, round( $erg->tage / 30.4 ) );
				$k      = Mietv_Soll::konditionen( (int) $erg->mv_id, current_time( 'Y-m-01' ) );
				$ab     = gmdate( 'Y-m-01', strtotime( '+1 month', strtotime( current_time( 'Y-m-01' ) ) ) );
				$wpdb->insert(
					mietv_table( 'konditionen' ),
					[
						'mv_id'      => $erg->mv_id,
						'gueltig_ab' => $ab,
						'kaltmiete'  => $k ? $k->kaltmiete : 0,
						'bk_vz'      => ceil( $erg->kosten_bk / $monate ),
						'hk_vz'      => ceil( $erg->kosten_hk / $monate ),
					]
				);
			}
			wp_safe_redirect( $base . '&id=' . $id . '&msg=vz' );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		global $wpdb;
		$id = ( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		echo '<div class="wrap mv-wrap">';
		if ( $id ) {
			self::detail( $id );
		} else {
			self::liste();
		}
		echo '</div>';
	}

	private static function liste(): void {
		global $wpdb;
		echo '<h1>' . esc_html__( 'Betriebskostenabrechnung', 'mietverwaltung' ) . '</h1>';
		$rows = $wpdb->get_results( 'SELECT a.*, o.bezeichnung AS objekt, (SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis e WHERE e.abrechnung_id = a.id) AS anzahl FROM ' . $wpdb->prefix . 'mv_abrechnungen a JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = a.objekt_id ORDER BY a.bis DESC' );

		echo '<h2>' . esc_html__( 'Neue Abrechnung', 'mietverwaltung' ) . '</h2><form method="post" class="mv-filter">';
		wp_nonce_field( 'mietv_abr_neu' );
		echo '<input type="hidden" name="mietv_abr_action" value="neu"><label>' . esc_html__( 'Objekt', 'mietverwaltung' ) . ' <select name="objekt_id" required>';
		foreach ( Mietv_Admin::opt_objekte() as $k => $v ) {
			printf( '<option value="%d">%s</option>', (int) $k, esc_html( $v ) );
		}
		$vj = (int) current_time( 'Y' ) - 1;
		printf( '</select></label> <label>' . esc_html__( 'Zeitraum von', 'mietverwaltung' ) . ' <input type="date" name="von" value="%d-01-01"></label> <label>' . esc_html__( 'bis', 'mietverwaltung' ) . ' <input type="date" name="bis" value="%d-12-31"></label> <button class="button button-primary">' . esc_html__( 'Berechnen', 'mietverwaltung' ) . '</button></form>', (int) $vj, (int) $vj );
		echo '<p class="description">' . esc_html__( 'Voraussetzungen: umlagefähige Belege mit Leistungszeitraum, Wohnflächen an den Einheiten, Personenzahl am Mietverhältnis, bei Verbrauchsschlüssel Zählerstände zu Beginn und Ende. Frist: 12 Monate nach Ende des Zeitraums (§ 556 Abs. 3 BGB).', 'mietverwaltung' ) . '</p>';

		echo '<h2>' . esc_html__( 'Vorhandene Abrechnungen', 'mietverwaltung' ) . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Objekt', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Zeitraum', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Status', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Mieter', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Erstellt', 'mietverwaltung' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$del = wp_nonce_url( admin_url( 'admin.php?page=mietv_abrechnung&mietv_abr_action=loeschen&id=' . $r->id ), 'mietv_abr_loeschen' );
			printf(
				'<tr><td>%s</td><td>%s – %s</td><td>%s</td><td>%d</td><td>%s</td><td><a href="%s">' . esc_html__( 'Öffnen', 'mietverwaltung' ) . '</a> | <a href="%s" class="mv-delete" onclick="return confirm(\'Abrechnung löschen?\')">' . esc_html__( 'Löschen', 'mietverwaltung' ) . '</a></td></tr>',
				esc_html( $r->objekt ),
				esc_html( mietv_date( $r->von ) ),
				esc_html( mietv_date( $r->bis ) ),
				$r->status === 'fertig' ? '<strong>' . esc_html__( 'Fertig / freigegeben', 'mietverwaltung' ) . '</strong>' : 'Entwurf',
				(int) $r->anzahl,
				esc_html( $r->erstellt ? date_i18n( 'd.m.Y', strtotime( $r->erstellt ) ) : '–' ),
				esc_url( admin_url( 'admin.php?page=mietv_abrechnung&id=' . $r->id ) ),
				esc_url( $del )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Abrechnung.', 'mietverwaltung' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function detail( int $id ): void {
		global $wpdb;
		$abr = $wpdb->get_row( $wpdb->prepare( 'SELECT a.*, o.bezeichnung AS objekt, o.heizung, o.hk_verbrauchsanteil FROM ' . $wpdb->prefix . 'mv_abrechnungen a JOIN ' . $wpdb->prefix . 'mv_objekte o ON o.id = a.objekt_id WHERE a.id = %d', $id ) );
		if ( ! $abr ) {
			echo '<p>' . esc_html__( 'Nicht gefunden.', 'mietverwaltung' ) . '</p>';
			return;
		}
		$ergs = $wpdb->get_results( $wpdb->prepare( 'SELECT e.*, m.vorname, m.nachname, ei.bezeichnung AS einheit FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis e JOIN ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv ON mv.id = e.mv_id JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id JOIN ' . $wpdb->prefix . 'mv_einheiten ei ON ei.id = mv.einheit_id WHERE e.abrechnung_id = %d ORDER BY m.nachname', $id ) );

		$msg = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		if ( $msg === 'berechnet' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Neu berechnet.', 'mietverwaltung' ) . '</p></div>';
		} elseif ( $msg === 'vz' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Neue Vorauszahlung als Kondition ab dem nächsten Monat angelegt – bitte unter „Konditionen“ prüfen und dem Mieter mitteilen (§ 560 Abs. 4 BGB).', 'mietverwaltung' ) . '</p></div>';
		}

		printf( '<h1>' . /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert */ esc_html__( 'Betriebskostenabrechnung %1$s – %2$s bis %3$s', 'mietverwaltung' ) . '</h1>', esc_html( $abr->objekt ), esc_html( mietv_date( $abr->von ) ), esc_html( mietv_date( $abr->bis ) ) );
		$u = fn( $a ) => wp_nonce_url( admin_url( 'admin.php?page=mietv_abrechnung&mietv_abr_action=' . $a . '&id=' . $id ), 'mietv_abr_' . $a );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mietv_abrechnung' ) ) . '" class="button">← Übersicht</a> <a href="' . esc_url( $u( 'neu_berechnen' ) ) . '" class="button">Neu berechnen</a> ';
		if ( $abr->status === 'fertig' ) {
			echo '<a href="' . esc_url( $u( 'entwurf' ) ) . '" class="button">Freigabe zurücknehmen</a> <span class="description">Freigegeben – im Mieterportal sichtbar.</span>';
		} else {
			echo '<a href="' . esc_url( $u( 'fertig' ) ) . '" class="button button-primary">Fertigstellen und im Mieterportal freigeben</a>';
		}
		echo '</p>';
		printf( '<p class="description">' . /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert */ esc_html__( 'Heizung: %1$s%2$s · Status: %3$s', 'mietverwaltung' ) . '</p>', esc_html( $abr->heizung ), $abr->heizung !== 'dezentral' ? ', Verbrauchsanteil ' . (int) ( $abr->hk_verbrauchsanteil ?: 70 ) . ' %' : '', esc_html( $abr->status ) );

		$hinweise = [];
		if ( $ergs ) {
			$d        = json_decode( $ergs[0]->details, true );
			$hinweise = $d['hinweise'] ?? [];
		}
		foreach ( $hinweise as $h ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $h ) . '</p></div>';
		}

		echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th>' . esc_html__( 'Mieter / Einheit', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Tage', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Betriebskosten', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Heizkosten', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Vorauszahlungen', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Ergebnis', 'mietverwaltung' ) . '</th><th>§ 35a</th><th></th></tr></thead><tbody>';
		foreach ( $ergs as $e ) {
			$brief = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_abrechnung_brief&erg=' . $e->id ), 'mietv_abr_brief_' . $e->id );
			$vz    = wp_nonce_url( admin_url( 'admin.php?page=mietv_abrechnung&mietv_abr_action=vz_anpassen&id=' . $id . '&erg=' . $e->id ), 'mietv_abr_vz_anpassen' );
			printf(
				'<tr><td>%s – %s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td class="%s"><strong>%s</strong><br><small>%s</small></td><td>%s</td><td><a href="%s" target="_blank" class="button button-small">' . esc_html__( 'PDF', 'mietverwaltung' ) . '</a> <a href="%s&ablegen=1" target="_blank" class="button button-small" title="PDF erzeugen und unter Dokumente (für den Mieter sichtbar) ablegen">' . esc_html__( 'PDF ablegen', 'mietverwaltung' ) . '</a> <a href="%s" class="button button-small" onclick="return confirm(\'Neue Vorauszahlung ab nächstem Monat anlegen?\')">' . esc_html__( 'VZ anpassen', 'mietverwaltung' ) . '</a></td></tr>',
				esc_html( $e->nachname . ', ' . $e->vorname ),
				esc_html( $e->einheit ),
				(int) $e->tage,
				esc_html( mietv_eur( $e->kosten_bk ) ),
				esc_html( mietv_eur( $e->kosten_hk ) ),
				esc_html( mietv_eur( $e->vz_bk + $e->vz_hk ) ),
				$e->saldo < 0 ? 'mv-neg' : 'mv-pos',
				esc_html( mietv_eur( abs( $e->saldo ) ) ),
				$e->saldo < 0 ? esc_html__( 'Nachzahlung', 'mietverwaltung' ) : esc_html__( 'Guthaben', 'mietverwaltung' ),
				esc_html( ( $e->par35a_handwerker + $e->par35a_haushalt ) > 0 ? mietv_eur( $e->par35a_handwerker + $e->par35a_haushalt ) : '–' ),
				esc_url( $brief ),
				esc_url( $brief ),
				esc_url( $vz )
			);
		}
		echo '</tbody></table>';

		// Detailpositionen
		foreach ( $ergs as $e ) {
			$d = json_decode( $e->details, true );
			printf( '<h3>' . /* translators: %s: Wert */ esc_html__( 'Positionen %s', 'mietverwaltung' ) . '</h3><table class="wp-list-table widefat striped mv-op"><thead><tr><th>' . esc_html__( 'Kostenart', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Gesamtkosten', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Schlüssel', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Basis gesamt', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Basis Mieter', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Anteil', 'mietverwaltung' ) . '</th></tr></thead><tbody>', esc_html( $e->nachname ) );
			foreach ( $d['positionen'] ?? [] as $p ) {
				printf( '<tr><td>%s <small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $p['name'] ), esc_html( $p['betrkv'] ), esc_html( mietv_eur( $p['gesamt'] ) ), esc_html( $p['schluessel'] ), esc_html( number_format( $p['basis_gesamt'], 2, ',', '.' ) ), esc_html( number_format( $p['basis_mv'], 2, ',', '.' ) ), esc_html( mietv_eur( $p['anteil'] ) ) );
			}
			echo '</tbody></table>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Schreiben an den Mieter                                            */
	/* ------------------------------------------------------------------ */

	public static function brief( ?int $erg_id = null, bool $portal = false ): void {
		global $wpdb;
		$erg_id = $erg_id ?: ( isset( $_GET['erg'] ) ? (int) $_GET['erg'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		if ( ! $portal ) {
			if ( ! current_user_can( Mietv_Admin::CAP ) ) {
				wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
			}
			check_admin_referer( 'mietv_abr_brief_' . $erg_id );
		}
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT e.*, a.von, a.bis, a.objekt_id FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis e JOIN ' . $wpdb->prefix . 'mv_abrechnungen a ON a.id = e.abrechnung_id WHERE e.id = %d', $erg_id ) );
		if ( ! $e ) {
			wp_die( esc_html__( 'Nicht gefunden.', 'mietverwaltung' ) );
		}
		$mv      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $e->mv_id ) );
		$objekt  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte WHERE id = %d', $e->objekt_id ) );
		$einheit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
		$d       = json_decode( $e->details, true );

		$b        = '<p>' . esc_html( Mietv_Brief::anrede( $mv ) ) . '</p>';
		$zeitraum = sprintf( /* translators: %1$s: Datum von, %2$s: Datum bis */ __( '%1$s bis %2$s', 'mietverwaltung' ), mietv_date( $e->von ), mietv_date( $e->bis ) );
		$nutzung  = sprintf( /* translators: %1$s: Datum von, %2$s: Datum bis, %3$d: Tage, %4$s: Fläche, %5$d: Personen */ __( '. Ihr Nutzungszeitraum: %1$s bis %2$s (%3$d Tage). Wohnfläche %4$s m², Personen im Haushalt: %5$d.', 'mietverwaltung' ), mietv_date( $d['von'] ), mietv_date( $d['bis'] ), (int) $e->tage, number_format( $d['flaeche'], 2, ',', '.' ), (int) $d['personen'] );
		$b       .= '<p>' . esc_html__( 'für die Wohnung', 'mietverwaltung' ) . ' <strong>' . esc_html( $objekt->strasse . ', ' . $objekt->plz . ' ' . $objekt->ort ) . ', ' . esc_html( $einheit->bezeichnung ) . '</strong> ' . esc_html__( 'erhalten Sie die Abrechnung der Betriebs- und Heizkosten für den Zeitraum', 'mietverwaltung' ) . ' <strong>' . esc_html( $zeitraum ) . '</strong>' . esc_html( $nutzung ) . '</p>';

		$b .= '<table class="liste"><thead><tr><th>' . esc_html__( 'Kostenart', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Gesamtkosten', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Verteilerschlüssel', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Gesamt', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Ihr Anteil', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Betrag', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
		foreach ( $d['positionen'] as $p ) {
			$b .= sprintf( '<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $p['name'] ), esc_html( $p['betrkv'] ), esc_html( mietv_eur( $p['gesamt'] ) ), esc_html( $p['schluessel'] ), esc_html( number_format( $p['basis_gesamt'], 2, ',', '.' ) ), esc_html( number_format( $p['basis_mv'], 2, ',', '.' ) ), esc_html( mietv_eur( $p['anteil'] ) ) );
		}
		$kosten  = $e->kosten_bk + $e->kosten_hk;
		$vz      = $e->vz_bk + $e->vz_hk;
		$b      .= sprintf( '<tr class="summe"><td colspan="5">' . esc_html__( 'Summe Ihrer Kosten', 'mietverwaltung' ) . '</td><td>%s</td></tr>', esc_html( mietv_eur( $kosten ) ) );
		$vz_text = sprintf( /* translators: %1$s: Betrag, %2$s: Betrag */ __( 'abzüglich geleistete Vorauszahlungen (Betriebskosten %1$s, Heizkosten %2$s)', 'mietverwaltung' ), mietv_eur( $e->vz_bk ), mietv_eur( $e->vz_hk ) );
		$b      .= '<tr><td colspan="5">' . esc_html( $vz_text ) . '</td><td>– ' . esc_html( mietv_eur( $vz ) ) . '</td></tr>';
		$b      .= sprintf( '<tr class="summe"><td colspan="5">%s</td><td>%s</td></tr></tbody></table>', $e->saldo < 0 ? __( 'Nachzahlung', 'mietverwaltung' ) : __( 'Guthaben zu Ihren Gunsten', 'mietverwaltung' ), esc_html( mietv_eur( abs( $e->saldo ) ) ) );

		if ( $e->saldo < -0.005 ) {
			$frist = date_i18n( 'd.m.Y', strtotime( '+30 days' ) );
			$b    .= sprintf( '<p>' . esc_html__( 'Bitte überweisen Sie den Nachzahlungsbetrag von', 'mietverwaltung' ) . ' <strong>%s</strong> ' . esc_html__( 'bis zum', 'mietverwaltung' ) . ' <strong>%s</strong>.</p>', esc_html( mietv_eur( abs( $e->saldo ) ) ), esc_html( $frist ) ) . Mietv_Brief::bankverbindung();
		} elseif ( $e->saldo > 0.005 ) {
			$b .= sprintf( '<p>' . esc_html__( 'Das Guthaben von', 'mietverwaltung' ) . ' <strong>%s</strong> ' . esc_html__( 'wird Ihnen innerhalb von 30 Tagen überwiesen bzw. mit der nächsten Mietzahlung verrechnet.', 'mietverwaltung' ) . '</p>', esc_html( mietv_eur( $e->saldo ) ) );
		}

		if ( $e->par35a_handwerker > 0 || $e->par35a_haushalt > 0 ) {
			$b .= '<h2>' . esc_html__( 'Bescheinigung nach § 35a EStG', 'mietverwaltung' ) . '</h2><p>' . esc_html__( 'In den abgerechneten Kosten sind folgende Arbeitskostenanteile enthalten, die Sie in Ihrer Einkommensteuererklärung geltend machen können:', 'mietverwaltung' ) . '</p><table class="liste">';
			if ( $e->par35a_handwerker > 0 ) {
				$b .= sprintf( '<tr><td>' . esc_html__( 'Handwerkerleistungen (§ 35a Abs. 3 EStG) – Ihr Anteil Lohnkosten', 'mietverwaltung' ) . '</td><td>%s</td></tr>', esc_html( mietv_eur( $e->par35a_handwerker ) ) );
			}
			if ( $e->par35a_haushalt > 0 ) {
				$b .= sprintf( '<tr><td>' . esc_html__( 'Haushaltsnahe Dienstleistungen (§ 35a Abs. 2 EStG) – Ihr Anteil Lohnkosten', 'mietverwaltung' ) . '</td><td>%s</td></tr>', esc_html( mietv_eur( $e->par35a_haushalt ) ) );
			}
			$b .= '</table>';
		}

		$b .= '<p class="hinweis">' . esc_html__( 'Die Belege können nach Terminvereinbarung eingesehen werden. Einwendungen gegen die Abrechnung sind innerhalb von 12 Monaten nach Zugang mitzuteilen (§ 556 Abs. 3 Satz 5 BGB).', 'mietverwaltung' ) . '</p>';

		Mietv_Brief::ausgeben( 'Betriebskostenabrechnung ' . substr( $e->bis, 0, 4 ), Mietv_Brief::empfaenger( $mv ), 'Betriebs- und Heizkostenabrechnung ' . mietv_date( $e->von ) . ' – ' . mietv_date( $e->bis ), $b, null, ! $portal, [ $e->objekt_id, $e->mv_id, 'abrechnung', 'Betriebskostenabrechnung ' . substr( $e->bis, 0, 4 ), true ] );
	}
}
