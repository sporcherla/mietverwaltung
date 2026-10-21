<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mv_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'mv_' . $name;
}

function mv_eur( $value ): string {
	return number_format( (float) $value, 2, ',', '.' ) . ' €';
}

function mv_date( ?string $date ): string {
	if ( empty( $date ) || $date === '0000-00-00' ) {
		return '–';
	}
	$ts = strtotime( $date );
	return $ts ? date_i18n( 'd.m.Y', $ts ) : '–';
}

function mv_monat( ?string $date ): string {
	if ( empty( $date ) ) {
		return '–';
	}
	$ts = strtotime( $date );
	return $ts ? date_i18n( 'm/Y', $ts ) : '–';
}

/** "1.234,56" oder "1234.56" -> float */
function mv_to_float( $value ): float {
	$value = trim( (string) $value );
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

function mv_mieter_name( $mieter ): string {
	if ( ! $mieter ) {
		return '–';
	}
	$n = trim( $mieter->nachname . ', ' . $mieter->vorname, ', ' );
	if ( ! empty( $mieter->p_nachname ) ) {
		$n .= ' & ' . trim( $mieter->p_vorname . ' ' . $mieter->p_nachname );
	}
	return $n;
}

/** Hat der Mieter eine zweite Vertragspartei? */
function mv_hat_partner( $mieter ): bool {
	return $mieter && ! empty( $mieter->p_nachname );
}

/** Alle Vertragsparteien als Zeilen „Anrede Vorname Nachname“ (für Empfängerblock, Vertrag). */
function mv_mieter_personen( $mieter, bool $mit_geburtsdatum = false ): array {
	$out = [];
	if ( ! $mieter ) {
		return $out;
	}
	$f     = function ( $anrede, $vorname, $nachname, $geb ) use ( $mit_geburtsdatum ) {
		$z = trim( ( $anrede ? $anrede . ' ' : '' ) . $vorname . ' ' . $nachname );
		if ( $mit_geburtsdatum && $geb && $geb !== '0000-00-00' ) {
			$z .= ', geb. ' . mv_date( $geb );
		}
		return $z;
	};
	$out[] = $f( $mieter->anrede ?? '', $mieter->vorname, $mieter->nachname, $mieter->geburtsdatum ?? null );
	if ( mv_hat_partner( $mieter ) ) {
		$out[] = $f( $mieter->p_anrede ?? '', $mieter->p_vorname, $mieter->p_nachname, $mieter->p_geburtsdatum ?? null );
	}
	return $out;
}
