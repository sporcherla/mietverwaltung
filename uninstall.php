<?php
/**
 * Wird beim Löschen des Plugins über die Plugin-Seite ausgeführt.
 * Daten werden nur entfernt, wenn in den Einstellungen „Daten bei Deinstallation löschen“ aktiv ist.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
$einst = get_option( 'mv_einstellungen', [] );
if ( empty( $einst['loeschen_bei_deinstallation'] ) || $einst['loeschen_bei_deinstallation'] !== '1' ) {
	return; // Daten bewusst behalten
}
global $wpdb;
$tabellen = [ 'objekte', 'einheiten', 'mieter', 'mietverhaeltnisse', 'konditionen', 'zahlungen', 'meldungen', 'kostenarten', 'belege', 'anlagegueter', 'darlehen', 'darlehen_jahre', 'dokumente', 'zaehler', 'zaehlerstaende', 'abrechnungen', 'abrechnung_ergebnis', 'bank_regeln', 'bank_import', 'mahnungen', 'erhoehungen', 'vertraege', 'kaution', 'mv_einheiten', 'darlehen_raten' ];
foreach ( $tabellen as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mv_' . $t );  // phpcs:ignore WordPress.DB.PreparedSQL -- Tabellenname aus fester Liste.
}
foreach ( [ 'mv_db_version', 'mv_einstellungen', 'mv_hinweis_hash', 'mv_hinweis_datum', 'mv_anlagev_zeilen' ] as $o ) {
	delete_option( $o );
}
delete_metadata( 'user', 0, 'mv_fokus', '', true );
remove_role( 'mv_vermieter' );
$admin = get_role( 'administrator' );
if ( $admin ) {
	$admin->remove_cap( 'mv_verwalten' );
}
wp_clear_scheduled_hook( 'mv_taeglich' );
