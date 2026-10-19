<?php
/**
 * Plugin settings storage.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitises and stores the plugin options.
 *
 * The QRZ.com API key is kept in its own option (never autoloaded, never
 * printed back in full) and can instead be defined as WPLB_QRZ_API_KEY in
 * wp-config.php.
 */
class WPLB_Settings {

	const OPTION         = 'wplb_settings';
	const API_KEY_OPTION = 'wplb_qrz_api_key';

	/**
	 * Station fields that pre-fill new QSOs.
	 *
	 * @var string[]
	 */
	const STATION_FIELDS = array( 'station_callsign', 'operator', 'my_gridsquare', 'my_city', 'my_state', 'my_cnty', 'my_country', 'tx_pwr', 'my_rig', 'my_antenna' );

	/**
	 * Validation errors already reported during this request.
	 *
	 * @var array
	 */
	private static $reported = array();

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			'public_columns'      => array( 'time_on', 'callsign', 'band', 'mode', 'country', 'gridsquare' ),
			'public_per_page'     => 25,
			'public_search'       => 1,
			'qrz_enabled'         => 0,
			'qrz_auto_upload'     => 1,
			'qrz_background_sync' => 0,
			'qrz_sync_edits'      => 1,
			'qrz_delete_remote'   => 0,
			'delete_on_uninstall' => 0,
		);
		foreach ( self::STATION_FIELDS as $key ) {
			$defaults[ $key ] = '';
		}
		return $defaults;
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Values that pre-fill the "My station" part of a new QSO.
	 *
	 * @return array
	 */
	public static function station_defaults() {
		$all = self::all();
		return array_intersect_key( $all, array_flip( self::STATION_FIELDS ) );
	}

	/**
	 * The QRZ.com Logbook API key.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::api_key_is_constant() ) {
			return trim( (string) WPLB_QRZ_API_KEY );
		}
		return trim( (string) get_option( self::API_KEY_OPTION, '' ) );
	}

	/**
	 * Whether the API key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant() {
		return defined( 'WPLB_QRZ_API_KEY' ) && '' !== trim( (string) WPLB_QRZ_API_KEY );
	}

	/**
	 * A key with all but the last four characters hidden.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Sanitise the settings array (register_setting callback).
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		list( $station, $errors ) = WPLB_Fields::sanitize( array_intersect_key( $input, array_flip( self::STATION_FIELDS ) ) );
		foreach ( self::STATION_FIELDS as $key ) {
			$clean[ $key ] = isset( $station[ $key ] ) && null !== $station[ $key ] ? (string) $station[ $key ] : '';
		}
		foreach ( $errors as $key => $message ) {
			// WordPress runs this callback twice when the option is first created.
			if ( function_exists( 'add_settings_error' ) && ! isset( self::$reported[ $key ] ) ) {
				add_settings_error( self::OPTION, 'wplb_' . $key, $message );
				self::$reported[ $key ] = true;
			}
		}

		$allowed                 = array_keys( WPLB_Fields::public_columns() );
		$columns                 = isset( $input['public_columns'] ) ? array_map( 'sanitize_key', (array) $input['public_columns'] ) : array();
		$clean['public_columns'] = array_values( array_intersect( $columns, $allowed ) );
		if ( empty( $clean['public_columns'] ) ) {
			$clean['public_columns'] = $defaults['public_columns'];
		}

		$per_page                 = isset( $input['public_per_page'] ) ? absint( $input['public_per_page'] ) : $defaults['public_per_page'];
		$clean['public_per_page'] = min( 500, max( 1, $per_page ) );

		foreach ( array( 'public_search', 'qrz_enabled', 'qrz_auto_upload', 'qrz_background_sync', 'qrz_sync_edits', 'qrz_delete_remote', 'delete_on_uninstall' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		return $clean;
	}

	/**
	 * Sanitise the API key option (register_setting callback).
	 *
	 * An empty submission keeps the stored key so the settings form never has
	 * to print it; the "remove" checkbox clears it.
	 *
	 * @param mixed $value Submitted key.
	 * @return string
	 */
	public static function sanitize_api_key( $value ) {
		$stored = (string) get_option( self::API_KEY_OPTION, '' );

		// The settings form is nonce-checked by options.php before this runs.
		if ( ! empty( $_POST['wplb_qrz_api_key_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return $stored;
		}
		return preg_replace( '/[^A-Za-z0-9-]/', '', $value );
	}
}
