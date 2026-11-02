<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Änderungsprotokoll (Audit-Log): wer hat wann welchen Datensatz angelegt, geändert, gelöscht.
 * Rechenschaftspflicht (Art. 5 Abs. 2 DSGVO) und Nachvollziehbarkeit.
 *
 * Andere Module protokollieren über
 *   do_action( 'mietv_protokoll', $aktion, $tabelle, $id, $bezeichnung, $aenderungen );
 * Das generische Speichern/Löschen in Mietv_Admin ruft vor_speichern() / nach_speichern() / nach_loeschen().
 */
class Mietv_Protokoll {

	const CRON      = 'mietv_protokoll_aufraeumen';
	const OPTION    = 'mietv_protokoll_tage';
	const TAGE      = 365;
	const PRO_SEITE = 50;
	const AKTIONEN  = [ 'anlegen', 'aendern', 'loeschen', 'anonymisieren', 'export', 'import', 'login_portal' ];

	/** Feldnamen (Teilstrings), deren Werte nie im Klartext protokolliert werden. */
	const GEHEIM = [ 'pass', 'kennwort', 'secret', 'token', 'pin' ];

	public static function init(): void {
		add_action( 'mietv_protokoll', [ __CLASS__, 'log' ], 10, 5 );
		add_action( self::CRON, [ __CLASS__, 'aufbewahrung' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_mietv_protokoll_csv', [ __CLASS__, 'csv' ] );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			self::activate();
		}
	}

	/** In register_activation_hook einhängen (bzw. aus Mietv_DB::install aufrufen). */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:30' ), 'daily', self::CRON );
		}
	}

	/** In register_deactivation_hook einhängen. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** CREATE-TABLE im dbDelta-Format (wird von Mietv_DB eingehängt). */
	public static function tabellen( string $charset ): array {
		global $wpdb;
		return [
			'CREATE TABLE ' . $wpdb->prefix . "mv_protokoll (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zeit datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  aktion varchar(20) NOT NULL,
  tabelle varchar(40) NOT NULL DEFAULT '',
  datensatz_id bigint(20) unsigned NOT NULL DEFAULT 0,
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  aenderungen text,
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY zeit (zeit),
  KEY tabelle (tabelle, datensatz_id)
) $charset;",
		];
	}

	/* ------------------------------------------------------------------ */
	/* Protokollieren                                                     */
	/* ------------------------------------------------------------------ */

	/** Eintrag schreiben. $aenderungen: aendern → { feld: [alt, neu] }, anlegen/loeschen → { feld: wert }. */
	public static function log( string $aktion, string $tabelle, int $id, string $bezeichnung, array $aenderungen = [] ): void {
		global $wpdb;
		if ( ! in_array( $aktion, self::AKTIONEN, true ) ) {
			$aktion = 'aendern';
		}
		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$login = $user && ! empty( $user->user_login ) ? (string) $user->user_login : '';
		$uid   = (int) get_current_user_id();
		if ( ! $uid && $login === '' ) {
			$login = ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 'cron' : 'system';
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $ip !== '' ) {
			$ip = (string) wp_privacy_anonymize_ip( $ip );
		}
		$wpdb->insert(
			mietv_table( 'protokoll' ),
			[
				'zeit'         => current_time( 'mysql' ),
				'user_id'      => $uid,
				'user_login'   => mb_substr( $login, 0, 60 ),
				'aktion'       => $aktion,
				'tabelle'      => mb_substr( $tabelle, 0, 40 ),
				'datensatz_id' => $id,
				'bezeichnung'  => mb_substr( $bezeichnung, 0, 190 ),
				'aenderungen'  => $aenderungen ? wp_json_encode( self::maskieren( $aenderungen ), JSON_UNESCAPED_UNICODE ) : '',
				'ip'           => mb_substr( $ip, 0, 45 ),
			]
		);
	}

	/** Nur geänderte Felder als { feld: [alt, neu] }, Werte als Strings, Geheimnisse maskiert. */
	public static function diff( array $alt, array $neu ): array {
		$out = [];
		foreach ( $neu as $feld => $wert ) {
			if ( ! is_scalar( $wert ) && $wert !== null ) {
				$wert = wp_json_encode( $wert );
			}
			$a = self::normal( $alt[ $feld ] ?? null );
			$n = self::normal( $wert );
			if ( $a === $n ) {
				continue;
			}
			$out[ $feld ] = [ self::wert( (string) $feld, $a ), self::wert( (string) $feld, $n ) ];
		}
		return $out;
	}

	/** Vergleichswert: null → '', Zahlen ohne Nachkomma-Nullen ("500.00" == "500"). */
	private static function normal( $wert ): string {
		if ( $wert === null || $wert === false ) {
			return '';
		}
		if ( is_bool( $wert ) ) {
			return $wert ? '1' : '0';
		}
		$s = (string) $wert;
		if ( is_numeric( $s ) && preg_match( '/^-?\d+(\.\d+)?$/', $s ) ) {
			$s = rtrim( rtrim( number_format( (float) $s, 6, '.', '' ), '0' ), '.' );
			return $s === '' || $s === '-0' ? '0' : $s;
		}
		return $s;
	}

	/** Einzelwert für das Protokoll: IBAN auf die letzten 4 Stellen, Kennwörter u. ä. gar nicht. */
	private static function wert( string $feld, string $wert ): string {
		$f = strtolower( $feld );
		if ( $wert === '' ) {
			return '';
		}
		if ( strpos( $f, 'iban' ) !== false ) {
			$w = preg_replace( '/\s+/', '', $wert );
			return strlen( $w ) > 4 ? str_repeat( '*', max( 4, strlen( $w ) - 4 ) ) . substr( $w, -4 ) : '****';
		}
		foreach ( self::GEHEIM as $g ) {
			if ( strpos( $f, $g ) !== false ) {
				return '***';
			}
		}
		return $wert;
	}

	/** Maskierung auf ein Änderungs-Array anwenden (für Aufrufe über do_action / nach_loeschen). */
	private static function maskieren( array $aenderungen ): array {
		$out = [];
		foreach ( $aenderungen as $feld => $v ) {
			if ( is_array( $v ) && array_keys( $v ) === [ 0, 1 ] ) {
				$out[ $feld ] = [ self::wert( (string) $feld, self::normal( $v[0] ) ), self::wert( (string) $feld, self::normal( $v[1] ) ) ];
			} else {
				$out[ $feld ] = self::wert( (string) $feld, self::normal( is_scalar( $v ) || $v === null ? $v : wp_json_encode( $v ) ) );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Anbindung an das generische Speichern/Löschen (Mietv_Admin)        */
	/* ------------------------------------------------------------------ */

	/** Alten Datensatz laden (vor dem Update). Liefert [] bei Neuanlage. */
	public static function vor_speichern( string $key, int $id ): array {
		global $wpdb;
		if ( ! $id || ! preg_match( '/^[a-z_]+$/', $key ) ) {
			return [];
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( $key ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB -- Tabellenname stammt aus der internen Entitätsdefinition (geprüft), nicht aus Nutzereingaben.
		return is_array( $row ) ? $row : [];
	}

	/** Nach Insert/Update: anlegen (alt leer) oder aendern (nur Differenzen). */
	public static function nach_speichern( string $key, int $id, array $alt, array $neu, string $label = '' ): void {
		unset( $neu['owner_id'], $neu['created_at'] );
		if ( ! $alt ) {
			$werte = [];
			foreach ( $neu as $f => $v ) {
				$werte[ $f ] = $v === null ? '' : ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
			}
			self::log( 'anlegen', $key, $id, self::bezeichnung( $key, $neu, $label ), $werte );
			return;
		}
		$d = self::diff( $alt, $neu );
		if ( ! $d ) {
			return;
		}
		self::log( 'aendern', $key, $id, self::bezeichnung( $key, array_merge( $alt, $neu ), $label ), $d );
	}

	/** Nach Delete: alte Werte protokollieren. */
	public static function nach_loeschen( string $key, int $id, array $alt, string $label = '' ): void {
		unset( $alt['owner_id'], $alt['created_at'] );
		self::log( 'loeschen', $key, $id, self::bezeichnung( $key, $alt, $label ), $alt );
	}

	/** Lesbarer Bezug: „Label – sprechendes Feld“, z. B. „Tenant – Beispiel, Anna“. */
	public static function bezeichnung( string $key, array $row, string $label = '' ): string {
		$felder = [
			'objekte'           => [ 'bezeichnung' ],
			'einheiten'         => [ 'bezeichnung' ],
			'mieter'            => [ 'nachname', 'vorname' ],
			'mietverhaeltnisse' => [ 'beginn', 'ende' ],
			'konditionen'       => [ 'gueltig_ab', 'kaltmiete' ],
			'zahlungen'         => [ 'datum', 'betrag' ],
			'belege'            => [ 'datum', 'lieferant', 'beschreibung' ],
			'zaehler'           => [ 'typ', 'nummer' ],
			'zaehlerstaende'    => [ 'datum', 'stand' ],
			'bank_regeln'       => [ 'muster' ],
			'anlagegueter'      => [ 'bezeichnung' ],
			'darlehen'          => [ 'bank', 'bezeichnung' ],
			'dokumente'         => [ 'bezeichnung', 'datum' ],
			'darlehen_jahre'    => [ 'jahr', 'zinsen' ],
			'kostenarten'       => [ 'name' ],
			'meldungen'         => [ 'datum', 'betreff' ],
			'hausgeld'          => [ 'gueltig_ab', 'verwalter' ],
		];
		$teile  = [];
		foreach ( $felder[ $key ] ?? [ 'bezeichnung', 'name', 'datum' ] as $f ) {
			$v = isset( $row[ $f ] ) && is_scalar( $row[ $f ] ) ? trim( (string) $row[ $f ], " \t\n\r\0\x0B" ) : '';
			if ( $v === '' || $v === '0000-00-00' ) {
				continue;
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$v = mietv_date( $v );
			} elseif ( in_array( $f, [ 'betrag', 'kaltmiete', 'zinsen', 'stand' ], true ) && is_numeric( $v ) ) {
				$v = $f === 'stand' ? $v : mietv_eur( $v );
			}
			$teile[] = $v;
		}
		$sep  = $key === 'mieter' ? ', ' : ' ';
		$text = implode( $sep, $teile );
		if ( $text === '' ) {
			$text = '#' . (int) ( $row['id'] ?? 0 );
		}
		return trim( ( $label !== '' ? $label . ' – ' : '' ) . $text, " \t\n\r\0\x0B" );
	}

	/* ------------------------------------------------------------------ */
	/* Aufbewahrung                                                       */
	/* ------------------------------------------------------------------ */

	public static function tage(): int {
		$t = (int) get_option( self::OPTION, self::TAGE );
		return $t > 0 ? $t : self::TAGE;
	}

	/** Einträge älter als die Frist löschen. Liefert die Anzahl. */
	public static function aufbewahrung(): int {
		global $wpdb;
		$dt = new DateTime( current_time( 'mysql' ) );
		$dt->modify( '-' . self::tage() . ' days' );
		$grenze = $dt->format( 'Y-m-d H:i:s' );
		$n      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_protokoll WHERE zeit < %s', $grenze ) );
		if ( $n ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'mv_protokoll WHERE zeit < %s', $grenze ) );
		}
		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen (Einstellung, Aufräumen, CSV)                             */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mietv_protokoll_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_protokoll_nonce'] ?? '' ) ), 'mietv_protokoll_einstellungen' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		if ( ! empty( $_POST['aufraeumen'] ) ) {
			mietv_flash_set( 'aufgeraeumt', [ 'n' => self::aufbewahrung() ] );
		} else {
			$tage = isset( $_POST['tage'] ) ? (int) $_POST['tage'] : self::TAGE;
			update_option( self::OPTION, max( 30, min( 3650, $tage ) ) );
			mietv_flash_set( 'gespeichert' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_protokoll' ) );
		exit;
	}

	/** Filter aus der URL (nur lesende Anzeigeparameter). */
	private static function filter(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Lesende Anzeigeparameter, keine Datenänderung.
		$g = fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		$d = fn( string $v ): string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		$f = [
			'von'     => $d( $g( 'von' ) ),
			'bis'     => $d( $g( 'bis' ) ),
			'aktion'  => in_array( $g( 'aktion' ), self::AKTIONEN, true ) ? $g( 'aktion' ) : '',
			'tabelle' => sanitize_key( $g( 'tabelle' ) ),
			'user'    => mb_substr( $g( 'user' ), 0, 60 ),
			'q'       => mb_substr( $g( 'q' ), 0, 100 ),
			'paged'   => max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ),
		];
		// phpcs:enable
		return $f;
	}

	/** WHERE-Klausel mit Platzhaltern und zugehörigen Werten. */
	private static function where( array $f ): array {
		global $wpdb;
		$w = [ 'id > %d' ];
		$a = [ 0 ];
		if ( $f['von'] ) {
			$w[] = 'zeit >= %s';
			$a[] = $f['von'] . ' 00:00:00';
		}
		if ( $f['bis'] ) {
			$w[] = 'zeit <= %s';
			$a[] = $f['bis'] . ' 23:59:59';
		}
		foreach ( [ 'aktion', 'tabelle' ] as $k ) {
			if ( $f[ $k ] !== '' ) {
				$w[] = $k . ' = %s';
				$a[] = $f[ $k ];
			}
		}
		if ( $f['user'] !== '' ) {
			$w[] = 'user_login = %s';
			$a[] = $f['user'];
		}
		if ( $f['q'] !== '' ) {
			$w[] = 'bezeichnung LIKE %s';
			$a[] = '%' . $wpdb->esc_like( $f['q'] ) . '%';
		}
		return [ implode( ' AND ', $w ), $a ];
	}

	/** Einträge gemäß Filter, neueste zuerst. $limit 0 = alle. */
	public static function eintraege( array $f, int $limit = self::PRO_SEITE, int $offset = 0 ): array {
		global $wpdb;
		[ $where, $args ] = self::where( $f );
		$sql              = 'SELECT * FROM ' . $wpdb->prefix . 'mv_protokoll WHERE ' . $where . ' ORDER BY zeit DESC, id DESC';
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = $offset;
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Bedingungen enthalten ausschließlich Platzhalter, alle Werte werden über prepare() gebunden.
	}

	public static function anzahl( array $f ): int {
		global $wpdb;
		[ $where, $args ] = self::where( $f );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mv_protokoll WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Bedingungen enthalten ausschließlich Platzhalter, alle Werte werden über prepare() gebunden.
	}

	/** CSV-Export (admin-post), gleiche Filter wie die Seite. */
	public static function csv(): void {
		check_admin_referer( 'mietv_protokoll_csv' );
		if ( ! current_user_can( Mietv_Admin::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mietfux' ) );
		}
		$f = self::filter();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="activity-log-' . current_time( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF"; // BOM für Excel
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'ID', 'Zeit', 'Benutzer-ID', 'Benutzer', 'Aktion', 'Tabelle', 'Datensatz-ID', 'Bezeichnung', 'Änderungen', 'IP' ], ';', '"', '\\' );
		foreach ( self::eintraege( $f, 0 ) as $r ) {
			$aend  = json_decode( (string) $r->aenderungen, true ) ?: [];
			$teile = [];
			foreach ( $aend as $feld => $v ) {
				$teile[] = is_array( $v ) ? $feld . ': ' . $v[0] . ' → ' . $v[1] : $feld . ': ' . $v;
			}
			fputcsv( $out, [ $r->id, $r->zeit, $r->user_id, $r->user_login, $r->aktion, $r->tabelle, $r->datensatz_id, $r->bezeichnung, implode( ' | ', $teile ), $r->ip ], ';', '"', '\\' );
		}
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	/** Englische Labels für Aktionen. */
	public static function aktion_label( string $aktion ): string {
		$l = [
			'anlegen'       => __( 'created', 'mietfux' ),
			'aendern'       => __( 'changed', 'mietfux' ),
			'loeschen'      => __( 'deleted', 'mietfux' ),
			'anonymisieren' => __( 'anonymised', 'mietfux' ),
			'export'        => __( 'exported', 'mietfux' ),
			'import'        => __( 'imported', 'mietfux' ),
			'login_portal'  => __( 'portal login', 'mietfux' ),
		];
		return $l[ $aktion ] ?? $aktion;
	}

	/** Labels der Entitäten (wie im Menü), unbekannte Keys unverändert. */
	public static function tabelle_label( string $key ): string {
		$l = [
			'objekte'           => __( 'Properties', 'mietfux' ),
			'einheiten'         => __( 'Units', 'mietfux' ),
			'mieter'            => __( 'Tenants', 'mietfux' ),
			'mietverhaeltnisse' => __( 'Tenancies', 'mietfux' ),
			'konditionen'       => __( 'Rent conditions', 'mietfux' ),
			'zahlungen'         => __( 'Payments', 'mietfux' ),
			'belege'            => __( 'Receipts', 'mietfux' ),
			'zaehler'           => __( 'Meters', 'mietfux' ),
			'zaehlerstaende'    => __( 'Meter readings', 'mietfux' ),
			'bank_regeln'       => __( 'Bank import rules', 'mietfux' ),
			'anlagegueter'      => __( 'Depreciation', 'mietfux' ),
			'darlehen'          => __( 'Loans', 'mietfux' ),
			'dokumente'         => __( 'Documents', 'mietfux' ),
			'darlehen_jahre'    => __( 'Interest certificates', 'mietfux' ),
			'kostenarten'       => __( 'Cost types', 'mietfux' ),
			'meldungen'         => __( 'Reports', 'mietfux' ),
			'hausgeld'          => __( 'Owners’ association fee', 'mietfux' ),
		];
		return $l[ $key ] ?? $key;
	}

	public static function page(): void {
		global $wpdb;
		$f = self::filter();
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Activity log', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		$code  = $flash['code'] ?? '';
		if ( $code === 'gespeichert' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Retention period saved.', 'mietfux' ) . '</p></div>';
		} elseif ( $code === 'aufgeraeumt' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %d: Anzahl */ esc_html__( '%d old log entries deleted.', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ) );
		}
		echo '<p class="description">' . esc_html__( 'Records who created, changed or deleted which record and when (accountability, Art. 5 (2) GDPR). Only the changed fields are stored; IBANs are masked, passwords are never logged, IP addresses are anonymised.', 'mietfux' ) . '</p>';

		// Filter
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_protokoll">';
		printf( '<label>%s <input type="date" name="von" value="%s"></label> <label>%s <input type="date" name="bis" value="%s"></label> ', esc_html__( 'From', 'mietfux' ), esc_attr( $f['von'] ), esc_html__( 'To', 'mietfux' ), esc_attr( $f['bis'] ) );
		echo '<label>' . esc_html__( 'Action', 'mietfux' ) . ' <select name="aktion"><option value="">' . esc_html__( 'all', 'mietfux' ) . '</option>';
		foreach ( self::AKTIONEN as $a ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $a ), selected( $f['aktion'], $a, false ), esc_html( self::aktion_label( $a ) ) );
		}
		echo '</select></label> <label>' . esc_html__( 'Table', 'mietfux' ) . ' <select name="tabelle"><option value="">' . esc_html__( 'all', 'mietfux' ) . '</option>';
		foreach ( $wpdb->get_col( 'SELECT DISTINCT tabelle FROM ' . $wpdb->prefix . 'mv_protokoll ORDER BY tabelle' ) as $t ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $t ), selected( $f['tabelle'], $t, false ), esc_html( self::tabelle_label( (string) $t ) ) );
		}
		echo '</select></label> <label>' . esc_html__( 'User', 'mietfux' ) . ' <select name="user"><option value="">' . esc_html__( 'all', 'mietfux' ) . '</option>';
		foreach ( $wpdb->get_col( 'SELECT DISTINCT user_login FROM ' . $wpdb->prefix . 'mv_protokoll ORDER BY user_login' ) as $u ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $u ), selected( $f['user'], $u, false ), esc_html( $u ) );
		}
		printf( '</select></label> <label>%s <input type="search" name="q" value="%s"></label> <button class="button">%s</button>', esc_html__( 'Search', 'mietfux' ), esc_attr( $f['q'] ), esc_html__( 'Filter', 'mietfux' ) );
		$csv_args = array_filter( $f, fn( $v, $k ) => $k !== 'paged' && $v !== '', ARRAY_FILTER_USE_BOTH );
		printf( ' <a class="button" href="%s">%s</a></form>', esc_url( wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'mietv_protokoll_csv' ], $csv_args ), admin_url( 'admin-post.php' ) ), 'mietv_protokoll_csv' ) ), esc_html__( 'Export CSV', 'mietfux' ) );

		// Tabelle
		$gesamt = self::anzahl( $f );
		$seiten = max( 1, (int) ceil( $gesamt / self::PRO_SEITE ) );
		$paged  = min( $f['paged'], $seiten );
		$rows   = self::eintraege( $f, self::PRO_SEITE, ( $paged - 1 ) * self::PRO_SEITE );
		printf( '<p class="description">' . /* translators: %d: Anzahl */ esc_html__( '%d entries', 'mietfux' ) . '</p>', (int) $gesamt );
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:140px">' . esc_html__( 'Time', 'mietfux' ) . '</th><th style="width:120px">' . esc_html__( 'User', 'mietfux' ) . '</th><th style="width:100px">' . esc_html__( 'Action', 'mietfux' ) . '</th><th style="width:140px">' . esc_html__( 'Table', 'mietfux' ) . '</th><th>' . esc_html__( 'Record', 'mietfux' ) . '</th><th>' . esc_html__( 'Changes', 'mietfux' ) . '</th><th style="width:110px">' . esc_html__( 'IP', 'mietfux' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$ts = strtotime( (string) $r->zeit );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s <small>#%d</small></td><td>%s</td><td>%s</td></tr>',
				esc_html( $ts ? date_i18n( 'd.m.Y H:i', $ts ) : $r->zeit ),
				esc_html( $r->user_login !== '' ? $r->user_login : '#' . (int) $r->user_id ),
				esc_html( self::aktion_label( (string) $r->aktion ) ),
				esc_html( self::tabelle_label( (string) $r->tabelle ) ),
				esc_html( $r->bezeichnung ),
				(int) $r->datensatz_id,
				wp_kses(
					self::details( (string) $r->aenderungen ),
					mietv_kses_html_tags() + [
						'details' => [ 'class' => true ],
						'summary' => [],
					]
				),
				esc_html( $r->ip )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No entries.', 'mietfux' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $seiten > 1 ) {
			$links = paginate_links(
				[
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $seiten,
				]
			);
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
		}

		// Aufbewahrung
		echo '<div class="mv-kacheln"><div class="mv-kachel" style="min-width:420px"><h3>' . esc_html__( 'Retention', 'mietfux' ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_protokoll_einstellungen', 'mietv_protokoll_nonce' );
		printf(
			'<p><label>%s <input type="number" name="tage" value="%d" min="30" max="3650" class="small-text"></label> <button class="button button-primary">%s</button> <button name="aufraeumen" value="1" class="button" data-confirm="%s">%s</button></p><p class="description">%s</p></form></div></div></div>',
			esc_html__( 'Keep log entries for (days)', 'mietfux' ),
			(int) self::tage(),
			esc_html__( 'Save', 'mietfux' ),
			esc_attr__( 'Delete all log entries older than the retention period now?', 'mietfux' ),
			esc_html__( 'Clean up now', 'mietfux' ),
			esc_html__( 'Older entries are deleted automatically once a day (WP-Cron). Default: 365 days.', 'mietfux' )
		);
	}

	/** Aufklappbare Änderungsdetails (ohne JS). Rückgabe ist HTML mit bereits escapten Werten. */
	private static function details( string $json ): string {
		$aend = json_decode( $json, true );
		if ( ! is_array( $aend ) || ! $aend ) {
			return '';
		}
		$zeilen = [];
		foreach ( $aend as $feld => $v ) {
			if ( is_array( $v ) ) {
				$zeilen[] = '<tr><td><strong>' . esc_html( (string) $feld ) . '</strong></td><td class="mv-neg">' . esc_html( (string) ( $v[0] ?? '' ) ) . '</td><td class="mv-pos">' . esc_html( (string) ( $v[1] ?? '' ) ) . '</td></tr>';
			} else {
				$zeilen[] = '<tr><td><strong>' . esc_html( (string) $feld ) . '</strong></td><td colspan="2">' . esc_html( (string) $v ) . '</td></tr>';
			}
		}
		return '<details class="mv-details"><summary>' . esc_html( sprintf( /* translators: %d: Anzahl Felder */ _n( '%d field', '%d fields', count( $aend ), 'mietfux' ), count( $aend ) ) ) . '</summary><table class="widefat striped"><tbody>' . implode( '', $zeilen ) . '</tbody></table></details>';
	}

	/* ------------------------------------------------------------------ */
	/* Hilfe                                                              */
	/* ------------------------------------------------------------------ */

	public static function hilfe(): array {
		return [
			'protokoll' => [
				__( 'Activity log', 'mietfux' ),
				[
					__( 'The activity log records who created, changed or deleted which record and when. This serves the accountability principle (Art. 5 (2) GDPR) and lets you trace changes to tenant and payment data.', 'mietfux' ),
					__( 'For changes only the fields that actually changed are stored, with their old and new value. IBANs are reduced to the last four digits, passwords are never stored, and IP addresses are anonymised (last octet removed).', 'mietfux' ),
					__( 'Entries are deleted automatically after the retention period (default 365 days). Use the CSV export to archive the log before it is cleaned up, for example at year end.', 'mietfux' ),
				],
			],
		];
	}
}
