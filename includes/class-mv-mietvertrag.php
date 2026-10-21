<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mietvertrag (Wohnraum), Übergabeprotokoll und Datenschutzinformation als druckfertige Dokumente.
 * Eigene Formulierungen nach BGB (Stand 2026); keine Übernahme geschützter Verbandsmuster.
 */
class Mietv_Mietvertrag {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mv_vertrag', [ __CLASS__, 'drucken' ] );
		add_action( 'admin_post_mv_uebergabe', [ __CLASS__, 'uebergabe' ] );
		add_action( 'admin_post_mv_sepa', [ __CLASS__, 'sepa' ] );
	}

	/** SEPA-Basislastschriftmandat für die Miete. */
	public static function sepa(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		$mv_id = (int) $_GET['mv_id'];
		check_admin_referer( 'mietv_sepa_' . $mv_id );
		$d = self::daten( $mv_id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Nicht gefunden.', 'mietverwaltung' ) );
		}
		[ 'mv' => $mv, 'mieter' => $m, 'einheit' => $e, 'objekt' => $ob, 'k' => $k ] = $d;
		$E      = Mietv_Einstellungen::class;
		$gl     = $E::get( 'glaeubiger_id' ) ?: '[Gläubiger-ID in den Einstellungen eintragen]';
		$ref    = 'MIETE-' . str_pad( (string) $mv_id, 6, '0', STR_PAD_LEFT );
		$gesamt = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0;
		self::kopf( 'SEPA-Mandat ' . $m->nachname, [ $ob->id, $mv->id, 'sonstiges', 'SEPA-Lastschriftmandat', false ] );
		echo '<h1>' . esc_html__( 'SEPA-Basislastschriftmandat', 'mietverwaltung' ) . '</h1>';
		printf( '<p>' . '<strong>' . esc_html__( 'Zahlungsempfänger (Gläubiger):', 'mietverwaltung' ) . '</strong> %s, %s<br>' . '<strong>' . esc_html__( 'Gläubiger-Identifikationsnummer:', 'mietverwaltung' ) . '</strong> %s<br>' . '<strong>' . esc_html__( 'Mandatsreferenz:', 'mietverwaltung' ) . '</strong> %s<br>' . '<strong>' . esc_html__( 'Art der Zahlung:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'wiederkehrende Zahlung', 'mietverwaltung' ) . '</p>', esc_html( $E::get( 'vermieter_name' ) ), esc_html( str_replace( "\n", ', ', $E::get( 'vermieter_adresse' ) ) ), esc_html( $gl ), esc_html( $ref ) );
		printf( '<p>' . esc_html__( 'Ich ermächtige %1$s, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von %2$s auf mein Konto gezogenen Lastschriften einzulösen.', 'mietverwaltung' ) . '</p>', esc_html( $E::get( 'vermieter_name' ) ), esc_html( $E::get( 'vermieter_name' ) ) );
		echo '<p class="klein">' . esc_html__( 'Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.', 'mietverwaltung' ) . '</p>';
		printf( '<p>' . '<strong>' . esc_html__( 'Verwendungszweck:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Monatliche Miete einschließlich Vorauszahlungen für die Wohnung %1$s, %2$s, %3$s %4$s – derzeit %5$s, fällig zum %6$d. Werktag des Monats. Änderungen des Betrags (Mietanpassung, neue Vorauszahlungen) werden mindestens 14 Tage vor Einzug mitgeteilt.', 'mietverwaltung' ) . '</p>', esc_html( $e->bezeichnung ), esc_html( $ob->strasse ), esc_html( $ob->plz ), esc_html( $ob->ort ), esc_html( mietv_eur( $gesamt ) ), (int) max( 1, min( 3, (int) $mv->zahltag ) ) );
		printf(
			'<table><tr><td style="width:35%%">' . esc_html__( 'Kontoinhaber', 'mietverwaltung' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'Straße, Hausnummer', 'mietverwaltung' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'PLZ, Ort', 'mietverwaltung' ) . '</td><td>%s %s</td></tr><tr><td>' . esc_html__( 'Kreditinstitut', 'mietverwaltung' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'IBAN', 'mietverwaltung' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'BIC', 'mietverwaltung' ) . '</td><td>%s</td></tr></table>',
			esc_html( implode( ' / ', array_map( fn( $z ) => preg_replace( '/^(Frau|Herr) /', '', $z ), mietv_mieter_personen( $m ) ) ) ),
			esc_html( $ob->strasse ),
			esc_html( $ob->plz ),
			esc_html( $ob->ort ),
			'<span class="feld" style="min-width:80mm"></span>',
			esc_html( $m->iban ?: '' ) . ( $m->iban ? '' : '<span class="feld" style="min-width:80mm"></span>' ),
			'<span class="feld" style="min-width:60mm"></span>'
		);
		echo self::unterschriften( $ob->ort, $m ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		self::fuss();
	}

	public static function optionen_default(): array {
		return [
			'mitmieter'              => '',
			'raeume'                 => '',
			'nebenraeume'            => esc_html__( 'Kellerabteil', 'mietverwaltung' ),
			'ausstattung'            => '',
			'schluessel'             => esc_html__( '2 Haustürschlüssel, 2 Wohnungsschlüssel, 1 Briefkastenschlüssel', 'mietverwaltung' ),
			'befristung_grund'       => '',
			'mietart'                => 'standard',   // standard | staffel | index
			'staffeln'               => '',           // Zeilen: 2027-01-01;650
			'kaution_raten'          => '3',
			'kleinrep_einzel'        => '120',
			'kleinrep_jahr'          => '8',
			'schoenheitsrep'         => 'renoviert',  // renoviert | unrenoviert
			'tiere'                  => 'erlaubnis',
			'untervermietung'        => 'erlaubnis',
			'kuendigungsverzicht'    => '0',
			'rauchen'                => 'nein',
			'hausordnung'            => '1',
			'energieausweis'         => '',
			'vormiete'               => '',
			'mpb_ausnahme'           => '',
			'mitbenutzung'           => 'Waschküche, Trockenraum, Fahrradabstellraum, Gartenanteil nach Hausordnung',
			'mietereigentum'         => '',
			'moeblierung'            => '0',
			'bk_art'                 => 'vorauszahlung',
			'zahlungsweise'          => 'dauerauftrag',
			'zustand_maengel'        => '',
			'arbeiten_vermieter'     => '',
			'arbeiten_vermieter_bis' => '',
			'arbeiten_mieter'        => '',
			'arbeiten_mieter_bis'    => '',
			'gartenpflege'           => '0',
			'merkblatt'              => '1',
			'sonstiges'              => '',
		];
	}

	private static function laden( int $mv_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_vertraege WHERE mv_id = %d', $mv_id ) );
		$o   = $row ? (array) json_decode( $row->optionen, true ) : [];
		return array_merge( self::optionen_default(), $o );
	}

	public static function handle(): void {
		if ( empty( $_POST['mietv_vertrag_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['mietv_vertrag_nonce'], 'mietv_vertrag' ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$mv_id = (int) $_POST['mv_id'];
		$o     = [];
		foreach ( self::optionen_default() as $k => $d ) {
			$raw     = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $d;
			$o[ $k ] = in_array( $k, [ 'raeume', 'ausstattung', 'staffeln', 'sonstiges', 'befristung_grund', 'schluessel', 'zustand_maengel', 'arbeiten_vermieter', 'arbeiten_mieter' ], true ) ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		$wpdb->delete( mietv_table( 'vertraege' ), [ 'mv_id' => $mv_id ] );
		$wpdb->insert(
			mietv_table( 'vertraege' ),
			[
				'mv_id'    => $mv_id,
				'erstellt' => current_time( 'mysql' ),
				'optionen' => wp_json_encode( $o, JSON_UNESCAPED_UNICODE ),
			]
		);
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_mietvertrag&mv_id=' . $mv_id . '&msg=saved' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		global $wpdb;
		$mvs   = Mietv_Soll::alle_mv();
		$mv_id = (int) ( $_GET['mv_id'] ?? ( $mvs[0]->id ?? 0 ) );
		$o     = self::laden( $mv_id );
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Mietvertrag', 'mietverwaltung' ) . '</h1>';
		if ( ( $_GET['msg'] ?? '' ) === 'saved' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Vertragsoptionen gespeichert.', 'mietverwaltung' ) . '</p></div>';
		}
		echo '<div class="notice notice-info inline"><p>' . '<strong>' . esc_html__( 'Hinweis:', 'mietverwaltung' ) . '</strong> ' . esc_html__( 'Der Vertrag wird aus den Daten des Mietverhältnisses (Parteien, Wohnung, Miete, Kaution, Beginn, Ende) und den Optionen unten erzeugt. Die Klauseln folgen dem BGB und der BGH-Rechtsprechung (Stand 2026). Vor Verwendung bei besonderen Konstellationen (Gewerbe, WG, möbliert, Mietpreisbremse) rechtlich prüfen lassen.', 'mietverwaltung' ) . '</p></div>';

		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_mietvertrag"><label>' . esc_html__( 'Mietverhältnis', 'mietverwaltung' ) . ' <select name="mv_id" onchange="this.form.submit()">';
		foreach ( $mvs as $m ) {
			printf( '<option value="%d"%s>%s</option>', (int) $m->id, selected( (int) $mv_id, (int) $m->id, false ), esc_html( Mietv_Soll::mietv_label( $m ) ) );
		}
		echo '</select></label></form>';
		if ( ! $mv_id ) {
			echo '</div>';
			return;
		}

		$vertrag = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_vertrag&mv_id=' . $mv_id ), 'mietv_vertrag_' . $mv_id );
		$ueberg  = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_uebergabe&mv_id=' . $mv_id ), 'mietv_uebergabe_' . $mv_id );
		printf( '<p><a href="%1$s" target="_blank" class="button button-primary">' . esc_html__( 'Mietvertrag (PDF)', 'mietverwaltung' ) . '</a> <a href="%1$s&ablegen=1" target="_blank" class="button">' . esc_html__( '… ablegen', 'mietverwaltung' ) . '</a> &nbsp; <a href="%2$s&art=einzug" target="_blank" class="button">' . esc_html__( 'Übergabeprotokoll Einzug', 'mietverwaltung' ) . '</a> <a href="%2$s&art=einzug&ablegen=1" target="_blank" class="button">' . esc_html__( '… ablegen', 'mietverwaltung' ) . '</a> &nbsp; <a href="%2$s&art=auszug" target="_blank" class="button">' . esc_html__( 'Übergabeprotokoll Auszug', 'mietverwaltung' ) . '</a> &nbsp; <a href="%1$s&nur=datenschutz" target="_blank" class="button">' . esc_html__( 'Datenschutzinformation', 'mietverwaltung' ) . '</a> &nbsp; <a href="%3$s" target="_blank" class="button">' . esc_html__( 'SEPA-Lastschriftmandat', 'mietverwaltung' ) . '</a> &nbsp; <a href="%1$s&format=html" target="_blank" class="button-link">' . esc_html__( 'HTML-Ansicht', 'mietverwaltung' ) . '</a></p>', esc_url( $vertrag ), esc_url( $ueberg ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_sepa&mv_id=' . $mv_id ), 'mietv_sepa_' . $mv_id ) ) );

		echo '<form method="post" class="mv-form">';
		wp_nonce_field( 'mietv_vertrag', 'mietv_vertrag_nonce' );
		printf( '<input type="hidden" name="mv_id" value="%d">', (int) $mv_id );
		echo '<h2>' . esc_html__( 'Vertragsoptionen', 'mietverwaltung' ) . '</h2><table class="form-table"><tbody>';
		$t = function ( $k, $label, $hint = '', $type = 'text' ) use ( $o ) {
			printf( '<tr><th><label for="%1$s">%2$s</label></th><td>', esc_attr( $k ), esc_html( $label ) );
			if ( $type === 'area' ) {
				printf( '<textarea id="%1$s" name="%1$s" rows="3" class="large-text">%2$s</textarea>', esc_attr( $k ), esc_textarea( $o[ $k ] ) );
			} else {
				printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text">', esc_attr( $k ), esc_attr( $o[ $k ] ) );
			}
			echo ( $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' ) . '</td></tr>';
		};
		$s = function ( $k, $label, $opts, $hint = '' ) use ( $o ) {
			printf( '<tr><th><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%1$s">', esc_attr( $k ), esc_html( $label ) );
			foreach ( $opts as $v => $l ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( (string) $o[ $k ], (string) $v, false ), esc_html( $l ) );
			}
			echo '</select>' . ( $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' ) . '</td></tr>';
		};
		$t( 'mitmieter', __( 'Weitere Mieter (Name, Geburtsdatum)', 'mietverwaltung' ), __( 'Nur für dritte Personen – der beim Mieter hinterlegte Ehe-/Lebenspartner wird automatisch aufgenommen. Alle Mieter haften gesamtschuldnerisch.', 'mietverwaltung' ) );
		$t( 'raeume', __( 'Räume', 'mietverwaltung' ), __( 'z. B. 3 Zimmer, Küche, Bad mit WC, Flur, Balkon', 'mietverwaltung' ), 'area' );
		$t( 'nebenraeume', __( 'Nebenräume / Stellplatz', 'mietverwaltung' ) );
		$t( 'ausstattung', __( 'Mitvermietete Ausstattung', 'mietverwaltung' ), __( 'z. B. Einbauküche (Hersteller, Baujahr), Waschmaschinenanschluss', 'mietverwaltung' ), 'area' );
		$t( 'schluessel', __( 'Übergebene Schlüssel', 'mietverwaltung' ), '', 'area' );
		$t( 'befristung_grund', __( 'Befristungsgrund (nur bei Mietende)', 'mietverwaltung' ), __( 'Zeitmietvertrag nur mit Grund nach § 575 BGB: Eigenbedarf, Abriss/Umbau, Werkswohnung. Ohne Grund gilt der Vertrag als unbefristet.', 'mietverwaltung' ), 'area' );
		$s(
			'mietart',
			__( 'Mietart', 'mietverwaltung' ),
			[
				'standard' => esc_html__( 'Vergleichsmiete (Erhöhung nach § 558)', 'mietverwaltung' ),
				'staffel'  => esc_html__( 'Staffelmiete (§ 557a)', 'mietverwaltung' ),
				'index'    => esc_html__( 'Indexmiete (§ 557b)', 'mietverwaltung' ),
			]
		);
		$t( 'staffeln', __( 'Staffeln (bei Staffelmiete)', 'mietverwaltung' ), __( 'Je Zeile: Datum;Kaltmiete – z. B. 2027-01-01;650,00. Zwischen den Staffeln mindestens ein Jahr.', 'mietverwaltung' ), 'area' );
		$s(
			'kaution_raten',
			__( 'Kaution zahlbar in', 'mietverwaltung' ),
			[
				'1' => esc_html__( 'einer Summe', 'mietverwaltung' ),
				'3' => esc_html__( 'drei Monatsraten (§ 551 Abs. 2)', 'mietverwaltung' ),
			],
			__( 'Höchstens drei Nettokaltmieten; Betrag aus dem Mietverhältnis.', 'mietverwaltung' )
		);
		$t( 'kleinrep_einzel', __( 'Kleinreparaturen: Höchstbetrag je Fall (€)', 'mietverwaltung' ), __( 'Rechtsprechung: ca. 100–150 €. Nur Teile mit häufigem direktem Zugriff des Mieters.', 'mietverwaltung' ) );
		$t( 'kleinrep_jahr', __( 'Kleinreparaturen: Höchstbetrag je Jahr (% der Jahreskaltmiete)', 'mietverwaltung' ), __( 'Üblich 6–8 %, oft zusätzlich gedeckelt (hier: und maximal 8 Fälle × Einzelbetrag).', 'mietverwaltung' ) );
		$s(
			'schoenheitsrep',
			__( 'Zustand bei Übergabe', 'mietverwaltung' ),
			[
				'renoviert'                           => esc_html__( 'renoviert – Schönheitsreparaturen trägt der Mieter nach Bedarf', 'mietverwaltung' ),
				__( 'unrenoviert', 'mietverwaltung' ) => __( 'unrenoviert – keine Schönheitsreparaturklausel (BGH VIII ZR 185/14)', 'mietverwaltung' ),
			]
		);
		$s(
			'tiere',
			__( 'Tierhaltung', 'mietverwaltung' ),
			[
				'erlaubnis' => esc_html__( 'Kleintiere frei, sonst Zustimmung (Standard)', 'mietverwaltung' ),
				'frei'      => esc_html__( 'Haustiere gestattet', 'mietverwaltung' ),
			],
			__( 'Ein generelles Verbot ist unwirksam (BGH VIII ZR 168/12).', 'mietverwaltung' )
		);
		$s( 'untervermietung', __( 'Untervermietung', 'mietverwaltung' ), [ 'erlaubnis' => __( 'nur mit Zustimmung (§ 540), berechtigtes Interesse § 553 bleibt', 'mietverwaltung' ) ] );
		$s(
			'kuendigungsverzicht',
			__( 'Beiderseitiger Kündigungsverzicht', 'mietverwaltung' ),
			[
				'0' => esc_html__( 'keiner', 'mietverwaltung' ),
				'1' => esc_html__( '1 Jahr', 'mietverwaltung' ),
				'2' => esc_html__( '2 Jahre', 'mietverwaltung' ),
				'3' => esc_html__( '3 Jahre', 'mietverwaltung' ),
				'4' => esc_html__( '4 Jahre (Maximum)', 'mietverwaltung' ),
			],
			__( 'Formularvertraglich höchstens 4 Jahre ab Vertragsschluss (BGH VIII ZR 86/10).', 'mietverwaltung' )
		);
		$s(
			'rauchen',
			__( 'Rauchen in der Wohnung', 'mietverwaltung' ),
			[
				'nein' => esc_html__( 'Bitte unterlassen (nicht verbindlich untersagbar)', 'mietverwaltung' ),
				'ja'   => esc_html__( 'keine Regelung', 'mietverwaltung' ),
			]
		);
		$s(
			'hausordnung',
			__( 'Hausordnung als Anlage', 'mietverwaltung' ),
			[
				'1' => esc_html__( 'ja', 'mietverwaltung' ),
				'0' => esc_html__( 'nein', 'mietverwaltung' ),
			]
		);
		$t( 'energieausweis', __( 'Energieausweis', 'mietverwaltung' ), __( 'Art (Bedarf/Verbrauch), Kennwert kWh/(m²·a), Effizienzklasse, Ausstellungsdatum – Vorlagepflicht § 80 GEG.', 'mietverwaltung' ) );
		$t( 'vormiete', __( 'Vormiete (nur bei Mietpreisbremse, § 556g Abs. 1a)', 'mietverwaltung' ), __( 'Nur in Gebieten der bayerischen Mieterschutzverordnung: Auskunft über die Vormiete, wenn die Miete über Vergleichsmiete + 10 % liegt.', 'mietverwaltung' ) );
		$t( 'mitbenutzung', __( 'Mitbenutzungsrechte (Gemeinschaftsräume)', 'mietverwaltung' ), __( 'Waschküche, Trockenraum, Fahrradkeller, Garten – nach Hausordnung.', 'mietverwaltung' ) );
		$t( 'mietereigentum', __( 'Mietereigentum / nicht mitvermietet', 'mietverwaltung' ), __( 'Gegenstände, die der Mieter vom Vormieter übernommen hat oder die ihm gehören.', 'mietverwaltung' ) );
		$s(
			'moeblierung',
			__( 'Möblierungszuschlag', 'mietverwaltung' ),
			[
				'0' => esc_html__( 'nein', 'mietverwaltung' ),
				'1' => esc_html__( 'ja – in der Kaltmiete enthalten, Betrag in „Sonstige Vereinbarungen“ nennen', 'mietverwaltung' ),
			]
		);
		$s(
			'bk_art',
			__( 'Betriebskosten', 'mietverwaltung' ),
			[
				'vorauszahlung' => esc_html__( 'Vorauszahlung mit jährlicher Abrechnung (Standard)', 'mietverwaltung' ),
				'pauschale'     => esc_html__( 'Pauschale ohne Abrechnung (§ 556 Abs. 2 BGB)', 'mietverwaltung' ),
			],
			__( 'Bei Pauschale keine Abrechnung und keine Nachforderung; Erhöhung nur bei gestiegenen Kosten durch Erklärung in Textform (§ 560 Abs. 1).', 'mietverwaltung' )
		);
		$s(
			'mpb_ausnahme',
			__( 'Mietpreisbremse – Ausnahme (nur angespannter Markt)', 'mietverwaltung' ),
			[
				''               => esc_html__( 'keine / nicht betroffen', 'mietverwaltung' ),
				'vormiete'       => esc_html__( 'Vormiete war bereits höher (§ 556e Abs. 1)', 'mietverwaltung' ),
				'modernisierung' => esc_html__( 'Modernisierung in den letzten 3 Jahren (§ 556e Abs. 2)', 'mietverwaltung' ),
				'umfassend'      => esc_html__( 'Erste Vermietung nach umfassender Modernisierung (§ 556f S. 2)', 'mietverwaltung' ),
				'neubau'         => esc_html__( 'Neubau, erstmals nach 1.10.2014 vermietet (§ 556f S. 1)', 'mietverwaltung' ),
			],
			__( 'Die Auskunft muss vor Vertragsschluss unaufgefordert in Textform erfolgen (§ 556g Abs. 1a BGB), sonst kann sich der Vermieter nicht auf die Ausnahme berufen.', 'mietverwaltung' )
		);
		$s(
			'zahlungsweise',
			__( 'Zahlungsweise', 'mietverwaltung' ),
			[
				'dauerauftrag' => esc_html__( 'Dauerauftrag', 'mietverwaltung' ),
				'sepa'         => esc_html__( 'SEPA-Lastschriftmandat (Anlage)', 'mietverwaltung' ),
			]
		);
		$t( 'zustand_maengel', __( 'Zustand bei Übergabe / bekannte Mängel', 'mietverwaltung' ), __( 'Kurz; Details ins Übergabeprotokoll.', 'mietverwaltung' ), 'area' );
		$t( 'arbeiten_vermieter', __( 'Vom Vermieter noch auszuführende Arbeiten', 'mietverwaltung' ), '', 'area' );
		$t( 'arbeiten_vermieter_bis', __( '… bis spätestens (Datum)', 'mietverwaltung' ) );
		$t( 'arbeiten_mieter', __( 'Vom Mieter auf eigene Kosten auszuführende Arbeiten', 'mietverwaltung' ), __( 'Gegenleistung ggf. in „Sonstige Vereinbarungen“ (z. B. Mietfreiheit).', 'mietverwaltung' ), 'area' );
		$t( 'arbeiten_mieter_bis', __( '… bis spätestens (Datum)', 'mietverwaltung' ) );
		$s(
			'gartenpflege',
			__( 'Gartenpflege durch den Mieter', 'mietverwaltung' ),
			[
				'0' => esc_html__( 'nein / durch Vermieter bzw. Dienstleister (Betriebskosten)', 'mietverwaltung' ),
				'1' => esc_html__( 'ja – Mieter pflegt den ihm zugewiesenen Gartenteil', 'mietverwaltung' ),
			]
		);
		$s(
			'merkblatt',
			__( 'Merkblatt Heizen und Lüften als Anlage', 'mietverwaltung' ),
			[
				'1' => esc_html__( 'ja', 'mietverwaltung' ),
				'0' => esc_html__( 'nein', 'mietverwaltung' ),
			]
		);
		$t( 'sonstiges', __( 'Sonstige Vereinbarungen', 'mietverwaltung' ), '', 'area' );
		echo '</tbody></table>';
		submit_button( 'Optionen speichern' );
		echo '</form></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Daten                                                              */
	/* ------------------------------------------------------------------ */

	private static function daten( int $mv_id ): ?array {
		global $wpdb;
		$mv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mietverhaeltnisse WHERE id = %d', $mv_id ) );
		if ( ! $mv ) {
			return null;
		}
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_mieter WHERE id = %d', $mv->mieter_id ) );
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_einheiten WHERE id = %d', $mv->einheit_id ) );
		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_objekte WHERE id = %d', $e->objekt_id ) );
		$k = Mietv_Soll::konditionen( $mv_id, substr( $mv->beginn, 0, 7 ) . '-01' ) ?: Mietv_Soll::konditionen( $mv_id, current_time( 'Y-m-01' ) );
		return [
			'mv'      => $mv,
			'mieter'  => $m,
			'einheit' => $e,
			'objekt'  => $o,
			'k'       => $k,
			'opt'     => self::laden( $mv_id ),
			'zusatz'  => Mietv_Soll::zusatzeinheiten( $mv_id ),
		];
	}

	private static ?string $pdf_titel = null;
	private static array $pdf_ablage  = [];

	/** Startet die Ausgabe: PDF (gepuffert) oder HTML-Druckseite (?format=html). */
	private static function kopf( string $titel, array $ablage = [] ): void {
		$E = Mietv_Einstellungen::class;
		if ( ( $_GET['format'] ?? 'pdf' ) !== 'html' ) {
			self::$pdf_titel  = $titel;
			self::$pdf_ablage = $ablage;
			ob_start();
			return;
		}
		?>
<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title><?php echo esc_html( $titel ); ?></title>
<style>
@page { size: A4; margin: 20mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5pt; line-height: 1.4; color: #000; margin: 0; }
.seite { max-width: 170mm; margin: 0 auto; padding: 20px; }
h1 { font-size: 16pt; text-align: center; margin: 0 0 8mm; }
h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 1px solid #000; padding-bottom: 1mm; }
h3 { font-size: 10.5pt; margin: 4mm 0 1mm; }
p { margin: 0 0 2.5mm; text-align: justify; }
table { width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; }
td, th { padding: 2px 6px; vertical-align: top; text-align: left; border-bottom: 1px solid #ddd; }
.unterschrift { display: flex; gap: 20mm; margin-top: 14mm; }
.unterschrift div { flex: 1; border-top: 1px solid #000; padding-top: 2mm; font-size: 9pt; }
.klein { font-size: 9pt; color: #333; }
.toolbar { background: #1d2327; color: #fff; padding: 8px 16px; font-size: 13px; }
.umbruch { page-break-before: always; }
.feld { display: inline-block; min-width: 30mm; border-bottom: 1px dotted #000; }
@media print { .toolbar { display: none; } .seite { padding: 0; max-width: none; } }
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Drucken / Als PDF sichern</button> <?php echo esc_html( $titel ); ?></div>
<div class="seite">
		<?php
	}

	private static function fuss(): void {
		if ( self::$pdf_titel !== null ) {
			$html  = ob_get_clean();
			$bytes = Mietv_PDF::dokument( $html, Mietv_Brief::absender_zeile() );
			if ( self::$pdf_ablage && ! empty( $_GET['ablegen'] ) ) {
				[ $objekt_id, $mv_id, $typ, $bez, $sichtbar ] = self::$pdf_ablage;
				Mietv_PDF::ablegen( $bytes, self::$pdf_titel . '.pdf', (int) $objekt_id, $mv_id ? (int) $mv_id : null, $typ, $bez, (bool) $sichtbar );
			}
			Mietv_PDF::senden( $bytes, self::$pdf_titel . '.pdf' );
		}
		echo '</div></body></html>';
		exit;
	}

	private static function unterschriften( string $ort, $m = null ): string {
		$h = '<div class="unterschrift"><div>' . esc_html( $ort ) . ', den ____________<br><br><br>' . esc_html__( 'Vermieter', 'mietverwaltung' ) . '</div>';
		if ( $m && mietv_hat_partner( $m ) ) {
			foreach ( mietv_mieter_personen( $m ) as $z ) {
				$h .= '<div>' . esc_html( $ort ) . ', den ____________<br><br><br>' . esc_html__( 'Mieter/in', 'mietverwaltung' ) . ': ' . esc_html( $z ) . '</div>';
			}
		} else {
			$h .= '<div>' . esc_html( $ort ) . ', den ____________<br><br><br>' . esc_html__( 'Mieter (alle)', 'mietverwaltung' ) . '</div>';
		}
		return $h . '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Mietvertrag                                                        */
	/* ------------------------------------------------------------------ */

	/** Betriebskostenkatalog § 2 BetrKV (amtlicher Text ist gemeinfrei, § 5 UrhG) mit Umlageschlüssel aus den Kostenarten. */
	private static function betrkv_tabelle( bool $zentral, bool $etw ): string {
		global $wpdb;
		$posten           = [
			1  => 'Laufende öffentliche Lasten des Grundstücks (Grundsteuer)',
			2  => 'Wasserversorgung',
			3  => 'Entwässerung',
			4  => 'Heizung',
			5  => 'Warmwasser',
			6  => 'Verbundene Heizungs- und Warmwasserversorgungsanlagen',
			7  => 'Personen- oder Lastenaufzug',
			8  => 'Straßenreinigung und Müllbeseitigung',
			9  => 'Gebäudereinigung und Ungezieferbekämpfung',
			10 => 'Gartenpflege',
			11 => 'Beleuchtung',
			12 => 'Schornsteinreinigung',
			13 => 'Sach- und Haftpflichtversicherung',
			14 => 'Hauswart',
			15 => 'Gemeinschafts-Antennenanlage / Breitbandnetz / Glasfaser-Verteilanlage',
			16 => 'Einrichtungen für die Wäschepflege',
			17 => 'Sonstige Betriebskosten (z. B. Wartung Rauchwarnmelder, Feuerlöscher, Dachrinnenreinigung, Wartung Elektro-/Blitzschutzanlage)',
		];
		$schluessel_label = [
			'flaeche'   => 'Wohnfläche',
			'personen'  => 'Personenzahl',
			'verbrauch' => 'Verbrauch (Zähler)',
			'einheit'   => 'je Wohneinheit',
		];
		$arten            = $wpdb->get_results( 'SELECT name, schluessel FROM ' . $wpdb->prefix . 'mv_kostenarten WHERE umlagefaehig = 1' );
		$find             = function ( array $worte ) use ( $arten, $schluessel_label ) {
			foreach ( $arten as $a ) {
				foreach ( $worte as $w ) {
					if ( mb_stripos( $a->name, $w ) !== false && ! empty( $schluessel_label[ $a->schluessel ] ) ) {
						return $schluessel_label[ $a->schluessel ];
					}
				}
			}
			return null;
		};
		$map              = [
			1  => [ 'Grundsteuer' ],
			2  => [ 'Wasser' ],
			3  => [ 'Abwasser', 'Entwässerung', 'Wasser' ],
			8  => [ 'Müll', 'Straßen' ],
			9  => [ 'Reinigung', 'Hausmeister' ],
			10 => [ 'Garten', 'Hausmeister' ],
			11 => [ 'Strom', 'Beleuchtung' ],
			12 => [ 'Schornstein' ],
			13 => [ 'Versicherung' ],
			14 => [ 'Hauswart', 'Hausmeister' ],
			17 => [ 'Sonstige' ],
		];
		$html             = '<table class="liste"><thead><tr><th>Nr.</th><th>Betriebskosten (§ 2 BetrKV)</th><th>Umlageschlüssel</th></tr></thead><tbody>';
		foreach ( $posten as $nr => $name ) {
			if ( in_array( $nr, [ 4, 5, 6 ], true ) ) {
				$sch = $zentral ? 'nach HeizkostenV (verbrauchsabhängig)' : 'entfällt (dezentrale Heizung)';
			} else {
				$sch = $find( $map[ $nr ] ?? [] ) ?: ( $etw ? 'Schlüssel der Eigentümergemeinschaft' : 'Wohnfläche' );
			}
			$html .= sprintf( '<tr><td style="text-align:left">%d</td><td style="text-align:left">%s</td><td style="text-align:left">%s</td></tr>', $nr, esc_html( $name ), esc_html( $sch ) );
		}
		return $html . '</tbody></table>';
	}

	public static function drucken(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		$mv_id = (int) $_GET['mv_id'];
		check_admin_referer( 'mietv_vertrag_' . $mv_id );
		$d = self::daten( $mv_id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Mietverhältnis nicht gefunden.', 'mietverwaltung' ) );
		}
		[ 'mv' => $mv, 'mieter' => $m, 'einheit' => $e, 'objekt' => $ob, 'k' => $k, 'opt' => $o, 'zusatz' => $zusatz ] = $d;
		$E           = Mietv_Einstellungen::class;
		$vermieter   = $E::get( 'vermieter_name' ) . ', ' . str_replace( "\n", ', ', $E::get( 'vermieter_adresse' ) );
		$typen_label = [
			'garage'     => esc_html__( 'Garage', 'mietverwaltung' ),
			'stellplatz' => esc_html__( 'Stellplatz', 'mietverwaltung' ),
			'keller'     => esc_html__( 'Keller/Lager', 'mietverwaltung' ),
			'gewerbe'    => esc_html__( 'Gewerbefläche', 'mietverwaltung' ),
			'wohnung'    => esc_html__( 'Wohnung', 'mietverwaltung' ),
		];
		$zusatz_text = implode( ', ', array_map( fn( $z ) => ( $typen_label[ $z->typ ] ?? $z->typ ) . ' ' . $z->bezeichnung, $zusatz ) );
		$nebenraeume = trim( implode( ', ', array_filter( [ $zusatz_text, $o['nebenraeume'] ] ) ), ', ' );
		$anteile     = array_filter( $zusatz, fn( $z ) => (float) $z->mietanteil > 0 );
		$personen    = mietv_mieter_personen( $m, true );
		if ( ! empty( $m->anschrift_alt ) ) {
			$personen = array_map( fn( $z ) => $z . ', ' . $m->anschrift_alt, $personen );
		}
		if ( $o['mitmieter'] ) {
			$personen[] = $o['mitmieter'];
		}
		$mieter    = implode( ' und ', $personen );
		$adresse   = $ob->strasse . ', ' . $ob->plz . ' ' . $ob->ort;
		$kalt      = $k ? (float) $k->kaltmiete : 0;
		$bk        = $k ? (float) $k->bk_vz : 0;
		$hk        = $k ? (float) $k->hk_vz : 0;
		$gesamt    = $kalt + $bk + $hk;
		$zentral   = $ob->heizung !== 'dezentral';
		$etw       = (bool) preg_match( '/eigentumswohnung|etw|weg\b/i', $ob->bezeichnung . ' ' . $ob->notiz );
		$befristet = $mv->ende && $mv->ende !== '0000-00-00';
		$ort       = Mietv_Brief::ort() ?: $ob->ort;
		// Dokumentgenerator: Absätze bestehen aus festen Vertragstexten und bereits escapten Werten.
		$P         = function ( string $t ): void {
			echo '<p>' . $t . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		$H         = function ( string $t ): void {
			echo '<h2>' . esc_html( $t ) . '</h2>';
		};

		if ( ( $_GET['nur'] ?? '' ) === 'datenschutz' ) {
			self::kopf( 'Datenschutzinformation ' . $m->nachname, [ $ob->id, $mv->id, 'sonstiges', 'Datenschutzinformation Art. 13', true ] );
			echo self::datenschutz_html( $vermieter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
			self::fuss();
		}

		self::kopf( 'Mietvertrag ' . $m->nachname, [ $ob->id, $mv->id, 'mietvertrag', 'Mietvertrag ' . mietv_date( $mv->beginn ), true ] );
		echo '<h1>' . esc_html__( 'Mietvertrag über Wohnraum', 'mietverwaltung' ) . '</h1>';

		$H( '§ 1 Vertragsparteien' );
		$P( 'zwischen<br><strong>' . esc_html( $vermieter ) . '</strong>' . ( $E::get( 'vermieter_steuernr' ) ? ', Steuer-Nr. ' . esc_html( $E::get( 'vermieter_steuernr' ) ) : '' ) . ( $E::get( 'vermieter_vertreter' ) ? ', vertreten durch ' . esc_html( $E::get( 'vermieter_vertreter' ) ) : '' ) . ' – nachfolgend „Vermieter“ –<br>und<br><strong>' . esc_html( $mieter ) . '</strong> – nachfolgend „Mieter“ –' );
		$P( 'Mehrere Mieter haften für alle Verpflichtungen aus diesem Vertrag als Gesamtschuldner. Sie bevollmächtigen sich gegenseitig zur Abgabe und Entgegennahme von Erklärungen, die das Mietverhältnis betreffen; die Vollmacht ist jederzeit widerruflich und gilt nicht für Kündigungen und Aufhebungsverträge. Tatsachen in der Person eines Mieters, die sich auf das Mietverhältnis auswirken, müssen alle Mieter für und gegen sich gelten lassen.' );

		$H( '§ 2 Mietsache' );
		$P(
			sprintf(
				'Vermietet wird zu Wohnzwecken die Wohnung <strong>%s</strong> im Anwesen <strong>%s</strong>%s, bestehend aus %s, Wohnfläche ca. %s m², zur Nutzung durch %d Person(en). Mitvermietet: %s%s.',
				esc_html( $e->bezeichnung ),
				esc_html( $adresse ),
				$e->lage ? ', ' . esc_html( $e->lage ) : '',
				esc_html( $o['raeume'] ?: ( $e->zimmer ? $e->zimmer . ' Zimmer, Küche, Bad' : '[Räume]' ) ),
				esc_html( number_format( (float) $e->flaeche, 2, ',', '.' ) ),
				(int) $mv->personen,
				esc_html( $nebenraeume ?: '–' ),
				$o['ausstattung'] ? '. Ausstattung: ' . esc_html( $o['ausstattung'] ) : ''
			)
		);
		$P( 'Die Wohnflächenangabe dient der Beschreibung des Mietgegenstands und ist keine Zusicherung; eine Abweichung von bis zu 10 % begründet keine Rechte. Maßgeblich ist die Wohnflächenverordnung.' );
		if ( $o['mitbenutzung'] ) {
			$P( 'Der Mieter darf nach Maßgabe der Hausordnung mitbenutzen: ' . esc_html( $o['mitbenutzung'] ) . '.' );
		}
		if ( $o['mietereigentum'] ) {
			$P( 'Nicht mitvermietet, sondern Eigentum des Mieters sind: ' . esc_html( $o['mietereigentum'] ) . '.' );
		}
		if ( $zusatz ) {
			$P( 'Ist ein Stellplatz, Carport oder eine Garage Teil der Mietsache, darf der Vermieter dessen Lage aus sachlichem Grund ändern, wenn er einen gleichwertigen Ersatz in zumutbarer Entfernung bereitstellt.' );
		}
		$P( sprintf( '<strong>Schlüssel:</strong> Übergeben werden %s. Weitere Schlüssel darf der Mieter nur mit Zustimmung des Vermieters anfertigen lassen; sie sind bei Auszug herauszugeben. Gehen Schlüssel durch Verschulden des Mieters verloren, trägt er die Kosten des Ersatzes – bei Schließanlagen auch die Kosten des Austauschs der Anlage, soweit eine missbräuchliche Verwendung nicht ausgeschlossen werden kann.', esc_html( $o['schluessel'] ) ) );

		$H( '§ 3 Mietzeit, Kündigung' );
		if ( $befristet && $o['befristung_grund'] ) {
			$P( sprintf( 'Das Mietverhältnis beginnt am <strong>%s</strong> und endet am <strong>%s</strong>, ohne dass es einer Kündigung bedarf (Zeitmietvertrag, § 575 BGB). Befristungsgrund: %s. Der Mieter kann frühestens vier Monate vor Ablauf Auskunft verlangen, ob der Grund noch besteht; entfällt er, kann der Mieter eine Verlängerung auf unbestimmte Zeit verlangen.', esc_html( mietv_date( $mv->beginn ) ), esc_html( mietv_date( $mv->ende ) ), esc_html( $o['befristung_grund'] ) ) );
		} else {
			$P( sprintf( 'Das Mietverhältnis beginnt am <strong>%s</strong> und läuft auf unbestimmte Zeit. Es gelten die gesetzlichen Kündigungsfristen (§ 573c BGB): für den Mieter drei Monate; für den Vermieter drei Monate, nach fünf und acht Jahren seit Überlassung jeweils drei Monate länger. Die Kündigung bedarf der Schriftform.', esc_html( mietv_date( $mv->beginn ) ) ) );
			if ( (int) $o['kuendigungsverzicht'] > 0 ) {
				$P( sprintf( 'Beide Parteien verzichten für <strong>%d Jahr(e) ab Vertragsschluss</strong> auf das Recht zur ordentlichen Kündigung; eine ordentliche Kündigung ist erstmals zum Ablauf dieses Zeitraums zulässig. Das Recht zur außerordentlichen Kündigung bleibt unberührt.', (int) $o['kuendigungsverzicht'] ) );
			}
		}
		$P( 'Setzt der Mieter den Gebrauch nach Ablauf der Mietzeit fort, verlängert sich das Mietverhältnis nicht stillschweigend; § 545 BGB wird ausgeschlossen. Gibt der Mieter die Mietsache nach Beendigung nicht zurück, schuldet er für die Dauer der Vorenthaltung als Entschädigung die vereinbarte Miete, mindestens die ortsübliche Vergleichsmiete (§ 546a BGB); weitergehende Ansprüche bleiben vorbehalten.' );

		$H( '§ 4 Miete, Betriebskosten' );
		$pauschale = $o['bk_art'] === 'pauschale';
		if ( $anteile ) {
			$teile          = implode( ', ', array_map( fn( $z ) => mietv_eur( $z->mietanteil ) . ' ' . ( $typen_label[ $z->typ ] ?? $z->typ ) . ' ' . $z->bezeichnung, $anteile ) );
			$wohnung_anteil = $kalt - array_sum( array_map( fn( $z ) => (float) $z->mietanteil, $anteile ) );
			$P( sprintf( 'Die monatliche Grundmiete (Nettokaltmiete) beträgt insgesamt <strong>%s</strong>; davon entfallen %s auf die Wohnung und %s. Wohnung und mitvermietete Einheiten bilden ein einheitliches Mietverhältnis und können nur gemeinsam gekündigt werden.', esc_html( mietv_eur( $kalt ) ), esc_html( mietv_eur( $wohnung_anteil ) ), esc_html( $teile ) ) );
		} elseif ( $zusatz ) {
			$P( sprintf( 'Die monatliche Grundmiete (Nettokaltmiete) für Wohnung und mitvermietete Einheiten beträgt insgesamt <strong>%s</strong>. Sie bilden ein einheitliches Mietverhältnis und können nur gemeinsam gekündigt werden.', esc_html( mietv_eur( $kalt ) ) ) );
		} else {
			$P( sprintf( 'Die monatliche Grundmiete (Nettokaltmiete) beträgt <strong>%s</strong>.', esc_html( mietv_eur( $kalt ) ) ) );
		}
		if ( $o['moeblierung'] === '1' ) {
			$P( 'In der Grundmiete ist ein Zuschlag für die mitvermietete Möblierung enthalten.' );
		}
		echo '<table class="liste"><tbody>';
		printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html__( 'Grundmiete', 'mietverwaltung' ), esc_html( mietv_eur( $kalt ) ) );
		printf( '<tr><td>%s</td><td>%s</td></tr>', $pauschale ? esc_html__( 'Betriebskostenpauschale (ohne Heizung/Warmwasser)', 'mietverwaltung' ) : esc_html__( 'Vorauszahlung Betriebskosten (ohne Heizung/Warmwasser)', 'mietverwaltung' ), esc_html( mietv_eur( $bk ) ) );
		if ( $zentral ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html__( 'Vorauszahlung Heizung und Warmwasser', 'mietverwaltung' ), esc_html( mietv_eur( $hk ) ) );
		}
		printf( '<tr class="summe"><td>%s</td><td>%s</td></tr></tbody></table>', esc_html__( 'Gesamtmiete monatlich', 'mietverwaltung' ), esc_html( mietv_eur( $gesamt ) ) );
		if ( $pauschale ) {
			$P( 'Mit der Betriebskostenpauschale sind die Betriebskosten im Sinne des § 2 BetrKV – mit Ausnahme der Heiz- und Warmwasserkosten – abgegolten; eine Abrechnung findet insoweit nicht statt. Erhöhen sich die Betriebskosten, kann der Vermieter die Pauschale durch Erklärung in Textform anteilig anheben (§ 560 Abs. 1 BGB); Ermäßigungen sind weiterzugeben.' );
		} else {
			$P( 'Der Mieter trägt die Betriebskosten im Sinne des § 2 Betriebskostenverordnung (BetrKV) in der jeweils geltenden Fassung, soweit sie für das Objekt anfallen – auch neu entstehende Betriebskosten, soweit ihre Umlage zulässig ist. Hierauf leistet er die vereinbarten monatlichen Vorauszahlungen. Über die Vorauszahlungen rechnet der Vermieter jährlich ab; die Abrechnung ist dem Mieter spätestens zwölf Monate nach Ende des Abrechnungszeitraums mitzuteilen (§ 556 Abs. 3 BGB). Nach einer Abrechnung kann jede Partei die Vorauszahlungen durch Erklärung in Textform auf eine angemessene Höhe anpassen (§ 560 Abs. 4 BGB). Nachzahlungen und Guthaben sind innerhalb von 30 Tagen nach Zugang der Abrechnung auszugleichen. Der Mieter kann die Abrechnungsunterlagen beim Vermieter einsehen.' );
		}
		$P( 'Umgelegt werden insbesondere die nachfolgenden Betriebskosten mit dem jeweils genannten Umlageschlüssel. Soweit kein Schlüssel vereinbart ist, erfolgt die Verteilung nach dem Verhältnis der Wohnflächen; verbrauchsabhängig erfasste Kosten nach Verbrauch. Heiz- und Warmwasserkosten werden nach der Heizkostenverordnung abgerechnet' . ( $zentral ? ' (Verbrauchsanteil ' . (int) ( $ob->hk_verbrauchsanteil ?: 70 ) . ' %, Rest nach Wohnfläche)' : '' ) . '.' . ( $etw ? ' Bei Eigentumswohnungen gilt der von der Eigentümergemeinschaft angewandte Umlageschlüssel; die auf die Wohnung entfallende Grundsteuer wird in voller Höhe umgelegt.' : '' ) );
		echo self::betrkv_tabelle( $zentral, $etw ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		$P( 'Sach- und Arbeitsleistungen des Vermieters dürfen mit dem Betrag angesetzt werden, der für eine gleichwertige Leistung eines Dritten angesetzt werden könnte, ohne Umsatzsteuer (§ 1 Abs. 1 Satz 2 BetrKV). Der Mieter schließt ab Übergabe einen eigenen Stromliefervertrag für die Wohnung ab und weist dies auf Verlangen nach; eine Abmeldung vor Ende des Mietverhältnisses ist unzulässig. Der Vermieter ist nicht verpflichtet, Telekommunikationsanschlüsse (Internet, Fernsehen) bereitzustellen oder eine bestehende Versorgung aufrechtzuerhalten.' );
		if ( $o['mietart'] === 'staffel' ) {
			$P( '<strong>Staffelmiete (§ 557a BGB):</strong> Die Grundmiete erhöht sich wie folgt; zwischen zwei Staffeln liegt jeweils mindestens ein Jahr:' );
			echo '<table class="liste"><tbody>';
			foreach ( array_filter( array_map( 'trim', explode( "\n", $o['staffeln'] ) ) ) as $z ) {
				[ $dat, $betr ] = array_pad( explode( ';', $z ), 2, '' );
				printf( '<tr><td>ab %s</td><td>%s</td></tr>', esc_html( mietv_date( trim( $dat ) ) ), esc_html( mietv_eur( mietv_to_float( $betr ) ) ) );
			}
			echo '</tbody></table>';
			$P( 'Während der Laufzeit der Staffelmiete sind Erhöhungen nach §§ 558 bis 559b BGB ausgeschlossen.' );
		} elseif ( $o['mietart'] === 'index' ) {
			$P( '<strong>Indexmiete (§ 557b BGB):</strong> Die Grundmiete ist an den vom Statistischen Bundesamt ermittelten Verbraucherpreisindex für Deutschland gekoppelt. Ändert sich der Index, kann jede Partei eine entsprechende prozentuale Anpassung der Grundmiete verlangen, sofern die Miete seit Vertragsbeginn oder der letzten Änderung mindestens ein Jahr unverändert war. Die Änderung ist in Textform zu erklären; die eingetretene Indexänderung und die neue Miete sind in einem Geldbetrag anzugeben. Die geänderte Miete ist ab Beginn des übernächsten Monats nach Zugang zu zahlen. Erhöhungen nach § 558 BGB sind ausgeschlossen; Erhöhungen nach § 559 BGB nur für Maßnahmen, die der Vermieter nicht zu vertreten hat.' );
		} else {
			$P( 'Mieterhöhungen richten sich nach den gesetzlichen Vorschriften (§§ 558 bis 560 BGB).' );
		}
		$mpb = [
			'vormiete'       => 'die vom Vormieter zuletzt geschuldete Miete bereits ' . esc_html( $o['vormiete'] ?: '[Betrag]' ) . ' betrug (§ 556e Abs. 1 BGB)',
			'modernisierung' => 'in den letzten drei Jahren vor Beginn des Mietverhältnisses Modernisierungsmaßnahmen durchgeführt wurden (§ 556e Abs. 2 BGB)',
			'umfassend'      => 'es sich um die erste Vermietung nach einer umfassenden Modernisierung handelt (§ 556f Satz 2 BGB)',
			'neubau'         => 'die Wohnung nach dem 1. Oktober 2014 erstmals genutzt und vermietet wurde (§ 556f Satz 1 BGB)',
		];
		if ( ! empty( $mpb[ $o['mpb_ausnahme'] ] ) ) {
			$P( '<strong>Auskunft nach § 556g Abs. 1a BGB:</strong> Die Wohnung liegt in einem Gebiet mit angespanntem Wohnungsmarkt. Soweit die vereinbarte Grundmiete die ortsübliche Vergleichsmiete um mehr als 10 % übersteigt, ist dies zulässig, weil ' . $mpb[ $o['mpb_ausnahme'] ] . '. Diese Auskunft wurde dem Mieter vor Vertragsschluss in Textform erteilt.' );
		} elseif ( $o['vormiete'] ) {
			$P( '<strong>Auskunft nach § 556g Abs. 1a BGB:</strong> Die vom Vormieter zuletzt geschuldete Miete betrug ' . esc_html( $o['vormiete'] ) . '.' );
		}

		$H( '§ 5 Zahlung, Verzug, Mietsicherheit' );
		$P( sprintf( 'Die Gesamtmiete ist monatlich im Voraus, spätestens am <strong>%d. Werktag</strong> des Monats, kostenfrei zu zahlen%s. Für die Rechtzeitigkeit kommt es auf den Eingang beim Vermieter an, es sei denn, die Verzögerung ist vom Mieter nicht zu vertreten. Der Mieter zahlt per %s.', max( 1, min( 3, (int) $mv->zahltag ) ), $E::get( 'vermieter_iban' ) ? ' auf das Konto IBAN ' . esc_html( $E::get( 'vermieter_iban' ) ) . ' bei der ' . esc_html( $E::get( 'vermieter_bank' ) ) . ', Kontoinhaber ' . esc_html( $E::get( 'vermieter_name' ) ) : ' auf das vom Vermieter benannte Konto', $o['zahlungsweise'] === 'sepa' ? 'SEPA-Lastschriftmandat (Anlage)' : 'Dauerauftrag' ) );
		$P( 'Gerät der Mieter in Verzug, sind rückständige Beträge mit fünf Prozentpunkten über dem Basiszinssatz zu verzinsen (§ 288 Abs. 1 BGB). Für jede berechtigte Mahnung nach Verzugseintritt kann der Vermieter die ihm entstandenen Kosten, pauschal 5,00 €, verlangen; dem Mieter bleibt der Nachweis gestattet, dass kein oder ein geringerer Schaden entstanden ist.' );
		$P( sprintf( 'Der Mieter leistet eine Mietsicherheit in Höhe von <strong>%s</strong> (höchstens drei Grundmieten, § 551 BGB)%s. Der Vermieter legt sie getrennt von seinem Vermögen bei einem Kreditinstitut zum üblichen Zinssatz für Spareinlagen mit dreimonatiger Kündigungsfrist an; die Erträge stehen dem Mieter zu und erhöhen die Sicherheit. Der Mieter darf während des Mietverhältnisses nicht gegen die Mietsicherheit aufrechnen oder den Vermieter auf sie verweisen. Der Rückzahlungsanspruch wird nach Beendigung des Mietverhältnisses, Rückgabe der Mietsache und Ablauf einer angemessenen Prüffrist fällig, soweit dem Vermieter keine Gegenforderungen zustehen.', esc_html( mietv_eur( $mv->kaution ) ), $o['kaution_raten'] === '3' ? ', zahlbar in drei gleichen Monatsraten, die erste zu Beginn des Mietverhältnisses' : ', fällig zu Beginn des Mietverhältnisses' ) );

		$H( '§ 6 Zustand der Mietsache, Arbeiten' );
		$P( 'Der Zustand der Mietsache bei Übergabe wird im gemeinsamen Übergabeprotokoll festgehalten, das Bestandteil dieses Vertrags ist.' . ( $o['zustand_maengel'] ? ' Bekannte Mängel: ' . esc_html( $o['zustand_maengel'] ) . '.' : '' ) );
		if ( $o['arbeiten_vermieter'] ) {
			$P( 'Der Vermieter führt auf seine Kosten fachgerecht' . ( $o['arbeiten_vermieter_bis'] ? ' bis spätestens ' . esc_html( $o['arbeiten_vermieter_bis'] ) : '' ) . ' folgende Arbeiten aus: ' . esc_html( $o['arbeiten_vermieter'] ) . '.' );
		}
		if ( $o['arbeiten_mieter'] ) {
			$P( 'Der Mieter führt auf seine Kosten fachgerecht' . ( $o['arbeiten_mieter_bis'] ? ' bis spätestens ' . esc_html( $o['arbeiten_mieter_bis'] ) : '' ) . ' folgende Arbeiten aus: ' . esc_html( $o['arbeiten_mieter'] ) . '.' );
		}

		$H( '§ 7 Nutzung, Überlassung an Dritte, Meldepflicht' );
		$P( 'Die Mietsache darf nur zu Wohnzwecken genutzt werden; eine andere Nutzung bedarf der Erlaubnis des Vermieters, die aus wichtigem Grund widerrufen werden kann. Der Mieter darf den Gebrauch ohne Erlaubnis des Vermieters weder ganz noch teilweise Dritten überlassen, insbesondere nicht untervermieten (§ 540 BGB). Hat der Mieter nach Vertragsschluss ein berechtigtes Interesse, einen Teil der Wohnung Dritten zu überlassen, kann er die Erlaubnis verlangen (§ 553 BGB); der Vermieter kann sie von einem angemessenen Untermietzuschlag abhängig machen.' );
		$P( 'Der Mieter teilt dem Vermieter Änderungen der Zahl der im Haushalt lebenden Personen binnen eines Monats mit. Der Vermieter stellt die Wohnungsgeberbestätigung nach § 19 Bundesmeldegesetz aus; der Mieter meldet sich fristgerecht an und bei Auszug um und teilt seine neue Anschrift mit. Schäden aus der Verletzung dieser Pflichten (z. B. unzutreffende Betriebskostenabrechnung) trägt der Mieter.' );

		$H( '§ 8 Tierhaltung' );
		$P(
			$o['tiere'] === 'frei'
				? 'Die Haltung von Haustieren ist gestattet, soweit Mitbewohner und Nachbarn nicht unzumutbar beeinträchtigt werden. Der Mieter haftet für alle durch die Tierhaltung entstehenden Schäden.'
				: 'Kleintiere, von denen keine Belästigung oder Gefährdung ausgeht (Zierfische, Ziervögel, Hamster u. ä.), dürfen ohne Zustimmung gehalten werden. Die Haltung sonstiger Tiere, insbesondere Hunde und Katzen, bedarf der Zustimmung des Vermieters, die nach Abwägung der beiderseitigen Interessen im Einzelfall erteilt wird und bei berechtigtem Interesse widerrufen werden kann. Das Füttern von Tauben und Wildtieren vom Grundstück aus ist untersagt. Der Mieter haftet für alle durch Tierhaltung entstehenden Schäden.'
		);

		$H( '§ 9 Betreten der Mietsache, Abwesenheit' );
		$P( 'Der Vermieter oder von ihm Beauftragte dürfen die Mietsache nach rechtzeitiger Ankündigung zu angemessener Tageszeit aus konkretem Anlass betreten – insbesondere zur Ablesung, zur Prüfung des Zustands, zur Durchführung von Arbeiten sowie zur Besichtigung bei Verkauf oder Neuvermietung. Bei Gefahr im Verzug ist das Betreten jederzeit zulässig. Bei längerer Abwesenheit sorgt der Mieter dafür, dass die Wohnung in dringenden Fällen zugänglich ist, und hinterlegt einen Schlüssel bei einer Vertrauensperson, die er dem Vermieter benennt; andernfalls darf der Vermieter in dringenden Fällen auf Kosten des Mieters öffnen lassen.' );

		$H( '§ 10 Bauliche Veränderungen, barrierefreier Umbau, E-Mobilität, Steckersolargeräte' );
		$P( 'Um- und Einbauten, Installationen und sonstige bauliche Veränderungen durch den Mieter bedürfen der vorherigen Zustimmung des Vermieters. Der Mieter stellt auf Verlangen bei Rückgabe den ursprünglichen Zustand fachgerecht wieder her, soweit nichts anderes vereinbart ist.' );
		$P( 'Auf Maßnahmen, die dem Gebrauch durch Menschen mit Behinderungen, dem Laden elektrisch betriebener Fahrzeuge, dem Einbruchschutz oder dem Betrieb eines Steckersolargeräts dienen, hat der Mieter nach § 554 BGB Anspruch, soweit sie dem Vermieter unter Abwägung der beiderseitigen Interessen zumutbar sind. Der Vermieter kann die Zustimmung von einer angemessenen zusätzlichen Sicherheit abhängig machen. Bei Ladeeinrichtungen kann er verlangen, dass die Anschlussleistung des Gebäudes ausreicht, die Installation durch einen Fachbetrieb erfolgt und Genehmigungen Dritter vorliegen. Bei Steckersolargeräten kann er verlangen, dass das Gerät den gesetzlichen und technischen Normen sowie den Vorgaben des Netzbetreibers entspricht, ordnungsgemäß angemeldet und ausreichend versichert ist und keine unzumutbare Blendwirkung ausgeht. Die Zustimmung kann widerrufen werden, wenn sich die tatsächlichen oder rechtlichen Verhältnisse so ändern, dass eine Versagung gerechtfertigt wäre.' );

		$H( '§ 11 Sorgfaltspflichten, Heizen und Lüften, Haftung des Mieters' );
		$P( 'Der Mieter behandelt die Mietsache und die gemeinschaftlichen Einrichtungen schonend und pfleglich. Er sorgt für ausreichende Beheizung und regelmäßiges Stoßlüften, auch bei Abwesenheit, und stellt Möbel mit Abstand zu Außenwänden auf, um Feuchtigkeits- und Schimmelschäden zu vermeiden' . ( $o['merkblatt'] === '1' ? ' (siehe Merkblatt in der Anlage)' : '' ) . '. Bodenbeläge sind mit den vom Hersteller empfohlenen Mitteln zu pflegen. Mängel und Schäden zeigt der Mieter unverzüglich an (§ 536c BGB); unterlässt er dies, haftet er für den dadurch entstehenden weiteren Schaden.' );
		$P( 'Der Mieter haftet für Schäden, die er, seine Haushaltsangehörigen, Besucher, Untermieter oder von ihm beauftragte Personen schuldhaft verursachen. Entsteht ein Schaden im Bereich, der dem unmittelbaren Einfluss und der Obhut des Mieters unterliegt, und hat der Vermieter eine Ursache aus seinem Verantwortungsbereich ausgeschlossen, trifft den Mieter die Beweislast dafür, dass er den Schaden nicht zu vertreten hat.' );

		$H( '§ 12 Haftung des Vermieters' );
		$P( 'Die verschuldensunabhängige Haftung des Vermieters für anfängliche Mängel (§ 536a Abs. 1 Alt. 1 BGB) ist ausgeschlossen. Im Übrigen haftet der Vermieter für Vorsatz und grobe Fahrlässigkeit; für leichte Fahrlässigkeit nur bei Verletzung wesentlicher Vertragspflichten (Kardinalpflichten), begrenzt auf den vertragstypischen, vorhersehbaren Schaden. Diese Beschränkungen gelten nicht für Schäden an Leben, Körper oder Gesundheit, bei arglistig verschwiegenen Mängeln, bei Übernahme einer Garantie und soweit der Schaden durch eine Gebäude- oder Haftpflichtversicherung des Vermieters gedeckt ist.' );

		$H( '§ 13 Kleinreparaturen' );
		$P( sprintf( 'Der Mieter trägt ohne Rücksicht auf Verschulden die Kosten kleiner Instandhaltungen und Instandsetzungen an Teilen der Mietsache, die seinem häufigen und unmittelbaren Zugriff ausgesetzt sind – insbesondere Installationsgegenstände für Elektrizität, Wasser und Gas, Heiz- und Kocheinrichtungen, Fenster- und Türverschlüsse, Rollläden und Verschlussvorrichtungen von Fensterläden –, soweit die Kosten im Einzelfall <strong>%s</strong> nicht übersteigen. Je Kalenderjahr ist die Belastung auf <strong>%s %% der Jahresgrundmiete (%s)</strong> begrenzt. Übersteigt eine Reparatur den Einzelbetrag, trägt der Vermieter die gesamten Kosten; der Mieter beauftragt keine Handwerker selbst, sondern meldet den Schaden.', esc_html( mietv_eur( mietv_to_float( $o['kleinrep_einzel'] ) ) ), esc_html( $o['kleinrep_jahr'] ), esc_html( mietv_eur( $kalt * 12 * mietv_to_float( $o['kleinrep_jahr'] ) / 100 ) ) ) );

		$H( '§ 14 Schönheitsreparaturen' );
		$P(
			$o['schoenheitsrep'] === 'renoviert'
				? 'Die Wohnung wird renoviert übergeben; sie gilt auch dann als renoviert, wenn vorhandene Gebrauchsspuren so unerheblich sind, dass die Räume den Gesamteindruck einer renovierten Wohnung vermitteln. Der Mieter übernimmt die während der Mietzeit durch seinen Gebrauch erforderlich werdenden Schönheitsreparaturen: Tapezieren und Anstreichen der Wände und Decken, Streichen der Heizkörper einschließlich Heizrohre, der Innentüren sowie der Fenster und Außentüren von innen, fachgerechte Reinigung der Teppichböden. Starre Fristen bestehen nicht; maßgeblich ist der tatsächliche Zustand. Die Farbwahl ist während der Mietzeit frei; bei Rückgabe sind fällige Schönheitsreparaturen in neutralen, deckenden, hellen Farbtönen auszuführen.'
				: 'Die Wohnung wird unrenoviert übergeben; ihr Zustand ist im Übergabeprotokoll festgehalten. Die Schönheitsreparaturen obliegen dem Vermieter.'
		);

		$H( '§ 15 Aufrechnung, Zurückbehaltung' );
		$P( 'Gegen Mietforderungen kann der Mieter mit Forderungen aus §§ 536a, 539 BGB oder aus ungerechtfertigter Bereicherung wegen zu viel gezahlter Miete nur aufrechnen oder ein Zurückbehaltungsrecht ausüben, wenn er dies dem Vermieter mindestens einen Monat vor Fälligkeit der Miete in Textform angezeigt hat (§ 556b Abs. 2 BGB). Mit anderen Forderungen kann er nur aufrechnen oder zurückbehalten, soweit sie unbestritten oder rechtskräftig festgestellt sind.' );

		if ( $zentral ) {
			$H( '§ 16 Zentralheizung, Nutzerwechsel' );
			$P( 'Der Vermieter betreibt die Heizungsanlage in der Heizperiode vom 1. Oktober bis 30. April sowie außerhalb, wenn die Witterung es erfordert, so, dass in den Wohnräumen zwischen 6 und 23 Uhr eine Temperatur von mindestens 20 °C erreicht werden kann; die Grundsätze sparsamen Energieeinsatzes sind zu beachten. Betriebsstörungen zeigt der Mieter unverzüglich an. Der Mieter ist zur Benutzung der Heizung verpflichtet; Nichtbenutzung befreit nicht von den Heizkosten. Der Vermieter darf die Wärmeversorgung auf eine andere Art, auch Wärmelieferung durch Dritte (§ 556c BGB), umstellen.' );
			$P( 'Endet das Mietverhältnis durch Kündigung des Mieters oder aus von ihm zu vertretenden Gründen während eines Abrechnungszeitraums, trägt der Mieter die Kosten einer Zwischenablesung. Unterbleibt eine Zwischenablesung, werden die Heiz- und Warmwasserkosten zwischen Vor- und Nachmieter nach der Gradtagszahlentabelle bzw. zeitanteilig aufgeteilt.' );
		} else {
			$H( '§ 16 Heizung' );
			$P( 'Die Wohnung verfügt über eine eigene Heizungsanlage. Der Mieter schließt die Verträge über Brennstoff- bzw. Energielieferung selbst, trägt die Verbrauchskosten unmittelbar und sorgt für ausreichende Beheizung zur Vermeidung von Frost- und Feuchtigkeitsschäden. Wartung und Instandhaltung der Anlage obliegen dem Vermieter; die Wartungskosten sind als Betriebskosten umlagefähig.' );
		}

		$H( '§ 17 Schilder, Antennen, Blumenkästen, Gartenpflege' );
		$P( 'Schilder, Anschläge und Außenantennen dürfen nur mit Zustimmung des Vermieters angebracht werden, der den Ort bestimmt und die Zustimmung von einer angemessenen Sicherheitsleistung abhängig machen kann; bei Auszug sind sie fachgerecht zu entfernen. Blumenkästen sind nur an der Innenseite von Balkonbrüstungen und sicher befestigt zulässig.' );
		if ( $o['gartenpflege'] === '1' ) {
			$P( 'Der Mieter pflegt den ihm zugewiesenen Gartenteil ordnungsgemäß (Rasen mähen, wässern, Beete und Wege von Unkraut freihalten, Hecken mindestens einmal jährlich schneiden, Laub beseitigen). Der Vermieter stellt hierfür keine Geräte. Kommt der Mieter trotz Abmahnung seinen Pflichten nicht nach, kann der Vermieter die Arbeiten auf Kosten des Mieters ausführen lassen.' );
		}

		$H( '§ 18 Hausordnung, Reinigung, Winterdienst' );
		$P( 'Die beigefügte Hausordnung ist Bestandteil des Vertrags. Soweit Reinigung von Gemeinschaftsflächen und Winterdienst nicht durch den Vermieter oder beauftragte Dritte erbracht werden, obliegen sie den Mietparteien im wöchentlichen Wechsel nach dem im Haus ausgehängten Plan; der Vermieter stellt das Räumgerät, der Mieter das Streumaterial. Kommt der Mieter diesen Pflichten trotz Abmahnung nicht oder unzureichend nach, kann der Vermieter sie im Wege der Ersatzvornahme auf Kosten des Mieters ausführen lassen. Verhinderung durch Abwesenheit, Alter, Krankheit oder Urlaub entbindet nicht; der Mieter hat für Vertretung zu sorgen.' );

		$H( '§ 19 Rückgabe' );
		$P( 'Bei Beendigung gibt der Mieter die Mietsache vollständig geräumt, gereinigt (einschließlich Fenster und Böden) und mit sämtlichen Schlüsseln zurück. Vom Mieter eingebrachte Einrichtungen sind zu entfernen und der ursprüngliche Zustand ist fachgerecht wiederherzustellen, sofern der Vermieter sie nicht gegen angemessene Entschädigung übernimmt. Bis zur Rückgabe, mindestens bis zum Vertragsende, bestehen die Heiz- und Obhutspflichten fort. Über die Rückgabe wird ein gemeinsames Protokoll erstellt.' );

		$H( '§ 20 Schlussbestimmungen' );
		$P( 'Mündliche Nebenabreden bestehen nicht. Änderungen und Ergänzungen des Vertrags bedürfen der Textform. Vorübergehende unentgeltliche Gestattungen des Vermieters sind jederzeit widerruflich. Sollte eine Bestimmung unwirksam sein, bleibt der Vertrag im Übrigen wirksam; an ihre Stelle tritt die gesetzliche Regelung. Ändern sich gesetzliche Bestimmungen, gelten die neuen Regelungen, soweit sie zwingend sind.' );
		if ( $o['energieausweis'] ) {
			$P( 'Energieausweis (§ 80 GEG): ' . esc_html( $o['energieausweis'] ) . '. Der Energieausweis wurde dem Mieter vor Vertragsschluss vorgelegt und wird in Kopie übergeben.' );
		}
		$P( 'Die Wohnung ist nach Art. 46 Abs. 4 BayBO mit Rauchwarnmeldern ausgestattet. Die Sicherstellung der Betriebsbereitschaft (Funktionsprüfung, Batteriewechsel) obliegt dem Mieter, soweit nicht der Vermieter die Wartung übernommen und die Kosten als Betriebskosten umgelegt hat.' );
		if ( $o['sonstiges'] ) {
			$P( '<strong>Sonstige Vereinbarungen:</strong> ' . nl2br( esc_html( $o['sonstiges'] ) ) );
		}
		$anlagen = [ 'Übergabeprotokoll', 'Datenschutzinformation nach Art. 13 DSGVO' ];
		if ( $o['hausordnung'] === '1' ) {
			$anlagen[] = 'Hausordnung';
		}
		if ( $o['merkblatt'] === '1' ) {
			$anlagen[] = 'Merkblatt Heizen und Lüften';
		}
		if ( $o['zahlungsweise'] === 'sepa' ) {
			$anlagen[] = 'SEPA-Lastschriftmandat';
		}
		$P( 'Anlagen: ' . esc_html( implode( ', ', $anlagen ) ) . '.' );
		echo self::unterschriften( $ort, $m ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.

		echo '<div class="umbruch"></div>' . self::datenschutz_html( $vermieter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		if ( $o['hausordnung'] === '1' ) {
			echo '<div class="umbruch"></div>' . self::hausordnung_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		}
		if ( $o['merkblatt'] === '1' ) {
			echo '<div class="umbruch"></div>' . self::merkblatt_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		}
		self::fuss();
	}

	private static function hausordnung_html(): string {
		return '<h1>Hausordnung</h1>
<p><strong>Ruhezeiten:</strong> 22–7 Uhr sowie 13–15 Uhr; an Sonn- und Feiertagen ganztägig Zimmerlautstärke. Musizieren außerhalb der Ruhezeiten täglich höchstens zwei Stunden. Türenschlagen, Lärm auf Treppen und erschütternde Arbeiten sind zu vermeiden; handwerkliche Arbeiten mit Lärm nur werktags 7–19 Uhr.</p>
<p><strong>Reinigung:</strong> Treppenhaus, Hauseingang, Zugangswege, Keller- und Speicherflure werden – soweit kein Dienstleister beauftragt ist – im wöchentlichen Wechsel durch die Mietparteien gereinigt; Winterdienst nach dem im Haus ausgehängten Plan. Jede Partei beseitigt von ihr verursachte Verschmutzungen sofort.</p>
<p><strong>Sicherheit:</strong> Haustür ab 22 Uhr geschlossen halten. Flucht- und Rettungswege (Treppenhaus, Flure, Kellergänge) freihalten – keine Fahrräder, Kinderwagen, Schuhe oder Möbel außerhalb der dafür vorgesehenen Plätze. Keine Lagerung brennbarer oder explosiver Stoffe in Keller, Speicher und Wohnung. Keller und Speicher nicht mit offenem Feuer betreten. Das Laden von Akkus (E-Bikes, E-Scooter) an gemeinschaftlichen Stromquellen ist untersagt.</p>
<p><strong>Lüften und Heizen:</strong> Mehrmals täglich stoßlüften, keine dauerhafte Kippstellung in der Heizperiode, Räume auch bei Abwesenheit ausreichend temperieren (mindestens 16 °C), Möbel mit Abstand zu Außenwänden stellen. Bei Frost Kellerfenster schließen und Außenwasserhähne entleeren.</p>
<p><strong>Abfall:</strong> Mülltrennung nach den Vorgaben des Landkreises; Standplätze sauber halten; Sperrmüll nur zu den Abholterminen bereitstellen, nicht im Hof oder Keller lagern.</p>
<p><strong>Balkone und Fenster:</strong> Nichts ausschütten, ausgießen oder hinauswerfen. Grillen nur mit Elektro- oder Gasgrill ohne Belästigung der Nachbarn. Blumenkästen sicher an der Innenseite befestigen. Wäsche nicht tropfnass und nur an den vorgesehenen Plätzen trocknen. Balkone von Schnee freihalten, Abflüsse freihalten.</p>
<p><strong>Rauchen:</strong> In gemeinschaftlich genutzten Räumen (Treppenhaus, Keller, Speicher, Waschküche) ist Rauchen – einschließlich E-Zigaretten und cannabishaltiger Produkte – untersagt.</p>
<p><strong>Bohren und Nageln:</strong> Vor dem Bohren in Wände, Böden und Decken ist der Verlauf von Leitungen zu prüfen bzw. beim Vermieter zu erfragen; Schäden aus Nichtbeachtung trägt der Mieter.</p>
<p><strong>Fahrzeuge und Haustiere:</strong> Das Abstellen von Fahrzeugen auf dem Grundstück nur auf zugewiesenen Plätzen. Hunde im Haus und auf dem Grundstück anleinen, Verunreinigungen sofort beseitigen.</p>
<p><strong>Änderungen:</strong> Der Vermieter kann die Hausordnung zur ordnungsgemäßen Verwaltung nach billigem Ermessen durch schriftliche Erklärung anpassen.</p>';
	}

	/** Eigenes Merkblatt zum Vermeiden von Feuchtigkeits- und Schimmelschäden. */
	private static function merkblatt_html(): string {
		return '<h1>Merkblatt: Richtig heizen und lüften</h1>
<p>Feuchtigkeit entsteht in jeder Wohnung – beim Duschen, Kochen, Wäschetrocknen und durch die Bewohner selbst (zwei bis drei Liter Wasser täglich in einem Zwei-Personen-Haushalt). Wird sie nicht abgeführt, schlägt sie sich an kühlen Wandflächen nieder, und ab etwa 70 % relativer Luftfeuchte an der Wandoberfläche kann Schimmel wachsen. Ein Thermo-Hygrometer (wenige Euro) macht Temperatur und Luftfeuchte sichtbar; angenehm und unkritisch sind 19–22 °C bei 40–60 % Luftfeuchte.</p>
<h2>Lüften</h2>
<p><strong>Stoßlüften statt Kippen:</strong> Fenster zwei- bis viermal täglich für 5–10 Minuten ganz öffnen, am besten gegenüberliegende Fenster zugleich (Querlüften). Dauerhaft gekippte Fenster in der Heizperiode kühlen die Wand über dem Fenster aus und begünstigen dort Schimmel – und sie verschwenden Heizenergie.</p>
<p><strong>Feuchte sofort abführen:</strong> Nach dem Duschen oder Kochen unmittelbar lüften und dabei die Tür zu den übrigen Räumen schließen, damit sich die feuchte Luft nicht in kühleren Räumen niederschlägt. Wird Wäsche in der Wohnung getrocknet, zusätzlich lüften.</p>
<p><strong>Keller und kühle Räume:</strong> Im Sommer nur in den kühlen Morgen- oder Abendstunden lüften; warme, feuchte Außenluft kondensiert an kalten Kellerwänden.</p>
<h2>Heizen</h2>
<p><strong>Alle Räume temperieren:</strong> Auch wenig genutzte Räume nicht unter 16 °C auskühlen lassen und Türen zu kühleren Räumen geschlossen halten – kühle Räume nicht mit der Luft aus warmen Räumen „mitheizen“, denn genau dabei kondensiert Feuchtigkeit.</p>
<p><strong>Beim Lüften Heizung herunterdrehen</strong> (Thermostat auf Frostschutz), danach wieder aufdrehen. Bei Abwesenheit und nachts darf abgesenkt werden, aber nicht unter 16 °C.</p>
<p><strong>Heizkörper frei halten:</strong> Keine Verkleidungen, Möbel oder lange Vorhänge vor Heizkörpern; Thermostatventile müssen die Raumluft erfassen können.</p>
<h2>Möbel und Wände</h2>
<p>Große Möbel (Schränke, Betten, Sofas) an Außenwänden mit mindestens 10 cm Abstand aufstellen, damit Luft zirkulieren kann; besser an Innenwände stellen. Nach Modernisierungen (neue Fenster, Dämmung) ist die Wohnung dichter – dann häufiger lüften als zuvor.</p>
<p class="klein">Zeigen sich dennoch feuchte Stellen oder Schimmel, bitte den Vermieter sofort informieren (§ 536c BGB), damit die Ursache geklärt und beseitigt werden kann.</p>';
	}

	private static function datenschutz_html( string $vermieter ): string {
		$E = Mietv_Einstellungen::class;
		return '<h1>' . esc_html__( 'Datenschutzinformation nach Art. 13 DSGVO', 'mietverwaltung' ) . '</h1>
<p>' . '<strong>' . esc_html__( 'Verantwortlicher:', 'mietverwaltung' ) . '</strong> ' . esc_html( $vermieter ) . ( $E::get( 'vermieter_email' ) ? ', E-Mail ' . esc_html( $E::get( 'vermieter_email' ) ) : '' ) . ( $E::get( 'vermieter_telefon' ) ? ', Telefon ' . esc_html( $E::get( 'vermieter_telefon' ) ) : '' ) . '. Ein Datenschutzbeauftragter ist nicht bestellt (nicht erforderlich, Art. 37 DSGVO).</p>
<p><strong>Zwecke und Rechtsgrundlagen:</strong> Anbahnung, Durchführung und Beendigung des Mietverhältnisses einschließlich Mietbuchhaltung, Betriebskostenabrechnung, Mängelbearbeitung und Schriftverkehr (Art. 6 Abs. 1 lit. b DSGVO); Erfüllung gesetzlicher Pflichten, insbesondere steuerliche Aufbewahrung und Abrechnungspflichten (Art. 6 Abs. 1 lit. c DSGVO i. V. m. § 147 AO, § 556 BGB); Wahrung berechtigter Interessen wie Geltendmachung von Ansprüchen und Hausverwaltung (Art. 6 Abs. 1 lit. f DSGVO).</p>
<p><strong>Datenkategorien:</strong> Name, Anschrift, Geburtsdatum, Kontaktdaten, Bankverbindung, Personenzahl im Haushalt, Vertrags- und Zahlungsdaten, Verbrauchsdaten (Zählerstände), Meldungen und Schriftverkehr, Zugangsdaten zum Mieterportal.</p>
<p><strong>Empfänger:</strong> Soweit für die Durchführung des Mietverhältnisses erforderlich: Finanzamt und Steuerberater (Einkünfte aus Vermietung), Kreditinstitut (Zahlungsverkehr), Energieversorger, Messdienstleister und Abrechnungsunternehmen (Verbrauchsdaten für Heiz- und Betriebskostenabrechnung), bei Eigentumswohnungen die WEG-Verwaltung (Name, Verbrauchsdaten, Umlagerelevantes), Handwerker und Dienstleister (Name und Kontaktdaten zur Terminvereinbarung), Gebäude- und Haftpflichtversicherer bei Schadensfällen, Rechtsanwalt, Gericht oder Inkasso bei Streitigkeiten, Meldebehörde (Wohnungsgeberbestätigung), Hosting-Anbieter der Verwaltungssoftware als Auftragsverarbeiter (Art. 28 DSGVO). Anderen Mietern wird auf Verlangen Einsicht in die Abrechnungsbelege gewährt, soweit dies zur Prüfung ihrer Abrechnung erforderlich ist. Eine Übermittlung in Drittländer findet nicht statt.</p>
<p><strong>Speicherdauer:</strong> Für die Dauer des Mietverhältnisses und anschließend ' . (int) $E::get( 'aufbewahrung_jahre', 10 ) . ' Jahre ab Ende des Kalenderjahres der Beendigung (steuerliche Aufbewahrungsfrist); danach werden die Daten anonymisiert bzw. gelöscht. Daten von Mietinteressenten, mit denen kein Vertrag zustande kommt, werden spätestens sechs Monate nach der Entscheidung gelöscht (Nachweiszwecke nach dem Allgemeinen Gleichbehandlungsgesetz).</p>
<p><strong>Ihre Rechte:</strong> Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20), Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21) sowie Widerruf einer erteilten Einwilligung mit Wirkung für die Zukunft (Art. 7 Abs. 3). Auskünfte werden unverzüglich, spätestens innerhalb eines Monats erteilt (Art. 12 Abs. 3). Beschwerderecht bei einer Aufsichtsbehörde, in Bayern: Bayerisches Landesamt für Datenschutzaufsicht (BayLDA), Promenade 18, 91522 Ansbach, www.lda.bayern.de.</p>
<p><strong>Bereitstellungspflicht:</strong> Die Angabe der für den Vertrag erforderlichen Daten ist Voraussetzung für den Abschluss und die Durchführung des Mietvertrags. Eine automatisierte Entscheidungsfindung findet nicht statt.</p>
<p class="klein">Erhalten am ____________ &nbsp;&nbsp; Unterschrift Mieter: ______________________</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Übergabeprotokoll                                                  */
	/* ------------------------------------------------------------------ */

	public static function uebergabe(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'mietverwaltung' ) );
		}
		global $wpdb;
		$mv_id = (int) $_GET['mv_id'];
		check_admin_referer( 'mietv_uebergabe_' . $mv_id );
		$art = ( $_GET['art'] ?? 'einzug' ) === 'auszug' ? __( 'Auszug', 'mietverwaltung' ) : __( 'Einzug', 'mietverwaltung' );
		$d   = self::daten( $mv_id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Nicht gefunden.', 'mietverwaltung' ) );
		}
		[ 'mv' => $mv, 'mieter' => $m, 'einheit' => $e, 'objekt' => $ob, 'opt' => $o, 'zusatz' => $zusatz ] = $d;
		$zusatz_ids = array_map( fn( $z ) => (int) $z->einheit_id, $zusatz );
		$E          = Mietv_Einstellungen::class;
		$ort        = $ob->ort;

		self::kopf( 'Uebergabeprotokoll ' . $art . ' ' . $m->nachname, [ $ob->id, $mv->id, 'uebergabeprotokoll', 'Übergabeprotokoll ' . $art, true ] );
		printf(
			'<h1>' . esc_html__( 'Übergabeprotokoll – %s', 'mietverwaltung' ) . '</h1><p>' . esc_html__( 'Wohnung', 'mietverwaltung' ) . ' <strong>%s</strong>, %s, %s %s<br>' . esc_html__( 'Vermieter: %s', 'mietverwaltung' ) . '<br>' . esc_html__( 'Mieter: %s', 'mietverwaltung' ) . '<br>' . esc_html__( 'Datum der Übergabe:', 'mietverwaltung' ) . ' <span class="feld"></span> &nbsp; ' . esc_html__( 'Uhrzeit:', 'mietverwaltung' ) . ' <span class="feld"></span></p>',
			esc_html( $art ),
			esc_html( $e->bezeichnung ),
			esc_html( $ob->strasse ),
			esc_html( $ob->plz ),
			esc_html( $ob->ort ),
			esc_html( $E::get( 'vermieter_name' ) ),
			esc_html( implode( ', ', array_merge( mietv_mieter_personen( $m ), $o['mitmieter'] ? [ $o['mitmieter'] ] : [] ) ) )
		);

		echo '<h2>' . esc_html__( '1. Zählerstände', 'mietverwaltung' ) . '</h2><table><tr><th>' . esc_html__( 'Zähler', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Nummer', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Stand', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Stand laut System', 'mietverwaltung' ) . '</th></tr>';
		$zaehler = $wpdb->get_results( $wpdb->prepare( 'SELECT z.*, (SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende s WHERE s.zaehler_id = z.id ORDER BY datum DESC LIMIT 1) AS letzter, (SELECT datum FROM ' . $wpdb->prefix . 'mv_zaehlerstaende s WHERE s.zaehler_id = z.id ORDER BY datum DESC LIMIT 1) AS letzter_datum FROM ' . $wpdb->prefix . 'mv_zaehler z WHERE z.einheit_id IN (' . implode( ',', array_map( 'intval', array_merge( [ $e->id ], $zusatz_ids ) ) ) . ') OR (z.einheit_id IS NULL AND z.objekt_id = %d) ORDER BY z.einheit_id, z.typ', $ob->id ) );  // phpcs:ignore WordPress.DB.PreparedSQL -- IN-Platzhalter aus count() erzeugt, IDs sind int.
		$typen   = [
			'kaltwasser' => esc_html__( 'Kaltwasser (m³)', 'mietverwaltung' ),
			'warmwasser' => esc_html__( 'Warmwasser (m³)', 'mietverwaltung' ),
			'heizung'    => esc_html__( 'Heizung (kWh/Einh.)', 'mietverwaltung' ),
			'strom'      => esc_html__( 'Strom (kWh)', 'mietverwaltung' ),
		];
		foreach ( $zaehler as $z ) {
			printf( '<tr><td>%s%s</td><td>%s</td><td><span class="feld" style="min-width:40mm"></span></td><td class="klein">%s</td></tr>', esc_html( $typen[ $z->typ ] ?? $z->typ ), $z->einheit_id ? '' : ' (Haus)', esc_html( $z->nummer ), $z->letzter !== null ? esc_html( number_format( (float) $z->letzter, 2, ',', '.' ) . ' am ' . mietv_date( $z->letzter_datum ) ) : '–' );
		}
		foreach ( [ 'Strom', 'Gas', 'Kaltwasser', 'Warmwasser', 'Heizung' ] as $leer ) {
			printf( '<tr><td>%s</td><td><span class="feld"></span></td><td><span class="feld" style="min-width:40mm"></span></td><td></td></tr>', esc_html( $leer ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( '2. Schlüssel', 'mietverwaltung' ) . '</h2><p>' . esc_html( $o['schluessel'] ) . '</p><p>Übergeben: <span class="feld" style="min-width:120mm"></span></p>';

		echo '<h2>' . esc_html__( '3. Zustand der Räume', 'mietverwaltung' ) . '</h2><p class="klein">' . esc_html__( 'Bewertung: 1 = neuwertig, 2 = gut, 3 = gebraucht/normale Abnutzung, 4 = mangelhaft (Mängel beschreiben). Fotos als Anlage.', 'mietverwaltung' ) . '</p><table><tr><th style="width:25%">' . esc_html__( 'Raum', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Boden', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Wände/Decke', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Fenster/Türen', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Elektrik/Sanitär', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Bemerkungen / Mängel', 'mietverwaltung' ) . '</th></tr>';
		$raeume = array_filter( array_map( 'trim', preg_split( '/[,\n]/', $o['raeume'] ?: 'Wohnzimmer, Schlafzimmer, Küche, Bad, Flur, Balkon' ) ) );
		foreach ( array_merge( $raeume, [ 'Keller / Nebenraum', 'Hausflur / Gemeinschaft' ] ) as $r ) {
			printf( '<tr><td>%s</td><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>', esc_html( $r ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( '4. Ausstattung und Funktionsprüfung', 'mietverwaltung' ) . '</h2><table><tr><th>' . esc_html__( 'Gegenstand', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'vorhanden', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'funktioniert', 'mietverwaltung' ) . '</th><th>' . esc_html__( 'Bemerkung', 'mietverwaltung' ) . '</th></tr>';
		foreach ( array_merge( [ 'Rauchwarnmelder (alle Schlaf-/Kinderzimmer, Flur)', 'Heizung / Thermostate', 'Warmwasser', 'Herd / Backofen', 'Dunstabzug', 'Spüle / Armaturen', 'WC-Spülung', 'Dusche / Badewanne', 'Steckdosen / Lichtschalter', 'Klingel / Gegensprechanlage', 'Briefkasten (Name)', 'Fenster / Rollläden' ], array_filter( array_map( 'trim', preg_split( '/[,\n]/', $o['ausstattung'] ) ) ) ) as $g ) {
			printf( '<tr><td>%s</td><td>' . esc_html__( '☐ ja ☐ nein', 'mietverwaltung' ) . '</td><td>' . esc_html__( '☐ ja ☐ nein', 'mietverwaltung' ) . '</td><td></td></tr>', esc_html( $g ) );
		}
		echo '</table>';

		if ( $art === 'Auszug' ) {
			echo '<h2>' . esc_html__( '5. Rückgabe', 'mietverwaltung' ) . '</h2><p>' . __( '☐ Wohnung vollständig geräumt und besenrein &nbsp; ☐ Alle Schlüssel zurückgegeben &nbsp; ☐ Schönheitsreparaturen fällig/ausgeführt:', 'mietverwaltung' ) . ' <span class="feld"></span></p><p>' . esc_html__( 'Vereinbarte Nacharbeiten durch den Mieter bis', 'mietverwaltung' ) . ' <span class="feld"></span>: <span class="feld" style="min-width:100mm"></span></p><p>' . esc_html__( 'Neue Anschrift des Mieters (für Kautionsabrechnung und Betriebskostenabrechnung):', 'mietverwaltung' ) . ' <span class="feld" style="min-width:120mm"></span></p><p>' . esc_html__( 'Kautionsrückzahlung auf IBAN:', 'mietverwaltung' ) . ' <span class="feld" style="min-width:80mm"></span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		} else {
			echo '<h2>' . esc_html__( '5. Vereinbarungen', 'mietverwaltung' ) . '</h2><p>' . esc_html__( 'Vom Vermieter noch zu erledigen bis', 'mietverwaltung' ) . ' <span class="feld"></span>: <span class="feld" style="min-width:100mm"></span></p><p>' . esc_html__( 'Dem Mieter übergeben: ☐ Energieausweis ☐ Hausordnung ☐ Datenschutzinformation ☐ Bedienungsanleitungen (Heizung, Küche)', 'mietverwaltung' ) . '</p>';
		}
		echo '<p class="klein">' . esc_html__( 'Die Parteien bestätigen die Richtigkeit der Angaben. Nicht vermerkte Mängel gelten als bei Übergabe nicht vorhanden, soweit sie bei der Besichtigung erkennbar waren.', 'mietverwaltung' ) . '</p>';
		echo self::unterschriften( $ort, $m ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		self::fuss();
	}
}
