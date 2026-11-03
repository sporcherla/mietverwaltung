<?php
// Zwei-Faktor: TOTP nach RFC 6238 (Testvektoren), Base32, Toleranzfenster, Verschlüsselung.
require __DIR__ . '/stub.php';
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = '' ) { return 'test-salt-' . $s; } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $l = 12 ) { return substr( bin2hex( random_bytes( $l ) ), 0, $l ); } }
if ( ! function_exists( 'wp_hash_password' ) ) { function wp_hash_password( $p ) { return password_hash( $p, PASSWORD_DEFAULT ); } function wp_check_password( $p, $h ) { return password_verify( $p, $h ); } }
$GLOBALS['um'] = [];
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['um'][ "$u|$k" ] ?? ''; }
	function update_user_meta( $u, $k, $v ) { $GLOBALS['um'][ "$u|$k" ] = $v; return true; }
	function delete_user_meta( $u, $k ) { unset( $GLOBALS['um'][ "$u|$k" ] ); return true; }
}
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url() { return 'https://example.org'; } }
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $id ) { return (object) [ 'ID' => $id, 'user_login' => 'markus', 'roles' => [ 'mietv_vermieter' ] ]; } }
require_once dirname( __DIR__ ) . '/includes/class-mv-zfa.php';

// RFC 6238, Anhang B: Secret "12345678901234567890", SHA-1, 8 Stellen → wir nutzen die letzten 6.
$secret = Mietv_Zfa::base32_encode( '12345678901234567890' );
assert( $secret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'Base32' );
assert( Mietv_Zfa::base32_decode( $secret ) === '12345678901234567890', 'Base32 zurück' );
$vektoren = [ 59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037' ];
foreach ( $vektoren as $t => $erw ) {
	assert( Mietv_Zfa::code( $secret, $t ) === $erw, "TOTP $t" );
}
assert( Mietv_Zfa::code_ok( $secret, '287 082', 59 ), 'Eingabe mit Leerzeichen' );
assert( Mietv_Zfa::code_ok( $secret, Mietv_Zfa::code( $secret, 59 - 30 ), 59 ), 'Vorheriger Zeitschritt' );
assert( ! Mietv_Zfa::code_ok( $secret, Mietv_Zfa::code( $secret, 59 - 90 ), 59 ), 'Zu alt' );
assert( ! Mietv_Zfa::code_ok( $secret, '000000', 59 ), 'Falscher Code' );
$c = Mietv_Zfa::verschluesseln( $secret );
assert( $c !== $secret && Mietv_Zfa::entschluesseln( $c ) === $secret, 'Verschlüsselung' );
assert( Mietv_Zfa::entschluesseln( 'x1:abc' ) === '', 'Unbekanntes Format' );
$codes = Mietv_Zfa::backup_codes_erzeugen( 7 );
assert( count( $codes ) === 8 && Mietv_Zfa::backup_codes_rest( 7 ) === 8, 'Backup-Codes' );
assert( Mietv_Zfa::backup_code_ok( 7, strtoupper( $codes[2] ) ), 'Backup-Code gültig' );
assert( ! Mietv_Zfa::backup_code_ok( 7, $codes[2] ), 'Backup-Code nur einmal' );
assert( Mietv_Zfa::backup_codes_rest( 7 ) === 7, 'Rest 7' );
assert( strpos( Mietv_Zfa::otpauth( 7, $secret ), 'otpauth://totp/Mietfux%20example.org%3Amarkus?secret=' . $secret ) === 0, 'otpauth' );
echo "ZFA OK\n";
