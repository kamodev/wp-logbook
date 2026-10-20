<?php
/**
 * Uploading QSOs to the QRZ.com Logbook.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the local logbook and the QRZ.com Logbook in step.
 *
 * Every QSO carries a qrz_status:
 * - pending:  not on QRZ.com yet.
 * - uploaded: accepted by QRZ.com (qrz_logid holds its log ID when known).
 * - modified: edited after upload; the next upload replaces the QRZ.com copy.
 * - failed:   QRZ.com rejected it; qrz_message says why.
 * - skip:     never upload.
 *
 * The whole feature is optional: nothing here runs unless it is switched on
 * in the settings and an API key is present.
 */
class WPLB_QRZ_Sync {

	const CRON_HOOK = 'wplb_qrz_sync';
	const LOCK      = 'wplb_qrz_sync_lock';

	/**
	 * Errors from deleting QSOs on QRZ.com during this request.
	 *
	 * @var string[]
	 */
	public static $delete_errors = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'add_option_' . WPLB_Settings::OPTION, array( __CLASS__, 'sync_schedule' ), 10, 0 );
		add_action( 'update_option_' . WPLB_Settings::OPTION, array( __CLASS__, 'sync_schedule' ), 10, 0 );
		add_action( 'wplb_before_delete_qsos', array( __CLASS__, 'delete_remote' ) );
	}

	/**
	 * Whether QRZ.com uploads are switched on and at least one logbook can
	 * upload (it has a key of its own or the settings have one).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) WPLB_Settings::get( 'qrz_enabled' ) && WPLB_Logbooks::qrz_logbook_ids();
	}

	/**
	 * API client using the configured key.
	 *
	 * @param string $api_key Optional key to use instead of the stored one.
	 * @return WPLB_QRZ_Client
	 */
	public static function client( $api_key = null ) {
		return new WPLB_QRZ_Client( null === $api_key ? WPLB_Settings::api_key() : $api_key );
	}

	/**
	 * Schedule or clear the hourly background upload to match the settings.
	 */
	public static function sync_schedule() {
		$wanted = WPLB_Settings::get( 'qrz_enabled' ) && WPLB_Settings::get( 'qrz_background_sync' );
		$next   = wp_next_scheduled( self::CRON_HOOK );
		if ( $wanted && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		} elseif ( ! $wanted && $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Remove the background upload event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * WP-Cron: upload QSOs that are waiting.
	 */
	public static function run_cron() {
		if ( ! self::is_enabled() || ! WPLB_Settings::get( 'qrz_background_sync' ) || get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

		/**
		 * Filters how many QSOs one background run uploads.
		 *
		 * @param int $limit QSOs per run.
		 */
		$limit = (int) apply_filters( 'wplb_qrz_cron_batch_size', 50 );
		self::upload_many( WPLB_QSOs::ids_with_qrz_status( array( 'pending', 'modified' ), $limit, WPLB_Logbooks::qrz_logbook_ids() ) );

		delete_transient( self::LOCK );
	}

	/**
	 * Upload one QSO.
	 *
	 * @param array|int $qso QSO row or ID.
	 * @return array {
	 *     @type string $result  uploaded, failed, skipped or error.
	 *     @type string $message Details for the user.
	 *     @type bool   $stop    True when the problem is not specific to this QSO
	 *                           (network, API key), so a batch should stop.
	 * }
	 */
	public static function upload( $qso ) {
		if ( ! is_array( $qso ) ) {
			$qso = WPLB_QSOs::get( (int) $qso );
		}
		if ( ! $qso ) {
			return self::outcome( 'error', __( 'QSO not found.', 'wp-logbook' ) );
		}
		$logbook = (int) $qso['logbook_id'];
		if ( ! WPLB_Logbooks::uploads_to_qrz( $logbook ) ) {
			/* translators: %s: logbook title */
			return self::outcome( 'skipped', sprintf( __( 'The logbook "%s" is not uploaded to QRZ.com.', 'wp-logbook' ), WPLB_Logbooks::title( $logbook ) ) );
		}
		if ( 'skip' === $qso['qrz_status'] ) {
			return self::outcome( 'skipped', __( 'This QSO is marked "Not for QRZ.com".', 'wp-logbook' ) );
		}

		$send = $qso;
		if ( empty( $send['station_callsign'] ) ) {
			$send['station_callsign'] = WPLB_Logbooks::station_callsign( $logbook );
		}
		$client    = self::client( WPLB_Logbooks::qrz_key( $logbook ) );
		$old_logid = (int) $qso['qrz_logid'];
		$replace   = $old_logid > 0 || in_array( $qso['qrz_status'], array( 'uploaded', 'modified' ), true );

		$response = $client->insert( WPLB_ADIF::record( $send, 'qrz' ), $replace );
		if ( is_wp_error( $response ) ) {
			return self::outcome( 'error', $response->get_error_message(), true );
		}

		$result = strtoupper( $response['RESULT'] );
		$reason = isset( $response['REASON'] ) ? $response['REASON'] : '';
		$logid  = isset( $response['LOGID'] ) && ctype_digit( $response['LOGID'] ) ? (int) $response['LOGID'] : 0;
		$now    = current_time( 'mysql', true );

		if ( 'OK' === $result || 'REPLACE' === $result ) {
			$message = '';
			// OK rather than REPLACE means QRZ.com did not see the edited QSO as the
			// same contact (callsign, band, mode or time changed), so it created a
			// new record. Remove the old one so the edit does not leave a stray copy.
			if ( 'OK' === $result && $old_logid && $logid && $old_logid !== $logid ) {
				$deleted = $client->delete( array( $old_logid ) );
				if ( is_wp_error( $deleted ) || ! in_array( strtoupper( $deleted['RESULT'] ), array( 'OK', 'PARTIAL' ), true ) ) {
					/* translators: %d: QRZ.com log ID */
					$message = sprintf( __( 'Uploaded, but the previous copy on QRZ.com (log ID %d) could not be removed.', 'wp-logbook' ), $old_logid );
				}
			}
			WPLB_QSOs::update(
				$qso['id'],
				array(
					'qrz_status'      => 'uploaded',
					'qrz_logid'       => $logid ? $logid : ( $old_logid ? $old_logid : null ),
					'qrz_upload_date' => $now,
					'qrz_message'     => $message,
				),
				false
			);
			return self::outcome( 'uploaded', $message );
		}

		if ( 'AUTH' === $result ) {
			$message = __( 'QRZ.com refused the API key (RESULT=AUTH). Check the key in the Logbook settings; uploading through the API may require a QRZ.com subscription.', 'wp-logbook' );
			return self::outcome( 'error', trim( $message . ' ' . $reason ), true );
		}

		if ( false !== stripos( $reason, 'duplicate' ) ) {
			$message = __( 'Already in the QRZ.com logbook.', 'wp-logbook' );
			WPLB_QSOs::update(
				$qso['id'],
				array(
					'qrz_status'      => 'uploaded',
					'qrz_upload_date' => $now,
					'qrz_message'     => $message,
				),
				false
			);
			return self::outcome( 'uploaded', $message );
		}

		if ( preg_match( '/invalid.{0,12}key|key.{0,12}invalid|not authori[sz]ed|access denied|subscription/i', $reason ) ) {
			/* translators: %s: reason given by QRZ.com */
			return self::outcome( 'error', sprintf( __( 'QRZ.com rejected the request: %s', 'wp-logbook' ), $reason ), true );
		}

		/* translators: %s: RESULT value returned by QRZ.com */
		$message = '' !== $reason ? $reason : sprintf( __( 'QRZ.com answered RESULT=%s.', 'wp-logbook' ), $result );
		WPLB_QSOs::update(
			$qso['id'],
			array(
				'qrz_status'  => 'failed',
				'qrz_message' => mb_substr( $message, 0, 255 ),
			),
			false
		);
		return self::outcome( 'failed', $message );
	}

	/**
	 * Upload several QSOs, stopping at the first problem that is not specific
	 * to one QSO.
	 *
	 * @param int[] $ids QSO IDs.
	 * @return array Counts plus per-QSO error messages.
	 */
	public static function upload_many( array $ids ) {
		$summary = array(
			'processed'    => 0,
			'uploaded'     => 0,
			'failed'       => 0,
			'skipped'      => 0,
			'errors'       => array(),
			'stopped'      => false,
			'stop_message' => '',
		);

		foreach ( WPLB_QSOs::get_many( $ids ) as $qso ) {
			$outcome = self::upload( $qso );
			if ( $outcome['stop'] ) {
				$summary['stopped']      = true;
				$summary['stop_message'] = $outcome['message'];
				break;
			}
			++$summary['processed'];
			if ( 'uploaded' === $outcome['result'] ) {
				++$summary['uploaded'];
			} elseif ( 'failed' === $outcome['result'] ) {
				++$summary['failed'];
				$summary['errors'][] = sprintf( '%s %s UTC: %s', $qso['callsign'], substr( $qso['time_on'], 0, 16 ), $outcome['message'] );
			} else {
				++$summary['skipped'];
			}
		}

		return $summary;
	}

	/**
	 * Upload a QSO right after it was saved, when the settings ask for it.
	 *
	 * @param int $id QSO ID.
	 * @return array|null Outcome of upload(), or null when nothing was sent.
	 */
	public static function maybe_upload_after_save( $id ) {
		if ( ! self::is_enabled() || ! WPLB_Settings::get( 'qrz_auto_upload' ) ) {
			return null;
		}
		$qso = WPLB_QSOs::get( $id );
		if ( ! $qso || ! in_array( $qso['qrz_status'], array( 'pending', 'modified' ), true ) || ! WPLB_Logbooks::uploads_to_qrz( $qso['logbook_id'] ) ) {
			return null;
		}
		return self::upload( $qso );
	}

	/**
	 * Upload state for a QSO after it has been edited.
	 *
	 * @param array $stored Row before the edit.
	 * @param array $edited Row after the edit.
	 * @return string
	 */
	public static function status_after_edit( array $stored, array $edited ) {
		$status = $stored['qrz_status'];

		if ( 'uploaded' === $status && WPLB_Settings::get( 'qrz_sync_edits' ) && WPLB_ADIF::record( $stored, 'qrz' ) !== WPLB_ADIF::record( $edited, 'qrz' ) ) {
			return 'modified';
		}
		if ( 'failed' === $status ) {
			// The edit may have fixed whatever QRZ.com complained about.
			return empty( $stored['qrz_logid'] ) ? 'pending' : 'modified';
		}
		return $status;
	}

	/**
	 * Delete QSOs from QRZ.com before they are deleted locally, when the
	 * settings ask for it (hooked to wplb_before_delete_qsos).
	 *
	 * @param int[] $ids QSO IDs.
	 */
	public static function delete_remote( array $ids ) {
		if ( ! self::is_enabled() || ! WPLB_Settings::get( 'qrz_delete_remote' ) ) {
			return;
		}

		// Each logbook may upload to a different QRZ.com logbook (API key).
		$by_key = array();
		foreach ( WPLB_QSOs::get_many( $ids ) as $qso ) {
			$key = WPLB_Logbooks::qrz_key( $qso['logbook_id'] );
			if ( ! empty( $qso['qrz_logid'] ) && '' !== $key ) {
				$by_key[ $key ][] = (int) $qso['qrz_logid'];
			}
		}

		foreach ( $by_key as $key => $logids ) {
			foreach ( array_chunk( $logids, 100 ) as $chunk ) {
				self::delete_logids( $key, $chunk );
			}
		}
	}

	/**
	 * Delete QSOs from one QRZ.com logbook, noting failures in $delete_errors.
	 *
	 * @param string $key    API key.
	 * @param int[]  $logids QRZ.com log IDs (at most 100).
	 */
	private static function delete_logids( $key, array $logids ) {
		$response = self::client( $key )->delete( $logids );
		if ( is_wp_error( $response ) ) {
			self::$delete_errors[] = $response->get_error_message();
		} elseif ( 'OK' !== strtoupper( $response['RESULT'] ) ) {
			$detail                = isset( $response['REASON'] ) ? $response['REASON'] : $response['RESULT'];
			self::$delete_errors[] = sprintf(
				/* translators: 1: comma separated log IDs, 2: reason */
				__( 'QRZ.com did not delete log IDs %1$s: %2$s', 'wp-logbook' ),
				isset( $response['LOGIDS'] ) && '' !== $response['LOGIDS'] ? $response['LOGIDS'] : implode( ',', $logids ),
				$detail
			);
		}
	}

	/**
	 * Prepare QSOs for a move to another logbook.
	 *
	 * When the target uploads to a different QRZ.com logbook (another API
	 * key), the QRZ.com copy belongs to the old logbook: it is deleted there
	 * when deletions are passed on, and the QSO is queued for the new one.
	 *
	 * @param array[] $qsos    QSO rows.
	 * @param int     $logbook Target logbook ID.
	 */
	public static function before_move( array $qsos, $logbook ) {
		$new_key = WPLB_Logbooks::qrz_key( $logbook );
		$by_key  = array();
		$reset   = array();

		foreach ( $qsos as $qso ) {
			$old_key  = WPLB_Logbooks::qrz_key( $qso['logbook_id'] );
			$was_sent = ! empty( $qso['qrz_logid'] ) || in_array( $qso['qrz_status'], array( 'uploaded', 'modified' ), true );
			if ( ! $was_sent || $old_key === $new_key ) {
				continue;
			}
			$reset[] = (int) $qso['id'];
			if ( ! empty( $qso['qrz_logid'] ) && '' !== $old_key ) {
				$by_key[ $old_key ][] = (int) $qso['qrz_logid'];
			}
		}

		if ( self::is_enabled() && WPLB_Settings::get( 'qrz_delete_remote' ) ) {
			foreach ( $by_key as $key => $logids ) {
				foreach ( array_chunk( $logids, 100 ) as $chunk ) {
					self::delete_logids( $key, $chunk );
				}
			}
		}
		foreach ( $reset as $id ) {
			WPLB_QSOs::update(
				$id,
				array(
					'qrz_status'      => 'pending',
					'qrz_logid'       => null,
					'qrz_upload_date' => null,
					'qrz_message'     => '',
				),
				false
			);
		}
	}

	/**
	 * Build an upload outcome.
	 *
	 * @param string $result  Result code.
	 * @param string $message Message.
	 * @param bool   $stop    Whether a batch should stop.
	 * @return array
	 */
	private static function outcome( $result, $message, $stop = false ) {
		return array(
			'result'  => $result,
			'message' => $message,
			'stop'    => $stop,
		);
	}
}
