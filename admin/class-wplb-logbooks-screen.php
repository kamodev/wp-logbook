<?php
/**
 * Logbooks screen.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logbook → Logbooks: add, edit and delete logbooks.
 *
 * The forms post back to the screen itself and are handled on its load
 * hook, so a form with errors is shown again with what was typed.
 */
class WPLB_Logbooks_Screen {

	/**
	 * Submitted values to show again after a validation error.
	 *
	 * @var array|null
	 */
	private static $submitted = null;

	/**
	 * Validation errors keyed by field.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Load handler: process a submitted form.
	 */
	public static function load() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save() and delete().
		if ( isset( $_POST['wplb_logbook_nonce'] ) ) {
			self::save();
		} elseif ( isset( $_POST['wplb_delete_logbook_nonce'] ) ) {
			self::delete();
		}
		// phpcs:enable
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=wplb-logbooks' ) );
	}

	/**
	 * Stop users who may not manage the logbook.
	 */
	private static function check_capability() {
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage logbooks.', 'wp-logbook' ), 403 );
		}
	}

	/**
	 * Add or update a logbook.
	 */
	private static function save() {
		check_admin_referer( 'wplb_save_logbook', 'wplb_logbook_nonce' );
		self::check_capability();

		$id       = isset( $_POST['logbook_id'] ) ? absint( $_POST['logbook_id'] ) : 0;
		$existing = $id ? WPLB_Logbooks::get( $id ) : null;
		if ( $id && ! $existing ) {
			wp_die( esc_html__( 'That logbook does not exist. It may have been deleted.', 'wp-logbook' ), '', array( 'back_link' => true ) );
		}

		// Every value is cleaned by WPLB_Logbooks::sanitize().
		$input = isset( $_POST['wplb_logbook'] ) && is_array( $_POST['wplb_logbook'] ) ? array_filter( wp_unslash( $_POST['wplb_logbook'] ), 'is_string' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! current_user_can( 'manage_options' ) ) {
			// QRZ.com keys are settings: only administrators see and change them.
			unset( $input['qrz_api_key'], $input['qrz_api_key_remove'], $input['qrz_upload'] );
		}

		list( , $errors ) = WPLB_Logbooks::sanitize( $input, $id );
		if ( ! $errors ) {
			$result = $id ? WPLB_Logbooks::update( $id, $input ) : WPLB_Logbooks::create( $input );
			if ( is_wp_error( $result ) ) {
				$errors = array( 'db' => $result->get_error_message() );
			}
		}
		if ( $errors ) {
			unset( $input['qrz_api_key'] );
			self::$submitted = $input;
			self::$errors    = $errors;
			return;
		}

		$id = $id ? $id : (int) $result;
		if ( ! empty( $input['make_default'] ) ) {
			update_option( WPLB_Logbooks::DEFAULT_OPTION, $id, false );
		}

		$message = $existing
			/* translators: %s: logbook title */
			? __( 'Logbook "%s" updated.', 'wp-logbook' )
			/* translators: %s: logbook title */
			: __( 'Logbook "%s" added.', 'wp-logbook' );
		WPLB_Admin::add_notice( esc_html( sprintf( $message, WPLB_Logbooks::title( $id ) ) ) );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Delete a logbook, moving or deleting its QSOs.
	 */
	private static function delete() {
		$id = isset( $_POST['logbook_id'] ) ? absint( $_POST['logbook_id'] ) : 0;
		check_admin_referer( 'wplb_delete_logbook_' . $id, 'wplb_delete_logbook_nonce' );
		self::check_capability();

		$logbook = WPLB_Logbooks::get( $id );
		if ( ! $logbook ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$counts      = WPLB_Logbooks::counts();
		$total       = isset( $counts[ $id ] ) ? $counts[ $id ] : 0;
		$delete_qsos = isset( $_POST['qsos'] ) && 'delete' === $_POST['qsos'];
		$move_to     = ! $delete_qsos && isset( $_POST['move_to'] ) ? absint( $_POST['move_to'] ) : 0;

		if ( $total && ! $delete_qsos && ! $move_to ) {
			$result = new WP_Error( 'wplb_logbook_target', __( 'Choose another logbook for the QSOs.', 'wp-logbook' ) );
		} else {
			$result = WPLB_Logbooks::delete( $id, $move_to );
		}
		if ( is_wp_error( $result ) ) {
			WPLB_Admin::add_notice( esc_html( $result->get_error_message() ), 'error' );
			wp_safe_redirect( self::url( array( 'delete' => $id ) ) );
			exit;
		}

		/* translators: %s: logbook title */
		$message = sprintf( __( 'Logbook "%s" deleted.', 'wp-logbook' ), $logbook['title'] );
		if ( $total && $move_to ) {
			/* translators: 1: number of QSOs, 2: logbook title */
			$message .= ' ' . sprintf( _n( 'Its %1$s QSO was moved to "%2$s".', 'Its %1$s QSOs were moved to "%2$s".', $total, 'wp-logbook' ), number_format_i18n( $total ), WPLB_Logbooks::title( $move_to ) );
		} elseif ( $total ) {
			/* translators: %s: number of QSOs */
			$message .= ' ' . sprintf( _n( 'Its %s QSO was deleted.', 'Its %s QSOs were deleted.', $total, 'wp-logbook' ), number_format_i18n( $total ) );
		}
		WPLB_Admin::add_notice( esc_html( $message ) );
		WPLB_Admin::add_remote_delete_notice();

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- selects what to show.
		$edit   = isset( $_GET['edit'] ) ? WPLB_Logbooks::get( absint( $_GET['edit'] ) ) : null;
		$delete = isset( $_GET['delete'] ) ? WPLB_Logbooks::get( absint( $_GET['delete'] ) ) : null;
		// phpcs:enable

		echo '<div class="wrap wplb-wrap wplb-logbooks-screen">';
		if ( $edit ) {
			self::render_edit( $edit );
		} elseif ( $delete ) {
			self::render_delete( $delete );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * The logbooks and the form to add one.
	 */
	private static function render_list() {
		$logbooks = WPLB_Logbooks::all();
		$counts   = WPLB_Logbooks::counts();
		$default  = WPLB_Logbooks::default_id();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Logbooks', 'wp-logbook' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		WPLB_Admin::render_notices();
		echo '<p>' . esc_html__( 'Keep separate logs, for example for your home station, portable or POTA activations, contests or a second callsign. Every QSO is in one logbook. Logging, import, export and the public shortcodes can each work with one logbook or all of them.', 'wp-logbook' ) . '</p>';

		echo '<div id="col-container" class="wp-clearfix">';

		echo '<div id="col-left"><div class="col-wrap"><div class="form-wrap">';
		echo '<h2>' . esc_html__( 'Add a logbook', 'wp-logbook' ) . '</h2>';
		self::render_errors();
		printf( '<form method="post" action="%s" id="wplb-logbook-form">', esc_url( self::url() ) );
		self::render_fields( null, 'wrap' );
		submit_button( __( 'Add logbook', 'wp-logbook' ) );
		echo '</form></div></div></div>';

		echo '<div id="col-right"><div class="col-wrap"><div class="wplb-table-scroll">';
		$columns = array(
			'title'   => __( 'Title', 'wp-logbook' ),
			'slug'    => __( 'Shortcode attribute', 'wp-logbook' ),
			'station' => __( 'Station callsign', 'wp-logbook' ),
			'qsos'    => __( 'QSOs', 'wp-logbook' ),
			'qrz'     => __( 'QRZ.com', 'wp-logbook' ),
		);
		echo '<table class="widefat striped wplb-logbooks-table"><thead><tr>';
		foreach ( $columns as $column => $label ) {
			printf( '<th scope="col" class="column-%s">%s</th>', esc_attr( $column ), esc_html( $label ) );
		}
		echo '</tr></thead><tbody>';
		// Column names label the cells when the table is stacked on small screens.
		$cell = function ( $column ) use ( $columns ) {
			return sprintf( '<td class="column-%s" data-colname="%s">', esc_attr( $column ), esc_attr( $columns[ $column ] ) );
		};

		foreach ( $logbooks as $id => $logbook ) {
			$list = admin_url( 'admin.php?page=wplb-logbook&logbook=' . $id );

			echo '<tr>' . $cell( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $cell.
			printf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( self::url( array( 'edit' => $id ) ) ), esc_html( $logbook['title'] ) );
			if ( $id === $default ) {
				echo ' <span class="wplb-default-tag">' . esc_html__( 'Default', 'wp-logbook' ) . '</span>';
			}
			if ( '' !== $logbook['description'] ) {
				echo '<p class="description">' . esc_html( $logbook['description'] ) . '</p>';
			}
			$actions = array(
				'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'edit' => $id ) ) ), esc_html__( 'Edit', 'wp-logbook' ) ),
				'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $list ), esc_html__( 'View QSOs', 'wp-logbook' ) ),
				'log'    => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=wplb-qso&logbook=' . $id ) ), esc_html__( 'Log a QSO', 'wp-logbook' ) ),
				'export' => sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wplb_export&format=adif&logbook=' . $id ), 'wplb_export' ) ), esc_html__( 'Export ADIF', 'wp-logbook' ) ),
			);
			if ( count( $logbooks ) > 1 ) {
				$actions['delete'] = sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'delete' => $id ) ) ), esc_html__( 'Delete', 'wp-logbook' ) );
			}
			echo '<div class="row-actions visible">';
			$links = array();
			foreach ( $actions as $class => $link ) {
				$links[] = '<span class="' . esc_attr( $class ) . '">' . $link . '</span>';
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</div></td>';

			echo $cell( 'slug' ) . '<code>logbook="' . esc_html( $logbook['slug'] ) . '"</code></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $cell.

			echo $cell( 'station' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $cell.
			if ( '' !== $logbook['station_callsign'] ) {
				echo esc_html( $logbook['station_callsign'] );
			} elseif ( '' !== WPLB_Logbooks::station_callsign( $id ) ) {
				/* translators: %s: station callsign from the settings */
				echo '<span class="description">' . esc_html( sprintf( __( '%s (settings)', 'wp-logbook' ), WPLB_Logbooks::station_callsign( $id ) ) ) . '</span>';
			} else {
				echo '—';
			}
			echo '</td>';

			echo $cell( 'qsos' ) . '<a href="' . esc_url( $list ) . '">' . esc_html( number_format_i18n( $counts[ $id ] ) ) . '</a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $cell.
			echo $cell( 'qrz' ) . esc_html( self::qrz_summary( $logbook ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $cell.
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		if ( ! WPLB_Settings::get( 'qrz_enabled' ) ) {
			echo '<p class="description">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the settings */
					__( 'QRZ.com upload is switched off in the %s, so no logbook is uploaded at the moment.', 'wp-logbook' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wplb-settings' ) ) . '">' . esc_html__( 'settings', 'wp-logbook' ) . '</a>'
				)
			) . '</p>';
		}
		echo '</div></div></div>';
	}

	/**
	 * How a logbook goes to QRZ.com.
	 *
	 * @param array $logbook Logbook row.
	 * @return string
	 */
	private static function qrz_summary( array $logbook ) {
		if ( ! $logbook['qrz_upload'] ) {
			return __( 'Not uploaded', 'wp-logbook' );
		}
		if ( '' !== $logbook['qrz_api_key'] ) {
			/* translators: %s: masked API key */
			return sprintf( __( 'Own API key (%s)', 'wp-logbook' ), WPLB_Settings::mask( $logbook['qrz_api_key'] ) );
		}
		return '' !== WPLB_Settings::api_key() ? __( 'Default API key', 'wp-logbook' ) : __( 'No API key yet', 'wp-logbook' );
	}

	/**
	 * Edit form.
	 *
	 * @param array $logbook Logbook row.
	 */
	private static function render_edit( array $logbook ) {
		$id = (int) $logbook['id'];

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Edit logbook', 'wp-logbook' ) . '</h1>';
		printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( admin_url( 'admin.php?page=wplb-logbook&logbook=' . $id ) ), esc_html__( 'View QSOs', 'wp-logbook' ) );
		echo '<hr class="wp-header-end">';
		WPLB_Admin::render_notices();
		self::render_errors();

		printf( '<form method="post" action="%s" id="wplb-logbook-form">', esc_url( self::url( array( 'edit' => $id ) ) ) );
		printf( '<input type="hidden" name="logbook_id" value="%d">', (int) $id );
		echo '<table class="form-table" role="presentation">';
		self::render_fields( $logbook, 'table' );
		echo '</table>';

		echo '<p class="submit">';
		submit_button( __( 'Update logbook', 'wp-logbook' ), 'primary', 'submit', false );
		printf( ' <a href="%s" class="button">%s</a>', esc_url( self::url() ), esc_html__( 'Cancel', 'wp-logbook' ) );
		if ( count( WPLB_Logbooks::all() ) > 1 ) {
			printf( ' <a href="%s" class="wplb-danger-link">%s</a>', esc_url( self::url( array( 'delete' => $id ) ) ), esc_html__( 'Delete', 'wp-logbook' ) );
		}
		echo '</p></form>';
	}

	/**
	 * Delete confirmation.
	 *
	 * @param array $logbook Logbook row.
	 */
	private static function render_delete( array $logbook ) {
		$id     = (int) $logbook['id'];
		$counts = WPLB_Logbooks::counts();
		$total  = $counts[ $id ];
		$others = WPLB_Logbooks::choices();
		unset( $others[ $id ] );

		echo '<h1>' . esc_html__( 'Delete logbook', 'wp-logbook' ) . '</h1>';
		WPLB_Admin::render_notices();

		if ( ! $others ) {
			echo '<p>' . esc_html__( 'The last logbook cannot be deleted.', 'wp-logbook' ) . '</p>';
			printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( self::url() ), esc_html__( 'Back to logbooks', 'wp-logbook' ) );
			return;
		}

		printf( '<form method="post" action="%s" id="wplb-delete-logbook-form">', esc_url( self::url( array( 'delete' => $id ) ) ) );
		wp_nonce_field( 'wplb_delete_logbook_' . $id, 'wplb_delete_logbook_nonce' );
		printf( '<input type="hidden" name="logbook_id" value="%d">', (int) $id );

		/* translators: %s: logbook title */
		echo '<p>' . wp_kses( sprintf( __( 'You are about to delete the logbook %s.', 'wp-logbook' ), '<strong>' . esc_html( $logbook['title'] ) . '</strong>' ), array( 'strong' => array() ) ) . '</p>';
		if ( WPLB_Logbooks::default_id() === $id ) {
			echo '<p>' . esc_html__( 'It is the default logbook. The logbook that receives its QSOs becomes the default.', 'wp-logbook' ) . '</p>';
		}

		if ( $total ) {
			$target = WPLB_Logbooks::default_id() !== $id ? WPLB_Logbooks::default_id() : (int) key( $others );
			echo '<fieldset class="wplb-delete-choice"><legend>';
			/* translators: %s: number of QSOs */
			echo esc_html( sprintf( _n( 'What should happen to its %s QSO?', 'What should happen to its %s QSOs?', $total, 'wp-logbook' ), number_format_i18n( $total ) ) );
			echo '</legend>';
			printf( '<p><label><input type="radio" name="qsos" value="move" checked> %s</label> ', esc_html__( 'Move them to', 'wp-logbook' ) );
			printf( '<select name="move_to" aria-label="%s">', esc_attr__( 'Logbook that receives the QSOs', 'wp-logbook' ) );
			foreach ( $others as $other_id => $title ) {
				printf( '<option value="%d"%s>%s</option>', (int) $other_id, selected( $target, $other_id, false ), esc_html( $title ) );
			}
			echo '</select></p>';
			printf( '<p><label><input type="radio" name="qsos" value="delete"> %s</label></p>', esc_html__( 'Delete them', 'wp-logbook' ) );
			echo '<p class="description">';
			esc_html_e( 'Deleted QSOs cannot be restored. Export the logbook first if you may need them again.', 'wp-logbook' );
			if ( WPLB_QRZ_Sync::is_enabled() && WPLB_Settings::get( 'qrz_delete_remote' ) ) {
				echo ' ' . esc_html__( 'QSOs that were uploaded are deleted from QRZ.com too, as set in the settings.', 'wp-logbook' );
			}
			echo '</p></fieldset>';
		} else {
			echo '<p>' . esc_html__( 'It has no QSOs.', 'wp-logbook' ) . '</p>';
		}

		echo '<p class="submit">';
		submit_button( __( 'Delete logbook', 'wp-logbook' ), 'primary', 'submit', false );
		printf( ' <a href="%s" class="button">%s</a>', esc_url( self::url() ), esc_html__( 'Cancel', 'wp-logbook' ) );
		echo '</p></form>';
	}

	/**
	 * Errors of the last submit.
	 */
	private static function render_errors() {
		if ( ! self::$errors ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The logbook was not saved:', 'wp-logbook' ) . '</p><ul class="wplb-notice-list">';
		foreach ( self::$errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * The logbook fields, as form-wrap rows or form-table rows.
	 *
	 * @param array|null $logbook Logbook being edited, null for a new one.
	 * @param string     $layout  "wrap" or "table".
	 */
	private static function render_fields( $logbook, $layout ) {
		$id     = $logbook ? (int) $logbook['id'] : 0;
		$values = $logbook ? $logbook : array(
			'title'            => '',
			'slug'             => '',
			'description'      => '',
			'station_callsign' => '',
			'qrz_upload'       => 1,
		);
		if ( null !== self::$submitted ) {
			$values = array_merge( $values, self::$submitted );
		}
		$value = function ( $key ) use ( $values ) {
			return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		};

		wp_nonce_field( 'wplb_save_logbook', 'wplb_logbook_nonce' );

		self::field(
			$layout,
			'wplb-logbook-title',
			esc_html__( 'Title', 'wp-logbook' ) . ' <span class="wplb-required" aria-hidden="true">*</span>',
			sprintf( '<input type="text" id="wplb-logbook-title" name="wplb_logbook[title]" value="%s" class="regular-text" maxlength="100" required%s>', esc_attr( $value( 'title' ) ), isset( self::$errors['title'] ) ? ' aria-invalid="true"' : '' ),
			__( 'Shown in the admin and in the logbook filter of public pages.', 'wp-logbook' )
		);
		self::field(
			$layout,
			'wplb-logbook-slug',
			esc_html__( 'Slug', 'wp-logbook' ),
			sprintf( '<input type="text" id="wplb-logbook-slug" name="wplb_logbook[slug]" value="%s" class="regular-text" maxlength="90" spellcheck="false">', esc_attr( $value( 'slug' ) ) ),
			__( 'Picks the logbook in shortcodes, for example [wp_logbook logbook="pota"], and names export files. Leave empty to make one from the title.', 'wp-logbook' )
		);
		$settings_call = (string) WPLB_Settings::get( 'station_callsign' );
		self::field(
			$layout,
			'wplb-logbook-station',
			esc_html__( 'Station callsign', 'wp-logbook' ),
			sprintf( '<input type="text" id="wplb-logbook-station" name="wplb_logbook[station_callsign]" value="%s" maxlength="32" class="regular-text wplb-uppercase" spellcheck="false"%s>', esc_attr( $value( 'station_callsign' ) ), isset( self::$errors['station_callsign'] ) ? ' aria-invalid="true"' : '' ),
			'' !== $settings_call
				/* translators: %s: station callsign from the settings */
				? sprintf( __( 'Pre-filled on new QSOs in this logbook. Leave empty to use the callsign from the settings (%s).', 'wp-logbook' ), $settings_call )
				: __( 'Pre-filled on new QSOs in this logbook. Leave empty to use the callsign from the settings.', 'wp-logbook' )
		);
		self::field(
			$layout,
			'wplb-logbook-description',
			esc_html__( 'Description', 'wp-logbook' ),
			sprintf( '<textarea id="wplb-logbook-description" name="wplb_logbook[description]" rows="3" cols="50">%s</textarea>', esc_textarea( $value( 'description' ) ) ),
			__( 'Notes for yourself. Not shown on the site.', 'wp-logbook' )
		);

		if ( $id && WPLB_Logbooks::default_id() === $id ) {
			self::field( $layout, '', esc_html__( 'Default', 'wp-logbook' ), esc_html__( 'This is the default logbook.', 'wp-logbook' ), '' );
		} else {
			self::field(
				$layout,
				'',
				esc_html__( 'Default', 'wp-logbook' ),
				sprintf( '<label><input type="checkbox" name="wplb_logbook[make_default]" value="1"%s> %s</label>', checked( ! empty( $values['make_default'] ), true, false ), esc_html__( 'Make this the default logbook', 'wp-logbook' ) ),
				__( 'New QSOs and imports go to the default logbook unless you choose another.', 'wp-logbook' )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::field(
			$layout,
			'',
			esc_html__( 'QRZ.com', 'wp-logbook' ),
			sprintf( '<input type="hidden" name="wplb_logbook[qrz_upload]" value="0"><label><input type="checkbox" name="wplb_logbook[qrz_upload]" value="1"%s> %s</label>', checked( ! empty( $values['qrz_upload'] ), true, false ), esc_html__( 'Upload the QSOs of this logbook to QRZ.com', 'wp-logbook' ) ),
			__( 'Uploads also need QRZ.com upload switched on in the settings.', 'wp-logbook' )
		);

		$own_key = $logbook ? (string) $logbook['qrz_api_key'] : '';
		$control = sprintf(
			'<input type="password" id="wplb-qrz-key" name="wplb_logbook[qrz_api_key]" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="%s">',
			esc_attr( '' !== $own_key ? WPLB_Settings::mask( $own_key ) : __( 'Use the default key', 'wp-logbook' ) )
		);
		if ( '' !== $own_key ) {
			$control .= '<p class="description">' . esc_html__( 'A key is saved for this logbook. Leave the field empty to keep it.', 'wp-logbook' ) . '</p>';
			$control .= sprintf( '<p><label><input type="checkbox" name="wplb_logbook[qrz_api_key_remove]" value="1"> %s</label></p>', esc_html__( 'Remove the key and use the default key', 'wp-logbook' ) );
		}
		$control .= sprintf(
			'<p><button type="button" class="button" id="wplb-qrz-test" data-logbook="%d">%s</button></p><div id="wplb-qrz-test-result" class="wplb-test-result" role="status"></div>',
			(int) $id,
			esc_html__( 'Test connection', 'wp-logbook' )
		);
		self::field(
			$layout,
			'wplb-qrz-key',
			esc_html__( 'QRZ.com API key', 'wp-logbook' ),
			$control,
			__( 'Only needed when this logbook goes to another QRZ.com logbook than the default key in the settings. QRZ.com keeps a separate logbook, with its own key, for each callsign.', 'wp-logbook' )
		);
	}

	/**
	 * One field row.
	 *
	 * @param string $layout      "wrap" or "table".
	 * @param string $id          ID of the control the label is for; empty for no label element.
	 * @param string $label       Label HTML, escaped.
	 * @param string $control     Control HTML (escaped).
	 * @param string $description Plain-text help.
	 */
	private static function field( $layout, $id, $label, $control, $description ) {
		$label = $id ? sprintf( '<label for="%s">%s</label>', esc_attr( $id ), $label ) : $label;
		$help  = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';
		if ( 'table' === $layout ) {
			printf( '<tr><th scope="row">%s</th><td>%s%s</td></tr>', $label, $control, $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
		} else {
			printf( '<div class="form-field">%s%s%s</div>', $id ? $label : '<p class="wplb-field-heading">' . $label . '</p>', $control, $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
		}
	}
}
