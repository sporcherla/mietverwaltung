<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.

function mietv_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'mv_' . $name;
}

function mietv_eur( $value ): string {
	return number_format( (float) $value, 2, ',', '.' ) . ' €';
}

function mietv_date( ?string $date ): string {
	if ( empty( $date ) || $date === '0000-00-00' ) {
		return '–';
	}
	$ts = strtotime( $date );
	return $ts ? date_i18n( 'd.m.Y', $ts ) : '–';
}

function mietv_monat( ?string $date ): string {
	if ( empty( $date ) ) {
		return '–';
	}
	$ts = strtotime( $date );
	return $ts ? date_i18n( 'm/Y', $ts ) : '–';
}

/** "1.234,56" oder "1234.56" -> float */
function mietv_to_float( $value ): float {
	$value = trim( (string) $value, " \t\n\r\0\x0B" );
	if ( $value === '' ) {
		return 0.0;
	}
	$value = preg_replace( '/[^\d,.\-]/', '', $value );
	if ( strpos( $value, ',' ) !== false ) {
		// deutsch: 1.234,56
		$value = str_replace( '.', '', $value );
		$value = str_replace( ',', '.', $value );
	} elseif ( preg_match( '/^-?\d{1,3}(\.\d{3})+$/', $value ) ) {
		// 1.200 ohne Nachkommastellen = Tausenderpunkt
		$value = str_replace( '.', '', $value );
	}
	return (float) $value;
}

function mietv_mieter_name( $mieter ): string {
	if ( ! $mieter ) {
		return '–';
	}
	$n = trim( $mieter->nachname . ', ' . $mieter->vorname, ', ' );
	if ( ! empty( $mieter->p_nachname ) ) {
		$n .= ' & ' . trim( $mieter->p_vorname . ' ' . $mieter->p_nachname, " \t\n\r\0\x0B" );
	}
	return $n;
}

/** Kurzlebige Statusmeldung je Benutzer (statt ?msg= in der URL). */
function mietv_flash_set( string $code, array $extra = [] ): void {
	set_transient( 'mietv_flash_' . get_current_user_id(), array_merge( [ 'code' => $code ], $extra ), 120 );
}

/** Statusmeldung abholen und löschen. */
function mietv_flash_get(): array {
	$k = 'mietv_flash_' . get_current_user_id();
	$f = get_transient( $k );
	if ( $f ) {
		delete_transient( $k );
	}
	return is_array( $f ) ? $f : [];
}

/** Hat der Mieter eine zweite Vertragspartei? */
function mietv_hat_partner( $mieter ): bool {
	return $mieter && ! empty( $mieter->p_nachname );
}

/** Alle Vertragsparteien als Zeilen „Anrede Vorname Nachname“ (für Empfängerblock, Vertrag). */
function mietv_mieter_personen( $mieter, bool $mit_geburtsdatum = false ): array {
	$out = [];
	if ( ! $mieter ) {
		return $out;
	}
	$f     = function ( $anrede, $vorname, $nachname, $geb ) use ( $mit_geburtsdatum ) {
		$z = trim( ( $anrede ? $anrede . ' ' : '' ) . $vorname . ' ' . $nachname, " \t\n\r\0\x0B" );
		if ( $mit_geburtsdatum && $geb && $geb !== '0000-00-00' ) {
			$z .= ', geb. ' . mietv_date( $geb );
		}
		return $z;
	};
	$out[] = $f( $mieter->anrede ?? '', $mieter->vorname, $mieter->nachname, $mieter->geburtsdatum ?? null );
	if ( mietv_hat_partner( $mieter ) ) {
		$out[] = $f( $mieter->p_anrede ?? '', $mieter->p_vorname, $mieter->p_nachname, $mieter->p_geburtsdatum ?? null );
	}
	return $out;
}
