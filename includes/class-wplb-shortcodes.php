<?php
/**
 * Public display of the logbook.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * [wp_logbook] and [wp_logbook_stats] shortcodes.
 *
 * Only fields flagged "public" in WPLB_Fields can be shown, so private notes
 * and email addresses never reach the front end whatever the attributes say.
 */
class WPLB_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'wp_logbook', array( __CLASS__, 'logbook' ) );
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
	 * [wp_logbook] – table of QSOs with an optional "Am I in your log?" lookup.
	 *
	 * Attributes: columns (comma separated), limit, search (yes|no), band,
	 * mode, station (station callsign), callsign, pagination (yes|no).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function logbook( $atts ) {
		$settings = WPLB_Settings::all();
		$atts     = shortcode_atts(
			array(
				'columns'    => implode( ',', $settings['public_columns'] ),
				'limit'      => $settings['public_per_page'],
				'search'     => $settings['public_search'] ? 'yes' : 'no',
				'band'       => '',
				'mode'       => '',
				'station'    => '',
				'callsign'   => '',
				'pagination' => 'yes',
			),
			$atts,
			'wp_logbook'
		);

		wp_enqueue_style( 'wplb-public' );

		$available = WPLB_Fields::public_columns();
		$columns   = array_values( array_intersect( array_map( 'trim', explode( ',', strtolower( $atts['columns'] ) ) ), array_keys( $available ) ) );
		if ( ! $columns ) {
			$columns = $settings['public_columns'];
		}
		$limit      = min( 500, max( 1, absint( $atts['limit'] ) ) );
		$search     = self::is_yes( $atts['search'] );
		$paginate   = self::is_yes( $atts['pagination'] );
		$lookup_raw = '';
		$lookup     = '';

		// Read-only public query arguments; no state changes, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( $search && isset( $_GET['wplb_call'] ) ) {
			$lookup_raw = sanitize_text_field( wp_unslash( $_GET['wplb_call'] ) );
			$lookup     = (string) WPLB_Fields::normalize_callsign( $lookup_raw );
		}
		$page = $paginate && isset( $_GET['wplb_pg'] ) ? max( 1, absint( $_GET['wplb_pg'] ) ) : 1;
		// phpcs:enable

		$query = array(
			'band'             => $atts['band'],
			'mode'             => $atts['mode'],
			'station_callsign' => $atts['station'],
			'callsign'         => '' !== $lookup ? $lookup : $atts['callsign'],
			'per_page'         => $limit,
			'page'             => $page,
		);
		if ( '' !== $lookup_raw && '' === $lookup ) {
			$result = array(
				'items' => array(),
				'total' => 0,
			);
		} else {
			$result = WPLB_QSOs::query( $query );
		}

		ob_start();
		echo '<div class="wplb-logbook">';

		if ( $search ) {
			self::lookup_form( $lookup_raw );
			if ( '' !== $lookup_raw ) {
				echo '<p class="wplb-lookup-result" role="status">';
				if ( $result['total'] ) {
					echo esc_html(
						sprintf(
							/* translators: 1: number of contacts, 2: callsign */
							_n( '%1$s contact with %2$s found.', '%1$s contacts with %2$s found.', $result['total'], 'wp-logbook' ),
							number_format_i18n( $result['total'] ),
							'' !== $lookup ? $lookup : $lookup_raw
						)
					);
				} else {
					/* translators: %s: callsign */
					echo esc_html( sprintf( __( 'No contacts with %s in this log.', 'wp-logbook' ), '' !== $lookup ? $lookup : $lookup_raw ) );
				}
				echo '</p>';
			}
		}

		if ( $result['items'] ) {
			echo '<div class="wplb-table-wrap"><table class="wplb-table"><thead><tr>';
			foreach ( $columns as $column ) {
				printf( '<th scope="col" class="wplb-col-%s">%s</th>', esc_attr( $column ), esc_html( $available[ $column ] ) );
			}
			echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $qso ) {
				echo '<tr>';
				foreach ( $columns as $column ) {
					printf( '<td class="wplb-col-%s">%s</td>', esc_attr( $column ), esc_html( self::cell( $qso, $column ) ) );
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';

			if ( $paginate ) {
				self::pagination( $page, (int) ceil( $result['total'] / $limit ) );
			}
		} elseif ( '' === $lookup_raw ) {
			echo '<p class="wplb-empty">' . esc_html__( 'No contacts logged yet.', 'wp-logbook' ) . '</p>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * [wp_logbook_stats] – totals and band/mode breakdown.
	 *
	 * Attributes: show (comma separated: totals, bands, modes), station.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function stats( $atts ) {
		$atts = shortcode_atts(
			array(
				'show'    => 'totals,bands,modes',
				'station' => '',
			),
			$atts,
			'wp_logbook_stats'
		);

		wp_enqueue_style( 'wplb-public' );

		$show  = array_map( 'trim', explode( ',', strtolower( $atts['show'] ) ) );
		$stats = WPLB_QSOs::stats( array( 'station_callsign' => $atts['station'] ) );

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

	/**
	 * Text shown in a public table cell.
	 *
	 * @param array  $qso    QSO row.
	 * @param string $column Column key.
	 * @return string
	 */
	private static function cell( array $qso, $column ) {
		switch ( $column ) {
			case 'time_on':
				return substr( (string) $qso['time_on'], 0, 16 );
			case 'mode':
				return WPLB_Fields::display_mode( $qso['mode'], $qso['submode'] );
			case 'freq':
			case 'tx_pwr':
				return WPLB_Fields::format_number( $qso[ $column ] );
			case 'confirmed':
				foreach ( array( 'qsl_rcvd', 'lotw_qsl_rcvd', 'eqsl_qsl_rcvd' ) as $key ) {
					if ( 'Y' === $qso[ $key ] ) {
						return '✓';
					}
				}
				return '';
			default:
				return isset( $qso[ $column ] ) ? (string) $qso[ $column ] : '';
		}
	}

	/**
	 * Callsign lookup form.
	 *
	 * @param string $value Current lookup.
	 */
	private static function lookup_form( $value ) {
		static $instance = 0;
		++$instance;
		$id = 'wplb-call-' . $instance;

		echo '<form class="wplb-lookup" method="get" action="">';
		// Keep the query arguments that identify the page (e.g. ?page_id=12 without pretty permalinks).
		foreach ( $_GET as $key => $arg ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $arg ) && ! in_array( $key, array( 'wplb_call', 'wplb_pg' ), true ) ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( sanitize_key( $key ) ), esc_attr( sanitize_text_field( wp_unslash( $arg ) ) ) );
			}
		}
		printf( '<label for="%s">%s</label> ', esc_attr( $id ), esc_html__( 'Are you in the log? Enter your callsign:', 'wp-logbook' ) );
		printf( '<input type="text" id="%s" name="wplb_call" value="%s" maxlength="32" autocomplete="off" spellcheck="false"> ', esc_attr( $id ), esc_attr( $value ) );
		printf( '<button type="submit">%s</button>', esc_html__( 'Search', 'wp-logbook' ) );
		if ( '' !== $value ) {
			printf( ' <a class="wplb-lookup-clear" href="%s">%s</a>', esc_url( remove_query_arg( array( 'wplb_call', 'wplb_pg' ) ) ), esc_html__( 'Show all contacts', 'wp-logbook' ) );
		}
		echo '</form>';
	}

	/**
	 * Previous/next page links.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Number of pages.
	 */
	private static function pagination( $page, $pages ) {
		if ( $pages < 2 ) {
			return;
		}
		echo '<nav class="wplb-pagination" aria-label="' . esc_attr__( 'Logbook pages', 'wp-logbook' ) . '">';
		if ( $page > 1 ) {
			printf( '<a class="wplb-prev" href="%s">%s</a> ', esc_url( add_query_arg( 'wplb_pg', $page - 1 ) ), esc_html__( '« Newer', 'wp-logbook' ) );
		}
		/* translators: 1: current page, 2: number of pages */
		printf( '<span class="wplb-page-count">%s</span>', esc_html( sprintf( __( 'Page %1$s of %2$s', 'wp-logbook' ), number_format_i18n( $page ), number_format_i18n( $pages ) ) ) );
		if ( $page < $pages ) {
			printf( ' <a class="wplb-next" href="%s">%s</a>', esc_url( add_query_arg( 'wplb_pg', $page + 1 ) ), esc_html__( 'Older »', 'wp-logbook' ) );
		}
		echo '</nav>';
	}

	/**
	 * Whether a shortcode attribute means "yes".
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function is_yes( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'yes', 'true', '1', 'on' ), true );
	}
}
