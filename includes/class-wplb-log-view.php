<?php
/**
 * Public logbook view.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders [wp_logbook] (table) and [wp_logbook_tiles] (tiles): a filter
 * form, the QSOs and page links.
 *
 * Every filter can be switched on or off with an attribute, and most can be
 * fixed to a value instead (e.g. my_pota="US-1234" for a page about one
 * activation). Visitor input arrives as GET parameters named wplb_<filter>
 * (wplb_<id>_<filter> when the shortcode has an id), so pages with several
 * logbooks stay independent. Only public fields can be shown or filtered.
 */
class WPLB_Log_View {

	/**
	 * Filters shown when the shortcode does not choose any.
	 *
	 * @var string[]
	 */
	const DEFAULT_FILTERS = array( 'date', 'callsign', 'band', 'mode', 'grid', 'state', 'county', 'pota', 'sota' );

	/**
	 * Alternative names accepted in the filters attribute.
	 *
	 * @var array
	 */
	const ALIASES = array(
		'gridsquare' => 'grid',
		'cnty'       => 'county',
		'cont'       => 'continent',
		'pota_ref'   => 'pota',
		'sota_ref'   => 'sota',
	);

	/**
	 * Counter for unique element IDs.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Filters visitors can use.
	 *
	 * Each filter becomes a WPLB_QSOs::query() argument: "arg" names the
	 * argument directly, otherwise "match" (equals, prefix or contains) is
	 * applied to "column". Select filters list the values found in the log.
	 *
	 * @return array Key => definition.
	 */
	public static function filters() {
		return array(
			'date'      => array(
				'label' => __( 'Date (UTC)', 'wp-logbook' ),
				'type'  => 'date',
			),
			'callsign'  => array(
				'label'       => __( 'Callsign', 'wp-logbook' ),
				'type'        => 'text',
				'arg'         => 'callsign_starts',
				'placeholder' => 'K1ABC',
			),
			'band'      => array(
				'label' => __( 'Band', 'wp-logbook' ),
				'type'  => 'select',
				'arg'   => 'band',
			),
			'mode'      => array(
				'label' => __( 'Mode', 'wp-logbook' ),
				'type'  => 'select',
				'arg'   => 'mode',
			),
			'grid'      => array(
				'label'       => __( 'Grid square', 'wp-logbook' ),
				'type'        => 'text',
				'match'       => 'prefix',
				'column'      => 'gridsquare',
				'placeholder' => 'FN31',
			),
			'state'     => array(
				'label'  => __( 'State / province', 'wp-logbook' ),
				'type'   => 'select',
				'match'  => 'equals',
				'column' => 'state',
			),
			'county'    => array(
				'label'  => __( 'County', 'wp-logbook' ),
				'type'   => 'text',
				'match'  => 'contains',
				'column' => 'cnty',
			),
			'country'   => array(
				'label'  => __( 'Country', 'wp-logbook' ),
				'type'   => 'select',
				'match'  => 'equals',
				'column' => 'country',
			),
			'continent' => array(
				'label'  => __( 'Continent', 'wp-logbook' ),
				'type'   => 'select',
				'match'  => 'equals',
				'column' => 'cont',
			),
			'pota'      => array(
				'label'       => __( 'POTA park', 'wp-logbook' ),
				'type'        => 'text',
				'match'       => 'contains',
				'column'      => 'pota_ref',
				'placeholder' => 'US-0001',
			),
			'sota'      => array(
				'label'       => __( 'SOTA summit', 'wp-logbook' ),
				'type'        => 'text',
				'match'       => 'contains',
				'column'      => 'sota_ref',
				'placeholder' => 'W7W/LC-001',
			),
			'wwff'      => array(
				'label'       => __( 'WWFF reference', 'wp-logbook' ),
				'type'        => 'text',
				'match'       => 'contains',
				'column'      => 'wwff_ref',
				'placeholder' => 'KFF-0001',
			),
			'iota'      => array(
				'label'       => __( 'IOTA', 'wp-logbook' ),
				'type'        => 'text',
				'match'       => 'prefix',
				'column'      => 'iota',
				'placeholder' => 'NA-001',
			),
			'my_pota'   => array(
				'label'   => __( 'My POTA park', 'wp-logbook' ),
				'type'    => 'text',
				'match'   => 'contains',
				'column'  => 'my_pota_ref',
				'suggest' => true,
			),
			'my_sota'   => array(
				'label'   => __( 'My SOTA summit', 'wp-logbook' ),
				'type'    => 'text',
				'match'   => 'contains',
				'column'  => 'my_sota_ref',
				'suggest' => true,
			),
			'contest'   => array(
				'label'  => __( 'Contest', 'wp-logbook' ),
				'type'   => 'select',
				'match'  => 'equals',
				'column' => 'contest_id',
			),
			'satellite' => array(
				'label'  => __( 'Satellite', 'wp-logbook' ),
				'type'   => 'select',
				'match'  => 'equals',
				'column' => 'sat_name',
			),
			'station'   => array(
				'label'  => __( 'Station callsign', 'wp-logbook' ),
				'type'   => 'select',
				'match'  => 'equals',
				'column' => 'station_callsign',
			),
			'confirmed' => array(
				'label' => __( 'QSL', 'wp-logbook' ),
				'type'  => 'select',
				'arg'   => 'confirmed',
			),
		);
	}

	/**
	 * Shortcode callback for [wp_logbook] and [wp_logbook_tiles].
	 *
	 * @param array|string $atts    Attributes.
	 * @param string       $content Enclosed content (unused).
	 * @param string       $tag     Shortcode name.
	 * @return string
	 */
	public static function shortcode( $atts, $content = '', $tag = 'wp_logbook' ) {
		$atts = shortcode_atts( self::default_atts( 'wp_logbook_tiles' === $tag ? 'tiles' : 'table' ), $atts, $tag );
		wp_enqueue_style( 'wplb-public' );
		return self::render( $atts );
	}

	/**
	 * Attributes and their defaults.
	 *
	 * @param string $layout Default layout.
	 * @return array
	 */
	public static function default_atts( $layout ) {
		$settings = WPLB_Settings::all();
		$atts     = array(
			'layout'           => $layout,
			'columns'          => implode( ',', $settings['public_columns'] ),
			'pagination'       => 'yes',
			'page_size'        => '',
			'limit'            => '',
			'page_sizes'       => '',
			'filters'          => '',
			'collapse_filters' => 'no',
			'search'           => '',
			'id'               => '',
			'callsign'         => '',
			'date_from'        => '',
			'date_to'          => '',
		);
		foreach ( self::filters() as $key => $filter ) {
			$atts[ 'filter_' . $key ] = '';
			if ( 'date' !== $key && 'callsign' !== $key ) {
				$atts[ $key ] = '';
			}
		}
		return $atts;
	}

	/**
	 * Render the logbook.
	 *
	 * @param array $atts Attributes merged with default_atts().
	 * @return string HTML.
	 */
	public static function render( array $atts ) {
		++self::$instance;

		$settings = WPLB_Settings::all();
		$prefix   = 'wplb_' . ( '' !== $atts['id'] ? sanitize_key( $atts['id'] ) . '_' : '' );
		$layout   = in_array( strtolower( $atts['layout'] ), array( 'tiles', 'tile', 'cards', 'grid' ), true ) ? 'tiles' : 'table';
		$columns  = self::columns( $atts['columns'], $settings['public_columns'] );
		$paginate = self::is_yes( $atts['pagination'] );

		// Page size: page_size, the older "limit", then the setting.
		$size  = '' !== $atts['page_size'] ? $atts['page_size'] : $atts['limit'];
		$size  = min( 500, max( 1, absint( '' !== $size ? $size : $settings['public_per_page'] ) ) );
		$sizes = array();
		if ( $paginate && '' !== trim( $atts['page_sizes'] ) ) {
			$sizes = array_map( 'absint', explode( ',', $atts['page_sizes'] ) );
			$sizes = array_filter(
				array_merge( $sizes, array( $size ) ),
				function ( $value ) {
					return $value >= 1 && $value <= 500;
				}
			);
			$sizes = array_values( array_unique( $sizes ) );
			sort( $sizes );
			$chosen = absint( self::param( $prefix . 'size' ) );
			if ( in_array( $chosen, $sizes, true ) ) {
				$size = $chosen;
			}
		}

		list( $base, $fixed ) = self::fixed_query( $atts );
		$enabled              = self::enabled_filters( $atts, $fixed, ! empty( $settings['public_search'] ) );
		$values               = self::visitor_values( $enabled, $prefix );

		$query = $base;
		foreach ( $values as $key => $value ) {
			self::apply_filter( $query, $key, $value );
		}

		$page   = $paginate ? max( 1, absint( self::param( $prefix . 'pg' ) ) ) : 1;
		$result = WPLB_QSOs::query(
			array_merge(
				$query,
				array(
					'per_page' => $size,
					'page'     => $page,
				)
			)
		);
		if ( ! $result['items'] && $result['total'] && $page > 1 ) {
			// Past the last page, e.g. after a filter narrowed the results.
			$page   = (int) ceil( $result['total'] / $size );
			$result = WPLB_QSOs::query(
				array_merge(
					$query,
					array(
						'per_page' => $size,
						'page'     => $page,
					)
				)
			);
		}

		$state = array(
			'prefix'   => $prefix,
			'enabled'  => $enabled,
			'values'   => $values,
			'base'     => $base,
			'sizes'    => $sizes,
			'size'     => $size,
			'params'   => self::own_params( $prefix ),
			'collapse' => self::is_yes( $atts['collapse_filters'] ),
		);

		ob_start();
		printf( '<div class="wplb-logbook wplb-layout-%s">', esc_attr( $layout ) );

		if ( $enabled || $sizes ) {
			self::filter_form( $state );
		}

		if ( $result['items'] ) {
			self::summary( $result['total'], $page, $size, $paginate );
			if ( 'tiles' === $layout ) {
				self::tiles( $result['items'], $columns );
			} else {
				self::table( $result['items'], $columns );
			}
			if ( $paginate ) {
				self::pagination( $page, (int) ceil( $result['total'] / $size ), $prefix . 'pg' );
			}
		} elseif ( $values || $base ) {
			echo '<p class="wplb-empty" role="status">' . esc_html__( 'No contacts match these filters.', 'wp-logbook' ) . '</p>';
		} else {
			echo '<p class="wplb-empty">' . esc_html__( 'No contacts logged yet.', 'wp-logbook' ) . '</p>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Columns to show, limited to public fields.
	 *
	 * @param string   $keys     Comma separated column keys.
	 * @param string[] $fallback Columns used when the list has no valid key.
	 * @return string[]
	 */
	private static function columns( $keys, array $fallback ) {
		$available = array_keys( WPLB_Fields::public_columns() );
		$columns   = array_values( array_intersect( array_map( 'trim', explode( ',', strtolower( $keys ) ) ), $available ) );
		return $columns ? $columns : $fallback;
	}

	/**
	 * Query arguments fixed by shortcode attributes.
	 *
	 * @param array $atts Attributes.
	 * @return array array( query arguments, keys of the filters they fix ).
	 */
	private static function fixed_query( array $atts ) {
		$query = array();
		$fixed = array();

		if ( '' !== trim( $atts['callsign'] ) ) {
			$query['callsign'] = strtoupper( trim( $atts['callsign'] ) );
			$fixed[]           = 'callsign';
		}
		foreach ( array( 'from', 'to' ) as $end ) {
			$date = WPLB_Fields::normalize_date( $atts[ 'date_' . $end ] );
			if ( $date ) {
				$query[ 'date_' . $end ] = $date;
				$fixed[]                 = 'date';
			}
		}
		foreach ( self::filters() as $key => $filter ) {
			if ( isset( $atts[ $key ] ) && '' !== trim( $atts[ $key ] ) ) {
				self::apply_filter( $query, $key, trim( $atts[ $key ] ) );
				$fixed[] = $key;
			}
		}

		return array( $query, array_values( array_unique( $fixed ) ) );
	}

	/**
	 * Filters to show, from the filters attribute and the filter_* switches.
	 *
	 * @param array    $atts   Attributes.
	 * @param string[] $fixed  Filters fixed by attributes (never shown).
	 * @param bool     $search Whether the callsign search setting is on.
	 * @return string[]
	 */
	private static function enabled_filters( array $atts, array $fixed, $search ) {
		$all  = array_keys( self::filters() );
		$list = strtolower( trim( $atts['filters'] ) );

		if ( '' === $list ) {
			$enabled = self::DEFAULT_FILTERS;
			if ( ! $search ) {
				$enabled = array_diff( $enabled, array( 'callsign' ) );
			}
		} elseif ( 'all' === $list ) {
			$enabled = $all;
		} elseif ( 'none' === $list || 'no' === $list ) {
			$enabled = array();
		} else {
			$enabled = array();
			foreach ( explode( ',', $list ) as $key ) {
				$key       = trim( $key );
				$key       = isset( self::ALIASES[ $key ] ) ? self::ALIASES[ $key ] : $key;
				$enabled[] = $key;
			}
			$enabled = array_intersect( $enabled, $all );
		}

		// "search" is the 1.0 name of the callsign filter switch.
		$switches = array( 'filter_callsign' => '' !== $atts['filter_callsign'] ? $atts['filter_callsign'] : $atts['search'] );
		foreach ( $all as $key ) {
			$switch = 'callsign' === $key ? $switches['filter_callsign'] : $atts[ 'filter_' . $key ];
			if ( '' === trim( (string) $switch ) ) {
				continue;
			}
			if ( self::is_yes( $switch ) ) {
				$enabled[] = $key;
			} else {
				$enabled = array_diff( $enabled, array( $key ) );
			}
		}

		return array_values( array_diff( array_unique( $enabled ), $fixed ) );
	}

	/**
	 * Filter values submitted by the visitor.
	 *
	 * @param string[] $enabled Enabled filters.
	 * @param string   $prefix  Parameter prefix.
	 * @return array Filter key (date_from/date_to for dates) => value.
	 */
	private static function visitor_values( array $enabled, $prefix ) {
		$values = array();
		foreach ( $enabled as $key ) {
			if ( 'date' === $key ) {
				foreach ( array( 'from', 'to' ) as $end ) {
					$date = WPLB_Fields::normalize_date( self::param( $prefix . $end ) );
					if ( $date ) {
						$values[ 'date_' . $end ] = $date;
					}
				}
				continue;
			}
			$value = self::param( $prefix . $key );
			if ( '' !== $value ) {
				$values[ $key ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Add a filter value to query arguments.
	 *
	 * @param array  $query Query arguments (modified).
	 * @param string $key   Filter key, or date_from/date_to.
	 * @param string $value Value.
	 */
	private static function apply_filter( array &$query, $key, $value ) {
		if ( 'date_from' === $key || 'date_to' === $key ) {
			$query[ $key ] = $value;
			return;
		}

		$filters = self::filters();
		if ( ! isset( $filters[ $key ] ) ) {
			return;
		}
		$filter = $filters[ $key ];

		if ( isset( $filter['arg'] ) ) {
			if ( 'confirmed' === $filter['arg'] ) {
				$value = self::is_yes( $value ) || 'confirmed' === strtolower( $value ) ? 'yes' : 'no';
			} elseif ( 'callsign_starts' === $filter['arg'] ) {
				$value = strtoupper( preg_replace( '/\s+/', '', $value ) );
			}
			$query[ $filter['arg'] ] = $value;
		} elseif ( isset( $filter['match'], $filter['column'] ) ) {
			// Exact matches can be case-sensitive; these columns are stored in upper case.
			$field = WPLB_Fields::get( $filter['column'] );
			if ( 'equals' === $filter['match'] && $field && in_array( $field['type'], array( 'callsign', 'upper', 'enum' ), true ) ) {
				$value = strtoupper( $value );
			}
			$query[ $filter['match'] ][ $filter['column'] ] = $value;
		}
	}

	/**
	 * Every GET parameter this logbook uses.
	 *
	 * @param string $prefix Parameter prefix.
	 * @return string[]
	 */
	private static function own_params( $prefix ) {
		$params = array( $prefix . 'from', $prefix . 'to', $prefix . 'pg', $prefix . 'size' );
		foreach ( array_keys( self::filters() ) as $key ) {
			$params[] = $prefix . $key;
		}
		return $params;
	}

	/**
	 * A sanitised GET parameter.
	 *
	 * @param string $name Parameter name.
	 * @return string
	 */
	private static function param( $name ) {
		// Read-only public filters; no state changes, so no nonce.
		if ( ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$value = trim( sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return mb_substr( $value, 0, 64 );
	}

	/**
	 * The filter form.
	 *
	 * @param array $state Render state.
	 */
	private static function filter_form( array $state ) {
		$filters = self::filters();
		$id      = 'wplb-' . self::$instance;
		$options = null;

		echo '<form class="wplb-filters" method="get" action="" role="search">';

		// Keep the query arguments that identify the page (e.g. ?page_id=12 without pretty permalinks).
		foreach ( $_GET as $key => $arg ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $arg ) && ! in_array( $key, $state['params'], true ) ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( sanitize_key( $key ) ), esc_attr( sanitize_text_field( wp_unslash( $arg ) ) ) );
			}
		}

		$open = ! $state['collapse'] || $state['values'];
		printf( '<details class="wplb-filters-panel"%s><summary class="wplb-filters-title">%s</summary>', $open ? ' open' : '', esc_html( $state['enabled'] ? __( 'Filter contacts', 'wp-logbook' ) : __( 'Display', 'wp-logbook' ) ) );
		echo '<div class="wplb-filter-fields">';
		foreach ( $state['enabled'] as $key ) {
			$filter = $filters[ $key ];
			$name   = $state['prefix'] . $key;
			$field  = $id . '-' . $key;

			if ( 'date' === $filter['type'] ) {
				$ends = array(
					'from' => __( 'From (UTC)', 'wp-logbook' ),
					'to'   => __( 'To (UTC)', 'wp-logbook' ),
				);
				foreach ( $ends as $end => $label ) {
					$value = isset( $state['values'][ 'date_' . $end ] ) ? $state['values'][ 'date_' . $end ] : '';
					printf(
						'<div class="wplb-filter wplb-filter-date-%1$s"><label for="%2$s">%3$s</label><input type="date" id="%2$s" name="%4$s" value="%5$s"></div>',
						esc_attr( $end ),
						esc_attr( $field . '-' . $end ),
						esc_html( $label ),
						esc_attr( $state['prefix'] . $end ),
						esc_attr( $value )
					);
				}
				continue;
			}

			$value = isset( $state['values'][ $key ] ) ? $state['values'][ $key ] : '';

			if ( 'select' === $filter['type'] ) {
				if ( null === $options && in_array( $key, array( 'band', 'mode' ), true ) ) {
					$options = WPLB_QSOs::filter_options( $state['base'] );
				}
				$choices = self::choices( $key, $filter, $state['base'], $options );
				// A menu with a single entry filters nothing, unless it is the one in use.
				if ( count( $choices ) < 2 && '' === $value ) {
					continue;
				}
				printf( '<div class="wplb-filter wplb-filter-%1$s"><label for="%2$s">%3$s</label><select id="%2$s" name="%4$s">', esc_attr( $key ), esc_attr( $field ), esc_html( $filter['label'] ), esc_attr( $name ) );
				printf( '<option value="">%s</option>', esc_html__( 'All', 'wp-logbook' ) );
				foreach ( $choices as $choice => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $choice ), selected( (string) $choice, $value, false ), esc_html( $label ) );
				}
				echo '</select></div>';
				continue;
			}

			$list = '';
			if ( ! empty( $filter['suggest'] ) ) {
				$suggestions = WPLB_QSOs::distinct_values( $filter['column'], $state['base'], 200 );
				if ( $suggestions ) {
					$list = $field . '-list';
				}
			}
			printf(
				'<div class="wplb-filter wplb-filter-%1$s"><label for="%2$s">%3$s</label><input type="text" id="%2$s" name="%4$s" value="%5$s" placeholder="%6$s" maxlength="64" autocomplete="off" spellcheck="false"%7$s>',
				esc_attr( $key ),
				esc_attr( $field ),
				esc_html( $filter['label'] ),
				esc_attr( $name ),
				esc_attr( $value ),
				esc_attr( isset( $filter['placeholder'] ) ? $filter['placeholder'] : '' ),
				$list ? ' list="' . esc_attr( $list ) . '"' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
			);
			if ( $list ) {
				printf( '<datalist id="%s">', esc_attr( $list ) );
				foreach ( $suggestions as $suggestion ) {
					printf( '<option value="%s"></option>', esc_attr( $suggestion ) );
				}
				echo '</datalist>';
			}
			echo '</div>';
		}

		if ( $state['sizes'] ) {
			printf( '<div class="wplb-filter wplb-filter-size"><label for="%1$s-size">%2$s</label><select id="%1$s-size" name="%3$s">', esc_attr( $id ), esc_html__( 'Per page', 'wp-logbook' ), esc_attr( $state['prefix'] . 'size' ) );
			foreach ( $state['sizes'] as $choice ) {
				printf( '<option value="%1$d"%2$s>%1$d</option>', (int) $choice, selected( $choice, $state['size'], false ) );
			}
			echo '</select></div>';
		}
		echo '</div>';

		echo '<div class="wplb-filter-actions">';
		printf( '<button type="submit">%s</button>', $state['enabled'] ? esc_html__( 'Apply filters', 'wp-logbook' ) : esc_html__( 'Show', 'wp-logbook' ) );
		if ( $state['values'] ) {
			printf( ' <a class="wplb-filters-clear" href="%s">%s</a>', esc_url( remove_query_arg( $state['params'] ) ), esc_html__( 'Clear filters', 'wp-logbook' ) );
		}
		echo '</div></details></form>';
	}

	/**
	 * Entries of a select filter.
	 *
	 * @param string     $key     Filter key.
	 * @param array      $filter  Filter definition.
	 * @param array      $base    Fixed query arguments.
	 * @param array|null $options Bands and modes from WPLB_QSOs::filter_options().
	 * @return array Value => label.
	 */
	private static function choices( $key, array $filter, array $base, $options ) {
		switch ( $key ) {
			case 'band':
				return array_combine( $options['bands'], $options['bands'] );
			case 'mode':
				return $options['modes'];
			case 'confirmed':
				return array(
					'yes' => __( 'Confirmed', 'wp-logbook' ),
					'no'  => __( 'Not confirmed', 'wp-logbook' ),
				);
		}

		$labels  = 'continent' === $key ? WPLB_Fields::continents() : array();
		$choices = array();
		foreach ( WPLB_QSOs::distinct_values( $filter['column'], $base ) as $value ) {
			$choices[ $value ] = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		}
		return $choices;
	}

	/**
	 * "Showing 1–25 of 132 contacts".
	 *
	 * @param int  $total    Matching QSOs.
	 * @param int  $page     Current page.
	 * @param int  $size     Page size.
	 * @param bool $paginate Whether pagination is on.
	 */
	private static function summary( $total, $page, $size, $paginate ) {
		$first = ( $page - 1 ) * $size + 1;
		$last  = min( $total, $page * $size );

		if ( $total <= $size && 1 === $page ) {
			/* translators: %s: number of contacts */
			$text = sprintf( _n( '%s contact', '%s contacts', $total, 'wp-logbook' ), number_format_i18n( $total ) );
		} elseif ( $paginate ) {
			/* translators: 1: first contact shown, 2: last contact shown, 3: number of contacts */
			$text = sprintf( __( 'Showing %1$s–%2$s of %3$s contacts', 'wp-logbook' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) );
		} else {
			/* translators: 1: contacts shown, 2: number of contacts */
			$text = sprintf( __( 'Showing the latest %1$s of %2$s contacts', 'wp-logbook' ), number_format_i18n( $last ), number_format_i18n( $total ) );
		}
		echo '<p class="wplb-summary" role="status">' . esc_html( $text ) . '</p>';
	}

	/**
	 * QSOs as a table.
	 *
	 * @param array[]  $items   QSOs.
	 * @param string[] $columns Columns.
	 */
	private static function table( array $items, array $columns ) {
		$available = WPLB_Fields::public_columns();

		echo '<div class="wplb-table-wrap"><table class="wplb-table"><thead><tr>';
		foreach ( $columns as $column ) {
			printf( '<th scope="col" class="wplb-col-%s">%s</th>', esc_attr( $column ), esc_html( $available[ $column ] ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $qso ) {
			echo '<tr>';
			foreach ( $columns as $column ) {
				printf( '<td class="wplb-col-%s">%s</td>', esc_attr( $column ), esc_html( self::cell( $qso, $column ) ) );
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * QSOs as tiles: callsign, time and band/mode chips, then the other
	 * columns that have a value.
	 *
	 * @param array[]  $items   QSOs.
	 * @param string[] $columns Columns.
	 */
	private static function tiles( array $items, array $columns ) {
		$available = WPLB_Fields::public_columns();
		$chips     = array_intersect( array( 'band', 'mode', 'freq' ), $columns );
		$details   = array_diff( $columns, array( 'callsign', 'time_on', 'confirmed', 'band', 'mode', 'freq' ) );

		echo '<ul class="wplb-tiles" role="list">';
		foreach ( $items as $qso ) {
			echo '<li class="wplb-tile">';
			echo '<div class="wplb-tile-head">';
			printf( '<span class="wplb-tile-call">%s</span>', esc_html( $qso['callsign'] ) );
			if ( in_array( 'confirmed', $columns, true ) && '' !== self::cell( $qso, 'confirmed' ) ) {
				printf( '<span class="wplb-tile-confirmed">%s</span>', esc_html__( '✓ Confirmed', 'wp-logbook' ) );
			}
			echo '</div>';

			if ( in_array( 'time_on', $columns, true ) ) {
				$timestamp = strtotime( $qso['time_on'] . ' UTC' );
				printf(
					'<p class="wplb-tile-time"><time datetime="%s">%s</time></p>',
					esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) ),
					/* translators: %s: date and time */
					esc_html( sprintf( __( '%s UTC', 'wp-logbook' ), self::cell( $qso, 'time_on' ) ) )
				);
			}

			$chip_html = '';
			foreach ( $chips as $chip ) {
				$value = self::cell( $qso, $chip );
				if ( '' !== $value ) {
					/* translators: %s: frequency in MHz */
					$text       = 'freq' === $chip ? sprintf( __( '%s MHz', 'wp-logbook' ), $value ) : $value;
					$chip_html .= sprintf( '<span class="wplb-chip wplb-chip-%s">%s</span>', esc_attr( $chip ), esc_html( $text ) );
				}
			}
			if ( '' !== $chip_html ) {
				echo '<p class="wplb-chips">' . $chip_html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}

			$rows = '';
			foreach ( $details as $column ) {
				$value = self::cell( $qso, $column );
				if ( '' !== $value ) {
					$rows .= sprintf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $available[ $column ] ), esc_html( $value ) );
				}
			}
			if ( '' !== $rows ) {
				echo '<dl class="wplb-tile-fields">' . $rows . '</dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Text for one QSO value.
	 *
	 * @param array  $qso    QSO row.
	 * @param string $column Column key.
	 * @return string
	 */
	public static function cell( array $qso, $column ) {
		switch ( $column ) {
			case 'time_on':
				return substr( (string) $qso['time_on'], 0, 16 );
			case 'mode':
				return WPLB_Fields::display_mode( $qso['mode'], $qso['submode'] );
			case 'freq':
			case 'tx_pwr':
				return WPLB_Fields::format_number( $qso[ $column ] );
			case 'cont':
				$continents = WPLB_Fields::continents();
				return isset( $continents[ $qso['cont'] ] ) ? $continents[ $qso['cont'] ] : (string) $qso['cont'];
			case 'cnty':
			case 'pota_ref':
			case 'my_pota_ref':
				// "MA,Middlesex" and "US-0001,US-0002" read (and wrap) better with a space.
				return preg_replace( '/\s*,\s*/', ', ', (string) $qso[ $column ] );
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
	 * Page links: previous, numbers around the current page, next.
	 *
	 * @param int    $page  Current page.
	 * @param int    $pages Number of pages.
	 * @param string $param Page parameter name.
	 */
	private static function pagination( $page, $pages, $param ) {
		if ( $pages < 2 ) {
			return;
		}

		$url = function ( $number ) use ( $param ) {
			return 1 === $number ? remove_query_arg( $param ) : add_query_arg( $param, $number );
		};

		echo '<nav class="wplb-pagination" aria-label="' . esc_attr__( 'Logbook pages', 'wp-logbook' ) . '">';
		if ( $page > 1 ) {
			printf( '<a class="wplb-prev" rel="prev" href="%s">%s</a>', esc_url( $url( $page - 1 ) ), esc_html__( '« Newer', 'wp-logbook' ) );
		}

		$shown = array_unique( array_merge( array( 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) );
		sort( $shown );
		$previous = 0;
		foreach ( $shown as $number ) {
			if ( $number - $previous > 1 ) {
				echo '<span class="wplb-page-gap" aria-hidden="true">…</span>';
			}
			if ( $number === $page ) {
				printf( '<span class="wplb-page-current" aria-current="page">%s</span>', esc_html( number_format_i18n( $number ) ) );
			} else {
				printf(
					'<a class="wplb-page" href="%s" aria-label="%s">%s</a>',
					esc_url( $url( $number ) ),
					/* translators: %s: page number */
					esc_attr( sprintf( __( 'Page %s', 'wp-logbook' ), number_format_i18n( $number ) ) ),
					esc_html( number_format_i18n( $number ) )
				);
			}
			$previous = $number;
		}

		if ( $page < $pages ) {
			printf( '<a class="wplb-next" rel="next" href="%s">%s</a>', esc_url( $url( $page + 1 ) ), esc_html__( 'Older »', 'wp-logbook' ) );
		}
		echo '</nav>';
	}

	/**
	 * Whether an attribute means "yes".
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function is_yes( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'yes', 'true', '1', 'on' ), true );
	}
}
