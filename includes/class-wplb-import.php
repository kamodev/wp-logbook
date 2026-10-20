<?php
/**
 * ADIF import.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the QSOs of an ADIF file to a logbook.
 */
class WPLB_Import {

	/**
	 * Maximum number of per-record errors kept in the summary.
	 */
	const MAX_ERRORS = 25;

	/**
	 * Import ADI text.
	 *
	 * @param string $text    File contents.
	 * @param array  $options {
	 *     Optional.
	 *
	 *     @type int    $logbook          Logbook that receives the QSOs (default logbook when 0).
	 *     @type bool   $file_logbooks    Put QSOs exported by WP Logbook back into the logbook named
	 *                                    in the file (APP_WPLOGBOOK_LOGBOOK), creating it if needed.
	 *     @type bool   $skip_duplicates  Skip QSOs already in the target logbook (same callsign, band
	 *                                    and mode within a minute).
	 *     @type bool   $mark_uploaded    Treat imported QSOs as already in the QRZ.com logbook.
	 *     @type string $station_callsign Station callsign for records that have none; when empty the
	 *                                    logbook's (or the settings') station callsign is used.
	 * }
	 * @return array Summary: records, imported, duplicates, failed, errors, logbooks (ID => QSOs imported).
	 */
	public static function adif( $text, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'logbook'          => 0,
				'file_logbooks'    => false,
				'skip_duplicates'  => true,
				'mark_uploaded'    => false,
				'station_callsign' => '',
			)
		);
		$default = WPLB_Logbooks::get( $options['logbook'] ) ? (int) $options['logbook'] : WPLB_Logbooks::default_id();

		$parsed  = WPLB_ADIF::parse( $text );
		$summary = array(
			'records'    => count( $parsed['records'] ),
			'imported'   => 0,
			'duplicates' => 0,
			'failed'     => 0,
			'errors'     => array(),
			'logbooks'   => array(),
		);
		$found   = array();

		foreach ( $parsed['records'] as $index => $record ) {
			$raw                   = WPLB_ADIF::record_to_qso( $record );
			list( $data, $errors ) = WPLB_Fields::sanitize( $raw );

			$logbook = $default;
			if ( $options['file_logbooks'] && isset( $raw['_logbook'] ) ) {
				$key = $raw['_logbook']['slug'] . '|' . $raw['_logbook']['title'];
				if ( ! isset( $found[ $key ] ) ) {
					$found[ $key ] = self::file_logbook( $raw['_logbook'], $default );
				}
				$logbook = $found[ $key ];
			}
			$data['logbook_id'] = $logbook;

			if ( empty( $data['station_callsign'] ) ) {
				$data['station_callsign'] = '' !== $options['station_callsign'] ? $options['station_callsign'] : WPLB_Logbooks::station_callsign( $logbook );
			}
			$errors += WPLB_Fields::validate( $data );

			// Keep QSOs whose frequency sits just outside the band edge; the band is what counts.
			if ( isset( $errors['freq'] ) && ! empty( $data['band'] ) ) {
				unset( $errors['freq'] );
			}

			if ( $errors ) {
				self::fail(
					$summary,
					sprintf(
						/* translators: 1: record number, 2: callsign, 3: error messages */
						__( 'Record %1$d (%2$s): %3$s', 'wp-logbook' ),
						$index + 1,
						isset( $record['CALL'] ) ? $record['CALL'] : '?',
						implode( ' ', $errors )
					)
				);
				continue;
			}

			if ( $options['skip_duplicates'] && WPLB_QSOs::find_duplicate( $data['callsign'], $data['time_on'], $data['band'], $data['mode'], 0, $logbook ) ) {
				++$summary['duplicates'];
				continue;
			}

			foreach ( array( 'qrz_status', 'qrz_logid', 'qrz_upload_date' ) as $key ) {
				if ( isset( $raw[ $key ] ) ) {
					$data[ $key ] = $raw[ $key ];
				}
			}
			if ( $options['mark_uploaded'] && ( empty( $data['qrz_status'] ) || 'pending' === $data['qrz_status'] ) ) {
				$data['qrz_status'] = 'uploaded';
			}

			$id = WPLB_QSOs::insert( $data );
			if ( is_wp_error( $id ) ) {
				/* translators: 1: record number, 2: error message */
				self::fail( $summary, sprintf( __( 'Record %1$d: %2$s', 'wp-logbook' ), $index + 1, $id->get_error_message() ) );
				continue;
			}
			++$summary['imported'];
			$summary['logbooks'][ $logbook ] = ( isset( $summary['logbooks'][ $logbook ] ) ? $summary['logbooks'][ $logbook ] : 0 ) + 1;
		}

		return $summary;
	}

	/**
	 * The logbook named in an exported file, created when missing.
	 *
	 * @param array $named    Title and slug from the file.
	 * @param int   $fallback Logbook used when it cannot be created.
	 * @return int Logbook ID.
	 */
	private static function file_logbook( array $named, $fallback ) {
		$id = '' !== $named['slug'] ? WPLB_Logbooks::resolve( $named['slug'] ) : 0;
		if ( ! $id && '' !== $named['title'] ) {
			$id = WPLB_Logbooks::resolve( $named['title'] );
		}
		if ( ! $id && '' !== $named['title'] ) {
			$id = WPLB_Logbooks::create(
				array(
					'title' => $named['title'],
					'slug'  => $named['slug'],
				)
			);
			$id = is_wp_error( $id ) ? 0 : $id;
		}
		return $id ? $id : $fallback;
	}

	/**
	 * Count a failed record and keep its message.
	 *
	 * @param array  $summary Import summary (modified in place).
	 * @param string $message Error.
	 */
	private static function fail( array &$summary, $message ) {
		++$summary['failed'];
		if ( count( $summary['errors'] ) < self::MAX_ERRORS ) {
			$summary['errors'][] = $message;
		}
	}
}
