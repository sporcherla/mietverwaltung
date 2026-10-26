<?php
/**
 * Plugin Name: Mietfux – Mietverwaltung für Vermieter
 * Plugin URI: https://github.com/sporcherla/mietverwaltung
 * Description: Verwaltung von Mietobjekten, Mietverhältnissen, Zahlungen (Soll/Ist) und Mieterportal mit Schadensmeldung, Belegerfassung, AfA, Darlehen, Steuer-Auswertung (Anlage V), Betriebskostenabrechnung, Bankimport, Mahnwesen, Mieterhöhung, Mietvertrag/Übergabeprotokoll, Kautionskonto, PDF-Erzeugung, Datenschutz-Löschkonzept und Backup.
 * Version: 1.0.1
 * Author: Markus Hirn
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: mietfux
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MIETV_VERSION', '1.0.1' );
define( 'MIETV_PATH', plugin_dir_path( __FILE__ ) );
define( 'MIETV_URL', plugin_dir_url( __FILE__ ) );
// Vorbereitung Mandantenfähigkeit: vorerst immer 1.
define( 'MIETV_OWNER_ID', 1 );

require_once MIETV_PATH . 'includes/helpers.php';
require_once MIETV_PATH . 'includes/class-mv-db.php';
require_once MIETV_PATH . 'includes/class-mv-soll.php';
require_once MIETV_PATH . 'includes/class-mv-admin.php';
require_once MIETV_PATH . 'includes/class-mv-afa.php';
require_once MIETV_PATH . 'includes/class-mv-steuer.php';
require_once MIETV_PATH . 'includes/class-mv-portal.php';
require_once MIETV_PATH . 'includes/class-mv-einstellungen.php';
require_once MIETV_PATH . 'includes/class-mv-pdf.php';
require_once MIETV_PATH . 'includes/class-mv-brief.php';
require_once MIETV_PATH . 'includes/class-mv-kaution.php';
require_once MIETV_PATH . 'includes/class-mv-darlehen.php';
require_once MIETV_PATH . 'includes/class-mv-abrechnung.php';
require_once MIETV_PATH . 'includes/class-mv-bank.php';
require_once MIETV_PATH . 'includes/class-mv-mahnung.php';
require_once MIETV_PATH . 'includes/class-mv-erhoehung.php';
require_once MIETV_PATH . 'includes/class-mv-hinweise.php';
require_once MIETV_PATH . 'includes/class-mv-datenschutz.php';
require_once MIETV_PATH . 'includes/class-mv-mietvertrag.php';
require_once MIETV_PATH . 'includes/class-mv-hilfe.php';
require_once MIETV_PATH . 'includes/class-mv-assistent.php';

register_activation_hook( __FILE__, [ 'Mietv_DB', 'install' ] );
register_deactivation_hook( __FILE__, [ 'Mietv_Hinweise', 'deaktivieren' ] );

// Datenbank-Upgrade erst nach dem Laden der Übersetzungen (Rollenname, Kostenarten-Katalog).
add_action( 'init', [ 'Mietv_DB', 'maybe_upgrade' ], 6 );

add_action(
	'plugins_loaded',
	function () {
		Mietv_Admin::init();
		Mietv_Steuer::init();
		Mietv_Portal::init();
		Mietv_Einstellungen::init();
		Mietv_Abrechnung::init();
		Mietv_Bank::init();
		Mietv_Mahnung::init();
		Mietv_Erhoehung::init();
		Mietv_Hinweise::init();
		Mietv_Datenschutz::init();
		Mietv_Mietvertrag::init();
		Mietv_Kaution::init();
		Mietv_Darlehen::init();
		Mietv_Hilfe::init();
		Mietv_Assistent::init();
	}
);
