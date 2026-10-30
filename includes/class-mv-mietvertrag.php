<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Mietvertrag (Wohnraum), Übergabeprotokoll und Datenschutzinformation als druckfertige Dokumente.
 * Eigene Formulierungen nach BGB (Stand 2026); keine Übernahme geschützter Verbandsmuster.
 */
class Mietv_Mietvertrag {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mietv_vertrag', [ __CLASS__, 'drucken' ] );
		add_action( 'admin_post_mietv_uebergabe', [ __CLASS__, 'uebergabe' ] );
		add_action( 'admin_post_mietv_sepa', [ __CLASS__, 'sepa' ] );
	}

	/** SEPA-Basislastschriftmandat für die Miete. */
	public static function sepa(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		check_admin_referer( 'mietv_sepa' );
		Mietv_PDF::ablegen_aus_request( 'mietv_sepa' );
		$mv_id = ( isset( $_GET['mv_id'] ) ? (int) $_GET['mv_id'] : 0 );
		$d     = self::daten( $mv_id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Not found.', 'mietfux' ) );
		}
		[ 'mv' => $mv, 'mieter' => $m, 'einheit' => $e, 'objekt' => $ob, 'k' => $k ] = $d;
		$einst  = Mietv_Einstellungen::class;
		$gl     = $einst::get( 'glaeubiger_id' ) ?: '[Gläubiger-ID in den Einstellungen eintragen]';
		$ref    = 'MIETE-' . str_pad( (string) $mv_id, 6, '0', STR_PAD_LEFT );
		$gesamt = $k ? (float) $k->kaltmiete + (float) $k->bk_vz + (float) $k->hk_vz : 0;
		self::kopf( 'SEPA-Mandat ' . $m->nachname, [ $ob->id, $mv->id, 'sonstiges', 'SEPA-Lastschriftmandat', false ] );
		echo '<h1>' . esc_html__( 'SEPA core direct debit mandate', 'mietfux' ) . '</h1>';
		printf( '<p><strong>' . esc_html__( 'Payee (creditor):', 'mietfux' ) . '</strong> %s, %s<br><strong>' . esc_html__( 'Creditor identifier:', 'mietfux' ) . '</strong> %s<br><strong>' . esc_html__( 'Mandate reference:', 'mietfux' ) . '</strong> %s<br><strong>' . esc_html__( 'Type of payment:', 'mietfux' ) . '</strong> ' . esc_html__( 'recurring payment', 'mietfux' ) . '</p>', esc_html( $einst::get( 'vermieter_name' ) ), esc_html( str_replace( "\n", ', ', $einst::get( 'vermieter_adresse' ) ) ), esc_html( $gl ), esc_html( $ref ) );
		printf( '<p>' . /* translators: %1$s: Wert, %2$s: Wert */ esc_html__( 'I authorise %1$s to collect payments from my account by direct debit. At the same time I instruct my bank to honour the direct debits drawn on my account by %2$s.', 'mietfux' ) . '</p>', esc_html( $einst::get( 'vermieter_name' ) ), esc_html( $einst::get( 'vermieter_name' ) ) );
		echo '<p class="klein">' . esc_html__( 'Note: I may request a refund of the debited amount within eight weeks from the debit date. The terms agreed with my bank apply.', 'mietfux' ) . '</p>';
		printf( '<p><strong>' . esc_html__( 'Purpose:', 'mietfux' ) . '</strong> ' . /* translators: %1$s: Wert, %2$s: Wert, %3$s: Wert, %4$s: Wert, %5$s: Wert, %6$d: Wert */ esc_html__( 'Monthly rent including advance payments for the flat %1$s, %2$s, %3$s %4$s – currently %5$s, due on the %6$d. working day of the month. Changes to the amount (rent adjustment, new advance payments) will be notified at least 14 days before collection.', 'mietfux' ) . '</p>', esc_html( $e->bezeichnung ), esc_html( $ob->strasse ), esc_html( $ob->plz ), esc_html( $ob->ort ), esc_html( mietv_eur( $gesamt ) ), (int) max( 1, min( 3, (int) $mv->zahltag ) ) );
		printf(
			'<table><tr><td style="width:35%%">' . esc_html__( 'Account holder', 'mietfux' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'Street, house number', 'mietfux' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'Postcode, town', 'mietfux' ) . '</td><td>%s %s</td></tr><tr><td>' . esc_html__( 'Credit institution', 'mietfux' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'IBAN', 'mietfux' ) . '</td><td>%s</td></tr><tr><td>' . esc_html__( 'BIC', 'mietfux' ) . '</td><td>%s</td></tr></table>',
			esc_html( implode( ' / ', array_map( fn( $z ) => preg_replace( '/^(Frau|Herr) /', '', $z ), mietv_mieter_personen( $m ) ) ) ),
			esc_html( $ob->strasse ),
			esc_html( $ob->plz ),
			esc_html( $ob->ort ),
			'<span class="feld" style="min-width:80mm"></span>',
			esc_html( $m->iban ?: '' ) . ( $m->iban ? '' : '<span class="feld" style="min-width:80mm"></span>' ),
			'<span class="feld" style="min-width:60mm"></span>'
		);
		echo wp_kses_post( self::unterschriften( $ob->ort, $m ) );
		self::fuss();
	}

	public static function optionen_default(): array {
		return [
			'mitmieter'              => '',
			'raeume'                 => '',
			'nebenraeume'            => esc_html__( 'Cellar compartment', 'mietfux' ),
			'ausstattung'            => '',
			'schluessel'             => esc_html__( '2 front door keys, 2 flat keys, 1 letterbox key', 'mietfux' ),
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
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_vertrag_nonce'] ?? '' ) ), 'mietv_vertrag' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		global $wpdb;
		$mv_id = ( isset( $_POST['mv_id'] ) ? (int) $_POST['mv_id'] : 0 );
		$o     = [];
		foreach ( self::optionen_default() as $k => $d ) {
			$raw     = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $d; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- in der Folgezeile bereinigt.
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
		mietv_flash_set( 'saved' );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_mietvertrag&mv_id=' . $mv_id ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		global $wpdb;
		$mvs   = Mietv_Soll::alle_mv();
		$mv_id = ( isset( $_GET['mv_id'] ) ? (int) $_GET['mv_id'] : (int) ( $mvs[0]->id ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$o     = self::laden( $mv_id );
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Tenancy agreement', 'mietfux' ) . '</h1>';
		if ( ( mietv_flash_get()['code'] ?? '' ) === 'saved' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Contract options saved.', 'mietfux' ) . '</p></div>';
		}
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Note:', 'mietfux' ) . '</strong> ' . esc_html__( 'The agreement is generated from the tenancy data (parties, flat, rent, deposit, start, end) and the options below. The clauses follow the BGB and Federal Court of Justice case law (as of 2026). Have it checked legally before use in special situations (commercial, shared flat, furnished, rent cap).', 'mietfux' ) . '</p></div>';

		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_mietvertrag"><label>' . esc_html__( 'Tenancy', 'mietfux' ) . ' <select name="mv_id" data-autosubmit="1">';
		foreach ( $mvs as $m ) {
			printf( '<option value="%d"%s>%s</option>', (int) $m->id, selected( (int) $mv_id, (int) $m->id, false ), esc_html( Mietv_Soll::mietv_label( $m ) ) );
		}
		echo '</select></label></form>';
		if ( ! $mv_id ) {
			echo '</div>';
			return;
		}

		$vertrag = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_vertrag&mv_id=' . $mv_id ), 'mietv_vertrag_pdf' );
		$ueberg  = wp_nonce_url( admin_url( 'admin-post.php?action=mietv_uebergabe&mv_id=' . $mv_id ), 'mietv_uebergabe' );
		printf( '<p><a href="%1$s" target="_blank" class="button button-primary">' . esc_html__( 'Tenancy agreement (PDF)', 'mietfux' ) . '</a> <a href="%1$s&ablegen=1" target="_blank" class="button">' . esc_html__( '… file', 'mietfux' ) . '</a> &nbsp; <a href="%2$s&art=einzug" target="_blank" class="button">' . esc_html__( 'Handover protocol move-in', 'mietfux' ) . '</a> <a href="%2$s&art=einzug&ablegen=1" target="_blank" class="button">' . esc_html__( '… file', 'mietfux' ) . '</a> &nbsp; <a href="%2$s&art=auszug" target="_blank" class="button">' . esc_html__( 'Handover protocol move-out', 'mietfux' ) . '</a> &nbsp; <a href="%1$s&nur=datenschutz" target="_blank" class="button">' . esc_html__( 'Privacy notice', 'mietfux' ) . '</a> &nbsp; <a href="%3$s" target="_blank" class="button">' . esc_html__( 'SEPA direct debit mandate', 'mietfux' ) . '</a> &nbsp; <a href="%1$s&format=html" target="_blank" class="button-link">' . esc_html__( 'HTML view', 'mietfux' ) . '</a></p>', esc_url( $vertrag ), esc_url( $ueberg ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mietv_sepa&mv_id=' . $mv_id ), 'mietv_sepa' ) ) );

		echo '<form method="post" class="mv-form">';
		wp_nonce_field( 'mietv_vertrag', 'mietv_vertrag_nonce' );
		printf( '<input type="hidden" name="mv_id" value="%d">', (int) $mv_id );
		echo '<h2>' . esc_html__( 'Contract options', 'mietfux' ) . '</h2><table class="form-table"><tbody>';
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
		$t( 'mitmieter', __( 'Additional tenants (name, date of birth)', 'mietfux' ), __( 'For third parties only – the spouse/partner stored with the tenant is included automatically. All tenants are jointly and severally liable.', 'mietfux' ) );
		$t( 'raeume', __( 'Rooms', 'mietfux' ), __( 'e.g. 3 rooms, kitchen, bathroom with WC, hallway, balcony', 'mietfux' ), 'area' );
		$t( 'nebenraeume', __( 'Ancillary rooms / parking', 'mietfux' ) );
		$t( 'ausstattung', __( 'Fixtures included', 'mietfux' ), __( 'e.g. fitted kitchen (manufacturer, year), washing machine connection', 'mietfux' ), 'area' );
		$t( 'schluessel', __( 'Keys handed over', 'mietfux' ), '', 'area' );
		$t( 'befristung_grund', __( 'Reason for fixed term (only with end date)', 'mietfux' ), __( 'Fixed-term agreement only with a reason under § 575 BGB: own use, demolition/conversion, company flat. Without a reason the agreement is deemed open-ended.', 'mietfux' ), 'area' );
		$s(
			'mietart',
			__( 'Rent type', 'mietfux' ),
			[
				'standard' => esc_html__( 'Reference rent (increase under § 558)', 'mietfux' ),
				'staffel'  => esc_html__( 'Stepped rent (§ 557a)', 'mietfux' ),
				'index'    => esc_html__( 'Index-linked rent (§ 557b)', 'mietfux' ),
			]
		);
		$t( 'staffeln', __( 'Steps (for stepped rent)', 'mietfux' ), __( 'One per line: date;base rent – e.g. 2027-01-01;650.00. At least one year between steps.', 'mietfux' ), 'area' );
		$s(
			'kaution_raten',
			__( 'Deposit payable in', 'mietfux' ),
			[
				'1' => esc_html__( 'one sum', 'mietfux' ),
				'3' => esc_html__( 'three monthly instalments (§ 551 (2))', 'mietfux' ),
			],
			__( 'Maximum three months\' net base rent; amount from the tenancy.', 'mietfux' )
		);
		$t( 'kleinrep_einzel', __( 'Minor repairs: maximum per case (€)', 'mietfux' ), __( 'Case law: approx. €100–150. Only parts frequently accessed directly by the tenant.', 'mietfux' ) );
		$t( 'kleinrep_jahr', __( 'Minor repairs: maximum per year (% of annual base rent)', 'mietfux' ), __( 'Usually 6–8 %, often additionally capped (here: and at most 8 cases × single amount).', 'mietfux' ) );
		$s(
			'schoenheitsrep',
			__( 'Condition at handover', 'mietfux' ),
			[
				'renoviert'                    => esc_html__( 'renovated – tenant bears decorative repairs as needed', 'mietfux' ),
				__( 'unrenovated', 'mietfux' ) => __( 'unrenovated – no decorative repairs clause (BGH VIII ZR 185/14)', 'mietfux' ),
			]
		);
		$s(
			'tiere',
			__( 'Pets', 'mietfux' ),
			[
				'erlaubnis' => esc_html__( 'Small animals allowed, otherwise consent (default)', 'mietfux' ),
				'frei'      => esc_html__( 'Pets permitted', 'mietfux' ),
			],
			__( 'A blanket ban is invalid (BGH VIII ZR 168/12).', 'mietfux' )
		);
		$s( 'untervermietung', __( 'Subletting', 'mietfux' ), [ 'erlaubnis' => __( 'only with consent (§ 540), legitimate interest under § 553 remains', 'mietfux' ) ] );
		$s(
			'kuendigungsverzicht',
			__( 'Mutual waiver of termination', 'mietfux' ),
			[
				'0' => esc_html__( 'none', 'mietfux' ),
				'1' => esc_html__( '1 year', 'mietfux' ),
				'2' => esc_html__( '2 years', 'mietfux' ),
				'3' => esc_html__( '3 years', 'mietfux' ),
				'4' => esc_html__( '4 years (maximum)', 'mietfux' ),
			],
			__( 'In standard-form contracts at most 4 years from signing (BGH VIII ZR 86/10).', 'mietfux' )
		);
		$s(
			'rauchen',
			__( 'Smoking in the flat', 'mietfux' ),
			[
				'nein' => esc_html__( 'Please refrain (cannot be bindingly prohibited)', 'mietfux' ),
				'ja'   => esc_html__( 'no provision', 'mietfux' ),
			]
		);
		$s(
			'hausordnung',
			__( 'House rules as annex', 'mietfux' ),
			[
				'1' => esc_html__( 'yes', 'mietfux' ),
				'0' => esc_html__( 'no', 'mietfux' ),
			]
		);
		$t( 'energieausweis', __( 'Energy certificate', 'mietfux' ), __( 'Type (demand/consumption), value kWh/(m²·a), efficiency class, issue date – must be presented under § 80 GEG.', 'mietfux' ) );
		$t( 'vormiete', __( 'Previous rent (rent cap areas only, § 556g (1a))', 'mietfux' ), __( 'Only in areas covered by the Bavarian Tenant Protection Ordinance: disclosure of the previous rent if the rent exceeds the reference rent + 10 %.', 'mietfux' ) );
		$t( 'mitbenutzung', __( 'Shared use rights (communal rooms)', 'mietfux' ), __( 'Laundry room, drying room, bicycle cellar, garden – per house rules.', 'mietfux' ) );
		$t( 'mietereigentum', __( 'Tenant\'s property / not included', 'mietfux' ), __( 'Items the tenant took over from the previous tenant or that belong to them.', 'mietfux' ) );
		$s(
			'moeblierung',
			__( 'Furnishing surcharge', 'mietfux' ),
			[
				'0' => esc_html__( 'no', 'mietfux' ),
				'1' => esc_html__( 'yes – included in base rent, state amount under “Other agreements”', 'mietfux' ),
			]
		);
		$s(
			'bk_art',
			__( 'Service charges', 'mietfux' ),
			[
				'vorauszahlung' => esc_html__( 'Advance payment with annual statement (default)', 'mietfux' ),
				'pauschale'     => esc_html__( 'Flat rate without statement (§ 556 (2) BGB)', 'mietfux' ),
			],
			__( 'With a flat rate there is no statement and no additional claim; increase only for increased costs by declaration in text form (§ 560 (1)).', 'mietfux' )
		);
		$s(
			'mpb_ausnahme',
			__( 'Rent cap – exception (tight market only)', 'mietfux' ),
			[
				''               => esc_html__( 'none / not affected', 'mietfux' ),
				'vormiete'       => esc_html__( 'Previous rent was already higher (§ 556e (1))', 'mietfux' ),
				'modernisierung' => esc_html__( 'Modernisation within the last 3 years (§ 556e (2))', 'mietfux' ),
				'umfassend'      => esc_html__( 'First letting after comprehensive modernisation (§ 556f sentence 2)', 'mietfux' ),
				'neubau'         => esc_html__( 'New build, first let after 1 Oct 2014 (§ 556f sentence 1)', 'mietfux' ),
			],
			__( 'The disclosure must be made unprompted in text form before signing (§ 556g (1a) BGB); otherwise the landlord cannot rely on the exception.', 'mietfux' )
		);
		$s(
			'zahlungsweise',
			__( 'Payment method', 'mietfux' ),
			[
				'dauerauftrag' => esc_html__( 'Standing order', 'mietfux' ),
				'sepa'         => esc_html__( 'SEPA direct debit mandate (annex)', 'mietfux' ),
			]
		);
		$t( 'zustand_maengel', __( 'Condition at handover / known defects', 'mietfux' ), __( 'Brief; details in the handover protocol.', 'mietfux' ), 'area' );
		$t( 'arbeiten_vermieter', __( 'Work still to be carried out by the landlord', 'mietfux' ), '', 'area' );
		$t( 'arbeiten_vermieter_bis', __( '… by no later than (date)', 'mietfux' ) );
		$t( 'arbeiten_mieter', __( 'Work to be carried out by the tenant at own expense', 'mietfux' ), __( 'Consideration, if any, under “Other agreements” (e.g. rent-free period).', 'mietfux' ), 'area' );
		$t( 'arbeiten_mieter_bis', __( '… by no later than (date)', 'mietfux' ) );
		$s(
			'gartenpflege',
			__( 'Garden maintenance by the tenant', 'mietfux' ),
			[
				'0' => esc_html__( 'no / by landlord or contractor (service charges)', 'mietfux' ),
				'1' => esc_html__( 'yes – tenant maintains the garden area assigned to them', 'mietfux' ),
			]
		);
		$s(
			'merkblatt',
			__( 'Heating and ventilation leaflet as annex', 'mietfux' ),
			[
				'1' => esc_html__( 'yes', 'mietfux' ),
				'0' => esc_html__( 'no', 'mietfux' ),
			]
		);
		$t( 'sonstiges', __( 'Other agreements', 'mietfux' ), '', 'area' );
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
		$einst = Mietv_Einstellungen::class;
		if ( ( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'pdf' ) !== 'html' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
			self::$pdf_titel  = $titel;
			self::$pdf_ablage = $ablage;
			ob_start();
			return;
		}
		?>
<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title><?php echo esc_html( $titel ); ?></title>
		<?php
		wp_enqueue_style( 'mietv-dokument-vertrag', MIETV_URL . 'assets/dokument-vertrag.css', [], MIETV_VERSION );
		wp_enqueue_script( 'mietv-dokument', MIETV_URL . 'assets/dokument.js', [], MIETV_VERSION, true );
		wp_print_styles( 'mietv-dokument-vertrag' );
		?>
</head><body>
<div class="toolbar"><button type="button" data-action="print">Drucken / Als PDF sichern</button> <?php echo esc_html( $titel ); ?></div>
<div class="seite">
		<?php
	}

	private static function fuss(): void {
		if ( self::$pdf_titel !== null ) {
			$html  = ob_get_clean();
			$bytes = Mietv_PDF::dokument( $html, Mietv_Brief::absender_zeile() );
			if ( self::$pdf_ablage && Mietv_PDF::ablegen_gewuenscht() ) {
				[ $objekt_id, $mv_id, $typ, $bez, $sichtbar ] = self::$pdf_ablage;
				Mietv_PDF::ablegen( $bytes, self::$pdf_titel . '.pdf', (int) $objekt_id, $mv_id ? (int) $mv_id : null, $typ, $bez, (bool) $sichtbar );
			}
			Mietv_PDF::senden( $bytes, self::$pdf_titel . '.pdf' );
		}
		echo '</div>';
		wp_print_scripts( 'mietv-dokument' );
		echo '</body></html>';
		exit;
	}

	private static function unterschriften( string $ort, $m = null ): string {
		$h = '<div class="unterschrift"><div>' . esc_html( $ort ) . ', den ____________<br><br><br>' . esc_html__( 'Landlord', 'mietfux' ) . '</div>';
		if ( $m && mietv_hat_partner( $m ) ) {
			foreach ( mietv_mieter_personen( $m ) as $z ) {
				$h .= '<div>' . esc_html( $ort ) . ', den ____________<br><br><br>' . esc_html__( 'Tenant', 'mietfux' ) . ': ' . esc_html( $z ) . '</div>';
			}
		} else {
			$h .= '<div>' . esc_html( $ort ) . ', den ____________<br><br><br>' . esc_html__( 'Tenant(s)', 'mietfux' ) . '</div>';
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
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		check_admin_referer( 'mietv_vertrag_pdf' );
		Mietv_PDF::ablegen_aus_request( 'mietv_vertrag_pdf' );
		$mv_id = ( isset( $_GET['mv_id'] ) ? (int) $_GET['mv_id'] : 0 );
		$d     = self::daten( $mv_id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Tenancy not found.', 'mietfux' ) );
		}
		[ 'mv' => $mv, 'mieter' => $m, 'einheit' => $e, 'objekt' => $ob, 'k' => $k, 'opt' => $o, 'zusatz' => $zusatz ] = $d;
		$einst       = Mietv_Einstellungen::class;
		$vermieter   = $einst::get( 'vermieter_name' ) . ', ' . str_replace( "\n", ', ', $einst::get( 'vermieter_adresse' ) );
		$typen_label = [
			'garage'     => esc_html__( 'Garage', 'mietfux' ),
			'stellplatz' => esc_html__( 'Parking space', 'mietfux' ),
			'keller'     => esc_html__( 'Cellar/storage', 'mietfux' ),
			'gewerbe'    => esc_html__( 'Commercial space', 'mietfux' ),
			'wohnung'    => esc_html__( 'Flat', 'mietfux' ),
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
		$absatz       = function ( string $t ): void {
			echo '<p>' . wp_kses_post( $t ) . '</p>';
		};
		$ueberschrift = function ( string $t ): void {
			echo '<h2>' . esc_html( $t ) . '</h2>';
		};

		if ( ( isset( $_GET['nur'] ) ? sanitize_key( $_GET['nur'] ) : '' ) === 'datenschutz' ) {
			self::kopf( 'Datenschutzinformation ' . $m->nachname, [ $ob->id, $mv->id, 'sonstiges', 'Datenschutzinformation Art. 13', true ] );
			echo wp_kses_post( self::datenschutz_html( $vermieter ) );
			self::fuss();
		}

		self::kopf( 'Mietvertrag ' . $m->nachname, [ $ob->id, $mv->id, 'mietvertrag', 'Mietvertrag ' . mietv_date( $mv->beginn ), true ] );
		echo '<h1>' . esc_html__( 'Residential tenancy agreement', 'mietfux' ) . '</h1>';

		$ueberschrift( '§ 1 Vertragsparteien' );
		$absatz( 'zwischen<br><strong>' . esc_html( $vermieter ) . '</strong>' . ( $einst::get( 'vermieter_steuernr' ) ? ', Steuer-Nr. ' . esc_html( $einst::get( 'vermieter_steuernr' ) ) : '' ) . ( $einst::get( 'vermieter_vertreter' ) ? ', vertreten durch ' . esc_html( $einst::get( 'vermieter_vertreter' ) ) : '' ) . ' – nachfolgend „Vermieter“ –<br>und<br><strong>' . esc_html( $mieter ) . '</strong> – nachfolgend „Mieter“ –' );
		$absatz( 'Mehrere Mieter haften für alle Verpflichtungen aus diesem Vertrag als Gesamtschuldner. Sie bevollmächtigen sich gegenseitig zur Abgabe und Entgegennahme von Erklärungen, die das Mietverhältnis betreffen; die Vollmacht ist jederzeit widerruflich und gilt nicht für Kündigungen und Aufhebungsverträge. Tatsachen in der Person eines Mieters, die sich auf das Mietverhältnis auswirken, müssen alle Mieter für und gegen sich gelten lassen.' );

		$ueberschrift( '§ 2 Mietsache' );
		$absatz(
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
		$absatz( 'Die Wohnflächenangabe dient der Beschreibung des Mietgegenstands und ist keine Zusicherung; eine Abweichung von bis zu 10 % begründet keine Rechte. Maßgeblich ist die Wohnflächenverordnung.' );
		if ( $o['mitbenutzung'] ) {
			$absatz( 'Der Mieter darf nach Maßgabe der Hausordnung mitbenutzen: ' . esc_html( $o['mitbenutzung'] ) . '.' );
		}
		if ( $o['mietereigentum'] ) {
			$absatz( 'Nicht mitvermietet, sondern Eigentum des Mieters sind: ' . esc_html( $o['mietereigentum'] ) . '.' );
		}
		if ( $zusatz ) {
			$absatz( 'Ist ein Stellplatz, Carport oder eine Garage Teil der Mietsache, darf der Vermieter dessen Lage aus sachlichem Grund ändern, wenn er einen gleichwertigen Ersatz in zumutbarer Entfernung bereitstellt.' );
		}
		$absatz( sprintf( '<strong>Schlüssel:</strong> Übergeben werden %s. Weitere Schlüssel darf der Mieter nur mit Zustimmung des Vermieters anfertigen lassen; sie sind bei Auszug herauszugeben. Gehen Schlüssel durch Verschulden des Mieters verloren, trägt er die Kosten des Ersatzes – bei Schließanlagen auch die Kosten des Austauschs der Anlage, soweit eine missbräuchliche Verwendung nicht ausgeschlossen werden kann.', esc_html( $o['schluessel'] ) ) );

		$ueberschrift( '§ 3 Mietzeit, Kündigung' );
		if ( $befristet && $o['befristung_grund'] ) {
			$absatz( sprintf( 'Das Mietverhältnis beginnt am <strong>%s</strong> und endet am <strong>%s</strong>, ohne dass es einer Kündigung bedarf (Zeitmietvertrag, § 575 BGB). Befristungsgrund: %s. Der Mieter kann frühestens vier Monate vor Ablauf Auskunft verlangen, ob der Grund noch besteht; entfällt er, kann der Mieter eine Verlängerung auf unbestimmte Zeit verlangen.', esc_html( mietv_date( $mv->beginn ) ), esc_html( mietv_date( $mv->ende ) ), esc_html( $o['befristung_grund'] ) ) );
		} else {
			$absatz( sprintf( 'Das Mietverhältnis beginnt am <strong>%s</strong> und läuft auf unbestimmte Zeit. Es gelten die gesetzlichen Kündigungsfristen (§ 573c BGB): für den Mieter drei Monate; für den Vermieter drei Monate, nach fünf und acht Jahren seit Überlassung jeweils drei Monate länger. Die Kündigung bedarf der Schriftform.', esc_html( mietv_date( $mv->beginn ) ) ) );
			if ( (int) $o['kuendigungsverzicht'] > 0 ) {
				$absatz( sprintf( 'Beide Parteien verzichten für <strong>%d Jahr(e) ab Vertragsschluss</strong> auf das Recht zur ordentlichen Kündigung; eine ordentliche Kündigung ist erstmals zum Ablauf dieses Zeitraums zulässig. Das Recht zur außerordentlichen Kündigung bleibt unberührt.', (int) $o['kuendigungsverzicht'] ) );
			}
		}
		$absatz( 'Setzt der Mieter den Gebrauch nach Ablauf der Mietzeit fort, verlängert sich das Mietverhältnis nicht stillschweigend; § 545 BGB wird ausgeschlossen. Gibt der Mieter die Mietsache nach Beendigung nicht zurück, schuldet er für die Dauer der Vorenthaltung als Entschädigung die vereinbarte Miete, mindestens die ortsübliche Vergleichsmiete (§ 546a BGB); weitergehende Ansprüche bleiben vorbehalten.' );

		$ueberschrift( '§ 4 Miete, Betriebskosten' );
		$pauschale = $o['bk_art'] === 'pauschale';
		if ( $anteile ) {
			$teile          = implode( ', ', array_map( fn( $z ) => mietv_eur( $z->mietanteil ) . ' ' . ( $typen_label[ $z->typ ] ?? $z->typ ) . ' ' . $z->bezeichnung, $anteile ) );
			$wohnung_anteil = $kalt - array_sum( array_map( fn( $z ) => (float) $z->mietanteil, $anteile ) );
			$absatz( sprintf( 'Die monatliche Grundmiete (Nettokaltmiete) beträgt insgesamt <strong>%s</strong>; davon entfallen %s auf die Wohnung und %s. Wohnung und mitvermietete Einheiten bilden ein einheitliches Mietverhältnis und können nur gemeinsam gekündigt werden.', esc_html( mietv_eur( $kalt ) ), esc_html( mietv_eur( $wohnung_anteil ) ), esc_html( $teile ) ) );
		} elseif ( $zusatz ) {
			$absatz( sprintf( 'Die monatliche Grundmiete (Nettokaltmiete) für Wohnung und mitvermietete Einheiten beträgt insgesamt <strong>%s</strong>. Sie bilden ein einheitliches Mietverhältnis und können nur gemeinsam gekündigt werden.', esc_html( mietv_eur( $kalt ) ) ) );
		} else {
			$absatz( sprintf( 'Die monatliche Grundmiete (Nettokaltmiete) beträgt <strong>%s</strong>.', esc_html( mietv_eur( $kalt ) ) ) );
		}
		if ( $o['moeblierung'] === '1' ) {
			$absatz( 'In der Grundmiete ist ein Zuschlag für die mitvermietete Möblierung enthalten.' );
		}
		echo '<table class="liste"><tbody>';
		printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html__( 'Basic rent', 'mietfux' ), esc_html( mietv_eur( $kalt ) ) );
		printf( '<tr><td>%s</td><td>%s</td></tr>', $pauschale ? esc_html__( 'Service charge flat rate (excl. heating/hot water)', 'mietfux' ) : esc_html__( 'Advance payment service charges (excl. heating/hot water)', 'mietfux' ), esc_html( mietv_eur( $bk ) ) );
		if ( $zentral ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html__( 'Advance payment heating and hot water', 'mietfux' ), esc_html( mietv_eur( $hk ) ) );
		}
		printf( '<tr class="summe"><td>%s</td><td>%s</td></tr></tbody></table>', esc_html__( 'Total monthly rent', 'mietfux' ), esc_html( mietv_eur( $gesamt ) ) );
		if ( $pauschale ) {
			$absatz( 'Mit der Betriebskostenpauschale sind die Betriebskosten im Sinne des § 2 BetrKV – mit Ausnahme der Heiz- und Warmwasserkosten – abgegolten; eine Abrechnung findet insoweit nicht statt. Erhöhen sich die Betriebskosten, kann der Vermieter die Pauschale durch Erklärung in Textform anteilig anheben (§ 560 Abs. 1 BGB); Ermäßigungen sind weiterzugeben.' );
		} else {
			$absatz( 'Der Mieter trägt die Betriebskosten im Sinne des § 2 Betriebskostenverordnung (BetrKV) in der jeweils geltenden Fassung, soweit sie für das Objekt anfallen – auch neu entstehende Betriebskosten, soweit ihre Umlage zulässig ist. Hierauf leistet er die vereinbarten monatlichen Vorauszahlungen. Über die Vorauszahlungen rechnet der Vermieter jährlich ab; die Abrechnung ist dem Mieter spätestens zwölf Monate nach Ende des Abrechnungszeitraums mitzuteilen (§ 556 Abs. 3 BGB). Nach einer Abrechnung kann jede Partei die Vorauszahlungen durch Erklärung in Textform auf eine angemessene Höhe anpassen (§ 560 Abs. 4 BGB). Nachzahlungen und Guthaben sind innerhalb von 30 Tagen nach Zugang der Abrechnung auszugleichen. Der Mieter kann die Abrechnungsunterlagen beim Vermieter einsehen.' );
		}
		$absatz( 'Umgelegt werden insbesondere die nachfolgenden Betriebskosten mit dem jeweils genannten Umlageschlüssel. Soweit kein Schlüssel vereinbart ist, erfolgt die Verteilung nach dem Verhältnis der Wohnflächen; verbrauchsabhängig erfasste Kosten nach Verbrauch. Heiz- und Warmwasserkosten werden nach der Heizkostenverordnung abgerechnet' . ( $zentral ? ' (Verbrauchsanteil ' . (int) ( $ob->hk_verbrauchsanteil ?: 70 ) . ' %, Rest nach Wohnfläche)' : '' ) . '.' . ( $etw ? ' Bei Eigentumswohnungen gilt der von der Eigentümergemeinschaft angewandte Umlageschlüssel; die auf die Wohnung entfallende Grundsteuer wird in voller Höhe umgelegt.' : '' ) );
		echo wp_kses_post( self::betrkv_tabelle( $zentral, $etw ) );
		$absatz( 'Sach- und Arbeitsleistungen des Vermieters dürfen mit dem Betrag angesetzt werden, der für eine gleichwertige Leistung eines Dritten angesetzt werden könnte, ohne Umsatzsteuer (§ 1 Abs. 1 Satz 2 BetrKV). Der Mieter schließt ab Übergabe einen eigenen Stromliefervertrag für die Wohnung ab und weist dies auf Verlangen nach; eine Abmeldung vor Ende des Mietverhältnisses ist unzulässig. Der Vermieter ist nicht verpflichtet, Telekommunikationsanschlüsse (Internet, Fernsehen) bereitzustellen oder eine bestehende Versorgung aufrechtzuerhalten.' );
		if ( $o['mietart'] === 'staffel' ) {
			$absatz( '<strong>Staffelmiete (§ 557a BGB):</strong> Die Grundmiete erhöht sich wie folgt; zwischen zwei Staffeln liegt jeweils mindestens ein Jahr:' );
			echo '<table class="liste"><tbody>';
			foreach ( array_filter( array_map( 'trim', explode( "\n", $o['staffeln'] ) ) ) as $z ) {
				[ $dat, $betr ] = array_pad( explode( ';', $z ), 2, '' );
				printf( '<tr><td>ab %s</td><td>%s</td></tr>', esc_html( mietv_date( trim( $dat, " \t\n\r\0\x0B" ) ) ), esc_html( mietv_eur( mietv_to_float( $betr ) ) ) );
			}
			echo '</tbody></table>';
			$absatz( 'Während der Laufzeit der Staffelmiete sind Erhöhungen nach §§ 558 bis 559b BGB ausgeschlossen.' );
		} elseif ( $o['mietart'] === 'index' ) {
			$absatz( '<strong>Indexmiete (§ 557b BGB):</strong> Die Grundmiete ist an den vom Statistischen Bundesamt ermittelten Verbraucherpreisindex für Deutschland gekoppelt. Ändert sich der Index, kann jede Partei eine entsprechende prozentuale Anpassung der Grundmiete verlangen, sofern die Miete seit Vertragsbeginn oder der letzten Änderung mindestens ein Jahr unverändert war. Die Änderung ist in Textform zu erklären; die eingetretene Indexänderung und die neue Miete sind in einem Geldbetrag anzugeben. Die geänderte Miete ist ab Beginn des übernächsten Monats nach Zugang zu zahlen. Erhöhungen nach § 558 BGB sind ausgeschlossen; Erhöhungen nach § 559 BGB nur für Maßnahmen, die der Vermieter nicht zu vertreten hat.' );
		} else {
			$absatz( 'Mieterhöhungen richten sich nach den gesetzlichen Vorschriften (§§ 558 bis 560 BGB).' );
		}
		$mpb = [
			'vormiete'       => 'die vom Vormieter zuletzt geschuldete Miete bereits ' . esc_html( $o['vormiete'] ?: '[Betrag]' ) . ' betrug (§ 556e Abs. 1 BGB)',
			'modernisierung' => 'in den letzten drei Jahren vor Beginn des Mietverhältnisses Modernisierungsmaßnahmen durchgeführt wurden (§ 556e Abs. 2 BGB)',
			'umfassend'      => 'es sich um die erste Vermietung nach einer umfassenden Modernisierung handelt (§ 556f Satz 2 BGB)',
			'neubau'         => 'die Wohnung nach dem 1. Oktober 2014 erstmals genutzt und vermietet wurde (§ 556f Satz 1 BGB)',
		];
		if ( ! empty( $mpb[ $o['mpb_ausnahme'] ] ) ) {
			$absatz( '<strong>Auskunft nach § 556g Abs. 1a BGB:</strong> Die Wohnung liegt in einem Gebiet mit angespanntem Wohnungsmarkt. Soweit die vereinbarte Grundmiete die ortsübliche Vergleichsmiete um mehr als 10 % übersteigt, ist dies zulässig, weil ' . $mpb[ $o['mpb_ausnahme'] ] . '. Diese Auskunft wurde dem Mieter vor Vertragsschluss in Textform erteilt.' );
		} elseif ( $o['vormiete'] ) {
			$absatz( '<strong>Auskunft nach § 556g Abs. 1a BGB:</strong> Die vom Vormieter zuletzt geschuldete Miete betrug ' . esc_html( $o['vormiete'] ) . '.' );
		}

		$ueberschrift( '§ 5 Zahlung, Verzug, Mietsicherheit' );
		$absatz( sprintf( 'Die Gesamtmiete ist monatlich im Voraus, spätestens am <strong>%d. Werktag</strong> des Monats, kostenfrei zu zahlen%s. Für die Rechtzeitigkeit kommt es auf den Eingang beim Vermieter an, es sei denn, die Verzögerung ist vom Mieter nicht zu vertreten. Der Mieter zahlt per %s.', max( 1, min( 3, (int) $mv->zahltag ) ), $einst::get( 'vermieter_iban' ) ? ' auf das Konto IBAN ' . esc_html( $einst::get( 'vermieter_iban' ) ) . ' bei der ' . esc_html( $einst::get( 'vermieter_bank' ) ) . ', Kontoinhaber ' . esc_html( $einst::get( 'vermieter_name' ) ) : ' auf das vom Vermieter benannte Konto', $o['zahlungsweise'] === 'sepa' ? 'SEPA-Lastschriftmandat (Anlage)' : 'Dauerauftrag' ) );
		$absatz( 'Gerät der Mieter in Verzug, sind rückständige Beträge mit fünf Prozentpunkten über dem Basiszinssatz zu verzinsen (§ 288 Abs. 1 BGB). Für jede berechtigte Mahnung nach Verzugseintritt kann der Vermieter die ihm entstandenen Kosten, pauschal 5,00 €, verlangen; dem Mieter bleibt der Nachweis gestattet, dass kein oder ein geringerer Schaden entstanden ist.' );
		$absatz( sprintf( 'Der Mieter leistet eine Mietsicherheit in Höhe von <strong>%s</strong> (höchstens drei Grundmieten, § 551 BGB)%s. Der Vermieter legt sie getrennt von seinem Vermögen bei einem Kreditinstitut zum üblichen Zinssatz für Spareinlagen mit dreimonatiger Kündigungsfrist an; die Erträge stehen dem Mieter zu und erhöhen die Sicherheit. Der Mieter darf während des Mietverhältnisses nicht gegen die Mietsicherheit aufrechnen oder den Vermieter auf sie verweisen. Der Rückzahlungsanspruch wird nach Beendigung des Mietverhältnisses, Rückgabe der Mietsache und Ablauf einer angemessenen Prüffrist fällig, soweit dem Vermieter keine Gegenforderungen zustehen.', esc_html( mietv_eur( $mv->kaution ) ), $o['kaution_raten'] === '3' ? ', zahlbar in drei gleichen Monatsraten, die erste zu Beginn des Mietverhältnisses' : ', fällig zu Beginn des Mietverhältnisses' ) );

		$ueberschrift( '§ 6 Zustand der Mietsache, Arbeiten' );
		$absatz( 'Der Zustand der Mietsache bei Übergabe wird im gemeinsamen Übergabeprotokoll festgehalten, das Bestandteil dieses Vertrags ist.' . ( $o['zustand_maengel'] ? ' Bekannte Mängel: ' . esc_html( $o['zustand_maengel'] ) . '.' : '' ) );
		if ( $o['arbeiten_vermieter'] ) {
			$absatz( 'Der Vermieter führt auf seine Kosten fachgerecht' . ( $o['arbeiten_vermieter_bis'] ? ' bis spätestens ' . esc_html( $o['arbeiten_vermieter_bis'] ) : '' ) . ' folgende Arbeiten aus: ' . esc_html( $o['arbeiten_vermieter'] ) . '.' );
		}
		if ( $o['arbeiten_mieter'] ) {
			$absatz( 'Der Mieter führt auf seine Kosten fachgerecht' . ( $o['arbeiten_mieter_bis'] ? ' bis spätestens ' . esc_html( $o['arbeiten_mieter_bis'] ) : '' ) . ' folgende Arbeiten aus: ' . esc_html( $o['arbeiten_mieter'] ) . '.' );
		}

		$ueberschrift( '§ 7 Nutzung, Überlassung an Dritte, Meldepflicht' );
		$absatz( 'Die Mietsache darf nur zu Wohnzwecken genutzt werden; eine andere Nutzung bedarf der Erlaubnis des Vermieters, die aus wichtigem Grund widerrufen werden kann. Der Mieter darf den Gebrauch ohne Erlaubnis des Vermieters weder ganz noch teilweise Dritten überlassen, insbesondere nicht untervermieten (§ 540 BGB). Hat der Mieter nach Vertragsschluss ein berechtigtes Interesse, einen Teil der Wohnung Dritten zu überlassen, kann er die Erlaubnis verlangen (§ 553 BGB); der Vermieter kann sie von einem angemessenen Untermietzuschlag abhängig machen.' );
		$absatz( 'Der Mieter teilt dem Vermieter Änderungen der Zahl der im Haushalt lebenden Personen binnen eines Monats mit. Der Vermieter stellt die Wohnungsgeberbestätigung nach § 19 Bundesmeldegesetz aus; der Mieter meldet sich fristgerecht an und bei Auszug um und teilt seine neue Anschrift mit. Schäden aus der Verletzung dieser Pflichten (z. B. unzutreffende Betriebskostenabrechnung) trägt der Mieter.' );

		$ueberschrift( '§ 8 Tierhaltung' );
		$absatz(
			$o['tiere'] === 'frei'
				? 'Die Haltung von Haustieren ist gestattet, soweit Mitbewohner und Nachbarn nicht unzumutbar beeinträchtigt werden. Der Mieter haftet für alle durch die Tierhaltung entstehenden Schäden.'
				: 'Kleintiere, von denen keine Belästigung oder Gefährdung ausgeht (Zierfische, Ziervögel, Hamster u. ä.), dürfen ohne Zustimmung gehalten werden. Die Haltung sonstiger Tiere, insbesondere Hunde und Katzen, bedarf der Zustimmung des Vermieters, die nach Abwägung der beiderseitigen Interessen im Einzelfall erteilt wird und bei berechtigtem Interesse widerrufen werden kann. Das Füttern von Tauben und Wildtieren vom Grundstück aus ist untersagt. Der Mieter haftet für alle durch Tierhaltung entstehenden Schäden.'
		);

		$ueberschrift( '§ 9 Betreten der Mietsache, Abwesenheit' );
		$absatz( 'Der Vermieter oder von ihm Beauftragte dürfen die Mietsache nach rechtzeitiger Ankündigung zu angemessener Tageszeit aus konkretem Anlass betreten – insbesondere zur Ablesung, zur Prüfung des Zustands, zur Durchführung von Arbeiten sowie zur Besichtigung bei Verkauf oder Neuvermietung. Bei Gefahr im Verzug ist das Betreten jederzeit zulässig. Bei längerer Abwesenheit sorgt der Mieter dafür, dass die Wohnung in dringenden Fällen zugänglich ist, und hinterlegt einen Schlüssel bei einer Vertrauensperson, die er dem Vermieter benennt; andernfalls darf der Vermieter in dringenden Fällen auf Kosten des Mieters öffnen lassen.' );

		$ueberschrift( '§ 10 Bauliche Veränderungen, barrierefreier Umbau, E-Mobilität, Steckersolargeräte' );
		$absatz( 'Um- und Einbauten, Installationen und sonstige bauliche Veränderungen durch den Mieter bedürfen der vorherigen Zustimmung des Vermieters. Der Mieter stellt auf Verlangen bei Rückgabe den ursprünglichen Zustand fachgerecht wieder her, soweit nichts anderes vereinbart ist.' );
		$absatz( 'Auf Maßnahmen, die dem Gebrauch durch Menschen mit Behinderungen, dem Laden elektrisch betriebener Fahrzeuge, dem Einbruchschutz oder dem Betrieb eines Steckersolargeräts dienen, hat der Mieter nach § 554 BGB Anspruch, soweit sie dem Vermieter unter Abwägung der beiderseitigen Interessen zumutbar sind. Der Vermieter kann die Zustimmung von einer angemessenen zusätzlichen Sicherheit abhängig machen. Bei Ladeeinrichtungen kann er verlangen, dass die Anschlussleistung des Gebäudes ausreicht, die Installation durch einen Fachbetrieb erfolgt und Genehmigungen Dritter vorliegen. Bei Steckersolargeräten kann er verlangen, dass das Gerät den gesetzlichen und technischen Normen sowie den Vorgaben des Netzbetreibers entspricht, ordnungsgemäß angemeldet und ausreichend versichert ist und keine unzumutbare Blendwirkung ausgeht. Die Zustimmung kann widerrufen werden, wenn sich die tatsächlichen oder rechtlichen Verhältnisse so ändern, dass eine Versagung gerechtfertigt wäre.' );

		$ueberschrift( '§ 11 Sorgfaltspflichten, Heizen und Lüften, Haftung des Mieters' );
		$absatz( 'Der Mieter behandelt die Mietsache und die gemeinschaftlichen Einrichtungen schonend und pfleglich. Er sorgt für ausreichende Beheizung und regelmäßiges Stoßlüften, auch bei Abwesenheit, und stellt Möbel mit Abstand zu Außenwänden auf, um Feuchtigkeits- und Schimmelschäden zu vermeiden' . ( $o['merkblatt'] === '1' ? ' (siehe Merkblatt in der Anlage)' : '' ) . '. Bodenbeläge sind mit den vom Hersteller empfohlenen Mitteln zu pflegen. Mängel und Schäden zeigt der Mieter unverzüglich an (§ 536c BGB); unterlässt er dies, haftet er für den dadurch entstehenden weiteren Schaden.' );
		$absatz( 'Der Mieter haftet für Schäden, die er, seine Haushaltsangehörigen, Besucher, Untermieter oder von ihm beauftragte Personen schuldhaft verursachen. Entsteht ein Schaden im Bereich, der dem unmittelbaren Einfluss und der Obhut des Mieters unterliegt, und hat der Vermieter eine Ursache aus seinem Verantwortungsbereich ausgeschlossen, trifft den Mieter die Beweislast dafür, dass er den Schaden nicht zu vertreten hat.' );

		$ueberschrift( '§ 12 Haftung des Vermieters' );
		$absatz( 'Die verschuldensunabhängige Haftung des Vermieters für anfängliche Mängel (§ 536a Abs. 1 Alt. 1 BGB) ist ausgeschlossen. Im Übrigen haftet der Vermieter für Vorsatz und grobe Fahrlässigkeit; für leichte Fahrlässigkeit nur bei Verletzung wesentlicher Vertragspflichten (Kardinalpflichten), begrenzt auf den vertragstypischen, vorhersehbaren Schaden. Diese Beschränkungen gelten nicht für Schäden an Leben, Körper oder Gesundheit, bei arglistig verschwiegenen Mängeln, bei Übernahme einer Garantie und soweit der Schaden durch eine Gebäude- oder Haftpflichtversicherung des Vermieters gedeckt ist.' );

		$ueberschrift( '§ 13 Kleinreparaturen' );
		$absatz( sprintf( 'Der Mieter trägt ohne Rücksicht auf Verschulden die Kosten kleiner Instandhaltungen und Instandsetzungen an Teilen der Mietsache, die seinem häufigen und unmittelbaren Zugriff ausgesetzt sind – insbesondere Installationsgegenstände für Elektrizität, Wasser und Gas, Heiz- und Kocheinrichtungen, Fenster- und Türverschlüsse, Rollläden und Verschlussvorrichtungen von Fensterläden –, soweit die Kosten im Einzelfall <strong>%s</strong> nicht übersteigen. Je Kalenderjahr ist die Belastung auf <strong>%s %% der Jahresgrundmiete (%s)</strong> begrenzt. Übersteigt eine Reparatur den Einzelbetrag, trägt der Vermieter die gesamten Kosten; der Mieter beauftragt keine Handwerker selbst, sondern meldet den Schaden.', esc_html( mietv_eur( mietv_to_float( $o['kleinrep_einzel'] ) ) ), esc_html( $o['kleinrep_jahr'] ), esc_html( mietv_eur( $kalt * 12 * mietv_to_float( $o['kleinrep_jahr'] ) / 100 ) ) ) );

		$ueberschrift( '§ 14 Schönheitsreparaturen' );
		$absatz(
			$o['schoenheitsrep'] === 'renoviert'
				? 'Die Wohnung wird renoviert übergeben; sie gilt auch dann als renoviert, wenn vorhandene Gebrauchsspuren so unerheblich sind, dass die Räume den Gesamteindruck einer renovierten Wohnung vermitteln. Der Mieter übernimmt die während der Mietzeit durch seinen Gebrauch erforderlich werdenden Schönheitsreparaturen: Tapezieren und Anstreichen der Wände und Decken, Streichen der Heizkörper einschließlich Heizrohre, der Innentüren sowie der Fenster und Außentüren von innen, fachgerechte Reinigung der Teppichböden. Starre Fristen bestehen nicht; maßgeblich ist der tatsächliche Zustand. Die Farbwahl ist während der Mietzeit frei; bei Rückgabe sind fällige Schönheitsreparaturen in neutralen, deckenden, hellen Farbtönen auszuführen.'
				: 'Die Wohnung wird unrenoviert übergeben; ihr Zustand ist im Übergabeprotokoll festgehalten. Die Schönheitsreparaturen obliegen dem Vermieter.'
		);

		$ueberschrift( '§ 15 Aufrechnung, Zurückbehaltung' );
		$absatz( 'Gegen Mietforderungen kann der Mieter mit Forderungen aus §§ 536a, 539 BGB oder aus ungerechtfertigter Bereicherung wegen zu viel gezahlter Miete nur aufrechnen oder ein Zurückbehaltungsrecht ausüben, wenn er dies dem Vermieter mindestens einen Monat vor Fälligkeit der Miete in Textform angezeigt hat (§ 556b Abs. 2 BGB). Mit anderen Forderungen kann er nur aufrechnen oder zurückbehalten, soweit sie unbestritten oder rechtskräftig festgestellt sind.' );

		if ( $zentral ) {
			$ueberschrift( '§ 16 Zentralheizung, Nutzerwechsel' );
			$absatz( 'Der Vermieter betreibt die Heizungsanlage in der Heizperiode vom 1. Oktober bis 30. April sowie außerhalb, wenn die Witterung es erfordert, so, dass in den Wohnräumen zwischen 6 und 23 Uhr eine Temperatur von mindestens 20 °C erreicht werden kann; die Grundsätze sparsamen Energieeinsatzes sind zu beachten. Betriebsstörungen zeigt der Mieter unverzüglich an. Der Mieter ist zur Benutzung der Heizung verpflichtet; Nichtbenutzung befreit nicht von den Heizkosten. Der Vermieter darf die Wärmeversorgung auf eine andere Art, auch Wärmelieferung durch Dritte (§ 556c BGB), umstellen.' );
			$absatz( 'Endet das Mietverhältnis durch Kündigung des Mieters oder aus von ihm zu vertretenden Gründen während eines Abrechnungszeitraums, trägt der Mieter die Kosten einer Zwischenablesung. Unterbleibt eine Zwischenablesung, werden die Heiz- und Warmwasserkosten zwischen Vor- und Nachmieter nach der Gradtagszahlentabelle bzw. zeitanteilig aufgeteilt.' );
		} else {
			$ueberschrift( '§ 16 Heizung' );
			$absatz( 'Die Wohnung verfügt über eine eigene Heizungsanlage. Der Mieter schließt die Verträge über Brennstoff- bzw. Energielieferung selbst, trägt die Verbrauchskosten unmittelbar und sorgt für ausreichende Beheizung zur Vermeidung von Frost- und Feuchtigkeitsschäden. Wartung und Instandhaltung der Anlage obliegen dem Vermieter; die Wartungskosten sind als Betriebskosten umlagefähig.' );
		}

		$ueberschrift( '§ 17 Schilder, Antennen, Blumenkästen, Gartenpflege' );
		$absatz( 'Schilder, Anschläge und Außenantennen dürfen nur mit Zustimmung des Vermieters angebracht werden, der den Ort bestimmt und die Zustimmung von einer angemessenen Sicherheitsleistung abhängig machen kann; bei Auszug sind sie fachgerecht zu entfernen. Blumenkästen sind nur an der Innenseite von Balkonbrüstungen und sicher befestigt zulässig.' );
		if ( $o['gartenpflege'] === '1' ) {
			$absatz( 'Der Mieter pflegt den ihm zugewiesenen Gartenteil ordnungsgemäß (Rasen mähen, wässern, Beete und Wege von Unkraut freihalten, Hecken mindestens einmal jährlich schneiden, Laub beseitigen). Der Vermieter stellt hierfür keine Geräte. Kommt der Mieter trotz Abmahnung seinen Pflichten nicht nach, kann der Vermieter die Arbeiten auf Kosten des Mieters ausführen lassen.' );
		}

		$ueberschrift( '§ 18 Hausordnung, Reinigung, Winterdienst' );
		$absatz( 'Die beigefügte Hausordnung ist Bestandteil des Vertrags. Soweit Reinigung von Gemeinschaftsflächen und Winterdienst nicht durch den Vermieter oder beauftragte Dritte erbracht werden, obliegen sie den Mietparteien im wöchentlichen Wechsel nach dem im Haus ausgehängten Plan; der Vermieter stellt das Räumgerät, der Mieter das Streumaterial. Kommt der Mieter diesen Pflichten trotz Abmahnung nicht oder unzureichend nach, kann der Vermieter sie im Wege der Ersatzvornahme auf Kosten des Mieters ausführen lassen. Verhinderung durch Abwesenheit, Alter, Krankheit oder Urlaub entbindet nicht; der Mieter hat für Vertretung zu sorgen.' );

		$ueberschrift( '§ 19 Rückgabe' );
		$absatz( 'Bei Beendigung gibt der Mieter die Mietsache vollständig geräumt, gereinigt (einschließlich Fenster und Böden) und mit sämtlichen Schlüsseln zurück. Vom Mieter eingebrachte Einrichtungen sind zu entfernen und der ursprüngliche Zustand ist fachgerecht wiederherzustellen, sofern der Vermieter sie nicht gegen angemessene Entschädigung übernimmt. Bis zur Rückgabe, mindestens bis zum Vertragsende, bestehen die Heiz- und Obhutspflichten fort. Über die Rückgabe wird ein gemeinsames Protokoll erstellt.' );

		$ueberschrift( '§ 20 Schlussbestimmungen' );
		$absatz( 'Mündliche Nebenabreden bestehen nicht. Änderungen und Ergänzungen des Vertrags bedürfen der Textform. Vorübergehende unentgeltliche Gestattungen des Vermieters sind jederzeit widerruflich. Sollte eine Bestimmung unwirksam sein, bleibt der Vertrag im Übrigen wirksam; an ihre Stelle tritt die gesetzliche Regelung. Ändern sich gesetzliche Bestimmungen, gelten die neuen Regelungen, soweit sie zwingend sind.' );
		if ( $o['energieausweis'] ) {
			$absatz( 'Energieausweis (§ 80 GEG): ' . esc_html( $o['energieausweis'] ) . '. Der Energieausweis wurde dem Mieter vor Vertragsschluss vorgelegt und wird in Kopie übergeben.' );
		}
		$absatz( 'Die Wohnung ist nach Art. 46 Abs. 4 BayBO mit Rauchwarnmeldern ausgestattet. Die Sicherstellung der Betriebsbereitschaft (Funktionsprüfung, Batteriewechsel) obliegt dem Mieter, soweit nicht der Vermieter die Wartung übernommen und die Kosten als Betriebskosten umgelegt hat.' );
		if ( $o['sonstiges'] ) {
			$absatz( '<strong>Sonstige Vereinbarungen:</strong> ' . nl2br( esc_html( $o['sonstiges'] ) ) );
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
		$absatz( 'Anlagen: ' . esc_html( implode( ', ', $anlagen ) ) . '.' );
		echo wp_kses_post( self::unterschriften( $ort, $m ) );

		echo '<div class=\"umbruch\"></div>' . wp_kses_post( self::datenschutz_html( $vermieter ) );
		if ( $o['hausordnung'] === '1' ) {
			echo '<div class=\"umbruch\"></div>' . wp_kses_post( self::hausordnung_html() );
		}
		if ( $o['merkblatt'] === '1' ) {
			echo '<div class=\"umbruch\"></div>' . wp_kses_post( self::merkblatt_html() );
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
		$einst = Mietv_Einstellungen::class;
		return '<h1>' . esc_html__( 'Privacy notice under Art. 13 GDPR', 'mietfux' ) . '</h1>
<p><strong>' . esc_html__( 'Controller:', 'mietfux' ) . '</strong> ' . esc_html( $vermieter ) . ( $einst::get( 'vermieter_email' ) ? ', E-Mail ' . esc_html( $einst::get( 'vermieter_email' ) ) : '' ) . ( $einst::get( 'vermieter_telefon' ) ? ', Telefon ' . esc_html( $einst::get( 'vermieter_telefon' ) ) : '' ) . '. Ein Datenschutzbeauftragter ist nicht bestellt (nicht erforderlich, Art. 37 DSGVO).</p>
<p><strong>Zwecke und Rechtsgrundlagen:</strong> Anbahnung, Durchführung und Beendigung des Mietverhältnisses einschließlich Mietbuchhaltung, Betriebskostenabrechnung, Mängelbearbeitung und Schriftverkehr (Art. 6 Abs. 1 lit. b DSGVO); Erfüllung gesetzlicher Pflichten, insbesondere steuerliche Aufbewahrung und Abrechnungspflichten (Art. 6 Abs. 1 lit. c DSGVO i. V. m. § 147 AO, § 556 BGB); Wahrung berechtigter Interessen wie Geltendmachung von Ansprüchen und Hausverwaltung (Art. 6 Abs. 1 lit. f DSGVO).</p>
<p><strong>Datenkategorien:</strong> Name, Anschrift, Geburtsdatum, Kontaktdaten, Bankverbindung, Personenzahl im Haushalt, Vertrags- und Zahlungsdaten, Verbrauchsdaten (Zählerstände), Meldungen und Schriftverkehr, Zugangsdaten zum Mieterportal.</p>
<p><strong>Empfänger:</strong> Soweit für die Durchführung des Mietverhältnisses erforderlich: Finanzamt und Steuerberater (Einkünfte aus Vermietung), Kreditinstitut (Zahlungsverkehr), Energieversorger, Messdienstleister und Abrechnungsunternehmen (Verbrauchsdaten für Heiz- und Betriebskostenabrechnung), bei Eigentumswohnungen die WEG-Verwaltung (Name, Verbrauchsdaten, Umlagerelevantes), Handwerker und Dienstleister (Name und Kontaktdaten zur Terminvereinbarung), Gebäude- und Haftpflichtversicherer bei Schadensfällen, Rechtsanwalt, Gericht oder Inkasso bei Streitigkeiten, Meldebehörde (Wohnungsgeberbestätigung), Hosting-Anbieter der Verwaltungssoftware als Auftragsverarbeiter (Art. 28 DSGVO). Anderen Mietern wird auf Verlangen Einsicht in die Abrechnungsbelege gewährt, soweit dies zur Prüfung ihrer Abrechnung erforderlich ist. Eine Übermittlung in Drittländer findet nicht statt.</p>
<p><strong>Speicherdauer:</strong> Für die Dauer des Mietverhältnisses und anschließend ' . (int) $einst::get( 'aufbewahrung_jahre', 10 ) . ' Jahre ab Ende des Kalenderjahres der Beendigung (steuerliche Aufbewahrungsfrist); danach werden die Daten anonymisiert bzw. gelöscht. Daten von Mietinteressenten, mit denen kein Vertrag zustande kommt, werden spätestens sechs Monate nach der Entscheidung gelöscht (Nachweiszwecke nach dem Allgemeinen Gleichbehandlungsgesetz).</p>
<p><strong>Ihre Rechte:</strong> Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20), Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21) sowie Widerruf einer erteilten Einwilligung mit Wirkung für die Zukunft (Art. 7 Abs. 3). Auskünfte werden unverzüglich, spätestens innerhalb eines Monats erteilt (Art. 12 Abs. 3). Beschwerderecht bei einer Aufsichtsbehörde, in Bayern: Bayerisches Landesamt für Datenschutzaufsicht (BayLDA), Promenade 18, 91522 Ansbach, www.lda.bayern.de.</p>
<p><strong>Bereitstellungspflicht:</strong> Die Angabe der für den Vertrag erforderlichen Daten ist Voraussetzung für den Abschluss und die Durchführung des Mietvertrags. Eine automatisierte Entscheidungsfindung findet nicht statt.</p>
<p class="klein">Erhalten am ____________ &nbsp;&nbsp; Unterschrift Mieter: ______________________</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Übergabeprotokoll                                                  */
	/* ------------------------------------------------------------------ */

	public static function uebergabe(): void {
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		global $wpdb;
		check_admin_referer( 'mietv_uebergabe' );
		Mietv_PDF::ablegen_aus_request( 'mietv_uebergabe' );
		$mv_id = ( isset( $_GET['mv_id'] ) ? (int) $_GET['mv_id'] : 0 );
		$art   = ( isset( $_GET['art'] ) ? sanitize_key( $_GET['art'] ) : 'einzug' ) === 'auszug' ? __( 'Move-out', 'mietfux' ) : __( 'Move-in', 'mietfux' );
		$d     = self::daten( $mv_id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Not found.', 'mietfux' ) );
		}
		[ 'mv' => $mv, 'mieter' => $m, 'einheit' => $e, 'objekt' => $ob, 'opt' => $o, 'zusatz' => $zusatz ] = $d;
		$zusatz_ids = array_map( fn( $z ) => (int) $z->einheit_id, $zusatz );
		$einst      = Mietv_Einstellungen::class;
		$ort        = $ob->ort;

		self::kopf( 'Uebergabeprotokoll ' . $art . ' ' . $m->nachname, [ $ob->id, $mv->id, 'uebergabeprotokoll', 'Übergabeprotokoll ' . $art, true ] );
		printf(
			'<h1>' . /* translators: %s: Wert */ esc_html__( 'Handover protocol – %s', 'mietfux' ) . '</h1><p>' . esc_html__( 'Flat', 'mietfux' ) . ' <strong>%s</strong>, %s, %s %s<br>' . /* translators: %s: Wert */ esc_html__( 'Landlord: %s', 'mietfux' ) . '<br>' . /* translators: %s: Wert */ esc_html__( 'Tenant: %s', 'mietfux' ) . '<br>' . esc_html__( 'Date of handover:', 'mietfux' ) . ' <span class="feld"></span> &nbsp; ' . esc_html__( 'Time:', 'mietfux' ) . ' <span class="feld"></span></p>',
			esc_html( $art ),
			esc_html( $e->bezeichnung ),
			esc_html( $ob->strasse ),
			esc_html( $ob->plz ),
			esc_html( $ob->ort ),
			esc_html( $einst::get( 'vermieter_name' ) ),
			esc_html( implode( ', ', array_merge( mietv_mieter_personen( $m ), $o['mitmieter'] ? [ $o['mitmieter'] ] : [] ) ) )
		);

		echo '<h2>' . esc_html__( '1. Meter readings', 'mietfux' ) . '</h2><table><tr><th>' . esc_html__( 'Meters', 'mietfux' ) . '</th><th>' . esc_html__( 'Number', 'mietfux' ) . '</th><th>' . esc_html__( 'Reading', 'mietfux' ) . '</th><th>' . esc_html__( 'Reading per system', 'mietfux' ) . '</th></tr>';
		$zaehler = $wpdb->get_results( $wpdb->prepare( 'SELECT z.*, (SELECT stand FROM ' . $wpdb->prefix . 'mv_zaehlerstaende s WHERE s.zaehler_id = z.id ORDER BY datum DESC LIMIT 1) AS letzter, (SELECT datum FROM ' . $wpdb->prefix . 'mv_zaehlerstaende s WHERE s.zaehler_id = z.id ORDER BY datum DESC LIMIT 1) AS letzter_datum FROM ' . $wpdb->prefix . 'mv_zaehler z WHERE z.einheit_id IN (' . implode( ',', array_map( 'intval', array_merge( [ $e->id ], $zusatz_ids ) ) ) . ') OR (z.einheit_id IS NULL AND z.objekt_id = %d) ORDER BY z.einheit_id, z.typ', $ob->id ) );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- IN-Platzhalter aus count() erzeugt, IDs sind int.
		$typen   = [
			'kaltwasser' => esc_html__( 'Cold water (m³)', 'mietfux' ),
			'warmwasser' => esc_html__( 'Hot water (m³)', 'mietfux' ),
			'heizung'    => esc_html__( 'Heating (kWh/units)', 'mietfux' ),
			'strom'      => esc_html__( 'Electricity (kWh)', 'mietfux' ),
		];
		foreach ( $zaehler as $z ) {
			printf( '<tr><td>%s%s</td><td>%s</td><td><span class="feld" style="min-width:40mm"></span></td><td class="klein">%s</td></tr>', esc_html( $typen[ $z->typ ] ?? $z->typ ), $z->einheit_id ? '' : ' (Haus)', esc_html( $z->nummer ), $z->letzter !== null ? esc_html( number_format( (float) $z->letzter, 2, ',', '.' ) . ' am ' . mietv_date( $z->letzter_datum ) ) : '–' );
		}
		foreach ( [ 'Strom', 'Gas', 'Kaltwasser', 'Warmwasser', 'Heizung' ] as $leer ) {
			printf( '<tr><td>%s</td><td><span class="feld"></span></td><td><span class="feld" style="min-width:40mm"></span></td><td></td></tr>', esc_html( $leer ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( '2. Keys', 'mietfux' ) . '</h2><p>' . esc_html( $o['schluessel'] ) . '</p><p>Übergeben: <span class="feld" style="min-width:120mm"></span></p>';

		echo '<h2>' . esc_html__( '3. Condition of rooms', 'mietfux' ) . '</h2><p class="klein">' . esc_html__( 'Rating: 1 = as new, 2 = good, 3 = used/normal wear, 4 = defective (describe defects). Photos as annex.', 'mietfux' ) . '</p><table><tr><th style="width:25%">' . esc_html__( 'Room', 'mietfux' ) . '</th><th>' . esc_html__( 'Floor', 'mietfux' ) . '</th><th>' . esc_html__( 'Walls/ceiling', 'mietfux' ) . '</th><th>' . esc_html__( 'Windows/doors', 'mietfux' ) . '</th><th>' . esc_html__( 'Electrics/plumbing', 'mietfux' ) . '</th><th>' . esc_html__( 'Remarks / defects', 'mietfux' ) . '</th></tr>';
		$raeume = array_filter( array_map( 'trim', preg_split( '/[,\n]/', $o['raeume'] ?: 'Wohnzimmer, Schlafzimmer, Küche, Bad, Flur, Balkon' ) ) );
		foreach ( array_merge( $raeume, [ 'Keller / Nebenraum', 'Hausflur / Gemeinschaft' ] ) as $r ) {
			printf( '<tr><td>%s</td><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>', esc_html( $r ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( '4. Fixtures and function check', 'mietfux' ) . '</h2><table><tr><th>' . esc_html__( 'Item', 'mietfux' ) . '</th><th>' . esc_html__( 'present', 'mietfux' ) . '</th><th>' . esc_html__( 'works', 'mietfux' ) . '</th><th>' . esc_html__( 'Remark', 'mietfux' ) . '</th></tr>';
		foreach ( array_merge( [ 'Rauchwarnmelder (alle Schlaf-/Kinderzimmer, Flur)', 'Heizung / Thermostate', 'Warmwasser', 'Herd / Backofen', 'Dunstabzug', 'Spüle / Armaturen', 'WC-Spülung', 'Dusche / Badewanne', 'Steckdosen / Lichtschalter', 'Klingel / Gegensprechanlage', 'Briefkasten (Name)', 'Fenster / Rollläden' ], array_filter( array_map( 'trim', preg_split( '/[,\n]/', $o['ausstattung'] ) ) ) ) as $g ) {
			printf( '<tr><td>%s</td><td>' . esc_html__( '☐ yes ☐ no', 'mietfux' ) . '</td><td>' . esc_html__( '☐ yes ☐ no', 'mietfux' ) . '</td><td></td></tr>', esc_html( $g ) );
		}
		echo '</table>';

		if ( $art === 'Auszug' ) {
			echo '<h2>' . esc_html__( '5. Return', 'mietfux' ) . '</h2><p>' . esc_html__( '☐ Flat completely cleared and broom-clean', 'mietfux' ) . ' &nbsp; ' . esc_html__( '☐ All keys returned', 'mietfux' ) . ' &nbsp; ' . esc_html__( '☐ Decorative repairs due/carried out:', 'mietfux' ) . ' <span class="feld"></span></p><p>' . esc_html__( 'Agreed follow-up work by the tenant by', 'mietfux' ) . ' <span class="feld"></span>: <span class="feld" style="min-width:100mm"></span></p><p>' . esc_html__( 'Tenant\'s new address (for deposit and service charge settlement):', 'mietfux' ) . ' <span class="feld" style="min-width:120mm"></span></p><p>' . esc_html__( 'Deposit refund to IBAN:', 'mietfux' ) . ' <span class="feld" style="min-width:80mm"></span></p>';
		} else {
			echo '<h2>' . esc_html__( '5. Agreements', 'mietfux' ) . '</h2><p>' . esc_html__( 'Still to be done by the landlord by', 'mietfux' ) . ' <span class="feld"></span>: <span class="feld" style="min-width:100mm"></span></p><p>' . esc_html__( 'Handed to the tenant: ☐ Energy certificate ☐ House rules ☐ Privacy notice ☐ Operating instructions (heating, kitchen)', 'mietfux' ) . '</p>';
		}
		echo '<p class="klein">' . esc_html__( 'The parties confirm the accuracy of the details. Defects not noted are deemed not to have existed at handover insofar as they were discernible on inspection.', 'mietfux' ) . '</p>';
		echo wp_kses_post( self::unterschriften( $ort, $m ) );
		self::fuss();
	}
}
