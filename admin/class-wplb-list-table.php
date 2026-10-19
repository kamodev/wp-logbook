<?php
/**
 * Logbook list table.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Table of QSOs on the Logbook screen.
 */
class WPLB_List_Table extends WP_List_Table {

	/**
	 * Whether the QRZ.com columns and actions are shown.
	 *
	 * @var bool
	 */
	private $qrz;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->qrz = (bool) WPLB_Settings::get( 'qrz_enabled' );
		parent::__construct(
			array(
				'singular' => 'qso',
				'plural'   => 'qsos',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'         => '<input type="checkbox" />',
			'time_on'    => __( 'Date / time (UTC)', 'wp-logbook' ),
			'callsign'   => __( 'Callsign', 'wp-logbook' ),
			'band'       => __( 'Band', 'wp-logbook' ),
			'freq'       => __( 'MHz', 'wp-logbook' ),
			'mode'       => __( 'Mode', 'wp-logbook' ),
			'rst'        => __( 'RST sent / rcvd', 'wp-logbook' ),
			'name'       => __( 'Name', 'wp-logbook' ),
			'location'   => __( 'QTH / country', 'wp-logbook' ),
			'gridsquare' => __( 'Grid', 'wp-logbook' ),
			'qsl'        => __( 'QSL', 'wp-logbook' ),
		);
		if ( $this->qrz ) {
			$columns['qrz_status'] = __( 'QRZ.com', 'wp-logbook' );
		}
		return $columns;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'time_on'    => array( 'time_on', true ),
			'callsign'   => array( 'callsign', false ),
			'freq'       => array( 'freq', false ),
			'mode'       => array( 'mode', false ),
			'name'       => array( 'name', false ),
			'location'   => array( 'country', false ),
			'gridsquare' => array( 'gridsquare', false ),
			'qrz_status' => array( 'qrz_status', false ),
		);
	}

	/**
	 * Column that holds the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'callsign';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'export' => __( 'Export to ADIF', 'wp-logbook' ),
			'delete' => __( 'Delete', 'wp-logbook' ),
		);
		if ( $this->qrz ) {
			$actions['qrz_upload'] = __( 'Upload to QRZ.com now', 'wp-logbook' );
			$actions['qrz_queue']  = __( 'Queue for QRZ.com upload', 'wp-logbook' );
			$actions['qrz_skip']   = __( 'Do not upload to QRZ.com', 'wp-logbook' );
		}
		return $actions;
	}

	/**
	 * QRZ.com status views.
	 *
	 * @return array
	 */
	protected function get_views() {
		if ( ! $this->qrz ) {
			return array();
		}

		$counts  = WPLB_QSOs::count_by_qrz_status();
		$current = $this->current_status();
		$base    = admin_url( 'admin.php?page=wplb-logbook' );
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'wp-logbook' ),
				esc_html( number_format_i18n( array_sum( $counts ) ) )
			),
		);
		foreach ( WPLB_QSOs::qrz_statuses() as $status => $label ) {
			if ( ! $counts[ $status ] ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'qrz_status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}
		return $views;
	}

	/**
	 * Band and mode filters.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$options = WPLB_QSOs::filter_options();
		if ( ! $options['bands'] && ! $options['modes'] ) {
			return;
		}
		$band = $this->request_value( 'band' );
		$mode = $this->request_value( 'mode' );

		echo '<div class="alignleft actions">';
		printf( '<label class="screen-reader-text" for="wplb-filter-band">%s</label>', esc_html__( 'Filter by band', 'wp-logbook' ) );
		printf( '<select name="band" id="wplb-filter-band"><option value="">%s</option>', esc_html__( 'All bands', 'wp-logbook' ) );
		foreach ( $options['bands'] as $value ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $value ), selected( $band, $value, false ) );
		}
		echo '</select>';
		printf( '<label class="screen-reader-text" for="wplb-filter-mode">%s</label>', esc_html__( 'Filter by mode', 'wp-logbook' ) );
		printf( '<select name="mode" id="wplb-filter-mode"><option value="">%s</option>', esc_html__( 'All modes', 'wp-logbook' ) );
		foreach ( $options['modes'] as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $mode, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'wp-logbook' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Load the current page of QSOs.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'wplb_per_page', 50 );
		$result   = WPLB_QSOs::query(
			array(
				'search'     => $this->request_value( 's' ),
				'band'       => $this->request_value( 'band' ),
				'mode'       => $this->request_value( 'mode' ),
				'qrz_status' => $this->current_status(),
				'orderby'    => $this->request_value( 'orderby' ),
				'order'      => $this->request_value( 'order' ),
				'per_page'   => $per_page,
				'page'       => $this->get_pagenum(),
			)
		);

		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message for an empty table.
	 */
	public function no_items() {
		esc_html_e( 'No QSOs found.', 'wp-logbook' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item QSO.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="wplb-cb-%1$d">%2$s</label><input type="checkbox" id="wplb-cb-%1$d" name="qso[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %s: callsign */
			esc_html( sprintf( __( 'Select QSO with %s', 'wp-logbook' ), $item['callsign'] ) )
		);
	}

	/**
	 * Callsign column with row actions.
	 *
	 * @param array $item QSO.
	 * @return string
	 */
	protected function column_callsign( $item ) {
		$id      = (int) $item['id'];
		$edit    = admin_url( 'admin.php?page=wplb-qso&id=' . $id );
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'wp-logbook' ) ),
			'delete' => sprintf(
				'<a href="%s" class="wplb-delete-link">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wplb_delete_qso&id=' . $id ), 'wplb_delete_qso_' . $id ) ),
				esc_html__( 'Delete', 'wp-logbook' )
			),
		);
		if ( $this->qrz && 'skip' !== $item['qrz_status'] ) {
			$actions['qrz'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wplb_qrz_upload_one&id=' . $id ), 'wplb_qrz_upload_' . $id ) ),
				'uploaded' === $item['qrz_status'] ? esc_html__( 'Re-upload', 'wp-logbook' ) : esc_html__( 'Upload', 'wp-logbook' )
			);
		}

		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $item['callsign'] ) ) . $this->row_actions( $actions );
	}

	/**
	 * Other columns.
	 *
	 * @param array  $item        QSO.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'time_on':
				return esc_html( substr( $item['time_on'], 0, 16 ) );

			case 'freq':
				return esc_html( WPLB_Fields::format_number( $item['freq'] ) );

			case 'mode':
				return esc_html( WPLB_Fields::display_mode( $item['mode'], $item['submode'] ) );

			case 'rst':
				if ( '' === $item['rst_sent'] && '' === $item['rst_rcvd'] ) {
					return '';
				}
				return esc_html( $item['rst_sent'] . ' / ' . $item['rst_rcvd'] );

			case 'location':
				return esc_html( implode( ', ', array_filter( array( $item['qth'], $item['state'], $item['country'] ) ) ) );

			case 'qsl':
				return $this->qsl_summary( $item );

			case 'qrz_status':
				return WPLB_Admin::qrz_badge( $item );

			default:
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
		}
	}

	/**
	 * Compact QSL state: P (paper), L (LoTW), E (eQSL); bold when confirmed.
	 *
	 * @param array $item QSO.
	 * @return string HTML.
	 */
	private function qsl_summary( array $item ) {
		$services = array(
			'P' => array( 'qsl', __( 'QSL card', 'wp-logbook' ) ),
			'L' => array( 'lotw_qsl', __( 'LoTW', 'wp-logbook' ) ),
			'E' => array( 'eqsl_qsl', __( 'eQSL', 'wp-logbook' ) ),
		);
		$out      = '';
		foreach ( $services as $letter => $service ) {
			$received = 'Y' === $item[ $service[0] . '_rcvd' ];
			$sent     = 'Y' === $item[ $service[0] . '_sent' ];
			if ( ! $received && ! $sent ) {
				continue;
			}
			$state = $received ? __( 'confirmed', 'wp-logbook' ) : __( 'sent', 'wp-logbook' );
			$out  .= sprintf(
				'<span class="wplb-qsl wplb-qsl-%s" title="%s">%s</span>',
				$received ? 'rcvd' : 'sent',
				esc_attr( $service[1] . ': ' . $state ),
				esc_html( $letter )
			);
		}
		return $out;
	}

	/**
	 * QRZ.com status view being shown.
	 *
	 * @return string
	 */
	private function current_status() {
		$status = $this->request_value( 'qrz_status' );
		return array_key_exists( $status, WPLB_QSOs::qrz_statuses() ) ? $status : '';
	}

	/**
	 * A sanitised request value.
	 *
	 * @param string $key Query argument.
	 * @return string
	 */
	private function request_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filters only.
		return isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) ) : '';
	}
}
