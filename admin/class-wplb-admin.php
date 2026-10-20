<?php
/**
 * Admin screens.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menus, the logbook list, import/export, settings and AJAX endpoints.
 * The QSO entry form lives in WPLB_QSO_Form.
 */
class WPLB_Admin {

	/**
	 * Page hook suffixes keyed by screen.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * List table of the logbook screen.
	 *
	 * @var WPLB_List_Table|null
	 */
	private static $table = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( 'WPLB_QRZ_Sync', 'sync_schedule' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'admin_post_wplb_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_wplb_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wplb_delete_qso', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wplb_qrz_upload_one', array( __CLASS__, 'handle_upload_one' ) );
		add_action( 'admin_post_wplb_qrz_retry_failed', array( __CLASS__, 'handle_retry_failed' ) );

		add_action( 'wp_ajax_wplb_qrz_test', array( __CLASS__, 'ajax_qrz_test' ) );
		add_action( 'wp_ajax_wplb_qrz_upload', array( __CLASS__, 'ajax_qrz_upload' ) );
		add_action( 'wp_ajax_wplb_lookup', array( __CLASS__, 'ajax_lookup' ) );

		add_filter( 'set_screen_option_wplb_per_page', array( __CLASS__, 'save_screen_option' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( WPLB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$cap = WPLB_Plugin::capability();

		self::$hooks['list'] = add_menu_page( __( 'Logbook', 'wp-logbook' ), __( 'Logbook', 'wp-logbook' ), $cap, 'wplb-logbook', array( __CLASS__, 'render_list' ), 'dashicons-microphone', 26 );
		add_submenu_page( 'wplb-logbook', __( 'Logbook', 'wp-logbook' ), __( 'All QSOs', 'wp-logbook' ), $cap, 'wplb-logbook', array( __CLASS__, 'render_list' ) );

		self::$hooks['edit']     = add_submenu_page( 'wplb-logbook', __( 'Log a QSO', 'wp-logbook' ), __( 'Log a QSO', 'wp-logbook' ), $cap, 'wplb-qso', array( 'WPLB_QSO_Form', 'render' ) );
		self::$hooks['logbooks'] = add_submenu_page( 'wplb-logbook', __( 'Logbooks', 'wp-logbook' ), __( 'Logbooks', 'wp-logbook' ), $cap, 'wplb-logbooks', array( 'WPLB_Logbooks_Screen', 'render' ) );
		self::$hooks['tools']    = add_submenu_page( 'wplb-logbook', __( 'Import / Export', 'wp-logbook' ), __( 'Import / Export', 'wp-logbook' ), $cap, 'wplb-tools', array( __CLASS__, 'render_tools' ) );
		self::$hooks['settings'] = add_submenu_page( 'wplb-logbook', __( 'Logbook Settings', 'wp-logbook' ), __( 'Settings', 'wp-logbook' ), 'manage_options', 'wplb-settings', array( __CLASS__, 'render_settings' ) );

		add_action( 'load-' . self::$hooks['list'], array( __CLASS__, 'load_list' ) );
		add_action( 'load-' . self::$hooks['edit'], array( 'WPLB_QSO_Form', 'load' ) );
		add_action( 'load-' . self::$hooks['logbooks'], array( 'WPLB_Logbooks_Screen', 'load' ) );
	}

	/**
	 * Register the settings with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'wplb_settings',
			WPLB_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WPLB_Settings', 'sanitize' ),
				'default'           => WPLB_Settings::defaults(),
			)
		);
		register_setting(
			'wplb_settings',
			WPLB_Settings::API_KEY_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'WPLB_Settings', 'sanitize_api_key' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Scripts and styles for the plugin screens.
	 *
	 * @param string $hook Current page hook.
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'wplb-admin', WPLB_URL . 'assets/css/admin.css', array(), WPLB_VERSION );
		wp_enqueue_script( 'wplb-admin', WPLB_URL . 'assets/js/admin.js', array(), WPLB_VERSION, true );

		$bands = array();
		foreach ( WPLB_Fields::bands() as $band => $limits ) {
			$bands[] = array( $band, $limits[0], $limits[1] );
		}

		$data = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonces'    => array(
				'upload' => wp_create_nonce( 'wplb_qrz_upload' ),
				'test'   => wp_create_nonce( 'wplb_qrz_test' ),
				'lookup' => wp_create_nonce( 'wplb_lookup' ),
			),
			'batchSize' => self::batch_size(),
			'bands'     => $bands,
			'i18n'      => array(
				'confirmDelete'     => __( 'Delete the selected QSOs? This cannot be undone.', 'wp-logbook' ),
				'confirmDeleteOne'  => __( 'Delete this QSO? This cannot be undone.', 'wp-logbook' ),
				'selectFirst'       => __( 'Select at least one QSO first.', 'wp-logbook' ),
				/* translators: 1: QSOs uploaded so far, 2: total QSOs */
				'progress'          => __( 'Uploading to QRZ.com… %1$s of %2$s', 'wp-logbook' ),
				/* translators: 1: number uploaded, 2: number failed */
				'done'              => __( 'Finished: %1$s uploaded, %2$s failed.', 'wp-logbook' ),
				'stopped'           => __( 'Upload stopped:', 'wp-logbook' ),
				'requestFailed'     => __( 'The request failed. Check your connection and try again.', 'wp-logbook' ),
				'reload'            => __( 'Refresh list', 'wp-logbook' ),
				'testing'           => __( 'Contacting QRZ.com…', 'wp-logbook' ),
				'previous'          => __( 'Previous contacts', 'wp-logbook' ),
				/* translators: %s: number of earlier contacts */
				'previousCount'     => __( 'Worked %s times before.', 'wp-logbook' ),
				'firstContact'      => __( 'First contact with this station.', 'wp-logbook' ),
				'filledFromLastQso' => __( 'Details filled in from your last contact.', 'wp-logbook' ),
			),
		);
		wp_add_inline_script( 'wplb-admin', 'window.wplbAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=wplb-settings' ) ), esc_html__( 'Settings', 'wp-logbook' ) ) );
		return $links;
	}

	/**
	 * Store the per-page screen option.
	 *
	 * @param mixed  $status Value to save (false to skip).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return 'wplb_per_page' === $option ? min( 500, max( 1, (int) $value ) ) : $status;
	}

	/**
	 * Queue a notice for the next plugin screen the user sees.
	 *
	 * @param string $message HTML message; escape dynamic parts before passing them.
	 * @param string $type    success, info, warning or error.
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$key       = 'wplb_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear queued notices.
	 */
	public static function render_notices() {
		$key     = 'wplb_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), wp_kses_post( $notice['message'] ) );
		}
	}

	/**
	 * Messages as an HTML list for a notice.
	 *
	 * @param string[] $messages Plain-text messages.
	 * @return string
	 */
	public static function html_list( array $messages ) {
		return $messages ? '</p><ul class="wplb-notice-list"><li>' . implode( '</li><li>', array_map( 'esc_html', $messages ) ) . '</li></ul><p>' : '';
	}

	/**
	 * Badge describing a QSO's QRZ.com state.
	 *
	 * @param array $qso QSO row.
	 * @return string HTML.
	 */
	public static function qrz_badge( array $qso ) {
		$labels = WPLB_QSOs::qrz_statuses();
		$status = isset( $labels[ $qso['qrz_status'] ] ) ? $qso['qrz_status'] : 'pending';
		$title  = array();
		if ( ! empty( $qso['qrz_logid'] ) ) {
			/* translators: %s: QRZ.com log ID */
			$title[] = sprintf( __( 'Log ID %s', 'wp-logbook' ), $qso['qrz_logid'] );
		}
		if ( ! empty( $qso['qrz_upload_date'] ) ) {
			/* translators: %s: date and time */
			$title[] = sprintf( __( 'Uploaded %s UTC', 'wp-logbook' ), substr( $qso['qrz_upload_date'], 0, 16 ) );
		}
		if ( ! empty( $qso['qrz_message'] ) ) {
			$title[] = $qso['qrz_message'];
		}
		return sprintf(
			'<span class="wplb-badge wplb-badge-%s" title="%s">%s</span>',
			esc_attr( $status ),
			esc_attr( implode( ' — ', $title ) ),
			esc_html( $labels[ $status ] )
		);
	}

	/**
	 * Load handler of the logbook screen: screen options and bulk actions.
	 */
	public static function load_list() {
		require_once WPLB_DIR . 'admin/class-wplb-list-table.php';

		add_screen_option(
			'per_page',
			array(
				'default' => 50,
				'option'  => 'wplb_per_page',
			)
		);

		self::$table = new WPLB_List_Table();
		self::handle_bulk_action();

		// Searching and filtering submit the bulk-action nonce too; keep it out of the URL as core list screens do.
		if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Process a bulk action from the logbook screen.
	 */
	private static function handle_bulk_action() {
		$action = self::$table->current_action();
		if ( ! $action ) {
			return;
		}
		check_admin_referer( 'bulk-qsos' );

		$ids      = isset( $_REQUEST['qso'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['qso'] ) ) ) ) : array();
		$redirect = remove_query_arg( array( 'action', 'action2', 'qso', '_wpnonce', '_wp_http_referer' ) );

		if ( ! $ids ) {
			self::add_notice( esc_html__( 'Select at least one QSO first.', 'wp-logbook' ), 'warning' );
			wp_safe_redirect( $redirect );
			exit;
		}

		// "move-<logbook ID>": move the selection to another logbook.
		if ( 0 === strpos( $action, 'move-' ) ) {
			$logbook = (int) substr( $action, 5 );
			$count   = WPLB_Logbooks::move_qsos( $ids, $logbook );
			self::add_notice(
				esc_html(
					sprintf(
						/* translators: 1: number of QSOs, 2: logbook title */
						_n( '%1$s QSO moved to %2$s.', '%1$s QSOs moved to %2$s.', $count, 'wp-logbook' ),
						number_format_i18n( $count ),
						WPLB_Logbooks::title( $logbook )
					)
				)
			);
			self::add_remote_delete_notice();
			wp_safe_redirect( $redirect );
			exit;
		}

		switch ( $action ) {
			case 'export':
				self::send_export(
					array(
						'ids'   => $ids,
						'order' => 'ASC',
					),
					'adif'
				);
				break;

			case 'delete':
				$count = WPLB_QSOs::delete( $ids );
				/* translators: %s: number of QSOs */
				self::add_notice( esc_html( sprintf( _n( '%s QSO deleted.', '%s QSOs deleted.', $count, 'wp-logbook' ), number_format_i18n( $count ) ) ) );
				self::add_remote_delete_notice();
				break;

			case 'qrz_upload':
				// The page script uploads in small batches; this is the no-JavaScript fallback.
				self::add_upload_notice( WPLB_QRZ_Sync::is_enabled() ? WPLB_QRZ_Sync::upload_many( array_slice( $ids, 0, 20 ) ) : null );
				break;

			case 'qrz_queue':
				$count = WPLB_QSOs::queue_for_upload( $ids );
				/* translators: %s: number of QSOs */
				self::add_notice( esc_html( sprintf( _n( '%s QSO queued for upload to QRZ.com.', '%s QSOs queued for upload to QRZ.com.', $count, 'wp-logbook' ), number_format_i18n( $count ) ) ) );
				break;

			case 'qrz_skip':
				$count = WPLB_QSOs::set_qrz_status( $ids, 'skip' );
				/* translators: %s: number of QSOs */
				self::add_notice( esc_html( sprintf( _n( '%s QSO will not be uploaded to QRZ.com.', '%s QSOs will not be uploaded to QRZ.com.', $count, 'wp-logbook' ), number_format_i18n( $count ) ) ) );
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Logbook screen.
	 */
	public static function render_list() {
		$table = self::$table;
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view filter only.
		$logbook = isset( $_GET['logbook'] ) ? WPLB_Logbooks::get( absint( $_GET['logbook'] ) ) : null;
		$heading = $logbook ? $logbook['title'] : __( 'Logbook', 'wp-logbook' );
		$log_url = admin_url( 'admin.php?page=wplb-qso' . ( $logbook ? '&logbook=' . (int) $logbook['id'] : '' ) );

		echo '<div class="wrap wplb-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $heading ) . '</h1> ';
		printf( '<a href="%s" class="page-title-action">%s</a>', esc_url( $log_url ), esc_html__( 'Log a QSO', 'wp-logbook' ) );
		echo '<hr class="wp-header-end">';

		self::render_notices();
		self::render_qrz_panel();

		$table->views();
		echo '<form method="get" id="wplb-list-form">';
		echo '<input type="hidden" name="page" value="wplb-logbook">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view filter only.
		$status = isset( $_GET['qrz_status'] ) ? sanitize_key( wp_unslash( $_GET['qrz_status'] ) ) : '';
		if ( $status ) {
			printf( '<input type="hidden" name="qrz_status" value="%s">', esc_attr( $status ) );
		}
		$table->search_box( __( 'Search QSOs', 'wp-logbook' ), 'wplb-search' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * QRZ.com upload panel above the logbook.
	 */
	private static function render_qrz_panel() {
		if ( ! WPLB_Settings::get( 'qrz_enabled' ) ) {
			return;
		}
		if ( ! WPLB_QRZ_Sync::is_enabled() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'QRZ.com upload is switched on but no logbook can upload: add an API key in the settings, or to a logbook.', 'wp-logbook' ),
				esc_url( admin_url( 'admin.php?page=wplb-settings' ) ),
				esc_html__( 'Open the settings.', 'wp-logbook' )
			);
			return;
		}

		// Logbooks that do not upload to QRZ.com are left out of these counts.
		$counts  = WPLB_QSOs::count_by_qrz_status( WPLB_Logbooks::qrz_logbook_ids() );
		$waiting = $counts['pending'] + $counts['modified'];

		echo '<div class="wplb-qrz-panel" id="wplb-qrz-panel">';
		echo '<p><strong>' . esc_html__( 'QRZ.com Logbook:', 'wp-logbook' ) . '</strong> ';
		if ( $waiting ) {
			/* translators: %s: number of QSOs */
			echo esc_html( sprintf( _n( '%s QSO is waiting to be uploaded.', '%s QSOs are waiting to be uploaded.', $waiting, 'wp-logbook' ), number_format_i18n( $waiting ) ) ) . ' ';
			printf(
				'<button type="button" class="button button-primary" id="wplb-qrz-upload-pending" data-total="%d">%s</button> ',
				(int) $waiting,
				esc_html__( 'Upload now', 'wp-logbook' )
			);
		} else {
			echo esc_html__( 'Everything is uploaded.', 'wp-logbook' ) . ' ';
		}
		if ( $counts['failed'] ) {
			printf(
				'<a href="%s">%s</a> · <a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wplb-logbook&qrz_status=failed' ) ),
				/* translators: %s: number of QSOs */
				esc_html( sprintf( _n( '%s failed upload', '%s failed uploads', $counts['failed'], 'wp-logbook' ), number_format_i18n( $counts['failed'] ) ) ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wplb_qrz_retry_failed' ), 'wplb_qrz_retry_failed' ) ),
				esc_html__( 'Retry them', 'wp-logbook' )
			);
		}
		echo '</p>';
		echo '<div class="wplb-qrz-progress" hidden><progress max="100" value="0"></progress> <span class="wplb-qrz-progress-text" role="status"></span></div>';
		echo '<ul class="wplb-qrz-errors"></ul>';
		echo '</div>';
	}

	/**
	 * Import / Export screen.
	 */
	public static function render_tools() {
		$bands    = array_keys( WPLB_Fields::bands() );
		$max_size = size_format( wp_max_upload_size() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preselects a logbook only.
		$current = isset( $_GET['logbook'] ) && WPLB_Logbooks::get( absint( $_GET['logbook'] ) ) ? absint( $_GET['logbook'] ) : 0;
		$counts  = WPLB_Logbooks::counts();

		echo '<div class="wrap wplb-wrap">';
		echo '<h1>' . esc_html__( 'Import / Export', 'wp-logbook' ) . '</h1>';
		self::render_notices();
		echo '<div class="wplb-cards">';

		// Export.
		echo '<div class="card"><h2>' . esc_html__( 'Export', 'wp-logbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Download your log as ADIF for other logging programs and QSL services, or as CSV for spreadsheets. Leave the filters empty to export everything.', 'wp-logbook' ) . '</p>';
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'wplb_export' );
		echo '<input type="hidden" name="action" value="wplb_export">';
		printf( '<p><label for="wplb-export-logbook">%s</label><br><select id="wplb-export-logbook" name="logbook"><option value="">%s</option>', esc_html__( 'Logbook', 'wp-logbook' ), esc_html__( 'All logbooks', 'wp-logbook' ) );
		foreach ( WPLB_Logbooks::choices() as $id => $title ) {
			/* translators: 1: logbook title, 2: number of QSOs */
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $current, $id, false ), esc_html( sprintf( __( '%1$s (%2$s QSOs)', 'wp-logbook' ), $title, number_format_i18n( $counts[ $id ] ) ) ) );
		}
		echo '</select></p>';
		echo '<p class="wplb-inline-fields">';
		printf( '<label>%s <input type="date" name="date_from"></label> ', esc_html__( 'From', 'wp-logbook' ) );
		printf( '<label>%s <input type="date" name="date_to"></label> ', esc_html__( 'To', 'wp-logbook' ) );
		printf( '<label>%s <select name="band"><option value="">%s</option>', esc_html__( 'Band', 'wp-logbook' ), esc_html__( 'All', 'wp-logbook' ) );
		foreach ( $bands as $band ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $band ) );
		}
		echo '</select></label></p>';
		echo '<p class="wplb-inline-fields">';
		printf( '<label><input type="radio" name="format" value="adif" checked> %s</label> ', esc_html__( 'ADIF (.adi)', 'wp-logbook' ) );
		printf( '<label><input type="radio" name="format" value="csv"> %s</label>', esc_html__( 'CSV', 'wp-logbook' ) );
		echo '</p>';
		submit_button( __( 'Download', 'wp-logbook' ), 'primary', 'submit', false );
		echo '</form></div>';

		// Import.
		echo '<div class="card"><h2>' . esc_html__( 'Import ADIF', 'wp-logbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add QSOs from an ADIF (.adi) file exported by another logging program, LoTW or QRZ.com.', 'wp-logbook' ) . '</p>';
		printf( '<form method="post" enctype="multipart/form-data" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'wplb_import' );
		echo '<input type="hidden" name="action" value="wplb_import">';
		printf(
			'<p><label for="wplb-adif-file">%s</label><br><input type="file" id="wplb-adif-file" name="adif_file" accept=".adi,.adif,.txt" required> <span class="description">%s</span></p>',
			esc_html__( 'ADIF file', 'wp-logbook' ),
			/* translators: %s: maximum upload size */
			esc_html( sprintf( __( 'Maximum size: %s', 'wp-logbook' ), $max_size ) )
		);
		printf( '<p><label for="wplb-import-logbook">%s</label><br><select id="wplb-import-logbook" name="logbook">', esc_html__( 'Import into', 'wp-logbook' ) );
		$target = $current ? $current : WPLB_Logbooks::default_id();
		foreach ( WPLB_Logbooks::choices() as $id => $title ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $target, $id, false ), esc_html( $title ) );
		}
		printf( '<option value="new">%s</option></select></p>', esc_html__( 'A new logbook…', 'wp-logbook' ) );
		printf(
			'<p class="wplb-new-logbook" hidden><label for="wplb-import-new">%s</label><br><input type="text" id="wplb-import-new" name="new_logbook" class="regular-text" maxlength="100"></p>',
			esc_html__( 'Title of the new logbook', 'wp-logbook' )
		);
		printf( '<p><label><input type="checkbox" name="file_logbooks" value="1" checked> %s</label></p>', esc_html__( 'QSOs exported from WP Logbook go back into the logbook named in the file (missing logbooks are created)', 'wp-logbook' ) );
		printf( '<p><label><input type="checkbox" name="skip_duplicates" value="1" checked> %s</label></p>', esc_html__( 'Skip QSOs that are already in the logbook', 'wp-logbook' ) );
		if ( WPLB_Settings::get( 'qrz_enabled' ) ) {
			printf( '<p><label><input type="checkbox" name="mark_uploaded" value="1"> %s</label></p>', esc_html__( 'These QSOs are already in my QRZ.com Logbook (do not upload them again)', 'wp-logbook' ) );
		}
		printf(
			'<p><label for="wplb-import-station">%s</label><br><input type="text" id="wplb-import-station" name="station_callsign" class="regular-text wplb-uppercase" value="" maxlength="32"><br><span class="description">%s</span></p>',
			esc_html__( 'Station callsign for QSOs that do not have one', 'wp-logbook' ),
			esc_html__( 'Leave empty to use the station callsign of the logbook (or of the settings).', 'wp-logbook' )
		);
		submit_button( __( 'Import', 'wp-logbook' ), 'primary', 'submit', false );
		echo '</form></div>';

		echo '</div></div>';
	}

	/**
	 * Settings screen.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = WPLB_Settings::all();
		$option   = WPLB_Settings::OPTION;
		$fields   = WPLB_Fields::all();
		$api_key  = WPLB_Settings::api_key();

		echo '<div class="wrap wplb-wrap">';
		echo '<h1>' . esc_html__( 'Logbook Settings', 'wp-logbook' ) . '</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'wplb_settings' );

		// Station.
		echo '<h2>' . esc_html__( 'My station', 'wp-logbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'These details pre-fill every new QSO. The station callsign must match your QRZ.com logbook if you upload there.', 'wp-logbook' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ( WPLB_Settings::STATION_FIELDS as $key ) {
			$upper = in_array( $fields[ $key ]['type'], array( 'callsign', 'grid', 'upper' ), true );
			printf(
				'<tr><th scope="row"><label for="wplb-setting-%1$s">%2$s</label></th><td><input type="text" id="wplb-setting-%1$s" name="%3$s[%1$s]" value="%4$s" class="regular-text%5$s"></td></tr>',
				esc_attr( $key ),
				esc_html( $fields[ $key ]['label'] ),
				esc_attr( $option ),
				esc_attr( $settings[ $key ] ),
				$upper ? ' wplb-uppercase' : ''
			);
		}
		echo '</table>';

		// Public logbook.
		echo '<h2>' . esc_html__( 'Public logbook', 'wp-logbook' ) . '</h2>';
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: 1: table shortcode, 2: tiles shortcode, 3: statistics shortcode */
				__( 'Show your log on any page or post as a table with %1$s or as tiles with %2$s, and totals per band and mode with %3$s. These settings are the defaults; shortcode attributes choose the filters, columns and page size per page (see the plugin README).', 'wp-logbook' ),
				'<code>[wp_logbook]</code>',
				'<code>[wp_logbook_tiles]</code>',
				'<code>[wp_logbook_stats]</code>'
			)
		) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Columns', 'wp-logbook' ) . '</th><td><fieldset class="wplb-columns">';
		foreach ( WPLB_Fields::public_columns() as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[public_columns][]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( $option ),
				esc_attr( $key ),
				checked( in_array( $key, $settings['public_columns'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Private notes, email addresses and QSL details are never shown publicly.', 'wp-logbook' ) . '</p></td></tr>';
		printf(
			'<tr><th scope="row"><label for="wplb-per-page">%1$s</label></th><td><input type="number" id="wplb-per-page" name="%2$s[public_per_page]" value="%3$d" min="1" max="500" class="small-text"></td></tr>',
			esc_html__( 'QSOs per page', 'wp-logbook' ),
			esc_attr( $option ),
			(int) $settings['public_per_page']
		);
		self::checkbox_row( __( 'Callsign search', 'wp-logbook' ), 'public_search', __( 'Include the callsign filter by default, so visitors can check whether they are in the log', 'wp-logbook' ), $settings );
		echo '</table>';

		// QRZ.com.
		echo '<h2>' . esc_html__( 'QRZ.com Logbook (optional)', 'wp-logbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload your QSOs to your QRZ.com Logbook through the QRZ.com Logbook API. Your log works the same with this switched off.', 'wp-logbook' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Find the API key on QRZ.com under Logbook → Settings for the logbook you want to upload to. QRZ.com may require a paid subscription for API uploads.', 'wp-logbook' ) . '</p>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: %s: link to the Logbooks screen */
				__( 'The key below is the default. A logbook that uploads to a different QRZ.com logbook (another callsign) can have its own key, and upload can be switched off per logbook, under %s.', 'wp-logbook' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wplb-logbooks' ) ) . '">' . esc_html__( 'Logbook → Logbooks', 'wp-logbook' ) . '</a>'
			)
		) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::checkbox_row( __( 'QRZ.com upload', 'wp-logbook' ), 'qrz_enabled', __( 'Upload QSOs to my QRZ.com Logbook', 'wp-logbook' ), $settings );

		echo '<tr><th scope="row"><label for="wplb-qrz-key">' . esc_html__( 'Default API key', 'wp-logbook' ) . '</label></th><td>';
		if ( WPLB_Settings::api_key_is_constant() ) {
			/* translators: %s: masked API key */
			echo '<p>' . esc_html( sprintf( __( 'Set by WPLB_QRZ_API_KEY in wp-config.php (%s).', 'wp-logbook' ), WPLB_Settings::mask( $api_key ) ) ) . '</p>';
		} else {
			printf(
				'<input type="password" id="wplb-qrz-key" name="%1$s" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="%2$s">',
				esc_attr( WPLB_Settings::API_KEY_OPTION ),
				esc_attr( '' !== $api_key ? WPLB_Settings::mask( $api_key ) : 'XXXX-XXXX-XXXX-XXXX' )
			);
			if ( '' !== $api_key ) {
				echo '<p class="description">' . esc_html__( 'A key is saved. Leave the field empty to keep it.', 'wp-logbook' ) . '</p>';
				printf( '<p><label><input type="checkbox" name="wplb_qrz_api_key_remove" value="1"> %s</label></p>', esc_html__( 'Remove the saved key', 'wp-logbook' ) );
			}
		}
		printf(
			'<p><button type="button" class="button" id="wplb-qrz-test">%s</button></p><div id="wplb-qrz-test-result" class="wplb-test-result" role="status"></div>',
			esc_html__( 'Test connection', 'wp-logbook' )
		);
		echo '</td></tr>';

		self::checkbox_row( __( 'Upload on save', 'wp-logbook' ), 'qrz_auto_upload', __( 'Upload each QSO as soon as it is logged or edited', 'wp-logbook' ), $settings );
		self::checkbox_row( __( 'Background upload', 'wp-logbook' ), 'qrz_background_sync', __( 'Every hour, upload QSOs that are still waiting (uses WP-Cron)', 'wp-logbook' ), $settings );
		self::checkbox_row( __( 'Edits', 'wp-logbook' ), 'qrz_sync_edits', __( 'When an uploaded QSO is edited, replace the copy on QRZ.com', 'wp-logbook' ), $settings );
		self::checkbox_row( __( 'Deletions', 'wp-logbook' ), 'qrz_delete_remote', __( 'When a QSO is deleted here, delete it from QRZ.com too', 'wp-logbook' ), $settings );
		echo '</table>';

		// Data.
		echo '<h2>' . esc_html__( 'Data', 'wp-logbook' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::checkbox_row( __( 'Uninstall', 'wp-logbook' ), 'delete_on_uninstall', __( 'Delete all QSOs and settings when the plugin is deleted', 'wp-logbook' ), $settings );
		echo '</table>';

		submit_button();
		echo '</form></div>';
	}

	/**
	 * A settings row with one checkbox.
	 *
	 * @param string $title    Row heading.
	 * @param string $key      Setting name.
	 * @param string $label    Checkbox label.
	 * @param array  $settings Current settings.
	 */
	private static function checkbox_row( $title, $key, $label, array $settings ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1"%4$s> %5$s</label></td></tr>',
			esc_html( $title ),
			esc_attr( WPLB_Settings::OPTION ),
			esc_attr( $key ),
			checked( ! empty( $settings[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Export download (admin-post).
	 */
	public static function handle_export() {
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to export the logbook.', 'wp-logbook' ), 403 );
		}
		check_admin_referer( 'wplb_export' );

		// The Logbooks screen links here (GET); the export form posts.
		$format  = isset( $_REQUEST['format'] ) && 'csv' === $_REQUEST['format'] ? 'csv' : 'adif';
		$logbook = isset( $_REQUEST['logbook'] ) ? WPLB_Logbooks::get( absint( $_REQUEST['logbook'] ) ) : null;
		$args    = array(
			'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
			'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
			'band'      => isset( $_REQUEST['band'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['band'] ) ) : '',
			'order'     => 'ASC',
		);
		if ( $logbook ) {
			$args['logbook'] = (int) $logbook['id'];
		}
		self::send_export( $args, $format, $logbook ? $logbook['slug'] : 'logbook' );
	}

	/**
	 * Stream QSOs as an ADIF or CSV download and exit.
	 *
	 * @param array  $args   WPLB_QSOs::query() filters.
	 * @param string $format adif or csv.
	 * @param string $name   Start of the file name.
	 */
	private static function send_export( array $args, $format, $name = 'logbook' ) {
		$filename = sanitize_file_name( $name . '-' . gmdate( 'Ymd-His' ) . ( 'csv' === $format ? '.csv' : '.adi' ) );

		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv; charset=utf-8' : 'text/plain; charset=us-ascii' ) );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out     = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$columns = array_merge( array( 'logbook' ), array_keys( WPLB_Fields::all() ), array( 'qrz_status', 'qrz_logid', 'qrz_upload_date' ) );

		if ( 'csv' === $format ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheet programs.
			fputcsv( $out, $columns, ',', '"', '\\' );
		} else {
			fwrite( $out, WPLB_ADIF::header() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		$args = array_merge(
			$args,
			array(
				'orderby'  => 'time_on',
				'per_page' => 500,
				'count'    => false,
			)
		);
		for ( $page = 1; ; $page++ ) {
			$args['page'] = $page;
			$result       = WPLB_QSOs::query( $args );
			foreach ( $result['items'] as $qso ) {
				if ( 'csv' === $format ) {
					$qso['logbook'] = WPLB_Logbooks::title( $qso['logbook_id'] );
					$row            = array();
					foreach ( $columns as $column ) {
						$row[] = self::csv_cell( isset( $qso[ $column ] ) ? $qso[ $column ] : '' );
					}
					fputcsv( $out, $row, ',', '"', '\\' );
				} else {
					fwrite( $out, WPLB_ADIF::record( $qso ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				}
			}
			if ( count( $result['items'] ) < $args['per_page'] ) {
				break;
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralise spreadsheet formulas in a CSV cell.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * ADIF import (admin-post).
	 */
	public static function handle_import() {
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import QSOs.', 'wp-logbook' ), 403 );
		}
		check_admin_referer( 'wplb_import' );

		$redirect = admin_url( 'admin.php?page=wplb-tools' );
		$file     = isset( $_FILES['adif_file'] ) ? $_FILES['adif_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tmp_name and error are used.

		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::add_notice( esc_html__( 'The file could not be uploaded. Check that it is not larger than the maximum upload size.', 'wp-logbook' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$text   = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$parsed = WPLB_ADIF::parse( $text );
		if ( ! $parsed['records'] ) {
			self::add_notice( esc_html__( 'No QSO records were found in that file. Is it an ADIF (.adi) file?', 'wp-logbook' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Target logbook: an existing one, or a new one created for this import.
		$choice = isset( $_POST['logbook'] ) ? sanitize_key( wp_unslash( $_POST['logbook'] ) ) : '';
		if ( 'new' === $choice ) {
			$logbook = WPLB_Logbooks::create( array( 'title' => isset( $_POST['new_logbook'] ) ? sanitize_text_field( wp_unslash( $_POST['new_logbook'] ) ) : '' ) );
			if ( is_wp_error( $logbook ) ) {
				self::add_notice( esc_html( $logbook->get_error_message() ), 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
		} else {
			$logbook = WPLB_Logbooks::get( absint( $choice ) ) ? absint( $choice ) : WPLB_Logbooks::default_id();
		}

		$station = isset( $_POST['station_callsign'] ) ? WPLB_Fields::normalize_callsign( sanitize_text_field( wp_unslash( $_POST['station_callsign'] ) ) ) : false;
		$summary = WPLB_Import::adif(
			$text,
			array(
				'logbook'          => $logbook,
				'file_logbooks'    => ! empty( $_POST['file_logbooks'] ),
				'skip_duplicates'  => ! empty( $_POST['skip_duplicates'] ),
				'mark_uploaded'    => ! empty( $_POST['mark_uploaded'] ),
				'station_callsign' => $station ? $station : '',
			)
		);

		$message = esc_html(
			sprintf(
				/* translators: 1: QSOs imported, 2: records in the file, 3: duplicates skipped, 4: records with errors */
				__( 'Imported %1$s of %2$s QSOs. Duplicates skipped: %3$s. Records with errors: %4$s.', 'wp-logbook' ),
				number_format_i18n( $summary['imported'] ),
				number_format_i18n( $summary['records'] ),
				number_format_i18n( $summary['duplicates'] ),
				number_format_i18n( $summary['failed'] )
			)
		);
		$where = array();
		foreach ( $summary['logbooks'] as $id => $count ) {
			/* translators: 1: logbook title, 2: number of QSOs */
			$where[] = sprintf( __( '%1$s: %2$s', 'wp-logbook' ), WPLB_Logbooks::title( $id ), number_format_i18n( $count ) );
		}
		if ( $where ) {
			/* translators: %s: list of logbooks with QSO counts */
			$message .= ' ' . esc_html( sprintf( __( 'Logbooks: %s.', 'wp-logbook' ), implode( ', ', $where ) ) );
		}
		self::add_notice( $message . self::html_list( $summary['errors'] ), $summary['failed'] ? 'warning' : 'success' );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Delete one QSO (admin-post).
	 */
	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'wplb_delete_qso_' . $id );
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to delete QSOs.', 'wp-logbook' ), 403 );
		}

		$qso = WPLB_QSOs::get( $id );
		if ( $qso && WPLB_QSOs::delete( array( $id ) ) ) {
			/* translators: %s: callsign */
			self::add_notice( esc_html( sprintf( __( 'QSO with %s deleted.', 'wp-logbook' ), $qso['callsign'] ) ) );
			self::add_remote_delete_notice();
		}

		wp_safe_redirect( self::list_referer() );
		exit;
	}

	/**
	 * Upload one QSO to QRZ.com (admin-post).
	 */
	public static function handle_upload_one() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'wplb_qrz_upload_' . $id );
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to upload QSOs.', 'wp-logbook' ), 403 );
		}

		self::add_upload_notice( WPLB_QRZ_Sync::is_enabled() ? WPLB_QRZ_Sync::upload_many( array( $id ) ) : null );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : self::list_referer() );
		exit;
	}

	/**
	 * Queue every failed upload again (admin-post).
	 */
	public static function handle_retry_failed() {
		check_admin_referer( 'wplb_qrz_retry_failed' );
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to upload QSOs.', 'wp-logbook' ), 403 );
		}

		$count = WPLB_QSOs::queue_for_upload( WPLB_QSOs::ids_with_qrz_status( array( 'failed' ) ) );
		/* translators: %s: number of QSOs */
		self::add_notice( esc_html( sprintf( _n( '%s QSO queued again. Use "Upload now" to send it.', '%s QSOs queued again. Use "Upload now" to send them.', $count, 'wp-logbook' ), number_format_i18n( $count ) ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=wplb-logbook' ) );
		exit;
	}

	/**
	 * Notice summarising an upload_many() run.
	 *
	 * @param array|null $summary Summary, or null when uploads are not configured.
	 */
	private static function add_upload_notice( $summary ) {
		if ( null === $summary ) {
			self::add_notice( esc_html__( 'QRZ.com upload is not set up. Switch it on and add your API key in the Logbook settings.', 'wp-logbook' ), 'error' );
			return;
		}

		$parts = array();
		if ( $summary['uploaded'] ) {
			/* translators: %s: number of QSOs */
			$parts[] = sprintf( _n( '%s QSO uploaded to QRZ.com.', '%s QSOs uploaded to QRZ.com.', $summary['uploaded'], 'wp-logbook' ), number_format_i18n( $summary['uploaded'] ) );
		}
		if ( $summary['failed'] ) {
			/* translators: %s: number of QSOs */
			$parts[] = sprintf( _n( '%s QSO was rejected by QRZ.com.', '%s QSOs were rejected by QRZ.com.', $summary['failed'], 'wp-logbook' ), number_format_i18n( $summary['failed'] ) );
		}
		if ( $summary['skipped'] ) {
			/* translators: %s: number of QSOs */
			$parts[] = sprintf( _n( '%s QSO was skipped: it is marked "Not for QRZ.com" or its logbook is not uploaded.', '%s QSOs were skipped: they are marked "Not for QRZ.com" or their logbook is not uploaded.', $summary['skipped'], 'wp-logbook' ), number_format_i18n( $summary['skipped'] ) );
		}
		if ( $summary['stopped'] ) {
			$parts[] = __( 'Upload stopped:', 'wp-logbook' ) . ' ' . $summary['stop_message'];
		}

		$type = $summary['stopped'] || $summary['failed'] ? 'error' : 'success';
		self::add_notice( esc_html( implode( ' ', $parts ) ) . self::html_list( $summary['errors'] ), $type );
	}

	/**
	 * Notice for QSOs that could not be deleted from QRZ.com.
	 */
	public static function add_remote_delete_notice() {
		if ( WPLB_QRZ_Sync::$delete_errors ) {
			self::add_notice( esc_html__( 'Some QSOs could not be deleted from QRZ.com:', 'wp-logbook' ) . self::html_list( WPLB_QRZ_Sync::$delete_errors ), 'warning' );
		}
	}

	/**
	 * Where to return to after a row action: the referring list view if any.
	 *
	 * @return string
	 */
	private static function list_referer() {
		$referer = wp_get_referer();
		return $referer && false !== strpos( $referer, 'page=wplb-logbook' ) ? $referer : admin_url( 'admin.php?page=wplb-logbook' );
	}

	/**
	 * AJAX: check the QRZ.com API key with a STATUS request.
	 */
	public static function ajax_qrz_test() {
		check_ajax_referer( 'wplb_qrz_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'wp-logbook' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? preg_replace( '/[^A-Za-z0-9-]/', '', sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		if ( '' === $key ) {
			// The key saved for the logbook being edited, or the default key.
			$key = isset( $_POST['logbook'] ) && absint( $_POST['logbook'] ) ? WPLB_Logbooks::qrz_key( absint( $_POST['logbook'] ) ) : WPLB_Settings::api_key();
		}
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter your QRZ.com Logbook API key first.', 'wp-logbook' ) ) );
		}

		$response = WPLB_QRZ_Sync::client( $key )->status();
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$result = strtoupper( $response['RESULT'] );
		if ( 'OK' !== $result ) {
			$message = 'AUTH' === $result
				? __( 'QRZ.com refused the key (RESULT=AUTH). Check that you copied the key of the right logbook; API access may require a QRZ.com subscription.', 'wp-logbook' )
				/* translators: %s: RESULT value returned by QRZ.com */
				: sprintf( __( 'QRZ.com answered RESULT=%s.', 'wp-logbook' ), $result );
			if ( ! empty( $response['REASON'] ) ) {
				$message .= ' ' . $response['REASON'];
			}
			wp_send_json_error( array( 'message' => $message ) );
		}

		$labels  = array(
			'BOOK_NAME'  => __( 'Logbook', 'wp-logbook' ),
			'CALLSIGN'   => __( 'Callsign', 'wp-logbook' ),
			'OWNER'      => __( 'Owner', 'wp-logbook' ),
			'COUNT'      => __( 'QSOs', 'wp-logbook' ),
			'CONFIRMED'  => __( 'Confirmed', 'wp-logbook' ),
			'DXCC_COUNT' => __( 'DXCC entities', 'wp-logbook' ),
			'START_DATE' => __( 'First QSO', 'wp-logbook' ),
			'END_DATE'   => __( 'Last QSO', 'wp-logbook' ),
		);
		$details = array();
		foreach ( $labels as $field => $label ) {
			if ( isset( $response[ $field ] ) && '' !== $response[ $field ] ) {
				$details[] = array(
					'label' => $label,
					'value' => $response[ $field ],
				);
			}
		}

		$warning   = '';
		$book_call = isset( $response['CALLSIGN'] ) ? strtoupper( $response['CALLSIGN'] ) : '';
		$station   = isset( $_POST['station'] ) ? WPLB_Fields::normalize_callsign( sanitize_text_field( wp_unslash( $_POST['station'] ) ) ) : '';
		if ( ! $station ) {
			// The callsign typed on the Logbooks screen, else the saved one.
			$station = isset( $_POST['logbook'] ) && absint( $_POST['logbook'] ) ? WPLB_Logbooks::station_callsign( absint( $_POST['logbook'] ) ) : WPLB_Settings::get( 'station_callsign' );
		}
		if ( '' !== $book_call && '' !== $station && $book_call !== $station ) {
			$warning = sprintf(
				/* translators: 1: logbook callsign, 2: station callsign from the settings */
				__( 'This QRZ.com logbook is for %1$s but your station callsign is %2$s. QRZ.com rejects QSOs whose station callsign does not match the logbook.', 'wp-logbook' ),
				$book_call,
				$station
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Connected to QRZ.com.', 'wp-logbook' ),
				'details' => $details,
				'warning' => $warning,
			)
		);
	}

	/**
	 * AJAX: upload a batch of QSOs to QRZ.com.
	 *
	 * Without "ids" the next waiting QSOs are sent. The page calls this
	 * repeatedly so that large logs never hit the PHP time limit.
	 */
	public static function ajax_qrz_upload() {
		check_ajax_referer( 'wplb_qrz_upload', 'nonce' );
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'wp-logbook' ) ), 403 );
		}
		if ( ! WPLB_QRZ_Sync::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'QRZ.com upload is not set up. Switch it on and add your API key in the Logbook settings.', 'wp-logbook' ) ) );
		}

		$size = self::batch_size();
		$ids  = isset( $_POST['ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) ) : array();
		$ids  = $ids ? array_slice( $ids, 0, $size ) : WPLB_QSOs::ids_with_qrz_status( array( 'pending', 'modified' ), $size, WPLB_Logbooks::qrz_logbook_ids() );

		$summary              = WPLB_QRZ_Sync::upload_many( $ids );
		$counts               = WPLB_QSOs::count_by_qrz_status( WPLB_Logbooks::qrz_logbook_ids() );
		$summary['remaining'] = $counts['pending'] + $counts['modified'];

		wp_send_json_success( $summary );
	}

	/**
	 * QSOs sent to QRZ.com per AJAX request.
	 *
	 * @return int
	 */
	private static function batch_size() {
		/**
		 * Filters how many QSOs one upload request sends.
		 *
		 * Keep each request well inside the PHP time limit; QRZ.com answers
		 * one QSO at a time.
		 *
		 * @param int $size QSOs per request.
		 */
		return max( 1, (int) apply_filters( 'wplb_qrz_batch_size', 10 ) );
	}

	/**
	 * AJAX: earlier contacts with a callsign, for the QSO form.
	 */
	public static function ajax_lookup() {
		check_ajax_referer( 'wplb_lookup', 'nonce' );
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'wp-logbook' ) ), 403 );
		}

		$call = isset( $_GET['callsign'] ) ? WPLB_Fields::normalize_callsign( sanitize_text_field( wp_unslash( $_GET['callsign'] ) ) ) : false;
		if ( ! $call ) {
			wp_send_json_success(
				array(
					'total' => 0,
					'items' => array(),
					'fill'  => array(),
				)
			);
		}

		$exclude = isset( $_GET['exclude'] ) ? absint( $_GET['exclude'] ) : 0;
		$result  = WPLB_QSOs::previous_contacts( $call, $exclude, 5 );
		$items   = array();
		$fill    = array();

		foreach ( $result['items'] as $qso ) {
			$items[] = array(
				'callsign' => $qso['callsign'],
				'time'     => substr( $qso['time_on'], 0, 16 ),
				'band'     => $qso['band'],
				'mode'     => WPLB_Fields::display_mode( $qso['mode'], $qso['submode'] ),
				'url'      => admin_url( 'admin.php?page=wplb-qso&id=' . (int) $qso['id'] ),
			);
			// Pre-fill from the latest contact with exactly this callsign; a
			// portable or mobile operation is usually somewhere else.
			if ( ! $fill && $qso['callsign'] === $call ) {
				foreach ( array( 'name', 'qth', 'gridsquare', 'country', 'state', 'cnty', 'dxcc', 'cont', 'cqz', 'ituz', 'iota', 'qsl_via', 'email' ) as $key ) {
					if ( null !== $qso[ $key ] && '' !== (string) $qso[ $key ] ) {
						$fill[ $key ] = (string) $qso[ $key ];
					}
				}
			}
		}

		wp_send_json_success(
			array(
				'total' => (int) $result['total'],
				'items' => $items,
				'fill'  => $fill,
			)
		);
	}
}
