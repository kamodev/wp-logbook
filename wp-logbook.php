<?php
/**
 * Plugin Name:       WP Logbook
 * Plugin URI:        https://github.com/kamodev/wp-logbook
 * Description:       Amateur radio contact (QSO) logbook for WordPress. Log contacts, import/export ADIF, show your log on your site, and optionally upload contacts to your QRZ.com Logbook.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            kamodev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-logbook
 * Domain Path:       /languages
 *
 * @package WP_Logbook
 */

defined( 'ABSPATH' ) || exit;

define( 'WPLB_VERSION', '1.1.0' );
define( 'WPLB_DB_VERSION', '1' );
define( 'WPLB_FILE', __FILE__ );
define( 'WPLB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPLB_URL', plugin_dir_url( __FILE__ ) );

require_once WPLB_DIR . 'includes/class-wplb-fields.php';
require_once WPLB_DIR . 'includes/class-wplb-settings.php';
require_once WPLB_DIR . 'includes/class-wplb-install.php';
require_once WPLB_DIR . 'includes/class-wplb-qsos.php';
require_once WPLB_DIR . 'includes/class-wplb-adif.php';
require_once WPLB_DIR . 'includes/class-wplb-import.php';
require_once WPLB_DIR . 'includes/class-wplb-qrz-client.php';
require_once WPLB_DIR . 'includes/class-wplb-qrz-sync.php';
require_once WPLB_DIR . 'includes/class-wplb-log-view.php';
require_once WPLB_DIR . 'includes/class-wplb-shortcodes.php';
require_once WPLB_DIR . 'includes/class-wplb-plugin.php';

if ( is_admin() ) {
	require_once WPLB_DIR . 'admin/class-wplb-admin.php';
	require_once WPLB_DIR . 'admin/class-wplb-qso-form.php';
}

register_activation_hook( __FILE__, array( 'WPLB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPLB_Install', 'deactivate' ) );

WPLB_Plugin::init();
