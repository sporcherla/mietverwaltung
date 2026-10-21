<?php
/**
 * Wird beim Löschen des Plugins über die Plugin-Seite ausgeführt.
 * Daten werden nur entfernt, wenn in den Einstellungen „Daten bei Deinstallation löschen“ aktiv ist.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Das Plugin verwaltet eigene Tabellen (wp_mv_*); Caching über die WordPress-API ist dafür nicht anwendbar.
$mietv_einst = get_option( 'mietv_einstellungen', [] );
if ( empty( $mietv_einst['loeschen_bei_deinstallation'] ) || $mietv_einst['loeschen_bei_deinstallation'] !== '1' ) {
	return; // Daten bewusst behalten
}
global $wpdb;
$mietv_tabellen = [ 'objekte', 'einheiten', 'mieter', 'mietverhaeltnisse', 'konditionen', 'zahlungen', 'meldungen', 'kostenarten', 'belege', 'anlagegueter', 'darlehen', 'darlehen_jahre', 'dokumente', 'zaehler', 'zaehlerstaende', 'abrechnungen', 'abrechnung_ergebnis', 'bank_regeln', 'bank_import', 'mahnungen', 'erhoehungen', 'vertraege', 'kaution', 'mv_einheiten', 'darlehen_raten' ];
foreach ( $mietv_tabellen as $mietv_t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mv_' . $mietv_t );  // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Tabellenname aus fester Liste.
}
foreach ( [ 'mietv_db_version', 'mietv_einstellungen', 'mietv_hinweis_hash', 'mietv_hinweis_datum', 'mietv_anlagev_zeilen' ] as $mietv_o ) {
	delete_option( $mietv_o );
}
delete_metadata( 'user', 0, 'mietv_fokus', '', true );
remove_role( 'mietv_vermieter' );
$mietv_admin = get_role( 'administrator' );
if ( $mietv_admin ) {
	$mietv_admin->remove_cap( 'mietv_verwalten' );
}
wp_clear_scheduled_hook( 'mietv_taeglich' );
