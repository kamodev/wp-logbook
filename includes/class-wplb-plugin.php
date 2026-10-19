<?php
/**
 * Plugin bootstrap.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
class WPLB_Plugin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( 'WPLB_Install', 'maybe_upgrade' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		WPLB_QRZ_Sync::init();
		WPLB_Shortcodes::init();

		if ( is_admin() ) {
			WPLB_Admin::init();
		}
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'wp-logbook', false, dirname( plugin_basename( WPLB_FILE ) ) . '/languages' );
	}

	/**
	 * Capability needed to view and edit the logbook.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage the logbook.
		 *
		 * Settings always require manage_options.
		 *
		 * @param string $capability Capability name.
		 */
		return apply_filters( 'wplb_capability', 'manage_options' );
	}
}
