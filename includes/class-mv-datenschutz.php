<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Datenschutz: Löschkonzept (Anonymisierung nach Aufbewahrungsfrist) und Auskunft/Export (Art. 15, 20 DSGVO).
 */
class Mietv_Datenschutz {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mv_auskunft', [ __CLASS__, 'auskunft' ] );
	}

	/** Beendete Mietverhältnisse mit Löschfrist. */
	public static function beendete(): array {
		global $wpdb;
		$jahre = (int) Mietv_Einstellungen::get( 'aufbewahrung_jahre', 10 );
		$out   = [];
		$rows  = $wpdb->get_results( 'SELECT mv.*, m.vorname, m.nachname, m.email, e.bezeichnung AS einheit, o.bezeichnung AS objekt FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse mv JOIN ' . $wpdb->prefix . 'mv_mieter m ON m.id = mv.mieter_id JOIN ' . $wpdb->prefix . 'mv_einheiten e ON e.id = mv.einheit_id JOIN ' . $wpdb->prefix . "mv_objekte o ON o.id = e.objekt_id WHERE mv.ende IS NOT NULL AND mv.ende <> '0000-00-00' AND mv.ende < CURDATE() ORDER BY mv.ende" );
		foreach ( $rows as $r ) {
			$frist  = ( (int) substr( $r->ende, 0, 4 ) + $jahre ) . '-12-31';
			$aktive = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mv_mietverhaeltnisse WHERE mieter_id = %d AND (ende IS NULL OR ende = '0000-00-00' OR ende >= CURDATE())", $r->mieter_id ) );
			$out[]  = [
				'mv'           => $r,
				'label'        => $r->nachname . ', ' . $r->vorname . ' – ' . $r->objekt . ' / ' . $r->einheit,
				'frist'        => $frist,
				'faellig'      => current_time( 'Y-m-d' ) > $frist,
				'anonym'       => strpos( $r->nachname, 'Anonymisiert' ) === 0,
				'andere_aktiv' => $aktive > 0,
			];
		}
		return $out;
	}

	public static function faellig(): array {
		return array_filter( self::beendete(), fn( $b ) => $b['faellig'] && ! $b['anonym'] && ! $b['andere_aktiv'] );
	}

	/** Personenbezug entfernen, Zahlen (steuerlich) bleiben. */
	public static function anonymisieren( int $mieter_id ): void {
		global $wpdb;
		$wpdb->update(
			mietv_table( 'mieter' ),
			[
				'anrede'         => '',
				'vorname'        => '',
				'nachname'       => 'Anonymisiert #' . $mieter_id,
				'email'          => '',
				'telefon'        => '',
				'iban'           => '',
				'wp_user_id'     => null,
				'notiz'          => '',
				'geburtsdatum'   => null,
				'anschrift_alt'  => '',
				'p_anrede'       => '',
				'p_vorname'      => '',
				'p_nachname'     => '',
				'p_geburtsdatum' => null,
				'p_email'        => '',
			],
			[ 'id' => $mieter_id ]
		);
		$mietv_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE mieter_id = %d', $mieter_id ) );
		foreach ( $mietv_ids as $mv_id ) {
			$wpdb->update( mietv_table( 'mietverhaeltnisse' ), [ 'notiz' => '' ], [ 'id' => $mv_id ] );
			$wpdb->update(
				mietv_table( 'meldungen' ),
				[
					'betreff' => 'gelöscht',
					'text'    => '',
				],
				[ 'mv_id' => $mv_id ]
			);
			$wpdb->update( mietv_table( 'zahlungen' ), [ 'verwendungszweck' => '' ], [ 'mv_id' => $mv_id ] );
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM ' . $wpdb->prefix . 'mv_dokumente WHERE mv_id = %d AND attachment_id IS NOT NULL', $mv_id ) ) as $att ) {
				wp_delete_attachment( (int) $att, true );
			}
			$wpdb->delete( mietv_table( 'dokumente' ), [ 'mv_id' => $mv_id ] );
		}
	}

	public static function handle(): void {
		if ( empty( $_GET['mietv_anonym'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		$id = ( isset( $_GET['mietv_anonym'] ) ? (int) $_GET['mietv_anonym'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		check_admin_referer( 'mietv_anonym_' . $id );
		self::anonymisieren( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_datenschutz&msg=anonym' ) );
		exit;
	}

	/** Auskunft nach Art. 15 DSGVO / Datenübertragbarkeit Art. 20: alle Daten eines Mieters als JSON. */
	public static function auskunft(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$id = ( isset( $_GET['mieter'] ) ? (int) $_GET['mieter'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		check_admin_referer( 'mietv_auskunft_' . $id );
		$mieter = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $id ), ARRAY_A );
		if ( ! $mieter ) {
			wp_die( esc_html__( 'Nicht gefunden.', 'mietverwaltung' ) );
		}
		unset( $mieter['owner_id'] );
		$d = [
			'auskunft_nach'     => esc_html__( 'Art. 15 DSGVO', 'mietverwaltung' ),
			'erstellt'          => current_time( 'mysql' ),
			'verantwortlicher'  => Mietv_Einstellungen::get( 'vermieter_name' ) . ', ' . str_replace( "\n", ', ', Mietv_Einstellungen::get( 'vermieter_adresse' ) ),
			'zwecke'            => esc_html__( 'Durchführung des Mietverhältnisses (Art. 6 Abs. 1 lit. b DSGVO), Erfüllung steuer- und handelsrechtlicher Aufbewahrungspflichten (Art. 6 Abs. 1 lit. c DSGVO i. V. m. § 147 AO)', 'mietverwaltung' ),
			'speicherdauer'     => sprintf( /* translators: %d: Jahre */ __( '%d Jahre nach Ende des Kalenderjahres, in dem das Mietverhältnis endete', 'mietverwaltung' ), (int) Mietv_Einstellungen::get( 'aufbewahrung_jahre', 10 ) ),
			'empfaenger'        => esc_html__( 'Finanzamt (Anlage V), Steuerberater (sofern beauftragt), Bank (Zahlungsverkehr)', 'mietverwaltung' ),
			'stammdaten'        => $mieter,
			'mietverhaeltnisse' => [],
		];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE mieter_id = %d', $id ), ARRAY_A ) as $mv ) {
			$mv['konditionen']        = $wpdb->get_results( $wpdb->prepare( 'SELECT gueltig_ab, kaltmiete, bk_vz, hk_vz FROM ' . $wpdb->prefix . 'mv_konditionen WHERE mv_id = %d ORDER BY gueltig_ab', $mv['id'] ), ARRAY_A );
			$mv['zahlungen']          = $wpdb->get_results( $wpdb->prepare( 'SELECT datum, betrag, monat, verwendungszweck FROM ' . $wpdb->prefix . 'mv_zahlungen WHERE mv_id = %d ORDER BY datum', $mv['id'] ), ARRAY_A );
			$mv['meldungen']          = $wpdb->get_results( $wpdb->prepare( 'SELECT datum, betreff, text, status FROM ' . $wpdb->prefix . 'mv_meldungen WHERE mv_id = %d ORDER BY datum', $mv['id'] ), ARRAY_A );
			$mv['mahnungen']          = $wpdb->get_results( $wpdb->prepare( 'SELECT datum, stufe, betrag, frist FROM ' . $wpdb->prefix . 'mv_mahnungen WHERE mv_id = %d ORDER BY datum', $mv['id'] ), ARRAY_A );
			$mv['erhoehungen']        = $wpdb->get_results( $wpdb->prepare( 'SELECT zugang, art, alt, neu, wirksam_ab FROM ' . $wpdb->prefix . 'mv_erhoehungen WHERE mv_id = %d ORDER BY zugang', $mv['id'] ), ARRAY_A );
			$mv['abrechnungen']       = $wpdb->get_results( $wpdb->prepare( 'SELECT a.von, a.bis, e.kosten_bk, e.kosten_hk, e.vz_bk, e.vz_hk, e.saldo FROM ' . $wpdb->prefix . 'mv_abrechnung_ergebnis e JOIN ' . $wpdb->prefix . 'mv_abrechnungen a ON a.id = e.abrechnung_id WHERE e.mv_id = %d ORDER BY a.bis', $mv['id'] ), ARRAY_A );
			$mv['dokumente']          = $wpdb->get_results( $wpdb->prepare( 'SELECT typ, bezeichnung, datum FROM ' . $wpdb->prefix . 'mv_dokumente WHERE mv_id = %d ORDER BY datum', $mv['id'] ), ARRAY_A );
			$d['mietverhaeltnisse'][] = $mv;
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="auskunft-art15-mieter-' . $id . '.json"' );
		echo wp_json_encode( $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function page(): void {
		global $wpdb;
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Datenschutz', 'mietverwaltung' ) . '</h1>';
		if ( ( isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '' ) === 'anonym' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mieterdaten anonymisiert. Zahlungs- und Abrechnungsdaten bleiben ohne Personenbezug erhalten.', 'mietverwaltung' ) . '</p></div>';
		}
		printf( '<p class="description">' . /* translators: %d: Wert */ esc_html__( 'Rechtsgrundlage der Verarbeitung: Art. 6 Abs. 1 lit. b DSGVO (Mietvertrag) und lit. c (Aufbewahrungspflichten, § 147 AO). Aufbewahrungsfrist: %d Jahre nach Ende des Kalenderjahres, in dem das Mietverhältnis endete (', 'mietverwaltung' ) . '<a href="%s">' . esc_html__( 'Einstellungen', 'mietverwaltung' ) . '</a>' . esc_html__( '). Nach Ablauf wird der Personenbezug entfernt; Beträge bleiben für die Steuer erhalten.', 'mietverwaltung' ) . '</p>', (int) Mietv_Einstellungen::get( 'aufbewahrung_jahre', 10 ), esc_url( admin_url( 'admin.php?page=mietv_einstellungen' ) ) );

		echo '<h2>' . esc_html__( 'Löschkonzept – beendete Mietverhältnisse', 'mietverwaltung' ) . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Mietverhältnis', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Ende', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Löschfrist', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Status', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Aktion', 'mietverwaltung' ) . '</th></tr></thead><tbody>';
		$b = self::beendete();
		foreach ( $b as $x ) {
			$mv = $x['mv'];
			if ( $x['anonym'] ) {
				$status = 'anonymisiert';
			} elseif ( $x['andere_aktiv'] ) {
				$status = 'Mieter hat weiteres aktives Mietverhältnis';
			} elseif ( $x['faellig'] ) {
				$status = '<span class="mv-neg">' . esc_html__( 'Frist abgelaufen', 'mietverwaltung' ) . '</span>';
			} else {
				$status = 'in Aufbewahrung';
			}
			$url = wp_nonce_url( admin_url( 'admin.php?page=mietv_datenschutz&mietv_anonym=' . $mv->mieter_id ), 'mietv_anonym_' . $mv->mieter_id );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $x['label'] ),
				esc_html( mietv_date( $mv->ende ) ),
				esc_html( mietv_date( $x['frist'] ) ),
				$status, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
				( ! $x['anonym'] && ! $x['andere_aktiv'] ) ? sprintf( '<a href="%s" class="button button-small%s" onclick="return confirm(\'Personenbezug unwiderruflich entfernen?%s\')">' . esc_html__( 'Anonymisieren', 'mietverwaltung' ) . '</a>', esc_url( $url ), $x['faellig'] ? ' button-primary' : '', $x['faellig'] ? '' : ' Die Aufbewahrungsfrist ist noch nicht abgelaufen!' ) : '–'
			);
		}
		if ( ! $b ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Keine beendeten Mietverhältnisse.', 'mietverwaltung' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Auskunft (Art. 15 DSGVO) / Datenübertragbarkeit (Art. 20)', 'mietverwaltung' ) . '</h2><p>' . esc_html__( 'Erzeugt eine vollständige Datenübersicht des Mieters als JSON – Frist für die Beantwortung: ein Monat (Art. 12 Abs. 3 DSGVO).', 'mietverwaltung' ) . '</p><ul>';
		foreach ( $wpdb->get_results( 'SELECT id, vorname, nachname FROM ' . $wpdb->prefix . "mv_mieter WHERE nachname NOT LIKE 'Anonymisiert%' ORDER BY nachname" ) as $m ) {
			printf( '<li>%s – <a href="%s">' . esc_html__( 'Auskunft exportieren', 'mietverwaltung' ) . '</a></li>', esc_html( mietv_mieter_name( $m ) ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_auskunft&mieter=' . $m->id ), 'mietv_auskunft_' . $m->id ) ) );
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Hinweise für das Verarbeitungsverzeichnis', 'mietverwaltung' ) . '</h2><ul style="list-style:disc;margin-left:1.5em">
<li><strong>' . esc_html__( 'Verarbeitungstätigkeit:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Verwaltung von Mietverhältnissen (Stammdaten, Zahlungen, Abrechnungen, Mängelmeldungen, Schriftverkehr).', 'mietverwaltung' ) . '</li>
<li><strong>' . esc_html__( 'Betroffene:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Mieter, Mitbewohner (Personenzahl ohne Namen), Rechnungssteller.', 'mietverwaltung' ) . '</li>
<li><strong>' . esc_html__( 'Datenkategorien:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Name, Anschrift, Kontaktdaten, Bankverbindung, Vertrags- und Zahlungsdaten, Verbrauchsdaten (Zähler), Meldungen.', 'mietverwaltung' ) . '</li>
<li><strong>' . esc_html__( 'Technisch-organisatorische Maßnahmen:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Zugriff nur für Administrator (WordPress-Rolle), Mieterportal nur mit eigenem Login und Zuordnung, HTTPS, regelmäßiges Backup, Anonymisierung nach Fristablauf, Kaufvertrags-Auslesen lokal im Browser ohne Drittdienst.', 'mietverwaltung' ) . '</li>
<li><strong>' . esc_html__( 'Auftragsverarbeiter:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Hosting-Anbieter der WordPress-Installation (AV-Vertrag erforderlich).', 'mietverwaltung' ) . '</li>
</ul></div>';
	}
}
