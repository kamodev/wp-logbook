<?php
/**
 * QSO storage.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access for the QSO table.
 *
 * Callers pass values that went through WPLB_Fields::sanitize(); this class
 * only restricts writes to known columns and builds the SQL.
 */
class WPLB_QSOs {

	/**
	 * Columns that can be sorted on.
	 *
	 * @var string[]
	 */
	const SORTABLE = array( 'time_on', 'callsign', 'freq', 'mode', 'name', 'country', 'gridsquare', 'qrz_status', 'id' );

	/**
	 * Columns searched by the search box.
	 *
	 * @var string[]
	 */
	const SEARCHABLE = array( 'callsign', 'name', 'qth', 'country', 'state', 'gridsquare', 'comment', 'notes', 'sota_ref', 'pota_ref', 'wwff_ref', 'contest_id' );

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wplb_qsos';
	}

	/**
	 * QRZ.com upload states.
	 *
	 * @return array Status => label.
	 */
	public static function qrz_statuses() {
		return array(
			'pending'  => __( 'Not uploaded', 'wp-logbook' ),
			'uploaded' => __( 'On QRZ.com', 'wp-logbook' ),
			'modified' => __( 'Changed since upload', 'wp-logbook' ),
			'failed'   => __( 'Upload failed', 'wp-logbook' ),
			'skip'     => __( 'Not for QRZ.com', 'wp-logbook' ),
		);
	}

	/**
	 * All writable columns.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array_merge(
			array_keys( WPLB_Fields::all() ),
			array( 'logbook_id', 'qrz_status', 'qrz_logid', 'qrz_upload_date', 'qrz_message', 'created_at', 'updated_at' )
		);
	}

	/**
	 * Restrict a row to known columns and work out the wpdb formats.
	 *
	 * @param array $data Column => value.
	 * @return array array( row, formats ).
	 */
	private static function prepare_row( array $data ) {
		$row     = array_intersect_key( $data, array_flip( self::columns() ) );
		$formats = array();
		foreach ( $row as $key => $value ) {
			$field     = WPLB_Fields::get( $key );
			$is_int    = in_array( $key, array( 'qrz_logid', 'logbook_id' ), true ) || ( $field && 'int' === $field['type'] );
			$formats[] = $is_int ? '%d' : '%s';
		}
		return array( $row, $formats );
	}

	/**
	 * One QSO.
	 *
	 * @param int $id QSO ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/**
	 * Several QSOs, oldest first.
	 *
	 * @param int[] $ids QSO IDs.
	 * @return array[]
	 */
	public static function get_many( array $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$result = self::query(
			array(
				'ids'      => $ids,
				'orderby'  => 'time_on',
				'order'    => 'ASC',
				'per_page' => 0,
				'count'    => false,
			)
		);
		return $result['items'];
	}

	/**
	 * Add a QSO.
	 *
	 * @param array $data Sanitised values.
	 * @return int|WP_Error New QSO ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = wp_parse_args( $data, array( 'qrz_status' => 'pending' ) );
		if ( empty( $data['logbook_id'] ) || ! WPLB_Logbooks::get( $data['logbook_id'] ) ) {
			$data['logbook_id'] = WPLB_Logbooks::default_id();
		}
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		list( $row, $formats ) = self::prepare_row( $data );
		if ( false === $wpdb->insert( self::table(), $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'wplb_db_error', trim( __( 'The QSO could not be saved.', 'wp-logbook' ) . ' ' . $wpdb->last_error ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a QSO has been added to the logbook.
		 *
		 * @param int   $id  QSO ID.
		 * @param array $row Stored values.
		 */
		do_action( 'wplb_qso_inserted', $id, $row );

		return $id;
	}

	/**
	 * Change a QSO.
	 *
	 * @param int   $id    QSO ID.
	 * @param array $data  Sanitised values to change.
	 * @param bool  $touch Whether to bump updated_at (false for bookkeeping).
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data, $touch = true ) {
		global $wpdb;

		if ( $touch ) {
			$data['updated_at'] = current_time( 'mysql', true );
		}
		list( $row, $formats ) = self::prepare_row( $data );
		if ( ! $row ) {
			return true;
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'wplb_db_error', trim( __( 'The QSO could not be saved.', 'wp-logbook' ) . ' ' . $wpdb->last_error ) );
		}

		if ( $touch ) {
			/**
			 * Fires after a QSO has been edited.
			 *
			 * @param int   $id  QSO ID.
			 * @param array $row Changed values.
			 */
			do_action( 'wplb_qso_updated', (int) $id, $row );
		}

		return true;
	}

	/**
	 * Delete QSOs.
	 *
	 * @param int[] $ids QSO IDs.
	 * @return int Number deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		/**
		 * Fires before QSOs are deleted.
		 *
		 * @param int[] $ids QSO IDs.
		 */
		do_action( 'wplb_before_delete_qsos', $ids );

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return (int) $deleted;
	}

	/**
	 * Find QSOs.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int|int[]    $logbook          One or more logbook IDs.
	 *     @type string       $search           Text matched against the SEARCHABLE columns.
	 *     @type string       $callsign         Exact callsign, also matching portable forms such as K1ABC/P.
	 *     @type string       $band             ADIF band.
	 *     @type string       $mode             "MODE|SUBMODE" for an exact pair, or a single value matching mode or submode.
	 *     @type string|array $qrz_status       One or more QRZ upload states.
	 *     @type string       $station_callsign Station callsign used for the QSO.
	 *     @type string       $date_from        Y-m-d, inclusive.
	 *     @type string       $date_to          Y-m-d, inclusive.
	 *     @type string       $callsign_starts  Callsigns starting with this text, also after a prefix (VE3/K1ABC).
	 *     @type array        $equals           Column => value, exact match.
	 *     @type array        $prefix           Column => value, starts with.
	 *     @type array        $contains         Column => value, contains.
	 *     @type string       $confirmed        "yes" or "no": confirmed by QSL card, LoTW or eQSL.
	 *     @type int[]        $ids              Limit to these IDs.
	 *     @type int          $exclude          Leave out this ID.
	 *     @type string       $orderby          One of SORTABLE.
	 *     @type string       $order            ASC or DESC.
	 *     @type int          $per_page         Page size, 0 for no limit.
	 *     @type int          $page             Page number.
	 *     @type bool         $count            Whether to count all matches.
	 * }
	 * @return array { items: array[], total: int|null }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'orderby'  => 'time_on',
				'order'    => 'DESC',
				'per_page' => 50,
				'page'     => 1,
				'count'    => true,
			)
		);

		list( $where, $params ) = self::where( $args );

		$table   = self::table();
		$orderby = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'time_on';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql     = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id {$order}";

		$query_params = $params;
		if ( (int) $args['per_page'] > 0 ) {
			$sql           .= ' LIMIT %d OFFSET %d';
			$query_params[] = (int) $args['per_page'];
			$query_params[] = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
		}

		$items = $wpdb->get_results( $query_params ? $wpdb->prepare( $sql, $query_params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$total = null;
		if ( $args['count'] ) {
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
			$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Build the WHERE clause for query().
	 *
	 * @param array $args Query arguments.
	 * @return array array( SQL with placeholders, values ).
	 */
	private static function where( array $args ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['logbook'] ) ) {
			$logbooks = array_values( array_map( 'intval', (array) $args['logbook'] ) );
			$where[]  = 'logbook_id IN (' . implode( ',', array_fill( 0, count( $logbooks ), '%d' ) ) . ')';
			$params   = array_merge( $params, $logbooks );
		}

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(' . implode( ' LIKE %s OR ', self::SEARCHABLE ) . ' LIKE %s)';
			$params  = array_merge( $params, array_fill( 0, count( self::SEARCHABLE ), $like ) );
		}

		if ( ! empty( $args['callsign'] ) ) {
			$call    = strtoupper( $args['callsign'] );
			$escaped = $wpdb->esc_like( $call );
			$where[] = '(callsign = %s OR callsign LIKE %s OR callsign LIKE %s OR callsign LIKE %s)';
			array_push( $params, $call, $escaped . '/%', '%/' . $escaped, '%/' . $escaped . '/%' );
		}

		if ( ! empty( $args['band'] ) ) {
			$where[]  = 'band = %s';
			$params[] = strtolower( $args['band'] );
		}

		if ( ! empty( $args['mode'] ) ) {
			list( $mode, $submode ) = WPLB_Fields::parse_mode_value( $args['mode'] );
			if ( false !== strpos( (string) $args['mode'], '|' ) ) {
				$where[] = '(mode = %s AND submode = %s)';
				array_push( $params, $mode, $submode );
			} else {
				$where[] = '(mode = %s OR submode = %s)';
				array_push( $params, $mode, $mode );
			}
		}

		if ( ! empty( $args['qrz_status'] ) ) {
			$statuses = array_values( (array) $args['qrz_status'] );
			$where[]  = 'qrz_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params   = array_merge( $params, $statuses );
		}

		if ( ! empty( $args['station_callsign'] ) ) {
			$where[]  = 'station_callsign = %s';
			$params[] = strtoupper( $args['station_callsign'] );
		}

		if ( ! empty( $args['date_from'] ) && WPLB_Fields::normalize_date( $args['date_from'] ) ) {
			$where[]  = 'time_on >= %s';
			$params[] = WPLB_Fields::normalize_date( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) && WPLB_Fields::normalize_date( $args['date_to'] ) ) {
			$where[]  = 'time_on <= %s';
			$params[] = WPLB_Fields::normalize_date( $args['date_to'] ) . ' 23:59:59';
		}

		if ( ! empty( $args['callsign_starts'] ) ) {
			$escaped = $wpdb->esc_like( strtoupper( $args['callsign_starts'] ) );
			$where[] = '(callsign LIKE %s OR callsign LIKE %s)';
			array_push( $params, $escaped . '%', '%/' . $escaped . '%' );
		}

		// Column filters: array( column => value ) for exact, starts-with and contains matches.
		$columns = self::columns();
		foreach ( array( 'equals', 'prefix', 'contains' ) as $match ) {
			if ( empty( $args[ $match ] ) || ! is_array( $args[ $match ] ) ) {
				continue;
			}
			foreach ( $args[ $match ] as $column => $value ) {
				if ( ! in_array( $column, $columns, true ) || '' === (string) $value ) {
					continue;
				}
				if ( 'equals' === $match ) {
					$where[]  = "{$column} = %s";
					$params[] = (string) $value;
				} else {
					$where[]  = "{$column} LIKE %s";
					$params[] = ( 'contains' === $match ? '%' : '' ) . $wpdb->esc_like( (string) $value ) . '%';
				}
			}
		}

		if ( ! empty( $args['confirmed'] ) && in_array( $args['confirmed'], array( 'yes', 'no' ), true ) ) {
			$confirmed = "(qsl_rcvd = 'Y' OR lotw_qsl_rcvd = 'Y' OR eqsl_qsl_rcvd = 'Y')";
			$where[]   = 'yes' === $args['confirmed'] ? $confirmed : "NOT {$confirmed}";
		}

		if ( ! empty( $args['ids'] ) ) {
			$ids     = array_values( array_filter( array_map( 'absint', (array) $args['ids'] ) ) );
			$ids     = $ids ? $ids : array( 0 );
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		if ( ! empty( $args['exclude'] ) ) {
			$where[]  = 'id <> %d';
			$params[] = (int) $args['exclude'];
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * A QSO with the same station, band and mode within a minute.
	 *
	 * @param string $callsign Callsign.
	 * @param string $time_on  UTC start, Y-m-d H:i:s.
	 * @param string $band     Band.
	 * @param string $mode     ADIF mode.
	 * @param int    $exclude  QSO ID to ignore.
	 * @param int    $logbook  Only look in this logbook (0 for all).
	 * @return int Matching QSO ID or 0.
	 */
	public static function find_duplicate( $callsign, $time_on, $band, $mode, $exclude = 0, $logbook = 0 ) {
		global $wpdb;

		$timestamp = strtotime( $time_on . ' UTC' );
		if ( ! $timestamp ) {
			return 0;
		}
		$table = self::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE callsign = %s AND band = %s AND mode = %s AND time_on BETWEEN %s AND %s AND id <> %d AND ( %d = 0 OR logbook_id = %d ) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$callsign,
				$band,
				$mode,
				gmdate( 'Y-m-d H:i:s', $timestamp - 60 ),
				gmdate( 'Y-m-d H:i:s', $timestamp + 60 ),
				$exclude,
				$logbook,
				$logbook
			)
		);
	}

	/**
	 * Earlier contacts with a station, newest first.
	 *
	 * @param string $callsign Callsign.
	 * @param int    $exclude  QSO ID to leave out.
	 * @param int    $limit    Maximum rows.
	 * @return array[]
	 */
	public static function previous_contacts( $callsign, $exclude = 0, $limit = 10 ) {
		return self::query(
			array(
				'callsign' => $callsign,
				'exclude'  => $exclude,
				'per_page' => $limit,
			)
		);
	}

	/**
	 * The most recently logged QSO.
	 *
	 * @return array|null
	 */
	public static function last_logged() {
		$result = self::query(
			array(
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 1,
				'count'    => false,
			)
		);
		return $result['items'] ? $result['items'][0] : null;
	}

	/**
	 * Number of QSOs in each QRZ upload state.
	 *
	 * @param int[]|null $logbooks Only count these logbooks (null for all).
	 * @return array Status => count.
	 */
	public static function count_by_qrz_status( $logbooks = null ) {
		global $wpdb;
		$table  = self::table();
		$counts = array_fill_keys( array_keys( self::qrz_statuses() ), 0 );
		if ( is_array( $logbooks ) && ! $logbooks ) {
			return $counts;
		}
		$sql = "SELECT qrz_status, COUNT(*) AS total FROM {$table}";
		if ( is_array( $logbooks ) ) {
			$sql = $wpdb->prepare( $sql . ' WHERE logbook_id IN (' . implode( ',', array_fill( 0, count( $logbooks ), '%d' ) ) . ')', array_map( 'intval', $logbooks ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$rows = $wpdb->get_results( $sql . ' GROUP BY qrz_status', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$counts[ $row['qrz_status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Change the QRZ upload state of several QSOs.
	 *
	 * @param int[]  $ids    QSO IDs.
	 * @param string $status New state.
	 * @param array  $where  Only change QSOs currently in one of these states (empty for any).
	 * @return int Rows changed.
	 */
	public static function set_qrz_status( array $ids, $status, array $where = array() ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids || ! array_key_exists( $status, self::qrz_statuses() ) ) {
			return 0;
		}

		$table  = self::table();
		$sql    = "UPDATE {$table} SET qrz_status = %s, qrz_message = '' WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$params = array_merge( array( $status ), $ids );
		if ( $where ) {
			$sql   .= ' AND qrz_status IN (' . implode( ',', array_fill( 0, count( $where ), '%s' ) ) . ')';
			$params = array_merge( $params, array_values( $where ) );
		}

		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Queue QSOs for upload to QRZ.com.
	 *
	 * QSOs that were on QRZ.com before become "modified" so the upload
	 * replaces the QRZ.com copy; the others become "pending".
	 *
	 * @param int[] $ids QSO IDs.
	 * @return int Rows changed.
	 */
	public static function queue_for_upload( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$table = self::table();
		$sql   = "UPDATE {$table} SET qrz_message = '',
			qrz_status = CASE WHEN qrz_logid IS NOT NULL OR qrz_status IN ('uploaded', 'modified') THEN 'modified' ELSE 'pending' END
			WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';

		return (int) $wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Put QSOs into another logbook. Use WPLB_Logbooks::move_qsos(), which
	 * also takes care of their QRZ.com state.
	 *
	 * @param int[] $ids     QSO IDs.
	 * @param int   $logbook Logbook ID.
	 * @return int Rows changed.
	 */
	public static function move( array $ids, $logbook ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = self::table();
		$sql   = "UPDATE {$table} SET logbook_id = %d WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';

		return (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( array( (int) $logbook ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * IDs of QSOs in the given QRZ states, oldest first.
	 *
	 * @param string[]   $statuses States.
	 * @param int        $limit    Maximum IDs.
	 * @param int[]|null $logbooks Only these logbooks (null for all).
	 * @return int[]
	 */
	public static function ids_with_qrz_status( array $statuses, $limit = 0, $logbooks = null ) {
		global $wpdb;

		if ( is_array( $logbooks ) && ! $logbooks ) {
			return array();
		}
		$table  = self::table();
		$sql    = "SELECT id FROM {$table} WHERE qrz_status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params = array_values( $statuses );
		if ( is_array( $logbooks ) ) {
			$sql   .= ' AND logbook_id IN (' . implode( ',', array_fill( 0, count( $logbooks ), '%d' ) ) . ')';
			$params = array_merge( $params, array_map( 'intval', $logbooks ) );
		}
		$sql .= ' ORDER BY time_on ASC, id ASC';
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $limit;
		}

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Bands and mode pairs used in the log, for filter menus.
	 *
	 * @param array $args Optional query() filters limiting which QSOs count.
	 * @return array { bands: string[], modes: array value => label }
	 */
	public static function filter_options( array $args = array() ) {
		global $wpdb;

		list( $where, $params ) = self::where( $args );
		$table                  = self::table();

		$sql   = "SELECT DISTINCT band FROM {$table} WHERE {$where} AND band <> ''";
		$bands = $wpdb->get_col( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$order = array_flip( array_keys( WPLB_Fields::bands() ) );
		usort(
			$bands,
			function ( $a, $b ) use ( $order ) {
				return ( isset( $order[ $a ] ) ? $order[ $a ] : 999 ) - ( isset( $order[ $b ] ) ? $order[ $b ] : 999 );
			}
		);

		$modes = array();
		$sql   = "SELECT DISTINCT mode, submode FROM {$table} WHERE {$where} AND mode <> ''";
		$rows  = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$modes[ WPLB_Fields::mode_value( $row['mode'], $row['submode'] ) ] = WPLB_Fields::display_mode( $row['mode'], $row['submode'] );
		}
		asort( $modes );

		return array(
			'bands' => $bands,
			'modes' => $modes,
		);
	}

	/**
	 * Distinct non-empty values of a column, for filter menus.
	 *
	 * @param string $column Column name.
	 * @param array  $args   Optional query() filters limiting which QSOs count.
	 * @param int    $limit  Maximum number of values.
	 * @return string[] Sorted values.
	 */
	public static function distinct_values( $column, array $args = array(), $limit = 500 ) {
		global $wpdb;

		if ( ! in_array( $column, self::columns(), true ) ) {
			return array();
		}

		list( $where, $params ) = self::where( $args );
		$table                  = self::table();
		$sql                    = "SELECT DISTINCT {$column} FROM {$table} WHERE {$where} AND {$column} <> '' ORDER BY {$column} ASC LIMIT %d";
		$params[]               = max( 1, (int) $limit );

		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Logbook statistics.
	 *
	 * @param array $args Same filters as query().
	 * @return array
	 */
	public static function stats( array $args = array() ) {
		global $wpdb;

		list( $where, $params ) = self::where( $args );
		$table                  = self::table();

		$sql    = "SELECT COUNT(*) AS qsos, COUNT(DISTINCT callsign) AS callsigns, MIN(time_on) AS first_qso, MAX(time_on) AS last_qso,
			COUNT(DISTINCT CASE WHEN dxcc > 0 THEN dxcc END) AS dxcc,
			COUNT(DISTINCT CASE WHEN country <> '' THEN country END) AS countries,
			COUNT(DISTINCT CASE WHEN gridsquare <> '' THEN SUBSTR(gridsquare, 1, 4) END) AS grids
			FROM {$table} WHERE {$where}";
		$totals = $wpdb->get_row( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$sql   = "SELECT band, COUNT(*) AS total FROM {$table} WHERE {$where} GROUP BY band";
		$bands = array();
		foreach ( (array) $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$bands[ '' === $row['band'] ? '?' : $row['band'] ] = (int) $row['total'];
		}
		$order = array_flip( array_keys( WPLB_Fields::bands() ) );
		uksort(
			$bands,
			function ( $a, $b ) use ( $order ) {
				return ( isset( $order[ $a ] ) ? $order[ $a ] : 999 ) - ( isset( $order[ $b ] ) ? $order[ $b ] : 999 );
			}
		);

		$sql   = "SELECT mode, submode, COUNT(*) AS total FROM {$table} WHERE {$where} GROUP BY mode, submode";
		$modes = array();
		foreach ( (array) $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$label           = WPLB_Fields::display_mode( $row['mode'], $row['submode'] );
			$modes[ $label ] = ( isset( $modes[ $label ] ) ? $modes[ $label ] : 0 ) + (int) $row['total'];
		}
		arsort( $modes );

		return array(
			'qsos'      => (int) $totals['qsos'],
			'callsigns' => (int) $totals['callsigns'],
			'dxcc'      => (int) $totals['dxcc'],
			'countries' => (int) $totals['countries'],
			'grids'     => (int) $totals['grids'],
			'first_qso' => $totals['first_qso'],
			'last_qso'  => $totals['last_qso'],
			'bands'     => $bands,
			'modes'     => $modes,
		);
	}
}
