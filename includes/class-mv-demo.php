<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Demodaten: eine vermietete Eigentumswohnung mit einem Jahr Zahlungen, Belegen,
 * Darlehen, Hausgeld und Zählern – für die Live-Vorschau (WordPress Playground)
 * und zum Ausprobieren auf einer leeren Installation.
 */
class Mietv_Demo {

	public static function init(): void {
		add_action( 'admin_post_mietv_demo', [ __CLASS__, 'handle' ] );
	}

	/** Demodaten nur auf leerer Installation (keine Objekte) zulassen. */
	public static function moeglich(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_objekte' ) === 0;
	}

	public static function handle(): void {
		check_admin_referer( 'mietv_demo' );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$ok = self::moeglich() && self::anlegen();
		mietv_flash_set( $ok ? 'demo_ok' : 'demo_fehler' );
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_uebersicht' ) );
		exit;
	}

	/** Demodaten anlegen. Liefert true bei Erfolg. */
	public static function anlegen(): bool {
		global $wpdb;
		if ( ! self::moeglich() ) {
			return false;
		}
		$jahr  = (int) current_time( 'Y' );
		$start = ( $jahr - 1 ) . '-01-01';
		$heute = current_time( 'Y-m-d' );

		// Vermieter
		$e = array_merge( get_option( Mietv_Einstellungen::OPTION, [] ), Mietv_Einstellungen::defaults() );
		$e = array_merge(
			$e,
			[
				'vermieter_name'     => 'Max Mustermann',
				'vermieter_adresse'  => "Musterweg 7\n90513 Zirndorf",
				'vermieter_telefon'  => '0911 000000',
				'vermieter_iban'     => 'DE02120300000000202051',
				'vermieter_bank'     => 'Musterbank',
				'vermieter_steuernr' => '222/123/45678',
			]
		);
		update_option( Mietv_Einstellungen::OPTION, $e );

		// Objekt + Einheiten
		$wpdb->insert(
			mietv_table( 'objekte' ),
			[
				'owner_id'    => MIETV_OWNER_ID,
				'bezeichnung' => 'ETW Rosenstraße 12',
				'strasse'     => 'Rosenstraße 12',
				'plz'         => '90762',
				'ort'         => 'Fürth',
				'baujahr'     => 1998,
				'heizung'     => 'zentral',
				'notiz'       => 'Eigentumswohnung, WEG mit 8 Einheiten',
				'created_at'  => current_time( 'mysql' ),
			]
		);
		$oid = (int) $wpdb->insert_id;
		$wpdb->insert(
			mietv_table( 'einheiten' ),
			[
				'objekt_id'   => $oid,
				'bezeichnung' => 'Wohnung 1. OG',
				'lage'        => '1. OG links',
				'flaeche'     => 72.5,
				'zimmer'      => 3,
				'typ'         => 'wohnung',
			]
		);
		$eid = (int) $wpdb->insert_id;
		$wpdb->insert(
			mietv_table( 'einheiten' ),
			[
				'objekt_id'   => $oid,
				'bezeichnung' => 'Garage 4',
				'lage'        => 'Tiefgarage',
				'flaeche'     => 0,
				'zimmer'      => 0,
				'typ'         => 'garage',
			]
		);
		$gid = (int) $wpdb->insert_id;

		// Mieter + Mietverhältnis
		$wpdb->insert(
			mietv_table( 'mieter' ),
			[
				'owner_id'   => MIETV_OWNER_ID,
				'anrede'     => 'Frau',
				'vorname'    => 'Anna',
				'nachname'   => 'Beispiel',
				'email'      => 'anna.beispiel@example.com',
				'telefon'    => '0170 0000000',
				'p_anrede'   => 'Herr',
				'p_vorname'  => 'Jonas',
				'p_nachname' => 'Beispiel',
				'iban'       => 'DE89370400440532013000',
			]
		);
		$mid = (int) $wpdb->insert_id;
		$wpdb->insert(
			mietv_table( 'mietverhaeltnisse' ),
			[
				'einheit_id' => $eid,
				'mieter_id'  => $mid,
				'beginn'     => $start,
				'ende'       => null,
				'kaution'    => 2100,
				'personen'   => 2,
				'zahltag'    => 3,
			]
		);
		$mv_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			mietv_table( 'konditionen' ),
			[
				'mv_id'      => $mv_id,
				'gueltig_ab' => $start,
				'kaltmiete'  => 700,
				'bk_vz'      => 120,
				'hk_vz'      => 90,
			]
		);
		$wpdb->insert(
			mietv_table( 'mv_einheiten' ),
			[
				'mv_id'      => $mv_id,
				'einheit_id' => $gid,
				'mietanteil' => 50,
			]
		);

		// Zahlungen: Vorjahr komplett, laufendes Jahr bis Vormonat (ein Monat bewusst offen)
		$cursor = new DateTime( $start );
		$ende   = new DateTime( current_time( 'Y-m-01' ) );
		$ende->modify( '-1 month' );
		while ( $cursor <= $ende ) {
			$wpdb->insert(
				mietv_table( 'zahlungen' ),
				[
					'mv_id'            => $mv_id,
					'datum'            => $cursor->format( 'Y-m-03' ),
					'betrag'           => 910,
					'monat'            => $cursor->format( 'Y-m-01' ),
					'verwendungszweck' => 'Miete ' . $cursor->format( 'm/Y' ) . ' Beispiel',
					'quelle'           => 'bank',
				]
			);
			$cursor->modify( '+1 month' );
		}

		// Kaution
		$wpdb->insert(
			mietv_table( 'kaution' ),
			[
				'mv_id'  => $mv_id,
				'datum'  => $start,
				'art'    => 'einzahlung',
				'betrag' => 2100,
				'notiz'  => 'Überweisung',
			]
		);

		// Anlagegut + Darlehen
		$wpdb->insert(
			mietv_table( 'anlagegueter' ),
			[
				'owner_id'             => MIETV_OWNER_ID,
				'objekt_id'            => $oid,
				'bezeichnung'          => 'ETW Rosenstraße 12',
				'typ'                  => 'gebaeude',
				'anschaffung'          => ( $jahr - 2 ) . '-11-15',
				'ak'                   => 245000,
				'nk_grunderwerbsteuer' => 8575,
				'nk_notar'             => 2450,
				'nk_grundbuch'         => 1225,
				'nk_makler'            => 0,
				'grundanteil'          => 20,
				'methode'              => 'linear',
				'satz'                 => 2,
			]
		);
		$wpdb->insert(
			mietv_table( 'darlehen' ),
			[
				'owner_id'        => MIETV_OWNER_ID,
				'objekt_id'       => $oid,
				'bank'            => 'Sparkasse Fürth',
				'bezeichnung'     => 'Annuitätendarlehen',
				'betrag'          => 180000,
				'auszahlung'      => ( $jahr - 2 ) . '-11-30',
				'zinssatz'        => 3.45,
				'zinsbindung_bis' => ( $jahr + 8 ) . '-11-30',
				'rate'            => 820,
				'tilgung'         => 2,
				'status'          => 'aktiv',
			]
		);

		// Kostenarten holen
		$ka = [];
		foreach ( $wpdb->get_results( 'SELECT id, name, anlage_v FROM ' . $wpdb->prefix . 'mv_kostenarten' ) as $k ) {
			$ka[ $k->anlage_v ][]     = (int) $k->id;
			$ka[ 'name:' . $k->name ] = (int) $k->id;
		}
		$id_of = function ( string $teil ) use ( $ka ): int {
			foreach ( $ka as $key => $v ) {
				if ( is_string( $key ) && strpos( $key, 'name:' ) === 0 && stripos( $key, $teil ) !== false ) {
					return (int) $v;
				}
			}
			return 0;
		};

		// Belege im Vorjahr
		$belege = [
			[ ( $jahr - 1 ) . '-02-15', 'Grundsteuer', 'Stadt Fürth', 'Grundsteuer 1. Quartal', 84.5 ],
			[ ( $jahr - 1 ) . '-05-15', 'Grundsteuer', 'Stadt Fürth', 'Grundsteuer 2. Quartal', 84.5 ],
			[ ( $jahr - 1 ) . '-08-15', 'Grundsteuer', 'Stadt Fürth', 'Grundsteuer 3. Quartal', 84.5 ],
			[ ( $jahr - 1 ) . '-11-15', 'Grundsteuer', 'Stadt Fürth', 'Grundsteuer 4. Quartal', 84.5 ],
			[ ( $jahr - 1 ) . '-06-20', 'Erhaltungsaufwand', 'Sanitär Meier', 'Reparatur Armatur Bad', 236.4 ],
			[ ( $jahr - 1 ) . '-09-02', 'Sonstige Werbungskosten', 'Mietfux', 'Software, Porto', 59.9 ],
		];
		foreach ( $belege as $b ) {
			$kid = $id_of( $b[1] );
			if ( ! $kid ) {
				continue;
			}
			$wpdb->insert(
				mietv_table( 'belege' ),
				[
					'owner_id'         => MIETV_OWNER_ID,
					'objekt_id'        => $oid,
					'kostenart_id'     => $kid,
					'datum'            => $b[0],
					'steuerjahr'       => (int) substr( $b[0], 0, 4 ),
					'betrag'           => $b[4],
					'zeitraum_von'     => $b[0],
					'zeitraum_bis'     => $b[0],
					'lieferant'        => $b[2],
					'beschreibung'     => $b[3],
					'verteilung_jahre' => 1,
				]
			);
		}

		// Hausgeld: Wirtschaftsplan + Vorjahr gebucht
		if ( class_exists( 'Mietv_Hausgeld' ) ) {
			$verteilung = array_filter(
				[
					$id_of( 'Wasser' )              => 60,
					$id_of( 'Allgemeinstrom' )      => 10,
					$id_of( 'Heizung' )             => 90,
					$id_of( 'Gebäudeversicherung' ) => 25,
					$id_of( 'Hausmeister' )         => 35,
					$id_of( 'Hausverwaltung' )      => 30,
					$id_of( 'Erhaltungsaufwand' )   => 20,
					$id_of( 'Erhaltungsrücklage' )  => 50,
				],
				fn( $v, $k ) => $k > 0,
				ARRAY_FILTER_USE_BOTH
			);
			$wpdb->insert(
				mietv_table( 'hausgeld' ),
				[
					'owner_id'   => MIETV_OWNER_ID,
					'objekt_id'  => $oid,
					'verwalter'  => 'Hausverwaltung Muster GmbH',
					'iban'       => 'DE02120300000000202051',
					'betrag'     => array_sum( $verteilung ),
					'gueltig_ab' => $start,
					'verteilung' => wp_json_encode( $verteilung ),
				]
			);
			for ( $m = 1; $m <= 12; $m++ ) {
				$monat = sprintf( '%04d-%02d-01', $jahr - 1, $m );
				Mietv_Hausgeld::buchen_monat( $oid, $monat, sprintf( '%04d-%02d-01', $jahr - 1, $m ) );
			}
		}

		// Zähler mit Anfangs- und Jahresstand
		foreach ( [ [ 'strom', '1 ENE 0123456', 14210, 16085 ], [ 'kaltwasser', 'WZ-4711', 318, 356 ] ] as $z ) {
			$wpdb->insert(
				mietv_table( 'zaehler' ),
				[
					'objekt_id'  => $oid,
					'einheit_id' => $eid,
					'typ'        => $z[0],
					'nummer'     => $z[1],
				]
			);
			$zid = (int) $wpdb->insert_id;
			foreach ( [ [ $start, $z[2] ], [ ( $jahr - 1 ) . '-12-31', $z[3] ] ] as $s ) {
				$wpdb->insert(
					mietv_table( 'zaehlerstaende' ),
					[
						'zaehler_id' => $zid,
						'datum'      => $s[0],
						'stand'      => $s[1],
						'quelle'     => 'vermieter',
					]
				);
			}
		}

		update_option( 'mietv_assistent', [ 'fertig' => 1 ] );
		update_option( 'mietv_demo', $heute );
		do_action( 'mietv_protokoll', 'import', 'demo', 0, __( 'Demo data created', 'mietfux' ), [] );
		return true;
	}

	/** Link zum Anlegen der Demodaten (nur auf leerer Installation). */
	public static function link(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=mietv_demo' ), 'mietv_demo' );
	}
}
