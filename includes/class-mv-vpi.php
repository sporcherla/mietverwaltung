<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Verbraucherpreisindex (VPI, Destatis, Basis 2020 = 100) und Indexmieten-Automatik (§ 557b BGB).
 *
 * Monatswerte des VPI werden in wp_mv_vpi gehalten (manuell, CSV-Import oder GENESIS-Online-Abruf).
 * Für jedes aktive Mietverhältnis mit Indexmiete wird geprüft, ob eine Anpassung möglich ist:
 * frühestens ein Jahr nach der letzten Festlegung, neue Miete = alte Miete × VPI_neu / VPI_alt.
 */
class Mietv_Vpi {

	/** GENESIS-Online-Tabelle „Verbraucherpreisindex: Deutschland, Monate“. */
	const TABELLE = '61111-0002';
	const API     = 'https://www-genesis.destatis.de/genesisWS/rest/2020/data/tablefile';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Tabelle                                                            */
	/* ------------------------------------------------------------------ */

	public static function tabellen( string $charset ): array {
		global $wpdb;
		return [
			'CREATE TABLE ' . $wpdb->prefix . "mv_vpi (
  monat date NOT NULL,
  wert decimal(7,2) NOT NULL DEFAULT 0,
  quelle varchar(20) NOT NULL DEFAULT 'manuell',
  geaendert datetime DEFAULT NULL,
  PRIMARY KEY  (monat)
) $charset;",
		];
	}

	/* ------------------------------------------------------------------ */
	/* Daten                                                              */
	/* ------------------------------------------------------------------ */

	/** „2025-03“, „2025-03-17“ oder „2025-03-01“ → „2025-03-01“; leer bei ungültiger Angabe. */
	public static function monat_norm( string $monat ): string {
		return preg_match( '/^(\d{4})-(\d{2})/', trim( $monat, " \t\n\r\0\x0B" ), $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ? $m[1] . '-' . $m[2] . '-01' : '';
	}

	/** VPI eines Monats. */
	public static function wert( string $monat ): ?float {
		global $wpdb;
		$monat = self::monat_norm( $monat );
		if ( ! $monat ) {
			return null;
		}
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT wert FROM ' . $wpdb->prefix . 'mv_vpi WHERE monat = %s', $monat ) );
		return $v === null ? null : (float) $v;
	}

	/** Jüngster vorhandener Wert: [ 'monat' => 'YYYY-MM-01', 'wert' => float ] oder null. */
	public static function letzter(): ?array {
		global $wpdb;
		$r = $wpdb->get_row( 'SELECT monat, wert FROM ' . $wpdb->prefix . 'mv_vpi WHERE wert > 0 ORDER BY monat DESC LIMIT 1' );
		return $r ? [
			'monat' => $r->monat,
			'wert'  => (float) $r->wert,
		] : null;
	}

	/** Wert eintragen oder ändern. Liefert true bei Erfolg. */
	public static function setzen( string $monat, float $wert, string $quelle = 'manuell' ): bool {
		global $wpdb;
		$monat = self::monat_norm( $monat );
		if ( ! $monat || $wert <= 0 ) {
			return false;
		}
		$quelle = $quelle === 'destatis' ? 'destatis' : 'manuell';
		$daten  = [
			'wert'      => round( $wert, 2 ),
			'quelle'    => $quelle,
			'geaendert' => current_time( 'mysql' ),
		];
		$vorh   = $wpdb->get_var( $wpdb->prepare( 'SELECT monat FROM ' . $wpdb->prefix . 'mv_vpi WHERE monat = %s', $monat ) );
		if ( $vorh ) {
			$wpdb->update( mietv_table( 'vpi' ), $daten, [ 'monat' => $monat ] );
			return true;
		}
		$daten['monat'] = $monat;
		return (bool) $wpdb->insert( mietv_table( 'vpi' ), $daten );
	}

	/** Wert löschen. */
	public static function loeschen( string $monat ): void {
		global $wpdb;
		$monat = self::monat_norm( $monat );
		if ( $monat ) {
			$wpdb->delete( mietv_table( 'vpi' ), [ 'monat' => $monat ] );
		}
	}

	/** Alle Werte ab einem Jahr: [ 'YYYY-MM-01' => row(monat, wert, quelle, geaendert) ], aufsteigend. */
	public static function reihe( int $von_jahr ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_vpi WHERE monat >= %s ORDER BY monat ASC', $von_jahr . '-01-01' ) ) as $r ) {
			$r->wert          = (float) $r->wert;
			$out[ $r->monat ] = $r;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Berechnung                                                         */
	/* ------------------------------------------------------------------ */

	/** Anpassung nach § 557b BGB: [ 'neu' => neue Kaltmiete, 'prozent' => Indexänderung in % ]. */
	public static function anpassung( float $miete, float $vpi_alt, float $vpi_neu ): array {
		if ( $vpi_alt <= 0 || $vpi_neu <= 0 ) {
			return [
				'neu'     => round( $miete, 2 ),
				'prozent' => 0.0,
			];
		}
		return [
			'neu'     => round( $miete * $vpi_neu / $vpi_alt, 2 ),
			'prozent' => round( ( $vpi_neu / $vpi_alt - 1 ) * 100, 2 ),
		];
	}

	/** Ende der Sperrfrist: ein Jahr nach der letzten Festlegung (§ 557b Abs. 2 BGB). */
	public static function sperrfrist_ende( string $festlegung ): string {
		return gmdate( 'Y-m-d', strtotime( substr( $festlegung, 0, 10 ) . ' +12 months' ) );
	}

	/** Darf zum Stichtag angepasst werden (Sperrfrist abgelaufen)? */
	public static function anpassung_moeglich( string $festlegung, string $stichtag ): bool {
		return self::sperrfrist_ende( $festlegung ) <= substr( $stichtag, 0, 10 );
	}

	/** Datum der letzten Kaltmieterhöhung laut Konditionen, sonst Mietbeginn. */
	public static function letzte_festlegung( object $mv ): string {
		global $wpdb;
		$letzte = '';
		$vorher = null;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT gueltig_ab, kaltmiete FROM ' . $wpdb->prefix . 'mv_konditionen WHERE mv_id = %d ORDER BY gueltig_ab ASC, id ASC', (int) $mv->id ) ) as $r ) {
			if ( $vorher !== null && (float) $r->kaltmiete > $vorher ) {
				$letzte = $r->gueltig_ab;
			}
			$vorher = (float) $r->kaltmiete;
		}
		return $letzte ?: (string) $mv->beginn;
	}

	/** „Indexmiete, VPI 115,3 → 120,2“ (Begründung einer Index-Erhöhung) → [alt, neu] oder null. */
	public static function vpi_aus_begruendung( ?string $text ): ?array {
		if ( $text && preg_match( '/VPI\s+([\d.,]+)\s*(?:→|->)\s*([\d.,]+)/u', $text, $m ) ) {
			$alt = mietv_to_float( $m[1] );
			$neu = mietv_to_float( $m[2] );
			if ( $alt > 0 && $neu > 0 ) {
				return [ $alt, $neu ];
			}
		}
		return null;
	}

	/**
	 * Alle aktiven Mietverhältnisse mit Indexmiete samt Prüfstand.
	 * Indexmiete = Vertragsoption mietart = index (wp_mv_vertraege) ODER vorhandene Erhöhung mit art = index.
	 * Je Eintrag: mv, label, festlegung, sperrfrist_ende, miete, vpi_alt, vpi_alt_monat, vpi_neu, vpi_neu_monat,
	 * prozent, neu, moeglich (Sperrfrist abgelaufen und Werte vorhanden).
	 */
	public static function indexmieten(): array {
		global $wpdb;
		$index = [];
		foreach ( $wpdb->get_results( 'SELECT mv_id, optionen FROM ' . $wpdb->prefix . 'mv_vertraege' ) as $v ) {
			$o = json_decode( (string) $v->optionen, true );
			if ( is_array( $o ) && ( $o['mietart'] ?? '' ) === 'index' ) {
				$index[ (int) $v->mv_id ] = true;
			}
		}
		$erh = [];
		foreach ( $wpdb->get_results( 'SELECT mv_id, wirksam_ab, begruendung FROM ' . $wpdb->prefix . "mv_erhoehungen WHERE art = 'index' ORDER BY wirksam_ab ASC, id ASC" ) as $e ) {
			$index[ (int) $e->mv_id ] = true;
			$erh[ (int) $e->mv_id ]   = $e; // letzte gewinnt
		}
		if ( ! $index ) {
			return [];
		}
		$heute   = current_time( 'Y-m-d' );
		$aktuell = self::letzter();
		$out     = [];
		foreach ( Mietv_Soll::alle_mv( true ) as $mv ) {
			if ( empty( $index[ (int) $mv->id ] ) ) {
				continue;
			}
			$k          = Mietv_Soll::konditionen( (int) $mv->id, substr( $heute, 0, 7 ) . '-01' );
			$miete      = $k ? (float) $k->kaltmiete : 0.0;
			$festlegung = self::letzte_festlegung( $mv );
			$vpi_alt    = null;
			$alt_monat  = '';
			$e          = $erh[ (int) $mv->id ] ?? null;
			if ( $e && $e->wirksam_ab >= $festlegung ) {
				$paar = self::vpi_aus_begruendung( $e->begruendung );
				if ( $paar ) {
					$vpi_alt   = $paar[1];
					$alt_monat = $e->wirksam_ab;
				}
			}
			if ( $vpi_alt === null ) {
				$vpi_alt   = self::wert( $festlegung );
				$alt_monat = substr( $festlegung, 0, 7 ) . '-01';
			}
			$vpi_neu = $aktuell['wert'] ?? null;
			$calc    = $vpi_alt && $vpi_neu ? self::anpassung( $miete, $vpi_alt, $vpi_neu ) : null;
			$out[]   = [
				'mv'              => $mv,
				'label'           => Mietv_Soll::mietv_label( $mv ),
				'festlegung'      => $festlegung,
				'sperrfrist_ende' => self::sperrfrist_ende( $festlegung ),
				'miete'           => $miete,
				'vpi_alt'         => $vpi_alt,
				'vpi_alt_monat'   => $alt_monat,
				'vpi_neu'         => $vpi_neu,
				'vpi_neu_monat'   => $aktuell['monat'] ?? '',
				'prozent'         => $calc['prozent'] ?? null,
				'neu'             => $calc['neu'] ?? null,
				'moeglich'        => $calc && $miete > 0 && self::anpassung_moeglich( $festlegung, $heute ),
			];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Destatis / CSV                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * GENESIS-Flat-CSV (ffcsv) tolerant parsen → [ 'YYYY-MM-01' => wert ].
	 * Unterstützt das Breitformat (Spalte „PREIS1__Verbraucherpreisindex__2020=100“) und das
	 * Langformat (Spalten „value“, „value_variable_code“ = PREIS1). Monat als MONATnn-Code oder deutscher Monatsname.
	 */
	public static function parse_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = preg_split( '/\r\n|\r|\n/', trim( (string) $csv, " \t\n\r\0\x0B" ) );
		if ( count( $lines ) < 2 ) {
			return [];
		}
		$trenner   = substr_count( $lines[0], ';' ) >= substr_count( $lines[0], ',' ) ? ';' : ',';
		$kopf      = array_map( fn( $h ) => strtolower( trim( $h, " \t\"" ) ), str_getcsv( $lines[0], $trenner, '"', '\\' ) );
		$monate    = [
			'januar'    => 1,
			'februar'   => 2,
			'märz'      => 3,
			'maerz'     => 3,
			'april'     => 4,
			'mai'       => 5,
			'juni'      => 6,
			'juli'      => 7,
			'august'    => 8,
			'september' => 9,
			'oktober'   => 10,
			'november'  => 11,
			'dezember'  => 12,
		];
		$wert_col  = null;
		$zeit_col  = null;
		$code_col  = null;
		$label_col = null;
		foreach ( $kopf as $i => $h ) {
			if ( $wert_col === null && strpos( $h, 'verbraucherpreisindex' ) !== false && strpos( $h, 'label' ) === false ) {
				$wert_col = $i;
			}
			if ( $h === 'value' ) {
				$wert_col = $wert_col ?? $i;
			}
			if ( $zeit_col === null && ( $h === 'zeit' || $h === 'time' ) ) {
				$zeit_col = $i;
			}
			if ( $h === 'value_variable_code' ) {
				$code_col = $i;
			}
			if ( $h === 'value_variable_label' ) {
				$label_col = $i;
			}
		}
		$out = [];
		foreach ( array_slice( $lines, 1 ) as $line ) {
			if ( trim( $line, " \t\n\r\0\x0B" ) === '' ) {
				continue;
			}
			$c = array_map( fn( $x ) => trim( $x, " \t\"" ), str_getcsv( $line, $trenner, '"', '\\' ) );
			// Langformat: nur Zeilen des Index selbst (nicht die Veränderungsraten).
			if ( $code_col !== null && strtoupper( $c[ $code_col ] ?? '' ) !== 'PREIS1' ) {
				continue;
			}
			if ( $code_col === null && $label_col !== null && stripos( $c[ $label_col ] ?? '', 'verbraucherpreisindex' ) === false ) {
				continue;
			}
			$jahr = 0;
			if ( $zeit_col !== null && preg_match( '/^(19|20)\d{2}$/', $c[ $zeit_col ] ?? '' ) ) {
				$jahr = (int) $c[ $zeit_col ];
			}
			$monat = 0;
			foreach ( $c as $x ) {
				if ( ! $jahr && preg_match( '/^(19|20)\d{2}$/', $x ) ) {
					$jahr = (int) $x;
				}
				if ( ! $monat && preg_match( '/^MONAT(\d{2})$/i', $x, $m ) ) {
					$monat = (int) $m[1];
				} elseif ( ! $monat && isset( $monate[ mb_strtolower( $x ) ] ) ) {
					$monat = $monate[ mb_strtolower( $x ) ];
				}
			}
			if ( ! $jahr || $monat < 1 || $monat > 12 ) {
				continue;
			}
			$wert = 0.0;
			if ( $wert_col !== null ) {
				$wert = self::zahl( $c[ $wert_col ] ?? '' );
			} else {
				// letzte numerische Spalte
				for ( $i = count( $c ) - 1; $i >= 0; $i-- ) {
					if ( preg_match( '/^-?\d+([.,]\d+)?$/', $c[ $i ] ) ) {
						$wert = self::zahl( $c[ $i ] );
						break;
					}
				}
			}
			if ( $wert > 0 ) {
				$out[ sprintf( '%04d-%02d-01', $jahr, $monat ) ] = $wert;
			}
		}
		ksort( $out );
		return $out;
	}

	/** „120,3“ / „120.3“ → float, Platzhalter („...“, „-“, „.“) → 0. */
	private static function zahl( string $s ): float {
		return preg_match( '/^-?\d+([.,]\d+)?$/', trim( $s, " \t\n\r\0\x0B" ) ) ? (float) str_replace( ',', '.', trim( $s, " \t\n\r\0\x0B" ) ) : 0.0;
	}

	/** Geparste Werte speichern; liefert Anzahl. */
	public static function importieren( array $werte, string $quelle ): int {
		$n = 0;
		foreach ( $werte as $monat => $wert ) {
			if ( self::setzen( (string) $monat, (float) $wert, $quelle ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * VPI-Monatswerte von GENESIS-Online (Destatis) abrufen und speichern.
	 * Liefert [ 'n' => Anzahl, 'von' => erster Monat, 'bis' => letzter Monat ] oder WP_Error.
	 */
	public static function abrufen() {
		$user = (string) Mietv_Einstellungen::get( 'vpi_user', 'GAST' );
		$pass = (string) Mietv_Einstellungen::get( 'vpi_pass', 'GAST' );
		$url  = self::API . '?' . http_build_query(
			[
				'username'  => $user,
				'password'  => $pass,
				'name'      => self::TABELLE,
				'area'      => 'all',
				'compress'  => 'false',
				'transpose' => 'false',
				'startyear' => (int) current_time( 'Y' ) - 2,
				'format'    => 'ffcsv',
				'language'  => 'de',
			]
		);
		$r    = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => [ 'Accept' => 'text/csv, application/json' ],
			]
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'mietv_vpi_http', sprintf( /* translators: %s: Fehlermeldung */ __( 'Destatis could not be reached: %s', 'mietfux' ), $r->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = (string) wp_remote_retrieve_body( $r );
		if ( $code !== 200 ) {
			return new WP_Error( 'mietv_vpi_http', sprintf( /* translators: %d: HTTP-Status */ __( 'Destatis answered with HTTP status %d.', 'mietfux' ), $code ) );
		}
		if ( trim( $body, " \t\n\r\0\x0B" ) === '' ) {
			return new WP_Error( 'mietv_vpi_leer', __( 'Destatis returned an empty response.', 'mietfux' ) );
		}
		if ( strpos( ltrim( $body, " \t\n\r\0\x0B" ), '{' ) === 0 ) {
			// GENESIS meldet Fehler (Zugangsdaten, Tabelle, Auftrag) als JSON.
			$j = json_decode( $body, true );
			$m = $j['Status']['Content'] ?? ( $j['Content'] ?? __( 'unknown error', 'mietfux' ) );
			return new WP_Error( 'mietv_vpi_api', sprintf( /* translators: %s: Meldung von Destatis */ __( 'Destatis reported: %s', 'mietfux' ), is_string( $m ) ? $m : wp_json_encode( $m ) ) );
		}
		$werte = self::parse_csv( $body );
		if ( ! $werte ) {
			return new WP_Error( 'mietv_vpi_parse', __( 'The Destatis response could not be interpreted (no monthly index values found).', 'mietfux' ) );
		}
		$n = self::importieren( $werte, 'destatis' );
		return [
			'n'   => $n,
			'von' => array_key_first( $werte ),
			'bis' => array_key_last( $werte ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mietv_vpi_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_vpi_nonce'] ?? '' ) ), 'mietv_vpi' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		$jahr = isset( $_POST['jahr'] ) ? (int) $_POST['jahr'] : (int) current_time( 'Y' );
		if ( ! empty( $_POST['abrufen'] ) ) {
			$r = self::abrufen();
			if ( is_wp_error( $r ) ) {
				mietv_flash_set( 'fehler', [ 'text' => $r->get_error_message() ] );
			} else {
				mietv_flash_set( 'import', $r );
			}
		} elseif ( ! empty( $_POST['csv_import'] ) ) {
			$tmp = isset( $_FILES['mietv_vpi_datei']['tmp_name'] ) ? sanitize_text_field( $_FILES['mietv_vpi_datei']['tmp_name'] ) : '';
			if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
				mietv_flash_set( 'fehler', [ 'text' => __( 'Please choose a CSV file.', 'mietfux' ) ] );
			} else {
				$werte = self::parse_csv( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Hochgeladene Datei lokal lesen.
				if ( ! $werte ) {
					mietv_flash_set( 'fehler', [ 'text' => __( 'No monthly index values found in the file. Expected: GENESIS flat CSV (ffcsv) of table 61111-0002.', 'mietfux' ) ] );
				} else {
					mietv_flash_set(
						'import',
						[
							'n'   => self::importieren( $werte, 'manuell' ),
							'von' => array_key_first( $werte ),
							'bis' => array_key_last( $werte ),
						]
					);
				}
			}
		} elseif ( ! empty( $_POST['loeschen'] ) ) {
			self::loeschen( sanitize_text_field( wp_unslash( $_POST['loeschen'] ) ) );
			mietv_flash_set( 'ok', [ 'n' => 0 ] );
		} else {
			$n = 0;
			foreach ( map_deep( wp_unslash( (array) ( $_POST['wert'] ?? [] ) ), 'sanitize_text_field' ) as $monat => $wert ) {
				$wert = mietv_to_float( $wert );
				if ( $wert > 0 && self::setzen( (string) $monat, $wert, 'manuell' ) ) {
					++$n;
				}
			}
			$monat = sanitize_text_field( wp_unslash( $_POST['neu_monat'] ?? '' ) );
			$wert  = mietv_to_float( sanitize_text_field( wp_unslash( $_POST['neu_wert'] ?? '' ) ) );
			if ( $monat && $wert > 0 && self::setzen( $monat, $wert, 'manuell' ) ) {
				++$n;
				$jahr = (int) substr( self::monat_norm( $monat ), 0, 4 );
			}
			mietv_flash_set( 'ok', [ 'n' => $n ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_vpi&jahr=' . $jahr ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		$jetzt = (int) current_time( 'Y' );
		$jahr  = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : $jetzt; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Consumer price index (CPI)', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		$code  = $flash['code'] ?? '';
		if ( $code === 'ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %d: Anzahl */ esc_html__( '%d index value(s) saved.', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ) );
		} elseif ( $code === 'import' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %1$d: Anzahl, %2$s: Monat, %3$s: Monat */ esc_html__( '%1$d index value(s) imported (%2$s – %3$s).', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ), esc_html( mietv_monat( $flash['von'] ?? '' ) ), esc_html( mietv_monat( $flash['bis'] ?? '' ) ) );
		} elseif ( $code === 'fehler' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) ( $flash['text'] ?? '' ) ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'Consumer price index for Germany (Federal Statistical Office, base 2020 = 100). Index-linked rents (§ 557b BGB) may be adjusted in line with the index change, at the earliest one year after the last rent setting. Enter the monthly values here, upload the Destatis CSV or fetch the values directly from GENESIS-Online.', 'mietfux' ) . '</p>';

		$letzter = self::letzter();
		$reihe   = self::reihe( $jahr );
		$vorjahr = $letzter ? self::wert( gmdate( 'Y-m-01', strtotime( $letzter['monat'] . ' -12 months' ) ) ) : null;
		printf(
			'<div class="mv-kacheln"><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div><div class="mv-kachel"><span>%s</span><strong>%s</strong><small>%s</small></div><div class="mv-kachel"><span>%s</span><strong>%d</strong><small>%s</small></div></div>',
			esc_html__( 'Latest CPI', 'mietfux' ),
			esc_html( $letzter ? number_format( $letzter['wert'], 1, ',', '.' ) : '–' ),
			esc_html( $letzter ? mietv_monat( $letzter['monat'] ) : __( 'no values yet', 'mietfux' ) ),
			esc_html__( 'Change vs. previous year', 'mietfux' ),
			esc_html( $letzter && $vorjahr ? sprintf( '%+.1f %%', ( $letzter['wert'] / $vorjahr - 1 ) * 100 ) : '–' ),
			esc_html( $letzter && $vorjahr ? mietv_monat( gmdate( 'Y-m-01', strtotime( $letzter['monat'] . ' -12 months' ) ) ) . ' → ' . mietv_monat( $letzter['monat'] ) : '' ),
			esc_html__( 'Index-linked tenancies', 'mietfux' ),
			count( self::indexmieten() ),
			esc_html__( 'active', 'mietfux' )
		);

		// Indexmieten
		echo '<h2>' . esc_html__( 'Index-linked rents – adjustment possible?', 'mietfux' ) . '</h2>';
		$liste = self::indexmieten();
		if ( ! $liste ) {
			echo '<p class="description">' . esc_html__( 'No active tenancy with index-linked rent. Set the rent type to “Index-linked rent” in the tenancy agreement options, or record an index adjustment under “Rent increase”.', 'mietfux' ) . '</p>';
		} else {
			$heute = current_time( 'Y-m-d' );
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Tenancy', 'mietfux' ) . '</th><th>' . esc_html__( 'Last rent setting', 'mietfux' ) . '</th><th>' . esc_html__( 'CPI then', 'mietfux' ) . '</th><th>' . esc_html__( 'Current CPI', 'mietfux' ) . '</th><th>' . esc_html__( 'Change', 'mietfux' ) . '</th><th>' . esc_html__( 'Base rent', 'mietfux' ) . '</th><th>' . esc_html__( 'Possible new rent', 'mietfux' ) . '</th><th>' . esc_html__( 'Earliest adjustment', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $liste as $z ) {
				$frei = $z['sperrfrist_ende'] <= $heute;
				$link = '';
				if ( $z['vpi_alt'] && $z['vpi_neu'] ) {
					$u    = admin_url( 'admin.php?page=mietv_erhoehung&mv_id=' . (int) $z['mv']->id . '&art=index&vpi_alt=' . rawurlencode( number_format( (float) $z['vpi_alt'], 2, '.', '' ) ) . '&vpi_neu=' . rawurlencode( number_format( (float) $z['vpi_neu'], 2, '.', '' ) ) );
					$link = '<a href="' . esc_url( $u ) . '" class="button' . ( $z['moeglich'] ? ' button-primary' : '' ) . '">' . esc_html__( 'Prepare adjustment', 'mietfux' ) . '</a>';
				}
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td><td class="%s">%s</td><td>%s</td></tr>',
					esc_html( $z['label'] ),
					esc_html( mietv_date( $z['festlegung'] ) ),
					esc_html( $z['vpi_alt'] ? number_format( (float) $z['vpi_alt'], 1, ',', '.' ) . ' (' . mietv_monat( $z['vpi_alt_monat'] ) . ')' : __( 'unknown – enter the CPI of the month of the rent setting', 'mietfux' ) ),
					esc_html( $z['vpi_neu'] ? number_format( (float) $z['vpi_neu'], 1, ',', '.' ) . ' (' . mietv_monat( $z['vpi_neu_monat'] ) . ')' : '–' ),
					$z['prozent'] !== null && $z['prozent'] >= 1 ? 'mv-pos' : '',
					esc_html( $z['prozent'] !== null ? sprintf( '%+.2f %%', $z['prozent'] ) : '–' ),
					esc_html( mietv_eur( $z['miete'] ) ),
					esc_html( $z['neu'] !== null ? mietv_eur( $z['neu'] ) : '–' ),
					$frei ? 'mv-pos' : 'mv-neg',
					esc_html( mietv_date( $z['sperrfrist_ende'] ) . ( $frei ? '' : ' (' . __( 'blocked', 'mietfux' ) . ')' ) ),
					wp_kses( $link, mietv_kses_html_tags() )
				);
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'New rent = current base rent × current CPI ÷ CPI at the last rent setting. The adjustment must be declared in text form stating the index change and the new rent; it takes effect at the start of the second month after receipt (§ 557b (3) BGB). “Prepare adjustment” opens the rent increase page with the values pre-filled.', 'mietfux' ) . '</p>';
		}

		// Jahresfilter
		echo '<h2>' . esc_html__( 'Monthly index values', 'mietfux' ) . '</h2>';
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_vpi"><label>' . esc_html__( 'Year', 'mietfux' ) . ' <select name="jahr" data-autosubmit="1">';
		for ( $j = $jetzt + 1; $j >= $jetzt - 10; $j-- ) {
			printf( '<option value="%d"%s>%d</option>', (int) $j, selected( $jahr, $j, false ), (int) $j );
		}
		echo '</select></label></form>';

		// Tabelle + Inline-Formular
		echo '<form method="post">';
		wp_nonce_field( 'mietv_vpi', 'mietv_vpi_nonce' );
		printf( '<input type="hidden" name="jahr" value="%d">', (int) $jahr );
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Month', 'mietfux' ) . '</th><th>' . esc_html__( 'CPI (2020 = 100)', 'mietfux' ) . '</th><th>' . esc_html__( 'vs. previous year', 'mietfux' ) . '</th><th>' . esc_html__( 'Source', 'mietfux' ) . '</th><th>' . esc_html__( 'Changed', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
		$heute_m = current_time( 'Y-m-01' );
		for ( $m = 1; $m <= 12; $m++ ) {
			$monat = sprintf( '%04d-%02d-01', $jahr, $m );
			if ( $monat > $heute_m ) {
				break;
			}
			$r  = $reihe[ $monat ] ?? null;
			$vj = $r ? self::wert( sprintf( '%04d-%02d-01', $jahr - 1, $m ) ) : null;
			printf(
				'<tr><td>%s</td><td><input type="text" name="wert[%s]" value="%s" class="small-text mv-num" inputmode="decimal"></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mietv_monat( $monat ) ),
				esc_attr( $monat ),
				esc_attr( $r ? number_format( $r->wert, 1, ',', '' ) : '' ),
				esc_html( $r && $vj ? sprintf( '%+.1f %%', ( $r->wert / $vj - 1 ) * 100 ) : '–' ),
				esc_html( $r ? ( $r->quelle === 'destatis' ? __( 'Destatis', 'mietfux' ) : __( 'manual', 'mietfux' ) ) : '' ),
				esc_html( $r && $r->geaendert ? mietv_date( $r->geaendert ) : '' ),
				$r ? '<button name="loeschen" value="' . esc_attr( $monat ) . '" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete this index value?', 'mietfux' ) . '">×</button>' : ''
			);
		}
		echo '</tbody></table>';
		printf(
			'<p><label>%s <input type="month" name="neu_monat" value=""></label> <label>%s <input type="text" name="neu_wert" value="" class="small-text mv-num" inputmode="decimal" placeholder="120,3"></label> <button class="button button-primary">%s</button></p>',
			esc_html__( 'Add month', 'mietfux' ),
			esc_html__( 'Value', 'mietfux' ),
			esc_html__( 'Save values', 'mietfux' )
		);
		echo '</form>';

		// Destatis / CSV
		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:360px;flex:1"><h3>' . esc_html__( 'Fetch from Destatis', 'mietfux' ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_vpi', 'mietv_vpi_nonce' );
		printf( '<input type="hidden" name="jahr" value="%d">', (int) $jahr );
		printf( '<p class="description">' . /* translators: %1$s: Tabellennummer, %2$s: Benutzername */ esc_html__( 'Loads table %1$s (CPI Germany, months) of the last three years from GENESIS-Online and stores all monthly values. Access: the credentials from the settings (vpi_user / vpi_pass) or the guest login “%2$s”.', 'mietfux' ) . '</p>', esc_html( self::TABELLE ), esc_html( (string) Mietv_Einstellungen::get( 'vpi_user', 'GAST' ) ) );
		echo '<p><button name="abrufen" value="1" class="button button-primary">' . esc_html__( 'Fetch from Destatis', 'mietfux' ) . '</button></p></form></div>';
		echo '<div class="mv-kachel" style="min-width:360px;flex:1"><h3>' . esc_html__( 'Import CSV file', 'mietfux' ) . '</h3><form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'mietv_vpi', 'mietv_vpi_nonce' );
		printf( '<input type="hidden" name="jahr" value="%d">', (int) $jahr );
		echo '<p class="description">' . esc_html__( 'Without API access: download table 61111-0002 from GENESIS-Online as “flat CSV” (ffcsv) and upload it here. Existing months are overwritten.', 'mietfux' ) . '</p>';
		echo '<p><input type="file" name="mietv_vpi_datei" accept=".csv,text/csv"> <button name="csv_import" value="1" class="button">' . esc_html__( 'Import', 'mietfux' ) . '</button></p></form></div></div>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Hinweise / Hilfe                                                   */
	/* ------------------------------------------------------------------ */

	public static function hinweise(): array {
		$h = [];
		foreach ( self::indexmieten() as $z ) {
			if ( $z['moeglich'] && $z['prozent'] !== null && $z['prozent'] >= 1 ) {
				$h[] = [
					'typ'  => 'info',
					'text' => sprintf( /* translators: %1$s: Mietverhältnis, %2$s: Prozent, %3$s: neue Miete */ __( 'Index-linked rent %1$s: adjustment by %2$s possible (new rent %3$s)', 'mietfux' ), $z['label'], sprintf( '%+.2f %%', $z['prozent'] ), mietv_eur( $z['neu'] ) ),
					'url'  => admin_url( 'admin.php?page=mietv_vpi' ),
				];
			}
		}
		return $h;
	}

	public static function hilfe(): array {
		return [
			'vpi' => [
				__( 'Consumer price index (CPI)', 'mietfux' ),
				[
					__( 'Monthly consumer price index for Germany (Destatis, base 2020 = 100). Enter values by hand, upload the GENESIS flat CSV of table 61111-0002 or fetch it directly (guest login works without registration; your own GENESIS credentials can be stored in the settings as vpi_user / vpi_pass).', 'mietfux' ),
					__( 'Index-linked rent (§ 557b BGB): a tenancy counts as index-linked if the agreement option “rent type” is set to index-linked rent or an index adjustment has already been recorded. The table shows the CPI at the last rent setting (from the last index adjustment, otherwise the CPI of the month the rent was set), the current CPI, the change and the possible new base rent.', 'mietfux' ),
					__( 'The rent may be adjusted at the earliest one year after the last setting; the declaration must be in text form stating the index change and the new rent and takes effect at the start of the second month after receipt. “Prepare adjustment” opens the rent increase page with the index values pre-filled.', 'mietfux' ),
				],
			],
		];
	}
}
