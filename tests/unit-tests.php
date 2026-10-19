<?php
/**
 * Unit tests for the parts of the plugin that do not need a database:
 * field rules, ADIF reading/writing and QRZ.com response parsing.
 *
 * Run from the plugin folder:  php tests/unit-tests.php
 *
 * phpcs:disable -- test script, not plugin code.
 *
 * @package WP_Logbook
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPLB_VERSION', '1.0.0' );
define( 'DAY_IN_SECONDS', 86400 );

// Minimal stand-ins for the WordPress functions these classes use.
function __( $text ) {
	return $text;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function remove_accents( $text ) {
	return strtr( $text, array( 'á' => 'a', 'ã' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'Ü' => 'U', 'ñ' => 'n', 'ö' => 'o', 'ä' => 'a', 'ß' => 'ss', 'É' => 'E' ) );
}
function wp_check_invalid_utf8( $text ) {
	return preg_match( '//u', $text ) ? $text : '';
}
function sanitize_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}

require dirname( __DIR__ ) . '/includes/class-wplb-fields.php';
require dirname( __DIR__ ) . '/includes/class-wplb-adif.php';
require dirname( __DIR__ ) . '/includes/class-wplb-qrz-client.php';

$GLOBALS['wplb_failures'] = 0;
$GLOBALS['wplb_tests']    = 0;

function check( $condition, $label ) {
	++$GLOBALS['wplb_tests'];
	if ( ! $condition ) {
		++$GLOBALS['wplb_failures'];
		echo "FAIL: {$label}\n";
	}
}

function same( $expected, $actual, $label ) {
	check( $expected === $actual, $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

/* Bands */
same( '20m', WPLB_Fields::band_for_freq( 14.074 ), 'FT8 frequency is 20m' );
same( '160m', WPLB_Fields::band_for_freq( '1.8' ), 'lower band edge is inside' );
same( '2m', WPLB_Fields::band_for_freq( 148 ), 'upper band edge is inside' );
same( '', WPLB_Fields::band_for_freq( 14.5 ), 'out-of-band frequency' );
same( '70cm', WPLB_Fields::band_for_freq( 432.1 ), 'UHF' );
same( '', WPLB_Fields::band_for_freq( 'abc' ), 'not a number' );

/* Modes */
foreach ( WPLB_Fields::common_modes() as $label => $pair ) {
	$modes = WPLB_Fields::adif_modes();
	check( isset( $modes[ $pair[0] ] ), "common mode {$label} uses ADIF mode {$pair[0]}" );
	check( '' === $pair[1] || in_array( $pair[1], $modes[ $pair[0] ], true ), "common mode {$label} uses a submode of {$pair[0]}" );
}
same( array( 'MFSK', 'FT4' ), WPLB_Fields::parse_mode_value( 'mfsk|ft4' ), 'parse mode value' );
same( array( 'CW', '' ), WPLB_Fields::parse_mode_value( 'CW' ), 'parse mode value without submode' );
same( 'SSB|USB', WPLB_Fields::mode_value( 'SSB', 'USB' ), 'mode value' );
same( 'FT4', WPLB_Fields::display_mode( 'MFSK', 'FT4' ), 'display submode' );
same( 'MFSK', WPLB_Fields::submode_parent( 'js8' ), 'submode parent' );
same( '', WPLB_Fields::submode_parent( 'CW' ), 'mode is not a submode' );

/* Normalisation */
same( 'FN31pr', WPLB_Fields::normalize_grid( ' fn31PR ' ), 'grid case' );
same( 'JO62', WPLB_Fields::normalize_grid( 'jo62' ), '4-character grid' );
same( 'FN31pr12', WPLB_Fields::normalize_grid( 'FN31PR12' ), '8-character grid' );
same( false, WPLB_Fields::normalize_grid( 'ZZ99' ), 'grid field letters stop at R' );
same( false, WPLB_Fields::normalize_grid( 'FN3' ), 'odd grid length' );
same( 'K1ABC/P', WPLB_Fields::normalize_callsign( ' k1abc/p ' ), 'callsign' );
same( false, WPLB_Fields::normalize_callsign( 'K1 ABC!' ), 'callsign with punctuation' );
same( false, WPLB_Fields::normalize_callsign( 'K1' ), 'too short' );
same( '2026-10-19', WPLB_Fields::normalize_date( '20261019' ), 'ADIF date' );
same( false, WPLB_Fields::normalize_date( '2026-02-30' ), 'impossible date' );
same( '2026-10-19 07:05:00', WPLB_Fields::normalize_datetime( '2026-10-19 7:05' ), 'time without seconds' );
same( '2026-10-19 23:59:59', WPLB_Fields::normalize_datetime( '2026-10-19T23:59:59' ), 'ISO separator' );
same( false, WPLB_Fields::normalize_datetime( '2026-10-19 24:00' ), 'hour 24' );
same( '14.074', WPLB_Fields::format_number( '14.074000' ), 'trailing zeros' );
same( '100', WPLB_Fields::format_number( '100.000' ), 'whole number' );
same( '0', WPLB_Fields::format_number( '0.000' ), 'zero' );

/* Sanitising */
list( $data, $errors ) = WPLB_Fields::sanitize(
	array(
		'callsign'   => 'dl1abc',
		'time_on'    => '2026-10-19 12:00',
		'freq'       => '7,074',
		'mode'       => 'ft8',
		'cqz'        => '14',
		'ituz'       => '91',
		'cont'       => 'eu',
		'prop_mode'  => 'nonsense',
		'comment'    => "Tnx <3 & 100% copy\t73",
		'notes'      => "line 1\r\nline 2",
		'email'      => 'not an email',
		'state'      => 'nrw',
		'qsl_sent'   => 'q',
		'unknown_xx' => 'ignored',
	)
);
same( 'DL1ABC', $data['callsign'], 'callsign upper-cased' );
same( '7.074', $data['freq'], 'decimal comma accepted' );
same( 'FT8', $data['mode'], 'mode upper-cased' );
same( 14, $data['cqz'], 'integer' );
check( isset( $errors['ituz'] ), 'ITU zone out of range rejected' );
same( 'EU', $data['cont'], 'enum value' );
same( '', $data['prop_mode'], 'unknown enum value dropped' );
same( 'Tnx <3 & 100% copy 73', $data['comment'], 'free text keeps <, & and %' );
same( "line 1\nline 2", $data['notes'], 'notes keep line breaks' );
same( '', $data['email'], 'invalid email dropped' );
same( 'NRW', $data['state'], 'upper field' );
same( 'Q', $data['qsl_sent'], 'QSL queued' );
check( ! isset( $data['unknown_xx'] ), 'unknown keys ignored' );

$errors = WPLB_Fields::validate( $data );
same( '40m', $data['band'], 'band derived from frequency' );
same( array(), $errors, 'complete QSO validates' );

$qso    = array( 'callsign' => 'W1AW', 'time_on' => '2026-10-19 12:00:00', 'mode' => 'CW', 'band' => '20m', 'freq' => '7.030' );
$errors = WPLB_Fields::validate( $qso );
check( isset( $errors['freq'] ), 'frequency outside the chosen band' );
$qso    = array( 'callsign' => '', 'time_on' => null, 'mode' => '' );
$errors = WPLB_Fields::validate( $qso );
check( isset( $errors['callsign'], $errors['time_on'], $errors['mode'], $errors['band'] ), 'required fields' );
$qso    = array( 'callsign' => 'W1AW', 'time_on' => '2026-10-19 12:00:00', 'time_off' => '2026-10-19 11:00:00', 'mode' => 'CW', 'band' => '20m' );
check( isset( WPLB_Fields::validate( $qso )['time_off'] ), 'end before start' );

$public = array_keys( WPLB_Fields::public_columns() );
same( 'time_on', $public[0], 'date is the first public column' );
check( ! in_array( 'notes', $public, true ) && ! in_array( 'email', $public, true ), 'private fields are never public' );

/* ADIF writing */
same( '<CALL:4>W1AW', WPLB_ADIF::field( 'CALL', 'W1AW' ), 'data specifier' );
$row    = array(
	'callsign'      => 'EA1ABC',
	'time_on'       => '2026-10-19 23:58:30',
	'time_off'      => '2026-10-20 00:02:00',
	'band'          => '20m',
	'freq'          => '14.074000',
	'mode'          => 'MFSK',
	'submode'       => 'FT4',
	'name'          => 'José',
	'notes'         => "first\nsecond",
	'email'         => 'ea1abc@example.com',
	'qslsdate'      => '2026-10-21',
	'dxcc'          => '281',
	'qrz_status'    => 'uploaded',
	'qrz_upload_date' => '2026-10-21 10:00:00',
);
$record = WPLB_ADIF::record( $row );
foreach ( array( '<CALL:6>EA1ABC', '<QSO_DATE:8>20261019', '<TIME_ON:6>235830', '<QSO_DATE_OFF:8>20261020', '<TIME_OFF:6>000200', '<FREQ:6>14.074', '<MODE:4>MFSK', '<SUBMODE:3>FT4', '<NAME:4>Jose', "<NOTES:13>first\r\nsecond", '<QSLSDATE:8>20261021', '<DXCC:3>281', '<QRZCOM_QSO_UPLOAD_STATUS:1>Y', '<QRZCOM_QSO_UPLOAD_DATE:8>20261021' ) as $needle ) {
	check( false !== strpos( $record, $needle ), "record contains {$needle}" );
}
same( ' <EOR>', substr( $record, -6 ), 'record ends with EOR' );
$qrz = WPLB_ADIF::record( $row, 'qrz' );
check( false === strpos( $qrz, 'NOTES' ) && false === strpos( $qrz, 'EMAIL' ) && false === strpos( $qrz, 'QRZCOM_' ), 'private and bookkeeping fields stay out of QRZ.com uploads' );
same( 'Sao Paulo', WPLB_ADIF::ascii( "São\tPaulo" ), 'accents and tabs folded to ASCII' );
same( 'Tokyo', WPLB_ADIF::ascii( 'Tokyo 東京' ), 'characters without an ASCII form are dropped' );

/* ADIF reading */
$text = "Exported by <SomeLogger>\n<adif_ver:5>3.1.4 <eoh>\n"
	. "<call:4>W1AW <qso_date:8:d>20260101 <time_on:4>2359 <time_off:4>0005 <band:3>40M <mode:3>USB <freq:4>7200 <eor>\n"
	. "<CALL:6>DL1ABC<QSO_DATE:8>20260102<TIME_ON:6>120000<BAND:3>20m<MODE:3>FT4<APP_QRZLOG_LOGID:9>123456789<COMMENT:7>a<b&c>d<EOR>\n"
	. '<CALL:5>JA1_X <QSO_DATE:8>20260103 <TIME_ON:4>0800 <BAND:3>15m <MODE:2>CW <VE_PROV:2>ON <QSL_RCVD:1>V <QRZCOM_QSO_UPLOAD_STATUS:1>N';
$parsed = WPLB_ADIF::parse( $text );
same( '3.1.4', $parsed['header']['ADIF_VER'], 'header field' );
same( 3, count( $parsed['records'] ), 'records, including a final one without EOR' );
same( 'a<b&c>d', $parsed['records'][1]['COMMENT'], 'value containing < and >' );

$q = WPLB_ADIF::record_to_qso( $parsed['records'][0] );
same( array( 'SSB', 'USB' ), array( $q['mode'], $q['submode'] ), 'MODE=USB becomes SSB/USB' );
same( '7.2', $q['freq'], 'frequency in kHz repaired' );
same( '2026-01-01 23:59:00', $q['time_on'], 'start' );
same( '2026-01-02 00:05:00', $q['time_off'], 'end after midnight without QSO_DATE_OFF' );
$q = WPLB_ADIF::record_to_qso( $parsed['records'][1] );
same( array( 'MFSK', 'FT4' ), array( $q['mode'], $q['submode'] ), 'MODE=FT4 becomes MFSK/FT4' );
same( array( 123456789, 'uploaded' ), array( $q['qrz_logid'], $q['qrz_status'] ), 'QRZ.com log ID' );
$q = WPLB_ADIF::record_to_qso( $parsed['records'][2] );
same( 'JA1/X', $q['callsign'], 'underscore read as slash' );
same( 'ON', $q['state'], 'VE_PROV fallback' );
same( 'Y', $q['qsl_rcvd'], 'import-only V becomes Y' );
same( 'skip', $q['qrz_status'], 'QRZCOM_QSO_UPLOAD_STATUS N' );

$chars = WPLB_ADIF::parse( '<CALL:4>EA1X <NAME:4>José <QTH:6>Málaga <EOR>' );
same( array( 'José', 'Málaga' ), array( $chars['records'][0]['NAME'], $chars['records'][0]['QTH'] ), 'lengths counted in characters' );
$bytes = WPLB_ADIF::parse( "<CALL:4>EA1X <NAME:5>Jos\xC3\xA9 <QTH:7>M\xC3\xA1laga <EOR>" );
same( array( 'José', 'Málaga' ), array( $bytes['records'][0]['NAME'], $bytes['records'][0]['QTH'] ), 'lengths counted in bytes' );
$latin = WPLB_ADIF::parse( "<CALL:4>EA1X <NAME:4>Jos\xE9 <EOR>" );
same( 'José', $latin['records'][0]['NAME'], 'Latin-1 file converted to UTF-8' );
$bom = WPLB_ADIF::parse( "\xEF\xBB\xBF<CALL:4>EA1X <EOR>" );
same( 'EA1X', $bom['records'][0]['CALL'], 'byte order mark' );

$round = WPLB_ADIF::record_to_qso( WPLB_ADIF::parse( WPLB_ADIF::record( $row ) )['records'][0] );
same( array( 'EA1ABC', '2026-10-19 23:58:30', '2026-10-20 00:02:00', 'MFSK', 'FT4', '14.074', 'Jose' ), array( $round['callsign'], $round['time_on'], $round['time_off'], $round['mode'], $round['submode'], $round['freq'], $round['name'] ), 'round trip' );

/* QRZ.com responses */
$r = WPLB_QRZ_Client::parse_response( 'RESULT=OK&LOGID=130877825&COUNT=1' );
same( array( 'OK', '130877825', '1' ), array( $r['RESULT'], $r['LOGID'], $r['COUNT'] ), 'insert response' );
$r = WPLB_QRZ_Client::parse_response( 'STATUS=FAIL&RESULT=FAIL&REASON=Unable to add QSO to database: duplicate&EXTENDED=' );
same( 'Unable to add QSO to database: duplicate', $r['REASON'], 'failure reason with spaces and colon' );
$r = WPLB_QRZ_Client::parse_response( 'RESULT=AUTH' );
same( 'AUTH', $r['RESULT'], 'auth response' );
$r = WPLB_QRZ_Client::parse_response( "RESULT=OK&COUNT=12&DATA=CALLSIGN%3DN0CALL%26DXCC_COUNT%3D7%26BOOK_NAME%3DMy%20log\n" );
same( array( 'N0CALL', '7', 'My log', '12' ), array( $r['CALLSIGN'], $r['DXCC_COUNT'], $r['BOOK_NAME'], $r['COUNT'] ), 'status details nested in DATA' );
$r = WPLB_QRZ_Client::parse_response( 'RESULT=OK&COUNT=1&ADIF=&lt;call:4&gt;W1AW &lt;comment:3&gt;a&amp;b &lt;eor&gt;' );
same( '<call:4>W1AW <comment:3>a&b <eor>', $r['ADIF'], 'fetched ADIF is decoded and kept whole' );
same( array(), WPLB_QRZ_Client::parse_response( '<html>Bad gateway</html>' ), 'not a Logbook API response' );

printf( "\n%d checks, %d failed\n", $GLOBALS['wplb_tests'], $GLOBALS['wplb_failures'] );
exit( $GLOBALS['wplb_failures'] ? 1 : 0 );
