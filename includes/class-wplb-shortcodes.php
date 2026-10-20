<?php
/**
 * Public display of the logbook.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * [wp_logbook], [wp_logbook_tiles] and [wp_logbook_stats] shortcodes.
 *
 * Only fields flagged "public" in WPLB_Fields can be shown, so private notes
 * and email addresses never reach the front end whatever the attributes say.
 */
class WPLB_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'wp_logbook', array( 'WPLB_Log_View', 'shortcode' ) );
		add_shortcode( 'wp_logbook_tiles', array( 'WPLB_Log_View', 'shortcode' ) );
		add_shortcode( 'wp_logbook_stats', array( __CLASS__, 'stats' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register the front-end stylesheet (enqueued only when a shortcode is used).
	 */
	public static function register_assets() {
		wp_register_style( 'wplb-public', WPLB_URL . 'assets/css/public.css', array(), WPLB_VERSION );
	}

	/**
	 * [wp_logbook_stats] – totals and band/mode breakdown.
	 *
	 * Attributes: show (comma separated: totals, bands, modes), station,
	 * logbook (comma separated slugs, titles or IDs).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function stats( $atts ) {
		$atts = shortcode_atts(
			array(
				'show'    => 'totals,bands,modes',
				'station' => '',
				'logbook' => '',
			),
			$atts,
			'wp_logbook_stats'
		);

		wp_enqueue_style( 'wplb-public' );

		$show = array_map( 'trim', explode( ',', strtolower( $atts['show'] ) ) );
		$args = array( 'station_callsign' => $atts['station'] );
		if ( '' !== trim( $atts['logbook'] ) ) {
			$args['logbook'] = WPLB_Logbooks::resolve_list( $atts['logbook'] );
		}
		$stats = WPLB_QSOs::stats( $args );

		ob_start();
		echo '<div class="wplb-stats">';

		if ( in_array( 'totals', $show, true ) ) {
			$totals = array(
				__( 'QSOs', 'wp-logbook' )             => number_format_i18n( $stats['qsos'] ),
				__( 'Unique callsigns', 'wp-logbook' ) => number_format_i18n( $stats['callsigns'] ),
			);
			if ( $stats['dxcc'] ) {
				$totals[ __( 'DXCC entities', 'wp-logbook' ) ] = number_format_i18n( $stats['dxcc'] );
			} else {
				$totals[ __( 'Countries', 'wp-logbook' ) ] = number_format_i18n( $stats['countries'] );
			}
			$totals[ __( 'Grid squares', 'wp-logbook' ) ] = number_format_i18n( $stats['grids'] );
			echo '<dl class="wplb-totals">';
			foreach ( $totals as $label => $value ) {
				printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), esc_html( $value ) );
			}
			if ( $stats['first_qso'] ) {
				$dates = array(
					__( 'First QSO', 'wp-logbook' )  => $stats['first_qso'],
					__( 'Latest QSO', 'wp-logbook' ) => $stats['last_qso'],
				);
				foreach ( $dates as $label => $value ) {
					printf( '<div><dt>%s</dt><dd class="wplb-date">%s</dd></div>', esc_html( $label ), esc_html( substr( $value, 0, 10 ) ) );
				}
			}
			echo '</dl>';
		}

		$tables = array(
			'bands' => array( __( 'Band', 'wp-logbook' ), $stats['bands'] ),
			'modes' => array( __( 'Mode', 'wp-logbook' ), $stats['modes'] ),
		);
		foreach ( $tables as $key => $table ) {
			if ( ! in_array( $key, $show, true ) || ! $table[1] ) {
				continue;
			}
			printf( '<table class="wplb-table wplb-stats-%s"><thead><tr><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>', esc_attr( $key ), esc_html( $table[0] ), esc_html__( 'QSOs', 'wp-logbook' ) );
			foreach ( $table[1] as $label => $count ) {
				printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $label ), esc_html( number_format_i18n( $count ) ) );
			}
			echo '</tbody></table>';
		}

		echo '</div>';
		return ob_get_clean();
	}
}
