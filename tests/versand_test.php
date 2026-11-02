<?php
// Versand: Empfängerermittlung, senden() mit gestubbtem wp_mail, Protokoll, Fehlerfall ohne Adresse.
require __DIR__ . '/stub.php';

// WordPress-Funktionen, die der Stub nicht kennt
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( ...$args ) {
		$GLOBALS['mails'][] = $args;
		return $GLOBALS['mail_ok'] ?? true;
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $e ) {
		return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $n = '' ) {
		return tempnam( sys_get_temp_dir(), 'mv' );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $f ) {
		if ( file_exists( $f ) ) {
			unlink( $f );
		}
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$a ) {
		$GLOBALS['actions'][] = $a;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		return $v;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action() {}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $s ) {
		return $s;
	}
}
if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem() {
		$GLOBALS['wp_filesystem'] = new class() {
			public function put_contents( $p, $c, $m = null ) {
				$GLOBALS['tmp_files'][] = $p;
				return file_put_contents( $p, $c ) !== false;
			}
		};
		return true;
	}
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $msg;
		public function __construct( $c = '', $m = '' ) {
			$this->code = $c;
			$this->msg  = $m;
		}
		public function get_error_message() {
			return $this->msg;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
	function is_wp_error( $x ) {
		return $x instanceof WP_Error;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mv-pdf.php';
require_once dirname( __DIR__ ) . '/includes/class-mv-versand.php';

function check( $cond, string $msg ): void {
	if ( ! $cond ) {
		fwrite( STDERR, "FEHLER: $msg\n" );
		exit( 1 );
	}
}

global $wpdb;
foreach ( Mietv_Versand::tabellen( '' ) as $sql ) {
	$sql = preg_replace( '/bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql );
	$sql = preg_replace( '/,\s*PRIMARY KEY\s+\(id\)/', '', $sql );
	$sql = preg_replace( '/,\s*(UNIQUE )?KEY \w+ \([\w,]+\)/', '', $sql );
	$sql = preg_replace( '/\b(bigint|smallint|tinyint|int)\(\d+\)( unsigned)?/', 'INTEGER', $sql );
	$sql = preg_replace( '/varchar\(\d+\)/', 'TEXT', $sql );
	$sql = str_replace( [ 'datetime' ], [ 'TEXT' ], $sql );
	$wpdb->query( $sql );
}
check( $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type='table' AND name='wp_mv_versand'" ) === 'wp_mv_versand', 'Tabelle mv_versand angelegt' );

update_option(
	'mietv_einstellungen',
	[
		'vermieter_name'  => 'Max Vermieter',
		'vermieter_email' => 'max@example.org',
		'vermieter_adresse' => "Hauptstr. 1\n90513 Zirndorf",
		'versand_kopie'   => '1',
		'versand_signatur' => "Max Vermieter\nTel. 0911 12345",
	]
);

// Stammdaten
$wpdb->insert( mietv_table( 'objekte' ), [ 'bezeichnung' => 'Haus', 'strasse' => 'Weg 1', 'plz' => '90513', 'ort' => 'Zirndorf' ] );
$oid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'einheiten' ), [ 'objekt_id' => $oid, 'bezeichnung' => 'EG' ] );
$eid = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mieter' ), [ 'anrede' => 'Frau', 'vorname' => 'Anna', 'nachname' => 'Muster', 'email' => 'anna@example.org', 'p_anrede' => 'Herr', 'p_vorname' => 'Bernd', 'p_nachname' => 'Muster', 'p_email' => 'bernd@example.org' ] );
$m1 = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mieter' ), [ 'anrede' => 'Herr', 'vorname' => 'Carl', 'nachname' => 'Ohne', 'email' => 'kein-mail', 'p_email' => '' ] );
$m2 = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mieter' ), [ 'vorname' => 'Dora', 'nachname' => 'Doppelt', 'email' => 'Dora@example.org', 'p_email' => 'dora@example.org' ] );
$m3 = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mietverhaeltnisse' ), [ 'einheit_id' => $eid, 'mieter_id' => $m1, 'beginn' => '2024-01-01' ] );
$mv1 = (int) $wpdb->insert_id;
$wpdb->insert( mietv_table( 'mietverhaeltnisse' ), [ 'einheit_id' => $eid, 'mieter_id' => $m2, 'beginn' => '2024-01-01' ] );
$mv2 = (int) $wpdb->insert_id;

// Empfängerermittlung
check( Mietv_Versand::empfaenger( $m1 ) === [ 'anna@example.org', 'bernd@example.org' ], 'beide Adressen' );
check( Mietv_Versand::empfaenger( $m2 ) === [], 'ungültige Adresse verworfen' );
check( Mietv_Versand::empfaenger( $m3 ) === [ 'Dora@example.org' ], 'Dublette (Groß/Klein) entfernt' );
check( Mietv_Versand::empfaenger( 9999 ) === [], 'unbekannter Mieter' );

// Anrede
$mrow = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mietv_table( 'mieter' ) . ' WHERE id = %d', $m1 ) );
check( Mietv_Versand::anrede( $mrow ) === 'Sehr geehrte Frau Muster, sehr geehrter Herr Muster,', 'Anrede beide Parteien: ' . Mietv_Versand::anrede( $mrow ) );
check( Mietv_Versand::anrede( null ) === 'Sehr geehrte Damen und Herren,', 'Anrede ohne Mieter' );

// Klartext aus Brief-HTML
$kt = Mietv_Versand::klartext( '<p>Zeile <strong>eins</strong></p><table><tr><td>a</td><td>b</td></tr></table>' );
check( $kt === "Zeile eins\n\na | b", 'Klartext: ' . json_encode( $kt ) );

// senden(): Erfolg
$GLOBALS['mails']   = [];
$GLOBALS['actions'] = [];
$pdf                = Mietv_Brief_Test_PDF();
$erg                = Mietv_Versand::senden( $mv1, 'abrechnung', 42, 'Betriebskostenabrechnung 2025', 'anbei die Abrechnung.', $pdf, 'Abrechnung.pdf', 7 );
check( $erg === true, 'senden() liefert true' );
check( count( $GLOBALS['mails'] ) === 1, 'genau eine Mail' );
[ $an, $betreff, $body, $headers, $anhaenge ] = $GLOBALS['mails'][0];
check( $an === [ 'anna@example.org', 'bernd@example.org' ], 'Mail an beide Adressen' );
check( $betreff === 'Betriebskostenabrechnung 2025', 'Betreff' );
check( strpos( $body, 'Sehr geehrte Frau Muster, sehr geehrter Herr Muster,' ) === 0, 'Anrede am Anfang' );
check( strpos( $body, 'anbei die Abrechnung.' ) !== false && strpos( $body, 'Mit freundlichen Grüßen' ) !== false && strpos( $body, 'Tel. 0911 12345' ) !== false, 'Text, Gruß, Signatur' );
check( in_array( 'From: "Max Vermieter" <max@example.org>', $headers, true ) && in_array( 'Reply-To: max@example.org', $headers, true ) && in_array( 'Cc: max@example.org', $headers, true ), 'From/Reply-To/Cc: ' . json_encode( $headers ) );
check( count( $anhaenge ) === 1 && ! file_exists( $anhaenge[0] ), 'Anhang übergeben und Temp-Datei danach gelöscht' );
$p = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'versand' ) . ' ORDER BY id DESC LIMIT 1' );
check( $p && $p->status === 'gesendet' && (int) $p->mv_id === $mv1 && (int) $p->mieter_id === $m1 && $p->empfaenger === 'anna@example.org, bernd@example.org' && $p->typ === 'abrechnung' && (int) $p->bezug_id === 42 && (int) $p->dokument_id === 7 && $p->fehler === '' && $p->zeit !== null, 'Protokollzeile gesendet' );
check( count( $GLOBALS['actions'] ) === 1 && $GLOBALS['actions'][0][0] === 'mietv_protokoll' && $GLOBALS['actions'][0][1] === 'export' && $GLOBALS['actions'][0][2] === 'versand' && (int) $GLOBALS['actions'][0][3] === (int) $p->id, 'Hook mietv_protokoll' );

// Fehlerfall ohne Adresse
$GLOBALS['mails'] = [];
$erg              = Mietv_Versand::senden( $mv2, 'mahnung', 1, 'Mahnung', 'Text', $pdf, 'Mahnung.pdf' );
check( is_wp_error( $erg ) && $erg->get_error_code() === 'mietv_versand_keine_adresse' && strpos( $erg->get_error_message(), 'Ohne' ) !== false, 'WP_Error ohne Adresse: ' . ( is_wp_error( $erg ) ? $erg->get_error_message() : 'kein Fehler' ) );
check( count( $GLOBALS['mails'] ) === 0, 'keine Mail ohne Adresse' );
$p = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'versand' ) . ' ORDER BY id DESC LIMIT 1' );
check( $p && $p->status === 'fehler' && $p->empfaenger === '' && $p->fehler !== '' && $p->dokument_id === null, 'Protokollzeile fehler' );

// Fehlerfall: Mailer lehnt ab
$GLOBALS['mail_ok'] = false;
$erg                = Mietv_Versand::senden( $mv1, 'sonstiges', 0, 'Test', 'Text', '', 'x.pdf' );
check( is_wp_error( $erg ) && $erg->get_error_code() === 'mietv_versand_mail', 'WP_Error bei wp_mail=false' );
$p = $wpdb->get_row( 'SELECT * FROM ' . mietv_table( 'versand' ) . ' ORDER BY id DESC LIMIT 1' );
check( $p->status === 'fehler' && $p->typ === 'sonstiges', 'Protokoll fehler bei Mailer' );
$GLOBALS['mail_ok'] = true;

// Unbekannter Typ wird auf „sonstiges“ gemappt
Mietv_Versand::senden( $mv1, 'unsinn', 0, 'Test', 'Text', '', 'x.pdf' );
check( $wpdb->get_var( 'SELECT typ FROM ' . mietv_table( 'versand' ) . ' ORDER BY id DESC LIMIT 1' ) === 'sonstiges', 'Typ-Fallback' );

// dokument_senden(): fehlende body()-Methode liefert verständlichen Fehler
$erg = Mietv_Versand::dokument_senden( 'erhoehung', 1, $mv1 );
check( is_wp_error( $erg ) && strpos( $erg->get_error_message(), 'Mietv_Erhoehung::body()' ) !== false, 'method_exists-Guard: ' . ( is_wp_error( $erg ) ? $erg->get_error_message() : '' ) );
$erg = Mietv_Versand::dokument_senden( 'xyz', 1, $mv1 );
check( is_wp_error( $erg ) && $erg->get_error_code() === 'mietv_versand_typ', 'unbekannter Typ' );

check( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mietv_table( 'versand' ) ) === 4, '4 Protokollzeilen' );
check( isset( Mietv_Versand::hilfe()['versand'][1] ) && count( Mietv_Versand::hilfe()['versand'][1] ) >= 2, 'Hilfe' );

echo "Versand OK\n";

/** Kleines, echtes PDF über den eigenen Writer. */
function Mietv_Brief_Test_PDF(): string {
	return Mietv_PDF::dokument( '<p>Test</p>' );
}
