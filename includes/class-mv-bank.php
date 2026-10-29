<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Bankimport: CSV (Sparkasse, VR-Bank, ING, DKB, comdirect …) oder CAMT.053 XML.
 * Zwei Schritte: Datei einlesen → Vorschläge prüfen → übernehmen.
 */
class Mietv_Bank {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Parsen                                                             */
	/* ------------------------------------------------------------------ */

	/** Liefert Zeilen: [datum, betrag, name, iban, zweck] */
	public static function parse( string $inhalt, string $dateiname ): array {
		if ( preg_match( '/<\s*Document[\s>]/i', substr( $inhalt, 0, 2000 ) ) || stripos( $dateiname, '.xml' ) !== false ) {
			return self::parse_camt( $inhalt );
		}
		return self::parse_csv( $inhalt );
	}

	private static function parse_camt( string $xml ): array {
		$rows = [];
		libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml );
		if ( ! $doc ) {
			return $rows;
		}
		$doc->registerXPathNamespace( 'c', $doc->getNamespaces()[''] ?? 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02' );
		foreach ( $doc->xpath( '//c:Ntry' ) as $n ) {
			$n->registerXPathNamespace( 'c', $doc->getNamespaces()[''] ?? '' );
			$betrag = (float) $n->Amt;
			if ( (string) $n->CdtDbtInd === 'DBIT' ) {
				$betrag = -$betrag;
			}
			$datum = (string) ( $n->BookgDt->Dt ?: $n->ValDt->Dt );
			$name  = '';
			$iban  = '';
			$zweck = [];
			foreach ( $n->xpath( './/c:TxDtls' ) as $tx ) {
				$tx->registerXPathNamespace( 'c', $doc->getNamespaces()[''] ?? '' );
				$p    = $betrag >= 0 ? 'Dbtr' : 'Cdtr';
				$name = $name ?: (string) ( $tx->xpath( ".//c:RltdPties/c:$p/c:Nm" )[0] ?? '' );
				$iban = $iban ?: (string) ( $tx->xpath( ".//c:RltdPties/c:{$p}Acct/c:Id/c:IBAN" )[0] ?? '' );
				foreach ( $tx->xpath( './/c:RmtInf/c:Ustrd' ) as $u ) {
					$zweck[] = (string) $u;
				}
			}
			$rows[] = [
				'datum'  => substr( $datum, 0, 10 ),
				'betrag' => $betrag,
				'name'   => trim( $name, " \t\n\r\0\x0B" ),
				'iban'   => trim( $iban, " \t\n\r\0\x0B" ),
				'zweck'  => trim( implode( ' ', $zweck ), " \t\n\r\0\x0B" ),
			];
		}
		return $rows;
	}

	private static function parse_csv( string $inhalt ): array {
		if ( ! mb_check_encoding( $inhalt, 'UTF-8' ) ) {
			$inhalt = mb_convert_encoding( $inhalt, 'UTF-8', 'ISO-8859-1' );
		}
		$inhalt = ltrim( $inhalt, "\xEF\xBB\xBF" );
		$zeilen = preg_split( '/\r\n|\r|\n/', $inhalt );
		$zeilen = array_values( array_filter( $zeilen, fn( $z ) => trim( $z, " \t\n\r\0\x0B" ) !== '' ) );
		if ( ! $zeilen ) {
			return [];
		}
		// Kopfzeile finden: erste Zeile, die ein Datums- und ein Betragsfeld benennt
		$sep   = ';';
		$kopf  = null;
		$start = 0;
		foreach ( array_slice( $zeilen, 0, 15 ) as $i => $z ) {
			foreach ( [ ';', ',', "\t" ] as $s ) {
				$f = str_getcsv( $z, $s, '"', '\\' );
				$l = array_map( fn( $x ) => mb_strtolower( trim( $x, "\" \t" ) ), $f );
				if ( count( $f ) >= 3 && self::spalte( $l, [ 'buchungstag', 'buchungsdatum', 'datum', 'valuta', 'wertstellung', 'buchung' ] ) !== null && self::spalte( $l, [ 'betrag', 'umsatz', 'amount' ] ) !== null ) {
					$sep   = $s;
					$kopf  = $l;
					$start = $i + 1;
					break 2;
				}
			}
		}
		if ( $kopf === null ) {
			return [];
		}
		$c_datum  = self::spalte( $kopf, [ 'buchungstag', 'buchungsdatum', 'buchung', 'datum', 'valuta', 'wertstellung' ] );
		$c_betrag = self::spalte( $kopf, [ 'betrag', 'umsatz', 'amount' ] );
		$c_name   = self::spalte( $kopf, [ 'beguenstigter/zahlungspflichtiger', 'begünstigter/zahlungspflichtiger', 'name zahlungsbeteiligter', 'auftraggeber/empfänger', 'auftraggeber / begünstigter', 'zahlungspflichtige*r', 'zahlungsempfänger*in', 'empfänger', 'auftraggeber', 'name', 'beguenstigter', 'begünstigter' ] );
		$c_iban   = self::spalte( $kopf, [ 'iban zahlungsbeteiligter', 'kontonummer/iban', 'iban', 'kontonummer' ] );
		$c_zweck  = self::spalte( $kopf, [ 'verwendungszweck', 'buchungstext', 'vorgang/verwendungszweck', 'beschreibung', 'text' ] );
		$c_soll   = self::spalte( $kopf, [ 'soll/haben', 'soll' ] );

		$rows   = [];
		$anzahl = count( $zeilen );
		for ( $i = $start; $i < $anzahl; $i++ ) {
			$f = str_getcsv( $zeilen[ $i ], $sep, '"', '\\' );
			if ( count( $f ) < 3 ) {
				continue;
			}
			$datum = self::datum( $f[ $c_datum ] ?? '' );
			if ( ! $datum ) {
				continue;
			}
			$betrag = mietv_to_float( preg_replace( '/[^\d,.\-]/', '', $f[ $c_betrag ] ?? '0' ) );
			if ( $c_soll !== null && stripos( $f[ $c_soll ] ?? '', 's' ) === 0 && $betrag > 0 ) {
				$betrag = -$betrag;
			}
			$rows[] = [
				'datum'  => $datum,
				'betrag' => $betrag,
				'name'   => trim( $f[ $c_name ] ?? '', " \t\n\r\0\x0B" ),
				'iban'   => preg_replace( '/\s+/', '', $f[ $c_iban ] ?? '' ),
				'zweck'  => trim( $f[ $c_zweck ] ?? '', " \t\n\r\0\x0B" ),
			];
		}
		return $rows;
	}

	private static function spalte( array $kopf, array $kandidaten ): ?int {
		foreach ( $kandidaten as $k ) {
			foreach ( $kopf as $i => $h ) {
				if ( $h === $k ) {
					return $i;
				}
			}
		}
		foreach ( $kandidaten as $k ) {
			foreach ( $kopf as $i => $h ) {
				if ( strpos( $h, $k ) !== false ) {
					return $i;
				}
			}
		}
		return null;
	}

	private static function datum( string $s ): ?string {
		$s = trim( $s, '" ' );
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m ) ) {
			$y = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
			return sprintf( '%04d-%02d-%02d', $y, $m[2], $m[1] );
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $s, $m ) ) {
			return $m[0];
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Vorschläge                                                         */
	/* ------------------------------------------------------------------ */

	private static function hash( array $r ): string {
		return md5( $r['datum'] . '|' . number_format( $r['betrag'], 2, '.', '' ) . '|' . mb_strtolower( $r['name'] . $r['zweck'] ) );
	}

	/** Monat aus Verwendungszweck („Miete 03/2026“, „März 2026“, „2026-03“), sonst Buchungsmonat. */
	private static function monat_aus_zweck( string $zweck, string $datum ): string {
		$monate = [
			'januar'    => 1,
			'jan'       => 1,
			'februar'   => 2,
			'feb'       => 2,
			'märz'      => 3,
			'maerz'     => 3,
			'mrz'       => 3,
			'april'     => 4,
			'apr'       => 4,
			'mai'       => 5,
			'juni'      => 6,
			'jun'       => 6,
			'juli'      => 7,
			'jul'       => 7,
			'august'    => 8,
			'aug'       => 8,
			'september' => 9,
			'sep'       => 9,
			'oktober'   => 10,
			'okt'       => 10,
			'november'  => 11,
			'nov'       => 11,
			'dezember'  => 12,
			'dez'       => 12,
		];
		if ( preg_match( '/\b(\d{1,2})[\/\.\-](\d{4})\b/', $zweck, $m ) && $m[1] >= 1 && $m[1] <= 12 ) {
			return sprintf( '%04d-%02d-01', $m[2], $m[1] );
		}
		if ( preg_match( '/\b(\d{4})-(\d{2})\b/', $zweck, $m ) ) {
			return $m[1] . '-' . $m[2] . '-01';
		}
		if ( preg_match( '/\b(' . implode( '|', array_keys( $monate ) ) . ')\.?\s*(\d{2,4})?\b/iu', $zweck, $m ) ) {
			$y = ! empty( $m[2] ) ? ( strlen( $m[2] ) === 2 ? '20' . $m[2] : $m[2] ) : substr( $datum, 0, 4 );
			return sprintf( '%04d-%02d-01', $y, $monate[ mb_strtolower( $m[1] ) ] );
		}
		return substr( $datum, 0, 7 ) . '-01';
	}

	public static function vorschlaege( array $rows ): array {
		global $wpdb;
		$regeln = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_bank_regeln' );
		$mvs    = Mietv_Soll::alle_mv( true );
		$mieter = $wpdb->get_results( 'SELECT id, nachname, iban FROM ' . $wpdb->prefix . 'mv_mieter', OBJECT_K );
		$hashes = $wpdb->get_col( 'SELECT hash FROM ' . $wpdb->prefix . 'mv_bank_import' );
		$hashes = array_flip( $hashes );

		foreach ( $rows as &$r ) {
			$r['hash']         = self::hash( $r );
			$r['bereits']      = isset( $hashes[ $r['hash'] ] );
			$r['typ']          = 'ignorieren';
			$r['mv_id']        = 0;
			$r['kostenart_id'] = 0;
			$r['objekt_id']    = 0;
			$r['monat']        = self::monat_aus_zweck( $r['zweck'], $r['datum'] );
			$text              = mb_strtolower( $r['name'] . ' ' . $r['zweck'] );

			// Regeln zuerst
			foreach ( $regeln as $g ) {
				if ( $g->muster !== '' && mb_strpos( $text, mb_strtolower( $g->muster ) ) !== false ) {
					$r['typ']          = $g->typ;
					$r['mv_id']        = (int) $g->mv_id;
					$r['kostenart_id'] = (int) $g->kostenart_id;
					$r['objekt_id']    = (int) $g->objekt_id;
					$r['grund']        = 'Regel „' . $g->muster . '“';
					continue 2;
				}
			}
			// Eingang: Mieter über IBAN oder Nachname
			if ( $r['betrag'] > 0 ) {
				foreach ( $mvs as $mv ) {
					$m = $mieter[ $mv->mieter_id ] ?? null;
					if ( ! $m ) {
						continue;
					}
					$iban_ok = $m->iban && $r['iban'] && strcasecmp( preg_replace( '/\s+/', '', $m->iban ), $r['iban'] ) === 0;
					$name_ok = mb_strlen( $m->nachname ) > 2 && mb_strpos( $text, mb_strtolower( $m->nachname ) ) !== false;
					if ( $iban_ok || $name_ok ) {
						$r['typ']   = 'zahlung';
						$r['mv_id'] = (int) $mv->id;
						$r['grund'] = $iban_ok ? __( 'Tenant\'s IBAN', 'mietfux' ) : __( 'Tenant\'s name', 'mietfux' );
						break;
					}
				}
			} else {
				$r['typ']   = 'beleg';
				$r['grund'] = 'Ausgabe – Kostenart wählen oder überspringen';
			}
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mietv_bank_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_bank_nonce'] ?? '' ) ), 'mietv_bank' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		global $wpdb;
		$key = 'mietv_bank_' . get_current_user_id();

		if ( ! empty( $_FILES['mietv_bank_datei']['tmp_name'] ) ) {
			$rows = self::parse( (string) file_get_contents( sanitize_text_field( $_FILES['mietv_bank_datei']['tmp_name'] ?? '' ) ), sanitize_file_name( wp_unslash( $_FILES['mietv_bank_datei']['name'] ?? '' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Hochgeladene Datei lokal lesen.
			if ( ! $rows ) {
				mietv_flash_set( 'leer' );
				wp_safe_redirect( admin_url( 'admin.php?page=mietv_bank' ) );
				exit;
			}
			set_transient( $key, self::vorschlaege( $rows ), HOUR_IN_SECONDS );
			wp_safe_redirect( admin_url( 'admin.php?page=mietv_bank&schritt=pruefen' ) );
			exit;
		}

		if ( isset( $_POST['mietv_bank_uebernehmen'] ) ) {
			$rows = get_transient( $key ) ?: [];
			$n    = 0;
			foreach ( map_deep( wp_unslash( (array) ( $_POST['zeile'] ?? [] ) ), 'sanitize_text_field' ) as $i => $z ) {
				if ( empty( $z['ok'] ) || ! isset( $rows[ $i ] ) ) {
					continue;
				}
				$r   = $rows[ $i ];
				$typ = sanitize_key( $z['typ'] );
				if ( $typ === 'zahlung' && ! empty( $z['mv_id'] ) ) {
					$wpdb->insert(
						mietv_table( 'zahlungen' ),
						[
							'mv_id'            => (int) $z['mv_id'],
							'datum'            => $r['datum'],
							'betrag'           => $r['betrag'],
							'monat'            => preg_match( '/^\d{4}-\d{2}$/', $z['monat'] ) ? $z['monat'] . '-01' : $r['monat'],
							'verwendungszweck' => mb_substr( $r['zweck'], 0, 190 ),
							'quelle'           => 'bank',
						]
					);
					$ziel = [ 'zahlung', $wpdb->insert_id ];
				} elseif ( $typ === 'beleg' && ! empty( $z['kostenart_id'] ) && ! empty( $z['objekt_id'] ) ) {
					$wpdb->insert(
						mietv_table( 'belege' ),
						[
							'owner_id'         => MIETV_OWNER_ID,
							'objekt_id'        => (int) $z['objekt_id'],
							'kostenart_id'     => (int) $z['kostenart_id'],
							'datum'            => $r['datum'],
							'steuerjahr'       => (int) substr( $r['datum'], 0, 4 ),
							'betrag'           => abs( $r['betrag'] ),
							'zeitraum_von'     => $r['datum'],
							'zeitraum_bis'     => $r['datum'],
							'lieferant'        => mb_substr( $r['name'], 0, 190 ),
							'beschreibung'     => $r['zweck'],
							'verteilung_jahre' => 1,
						]
					);
					$ziel = [ 'beleg', $wpdb->insert_id ];
				} else {
					continue;
				}
				$wpdb->replace(
					mietv_table( 'bank_import' ),
					[
						'hash'       => $r['hash'],
						'datum'      => $r['datum'],
						'betrag'     => $r['betrag'],
						'ziel'       => $ziel[0],
						'ziel_id'    => $ziel[1],
						'importiert' => current_time( 'mysql' ),
					]
				);
				++$n;
				// Lernen: Regel anlegen, wenn gewünscht
				if ( ! empty( $z['regel'] ) && $r['name'] !== '' ) {
					$wpdb->insert(
						mietv_table( 'bank_regeln' ),
						[
							'owner_id'     => MIETV_OWNER_ID,
							'muster'       => mb_substr( $r['name'], 0, 60 ),
							'typ'          => $typ,
							'objekt_id'    => (int) ( $z['objekt_id'] ?? 0 ) ?: null,
							'kostenart_id' => (int) ( $z['kostenart_id'] ?? 0 ) ?: null,
							'mv_id'        => (int) ( $z['mv_id'] ?? 0 ) ?: null,
						]
					);
				}
			}
			delete_transient( $key );
			mietv_flash_set( 'ok', [ 'n' => $n ] );
			wp_safe_redirect( admin_url( 'admin.php?page=mietv_bank' ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Bank import', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		$msg   = $flash['code'] ?? '';
		if ( $msg === 'ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %d: Wert */ esc_html__( '%d transactions imported.', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ) );
		} elseif ( $msg === 'leer' ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No transactions detected. Supported: CSV with header row (booking date, amount, reference …) or CAMT.053 XML.', 'mietfux' ) . '</p></div>';
		}

		$rows = ( sanitize_key( isset( $_GET['schritt'] ) ? sanitize_key( $_GET['schritt'] ) : '' ) === 'pruefen' ) ? get_transient( 'mietv_bank_' . get_current_user_id() ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.

		if ( ! $rows ) {
			echo '<form method="post" enctype="multipart/form-data" class="mv-form">';
			wp_nonce_field( 'mietv_bank', 'mietv_bank_nonce' );
			echo '<p><input type="file" name="mietv_bank_datei" accept=".csv,.txt,.xml" required> <button class="button button-primary">' . esc_html__( 'Import', 'mietfux' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'Bank statement as CSV (online banking export) or CAMT.053 XML. Previously imported transactions are detected and not duplicated. Rent payments are matched by the tenant\'s IBAN or last name, expenses via', 'mietfux' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=mietv_bank_regeln' ) ) . '">Regeln</a>.</p></form></div>';
			return;
		}

		$opt_mv        = Mietv_Admin::opt_mv();
		$opt_ka        = Mietv_Admin::opt_kostenarten();
		$opt_ob        = Mietv_Admin::opt_objekte();
		$erstes_objekt = array_key_first( $opt_ob );

		echo '<form method="post">';
		wp_nonce_field( 'mietv_bank', 'mietv_bank_nonce' );
		echo '<p><button name="mietv_bank_uebernehmen" class="button button-primary">' . esc_html__( 'Import selected transactions', 'mietfux' ) . '</button> <a href="' . esc_url( admin_url( 'admin.php?page=mietv_bank' ) ) . '" class="button">Abbrechen</a></p>';
		echo '<table class="wp-list-table widefat striped mv-bank"><thead><tr><th class="check-column"><input type="checkbox" data-check-all="tbody input[type=checkbox]"></th><th>' . esc_html__( 'Date', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th><th>' . esc_html__( 'Name / reference', 'mietfux' ) . '</th><th>' . esc_html__( 'Create as', 'mietfux' ) . '</th><th>' . esc_html__( 'Assignment', 'mietfux' ) . '</th><th>' . esc_html__( 'Remember rule', 'mietfux' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			$dis     = $r['bereits'] ? ' disabled' : '';
			$checked = ! $r['bereits'] && $r['typ'] !== 'ignorieren' && ( $r['mv_id'] || $r['kostenart_id'] ) ? ' checked' : '';
			echo '<tr' . ( $r['bereits'] ? ' style="opacity:.5"' : '' ) . '>';
			printf( '<td><input type="checkbox" name="zeile[%d][ok]" value="1"%s%s></td>', (int) $i, esc_attr( $checked ), esc_attr( $dis ) );
			printf( '<td>%s</td><td class="%s">%s</td><td><strong>%s</strong><br><small>%s</small>%s</td>', esc_html( mietv_date( $r['datum'] ) ), $r['betrag'] < 0 ? 'mv-neg' : 'mv-pos', esc_html( mietv_eur( $r['betrag'] ) ), esc_html( $r['name'] ), esc_html( $r['zweck'] ), $r['bereits'] ? '<br><em>' . esc_html__( 'already imported', 'mietfux' ) . '</em>' : ( ! empty( $r['grund'] ) ? '<br><em>' . esc_html( $r['grund'] ) . '</em>' : '' ) );
			printf( '<td><select name="zeile[%d][typ]"><option value="zahlung"%s>' . esc_html__( 'Rent payment', 'mietfux' ) . '</option><option value="beleg"%s>' . esc_html__( 'Receipt', 'mietfux' ) . '</option><option value="ignorieren"%s>–</option></select></td>', (int) $i, selected( $r['typ'], 'zahlung', false ), selected( $r['typ'], 'beleg', false ), selected( $r['typ'], 'ignorieren', false ) );
			echo '<td>';
			// Zahlung
			printf( '<div class="mv-z-zahlung"><select name="zeile[%d][mv_id]"><option value="">' . esc_html__( '– tenancy –', 'mietfux' ) . '</option>', (int) $i );
			foreach ( $opt_mv as $k => $v ) {
				printf( '<option value="%d"%s>%s</option>', (int) $k, selected( $r['mv_id'], (int) $k, false ), esc_html( $v ) );
			}
			printf( '</select> <input type="month" name="zeile[%d][monat]" value="%s" title="Zuordnung Monat"></div>', (int) $i, esc_attr( substr( $r['monat'], 0, 7 ) ) );
			// Beleg
			printf( '<div class="mv-z-beleg"><select name="zeile[%d][objekt_id]">', (int) $i );
			foreach ( $opt_ob as $k => $v ) {
				printf( '<option value="%d"%s>%s</option>', (int) $k, selected( $r['objekt_id'] ?: $erstes_objekt, (int) $k, false ), esc_html( $v ) );
			}
			printf( '</select> <select name="zeile[%d][kostenart_id]"><option value="">' . esc_html__( '– cost type –', 'mietfux' ) . '</option>', (int) $i );
			foreach ( $opt_ka as $k => $v ) {
				printf( '<option value="%d"%s>%s</option>', (int) $k, selected( $r['kostenart_id'], (int) $k, false ), esc_html( $v ) );
			}
			echo '</select></div></td>';
			printf( '<td><label><input type="checkbox" name="zeile[%d][regel]" value="1"> „%s“</label></td>', (int) $i, esc_html( mb_substr( $r['name'], 0, 25 ) ) );
			echo '</tr>';
		}
		echo '</tbody></table><p><button name="mietv_bank_uebernehmen" class="button button-primary">' . esc_html__( 'Import selected transactions', 'mietfux' ) . '</button></p></form></div>';
	}
}
