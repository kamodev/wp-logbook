<?php
/**
 * Logbooks.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Named logbooks that QSOs belong to.
 *
 * Every QSO is in exactly one logbook. A logbook can set its own station
 * callsign (pre-filled on new QSOs) and its own QRZ.com Logbook API key,
 * because QRZ.com also keeps one logbook (and key) per callsign. Without a
 * key of its own a logbook uploads with the key from the settings.
 */
class WPLB_Logbooks {

	const DEFAULT_OPTION = 'wplb_default_logbook';

	/**
	 * Logbooks keyed by ID, loaded once per request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wplb_logbooks';
	}

	/**
	 * All logbooks, sorted by title.
	 *
	 * @return array[] ID => row.
	 */
	public static function all() {
		global $wpdb;
		if ( null === self::$cache ) {
			$table       = self::table();
			$rows        = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			self::$cache = array();
			foreach ( (array) $rows as $row ) {
				self::$cache[ (int) $row['id'] ] = $row;
			}
		}
		return self::$cache;
	}

	/**
	 * Forget the loaded logbooks after a change.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * One logbook.
	 *
	 * @param int $id Logbook ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Title of a logbook.
	 *
	 * @param int $id Logbook ID.
	 * @return string
	 */
	public static function title( $id ) {
		$logbook = self::get( $id );
		return $logbook ? $logbook['title'] : '';
	}

	/**
	 * Find a logbook by ID, slug or title.
	 *
	 * @param string|int $ref Reference.
	 * @return int Logbook ID or 0.
	 */
	public static function resolve( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return 0;
		}
		if ( ctype_digit( $ref ) && self::get( (int) $ref ) ) {
			return (int) $ref;
		}
		$slug = sanitize_title( $ref );
		foreach ( self::all() as $id => $logbook ) {
			if ( $slug === $logbook['slug'] || 0 === strcasecmp( $logbook['title'], $ref ) ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Find several logbooks from a comma separated list.
	 *
	 * @param string $refs IDs, slugs or titles.
	 * @return int[] Logbook IDs; array( 0 ) when none of them exists, so a
	 *               query using the result matches nothing.
	 */
	public static function resolve_list( $refs ) {
		$ids = array();
		foreach ( explode( ',', (string) $refs ) as $ref ) {
			$id = self::resolve( $ref );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return $ids ? array_values( array_unique( $ids ) ) : array( 0 );
	}

	/**
	 * The logbook used when none is chosen; created when there is none.
	 *
	 * @return int
	 */
	public static function default_id() {
		$id = (int) get_option( self::DEFAULT_OPTION, 0 );
		if ( $id && self::get( $id ) ) {
			return $id;
		}
		$all = self::all();
		if ( $all ) {
			$id = (int) key( $all );
		} else {
			$id = self::create( array( 'title' => __( 'My logbook', 'wp-logbook' ) ) );
			$id = is_wp_error( $id ) ? 0 : $id;
		}
		if ( $id ) {
			update_option( self::DEFAULT_OPTION, $id, false );
		}
		return $id;
	}

	/**
	 * Make sure a default logbook exists and holds every QSO without one
	 * (the QSOs logged before logbooks existed).
	 */
	public static function ensure_default() {
		global $wpdb;
		$id    = self::default_id();
		$table = WPLB_QSOs::table();
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET logbook_id = %d WHERE logbook_id = 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Number of QSOs in each logbook.
	 *
	 * @return int[] ID => count.
	 */
	public static function counts() {
		global $wpdb;
		$table  = WPLB_QSOs::table();
		$rows   = $wpdb->get_results( "SELECT logbook_id, COUNT(*) AS total FROM {$table} GROUP BY logbook_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts = array_fill_keys( array_keys( self::all() ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['logbook_id'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Clean submitted logbook fields.
	 *
	 * @param array $input Submitted values.
	 * @param int   $id    Logbook being edited, 0 for a new one.
	 * @return array array( values, errors ).
	 */
	public static function sanitize( array $input, $id = 0 ) {
		$data   = array();
		$errors = array();

		$title = isset( $input['title'] ) ? WPLB_Fields::plain_text( $input['title'] ) : '';
		$title = mb_substr( $title, 0, 100 );
		if ( '' === $title ) {
			$errors['title'] = __( 'Give the logbook a title.', 'wp-logbook' );
		} else {
			foreach ( self::all() as $other_id => $other ) {
				if ( (int) $other_id !== (int) $id && 0 === strcasecmp( $other['title'], $title ) ) {
					/* translators: %s: logbook title */
					$errors['title'] = sprintf( __( 'There is already a logbook called "%s".', 'wp-logbook' ), $title );
				}
			}
		}
		$data['title'] = $title;

		$slug         = isset( $input['slug'] ) && '' !== trim( (string) $input['slug'] ) ? $input['slug'] : $title;
		$data['slug'] = self::unique_slug( sanitize_title( $slug ), $id );

		$data['description'] = isset( $input['description'] ) ? WPLB_Fields::plain_text( $input['description'], true ) : '';

		$station = isset( $input['station_callsign'] ) ? trim( (string) $input['station_callsign'] ) : '';
		if ( '' !== $station && false === WPLB_Fields::normalize_callsign( $station ) ) {
			$errors['station_callsign'] = __( 'The station callsign is not a valid callsign.', 'wp-logbook' );
			$station                    = '';
		}
		$data['station_callsign'] = '' === $station ? '' : WPLB_Fields::normalize_callsign( $station );

		$data['qrz_upload'] = empty( $input['qrz_upload'] ) ? 0 : 1;

		$key = isset( $input['qrz_api_key'] ) ? preg_replace( '/[^A-Za-z0-9-]/', '', (string) $input['qrz_api_key'] ) : '';
		if ( ! empty( $input['qrz_api_key_remove'] ) ) {
			$data['qrz_api_key'] = '';
		} elseif ( '' !== $key ) {
			$data['qrz_api_key'] = $key;
		}

		return array( $data, $errors );
	}

	/**
	 * A slug that no other logbook uses.
	 *
	 * @param string $slug Wanted slug.
	 * @param int    $id   Logbook that may keep it.
	 * @return string
	 */
	public static function unique_slug( $slug, $id = 0 ) {
		$slug = '' !== $slug ? substr( $slug, 0, 90 ) : 'logbook';
		$base = $slug;
		$used = array();
		foreach ( self::all() as $other_id => $other ) {
			if ( (int) $other_id !== (int) $id ) {
				$used[] = $other['slug'];
			}
		}
		$n = 2;
		while ( in_array( $slug, $used, true ) || ctype_digit( $slug ) ) {
			$slug = $base . '-' . $n;
			++$n;
		}
		return $slug;
	}

	/**
	 * Add a logbook.
	 *
	 * @param array $input Values; run through sanitize().
	 * @return int|WP_Error New logbook ID.
	 */
	public static function create( array $input ) {
		global $wpdb;

		list( $data, $errors ) = self::sanitize( array_merge( array( 'qrz_upload' => 1 ), $input ) );
		if ( $errors ) {
			return new WP_Error( 'wplb_logbook_invalid', implode( ' ', $errors ) );
		}
		$data['created_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->insert( self::table(), $data, array_fill( 0, count( $data ), '%s' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'wplb_db_error', trim( __( 'The logbook could not be saved.', 'wp-logbook' ) . ' ' . $wpdb->last_error ) );
		}
		$id = (int) $wpdb->insert_id;
		self::flush();

		/**
		 * Fires after a logbook has been added.
		 *
		 * @param int $id Logbook ID.
		 */
		do_action( 'wplb_logbook_created', $id );

		return $id;
	}

	/**
	 * Change a logbook.
	 *
	 * @param int   $id    Logbook ID.
	 * @param array $input Values to change, run through sanitize(); the others are kept.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $input ) {
		global $wpdb;

		$current = self::get( $id );
		if ( ! $current ) {
			return new WP_Error( 'wplb_logbook_missing', __( 'That logbook does not exist.', 'wp-logbook' ) );
		}
		$input                 = array_merge( array_intersect_key( $current, array_flip( array( 'title', 'slug', 'description', 'station_callsign', 'qrz_upload' ) ) ), $input );
		list( $data, $errors ) = self::sanitize( $input, $id );
		if ( $errors ) {
			return new WP_Error( 'wplb_logbook_invalid', implode( ' ', $errors ) );
		}

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'wplb_db_error', trim( __( 'The logbook could not be saved.', 'wp-logbook' ) . ' ' . $wpdb->last_error ) );
		}
		self::flush();
		return true;
	}

	/**
	 * Delete a logbook, moving its QSOs to another logbook or deleting them.
	 *
	 * @param int $id      Logbook ID.
	 * @param int $move_to Logbook that receives the QSOs; 0 deletes them.
	 * @return true|WP_Error
	 */
	public static function delete( $id, $move_to = 0 ) {
		global $wpdb;

		$id      = (int) $id;
		$move_to = (int) $move_to;
		if ( ! self::get( $id ) ) {
			return new WP_Error( 'wplb_logbook_missing', __( 'That logbook does not exist.', 'wp-logbook' ) );
		}
		if ( count( self::all() ) < 2 ) {
			return new WP_Error( 'wplb_logbook_last', __( 'The last logbook cannot be deleted.', 'wp-logbook' ) );
		}
		if ( $move_to && ( $move_to === $id || ! self::get( $move_to ) ) ) {
			return new WP_Error( 'wplb_logbook_target', __( 'Choose another logbook for the QSOs.', 'wp-logbook' ) );
		}

		$qsos = WPLB_QSOs::table();
		$ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$qsos} WHERE logbook_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			if ( $move_to ) {
				self::move_qsos( $chunk, $move_to );
			} else {
				WPLB_QSOs::delete( $chunk );
			}
		}

		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();
		if ( (int) get_option( self::DEFAULT_OPTION ) === $id ) {
			update_option( self::DEFAULT_OPTION, $move_to ? $move_to : (int) key( self::all() ), false );
		}

		/**
		 * Fires after a logbook has been deleted.
		 *
		 * @param int $id      Logbook ID.
		 * @param int $move_to Logbook that received its QSOs, or 0 when they were deleted.
		 */
		do_action( 'wplb_logbook_deleted', $id, $move_to );

		return true;
	}

	/**
	 * Move QSOs to another logbook.
	 *
	 * QSOs that were on QRZ.com are queued again when the target logbook
	 * uploads to a different QRZ.com logbook (another API key).
	 *
	 * @param int[] $ids     QSO IDs.
	 * @param int   $logbook Target logbook ID.
	 * @return int QSOs moved.
	 */
	public static function move_qsos( array $ids, $logbook ) {
		$logbook = (int) $logbook;
		if ( ! self::get( $logbook ) ) {
			return 0;
		}
		$qsos = array_filter(
			WPLB_QSOs::get_many( $ids ),
			function ( $qso ) use ( $logbook ) {
				return (int) $qso['logbook_id'] !== $logbook;
			}
		);
		if ( ! $qsos ) {
			return 0;
		}
		WPLB_QRZ_Sync::before_move( $qsos, $logbook );
		return WPLB_QSOs::move( wp_list_pluck( $qsos, 'id' ), $logbook );
	}

	/**
	 * Station callsign for new QSOs in a logbook.
	 *
	 * @param int $id Logbook ID.
	 * @return string The logbook's own, or the one from the settings.
	 */
	public static function station_callsign( $id ) {
		$logbook = self::get( $id );
		if ( $logbook && '' !== $logbook['station_callsign'] ) {
			return $logbook['station_callsign'];
		}
		return (string) WPLB_Settings::get( 'station_callsign' );
	}

	/**
	 * QRZ.com Logbook API key used for a logbook.
	 *
	 * @param int $id Logbook ID.
	 * @return string The logbook's own key, or the key from the settings.
	 */
	public static function qrz_key( $id ) {
		$logbook = self::get( $id );
		if ( $logbook && '' !== $logbook['qrz_api_key'] ) {
			return $logbook['qrz_api_key'];
		}
		return WPLB_Settings::api_key();
	}

	/**
	 * Whether the QSOs of a logbook go to QRZ.com.
	 *
	 * @param int $id Logbook ID.
	 * @return bool
	 */
	public static function uploads_to_qrz( $id ) {
		$logbook = self::get( $id );
		return $logbook && $logbook['qrz_upload'] && '' !== self::qrz_key( $id );
	}

	/**
	 * Logbooks whose QSOs go to QRZ.com.
	 *
	 * @return int[]
	 */
	public static function qrz_logbook_ids() {
		self::default_id(); // Recreates a logbook if the table was emptied.
		return array_values( array_filter( array_keys( self::all() ), array( __CLASS__, 'uploads_to_qrz' ) ) );
	}

	/**
	 * Logbooks as ID => title, for menus.
	 *
	 * @return string[]
	 */
	public static function choices() {
		return wp_list_pluck( self::all(), 'title' );
	}
}
