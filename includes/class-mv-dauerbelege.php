<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

/**
 * Dauerbelege: wiederkehrende Ausgaben mit festem Rhythmus (Grundsteuer, Gebäudeversicherung,
 * Schornsteinfeger, Wartung, Kontoführung …). Je Dauerbeleg werden die Fälligkeiten aus
 * erster Fälligkeit und Intervall berechnet; jede gebuchte Fälligkeit erzeugt genau einen
 * Beleg (Zahlungsdatum = Abfluss, Leistungszeitraum = Intervall ab Fälligkeit), der über
 * dauerbeleg_id auf den Dauerbeleg verweist. Der Bankimport erkennt Zahlungen über IBAN
 * oder Lieferantenname und bucht die nächste offene Fälligkeit.
 */
class Mietv_Dauerbelege {

	public const INTERVALLE = [
		'monatlich'        => 1,
		'vierteljaehrlich' => 3,
		'halbjaehrlich'    => 6,
		'jaehrlich'        => 12,
	];

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'handle' ] );
	}

	/** CREATE-TABLE-Strings im dbDelta-Format (werden von Mietv_DB eingehängt). */
	public static function tabellen( string $charset ): array {
		global $wpdb;
		return [
			'CREATE TABLE ' . $wpdb->prefix . "mv_dauerbelege (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 1,
  objekt_id bigint(20) unsigned NOT NULL,
  kostenart_id bigint(20) unsigned NOT NULL,
  bezeichnung varchar(190) NOT NULL DEFAULT '',
  lieferant varchar(190) NOT NULL DEFAULT '',
  iban varchar(40) NOT NULL DEFAULT '',
  betrag decimal(10,2) NOT NULL DEFAULT 0,
  intervall varchar(20) NOT NULL DEFAULT 'jaehrlich',
  erste_faelligkeit date NOT NULL,
  ende date DEFAULT NULL,
  aktiv tinyint(1) NOT NULL DEFAULT 1,
  notiz text,
  PRIMARY KEY  (id),
  KEY objekt_id (objekt_id),
  KEY aktiv (aktiv)
) $charset;",
		];
	}

	/** Anzeigenamen der Intervalle. */
	public static function intervall_namen(): array {
		return [
			'monatlich'        => __( 'monthly', 'mietfux' ),
			'vierteljaehrlich' => __( 'quarterly', 'mietfux' ),
			'halbjaehrlich'    => __( 'half-yearly', 'mietfux' ),
			'jaehrlich'        => __( 'yearly', 'mietfux' ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Daten                                                              */
	/* ------------------------------------------------------------------ */

	/** Alle Dauerbelege, aktive zuerst, dann nach Objekt und Bezeichnung. */
	public static function alle( bool $nur_aktive = false ): array {
		global $wpdb;
		if ( $nur_aktive ) {
			return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_dauerbelege WHERE aktiv = 1 ORDER BY objekt_id, bezeichnung' );
		}
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_dauerbelege ORDER BY aktiv DESC, objekt_id, bezeichnung' );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_dauerbelege WHERE id = %d', $id ) );
	}

	/** Kostenarten als id => Objekt. */
	public static function kostenarten(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mv_kostenarten ORDER BY sortierung, name', OBJECT_K );
	}

	/** Objekte als id => Objekt. */
	public static function objekte(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, bezeichnung FROM ' . $wpdb->prefix . 'mv_objekte ORDER BY bezeichnung', OBJECT_K );
	}

	/**
	 * Datum um $monate Monate verschieben; der Tag wird auf den Monatsletzten begrenzt
	 * (31.01. + 1 Monat = 28./29.02.), ohne dass der ursprüngliche Tag verloren geht.
	 */
	private static function verschieben( string $basis, int $monate ): string {
		[ $j, $m, $t ] = array_map( 'intval', explode( '-', $basis ) );
		$idx           = $m - 1 + $monate; // Monate seit Januar des Basisjahres (0-basiert).
		$j            += intdiv( $idx, 12 );
		$m             = $idx % 12 + 1;
		$max           = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $j ) );
		return sprintf( '%04d-%02d-%02d', $j, $m, min( $t, $max ) );
	}

	/** Fälligkeit Nr. $i (0 = erste Fälligkeit). */
	private static function termin( object $d, int $i ): string {
		return self::verschieben( $d->erste_faelligkeit, $i * ( self::INTERVALLE[ $d->intervall ] ?? 12 ) );
	}

	/** Alle Fälligkeitstermine von erste_faelligkeit bis $bis (einschließlich), begrenzt durch ende. */
	public static function faelligkeiten( object $d, string $bis ): array {
		$out = [];
		if ( empty( $d->erste_faelligkeit ) || ! isset( self::INTERVALLE[ $d->intervall ] ) ) {
			return $out;
		}
		if ( ! empty( $d->ende ) && $d->ende !== '0000-00-00' && $d->ende < $bis ) {
			$bis = $d->ende;
		}
		for ( $i = 0; $i < 1200; $i++ ) {
			$t = self::termin( $d, $i );
			if ( $t > $bis ) {
				break;
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Leistungszeitraum [von, bis] des Intervalls, das mit der Fälligkeit beginnt. */
	public static function zeitraum( object $d, string $faelligkeit ): array {
		for ( $i = 0; $i < 1200; $i++ ) {
			$t = self::termin( $d, $i );
			if ( $t >= $faelligkeit ) {
				$naechste = self::termin( $d, $i + 1 );
				return [ $t, gmdate( 'Y-m-d', strtotime( $naechste . ' -1 day' ) ) ];
			}
		}
		return [ $faelligkeit, $faelligkeit ];
	}

	/** Gebuchte Belege eines Dauerbelegs: [ Fälligkeitsdatum (= zeitraum_von) => Beleg ]. */
	public static function gebucht( int $dauerbeleg_id ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mv_belege WHERE dauerbeleg_id = %d ORDER BY zeitraum_von', $dauerbeleg_id ) ) as $b ) {
			$out[ $b->zeitraum_von ] = $b;
		}
		return $out;
	}

	/**
	 * Eine Fälligkeit buchen: erzeugt genau einen Beleg. Liefert die Beleg-ID oder 0
	 * (unbekannter Dauerbeleg, Fälligkeit nicht im Plan, bereits gebucht).
	 */
	public static function buchen( int $dauerbeleg_id, string $faelligkeit, string $datum, ?float $betrag = null ): int {
		global $wpdb;
		$d = self::get( $dauerbeleg_id );
		if ( ! $d || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $faelligkeit ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
			return 0;
		}
		if ( ! in_array( $faelligkeit, self::faelligkeiten( $d, $faelligkeit ), true ) ) {
			return 0;
		}
		if ( isset( self::gebucht( $dauerbeleg_id )[ $faelligkeit ] ) ) {
			return 0;
		}
		[ $von, $bis ] = self::zeitraum( $d, $faelligkeit );
		$betrag        = $betrag !== null && $betrag > 0 ? $betrag : (float) $d->betrag;
		$ok            = $wpdb->insert(
			mietv_table( 'belege' ),
			[
				'owner_id'         => MIETV_OWNER_ID,
				'objekt_id'        => (int) $d->objekt_id,
				'kostenart_id'     => (int) $d->kostenart_id,
				'datum'            => $datum,
				'steuerjahr'       => (int) substr( $datum, 0, 4 ),
				'betrag'           => round( $betrag, 2 ),
				'zeitraum_von'     => $von,
				'zeitraum_bis'     => $bis,
				'lieferant'        => $d->lieferant,
				'belegnr'          => 'DB-' . (int) $d->id . '-' . $faelligkeit,
				'beschreibung'     => $d->bezeichnung . ' ' . mietv_date( $von ) . ' – ' . mietv_date( $bis ),
				'verteilung_jahre' => 1,
				'dauerbeleg_id'    => (int) $d->id,
			]
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Gebuchten Beleg eines Dauerbelegs löschen (nur Belege mit dauerbeleg_id). */
	public static function loeschen_buchung( int $beleg_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'mv_belege WHERE id = %d AND dauerbeleg_id IS NOT NULL', $beleg_id ) );
	}

	/** Offene (fällige, nicht gebuchte) Termine bis heute je aktivem Dauerbeleg: [ id => [ 'Y-m-d', … ] ]. */
	public static function offene(): array {
		$out   = [];
		$heute = current_time( 'Y-m-d' );
		foreach ( self::alle( true ) as $d ) {
			$gebucht = self::gebucht( (int) $d->id );
			$fehlt   = [];
			foreach ( self::faelligkeiten( $d, $heute ) as $t ) {
				if ( ! isset( $gebucht[ $t ] ) ) {
					$fehlt[] = $t;
				}
			}
			if ( $fehlt ) {
				$out[ (int) $d->id ] = $fehlt;
			}
		}
		return $out;
	}

	/** Nächste Fälligkeit nach heute (oder null, wenn beendet). */
	public static function naechste( object $d ): ?string {
		$heute = current_time( 'Y-m-d' );
		if ( ! empty( $d->ende ) && $d->ende !== '0000-00-00' && $d->ende < $heute ) {
			return null;
		}
		for ( $i = 0; $i < 1200; $i++ ) {
			$t = self::termin( $d, $i );
			if ( $t >= $heute ) {
				return ( ! empty( $d->ende ) && $d->ende !== '0000-00-00' && $t > $d->ende ) ? null : $t;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Bankimport                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Passenden Dauerbeleg zu einer Bankzeile ermitteln: Ausgabe (betrag < 0) und
	 * IBAN gleich oder Lieferantenname (> 3 Zeichen) im Text (Name + Verwendungszweck, kleingeschrieben).
	 */
	public static function erkennen( array $row, string $text ): ?object {
		static $cache = null;
		if ( ( $row['betrag'] ?? 0 ) >= 0 ) {
			return null;
		}
		if ( $cache === null ) {
			$cache = self::alle( true );
		}
		$text = mb_strtolower( $text );
		$iban = preg_replace( '/\s+/', '', (string) ( $row['iban'] ?? '' ) );
		foreach ( $cache as $d ) {
			$iban_ok = $d->iban !== '' && $iban !== '' && strcasecmp( preg_replace( '/\s+/', '', $d->iban ), $iban ) === 0;
			$name_ok = mb_strlen( $d->lieferant ) > 3 && mb_strpos( $text, mb_strtolower( $d->lieferant ) ) !== false;
			if ( $iban_ok || $name_ok ) {
				return $d;
			}
		}
		return null;
	}

	/** Aus dem Bankimport: nächste nicht gebuchte Fälligkeit ≤ Zahlungsdatum + 10 Tage buchen. Liefert Beleg-ID oder 0. */
	public static function buchen_aus_bank( object $d, string $datum, float $betrag ): int {
		$bis     = gmdate( 'Y-m-d', strtotime( $datum . ' +10 days' ) );
		$gebucht = self::gebucht( (int) $d->id );
		foreach ( self::faelligkeiten( $d, $bis ) as $t ) {
			if ( ! isset( $gebucht[ $t ] ) ) {
				return self::buchen( (int) $d->id, $t, $datum, abs( $betrag ) );
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------------ */
	/* Hinweise / Hilfe                                                   */
	/* ------------------------------------------------------------------ */

	public static function hinweise(): array {
		$h   = [];
		$obj = self::objekte();
		$ids = [];
		foreach ( self::alle( true ) as $d ) {
			$ids[ (int) $d->id ] = $d;
		}
		foreach ( self::offene() as $id => $termine ) {
			$d = $ids[ $id ] ?? null;
			if ( ! $d ) {
				continue;
			}
			$h[] = [
				'typ'  => 'warn',
				'text' => sprintf(
					/* translators: %1$s: Bezeichnung, %2$s: Objekt, %3$s: Termine */
					__( 'Recurring expense “%1$s” (%2$s) due but not yet booked: %3$s', 'mietfux' ),
					$d->bezeichnung,
					$obj[ $d->objekt_id ]->bezeichnung ?? '#' . $d->objekt_id,
					implode( ', ', array_map( 'mietv_date', $termine ) )
				),
				'url'  => admin_url( 'admin.php?page=mietv_dauerbelege&id=' . (int) $id ),
			];
		}
		return $h;
	}

	public static function hilfe(): array {
		return [
			'dauerbelege' => [
				__( 'Recurring expenses', 'mietfux' ),
				[
					__( 'Recurring costs with a fixed rhythm – property tax (quarterly), building insurance (yearly), chimney sweep, maintenance contracts, account fees (monthly). Enter supplier, amount per due date, interval and first due date once; the due dates are calculated from there.', 'mietfux' ),
					__( 'Booking a due date creates one receipt: payment date = outflow (Anlage V), service period = the interval starting at the due date (service charge statement). Booked receipts stay even if the recurring expense is deactivated or deleted. The bank import recognises payments by the supplier’s IBAN or name and books the next open due date.', 'mietfux' ),
				],
			],
		];
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( empty( $_POST['mietv_dauerbelege_nonce'] ) || ! current_user_can( Mietv_Admin::CAP ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mietv_dauerbelege_nonce'] ?? '' ) ), 'mietv_dauerbelege' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mietfux' ) );
		}
		global $wpdb;
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$jahr = isset( $_POST['jahr'] ) ? (int) $_POST['jahr'] : (int) current_time( 'Y' );

		if ( ! empty( $_POST['speichern'] ) ) {
			$intervall = sanitize_key( $_POST['intervall'] ?? 'jaehrlich' );
			$erste     = sanitize_text_field( wp_unslash( $_POST['erste_faelligkeit'] ?? '' ) );
			$ende      = sanitize_text_field( wp_unslash( $_POST['ende'] ?? '' ) );
			$daten     = [
				'owner_id'          => MIETV_OWNER_ID,
				'objekt_id'         => isset( $_POST['objekt_id'] ) ? (int) $_POST['objekt_id'] : 0,
				'kostenart_id'      => isset( $_POST['kostenart_id'] ) ? (int) $_POST['kostenart_id'] : 0,
				'bezeichnung'       => sanitize_text_field( wp_unslash( $_POST['bezeichnung'] ?? '' ) ),
				'lieferant'         => sanitize_text_field( wp_unslash( $_POST['lieferant'] ?? '' ) ),
				'iban'              => strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['iban'] ?? '' ) ) ) ),
				'betrag'            => round( abs( mietv_to_float( sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '' ) ) ) ), 2 ),
				'intervall'         => isset( self::INTERVALLE[ $intervall ] ) ? $intervall : 'jaehrlich',
				'erste_faelligkeit' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $erste ) ? $erste : current_time( 'Y-m-d' ),
				'ende'              => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ende ) ? $ende : null,
				'aktiv'             => empty( $_POST['aktiv'] ) ? 0 : 1,
				'notiz'             => sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
			];
			if ( ! $daten['objekt_id'] || ! $daten['kostenart_id'] || $daten['bezeichnung'] === '' ) {
				mietv_flash_set( 'fehlt' );
			} elseif ( $id && self::get( $id ) ) {
				$wpdb->update( mietv_table( 'dauerbelege' ), $daten, [ 'id' => $id ] );
				mietv_flash_set( 'gespeichert' );
			} else {
				$wpdb->insert( mietv_table( 'dauerbelege' ), $daten );
				$id = (int) $wpdb->insert_id;
				mietv_flash_set( 'gespeichert' );
			}
		} elseif ( ! empty( $_POST['aktiv_umschalten'] ) ) {
			$d = self::get( $id );
			if ( $d ) {
				$wpdb->update( mietv_table( 'dauerbelege' ), [ 'aktiv' => (int) $d->aktiv ? 0 : 1 ], [ 'id' => $id ] );
			}
			mietv_flash_set( 'gespeichert' );
		} elseif ( ! empty( $_POST['dauerbeleg_loeschen'] ) ) {
			// Nur der Dauerbeleg wird gelöscht; gebuchte Belege bleiben erhalten (dauerbeleg_id wird gelöst).
			$wpdb->delete( mietv_table( 'dauerbelege' ), [ 'id' => $id ] );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'mv_belege SET dauerbeleg_id = NULL WHERE dauerbeleg_id = %d', $id ) );
			mietv_flash_set( 'geloescht' );
			$id = 0;
		} elseif ( ! empty( $_POST['loeschen'] ) ) {
			$bid = (int) $_POST['loeschen'];
			$b   = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mv_belege WHERE id = %d AND dauerbeleg_id = %d', $bid, $id ) );
			if ( $b ) {
				self::loeschen_buchung( $bid );
			}
			mietv_flash_set( 'ok', [ 'n' => 0 ] );
		} elseif ( ! empty( $_POST['buchen'] ) ) {
			$zahltag  = sanitize_text_field( wp_unslash( $_POST['zahltag'] ?? '' ) );
			$zahltag  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $zahltag ) ? $zahltag : '';
			$markiert = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['termin'] ?? [] ) ) );
			sort( $markiert );
			$n = 0;
			foreach ( $markiert as $t ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $t ) ) {
					continue;
				}
				if ( self::buchen( $id, $t, $zahltag ?: $t ) ) {
					++$n;
				}
			}
			mietv_flash_set( 'ok', [ 'n' => $n ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mietv_dauerbelege&jahr=' . $jahr . ( $id ? '&id=' . $id : '' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                              */
	/* ------------------------------------------------------------------ */

	public static function page(): void {
		$objekte = self::objekte();
		$ka      = self::kostenarten();
		$jetzt   = (int) current_time( 'Y' );
		$heute   = current_time( 'Y-m-d' );
		$id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$jahr    = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : $jetzt; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$neu     = ! empty( $_GET['action'] ) && sanitize_key( $_GET['action'] ) === 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Anzeigeparameter, keine Datenänderung.
		$d       = $id ? self::get( $id ) : null;
		$namen   = self::intervall_namen();
		$base    = admin_url( 'admin.php?page=mietv_dauerbelege&jahr=' . $jahr );

		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Recurring expenses', 'mietfux' ) . '</h1>';
		$flash = mietv_flash_get();
		$code  = $flash['code'] ?? '';
		if ( $code === 'ok' ) {
			printf( '<div class="notice notice-success is-dismissible"><p>' . /* translators: %d: Wert */ esc_html__( '%d entry/entries created.', 'mietfux' ) . '</p></div>', (int) ( $flash['n'] ?? 0 ) );
		} elseif ( $code === 'gespeichert' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recurring expense saved.', 'mietfux' ) . '</p></div>';
		} elseif ( $code === 'geloescht' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recurring expense deleted – booked receipts remain.', 'mietfux' ) . '</p></div>';
		} elseif ( $code === 'fehlt' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please fill in property, cost type and description.', 'mietfux' ) . '</p></div>';
		}
		if ( ! $objekte ) {
			printf( '<p>' . esc_html__( 'No property created yet –', 'mietfux' ) . ' <a href="%s">' . esc_html__( 'Create property', 'mietfux' ) . '</a></p></div>', esc_url( admin_url( 'admin.php?page=mietv_objekte&action=new' ) ) );
			return;
		}
		echo '<p class="description">' . esc_html__( 'Recurring costs with a fixed rhythm (property tax, building insurance, chimney sweep, maintenance, account fees). Each booked due date creates one receipt with the payment date as outflow and the interval as service period.', 'mietfux' ) . '</p>';

		// Filter
		echo '<form method="get" class="mv-filter"><input type="hidden" name="page" value="mietv_dauerbelege">';
		if ( $id ) {
			printf( '<input type="hidden" name="id" value="%d">', (int) $id );
		}
		echo '<label>' . esc_html__( 'Year', 'mietfux' ) . ' <select name="jahr" data-autosubmit="1">';
		for ( $j = $jetzt + 1; $j >= $jetzt - 6; $j-- ) {
			printf( '<option value="%d"%s>%d</option>', (int) $j, selected( $jahr, $j, false ), (int) $j );
		}
		echo '</select></label> <a href="' . esc_url( $base . '&action=new' ) . '" class="button">' . esc_html__( 'New recurring expense', 'mietfux' ) . '</a></form>';

		// Liste
		$alle   = self::alle();
		$offene = self::offene();
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Property', 'mietfux' ) . '</th><th>' . esc_html__( 'Description', 'mietfux' ) . '</th><th>' . esc_html__( 'Cost type', 'mietfux' ) . '</th><th>' . esc_html__( 'Interval', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th><th>' . esc_html__( 'Next due date', 'mietfux' ) . '</th><th>' . esc_html__( 'Open / booked', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $alle ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No recurring expenses yet.', 'mietfux' ) . '</td></tr>';
		}
		foreach ( $alle as $r ) {
			$n_offen = count( $offene[ (int) $r->id ] ?? [] );
			$n_geb   = count( self::gebucht( (int) $r->id ) );
			$naechst = self::naechste( $r );
			printf(
				'<tr%s><td>%s</td><td><a href="%s">%s</a>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="button button-small">%s</a></td></tr>',
				(int) $r->aktiv ? '' : ' style="opacity:.55"',
				esc_html( $objekte[ $r->objekt_id ]->bezeichnung ?? '#' . $r->objekt_id ),
				esc_url( $base . '&id=' . (int) $r->id ),
				esc_html( $r->bezeichnung ),
				(int) $r->aktiv ? '' : ' <small>(' . esc_html__( 'inactive', 'mietfux' ) . ')</small>',
				esc_html( $ka[ $r->kostenart_id ]->name ?? '–' ),
				esc_html( $namen[ $r->intervall ] ?? $r->intervall ),
				esc_html( mietv_eur( $r->betrag ) ),
				esc_html( $naechst ? mietv_date( $naechst ) : '–' ),
				( $n_offen ? '<span class="mv-neg">' . (int) $n_offen . '</span>' : '0' ) . ' / ' . (int) $n_geb,
				esc_url( $base . '&id=' . (int) $r->id ),
				esc_html__( 'Open', 'mietfux' )
			);
		}
		echo '</tbody></table>';

		if ( ! $d && ! $neu ) {
			echo '</div>';
			return;
		}

		echo '<div class="mv-kacheln">';

		// Fälligkeiten des Jahres
		if ( $d ) {
			$gebucht = self::gebucht( (int) $d->id );
			$termine = [];
			foreach ( self::faelligkeiten( $d, $jahr . '-12-31' ) as $t ) {
				if ( substr( $t, 0, 4 ) === (string) $jahr ) {
					$termine[ $t ] = true;
				}
			}
			foreach ( $gebucht as $t => $b ) {
				if ( substr( (string) $t, 0, 4 ) === (string) $jahr ) {
					$termine[ $t ] = true;
				}
			}
			ksort( $termine );
			echo '<div class="mv-kachel" style="min-width:420px;flex:1"><h3>' . esc_html( sprintf( /* translators: %1$s: Bezeichnung, %2$d: Jahr */ __( 'Due dates %1$s – %2$d', 'mietfux' ), $d->bezeichnung, $jahr ) ) . '</h3>';
			echo '<form method="post">';
			wp_nonce_field( 'mietv_dauerbelege', 'mietv_dauerbelege_nonce' );
			printf( '<input type="hidden" name="id" value="%d"><input type="hidden" name="jahr" value="%d">', (int) $d->id, (int) $jahr );
			echo '<table class="wp-list-table widefat fixed striped mv-op"><thead><tr><th class="mv-op-check"><input type="checkbox" data-check-all="tbody input[type=checkbox]"></th><th>' . esc_html__( 'Due date', 'mietfux' ) . '</th><th>' . esc_html__( 'Service period', 'mietfux' ) . '</th><th>' . esc_html__( 'Amount', 'mietfux' ) . '</th><th>' . esc_html__( 'Payment date', 'mietfux' ) . '</th><th>' . esc_html__( 'Status', 'mietfux' ) . '</th><th></th></tr></thead><tbody>';
			$summe   = 0.0;
			$n_offen = 0;
			if ( ! $termine ) {
				echo '<tr><td colspan="7">' . esc_html__( 'No due dates in this year.', 'mietfux' ) . '</td></tr>';
			}
			foreach ( array_keys( $termine ) as $t ) {
				$b     = $gebucht[ $t ] ?? null;
				$offen = ! $b && $t <= $heute;
				if ( $offen ) {
					++$n_offen;
				}
				if ( $b ) {
					$summe += (float) $b->betrag;
					$von    = $b->zeitraum_von;
					$bis    = $b->zeitraum_bis;
				} else {
					[ $von, $bis ] = self::zeitraum( $d, $t );
				}
				printf(
					'<tr%s><td class="mv-op-check">%s</td><td>%s</td><td><small>%s – %s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					$b ? '' : ' style="opacity:.75"',
					$b ? '✓' : ( (int) $d->aktiv ? '<input type="checkbox" name="termin[]" value="' . esc_attr( $t ) . '">' : '' ),
					esc_html( mietv_date( $t ) ),
					esc_html( mietv_date( $von ) ),
					esc_html( mietv_date( $bis ) ),
					esc_html( mietv_eur( $b ? $b->betrag : $d->betrag ) ),
					esc_html( $b ? mietv_date( $b->datum ) : '–' ),
					esc_html( $b ? __( 'booked', 'mietfux' ) : ( $offen ? __( 'open', 'mietfux' ) : __( 'future', 'mietfux' ) ) ),
					$b ? '<button name="loeschen" value="' . (int) $b->id . '" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete this receipt?', 'mietfux' ) . '">×</button>' : ''
				);
			}
			echo '</tbody><tfoot><tr><th></th><th>' . esc_html__( 'Total', 'mietfux' ) . '</th><th></th><th>' . esc_html( mietv_eur( $summe ) ) . '</th><th colspan="3"></th></tr></tfoot></table>';
			if ( (int) $d->aktiv && $termine ) {
				printf(
					'<p><label>%s <input type="date" name="zahltag" value=""></label> <span class="description">%s</span><br><button name="buchen" value="1" class="button button-primary" data-require-checked="input[name=\'termin[]\']" data-require-msg="%s">%s</button></p>',
					esc_html__( 'Payment date', 'mietfux' ),
					esc_html__( 'Leave empty to use the due date as payment date.', 'mietfux' ),
					esc_attr__( 'Please tick at least one due date.', 'mietfux' ),
					esc_html__( 'Book selected due dates', 'mietfux' )
				);
			}
			echo '</form></div>';
		}

		// Formular Anlegen / Bearbeiten
		echo '<div class="mv-kachel" style="min-width:420px;flex:1"><h3>' . esc_html( $d ? __( 'Edit recurring expense', 'mietfux' ) : __( 'New recurring expense', 'mietfux' ) ) . '</h3><form method="post">';
		wp_nonce_field( 'mietv_dauerbelege', 'mietv_dauerbelege_nonce' );
		printf( '<input type="hidden" name="id" value="%d"><input type="hidden" name="jahr" value="%d">', (int) ( $d->id ?? 0 ), (int) $jahr );
		echo '<p><label>' . esc_html__( 'Property', 'mietfux' ) . '<br><select name="objekt_id">';
		foreach ( $objekte as $o ) {
			printf( '<option value="%d"%s>%s</option>', (int) $o->id, selected( (int) ( $d->objekt_id ?? 0 ), (int) $o->id, false ), esc_html( $o->bezeichnung ) );
		}
		echo '</select></label></p><p><label>' . esc_html__( 'Cost type', 'mietfux' ) . '<br><select name="kostenart_id"><option value="">' . esc_html__( '– cost type –', 'mietfux' ) . '</option>';
		foreach ( $ka as $k ) {
			if ( $k->anlage_v === 'einnahmen' ) {
				continue;
			}
			printf( '<option value="%d"%s>%s</option>', (int) $k->id, selected( (int) ( $d->kostenart_id ?? 0 ), (int) $k->id, false ), esc_html( $k->name ) );
		}
		echo '</select></label></p>';
		printf(
			'<p><label>%s<br><input type="text" name="bezeichnung" value="%s" class="regular-text" required placeholder="%s"></label></p><p><label>%s<br><input type="text" name="lieferant" value="%s" class="regular-text"></label></p><p><label>%s<br><input type="text" name="iban" value="%s" class="regular-text"></label><br><span class="description">%s</span></p>',
			esc_html__( 'Description', 'mietfux' ),
			esc_attr( $d->bezeichnung ?? '' ),
			esc_attr__( 'e.g. Property tax', 'mietfux' ),
			esc_html__( 'Supplier', 'mietfux' ),
			esc_attr( $d->lieferant ?? '' ),
			esc_html__( 'Supplier’s IBAN', 'mietfux' ),
			esc_attr( $d->iban ?? '' ),
			esc_html__( 'Used by the bank import to recognise payments automatically (IBAN or supplier name).', 'mietfux' )
		);
		echo '<p><label>' . esc_html__( 'Amount per due date (€)', 'mietfux' ) . ' <input type="text" name="betrag" value="' . esc_attr( $d ? number_format( (float) $d->betrag, 2, ',', '' ) : '' ) . '" class="mv-num" inputmode="decimal" style="width:110px"></label> &nbsp; <label>' . esc_html__( 'Interval', 'mietfux' ) . ' <select name="intervall">';
		foreach ( $namen as $k => $v ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $d->intervall ?? 'jaehrlich', $k, false ), esc_html( $v ) );
		}
		echo '</select></label></p>';
		printf(
			'<p><label>%s <input type="date" name="erste_faelligkeit" value="%s" required></label> &nbsp; <label>%s <input type="date" name="ende" value="%s"></label></p><p><label><input type="checkbox" name="aktiv" value="1"%s> %s</label></p><p><label>%s<br><textarea name="notiz" rows="2" class="large-text">%s</textarea></label></p>',
			esc_html__( 'First due date', 'mietfux' ),
			esc_attr( $d->erste_faelligkeit ?? $heute ),
			esc_html__( 'End (optional)', 'mietfux' ),
			esc_attr( ! empty( $d->ende ) && $d->ende !== '0000-00-00' ? $d->ende : '' ),
			checked( (int) ( $d->aktiv ?? 1 ), 1, false ),
			esc_html__( 'Active', 'mietfux' ),
			esc_html__( 'Note', 'mietfux' ),
			esc_textarea( $d->notiz ?? '' )
		);
		echo '<p class="description">' . esc_html__( 'Property tax is typically due on 15 Feb, 15 May, 15 Aug and 15 Nov (quarterly) – enter 15 Feb as the first due date. Later due dates follow the same day of month; the interval starting at each due date is used as service period.', 'mietfux' ) . '</p>';
		echo '<p><button name="speichern" value="1" class="button button-primary">' . esc_html__( 'Save recurring expense', 'mietfux' ) . '</button>';
		if ( $d ) {
			echo ' <button name="aktiv_umschalten" value="1" class="button">' . esc_html( (int) $d->aktiv ? __( 'Deactivate', 'mietfux' ) : __( 'Activate', 'mietfux' ) ) . '</button>';
			echo ' <button name="dauerbeleg_loeschen" value="1" class="button-link mv-delete" data-confirm="' . esc_attr__( 'Delete this recurring expense? Booked receipts remain.', 'mietfux' ) . '">' . esc_html__( 'Delete', 'mietfux' ) . '</button>';
		}
		echo '</p></form></div></div></div>';
	}
}
