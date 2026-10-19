<?php
/**
 * Database table and option setup.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the QSO table.
 */
class WPLB_Install {

	const DB_VERSION_OPTION = 'wplb_db_version';

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
		WPLB_QRZ_Sync::sync_schedule();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		WPLB_QRZ_Sync::unschedule();
	}

	/**
	 * Create or upgrade the table when the schema version changed.
	 *
	 * Also covers network activation, where activate() only runs for the
	 * current site.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== WPLB_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create the table and default options.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );

		add_option( WPLB_Settings::OPTION, WPLB_Settings::defaults() );
		add_option( WPLB_Settings::API_KEY_OPTION, '', '', false );
		update_option( self::DB_VERSION_OPTION, WPLB_DB_VERSION );
	}

	/**
	 * Table definition in dbDelta format.
	 *
	 * Column names follow the ADIF field names (see WPLB_Fields), except
	 * "callsign" for CALL, which is a reserved word in MySQL.
	 *
	 * @return string
	 */
	public static function schema() {
		global $wpdb;
		$table   = WPLB_QSOs::table();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  callsign varchar(32) NOT NULL DEFAULT '',
  time_on datetime NOT NULL,
  time_off datetime DEFAULT NULL,
  band varchar(10) NOT NULL DEFAULT '',
  freq decimal(14,6) DEFAULT NULL,
  mode varchar(20) NOT NULL DEFAULT '',
  submode varchar(30) NOT NULL DEFAULT '',
  rst_sent varchar(10) NOT NULL DEFAULT '',
  rst_rcvd varchar(10) NOT NULL DEFAULT '',
  name varchar(100) NOT NULL DEFAULT '',
  qth varchar(100) NOT NULL DEFAULT '',
  gridsquare varchar(8) NOT NULL DEFAULT '',
  country varchar(64) NOT NULL DEFAULT '',
  comment varchar(255) NOT NULL DEFAULT '',
  state varchar(32) NOT NULL DEFAULT '',
  cnty varchar(64) NOT NULL DEFAULT '',
  dxcc smallint(5) unsigned DEFAULT NULL,
  cont char(2) NOT NULL DEFAULT '',
  cqz tinyint(3) unsigned DEFAULT NULL,
  ituz tinyint(3) unsigned DEFAULT NULL,
  iota varchar(10) NOT NULL DEFAULT '',
  qsl_via varchar(100) NOT NULL DEFAULT '',
  email varchar(100) NOT NULL DEFAULT '',
  sota_ref varchar(20) NOT NULL DEFAULT '',
  pota_ref varchar(100) NOT NULL DEFAULT '',
  wwff_ref varchar(20) NOT NULL DEFAULT '',
  sig varchar(50) NOT NULL DEFAULT '',
  sig_info varchar(100) NOT NULL DEFAULT '',
  contest_id varchar(50) NOT NULL DEFAULT '',
  stx int(10) unsigned DEFAULT NULL,
  stx_string varchar(50) NOT NULL DEFAULT '',
  srx int(10) unsigned DEFAULT NULL,
  srx_string varchar(50) NOT NULL DEFAULT '',
  station_callsign varchar(32) NOT NULL DEFAULT '',
  operator varchar(32) NOT NULL DEFAULT '',
  tx_pwr decimal(10,3) DEFAULT NULL,
  my_rig varchar(100) NOT NULL DEFAULT '',
  my_antenna varchar(100) NOT NULL DEFAULT '',
  my_gridsquare varchar(8) NOT NULL DEFAULT '',
  my_city varchar(100) NOT NULL DEFAULT '',
  my_state varchar(32) NOT NULL DEFAULT '',
  my_cnty varchar(64) NOT NULL DEFAULT '',
  my_country varchar(64) NOT NULL DEFAULT '',
  my_dxcc smallint(5) unsigned DEFAULT NULL,
  my_cq_zone tinyint(3) unsigned DEFAULT NULL,
  my_itu_zone tinyint(3) unsigned DEFAULT NULL,
  my_iota varchar(10) NOT NULL DEFAULT '',
  my_sota_ref varchar(20) NOT NULL DEFAULT '',
  my_pota_ref varchar(100) NOT NULL DEFAULT '',
  my_wwff_ref varchar(20) NOT NULL DEFAULT '',
  my_sig varchar(50) NOT NULL DEFAULT '',
  my_sig_info varchar(100) NOT NULL DEFAULT '',
  prop_mode varchar(10) NOT NULL DEFAULT '',
  sat_name varchar(30) NOT NULL DEFAULT '',
  sat_mode varchar(20) NOT NULL DEFAULT '',
  band_rx varchar(10) NOT NULL DEFAULT '',
  freq_rx decimal(14,6) DEFAULT NULL,
  qsl_sent char(1) NOT NULL DEFAULT '',
  qslsdate date DEFAULT NULL,
  qsl_sent_via char(1) NOT NULL DEFAULT '',
  qsl_rcvd char(1) NOT NULL DEFAULT '',
  qslrdate date DEFAULT NULL,
  qsl_rcvd_via char(1) NOT NULL DEFAULT '',
  qslmsg varchar(255) NOT NULL DEFAULT '',
  lotw_qsl_sent char(1) NOT NULL DEFAULT '',
  lotw_qslsdate date DEFAULT NULL,
  lotw_qsl_rcvd char(1) NOT NULL DEFAULT '',
  lotw_qslrdate date DEFAULT NULL,
  eqsl_qsl_sent char(1) NOT NULL DEFAULT '',
  eqsl_qslsdate date DEFAULT NULL,
  eqsl_qsl_rcvd char(1) NOT NULL DEFAULT '',
  eqsl_qslrdate date DEFAULT NULL,
  notes text,
  qrz_status varchar(10) NOT NULL DEFAULT 'pending',
  qrz_logid bigint(20) unsigned DEFAULT NULL,
  qrz_upload_date datetime DEFAULT NULL,
  qrz_message varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY time_on (time_on),
  KEY callsign (callsign),
  KEY band_mode (band,mode),
  KEY qrz_status (qrz_status)
) {$collate};";
	}
}
