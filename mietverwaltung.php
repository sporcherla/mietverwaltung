<?php
/**
 * Plugin Name: Mietverwaltung
 * Plugin URI: https://github.com/sporcherla/mietverwaltung
 * Description: Verwaltung von Mietobjekten, Mietverhältnissen, Zahlungen (Soll/Ist) und Mieterportal mit Schadensmeldung, Belegerfassung, AfA, Darlehen, Steuer-Auswertung (Anlage V), Betriebskostenabrechnung, Bankimport, Mahnwesen, Mieterhöhung, Mietvertrag/Übergabeprotokoll, Kautionskonto, PDF-Erzeugung, Datenschutz-Löschkonzept und Backup.
 * Version: 0.9.8
 * Author: Markus Hirn
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: mietverwaltung
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MV_VERSION', '0.9.8' );
define( 'MV_PATH', plugin_dir_path( __FILE__ ) );
define( 'MV_URL', plugin_dir_url( __FILE__ ) );
// Vorbereitung Mandantenfähigkeit: vorerst immer 1.
define( 'MV_OWNER_ID', 1 );

require_once MV_PATH . 'includes/helpers.php';
require_once MV_PATH . 'includes/class-mv-db.php';
require_once MV_PATH . 'includes/class-mv-soll.php';
require_once MV_PATH . 'includes/class-mv-admin.php';
require_once MV_PATH . 'includes/class-mv-afa.php';
require_once MV_PATH . 'includes/class-mv-steuer.php';
require_once MV_PATH . 'includes/class-mv-portal.php';
require_once MV_PATH . 'includes/class-mv-einstellungen.php';
require_once MV_PATH . 'includes/class-mv-pdf.php';
require_once MV_PATH . 'includes/class-mv-brief.php';
require_once MV_PATH . 'includes/class-mv-kaution.php';
require_once MV_PATH . 'includes/class-mv-darlehen.php';
require_once MV_PATH . 'includes/class-mv-abrechnung.php';
require_once MV_PATH . 'includes/class-mv-bank.php';
require_once MV_PATH . 'includes/class-mv-mahnung.php';
require_once MV_PATH . 'includes/class-mv-erhoehung.php';
require_once MV_PATH . 'includes/class-mv-hinweise.php';
require_once MV_PATH . 'includes/class-mv-datenschutz.php';
require_once MV_PATH . 'includes/class-mv-mietvertrag.php';
require_once MV_PATH . 'includes/class-mv-hilfe.php';

register_activation_hook( __FILE__, [ 'MV_DB', 'install' ] );
register_deactivation_hook( __FILE__, [ 'MV_Hinweise', 'deaktivieren' ] );

add_action( 'init', function () {
	load_plugin_textdomain( 'mietverwaltung', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 5 );

add_action( 'plugins_loaded', function () {
	MV_DB::maybe_upgrade();
	MV_Admin::init();
	MV_Steuer::init();
	MV_Portal::init();
	MV_Einstellungen::init();
	MV_Abrechnung::init();
	MV_Bank::init();
	MV_Mahnung::init();
	MV_Erhoehung::init();
	MV_Hinweise::init();
	MV_Datenschutz::init();
	MV_Mietvertrag::init();
	MV_Kaution::init();
	MV_Darlehen::init();
	MV_Hilfe::init();
} );
