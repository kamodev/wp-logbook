<?php
/**
 * QRZ.com Logbook API client.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the QRZ.com Logbook API.
 *
 * The API is a single endpoint that takes an HTTP POST with KEY and ACTION
 * (INSERT, DELETE, STATUS or FETCH) plus action-specific ADIF, OPTION and
 * LOGIDS parameters. Responses are name=value pairs joined with "&":
 *
 *     RESULT=OK&LOGID=130877825&COUNT=1
 *     RESULT=FAIL&REASON=Unable to add QSO to database: duplicate
 *
 * RESULT is OK, FAIL, AUTH (the key lacks privileges for the action),
 * REPLACE (INSERT with OPTION=REPLACE overwrote a duplicate) or PARTIAL.
 *
 * @link https://www.qrz.com/docs/logbook/QRZLogbookAPI.html
 */
class WPLB_QRZ_Client {

	const ENDPOINT = 'https://logbook.qrz.com/api';

	/**
	 * Logbook API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Logbook API key from QRZ.com logbook settings.
	 */
	public function __construct( $api_key ) {
		$this->api_key = trim( (string) $api_key );
	}

	/**
	 * Logbook details (owner, callsign, QSO counts).
	 *
	 * @return array|WP_Error Parsed response.
	 */
	public function status() {
		return $this->request( 'STATUS' );
	}

	/**
	 * Add one QSO.
	 *
	 * @param string $adif    One ADIF record ending in <EOR>.
	 * @param bool   $replace Overwrite a QSO that QRZ.com considers a duplicate.
	 * @return array|WP_Error Parsed response.
	 */
	public function insert( $adif, $replace = false ) {
		$params = array( 'ADIF' => $adif );
		if ( $replace ) {
			$params['OPTION'] = 'REPLACE';
		}
		return $this->request( 'INSERT', $params );
	}

	/**
	 * Delete QSOs from the QRZ.com logbook.
	 *
	 * @param int[] $logids QRZ.com log IDs.
	 * @return array|WP_Error Parsed response.
	 */
	public function delete( array $logids ) {
		return $this->request( 'DELETE', array( 'LOGIDS' => implode( ',', array_map( 'absint', $logids ) ) ) );
	}

	/**
	 * Send a request.
	 *
	 * @param string $action API action.
	 * @param array  $params Extra parameters.
	 * @return array|WP_Error Parsed response with upper-case keys.
	 */
	public function request( $action, array $params = array() ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wplb_qrz_no_key', __( 'No QRZ.com Logbook API key is configured.', 'wp-logbook' ) );
		}

		$args = array(
			'timeout'    => 30,
			'user-agent' => self::user_agent(),
			'body'       => array_merge(
				array(
					'KEY'    => $this->api_key,
					'ACTION' => $action,
				),
				$params
			),
		);

		/**
		 * Filters the HTTP request sent to the QRZ.com Logbook API.
		 *
		 * @param array  $args   Arguments for wp_remote_post().
		 * @param string $action API action.
		 */
		$args = apply_filters( 'wplb_qrz_request_args', $args, $action );

		/**
		 * Filters the QRZ.com Logbook API endpoint.
		 *
		 * @param string $url Endpoint URL.
		 */
		$url = apply_filters( 'wplb_qrz_endpoint', self::ENDPOINT );

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wplb_qrz_http',
				/* translators: %s: error message */
				sprintf( __( 'Could not reach QRZ.com: %s', 'wp-logbook' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'wplb_qrz_http',
				/* translators: %d: HTTP status code */
				sprintf( __( 'QRZ.com answered with HTTP status %d.', 'wp-logbook' ), $code )
			);
		}

		$parsed = self::parse_response( wp_remote_retrieve_body( $response ) );
		if ( empty( $parsed['RESULT'] ) ) {
			return new WP_Error( 'wplb_qrz_response', __( 'QRZ.com sent a response this plugin does not understand.', 'wp-logbook' ) );
		}

		return $parsed;
	}

	/**
	 * Parse a Logbook API response.
	 *
	 * Values are URL-decoded. FETCH puts raw ADIF (which may contain "&")
	 * after ADIF=, so that part is split off first. STATUS can nest its
	 * details in DATA as another name=value string; those keys are copied to
	 * the top level when they do not clash.
	 *
	 * @param string $body Response body.
	 * @return array Upper-case keys.
	 */
	public static function parse_response( $body ) {
		$body   = trim( (string) $body );
		$result = array();

		if ( preg_match( '/(?:^|&)ADIF=/i', $body, $match, PREG_OFFSET_CAPTURE ) ) {
			$start          = $match[0][1] + strlen( $match[0][0] );
			$result['ADIF'] = html_entity_decode( substr( $body, $start ), ENT_QUOTES, 'UTF-8' );
			$body           = substr( $body, 0, $match[0][1] );
		}

		foreach ( self::split_pairs( $body ) as $key => $value ) {
			$result[ $key ] = $value;
		}

		if ( isset( $result['DATA'] ) && false !== strpos( $result['DATA'], '=' ) ) {
			foreach ( self::split_pairs( $result['DATA'] ) as $key => $value ) {
				if ( ! isset( $result[ $key ] ) ) {
					$result[ $key ] = $value;
				}
			}
		}

		return $result;
	}

	/**
	 * Split "A=1&B=2" into decoded pairs.
	 *
	 * @param string $text Query-string style text.
	 * @return array
	 */
	private static function split_pairs( $text ) {
		$pairs = array();
		foreach ( explode( '&', (string) $text ) as $pair ) {
			if ( '' === trim( $pair ) || false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $pair, 2 );
			$key                 = strtoupper( trim( urldecode( $key ) ) );
			$pairs[ $key ]       = trim( urldecode( $value ) );
		}
		return $pairs;
	}

	/**
	 * User-Agent that identifies this plugin to QRZ.com.
	 *
	 * @return string
	 */
	public static function user_agent() {
		$agent    = 'WP-Logbook/' . WPLB_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')';
		$callsign = WPLB_Settings::get( 'station_callsign' );
		if ( $callsign ) {
			$agent .= ' ' . $callsign;
		}
		return $agent;
	}
}
