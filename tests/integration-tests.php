<?php
/**
 * Integration tests against a real WordPress database, with the QRZ.com
 * Logbook API mocked through the pre_http_request filter.
 *
 * Run on a disposable test site with the plugin active and an EMPTY logbook:
 *
 *     WPLB_INTEGRATION=1 wp eval-file tests/integration-tests.php
 *
 * The script refuses to run when the logbook already holds QSOs, deletes
 * everything it adds and restores the plugin settings when it finishes.
 *
 * phpcs:disable -- test script, not plugin code.
 *
 * @package WP_Logbook
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WPLB_QSOs' ) ) {
	fwrite( STDERR, "Run with: WPLB_INTEGRATION=1 wp eval-file tests/integration-tests.php\n" );
	return;
}
if ( '1' !== getenv( 'WPLB_INTEGRATION' ) ) {
	fwrite( STDERR, "Set WPLB_INTEGRATION=1 to confirm this is a disposable test site.\n" );
	return;
}

global $wpdb;
$wplb_table = WPLB_QSOs::table();
if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wplb_table}" ) > 0 ) {
	fwrite( STDERR, "The logbook is not empty; refusing to run.\n" );
	return;
}

$GLOBALS['wplb_failures'] = 0;
$GLOBALS['wplb_tests']    = 0;
$GLOBALS['wplb_saved']    = array(
	'settings' => get_option( WPLB_Settings::OPTION ),
	'key'      => get_option( WPLB_Settings::API_KEY_OPTION ),
);

function wplb_check( $condition, $label ) {
	++$GLOBALS['wplb_tests'];
	if ( ! $condition ) {
		++$GLOBALS['wplb_failures'];
		echo "FAIL: {$label}\n";
	}
}

function wplb_reset() {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . WPLB_QSOs::table() );
}

function wplb_settings( array $changes ) {
	update_option( WPLB_Settings::OPTION, array_merge( WPLB_Settings::all(), $changes ) );
}

// Mock QRZ.com: queued responses are returned in order and requests recorded.
$GLOBALS['wplb_mock'] = array(
	'responses' => array(),
	'requests'  => array(),
);
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, WPLB_QRZ_Client::ENDPOINT ) ) {
			return $pre;
		}
		$GLOBALS['wplb_mock']['requests'][] = $args;
		$next                               = array_shift( $GLOBALS['wplb_mock']['responses'] );
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		$code = is_array( $next ) ? $next[0] : 200;
		$body = is_array( $next ) ? $next[1] : (string) $next;
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	},
	PHP_INT_MAX,
	3
);
function wplb_respond( ...$responses ) {
	$GLOBALS['wplb_mock']['responses'] = $responses;
	$GLOBALS['wplb_mock']['requests']  = array();
}
function wplb_request( $index = 0 ) {
	return isset( $GLOBALS['wplb_mock']['requests'][ $index ] ) ? $GLOBALS['wplb_mock']['requests'][ $index ]['body'] : null;
}
function wplb_request_count() {
	return count( $GLOBALS['wplb_mock']['requests'] );
}

try {
	/* Schema */
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wplb_table}" );
	wplb_check( ! array_diff( WPLB_QSOs::columns(), $columns ), 'every field has a column' );

	/* Storage and queries */
	list( $data, $errors ) = WPLB_Fields::sanitize( array( 'callsign' => 'k1abc/p', 'time_on' => '2026-10-19 14:05', 'freq' => '14.074', 'mode' => 'FT8', 'gridsquare' => 'fn31pr', 'name' => 'José', 'dxcc' => '291', 'notes' => "a\nb" ) );
	$errors               += WPLB_Fields::validate( $data );
	wplb_check( ! $errors, 'valid QSO' );
	$id  = WPLB_QSOs::insert( $data );
	$row = WPLB_QSOs::get( $id );
	wplb_check( '20m' === $row['band'] && '2026-10-19 14:05:00' === $row['time_on'] && 'pending' === $row['qrz_status'] && null === $row['time_off'], 'stored row' );
	wplb_check( '14.074' === WPLB_Fields::format_number( $row['freq'] ), 'decimal round trip' );

	WPLB_QSOs::insert( array( 'callsign' => 'W1AW', 'time_on' => '2026-10-18 01:00:00', 'band' => '40m', 'mode' => 'CW', 'country' => 'United States', 'dxcc' => 291, 'gridsquare' => 'FN31' ) );
	WPLB_QSOs::insert( array( 'callsign' => 'DL1ABC', 'time_on' => '2026-10-17 12:00:00', 'band' => '20m', 'freq' => '14.25', 'mode' => 'SSB', 'submode' => 'USB', 'country' => 'Germany', 'dxcc' => 230, 'gridsquare' => 'JO62' ) );
	WPLB_QSOs::insert( array( 'callsign' => 'K1ABC', 'time_on' => '2026-10-16 12:00:00', 'band' => '2m', 'mode' => 'FM', 'qth' => 'Boston' ) );

	$q = WPLB_QSOs::query( array( 'per_page' => 2 ) );
	wplb_check( 4 === $q['total'] && 2 === count( $q['items'] ) && 'K1ABC/P' === $q['items'][0]['callsign'], 'paging, newest first' );
	wplb_check( 2 === WPLB_QSOs::query( array( 'callsign' => 'K1ABC' ) )['total'], 'callsign lookup includes portable calls' );
	wplb_check( 1 === WPLB_QSOs::query( array( 'mode' => 'SSB' ) )['total'], 'mode filter includes submodes' );
	wplb_check( 1 === WPLB_QSOs::query( array( 'mode' => 'SSB|USB' ) )['total'], 'exact mode pair filter' );
	wplb_check( 1 === WPLB_QSOs::query( array( 'search' => 'bost' ) )['total'], 'search' );
	wplb_check( 1 === WPLB_QSOs::query( array( 'band' => '20m', 'date_from' => '2026-10-18', 'date_to' => '2026-10-19' ) )['total'], 'band and date filters' );
	wplb_check( 4 === WPLB_QSOs::query( array( 'orderby' => 'bogus; DROP TABLE x', 'order' => 'sideways' ) )['total'], 'unknown sort column ignored' );
	wplb_check( 1 === (int) WPLB_QSOs::previous_contacts( 'K1ABC', $id )['total'], 'previous contacts exclude the QSO being edited' );
	$stats = WPLB_QSOs::stats();
	wplb_check( 4 === $stats['qsos'] && 2 === $stats['dxcc'] && 2 === $stats['grids'] && array( '40m', '20m', '2m' ) === array_keys( $stats['bands'] ), 'statistics' );
	wplb_check( WPLB_QSOs::find_duplicate( 'W1AW', '2026-10-18 01:00:45', '40m', 'CW' ) > 0, 'duplicate within a minute' );
	wplb_check( 0 === WPLB_QSOs::find_duplicate( 'W1AW', '2026-10-18 01:02:00', '40m', 'CW' ), 'not a duplicate two minutes later' );

	/* Import and export */
	wplb_reset();
	$adif    = "<ADIF_VER:5>3.1.4 <EOH>\n<CALL:4>W1AW <QSO_DATE:8>20260101 <TIME_ON:4>2359 <BAND:3>40m <MODE:3>USB <EOR>\n"
		. "<CALL:6>DL1ABC <QSO_DATE:8>20260102 <TIME_ON:4>1200 <BAND:3>20m <MODE:3>FT4 <APP_QRZLOG_LOGID:3>123 <COMMENT:7>a<b&c>d <EOR>\n"
		. "<CALL:3>BAD <QSO_DATE:8>20261399 <TIME_ON:4>1000 <BAND:3>20m <MODE:2>CW <EOR>\n";
	$summary = WPLB_Import::adif( $adif, array( 'station_callsign' => 'N0CALL' ) );
	wplb_check( 3 === $summary['records'] && 2 === $summary['imported'] && 1 === $summary['failed'], 'import summary' );
	wplb_check( 2 === WPLB_Import::adif( $adif )['duplicates'], 'second import skips duplicates' );
	$dl = WPLB_QSOs::query( array( 'callsign' => 'DL1ABC' ) )['items'][0];
	wplb_check( 'MFSK' === $dl['mode'] && 'FT4' === $dl['submode'] && 'a<b&c>d' === $dl['comment'] && 'uploaded' === $dl['qrz_status'] && 'N0CALL' === $dl['station_callsign'], 'imported values' );
	$export = WPLB_ADIF::header();
	foreach ( WPLB_QSOs::query( array( 'per_page' => 0 ) )['items'] as $item ) {
		$export .= WPLB_ADIF::record( $item ) . "\n";
	}
	wplb_reset();
	wplb_check( 2 === WPLB_Import::adif( $export )['imported'], 'export re-imports' );

	/* QRZ.com upload */
	wplb_reset();
	wplb_settings( array( 'station_callsign' => 'N0CALL', 'qrz_enabled' => 1, 'qrz_auto_upload' => 1, 'qrz_sync_edits' => 1, 'qrz_delete_remote' => 0, 'qrz_background_sync' => 0 ) );
	update_option( WPLB_Settings::API_KEY_OPTION, 'ABCD-1234-EF56-7890' );
	wplb_check( WPLB_QRZ_Sync::is_enabled(), 'upload enabled' );

	$id = WPLB_QSOs::insert( array( 'callsign' => 'W1AW', 'time_on' => '2026-10-19 10:00:00', 'band' => '20m', 'mode' => 'SSB', 'submode' => 'USB', 'notes' => 'private' ) );
	wplb_respond( 'RESULT=OK&LOGID=1001&COUNT=1' );
	$outcome = WPLB_QRZ_Sync::upload( $id );
	$sent    = wplb_request();
	wplb_check( 'uploaded' === $outcome['result'], 'insert accepted' );
	wplb_check( 'INSERT' === $sent['ACTION'] && 'ABCD-1234-EF56-7890' === $sent['KEY'] && ! isset( $sent['OPTION'] ), 'INSERT request' );
	wplb_check( false !== strpos( $sent['ADIF'], '<STATION_CALLSIGN:6>N0CALL' ) && false === strpos( $sent['ADIF'], 'NOTES' ), 'station callsign added, notes withheld' );
	$row = WPLB_QSOs::get( $id );
	wplb_check( 'uploaded' === $row['qrz_status'] && 1001 === (int) $row['qrz_logid'], 'log ID stored' );

	wplb_check( 'modified' === WPLB_QRZ_Sync::status_after_edit( $row, array_merge( $row, array( 'rst_sent' => '57' ) ) ), 'edit marks QSO modified' );
	wplb_check( 'uploaded' === WPLB_QRZ_Sync::status_after_edit( $row, array_merge( $row, array( 'notes' => 'x' ) ) ), 'editing private notes changes nothing on QRZ.com' );
	WPLB_QSOs::update( $id, array( 'rst_sent' => '57', 'qrz_status' => 'modified' ) );
	wplb_respond( 'RESULT=REPLACE&LOGID=1001&COUNT=1' );
	WPLB_QRZ_Sync::maybe_upload_after_save( $id );
	wplb_check( 'REPLACE' === wplb_request()['OPTION'] && 1 === wplb_request_count(), 'edited QSO sent with OPTION=REPLACE' );

	WPLB_QSOs::update( $id, array( 'callsign' => 'W1AX', 'qrz_status' => 'modified' ) );
	wplb_respond( 'RESULT=OK&LOGID=2002&COUNT=1', 'RESULT=OK&COUNT=1' );
	WPLB_QRZ_Sync::upload( $id );
	wplb_check( 'DELETE' === wplb_request( 1 )['ACTION'] && '1001' === wplb_request( 1 )['LOGIDS'], 'stale QRZ.com copy removed after a callsign change' );
	wplb_check( 2002 === (int) WPLB_QSOs::get( $id )['qrz_logid'], 'new log ID stored' );

	$dup = WPLB_QSOs::insert( array( 'callsign' => 'DL1ABC', 'time_on' => '2026-10-18 10:00:00', 'band' => '40m', 'mode' => 'CW' ) );
	wplb_respond( 'STATUS=FAIL&RESULT=FAIL&REASON=Unable to add QSO to database: duplicate&EXTENDED=' );
	wplb_check( 'uploaded' === WPLB_QRZ_Sync::upload( $dup )['result'] && 'uploaded' === WPLB_QSOs::get( $dup )['qrz_status'], 'duplicate counts as uploaded' );

	$bad = WPLB_QSOs::insert( array( 'callsign' => 'JA1XX', 'time_on' => '1990-01-01 10:00:00', 'band' => '15m', 'mode' => 'CW' ) );
	wplb_respond( 'RESULT=FAIL&REASON=add_qso: outside date range' );
	$outcome = WPLB_QRZ_Sync::upload( $bad );
	$row     = WPLB_QSOs::get( $bad );
	wplb_check( 'failed' === $outcome['result'] && ! $outcome['stop'] && 'failed' === $row['qrz_status'] && 'add_qso: outside date range' === $row['qrz_message'], 'rejection stored with reason' );

	$a = WPLB_QSOs::insert( array( 'callsign' => 'VK2X', 'time_on' => '2026-10-17 10:00:00', 'band' => '20m', 'mode' => 'FT8' ) );
	$b = WPLB_QSOs::insert( array( 'callsign' => 'ZL1X', 'time_on' => '2026-10-17 11:00:00', 'band' => '20m', 'mode' => 'FT8' ) );
	wplb_respond( 'RESULT=AUTH' );
	$summary = WPLB_QRZ_Sync::upload_many( array( $a, $b ) );
	wplb_check( $summary['stopped'] && 1 === wplb_request_count() && 'pending' === WPLB_QSOs::get( $a )['qrz_status'], 'AUTH stops the batch and leaves QSOs pending' );
	wplb_respond( new WP_Error( 'http_request_failed', 'timeout' ) );
	wplb_check( WPLB_QRZ_Sync::upload( $a )['stop'], 'network error stops' );
	wplb_respond( array( 500, 'error' ) );
	wplb_check( WPLB_QRZ_Sync::upload( $a )['stop'], 'HTTP 500 stops' );

	wplb_respond( 'RESULT=OK&LOGID=3001&COUNT=1', 'RESULT=OK&LOGID=3002&COUNT=1' );
	wplb_check( 2 === WPLB_QRZ_Sync::upload_many( WPLB_QSOs::ids_with_qrz_status( array( 'pending', 'modified' ) ) )['uploaded'], 'pending QSOs uploaded' );

	WPLB_QSOs::set_qrz_status( array( $b ), 'skip' );
	wplb_respond();
	wplb_check( 'skipped' === WPLB_QRZ_Sync::upload( $b )['result'] && 0 === wplb_request_count(), 'skipped QSO not sent' );

	WPLB_QSOs::queue_for_upload( array( $bad, $a ) );
	wplb_check( 'pending' === WPLB_QSOs::get( $bad )['qrz_status'] && 'modified' === WPLB_QSOs::get( $a )['qrz_status'], 'queue for upload' );

	wplb_settings( array( 'qrz_delete_remote' => 1 ) );
	wplb_respond( 'RESULT=OK&COUNT=1' );
	WPLB_QSOs::delete( array( $a ) );
	wplb_check( 'DELETE' === wplb_request()['ACTION'] && '3001' === wplb_request()['LOGIDS'] && null === WPLB_QSOs::get( $a ), 'deleted here and on QRZ.com' );

	wplb_settings( array( 'qrz_enabled' => 0 ) );
	wplb_respond();
	WPLB_QSOs::delete( array( $b ) );
	wplb_check( null === WPLB_QRZ_Sync::maybe_upload_after_save( $bad ) && 0 === wplb_request_count(), 'nothing is sent when upload is off' );

	wplb_settings( array( 'qrz_enabled' => 1, 'qrz_background_sync' => 1 ) );
	wplb_check( (bool) wp_next_scheduled( WPLB_QRZ_Sync::CRON_HOOK ), 'background upload scheduled' );
	wplb_respond( 'RESULT=OK&LOGID=4001&COUNT=1' );
	do_action( WPLB_QRZ_Sync::CRON_HOOK );
	wplb_check( 'uploaded' === WPLB_QSOs::get( $bad )['qrz_status'], 'background upload' );
	wplb_settings( array( 'qrz_background_sync' => 0 ) );
	wplb_check( ! wp_next_scheduled( WPLB_QRZ_Sync::CRON_HOOK ), 'background upload unscheduled' );

	/* Public logbook shortcodes */
	wplb_reset();
	wplb_settings( array( 'public_per_page' => 25, 'public_search' => 1, 'public_columns' => array( 'time_on', 'callsign', 'band', 'mode', 'country', 'gridsquare' ) ) );
	$seed = array(
		array( 'callsign' => 'W1AW', 'time_on' => '2026-10-01 12:00:00', 'band' => '20m', 'mode' => 'SSB', 'submode' => 'USB', 'state' => 'CT', 'cnty' => 'CT,Hartford', 'gridsquare' => 'FN31pr', 'pota_ref' => 'US-1234', 'qsl_rcvd' => 'Y', 'station_callsign' => 'N0CALL' ),
		array( 'callsign' => 'K1ABC/P', 'time_on' => '2026-10-02 12:00:00', 'band' => '40m', 'mode' => 'CW', 'state' => 'MA', 'cnty' => 'MA,Middlesex', 'gridsquare' => 'FN42', 'sota_ref' => 'W1/HA-001', 'station_callsign' => 'N0CALL' ),
		array( 'callsign' => 'VE3XYZ', 'time_on' => '2026-10-04 12:00:00', 'band' => '20m', 'mode' => 'SSB', 'submode' => 'USB', 'state' => 'ON', 'gridsquare' => 'FN03', 'pota_ref' => 'CA-0001,CA-0002', 'station_callsign' => 'W0CLUB' ),
	);
	for ( $i = 1; $i <= 12; $i++ ) {
		$seed[] = array( 'callsign' => 'K0A' . chr( 64 + $i ), 'time_on' => sprintf( '2026-09-%02d 10:00:00', $i ), 'band' => '20m', 'mode' => 'FT8', 'gridsquare' => 'EM48', 'state' => 'MO', 'my_pota_ref' => $i <= 6 ? 'US-5678' : 'US-9999', 'station_callsign' => 'N0CALL' );
	}
	foreach ( $seed as $qso ) {
		WPLB_QSOs::insert( $qso );
	}
	$render = function ( $shortcode, array $get = array() ) {
		$_GET                   = $get;
		$_SERVER['REQUEST_URI'] = '/log/' . ( $get ? '?' . http_build_query( $get ) : '' );
		$html                   = do_shortcode( $shortcode );
		$_GET                   = array();
		return $html;
	};
	$rows = function ( $html ) {
		return preg_match( '/<tbody>(.*)<\/tbody>/s', $html, $m ) ? substr_count( $m[1], '<tr>' ) : substr_count( $html, '<li class="wplb-tile">' );
	};

	$html = $render( '[wp_logbook]' );
	foreach ( array( 'from', 'to', 'callsign', 'band', 'mode', 'grid', 'state', 'county', 'pota', 'sota' ) as $param ) {
		wplb_check( false !== strpos( $html, 'name="wplb_' . $param . '"' ), "default filter {$param}" );
	}
	wplb_check( 15 === $rows( $html ) && false === strpos( $html, 'name="wplb_my_pota"' ), 'default table' );
	wplb_check( false === strpos( $render( '[wp_logbook filters="none"]' ), 'wplb-filters' ), 'filters="none"' );
	$html = $render( '[wp_logbook filter_date="no" filter_my_pota="yes"]' );
	wplb_check( false === strpos( $html, 'wplb_from' ) && false !== strpos( $html, 'name="wplb_my_pota"' ), 'per-filter switches' );
	preg_match_all( '/name="(wplb_[a-z_]+)"/', $render( '[wp_logbook filters="state,gridsquare,bogus" filter_pota="yes"]' ), $names );
	wplb_check( array( 'wplb_state', 'wplb_grid', 'wplb_pota' ) === $names[1], 'filter list, alias and unknown names' );

	$cases = array(
		'state'     => array( array( 'wplb_state' => 'CT' ), 1 ),
		'county'    => array( array( 'wplb_county' => 'middlesex' ), 1 ),
		'grid'      => array( array( 'wplb_grid' => 'fn' ), 3 ),
		'pota'      => array( array( 'wplb_pota' => 'ca-0002' ), 1 ),
		'sota'      => array( array( 'wplb_sota' => 'W1/HA' ), 1 ),
		'callsign'  => array( array( 'wplb_callsign' => 'k0a' ), 12 ),
		'date'      => array( array( 'wplb_from' => '2026-10-01', 'wplb_to' => '2026-10-02' ), 2 ),
		'band+mode' => array( array( 'wplb_band' => '20m', 'wplb_mode' => 'SSB|USB' ), 2 ),
		'my_pota'   => array( array( 'wplb_my_pota' => 'US-5678' ), 6 ),
		'station'   => array( array( 'wplb_station' => 'W0CLUB' ), 1 ),
		'confirmed' => array( array( 'wplb_confirmed' => 'yes' ), 1 ),
	);
	foreach ( $cases as $label => $case ) {
		wplb_check( $case[1] === $rows( $render( '[wp_logbook filters="all"]', $case[0] ) ), "visitor filter {$label}" );
	}
	wplb_check( false !== strpos( $render( '[wp_logbook filters="all"]', array( 'wplb_state' => 'ZZ' ) ), 'No contacts match these filters.' ), 'no matches' );

	$html = $render( '[wp_logbook state="ma"]' );
	wplb_check( 1 === $rows( $html ) && false === strpos( $html, 'name="wplb_state"' ), 'fixed filter hides its control' );
	wplb_check( 6 === $rows( $render( '[wp_logbook my_pota="US-5678"]' ) ) && 1 === $rows( $render( '[wp_logbook station="w0club"]' ) ), 'fixed filters' );

	$html = $render( '[wp_logbook page_size="5"]', array( 'wplb_pg' => '2', 'wplb_state' => 'MO' ) );
	wplb_check( 5 === $rows( $html ) && false !== strpos( $html, 'Showing 6–10 of 12' ) && false !== strpos( $html, 'wplb_state=MO' ), 'page 2 keeps the filter' );
	wplb_check( 2 === $rows( $render( '[wp_logbook page_size="5"]', array( 'wplb_pg' => '99', 'wplb_state' => 'MO' ) ) ), 'page past the end shows the last page' );
	$html = $render( '[wp_logbook pagination="no" page_size="3"]', array( 'wplb_pg' => '3' ) );
	wplb_check( 3 === $rows( $html ) && false === strpos( $html, 'wplb-pagination' ) && false !== strpos( $html, 'VE3XYZ' ), 'pagination off shows the latest page_size QSOs' );
	wplb_check( 10 === $rows( $render( '[wp_logbook page_size="5" page_sizes="10,50"]', array( 'wplb_size' => '10' ) ) ), 'visitor page size' );
	wplb_check( 5 === $rows( $render( '[wp_logbook page_size="5" page_sizes="10,50"]', array( 'wplb_size' => '7' ) ) ), 'unlisted page size ignored' );

	$html = $render( '[wp_logbook_tiles page_size="4" columns="time_on,callsign,band,mode,state,pota_ref,confirmed"]', array( 'wplb_pota' => 'US-1234' ) );
	wplb_check( 1 === $rows( $html ) && false !== strpos( $html, 'wplb-tile-call">W1AW<' ) && false !== strpos( $html, '✓ Confirmed' ), 'tiles with a filter' );
	wplb_check( 4 === $rows( $render( '[wp_logbook layout="tiles" page_size="4"]' ) ), 'layout="tiles"' );
	wplb_check( 1 === $rows( $render( '[wp_logbook id="club" filters="state"]', array( 'wplb_club_state' => 'CT', 'wplb_state' => 'MA' ) ) ), 'id keeps logbooks independent' );
	$html = $render( '[wp_logbook]', array( 'wplb_county' => '"><script>alert(1)</script>' ) );
	wplb_check( false === strpos( $html, '<script>alert' ), 'visitor input escaped' );
} finally {
	wplb_reset();
	foreach ( array( WPLB_Settings::OPTION => 'settings', WPLB_Settings::API_KEY_OPTION => 'key' ) as $wplb_option => $wplb_saved ) {
		if ( false === $GLOBALS['wplb_saved'][ $wplb_saved ] ) {
			delete_option( $wplb_option );
		} else {
			update_option( $wplb_option, $GLOBALS['wplb_saved'][ $wplb_saved ] );
		}
	}
	WPLB_QRZ_Sync::sync_schedule();
}

printf( "\n%d checks, %d failed\n", $GLOBALS['wplb_tests'], $GLOBALS['wplb_failures'] );
if ( $GLOBALS['wplb_failures'] ) {
	exit( 1 );
}
