<?php
/**
 * Logbook field layout, based on the ADIF specification.
 *
 * Every QSO column in the database maps to an ADIF field so contacts can be
 * exported to ADIF files and uploaded to QRZ.com without translation tables
 * scattered through the code.
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions, enumerations and value normalisation.
 */
class WPLB_Fields {

	/**
	 * ADIF version that the field and enumeration tables follow.
	 */
	const ADIF_VERSION = '3.1.8';

	/**
	 * Cached field definitions.
	 *
	 * @var array|null
	 */
	private static $fields = null;

	/**
	 * Section headings used to group fields on the QSO form.
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'qso'     => __( 'Contact', 'wp-logbook' ),
			'station' => __( 'Contacted station', 'wp-logbook' ),
			'awards'  => __( 'Activations & contests', 'wp-logbook' ),
			'my'      => __( 'My station', 'wp-logbook' ),
			'prop'    => __( 'Propagation & satellite', 'wp-logbook' ),
			'qsl'     => __( 'QSL', 'wp-logbook' ),
			'notes'   => __( 'Notes', 'wp-logbook' ),
		);
	}

	/**
	 * All logbook fields keyed by database column.
	 *
	 * Keys used in each definition:
	 * - adif:     ADIF field name.
	 * - label:    Human readable label.
	 * - type:     callsign|string|upper|text|int|decimal|date|datetime|band|mode|enum|grid|email.
	 * - group:    Form section, see groups().
	 * - max:      Maximum length for text values.
	 * - range:    array( min, max ) for integers.
	 * - options:  Name of the method returning the allowed values for enums.
	 * - required: Whether a QSO is invalid without it.
	 * - public:   Whether the field may be shown on the public site.
	 * - qrz:      Whether the field is sent to QRZ.com (default true).
	 * - form:     Whether the generic form renders an input for it (default true).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$fields ) {
			self::$fields = self::define();
		}
		return self::$fields;
	}

	/**
	 * A single field definition.
	 *
	 * @param string $key Column name.
	 * @return array|null
	 */
	public static function get( $key ) {
		$fields = self::all();
		return isset( $fields[ $key ] ) ? $fields[ $key ] : null;
	}

	/**
	 * Build the field table.
	 *
	 * @return array
	 */
	private static function define() {
		// One line per field keeps the layout readable as a table.
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$f = array(
			// Contact.
			'callsign'         => array( 'CALL', __( 'Callsign', 'wp-logbook' ), 'callsign', 'qso', array( 'max' => 32, 'required' => true, 'public' => true ) ),
			'time_on'          => array( 'TIME_ON', __( 'Start (UTC)', 'wp-logbook' ), 'datetime', 'qso', array( 'required' => true, 'public' => true ) ),
			'time_off'         => array( 'TIME_OFF', __( 'End (UTC)', 'wp-logbook' ), 'datetime', 'qso' ),
			'band'             => array( 'BAND', __( 'Band', 'wp-logbook' ), 'band', 'qso', array( 'public' => true ) ),
			'freq'             => array( 'FREQ', __( 'Frequency (MHz)', 'wp-logbook' ), 'decimal', 'qso', array( 'public' => true ) ),
			'mode'             => array( 'MODE', __( 'Mode', 'wp-logbook' ), 'mode', 'qso', array( 'required' => true, 'public' => true ) ),
			'submode'          => array( 'SUBMODE', __( 'Submode', 'wp-logbook' ), 'upper', 'qso', array( 'max' => 30, 'form' => false ) ),
			'rst_sent'         => array( 'RST_SENT', __( 'RST sent', 'wp-logbook' ), 'string', 'qso', array( 'max' => 10, 'public' => true ) ),
			'rst_rcvd'         => array( 'RST_RCVD', __( 'RST received', 'wp-logbook' ), 'string', 'qso', array( 'max' => 10, 'public' => true ) ),
			'name'             => array( 'NAME', __( 'Name', 'wp-logbook' ), 'string', 'qso', array( 'max' => 100, 'public' => true ) ),
			'qth'              => array( 'QTH', __( 'QTH', 'wp-logbook' ), 'string', 'qso', array( 'max' => 100, 'public' => true ) ),
			'gridsquare'       => array( 'GRIDSQUARE', __( 'Grid square', 'wp-logbook' ), 'grid', 'qso', array( 'public' => true ) ),
			'country'          => array( 'COUNTRY', __( 'Country', 'wp-logbook' ), 'string', 'qso', array( 'max' => 64, 'public' => true ) ),
			'comment'          => array( 'COMMENT', __( 'Comment', 'wp-logbook' ), 'string', 'qso', array( 'max' => 255, 'public' => true ) ),

			// Contacted station.
			'state'            => array( 'STATE', __( 'State / province', 'wp-logbook' ), 'upper', 'station', array( 'max' => 32, 'public' => true ) ),
			'cnty'             => array( 'CNTY', __( 'County', 'wp-logbook' ), 'string', 'station', array( 'max' => 64, 'public' => true ) ),
			'dxcc'             => array( 'DXCC', __( 'DXCC entity code', 'wp-logbook' ), 'int', 'station', array( 'range' => array( 0, 999 ), 'public' => true ) ),
			'cont'             => array( 'CONT', __( 'Continent', 'wp-logbook' ), 'enum', 'station', array( 'options' => 'continents', 'public' => true ) ),
			'cqz'              => array( 'CQZ', __( 'CQ zone', 'wp-logbook' ), 'int', 'station', array( 'range' => array( 1, 40 ), 'public' => true ) ),
			'ituz'             => array( 'ITUZ', __( 'ITU zone', 'wp-logbook' ), 'int', 'station', array( 'range' => array( 1, 90 ), 'public' => true ) ),
			'iota'             => array( 'IOTA', __( 'IOTA', 'wp-logbook' ), 'upper', 'station', array( 'max' => 10, 'public' => true ) ),
			'qsl_via'          => array( 'QSL_VIA', __( 'QSL via', 'wp-logbook' ), 'string', 'station', array( 'max' => 100 ) ),
			'email'            => array( 'EMAIL', __( 'Email', 'wp-logbook' ), 'email', 'station', array( 'max' => 100, 'qrz' => false ) ),

			// Activations and contests.
			'sota_ref'         => array( 'SOTA_REF', __( 'SOTA reference', 'wp-logbook' ), 'upper', 'awards', array( 'max' => 20, 'public' => true ) ),
			'pota_ref'         => array( 'POTA_REF', __( 'POTA reference(s)', 'wp-logbook' ), 'upper', 'awards', array( 'max' => 100, 'public' => true ) ),
			'wwff_ref'         => array( 'WWFF_REF', __( 'WWFF reference', 'wp-logbook' ), 'upper', 'awards', array( 'max' => 20, 'public' => true ) ),
			'sig'              => array( 'SIG', __( 'Special interest group', 'wp-logbook' ), 'string', 'awards', array( 'max' => 50 ) ),
			'sig_info'         => array( 'SIG_INFO', __( 'SIG information', 'wp-logbook' ), 'string', 'awards', array( 'max' => 100 ) ),
			'contest_id'       => array( 'CONTEST_ID', __( 'Contest ID', 'wp-logbook' ), 'upper', 'awards', array( 'max' => 50, 'public' => true ) ),
			'stx'              => array( 'STX', __( 'Serial sent', 'wp-logbook' ), 'int', 'awards', array( 'range' => array( 0, 99999999 ) ) ),
			'stx_string'       => array( 'STX_STRING', __( 'Exchange sent', 'wp-logbook' ), 'string', 'awards', array( 'max' => 50 ) ),
			'srx'              => array( 'SRX', __( 'Serial received', 'wp-logbook' ), 'int', 'awards', array( 'range' => array( 0, 99999999 ) ) ),
			'srx_string'       => array( 'SRX_STRING', __( 'Exchange received', 'wp-logbook' ), 'string', 'awards', array( 'max' => 50 ) ),

			// My station.
			'station_callsign' => array( 'STATION_CALLSIGN', __( 'Station callsign', 'wp-logbook' ), 'callsign', 'my', array( 'max' => 32, 'public' => true ) ),
			'operator'         => array( 'OPERATOR', __( 'Operator', 'wp-logbook' ), 'callsign', 'my', array( 'max' => 32 ) ),
			'tx_pwr'           => array( 'TX_PWR', __( 'TX power (W)', 'wp-logbook' ), 'decimal', 'my', array( 'public' => true ) ),
			'my_rig'           => array( 'MY_RIG', __( 'Rig', 'wp-logbook' ), 'string', 'my', array( 'max' => 100 ) ),
			'my_antenna'       => array( 'MY_ANTENNA', __( 'Antenna', 'wp-logbook' ), 'string', 'my', array( 'max' => 100 ) ),
			'my_gridsquare'    => array( 'MY_GRIDSQUARE', __( 'My grid square', 'wp-logbook' ), 'grid', 'my', array( 'public' => true ) ),
			'my_city'          => array( 'MY_CITY', __( 'My city', 'wp-logbook' ), 'string', 'my', array( 'max' => 100 ) ),
			'my_state'         => array( 'MY_STATE', __( 'My state / province', 'wp-logbook' ), 'upper', 'my', array( 'max' => 32 ) ),
			'my_cnty'          => array( 'MY_CNTY', __( 'My county', 'wp-logbook' ), 'string', 'my', array( 'max' => 64 ) ),
			'my_country'       => array( 'MY_COUNTRY', __( 'My country', 'wp-logbook' ), 'string', 'my', array( 'max' => 64 ) ),
			'my_dxcc'          => array( 'MY_DXCC', __( 'My DXCC entity code', 'wp-logbook' ), 'int', 'my', array( 'range' => array( 0, 999 ) ) ),
			'my_cq_zone'       => array( 'MY_CQ_ZONE', __( 'My CQ zone', 'wp-logbook' ), 'int', 'my', array( 'range' => array( 1, 40 ) ) ),
			'my_itu_zone'      => array( 'MY_ITU_ZONE', __( 'My ITU zone', 'wp-logbook' ), 'int', 'my', array( 'range' => array( 1, 90 ) ) ),
			'my_iota'          => array( 'MY_IOTA', __( 'My IOTA', 'wp-logbook' ), 'upper', 'my', array( 'max' => 10 ) ),
			'my_sota_ref'      => array( 'MY_SOTA_REF', __( 'My SOTA reference', 'wp-logbook' ), 'upper', 'my', array( 'max' => 20, 'public' => true ) ),
			'my_pota_ref'      => array( 'MY_POTA_REF', __( 'My POTA reference(s)', 'wp-logbook' ), 'upper', 'my', array( 'max' => 100, 'public' => true ) ),
			'my_wwff_ref'      => array( 'MY_WWFF_REF', __( 'My WWFF reference', 'wp-logbook' ), 'upper', 'my', array( 'max' => 20 ) ),
			'my_sig'           => array( 'MY_SIG', __( 'My special interest group', 'wp-logbook' ), 'string', 'my', array( 'max' => 50 ) ),
			'my_sig_info'      => array( 'MY_SIG_INFO', __( 'My SIG information', 'wp-logbook' ), 'string', 'my', array( 'max' => 100 ) ),

			// Propagation and satellite.
			'prop_mode'        => array( 'PROP_MODE', __( 'Propagation mode', 'wp-logbook' ), 'enum', 'prop', array( 'options' => 'prop_modes', 'public' => true ) ),
			'sat_name'         => array( 'SAT_NAME', __( 'Satellite name', 'wp-logbook' ), 'upper', 'prop', array( 'max' => 30, 'public' => true ) ),
			'sat_mode'         => array( 'SAT_MODE', __( 'Satellite mode', 'wp-logbook' ), 'upper', 'prop', array( 'max' => 20 ) ),
			'band_rx'          => array( 'BAND_RX', __( 'Receive band (split)', 'wp-logbook' ), 'band', 'prop' ),
			'freq_rx'          => array( 'FREQ_RX', __( 'Receive frequency (MHz)', 'wp-logbook' ), 'decimal', 'prop' ),

			// QSL.
			'qsl_sent'         => array( 'QSL_SENT', __( 'QSL card sent', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_sent_options' ) ),
			'qslsdate'         => array( 'QSLSDATE', __( 'QSL sent date', 'wp-logbook' ), 'date', 'qsl' ),
			'qsl_sent_via'     => array( 'QSL_SENT_VIA', __( 'QSL sent via', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_via_options' ) ),
			'qsl_rcvd'         => array( 'QSL_RCVD', __( 'QSL card received', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_rcvd_options' ) ),
			'qslrdate'         => array( 'QSLRDATE', __( 'QSL received date', 'wp-logbook' ), 'date', 'qsl' ),
			'qsl_rcvd_via'     => array( 'QSL_RCVD_VIA', __( 'QSL received via', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_via_options' ) ),
			'qslmsg'           => array( 'QSLMSG', __( 'QSL message', 'wp-logbook' ), 'string', 'qsl', array( 'max' => 255 ) ),
			'lotw_qsl_sent'    => array( 'LOTW_QSL_SENT', __( 'LoTW sent', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_sent_options' ) ),
			'lotw_qslsdate'    => array( 'LOTW_QSLSDATE', __( 'LoTW sent date', 'wp-logbook' ), 'date', 'qsl' ),
			'lotw_qsl_rcvd'    => array( 'LOTW_QSL_RCVD', __( 'LoTW confirmed', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_rcvd_options' ) ),
			'lotw_qslrdate'    => array( 'LOTW_QSLRDATE', __( 'LoTW confirmed date', 'wp-logbook' ), 'date', 'qsl' ),
			'eqsl_qsl_sent'    => array( 'EQSL_QSL_SENT', __( 'eQSL sent', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_sent_options' ) ),
			'eqsl_qslsdate'    => array( 'EQSL_QSLSDATE', __( 'eQSL sent date', 'wp-logbook' ), 'date', 'qsl' ),
			'eqsl_qsl_rcvd'    => array( 'EQSL_QSL_RCVD', __( 'eQSL confirmed', 'wp-logbook' ), 'enum', 'qsl', array( 'options' => 'qsl_rcvd_options' ) ),
			'eqsl_qslrdate'    => array( 'EQSL_QSLRDATE', __( 'eQSL confirmed date', 'wp-logbook' ), 'date', 'qsl' ),

			// Private notes.
			'notes'            => array( 'NOTES', __( 'Notes (private)', 'wp-logbook' ), 'text', 'notes', array( 'qrz' => false ) ),
		);
		// phpcs:enable

		$fields = array();
		foreach ( $f as $key => $def ) {
			$extra          = isset( $def[4] ) ? $def[4] : array();
			$fields[ $key ] = array_merge(
				array(
					'adif'     => $def[0],
					'label'    => $def[1],
					'type'     => $def[2],
					'group'    => $def[3],
					'max'      => 0,
					'range'    => null,
					'options'  => null,
					'required' => false,
					'public'   => false,
					'qrz'      => true,
					'form'     => true,
				),
				$extra
			);
		}

		/**
		 * Filters the logbook field definitions.
		 *
		 * Only labels and flags should be changed here: every key must match a
		 * column of the QSO table.
		 *
		 * @param array $fields Field definitions keyed by column.
		 */
		return apply_filters( 'wplb_fields', $fields );
	}

	/**
	 * Allowed values for an enumeration field.
	 *
	 * @param array $field Field definition.
	 * @return array Value => label.
	 */
	public static function options( array $field ) {
		if ( empty( $field['options'] ) || ! method_exists( __CLASS__, $field['options'] ) ) {
			return array();
		}
		return call_user_func( array( __CLASS__, $field['options'] ) );
	}

	/**
	 * ADIF band enumeration with lower and upper frequency limits in MHz.
	 *
	 * @return array Band => array( lower, upper ).
	 */
	public static function bands() {
		return array(
			'2190m'  => array( 0.1357, 0.1378 ),
			'630m'   => array( 0.472, 0.479 ),
			'560m'   => array( 0.501, 0.504 ),
			'160m'   => array( 1.8, 2.0 ),
			'80m'    => array( 3.5, 4.0 ),
			'60m'    => array( 5.06, 5.45 ),
			'40m'    => array( 7.0, 7.3 ),
			'30m'    => array( 10.1, 10.15 ),
			'20m'    => array( 14.0, 14.35 ),
			'17m'    => array( 18.068, 18.168 ),
			'15m'    => array( 21.0, 21.45 ),
			'12m'    => array( 24.890, 24.99 ),
			'10m'    => array( 28.0, 29.7 ),
			'8m'     => array( 40, 45 ),
			'6m'     => array( 50, 54 ),
			'5m'     => array( 54.000001, 69.9 ),
			'4m'     => array( 70, 71 ),
			'2m'     => array( 144, 148 ),
			'1.25m'  => array( 222, 225 ),
			'70cm'   => array( 420, 450 ),
			'33cm'   => array( 902, 928 ),
			'23cm'   => array( 1240, 1300 ),
			'13cm'   => array( 2300, 2450 ),
			'9cm'    => array( 3300, 3500 ),
			'6cm'    => array( 5650, 5925 ),
			'3cm'    => array( 10000, 10500 ),
			'1.25cm' => array( 24000, 24250 ),
			'6mm'    => array( 47000, 47200 ),
			'4mm'    => array( 75500, 81000 ),
			'2.5mm'  => array( 119980, 123000 ),
			'2mm'    => array( 134000, 149000 ),
			'1mm'    => array( 241000, 250000 ),
			'submm'  => array( 300000, 7500000 ),
		);
	}

	/**
	 * Band that contains a frequency.
	 *
	 * @param float|string $mhz Frequency in MHz.
	 * @return string Band name or an empty string when outside every band.
	 */
	public static function band_for_freq( $mhz ) {
		if ( ! is_numeric( $mhz ) ) {
			return '';
		}
		$mhz = (float) $mhz;
		foreach ( self::bands() as $band => $limits ) {
			if ( $mhz >= $limits[0] && $mhz <= $limits[1] ) {
				return $band;
			}
		}
		return '';
	}

	/**
	 * ADIF modes and their submodes (ADIF 3.1.8, import-only values excluded).
	 *
	 * @return array Mode => list of submodes.
	 */
	public static function adif_modes() {
		return array(
			'AM'           => array(),
			'ARDOP'        => array(),
			'ATV'          => array(),
			'CHIP'         => array( 'CHIP64', 'CHIP128' ),
			'CLO'          => array(),
			'CONTESTI'     => array(),
			'CW'           => array( 'PCW' ),
			'DIGITALVOICE' => array( 'C4FM', 'DMR', 'DSTAR', 'FREEDV', 'M17' ),
			'DOMINO'       => array( 'DOM-M', 'DOM4', 'DOM5', 'DOM8', 'DOM11', 'DOM16', 'DOM22', 'DOM44', 'DOM88', 'DOMINOEX', 'DOMINOF' ),
			'DYNAMIC'      => array( 'FREEDATA', 'VARA HF', 'VARA SATELLITE', 'VARA FM 1200', 'VARA FM 9600' ),
			'FAX'          => array(),
			'FM'           => array(),
			'FSK441'       => array(),
			'FSK'          => array( 'SCAMP_FAST', 'SCAMP_SLOW', 'SCAMP_VSLOW' ),
			'FT8'          => array(),
			'HELL'         => array( 'FMHELL', 'FSKH105', 'FSKH245', 'FSKHELL', 'HELL80', 'HELLX5', 'HELLX9', 'HFSK', 'PSKHELL', 'SLOWHELL' ),
			'ISCAT'        => array( 'ISCAT-A', 'ISCAT-B' ),
			'JT4'          => array( 'JT4A', 'JT4B', 'JT4C', 'JT4D', 'JT4E', 'JT4F', 'JT4G' ),
			'JT6M'         => array(),
			'JT9'          => array( 'JT9-1', 'JT9-2', 'JT9-5', 'JT9-10', 'JT9-30', 'JT9A', 'JT9B', 'JT9C', 'JT9D', 'JT9E', 'JT9E FAST', 'JT9F', 'JT9F FAST', 'JT9G', 'JT9G FAST', 'JT9H', 'JT9H FAST' ),
			'JT44'         => array(),
			'JT65'         => array( 'JT65A', 'JT65B', 'JT65B2', 'JT65C', 'JT65C2' ),
			'MFSK'         => array( 'FSQCALL', 'FST4', 'FST4W', 'FT2', 'FT4', 'JS8', 'JTMS', 'JTTY', 'MFSK4', 'MFSK8', 'MFSK11', 'MFSK16', 'MFSK22', 'MFSK31', 'MFSK32', 'MFSK64', 'MFSK64L', 'MFSK128', 'MFSK128L', 'Q65' ),
			'MSK144'       => array(),
			'MTONE'        => array( 'SCAMP_OO', 'SCAMP_OO_SLW' ),
			'MT63'         => array(),
			'OFDM'         => array( 'RIBBIT_PIX', 'RIBBIT_SMS' ),
			'OLIVIA'       => array( 'OLIVIA 4/125', 'OLIVIA 4/250', 'OLIVIA 8/250', 'OLIVIA 8/500', 'OLIVIA 16/500', 'OLIVIA 16/1000', 'OLIVIA 32/1000' ),
			'OPERA'        => array( 'OPERA-BEACON', 'OPERA-QSO' ),
			'PAC'          => array( 'PAC2', 'PAC3', 'PAC4' ),
			'PAX'          => array( 'PAX2' ),
			'PKT'          => array(),
			'PSK'          => array( '8PSK125', '8PSK125F', '8PSK125FL', '8PSK250', '8PSK250F', '8PSK250FL', '8PSK500', '8PSK500F', '8PSK1000', '8PSK1000F', '8PSK1200F', 'FSK31', 'PSK10', 'PSK31', 'PSK63', 'PSK63F', 'PSK63RC4', 'PSK63RC5', 'PSK63RC10', 'PSK63RC20', 'PSK63RC32', 'PSK125', 'PSK125C12', 'PSK125R', 'PSK125RC10', 'PSK125RC12', 'PSK125RC16', 'PSK125RC4', 'PSK125RC5', 'PSK250', 'PSK250C6', 'PSK250R', 'PSK250RC2', 'PSK250RC3', 'PSK250RC5', 'PSK250RC6', 'PSK250RC7', 'PSK500', 'PSK500C2', 'PSK500C4', 'PSK500R', 'PSK500RC2', 'PSK500RC3', 'PSK500RC4', 'PSK800C2', 'PSK800RC2', 'PSK1000', 'PSK1000C2', 'PSK1000R', 'PSK1000RC2', 'PSKAM10', 'PSKAM31', 'PSKAM50', 'PSKFEC31', 'QPSK31', 'QPSK63', 'QPSK125', 'QPSK250', 'QPSK500', 'SIM31' ),
			'PSK2K'        => array(),
			'Q15'          => array(),
			'QRA64'        => array( 'QRA64A', 'QRA64B', 'QRA64C', 'QRA64D', 'QRA64E' ),
			'ROS'          => array( 'ROS-EME', 'ROS-HF', 'ROS-MF' ),
			'RTTY'         => array( 'ASCI' ),
			'RTTYM'        => array(),
			'SSB'          => array( 'LSB', 'USB' ),
			'SSTV'         => array(),
			'T10'          => array(),
			'THOR'         => array( 'THOR-M', 'THOR4', 'THOR5', 'THOR8', 'THOR11', 'THOR16', 'THOR22', 'THOR25X4', 'THOR50X1', 'THOR50X2', 'THOR100' ),
			'THRB'         => array( 'THRBX', 'THRBX1', 'THRBX2', 'THRBX4', 'THROB1', 'THROB2', 'THROB4' ),
			'TOR'          => array( 'AMTORFEC', 'GTOR', 'NAVTEX', 'SITORB' ),
			'V4'           => array(),
			'VOI'          => array(),
			'WINMOR'       => array(),
			'WSPR'         => array(),
		);
	}

	/**
	 * Modes offered at the top of the mode picker.
	 *
	 * Operators think of FT4 or USB as modes even though ADIF records them as
	 * submodes of MFSK and SSB, so the picker stores the ADIF pair for them.
	 *
	 * @return array Label => array( mode, submode ).
	 */
	public static function common_modes() {
		$modes = array(
			'SSB'     => array( 'SSB', '' ),
			'USB'     => array( 'SSB', 'USB' ),
			'LSB'     => array( 'SSB', 'LSB' ),
			'CW'      => array( 'CW', '' ),
			'FM'      => array( 'FM', '' ),
			'AM'      => array( 'AM', '' ),
			'FT8'     => array( 'FT8', '' ),
			'FT4'     => array( 'MFSK', 'FT4' ),
			'JS8'     => array( 'MFSK', 'JS8' ),
			'Q65'     => array( 'MFSK', 'Q65' ),
			'FST4'    => array( 'MFSK', 'FST4' ),
			'MSK144'  => array( 'MSK144', '' ),
			'JT65'    => array( 'JT65', '' ),
			'JT9'     => array( 'JT9', '' ),
			'WSPR'    => array( 'WSPR', '' ),
			'RTTY'    => array( 'RTTY', '' ),
			'PSK31'   => array( 'PSK', 'PSK31' ),
			'PSK63'   => array( 'PSK', 'PSK63' ),
			'OLIVIA'  => array( 'OLIVIA', '' ),
			'VARA HF' => array( 'DYNAMIC', 'VARA HF' ),
			'PACKET'  => array( 'PKT', '' ),
			'DMR'     => array( 'DIGITALVOICE', 'DMR' ),
			'D-STAR'  => array( 'DIGITALVOICE', 'DSTAR' ),
			'C4FM'    => array( 'DIGITALVOICE', 'C4FM' ),
			'FreeDV'  => array( 'DIGITALVOICE', 'FREEDV' ),
			'SSTV'    => array( 'SSTV', '' ),
		);

		/**
		 * Filters the shortlist of modes shown first in the mode picker.
		 *
		 * @param array $modes Label => array( ADIF mode, ADIF submode ).
		 */
		return apply_filters( 'wplb_common_modes', $modes );
	}

	/**
	 * Value used by the mode picker for a mode/submode pair.
	 *
	 * @param string $mode    ADIF mode.
	 * @param string $submode ADIF submode.
	 * @return string
	 */
	public static function mode_value( $mode, $submode = '' ) {
		return '' === (string) $submode ? (string) $mode : $mode . '|' . $submode;
	}

	/**
	 * Split a mode picker value into its ADIF mode and submode.
	 *
	 * @param string $value Picker value.
	 * @return array array( mode, submode ).
	 */
	public static function parse_mode_value( $value ) {
		$parts = explode( '|', strtoupper( trim( (string) $value ) ), 2 );
		return array( trim( $parts[0] ), isset( $parts[1] ) ? trim( $parts[1] ) : '' );
	}

	/**
	 * How a mode is shown to people: the submode when there is one.
	 *
	 * @param string $mode    ADIF mode.
	 * @param string $submode ADIF submode.
	 * @return string
	 */
	public static function display_mode( $mode, $submode = '' ) {
		return '' !== (string) $submode ? (string) $submode : (string) $mode;
	}

	/**
	 * ADIF mode that a submode belongs to.
	 *
	 * Many programs write submodes such as USB or FT4 into the MODE field;
	 * this finds the mode they should have used.
	 *
	 * @param string $submode Submode.
	 * @return string Parent mode or an empty string.
	 */
	public static function submode_parent( $submode ) {
		$submode = strtoupper( (string) $submode );
		foreach ( self::adif_modes() as $mode => $submodes ) {
			if ( in_array( $submode, $submodes, true ) ) {
				return $mode;
			}
		}
		return '';
	}

	/**
	 * Continent enumeration.
	 *
	 * @return array
	 */
	public static function continents() {
		return array(
			'NA' => __( 'North America', 'wp-logbook' ),
			'SA' => __( 'South America', 'wp-logbook' ),
			'EU' => __( 'Europe', 'wp-logbook' ),
			'AF' => __( 'Africa', 'wp-logbook' ),
			'OC' => __( 'Oceania', 'wp-logbook' ),
			'AS' => __( 'Asia', 'wp-logbook' ),
			'AN' => __( 'Antarctica', 'wp-logbook' ),
		);
	}

	/**
	 * Propagation mode enumeration.
	 *
	 * @return array
	 */
	public static function prop_modes() {
		return array(
			'AS'       => __( 'Aircraft scatter', 'wp-logbook' ),
			'AUE'      => __( 'Aurora-E', 'wp-logbook' ),
			'AUR'      => __( 'Aurora', 'wp-logbook' ),
			'BS'       => __( 'Back scatter', 'wp-logbook' ),
			'ECH'      => __( 'EchoLink', 'wp-logbook' ),
			'EME'      => __( 'Earth-Moon-Earth', 'wp-logbook' ),
			'ES'       => __( 'Sporadic E', 'wp-logbook' ),
			'F2'       => __( 'F2 reflection', 'wp-logbook' ),
			'FAI'      => __( 'Field aligned irregularities', 'wp-logbook' ),
			'GWAVE'    => __( 'Ground wave', 'wp-logbook' ),
			'INTERNET' => __( 'Internet-assisted', 'wp-logbook' ),
			'ION'      => __( 'Ionoscatter', 'wp-logbook' ),
			'IRL'      => __( 'IRLP', 'wp-logbook' ),
			'LOS'      => __( 'Line of sight', 'wp-logbook' ),
			'MS'       => __( 'Meteor scatter', 'wp-logbook' ),
			'RPT'      => __( 'Repeater or transponder', 'wp-logbook' ),
			'RS'       => __( 'Rain scatter', 'wp-logbook' ),
			'SAT'      => __( 'Satellite', 'wp-logbook' ),
			'TEP'      => __( 'Trans-equatorial', 'wp-logbook' ),
			'TR'       => __( 'Tropospheric ducting', 'wp-logbook' ),
		);
	}

	/**
	 * QSL_SENT style enumeration.
	 *
	 * @return array
	 */
	public static function qsl_sent_options() {
		return array(
			'Y' => __( 'Yes', 'wp-logbook' ),
			'N' => __( 'No', 'wp-logbook' ),
			'R' => __( 'Requested', 'wp-logbook' ),
			'Q' => __( 'Queued', 'wp-logbook' ),
			'I' => __( 'Ignore / invalid', 'wp-logbook' ),
		);
	}

	/**
	 * QSL_RCVD style enumeration.
	 *
	 * @return array
	 */
	public static function qsl_rcvd_options() {
		return array(
			'Y' => __( 'Yes', 'wp-logbook' ),
			'N' => __( 'No', 'wp-logbook' ),
			'R' => __( 'Requested', 'wp-logbook' ),
			'I' => __( 'Ignore / invalid', 'wp-logbook' ),
		);
	}

	/**
	 * QSL_VIA enumeration (how a card was sent or received).
	 *
	 * @return array
	 */
	public static function qsl_via_options() {
		return array(
			'B' => __( 'Bureau', 'wp-logbook' ),
			'D' => __( 'Direct', 'wp-logbook' ),
			'E' => __( 'Electronic', 'wp-logbook' ),
		);
	}

	/**
	 * Fields that may be shown on the public site.
	 *
	 * @return array Column => label, plus the virtual "confirmed" and "logbook" columns.
	 */
	public static function public_columns() {
		// Logbooks conventionally start with the date, so offer it first.
		$columns = array( 'time_on' => __( 'Date / time (UTC)', 'wp-logbook' ) );
		foreach ( self::all() as $key => $field ) {
			if ( $field['public'] && 'time_on' !== $key ) {
				$columns[ $key ] = $field['label'];
			}
		}
		$columns['confirmed'] = __( 'Confirmed', 'wp-logbook' );
		$columns['logbook']   = __( 'Logbook', 'wp-logbook' );
		return $columns;
	}

	/**
	 * Format a stored decimal without trailing zeros.
	 *
	 * @param mixed $value Number or numeric string.
	 * @return string
	 */
	public static function format_number( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$value = (string) $value;
		if ( false !== stripos( $value, 'e' ) ) {
			$value = sprintf( '%.6F', (float) $value );
		}
		if ( false !== strpos( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		return '' === $value || '-0' === $value ? '0' : $value;
	}

	/**
	 * Normalise a Maidenhead locator (FN31pr style).
	 *
	 * @param string $grid Locator of 2, 4, 6 or 8 characters.
	 * @return string|false Normalised locator, or false when invalid.
	 */
	public static function normalize_grid( $grid ) {
		$grid = strtoupper( preg_replace( '/\s+/', '', (string) $grid ) );
		if ( ! preg_match( '/^[A-R]{2}(?:[0-9]{2}(?:[A-X]{2}(?:[0-9]{2})?)?)?$/', $grid ) ) {
			return false;
		}
		if ( strlen( $grid ) >= 6 ) {
			$grid = substr( $grid, 0, 4 ) . strtolower( substr( $grid, 4, 2 ) ) . substr( $grid, 6 );
		}
		return $grid;
	}

	/**
	 * Normalise a callsign.
	 *
	 * @param string $call Callsign as typed.
	 * @return string|false Upper-case callsign, or false when invalid.
	 */
	public static function normalize_callsign( $call ) {
		$call = strtoupper( preg_replace( '/\s+/', '', (string) $call ) );
		$call = str_replace( '\\', '/', $call );
		if ( ! preg_match( '/^[A-Z0-9][A-Z0-9\/]*[A-Z0-9]$/', $call ) || strlen( $call ) < 3 ) {
			return false;
		}
		return $call;
	}

	/**
	 * Parse a date in Y-m-d or ADIF YYYYMMDD form.
	 *
	 * @param string $value Date.
	 * @return string|false Y-m-d, or false when invalid.
	 */
	public static function normalize_date( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4})-?(\d{2})-?(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		return false;
	}

	/**
	 * Parse a UTC date and time.
	 *
	 * @param string $value "Y-m-d H:i[:s]" (a "T" separator is accepted too).
	 * @return string|false "Y-m-d H:i:s", or false when invalid.
	 */
	public static function normalize_datetime( $value ) {
		$value = trim( str_replace( 'T', ' ', (string) $value ) );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			return false;
		}
		$date = self::normalize_date( $m[1] );
		$h    = (int) $m[2];
		$i    = (int) $m[3];
		$s    = isset( $m[4] ) ? (int) $m[4] : 0;
		if ( false === $date || $h > 23 || $i > 59 || $s > 59 ) {
			return false;
		}
		return sprintf( '%s %02d:%02d:%02d', $date, $h, $i, $s );
	}

	/**
	 * Sanitise and validate QSO values.
	 *
	 * Only keys present in $input are returned, so this works for partial
	 * updates as well as complete records.
	 *
	 * @param array $input Raw values keyed by column.
	 * @return array array( clean values, errors ) where errors are keyed by column.
	 */
	public static function sanitize( array $input ) {
		$data   = array();
		$errors = array();

		foreach ( self::all() as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw   = is_scalar( $input[ $key ] ) || null === $input[ $key ] ? (string) $input[ $key ] : '';
			$raw   = trim( $raw );
			$label = $field['label'];

			switch ( $field['type'] ) {
				case 'callsign':
					if ( '' === $raw ) {
						$data[ $key ] = '';
						break;
					}
					$call = self::normalize_callsign( $raw );
					if ( false === $call || strlen( $call ) > $field['max'] ) {
						/* translators: %s: field label */
						$errors[ $key ] = sprintf( __( '%s is not a valid callsign.', 'wp-logbook' ), $label );
						break;
					}
					$data[ $key ] = $call;
					break;

				case 'datetime':
					if ( '' === $raw ) {
						$data[ $key ] = null;
						break;
					}
					$datetime = self::normalize_datetime( $raw );
					if ( false === $datetime ) {
						/* translators: %s: field label */
						$errors[ $key ] = sprintf( __( '%s is not a valid date and time.', 'wp-logbook' ), $label );
						break;
					}
					$data[ $key ] = $datetime;
					break;

				case 'date':
					if ( '' === $raw ) {
						$data[ $key ] = null;
						break;
					}
					$date = self::normalize_date( $raw );
					if ( false === $date ) {
						/* translators: %s: field label */
						$errors[ $key ] = sprintf( __( '%s is not a valid date.', 'wp-logbook' ), $label );
						break;
					}
					$data[ $key ] = $date;
					break;

				case 'int':
					if ( '' === $raw ) {
						$data[ $key ] = null;
						break;
					}
					$range = $field['range'] ? $field['range'] : array( 0, PHP_INT_MAX );
					if ( ! preg_match( '/^\d+$/', $raw ) || (int) $raw < $range[0] || (int) $raw > $range[1] ) {
						/* translators: 1: field label, 2: minimum, 3: maximum */
						$errors[ $key ] = sprintf( __( '%1$s must be a whole number from %2$d to %3$d.', 'wp-logbook' ), $label, $range[0], $range[1] );
						break;
					}
					$data[ $key ] = (int) $raw;
					break;

				case 'decimal':
					$raw = str_replace( ',', '.', $raw );
					if ( '' === $raw ) {
						$data[ $key ] = null;
						break;
					}
					if ( ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw >= 10000000 ) {
						/* translators: %s: field label */
						$errors[ $key ] = sprintf( __( '%s must be a positive number.', 'wp-logbook' ), $label );
						break;
					}
					$data[ $key ] = self::format_number( sprintf( '%.6F', (float) $raw ) );
					break;

				case 'band':
					$band = strtolower( $raw );
					if ( '' !== $band && ! isset( self::bands()[ $band ] ) ) {
						/* translators: %s: band entered by the user */
						$errors[ $key ] = sprintf( __( '"%s" is not an ADIF band.', 'wp-logbook' ), $raw );
						break;
					}
					$data[ $key ] = $band;
					break;

				case 'mode':
					$mode = strtoupper( $raw );
					if ( '' !== $mode && ! preg_match( '/^[A-Z0-9][A-Z0-9 \/_-]{0,19}$/', $mode ) ) {
						/* translators: %s: mode entered by the user */
						$errors[ $key ] = sprintf( __( '"%s" is not a valid mode.', 'wp-logbook' ), $raw );
						break;
					}
					$data[ $key ] = $mode;
					break;

				case 'enum':
					$value        = strtoupper( $raw );
					$data[ $key ] = array_key_exists( $value, self::options( $field ) ) ? $value : '';
					break;

				case 'grid':
					if ( '' === $raw ) {
						$data[ $key ] = '';
						break;
					}
					$grid = self::normalize_grid( $raw );
					if ( false === $grid ) {
						/* translators: %s: field label */
						$errors[ $key ] = sprintf( __( '%s must be a Maidenhead locator such as FN31 or FN31pr.', 'wp-logbook' ), $label );
						break;
					}
					$data[ $key ] = $grid;
					break;

				case 'email':
					$data[ $key ] = substr( sanitize_email( $raw ), 0, $field['max'] );
					break;

				case 'text':
					$data[ $key ] = self::plain_text( $raw, true );
					break;

				case 'upper':
					$data[ $key ] = self::truncate( strtoupper( self::plain_text( $raw ) ), $field['max'] );
					break;

				default:
					$data[ $key ] = self::truncate( self::plain_text( $raw ), $field['max'] );
			}
		}

		return array( $data, $errors );
	}

	/**
	 * Fill in the band from the frequency and check required fields.
	 *
	 * @param array $data Sanitised values of a complete QSO (modified in place).
	 * @return array Errors keyed by column.
	 */
	public static function validate( array &$data ) {
		$errors = array();

		if ( ! empty( $data['freq'] ) ) {
			$freq_band = self::band_for_freq( $data['freq'] );
			if ( empty( $data['band'] ) ) {
				$data['band'] = $freq_band;
			} elseif ( '' !== $freq_band && $freq_band !== $data['band'] ) {
				$errors['freq'] = sprintf(
					/* translators: 1: frequency in MHz, 2: band */
					__( '%1$s MHz is not in the %2$s band.', 'wp-logbook' ),
					$data['freq'],
					$data['band']
				);
			}
		}
		if ( ! empty( $data['freq_rx'] ) && empty( $data['band_rx'] ) ) {
			$data['band_rx'] = self::band_for_freq( $data['freq_rx'] );
		}

		if ( empty( $data['callsign'] ) ) {
			$errors['callsign'] = __( 'Enter the callsign of the station you worked.', 'wp-logbook' );
		}
		if ( empty( $data['time_on'] ) ) {
			$errors['time_on'] = __( 'Enter the date and time (UTC) the contact started.', 'wp-logbook' );
		}
		if ( empty( $data['mode'] ) ) {
			$errors['mode'] = __( 'Choose a mode.', 'wp-logbook' );
		}
		if ( empty( $data['band'] ) && empty( $data['freq'] ) ) {
			$errors['band'] = __( 'Choose a band or enter a frequency.', 'wp-logbook' );
		} elseif ( empty( $data['band'] ) ) {
			$errors['freq'] = __( 'That frequency is outside every amateur band; choose the band as well.', 'wp-logbook' );
		}
		if ( ! empty( $data['time_on'] ) && ! empty( $data['time_off'] ) && $data['time_off'] < $data['time_on'] ) {
			$errors['time_off'] = __( 'The end time is before the start time.', 'wp-logbook' );
		}

		return $errors;
	}

	/**
	 * Clean free text.
	 *
	 * Log entries are plain text that is escaped wherever it is printed, so
	 * unlike sanitize_text_field() this keeps characters such as "<", "&" and
	 * "%" that operators type into comments ("<3", "100% copy").
	 *
	 * @param string $value     Text.
	 * @param bool   $multiline Keep line breaks.
	 * @return string
	 */
	public static function plain_text( $value, $multiline = false ) {
		$value = wp_check_invalid_utf8( (string) $value, true );
		if ( $multiline ) {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			$value = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $value );
		} else {
			$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
			$value = preg_replace( '/ {2,}/', ' ', $value );
		}
		return trim( $value );
	}

	/**
	 * Cut a string to a maximum number of characters.
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum length, 0 for no limit.
	 * @return string
	 */
	private static function truncate( $value, $max ) {
		if ( $max > 0 && mb_strlen( $value ) > $max ) {
			$value = mb_substr( $value, 0, $max );
		}
		return $value;
	}
}
