<?php
/**
 * ADIF (.adi) reading and writing.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts between QSO rows and ADI records.
 *
 * Output is plain ASCII as the ADI format requires: accented letters are
 * transliterated so that field lengths are unambiguous for every reader,
 * including the QRZ.com Logbook. The parser accepts files whose lengths
 * count either bytes or UTF-8 characters.
 */
class WPLB_ADIF {

	/**
	 * Program name written to PROGRAMID.
	 */
	const PROGRAM_ID = 'WP-Logbook';

	/**
	 * Header for an export file.
	 *
	 * @return string
	 */
	public static function header() {
		$lines = array(
			sprintf( 'ADIF export from WP Logbook %s', WPLB_VERSION ),
			self::field( 'ADIF_VER', WPLB_Fields::ADIF_VERSION ),
			self::field( 'CREATED_TIMESTAMP', gmdate( 'Ymd His' ) ),
			self::field( 'PROGRAMID', self::PROGRAM_ID ),
			self::field( 'PROGRAMVERSION', WPLB_VERSION ),
			'<EOH>',
		);
		return implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * One data specifier.
	 *
	 * @param string $name  Field name.
	 * @param string $value Value (ASCII).
	 * @return string
	 */
	public static function field( $name, $value ) {
		$value = (string) $value;
		return '<' . $name . ':' . strlen( $value ) . '>' . $value;
	}

	/**
	 * One QSO as an ADI record terminated by <EOR>.
	 *
	 * @param array  $qso     QSO row.
	 * @param string $context "file" for exports, "qrz" for QRZ.com uploads.
	 * @return string
	 */
	public static function record( array $qso, $context = 'file' ) {
		$parts = array();
		foreach ( self::fields_for_qso( $qso, $context ) as $name => $value ) {
			if ( '' !== (string) $value ) {
				$parts[] = self::field( $name, $value );
			}
		}
		return implode( ' ', $parts ) . ' <EOR>';
	}

	/**
	 * ADIF field values for a QSO.
	 *
	 * @param array  $qso     QSO row.
	 * @param string $context "file" or "qrz". Fields flagged qrz=false (private
	 *                        notes, email) are left out of QRZ.com uploads.
	 * @return array ADIF name => value.
	 */
	public static function fields_for_qso( array $qso, $context = 'file' ) {
		$out = array();

		foreach ( WPLB_Fields::all() as $key => $field ) {
			if ( 'qrz' === $context && ! $field['qrz'] ) {
				continue;
			}
			$value = isset( $qso[ $key ] ) ? $qso[ $key ] : null;
			if ( null === $value || '' === (string) $value ) {
				continue;
			}

			switch ( $field['type'] ) {
				case 'datetime':
					$timestamp = strtotime( $value . ' UTC' );
					if ( ! $timestamp ) {
						break;
					}
					if ( 'time_on' === $key ) {
						$out['QSO_DATE'] = gmdate( 'Ymd', $timestamp );
						$out['TIME_ON']  = gmdate( 'His', $timestamp );
					} else {
						$out['QSO_DATE_OFF'] = gmdate( 'Ymd', $timestamp );
						$out['TIME_OFF']     = gmdate( 'His', $timestamp );
					}
					break;

				case 'date':
					$out[ $field['adif'] ] = str_replace( '-', '', $value );
					break;

				case 'decimal':
					$out[ $field['adif'] ] = WPLB_Fields::format_number( $value );
					break;

				case 'text':
					$out[ $field['adif'] ] = self::ascii( $value, true );
					break;

				default:
					$out[ $field['adif'] ] = self::ascii( $value );
			}
		}

		if ( 'file' === $context ) {
			$upload_status = array(
				'uploaded' => 'Y',
				'modified' => 'M',
				'skip'     => 'N',
			);
			if ( isset( $qso['qrz_status'], $upload_status[ $qso['qrz_status'] ] ) ) {
				$out['QRZCOM_QSO_UPLOAD_STATUS'] = $upload_status[ $qso['qrz_status'] ];
			}
			if ( ! empty( $qso['qrz_upload_date'] ) ) {
				$out['QRZCOM_QSO_UPLOAD_DATE'] = gmdate( 'Ymd', strtotime( $qso['qrz_upload_date'] . ' UTC' ) );
			}
			// Name the logbook so a backup can be restored into the same logbooks.
			$logbook = ! empty( $qso['logbook_id'] ) && class_exists( 'WPLB_Logbooks' ) ? WPLB_Logbooks::get( $qso['logbook_id'] ) : null;
			if ( $logbook ) {
				$out['APP_WPLOGBOOK_LOGBOOK']      = self::ascii( $logbook['title'] );
				$out['APP_WPLOGBOOK_LOGBOOK_SLUG'] = $logbook['slug'];
			}
		}

		/**
		 * Filters the ADIF fields written for a QSO.
		 *
		 * @param array  $out     ADIF name => value.
		 * @param array  $qso     QSO row.
		 * @param string $context "file" or "qrz".
		 */
		return apply_filters( 'wplb_adif_fields', $out, $qso, $context );
	}

	/**
	 * Reduce a value to printable ASCII.
	 *
	 * @param string $value     Value.
	 * @param bool   $multiline Keep line breaks (as CR LF, for MultilineString fields).
	 * @return string
	 */
	public static function ascii( $value, $multiline = false ) {
		$value = (string) $value;
		if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
			$value = remove_accents( $value );
		}
		if ( $multiline ) {
			$value = preg_replace( '/\r\n|\r|\n/', "\r\n", $value );
			$value = preg_replace( '/[^\x20-\x7E\r\n]/', '', $value );
		} else {
			$value = preg_replace( '/\s+/', ' ', $value );
			$value = preg_replace( '/[^\x20-\x7E]/', '', $value );
		}
		return trim( $value );
	}

	/**
	 * Parse ADI text.
	 *
	 * @param string $text File contents.
	 * @return array { header: array, records: array[] } with upper-case field names.
	 */
	public static function parse( $text ) {
		$text = (string) $text;
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = substr( $text, 3 );
		}

		$length  = strlen( $text );
		$pos     = 0;
		$header  = array();
		$records = array();
		$current = array();

		while ( $pos < $length ) {
			$open = strpos( $text, '<', $pos );
			if ( false === $open ) {
				break;
			}
			$close = strpos( $text, '>', $open + 1 );
			if ( false === $close ) {
				break;
			}

			$parts = explode( ':', substr( $text, $open + 1, $close - $open - 1 ) );
			$name  = strtoupper( trim( $parts[0] ) );

			if ( 'EOH' === $name ) {
				$header  = $current;
				$current = array();
				$pos     = $close + 1;
				continue;
			}
			if ( 'EOR' === $name ) {
				if ( $current ) {
					$records[] = $current;
				}
				$current = array();
				$pos     = $close + 1;
				continue;
			}
			if ( count( $parts ) < 2 || ! preg_match( '/^[A-Z_][A-Z0-9_-]*$/', $name ) || ! ctype_digit( trim( $parts[1] ) ) ) {
				// A stray "<" in free text: keep scanning just after it.
				$pos = $open + 1;
				continue;
			}

			list( $value, $pos ) = self::read_value( $text, $close + 1, (int) trim( $parts[1] ) );
			if ( ! self::is_utf8( $value ) ) {
				$value = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $value, 'UTF-8', 'ISO-8859-1' ) : wp_check_invalid_utf8( $value, true );
			}
			$current[ $name ] = $value;
		}

		// Be forgiving about a missing <EOR> after the last record.
		if ( isset( $current['CALL'] ) ) {
			$records[] = $current;
		}

		return array(
			'header'  => $header,
			'records' => $records,
		);
	}

	/**
	 * Read a field value of the given length.
	 *
	 * The ADIF length counts characters, which for ASCII is the byte count.
	 * Writers disagree once UTF-8 sneaks in, so both readings are tried and
	 * the one that ends right before the next tag wins.
	 *
	 * @param string $text   File contents.
	 * @param int    $pos    Offset of the value.
	 * @param int    $length Declared length.
	 * @return array array( value, offset after the value ).
	 */
	private static function read_value( $text, $pos, $length ) {
		$bytes = (string) substr( $text, $pos, $length );
		$next  = $pos + strlen( $bytes );

		if ( ! preg_match( '/[\x80-\xFF]/', $bytes ) ) {
			return array( $bytes, $next );
		}
		if ( self::is_utf8( $bytes ) && self::at_tag_boundary( $text, $next ) ) {
			return array( $bytes, $next );
		}

		$chars = mb_substr( (string) substr( $text, $pos, $length * 4 ), 0, $length, 'UTF-8' );
		// mb_substr() replaces invalid bytes with "?", so make sure it read the file as-is.
		$exact = substr( $text, $pos, strlen( $chars ) ) === $chars;
		if ( $exact && self::is_utf8( $chars ) && self::at_tag_boundary( $text, $pos + strlen( $chars ) ) ) {
			return array( $chars, $pos + strlen( $chars ) );
		}

		return array( $bytes, $next );
	}

	/**
	 * Whether only whitespace separates an offset from the next tag.
	 *
	 * @param string $text Text.
	 * @param int    $pos  Offset.
	 * @return bool
	 */
	private static function at_tag_boundary( $text, $pos ) {
		$rest = ltrim( (string) substr( $text, $pos, 64 ) );
		return '' === $rest || '<' === $rest[0];
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_utf8( $value ) {
		return 1 === preg_match( '//u', (string) $value );
	}

	/**
	 * Map an ADIF record to unsanitised QSO values.
	 *
	 * Besides the plain field mapping this repairs common quirks of other
	 * loggers: submodes written as modes (MODE=USB, MODE=FT4), "_" in place of
	 * "/" in callsigns, and frequencies in kHz.
	 *
	 * @param array $record Parsed record (upper-case field names).
	 * @return array Column => raw value, plus qrz_status/qrz_logid/qrz_upload_date when known
	 *               and _logbook (title, slug) for files exported by this plugin.
	 */
	public static function record_to_qso( array $record ) {
		$qso = array();

		foreach ( WPLB_Fields::all() as $key => $field ) {
			if ( 'datetime' !== $field['type'] && isset( $record[ $field['adif'] ] ) ) {
				$qso[ $key ] = trim( $record[ $field['adif'] ] );
			}
		}
		if ( empty( $qso['state'] ) && ! empty( $record['VE_PROV'] ) ) {
			$qso['state'] = $record['VE_PROV'];
		}

		$date           = isset( $record['QSO_DATE'] ) ? $record['QSO_DATE'] : '';
		$qso['time_on'] = self::datetime( $date, isset( $record['TIME_ON'] ) ? $record['TIME_ON'] : '' );
		if ( ! empty( $record['TIME_OFF'] ) ) {
			$off = self::datetime( ! empty( $record['QSO_DATE_OFF'] ) ? $record['QSO_DATE_OFF'] : $date, $record['TIME_OFF'] );
			// Without QSO_DATE_OFF a contact that ends after midnight looks like it ends before it starts.
			if ( $off && $qso['time_on'] && empty( $record['QSO_DATE_OFF'] ) && $off < $qso['time_on'] ) {
				$off = gmdate( 'Y-m-d H:i:s', strtotime( $off . ' UTC' ) + DAY_IN_SECONDS );
			}
			$qso['time_off'] = $off;
		}

		$mode    = strtoupper( isset( $qso['mode'] ) ? $qso['mode'] : '' );
		$submode = strtoupper( isset( $qso['submode'] ) ? $qso['submode'] : '' );
		if ( '' !== $mode && ! array_key_exists( $mode, WPLB_Fields::adif_modes() ) ) {
			$parent = WPLB_Fields::submode_parent( $mode );
			if ( '' !== $parent ) {
				$submode = '' === $submode ? $mode : $submode;
				$mode    = $parent;
			}
		}
		$qso['mode']    = $mode;
		$qso['submode'] = $submode;

		foreach ( array( 'callsign', 'station_callsign', 'operator' ) as $key ) {
			if ( isset( $qso[ $key ] ) ) {
				$qso[ $key ] = str_replace( '_', '/', $qso[ $key ] );
			}
		}

		foreach ( array( 'qsl_rcvd', 'lotw_qsl_rcvd', 'eqsl_qsl_rcvd' ) as $key ) {
			if ( isset( $qso[ $key ] ) && 'V' === strtoupper( $qso[ $key ] ) ) {
				$qso[ $key ] = 'Y';
			}
		}

		if ( ! empty( $qso['freq'] ) && ! empty( $qso['band'] ) && is_numeric( $qso['freq'] ) ) {
			$band = strtolower( $qso['band'] );
			if ( WPLB_Fields::band_for_freq( $qso['freq'] ) !== $band && WPLB_Fields::band_for_freq( $qso['freq'] / 1000 ) === $band ) {
				$qso['freq'] = (string) ( $qso['freq'] / 1000 );
			}
		}

		$upload_status = array(
			'Y' => 'uploaded',
			'M' => 'modified',
			'N' => 'skip',
		);
		$status        = isset( $record['QRZCOM_QSO_UPLOAD_STATUS'] ) ? strtoupper( trim( $record['QRZCOM_QSO_UPLOAD_STATUS'] ) ) : '';
		if ( isset( $upload_status[ $status ] ) ) {
			$qso['qrz_status'] = $upload_status[ $status ];
		}
		if ( ! empty( $record['APP_QRZLOG_LOGID'] ) && ctype_digit( trim( $record['APP_QRZLOG_LOGID'] ) ) ) {
			$qso['qrz_logid']  = (int) $record['APP_QRZLOG_LOGID'];
			$qso['qrz_status'] = isset( $qso['qrz_status'] ) ? $qso['qrz_status'] : 'uploaded';
		}
		if ( ! empty( $record['QRZCOM_QSO_UPLOAD_DATE'] ) && WPLB_Fields::normalize_date( $record['QRZCOM_QSO_UPLOAD_DATE'] ) ) {
			$qso['qrz_upload_date'] = WPLB_Fields::normalize_date( $record['QRZCOM_QSO_UPLOAD_DATE'] ) . ' 00:00:00';
		}
		if ( ! empty( $record['APP_WPLOGBOOK_LOGBOOK'] ) ) {
			$qso['_logbook'] = array(
				'title' => trim( $record['APP_WPLOGBOOK_LOGBOOK'] ),
				'slug'  => isset( $record['APP_WPLOGBOOK_LOGBOOK_SLUG'] ) ? trim( $record['APP_WPLOGBOOK_LOGBOOK_SLUG'] ) : '',
			);
		}

		return $qso;
	}

	/**
	 * Combine an ADIF date and time.
	 *
	 * @param string $date YYYYMMDD.
	 * @param string $time HHMM or HHMMSS.
	 * @return string "Y-m-d H:i:s", or an empty string when invalid.
	 */
	private static function datetime( $date, $time ) {
		$date = WPLB_Fields::normalize_date( $date );
		$time = preg_replace( '/[^0-9]/', '', (string) $time );
		if ( ! $date || ! preg_match( '/^\d{4}(\d{2})?$/', $time ) ) {
			return '';
		}
		$value = WPLB_Fields::normalize_datetime( sprintf( '%s %s:%s:%s', $date, substr( $time, 0, 2 ), substr( $time, 2, 2 ), strlen( $time ) > 4 ? substr( $time, 4, 2 ) : '00' ) );
		return $value ? $value : '';
	}
}
