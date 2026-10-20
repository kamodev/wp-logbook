<?php
/**
 * QSO entry and edit form.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * The "Log a QSO" / "Edit QSO" screen.
 */
class WPLB_QSO_Form {

	/**
	 * QSO being edited, null for a new one.
	 *
	 * @var array|null
	 */
	private static $qso = null;

	/**
	 * Submitted values to show again after a validation error.
	 *
	 * @var array|null
	 */
	private static $submitted = null;

	/**
	 * Validation errors keyed by column.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Logbook the QSO is (or will be) in.
	 *
	 * @var int
	 */
	private static $logbook = 0;

	/**
	 * User meta that remembers the logbook last logged to.
	 */
	const LAST_LOGBOOK_META = 'wplb_last_logbook';

	/**
	 * Load handler: find the QSO and process a submitted form.
	 */
	public static function load() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- selects what to show.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			self::$qso = WPLB_QSOs::get( $id );
			if ( ! self::$qso ) {
				wp_die( esc_html__( 'That QSO does not exist. It may have been deleted.', 'wp-logbook' ), '', array( 'back_link' => true ) );
			}
			self::$logbook = (int) self::$qso['logbook_id'];
		} else {
			// New QSO: the logbook in the link, else the one used last, else the default.
			$wanted        = isset( $_GET['logbook'] ) ? absint( $_GET['logbook'] ) : (int) get_user_meta( get_current_user_id(), self::LAST_LOGBOOK_META, true );
			self::$logbook = WPLB_Logbooks::get( $wanted ) ? $wanted : WPLB_Logbooks::default_id();
		}
		// phpcs:enable

		if ( isset( $_POST['wplb_qso_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
			self::save();
		}
	}

	/**
	 * Validate and store the submitted QSO.
	 */
	private static function save() {
		check_admin_referer( 'wplb_save_qso', 'wplb_qso_nonce' );
		if ( ! current_user_can( WPLB_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit the logbook.', 'wp-logbook' ), 403 );
		}

		// Every value is sanitised by WPLB_Fields::sanitize() below.
		$raw   = isset( $_POST['wplb'] ) && is_array( $_POST['wplb'] ) ? wp_unslash( $_POST['wplb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input = self::input_from_form( $raw );

		$logbook = isset( $_POST['wplb_logbook'] ) ? absint( $_POST['wplb_logbook'] ) : 0;
		if ( WPLB_Logbooks::get( $logbook ) ) {
			self::$logbook = $logbook;
		}

		list( $data, $errors ) = WPLB_Fields::sanitize( $input );
		$errors               += WPLB_Fields::validate( $data );
		if ( $errors ) {
			self::$submitted = $input;
			self::$errors    = $errors;
			return;
		}

		$data['logbook_id'] = self::$logbook;
		$skip_qrz           = ! empty( $raw['qrz_skip'] );
		$duplicate          = 0;
		if ( self::$qso ) {
			$id = (int) self::$qso['id'];
			if ( self::$logbook !== (int) self::$qso['logbook_id'] ) {
				// Moving to another logbook may mean another QRZ.com logbook.
				WPLB_QRZ_Sync::before_move( array( self::$qso ), self::$logbook );
				self::$qso = WPLB_QSOs::get( $id );
			}
			$status = WPLB_QRZ_Sync::status_after_edit( self::$qso, array_merge( self::$qso, $data ) );
			if ( $skip_qrz ) {
				$status = 'skip';
			} elseif ( 'skip' === $status ) {
				$status = empty( self::$qso['qrz_logid'] ) ? 'pending' : 'modified';
			}
			$data['qrz_status'] = $status;
			$result             = WPLB_QSOs::update( $id, $data );
		} else {
			$duplicate          = WPLB_QSOs::find_duplicate( $data['callsign'], $data['time_on'], $data['band'], $data['mode'], 0, self::$logbook );
			$data['qrz_status'] = $skip_qrz ? 'skip' : 'pending';
			$result             = WPLB_QSOs::insert( $data );
			$id                 = is_wp_error( $result ) ? 0 : $result;
		}

		if ( is_wp_error( $result ) ) {
			self::$submitted = $input;
			self::$errors    = array( 'db' => $result->get_error_message() );
			return;
		}

		/* translators: %s: callsign */
		$message = sprintf( self::$qso ? __( 'QSO with %s updated.', 'wp-logbook' ) : __( 'QSO with %s logged.', 'wp-logbook' ), $data['callsign'] );
		$type    = 'success';
		if ( $duplicate ) {
			$message .= ' ' . __( 'Note: the log already had a QSO with this station on the same band and mode within a minute.', 'wp-logbook' );
			$type     = 'warning';
		}

		$upload = WPLB_QRZ_Sync::maybe_upload_after_save( $id );
		if ( $upload && 'uploaded' === $upload['result'] ) {
			$message .= ' ' . trim( __( 'Uploaded to QRZ.com.', 'wp-logbook' ) . ' ' . $upload['message'] );
		} elseif ( $upload ) {
			/* translators: %s: error message */
			$message .= ' ' . sprintf( __( 'QRZ.com upload failed: %s', 'wp-logbook' ), $upload['message'] );
			$type     = 'warning';
		}
		WPLB_Admin::add_notice( esc_html( $message ), $type );
		update_user_meta( get_current_user_id(), self::LAST_LOGBOOK_META, self::$logbook );

		$url = ! empty( $_POST['save_and_new'] ) ? admin_url( 'admin.php?page=wplb-qso&logbook=' . self::$logbook ) : admin_url( 'admin.php?page=wplb-logbook' );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Turn the posted form into column values.
	 *
	 * @param array $raw Posted wplb[] values.
	 * @return array
	 */
	private static function input_from_form( array $raw ) {
		$input = array();
		foreach ( WPLB_Fields::all() as $key => $field ) {
			if ( 'datetime' === $field['type'] ) {
				$date          = isset( $raw[ $key . '_date' ] ) && is_string( $raw[ $key . '_date' ] ) ? trim( $raw[ $key . '_date' ] ) : '';
				$time          = isset( $raw[ $key . '_time' ] ) && is_string( $raw[ $key . '_time' ] ) ? trim( $raw[ $key . '_time' ] ) : '';
				$input[ $key ] = trim( $date . ' ' . $time );
			} elseif ( 'mode' === $field['type'] ) {
				list( $input['mode'], $input['submode'] ) = WPLB_Fields::parse_mode_value( isset( $raw['mode'] ) && is_string( $raw['mode'] ) ? $raw['mode'] : '' );
			} elseif ( $field['form'] ) {
				$input[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';
			}
		}
		return $input;
	}

	/**
	 * Values for a new QSO: station defaults, the band and mode of the last
	 * QSO, and the current time.
	 *
	 * @return array
	 */
	private static function defaults() {
		$values                     = WPLB_Settings::station_defaults();
		$values['station_callsign'] = WPLB_Logbooks::station_callsign( self::$logbook );
		$last                       = WPLB_QSOs::last_logged();
		if ( $last ) {
			foreach ( array( 'band', 'freq', 'mode', 'submode' ) as $key ) {
				$values[ $key ] = $last[ $key ];
			}
			if ( '' === (string) $values['tx_pwr'] ) {
				$values['tx_pwr'] = $last['tx_pwr'];
			}
		}
		$values['time_on'] = gmdate( 'Y-m-d H:i:s' );
		return $values;
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		$editing = null !== self::$qso;
		if ( null !== self::$submitted ) {
			$values = self::$submitted;
		} else {
			$values = $editing ? self::$qso : self::defaults();
		}

		echo '<div class="wrap wplb-wrap">';
		echo '<h1 class="wp-heading-inline">' . ( $editing ? esc_html__( 'Edit QSO', 'wp-logbook' ) : esc_html__( 'Log a QSO', 'wp-logbook' ) ) . '</h1>';
		if ( $editing ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( admin_url( 'admin.php?page=wplb-qso&logbook=' . self::$logbook ) ), esc_html__( 'Log another QSO', 'wp-logbook' ) );
		}
		echo '<hr class="wp-header-end">';

		WPLB_Admin::render_notices();
		if ( self::$errors ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The QSO was not saved:', 'wp-logbook' ) . '</p><ul class="wplb-notice-list">';
			foreach ( self::$errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		printf(
			'<form method="post" id="wplb-qso-form" class="wplb-qso-form" data-new="%d" data-id="%d" autocomplete="off">',
			$editing ? 0 : 1,
			$editing ? (int) self::$qso['id'] : 0
		);
		wp_nonce_field( 'wplb_save_qso', 'wplb_qso_nonce' );
		echo '<div class="wplb-form-layout"><div class="wplb-form-main">';
		self::render_logbook_picker();

		$fields = WPLB_Fields::all();
		foreach ( WPLB_Fields::groups() as $group => $label ) {
			$keys = array();
			foreach ( $fields as $key => $field ) {
				if ( $group === $field['group'] && $field['form'] ) {
					$keys[] = $key;
				}
			}
			if ( ! $keys ) {
				continue;
			}

			$open = 'qso' === $group || array_intersect_key( self::$errors, array_flip( $keys ) ) || ( $editing && self::has_values( $keys, $values ) );
			if ( 'qso' === $group ) {
				echo '<div class="wplb-section wplb-section-main"><div class="wplb-grid">';
			} else {
				printf( '<details class="wplb-section"%s><summary>%s</summary><div class="wplb-grid">', $open ? ' open' : '', esc_html( $label ) );
			}
			foreach ( $keys as $key ) {
				self::render_field( $key, $fields[ $key ], $values );
			}
			echo 'qso' === $group ? '</div></div>' : '</div></details>';
		}

		if ( WPLB_Settings::get( 'qrz_enabled' ) ) {
			self::render_qrz_section();
		}

		echo '<p class="submit wplb-submit">';
		if ( $editing ) {
			submit_button( __( 'Update QSO', 'wp-logbook' ), 'primary', 'save', false );
			printf( ' <a href="%s" class="button">%s</a>', esc_url( admin_url( 'admin.php?page=wplb-logbook' ) ), esc_html__( 'Cancel', 'wp-logbook' ) );
			$id = (int) self::$qso['id'];
			printf(
				' <a href="%s" class="wplb-delete-link wplb-delete-qso">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wplb_delete_qso&id=' . $id ), 'wplb_delete_qso_' . $id ) ),
				esc_html__( 'Delete', 'wp-logbook' )
			);
		} else {
			// First button: Enter in any field saves and opens a fresh form.
			submit_button( __( 'Save & log another', 'wp-logbook' ), 'primary', 'save_and_new', false );
			echo ' ';
			submit_button( __( 'Save', 'wp-logbook' ), 'secondary', 'save', false );
		}
		echo '</p>';

		echo '</div><aside class="wplb-form-side">';
		printf( '<div class="wplb-lookup-panel" id="wplb-lookup" hidden><h2>%s</h2><div class="wplb-lookup-body" role="status"></div></div>', esc_html__( 'Previous contacts', 'wp-logbook' ) );
		echo '</aside></div></form></div>';
	}

	/**
	 * Logbook picker at the top of the form.
	 *
	 * Each option carries the logbook's station callsign so the script can
	 * swap the default callsign of a new QSO when another logbook is picked.
	 */
	private static function render_logbook_picker() {
		echo '<div class="wplb-logbook-picker">';
		printf( '<label for="wplb-logbook">%s</label> ', esc_html__( 'Logbook', 'wp-logbook' ) );
		echo '<select id="wplb-logbook" name="wplb_logbook">';
		foreach ( WPLB_Logbooks::choices() as $id => $title ) {
			printf(
				'<option value="%d" data-station="%s"%s>%s</option>',
				(int) $id,
				esc_attr( WPLB_Logbooks::station_callsign( $id ) ),
				selected( $id, self::$logbook, false ),
				esc_html( $title )
			);
		}
		echo '</select>';
		printf( ' <a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=wplb-logbooks' ) ), esc_html__( 'Manage logbooks', 'wp-logbook' ) );
		if ( self::$qso && WPLB_Settings::get( 'qrz_enabled' ) && ! empty( self::$qso['qrz_logid'] ) ) {
			echo '<p class="description">' . esc_html__( 'Moving a QSO to a logbook with a different QRZ.com API key uploads it again to that QRZ.com logbook.', 'wp-logbook' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * One form control.
	 *
	 * @param string $key    Column.
	 * @param array  $field  Definition.
	 * @param array  $values Current values.
	 */
	private static function render_field( $key, array $field, array $values ) {
		$id    = 'wplb-' . str_replace( '_', '-', $key );
		$name  = 'wplb[' . $key . ']';
		$value = isset( $values[ $key ] ) && null !== $values[ $key ] ? (string) $values[ $key ] : '';
		$error = isset( self::$errors[ $key ] );
		$label = esc_html( $field['label'] ) . ( $field['required'] ? ' <span class="wplb-required" aria-hidden="true">*</span>' : '' );
		$attrs = ( $field['required'] ? ' required' : '' ) . ( $error ? ' aria-invalid="true"' : '' );

		printf( '<div class="wplb-field wplb-field-%s wplb-key-%s%s">', esc_attr( $field['type'] ), esc_attr( $key ), $error ? ' wplb-has-error' : '' );

		switch ( $field['type'] ) {
			case 'datetime':
				$parts = explode( ' ', $value, 2 );
				$time  = isset( $parts[1] ) ? $parts[1] : '';
				printf( '<label for="%s">%s</label>', esc_attr( $id ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				echo '<span class="wplb-datetime">';
				printf( '<input type="date" id="%s" name="%s" value="%s"%s>', esc_attr( $id ), esc_attr( 'wplb[' . $key . '_date]' ), esc_attr( $parts[0] ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf(
					'<input type="time" id="%s-time" name="%s" value="%s" step="1" aria-label="%s"%s>',
					esc_attr( $id ),
					esc_attr( 'wplb[' . $key . '_time]' ),
					esc_attr( $time ),
					/* translators: %s: field label */
					esc_attr( sprintf( __( '%s, time', 'wp-logbook' ), $field['label'] ) ),
					$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				printf( '<button type="button" class="button wplb-now" data-target="%s">%s</button>', esc_attr( $id ), esc_html__( 'Now', 'wp-logbook' ) );
				echo '</span>';
				break;

			case 'mode':
				printf( '<label for="%s">%s</label>', esc_attr( $id ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				self::mode_select( $id, $value, isset( $values['submode'] ) ? (string) $values['submode'] : '' );
				break;

			case 'band':
			case 'enum':
				$options = 'band' === $field['type'] ? array_combine( array_keys( WPLB_Fields::bands() ), array_keys( WPLB_Fields::bands() ) ) : WPLB_Fields::options( $field );
				printf( '<label for="%s">%s</label>', esc_attr( $id ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( '<select id="%s" name="%s"%s><option value="">—</option>', esc_attr( $id ), esc_attr( $name ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( $options as $option => $option_label ) {
					$text = 'band' === $field['type'] || (string) $option === $option_label ? $option_label : $option . ' – ' . $option_label;
					printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( strtoupper( $value ), strtoupper( $option ), false ), esc_html( $text ) );
				}
				echo '</select>';
				break;

			case 'text':
				printf( '<label for="%s">%s</label>', esc_attr( $id ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( '<textarea id="%s" name="%s" rows="4"%s>%s</textarea>', esc_attr( $id ), esc_attr( $name ), $attrs, esc_textarea( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			default:
				$type  = array(
					'date'  => 'date',
					'email' => 'email',
					'int'   => 'number',
				);
				$extra = '';
				if ( 'int' === $field['type'] && $field['range'] ) {
					$extra .= sprintf( ' min="%d" max="%d" step="1"', $field['range'][0], $field['range'][1] );
				}
				if ( 'decimal' === $field['type'] ) {
					$value  = WPLB_Fields::format_number( $value ) ? WPLB_Fields::format_number( $value ) : $value;
					$extra .= ' inputmode="decimal"';
				}
				if ( $field['max'] ) {
					$extra .= sprintf( ' maxlength="%d"', $field['max'] );
				}
				if ( 'grid' === $field['type'] ) {
					$extra .= ' maxlength="8"';
				}
				if ( in_array( $field['type'], array( 'callsign', 'grid', 'upper' ), true ) ) {
					$extra .= ' class="wplb-uppercase" spellcheck="false"';
				}
				printf( '<label for="%s">%s</label>', esc_attr( $id ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf(
					'<input type="%s" id="%s" name="%s" value="%s"%s%s>',
					esc_attr( isset( $type[ $field['type'] ] ) ? $type[ $field['type'] ] : 'text' ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from integers and constants.
					$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
		}

		if ( $error ) {
			printf( '<span class="wplb-error">%s</span>', esc_html( self::$errors[ $key ] ) );
		}
		echo '</div>';
	}

	/**
	 * Mode picker: common modes first, then every ADIF mode.
	 *
	 * @param string $id      Element ID.
	 * @param string $mode    Current ADIF mode.
	 * @param string $submode Current ADIF submode.
	 */
	private static function mode_select( $id, $mode, $submode ) {
		$current = WPLB_Fields::mode_value( strtoupper( $mode ), strtoupper( $submode ) );
		$found   = false;

		printf( '<select id="%s" name="wplb[mode]" required><option value="">—</option>', esc_attr( $id ) );

		printf( '<optgroup label="%s">', esc_attr__( 'Common modes', 'wp-logbook' ) );
		foreach ( WPLB_Fields::common_modes() as $label => $pair ) {
			$value = WPLB_Fields::mode_value( $pair[0], $pair[1] );
			$match = ! $found && $value === $current;
			$found = $found || $match;
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), $match ? ' selected' : '', esc_html( $label ) );
		}
		echo '</optgroup>';

		printf( '<optgroup label="%s">', esc_attr__( 'All ADIF modes', 'wp-logbook' ) );
		foreach ( array_keys( WPLB_Fields::adif_modes() ) as $value ) {
			$match = ! $found && $value === $current;
			$found = $found || $match;
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $value ), $match ? ' selected' : '' );
		}
		echo '</optgroup>';

		if ( ! $found && '' !== $current ) {
			$label = '' !== $submode ? sprintf( '%s (%s)', strtoupper( $submode ), strtoupper( $mode ) ) : strtoupper( $mode );
			printf( '<option value="%s" selected>%s</option>', esc_attr( $current ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * QRZ.com status of the QSO and the opt-out checkbox.
	 */
	private static function render_qrz_section() {
		$status = self::$qso ? self::$qso['qrz_status'] : '';
		$skip   = null !== self::$submitted ? ! empty( $_POST['wplb']['qrz_skip'] ) : 'skip' === $status; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().

		printf( '<details class="wplb-section"%s><summary>%s</summary><div class="wplb-qrz-box">', self::$qso ? ' open' : '', esc_html__( 'QRZ.com Logbook', 'wp-logbook' ) );
		if ( self::$qso ) {
			echo '<p>' . WPLB_Admin::qrz_badge( self::$qso ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in qrz_badge().
			if ( ! empty( self::$qso['qrz_logid'] ) ) {
				/* translators: %s: QRZ.com log ID */
				echo ' ' . esc_html( sprintf( __( 'Log ID %s', 'wp-logbook' ), self::$qso['qrz_logid'] ) );
			}
			if ( ! empty( self::$qso['qrz_upload_date'] ) ) {
				/* translators: %s: date and time */
				echo ' · ' . esc_html( sprintf( __( 'uploaded %s UTC', 'wp-logbook' ), substr( self::$qso['qrz_upload_date'], 0, 16 ) ) );
			}
			echo '</p>';
			if ( ! empty( self::$qso['qrz_message'] ) ) {
				echo '<p class="description">' . esc_html( self::$qso['qrz_message'] ) . '</p>';
			}
		}
		printf(
			'<p><label><input type="checkbox" name="wplb[qrz_skip]" value="1"%s> %s</label></p>',
			checked( $skip, true, false ),
			esc_html__( 'Do not upload this QSO to QRZ.com', 'wp-logbook' )
		);
		echo '</div></details>';
	}

	/**
	 * Whether any of the fields has a value.
	 *
	 * @param string[] $keys   Columns.
	 * @param array    $values Values.
	 * @return bool
	 */
	private static function has_values( array $keys, array $values ) {
		foreach ( $keys as $key ) {
			if ( isset( $values[ $key ] ) && '' !== (string) $values[ $key ] ) {
				return true;
			}
		}
		return false;
	}
}
