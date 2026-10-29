<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Mieterhöhung: Vergleichsmiete (§ 558 BGB), Indexmiete (§ 557b BGB), Staffel/Vereinbarung.
 * Prüft Sperrfrist, Kappungsgrenze und Wirksamkeitszeitpunkt, erzeugt Schreiben und neue Kondition.
 */
class Mietv_Erhoehung {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mietv_erhoehung_brief', [ __CLASS__, 'brief' ] );
	}

	/** Beginn des n-ten Monats nach dem Zugangsmonat (n=3 → § 558b; n=2 → § 557b). */
	private static function wirksam_ab( string $zugang, int $n ): string {
		$d = new DateTime( substr( $zugang, 0, 7 ) . '-01' );
		$d->modify( '+' . $n . ' months' );
		return $d->format( 'Y-m-d' );
	}

	/** Kaltmiete, die zu einem Datum galt. */
	private static function kaltmiete_am( int $mv_id, string $datum ): float {
		$k = Mietv_Soll::konditionen( $mv_id, substr( $datum, 0, 7 ) . '-01' );
		return $k ? (float) $k->kaltmiete : 0.0;
	}

	/** Letzte Änderung der Kaltmiete (Datum) – für Sperrfrist. */
	private static function letzte_erhoehung( int $mv_id ): ?string {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT gueltig_ab, kaltmiete FROM ' . $wpdb->prefix . 'mv_konditionen WHERE mv_id = %d ORDER BY gueltig_ab ASC', $mv_id ) );
		$letzte = null;
		$vorher = null;
		foreach ( $rows as $r ) {
			if ( $vorher !== null && (float) $r->kaltmiete > $vorher ) {
				$letzte = $r->gueltig_ab;
			}
			$vorher = (float) $r->kaltmiete;
		}
		return $letzte;
	}

	public static function pruefen( object $mv, string $art, array $in ): array {
		$heute   = current_time( 'Y-m-d' );
		$zugang  = $in['zugang'] ?: $heute;
		$alt     = self::kaltmiete_am( (int) $mv->id, $heute );
		$flaeche = (float) $in['flaeche'];
		$res     = [
			'alt'         => $alt,
			'neu'         => 0.0,
			'max'         => null,
			'wirksam_ab'  => '',
			'fehler'      => [],
			'hinweise'    => [],
			'begruendung' => '',
		];

		if ( $art === 'vergleich' ) {
			$res['wirksam_ab'] = self::wirksam_ab( $zugang, 3 );
			$vergleich         = (float) $in['vergleich_qm'] * $flaeche;
			// Sperrfrist: seit letzter Erhöhung/Beginn mind. 12 Monate beim Verlangen, Wirksamkeit = 15 Monate (§ 558 Abs. 1)
			$basis = self::letzte_erhoehung( (int) $mv->id ) ?: $mv->beginn;
			$frei  = gmdate( 'Y-m-d', strtotime( $basis . ' +12 months' ) );
			if ( $zugang < $frei ) {
				$res['fehler'][] = 'Sperrfrist: Das Erhöhungsverlangen ist erst ab ' . mietv_date( $frei ) . ' zulässig (ein Jahr nach letzter Erhöhung bzw. Mietbeginn, § 558 Abs. 1 BGB).';
			}
			// Kappungsgrenze: innerhalb von 3 Jahren max. 20 % / 15 %
			$angespannt  = Mietv_Einstellungen::get( 'angespannter_markt', '0' ) === '1';
			$kappung     = $angespannt ? 0.15 : 0.20;
			$vor3        = gmdate( 'Y-m-d', strtotime( $res['wirksam_ab'] . ' -3 years' ) );
			$miete_vor3  = $vor3 < $mv->beginn ? self::kaltmiete_am( (int) $mv->id, $mv->beginn ) : self::kaltmiete_am( (int) $mv->id, $vor3 );
			$max_kappung = round( $miete_vor3 * ( 1 + $kappung ), 2 );
			$res['max']  = min( $vergleich, $max_kappung );
			$res['neu']  = $in['neu'] > 0 ? min( (float) $in['neu'], $res['max'] ) : $res['max'];
			$res['neu']  = round( $res['neu'], 2 );
			if ( $res['neu'] <= $alt ) {
				$res['fehler'][] = __( 'No increase possible: the local reference rent or the cap is not above the current rent.', 'mietfux' );
			}
			$res['hinweise'][]  = sprintf( /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert, %4$d: Wert, %5$s: Wert, %6$s: Wert, %7$s: Wert */ __( 'Local reference rent: %1$s €/m² × %2$s m² = %3$s. Cap (%4$d %% on rent 3 years ago %5$s): %6$s. Permitted maximum: %7$s.', 'mietfux' ), number_format( (float) $in['vergleich_qm'], 2, ',', '.' ), number_format( $flaeche, 2, ',', '.' ), mietv_eur( $vergleich ), $kappung * 100, mietv_eur( $miete_vor3 ), mietv_eur( $max_kappung ), mietv_eur( $res['max'] ) );
			$res['hinweise'][]  = 'Wirksam ab Beginn des dritten Monats nach Zugang (§ 558b Abs. 1 BGB): ' . mietv_date( $res['wirksam_ab'] ) . '. Der Mieter hat bis dahin Zeit zur Zustimmung; ohne Zustimmung Klage innerhalb weiterer drei Monate (§ 558b Abs. 2).';
			$res['begruendung'] = $in['begruendung'] ?: 'Mietspiegel';
		} elseif ( $art === 'index' ) {
			$res['wirksam_ab'] = self::wirksam_ab( $zugang, 2 );
			$vpi_alt           = (float) $in['vpi_alt'];
			$vpi_neu           = (float) $in['vpi_neu'];
			if ( $vpi_alt <= 0 || $vpi_neu <= 0 ) {
				$res['fehler'][] = __( 'Please enter both index values (CPI, base 2020 = 100).', 'mietfux' );
			} else {
				$res['neu'] = round( $alt * $vpi_neu / $vpi_alt, 2 );
				$basis      = self::letzte_erhoehung( (int) $mv->id ) ?: $mv->beginn;
				if ( gmdate( 'Y-m-d', strtotime( $basis . ' +12 months' ) ) > $res['wirksam_ab'] ) {
					$res['fehler'][] = __( 'Index-linked rent: the rent must have been unchanged for at least one year (§ 557b (2) BGB).', 'mietfux' );
				}
				$res['hinweise'][]  = sprintf( /* translators: %1$s: VPI alt, %2$s: VPI neu, %3$s: Änderung in Prozent, %4$s: neue Kaltmiete, %5$s: bisherige Kaltmiete, %6$s: Datum */ __( 'CPI %1$s → %2$s = %3$+.2f %%. New base rent %4$s (previously %5$s). Effective from the start of the second month after receipt: %6$s.', 'mietfux' ), number_format( $vpi_alt, 1, ',', '.' ), number_format( $vpi_neu, 1, ',', '.' ), ( $vpi_neu / $vpi_alt - 1 ) * 100, mietv_eur( $res['neu'] ), mietv_eur( $alt ), mietv_date( $res['wirksam_ab'] ) );
				$res['begruendung'] = 'Indexmiete, VPI ' . number_format( $vpi_alt, 1, ',', '.' ) . ' → ' . number_format( $vpi_neu, 1, ',', '.' );
			}
		} else { // vereinbarung / staffel
			$res['neu']        = round( (float) $in['neu'], 2 );
			$res['wirksam_ab'] = $in['wirksam_ab'] ?: self::wirksam_ab( $zugang, 1 );
			if ( $res['neu'] <= 0 ) {
				$res['fehler'][] = __( 'Enter new base rent.', 'mietfux' );
			}
			$res['hinweise'][]  = __( 'Mutual amendment (§ 557 (1) BGB) or agreed stepped rent (§ 557a BGB) – requires the tenant\'s consent or an agreement in the contract.', 'mietfux' );
			$res['begruendung'] = $in['begruendung'] ?: 'Vereinbarung';
		}
		return $res;
	}

	public static function handle(): void {
		if ( empty( $_POST['mietv_erh_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_erh_nonce'] ?? '' ) ), 'mietv_erh' ) || empty( $_POST['uebernehmen'] ) ) {
			return;
		}
		global $wpdb;
		$mv_id = ( isset( $_POST['mv_id'] ) ? (int) $_POST['mv_id'] : 0 );
		$mv    = null;
		foreach ( Mietv_Soll::alle_mv( true ) as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		if ( ! $mv ) {
			return;
		}
		$in  = self::eingaben();
		$res = self::pruefen( $mv, $in['art'], $in );
		if ( $res['fehler'] ) {
			return;
		}
		$k = Mietv_Soll::konditionen( $mv_id, current_time( 'Y-m-01' ) );
		$wpdb->insert(
			mietv_table( 'konditionen' ),
			[
				'mv_id'      => $mv_id,
				'gueltig_ab' => $res['wirksam_ab'],
				'kaltmiete'  => $res['neu'],
				'bk_vz'      => $k ? $k->bk_vz : 0,
				'hk_vz'      => $k ? $k->hk_vz : 0,
			]
		);
		$wpdb->insert(
			mietv_table( 'erhoehungen' ),
			[
				'mv_id'       => $mv_id,
				'art'         => $in['art'],
				'zugang'      => $in['zugang'],
				'wirksam_ab'  => $res['wirksam_ab'],
				'alt'         => $res['alt'],
				'neu'         => $res['neu'],
				'begruendung' => $res['begruendung'] . "\n" . implode( "\n", $res['hinweise'] ),
			]
		);
		mietv_flash_set( 'ok', [ 'id' => (int) $wpdb->insert_id ] );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_erhoehung' ) );
		exit;
	}

	/** Nonce wird von den Aufrufern (handle, page) geprüft. */
	private static function eingaben(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return [
			'art'          => sanitize_key( $_POST['art'] ?? 'vergleich' ),
			'zugang'       => ( sanitize_text_field( wp_unslash( $_POST['zugang'] ?? '' ) ) ?: current_time( 'Y-m-d' ) ),
			'flaeche'      => mietv_to_float( sanitize_text_field( wp_unslash( $_POST['flaeche'] ?? '' ) ) ),
			'vergleich_qm' => mietv_to_float( sanitize_text_field( wp_unslash( $_POST['vergleich_qm'] ?? '' ) ) ),
			'neu'          => mietv_to_float( sanitize_text_field( wp_unslash( $_POST['neu'] ?? '' ) ) ),
			'vpi_alt'      => mietv_to_float( sanitize_text_field( wp_unslash( $_POST['vpi_alt'] ?? '' ) ) ),
			'vpi_neu'      => mietv_to_float( sanitize_text_field( wp_unslash( $_POST['vpi_neu'] ?? '' ) ) ),
			'wirksam_ab'   => ( sanitize_text_field( wp_unslash( $_POST['wirksam_ab'] ?? '' ) ) ?: '' ),
			'begruendung'  => sanitize_textarea_field( wp_unslash( $_POST['begruendung'] ?? '' ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function page(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Rent increase', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		if ( ( $flash['code'] ?? '' ) === 'ok' ) {
			$id = (int) ( $flash['id'] ?? 0 );
			$u  = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_erhoehung_brief&id=' . $id ), 'mietv_erh_brief' );
			printf( '<div class="notice notice-success"><p>' . esc_html__( 'Increase saved and new rent condition created.', 'mietfux' ) . ' <a href="%s" target="_blank" class="button">' . esc_html__( 'Open PDF', 'mietfux' ) . '</a> <a href="%s&ablegen=1" target="_blank" class="button">' . esc_html__( 'File PDF', 'mietfux' ) . '</a></p></div>', esc_url( $u ), esc_url( $u ) );
		}

		$mvs   = Mietv_Soll::alle_mv( true );
		$in    = isset( $_POST['mietv_erh_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_erh_nonce'] ?? '' ) ), 'mietv_erh' ) ? self::eingaben() : null;
		$mv_id = ( isset( $_POST['mv_id'] ) ? (int) $_POST['mv_id'] : (int) ( $mvs[0]->id ?? 0 ) );
		$mv    = null;
		foreach ( $mvs as $m ) {
			if ( (int) $m->id === $mv_id ) {
				$mv = $m;
			}
		}
		$flaeche = $mv ? (float) $wpdb->get_var( $wpdb->prepare( 'SELECT flaeche FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) ) : 0;
		$art     = $in['art'] ?? 'vergleich';

		echo '<form method="post" class="mv-form">';
		wp_nonce_field( 'mietv_erh', 'mietv_erh_nonce' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Tenancy', 'mietfux' ) . '</th><td><select name="mv_id" onchange="this.form.submit()">';
		foreach ( $mvs as $m ) {
			printf( '<option value="%d"%s>%s</option>', (int) $m->id, selected( (int) $mv_id, (int) $m->id, false ), esc_html( Mietv_Soll::mietv_label( $m ) ) );
		}
		echo '</select>';
		if ( $mv ) {
			$letzte = self::letzte_erhoehung( $mv_id );
			printf( ' <span class="description">' . /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert, %4$s: Wert */ esc_html__( 'Current base rent %1$s, living area %2$s m², start of tenancy %3$s%4$s', 'mietfux' ) . '</span>', esc_html( mietv_eur( self::kaltmiete_am( $mv_id, current_time( 'Y-m-d' ) ) ) ), esc_html( number_format( $flaeche, 2, ',', '.' ) ), esc_html( mietv_date( $mv->beginn ) ), $letzte ? ', letzte Erhöhung ' . esc_html( mietv_date( $letzte ) ) : '' );
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Type', 'mietfux' ) . '</th><td><select name="art" onchange="this.form.submit()">';
		foreach ( [
			'vergleich'    => esc_html__( 'Local reference rent (§ 558 BGB)', 'mietfux' ),
			'index'        => esc_html__( 'Index-linked rent (§ 557b BGB)', 'mietfux' ),
			'vereinbarung' => esc_html__( 'Agreement / stepped rent', 'mietfux' ),
		] as $k => $v ) {
			printf( '<option value="%s"%s>%s</option>', (int) $k, selected( $art, (int) $k, false ), esc_html( $v ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th>' . esc_html__( 'Receipt of the letter by the tenant', 'mietfux' ) . '</th><td><input type="date" name="zugang" value="%s"></td></tr>', esc_attr( $in['zugang'] ?? current_time( 'Y-m-d' ) ) );
		printf( '<input type="hidden" name="flaeche" value="%s">', esc_attr( $flaeche ) );
		if ( $art === 'vergleich' ) {
			printf( '<tr><th>' . esc_html__( 'Local reference rent (€/m²)', 'mietfux' ) . '</th><td><input type="text" name="vergleich_qm" value="%s" class="small-text mv-num"> <span class="description">' . esc_html__( 'Rent index (qualified for Fürth/Nuremberg), three comparable flats or expert opinion', 'mietfux' ) . '</span></td></tr>', esc_attr( $in['vergleich_qm'] ?? '' ) );
			printf( '<tr><th>' . esc_html__( 'Desired new base rent (€)', 'mietfux' ) . '</th><td><input type="text" name="neu" value="%s" class="small-text mv-num"> <span class="description">' . esc_html__( 'empty = permitted maximum', 'mietfux' ) . '</span></td></tr>', esc_attr( $in['neu'] ?? '' ) );
			printf( '<tr><th>' . esc_html__( 'Justification', 'mietfux' ) . '</th><td><textarea name="begruendung" rows="3" class="large-text">%s</textarea><p class="description">' . esc_html__( 'e.g. “Fürth rent index 2025, field C3: €8.90–10.40/m², mean €9.60/m²”', 'mietfux' ) . '</p></td></tr>', esc_textarea( $in['begruendung'] ?? '' ) );
		} elseif ( $art === 'index' ) {
			printf( '<tr><th>' . esc_html__( 'CPI at last rent setting', 'mietfux' ) . '</th><td><input type="text" name="vpi_alt" value="%s" class="small-text mv-num"> <span class="description">' . esc_html__( 'Consumer price index Germany, base 2020 = 100 (Destatis)', 'mietfux' ) . '</span></td></tr>', esc_attr( $in['vpi_alt'] ?? '' ) );
			printf( '<tr><th>' . esc_html__( 'Current CPI', 'mietfux' ) . '</th><td><input type="text" name="vpi_neu" value="%s" class="small-text mv-num"></td></tr>', esc_attr( $in['vpi_neu'] ?? '' ) );
		} else {
			printf( '<tr><th>' . esc_html__( 'New base rent (€)', 'mietfux' ) . '</th><td><input type="text" name="neu" value="%s" class="small-text mv-num"></td></tr>', esc_attr( $in['neu'] ?? '' ) );
			printf( '<tr><th>' . esc_html__( 'Valid from', 'mietfux' ) . '</th><td><input type="date" name="wirksam_ab" value="%s"></td></tr>', esc_attr( $in['wirksam_ab'] ?? '' ) );
			printf( '<tr><th>' . esc_html__( 'Justification', 'mietfux' ) . '</th><td><input type="text" name="begruendung" value="%s" class="regular-text"></td></tr>', esc_attr( $in['begruendung'] ?? '' ) );
		}
		echo '</tbody></table>';
		echo '<p><button name="pruefen" value="1" class="button">' . esc_html__( 'Check', 'mietfux' ) . '</button> ';

		if ( $in && $mv && ( ! empty( $_POST['pruefen'] ) || ! empty( $_POST['uebernehmen'] ) ) ) {
			$res = self::pruefen( $mv, $art, $in );
			foreach ( $res['fehler'] as $f ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $f ) . '</p></div>';
			}
			foreach ( $res['hinweise'] as $h ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html( $h ) . '</p></div>';
			}
			if ( ! $res['fehler'] ) {
				printf( '<div class="mv-kacheln"><div class="mv-kachel"><span>' . esc_html__( 'Previously', 'mietfux' ) . '</span><strong>%s</strong></div><div class="mv-kachel"><span>' . /* translators: %s: Wert */ esc_html__( 'New from %s', 'mietfux' ) . '</span><strong>%s</strong></div><div class="mv-kachel"><span>' . esc_html__( 'Increase', 'mietfux' ) . '</span><strong>%s (%+.1f %%)</strong></div></div>', esc_html( mietv_eur( $res['alt'] ) ), esc_html( mietv_date( $res['wirksam_ab'] ) ), esc_html( mietv_eur( $res['neu'] ) ), esc_html( mietv_eur( $res['neu'] - $res['alt'] ) ), (float) ( $res['alt'] > 0 ? ( $res['neu'] / $res['alt'] - 1 ) * 100 : 0 ) );
				echo '<button name="uebernehmen" value="1" class="button button-primary" data-confirm="' . esc_attr__( 'Create rent condition and save the increase?', 'mietfux' ) . '">' . esc_html__( 'Save increase, create rent condition, generate letter', 'mietfux' ) . '</button>';
			}
		}
		echo '</p></form>';

		$hist = $wpdb->get_results( 'SELECT e.*, m.nachname FROM ' . $wpdb->prefix . 'mv_erhoehungen e JOIN ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv ON mv.id = e.mv_id JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id ORDER BY e.zugang DESC' );
		if ( $hist ) {
			echo '<h2>' . esc_html__( 'Previous increases', 'mietfux' ) . '</h2><table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Received', 'mietfux' ) . '</th><th>' . esc_html__( 'Tenants', 'mietfux' ) . '</th><th>' . esc_html__( 'Type', 'mietfux' ) . '</th><th>' . esc_html__( 'Old', 'mietfux' ) . '</th><th>' . esc_html__( 'New', 'mietfux' ) . '</th><th>' . esc_html__( 'Effective from', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $hist as $h ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank">' . esc_html__( 'Letter', 'mietfux' ) . '</a></td></tr>', esc_html( mietv_date( $h->zugang ) ), esc_html( $h->nachname ), esc_html( $h->art ), esc_html( mietv_eur( $h->alt ) ), esc_html( mietv_eur( $h->neu ) ), esc_html( mietv_date( $h->wirksam_ab ) ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_erhoehung_brief&id=' . $h->id ), 'mietv_erh_brief_' . $h->id ) ) );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function brief(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		global $wpdb;
		check_admin_referer( 'mietv_erh_brief' );
		Mietv_PDF::ablegen_aus_request( 'mietv_erh_brief' );
		$id     = ( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
		$e      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_erhoehungen WHERE id = %d', $id ) );
		$mv     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $e->mv_id ) );
		$k      = Mietv_Soll::konditionen( (int) $mv->id, $e->wirksam_ab );
		$gesamt = $e->neu + ( $k ? (float) $k->bk_vz + (float) $k->hk_vz : 0 );
		$begr   = explode( "\n", $e->begruendung );

		$b = '<p>' . esc_html( Mietv_Brief::anrede( $mv ) ) . '</p>';
		if ( $e->art === 'vergleich' ) {
			$betreff = 'Mieterhöhungsverlangen nach § 558 BGB';
			$b      .= sprintf( '<p>' . esc_html__( 'the net base rent for the flat you rent is currently', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'per month. I hereby request your consent to an increase of the net base rent to', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'per month with effect', 'mietfux' ) . ' <strong>' . /* translators: %s: Wert */ esc_html__( 'from %s', 'mietfux' ) . '</strong>.</p>', esc_html( mietv_eur( $e->alt ) ), esc_html( mietv_eur( $e->neu ) ), esc_html( mietv_date( $e->wirksam_ab ) ) );
			$b      .= '<p><strong>' . esc_html__( 'Justification:', 'mietfux' ) . '</strong> Die verlangte Miete übersteigt nicht die ortsübliche Vergleichsmiete. ' . esc_html( $begr[0] ) . '</p>';
			foreach ( array_slice( $begr, 1 ) as $z ) {
				$b .= '<p class="hinweis">' . esc_html( $z ) . '</p>';
			}
			$b .= sprintf( '<p>' . /* translators: %s: Wert */ esc_html__( 'The rent has been unchanged for at least 15 months; the cap (§ 558 (3) BGB) is observed. The advance payments remain unchanged, so the total monthly rent from %s', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'will be.', 'mietfux' ) . '</p>', esc_html( mietv_date( $e->wirksam_ab ) ), esc_html( mietv_eur( $gesamt ) ) );
			$b .= sprintf( '<p>' . /* translators: %s: Wert */ esc_html__( 'Please give your consent by the end of the second calendar month after receipt of this letter (§ 558b (2) BGB) by returning the enclosed copy signed or by transferring the increased amount from %s.', 'mietfux' ) . '</p>', esc_html( mietv_date( $e->wirksam_ab ) ) );
			$b .= '<p style="margin-top:12mm;border-top:1px solid #000;padding-top:3mm"><strong>' . esc_html__( 'Declaration of consent', 'mietfux' ) . '</strong><br>Ich/Wir stimme(n) der Erhöhung der Nettokaltmiete auf ' . esc_html( mietv_eur( $e->neu ) ) . ' ab dem ' . esc_html( mietv_date( $e->wirksam_ab ) ) . ' zu.<br><br>_______________________________<br>Ort, Datum, Unterschrift Mieter</p>';
		} elseif ( $e->art === 'index' ) {
			$betreff = 'Mietanpassung nach § 557b BGB (Indexmiete)';
			$b      .= sprintf( '<p>' . /* translators: %s: Wert */ esc_html__( 'in accordance with the index-linked rent agreed in the tenancy agreement, I am adjusting the net base rent in line with the consumer price index for Germany. %s.', 'mietfux' ) . '</p><p>' . esc_html__( 'The net base rent increases from', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'to', 'mietfux' ) . ' <strong>%s</strong> ' . esc_html__( 'per month', 'mietfux' ) . ' <strong>' . /* translators: %s: Wert */ esc_html__( 'from %s', 'mietfux' ) . '</strong>' . /* translators: %s: Wert */ esc_html__( '. The total rent including advance payments will then be %s.', 'mietfux' ) . '</p>', esc_html( $begr[0] ), esc_html( mietv_eur( $e->alt ) ), esc_html( mietv_eur( $e->neu ) ), esc_html( mietv_date( $e->wirksam_ab ) ), esc_html( mietv_eur( $gesamt ) ) );
			foreach ( array_slice( $begr, 1 ) as $z ) {
				$b .= '<p class="hinweis">' . esc_html( $z ) . '</p>';
			}
			$b .= '<p>' . esc_html__( 'No consent is required; the adjustment takes effect at the start of the second month after receipt of this letter (§ 557b (3) BGB). Please adjust your standing order accordingly.', 'mietfux' ) . '</p>';
		} else {
			$betreff = 'Anpassung der Miete';
			$rest    = sprintf( /* translators: %1$s: bisherige Miete, %2$s: Grund, %3$s: Gesamtmiete */ __( 'per month (previously %1$s). Reason: %2$s. The total rent including advance payments will then be %3$s.', 'mietfux' ), mietv_eur( $e->alt ), $begr[0], mietv_eur( $gesamt ) );
			$b      .= '<p>' . esc_html__( 'as discussed or contractually agreed, the net base rent is', 'mietfux' ) . ' <strong>' . esc_html( sprintf( /* translators: %s: Datum */ __( 'from %s', 'mietfux' ), mietv_date( $e->wirksam_ab ) ) ) . '</strong> <strong>' . esc_html( mietv_eur( $e->neu ) ) . '</strong> ' . esc_html( $rest ) . '</p><p>' . esc_html__( 'Please adjust your standing order accordingly.', 'mietfux' ) . '</p>';
		}
		$objekt_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT objekt_id FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
		Mietv_Brief::ausgeben( 'Mieterhoehung ' . $e->zugang, Mietv_Brief::empfaenger( $mv ), $betreff, $b, mietv_date( $e->zugang ), true, [ $objekt_id, $mv->id, 'sonstiges', 'Mieterhöhung ' . mietv_date( $e->zugang ), false ] );
	}
}
