<?php
/**
 * Plugin Name:       DB Recesso 54-bis
 * Plugin URI:        https://www.davidebertolino.it/progetti/
 * Description:        Funzione digitale di recesso conforme all'art. 54-bis del Codice del Consumo (D.Lgs. 209/2025). Aggiunge a WooCommerce un pulsante di recesso, dichiarazione guidata, ricevuta su supporto durevole e integrazione privacy. Self-contained, zero dipendenze esterne.
 * Version:           1.3.0
 * Author:            Davide Bertolino
 * Author URI:        https://www.davidebertolino.it
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       db-recesso-54bis
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy capabilities (per references/PRIVACY-INTEGRATION.md):
 *  - Personal data:        YES — wp_dbr54_recessi, wp_dbr54_garanzia (order ref,
 *                          guest email, reason/defect text) + PDF receipts
 *  - Third-party scripts:  NO  — optional RFC 3161 TSA call is server-side only
 *  - User consent:         NO  — legal bases art. 6.1.b / 6.1.c GDPR
 *  - DSAR-aware:           YES — DBR54_Privacy (export; erase declares retention, art. 17.3.e)
 *  - Hub-aware:            YES — dbph_processing_register (+ legacy dbseo_processing_register)
 *  - Retention:            daily cron purge after retention_years
 */
define( 'DBR54_DSAR_AVAILABLE', true );

define( 'DBR54_VERSION', '1.3.0' );
define( 'DBR54_FILE', __FILE__ );
define( 'DBR54_PATH', plugin_dir_path( __FILE__ ) );
define( 'DBR54_URL', plugin_dir_url( __FILE__ ) );
define( 'DBR54_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				DBR54_FILE,
				true
			);
		}
	}
);

/**
 * Bootstrap: verify WooCommerce is active, then load the plugin.
 */
add_action( 'plugins_loaded', 'dbr54_bootstrap', 20 );
function dbr54_bootstrap() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'DB Recesso 54-bis richiede WooCommerce attivo per funzionare.', 'db-recesso-54bis' );
				echo '</p></div>';
			}
		);
		return;
	}

	// Load classes.
	require_once DBR54_PATH . 'inc/class-updater.php';
	require_once DBR54_PATH . 'inc/class-db.php';
	require_once DBR54_PATH . 'inc/class-settings.php';
	require_once DBR54_PATH . 'inc/class-receipt.php';
	require_once DBR54_PATH . 'inc/class-guest-guard.php';
	require_once DBR54_PATH . 'inc/class-recesso.php';
	require_once DBR54_PATH . 'inc/class-frontend.php';
	require_once DBR54_PATH . 'inc/class-admin.php';
	require_once DBR54_PATH . 'inc/class-privacy.php';
	require_once DBR54_PATH . 'inc/class-admin-core.php';
	require_once DBR54_PATH . 'inc/class-garanzia-db.php';
	require_once DBR54_PATH . 'inc/class-garanzia.php';
	require_once DBR54_PATH . 'inc/class-garanzia-frontend.php';
	require_once DBR54_PATH . 'inc/class-garanzia-admin.php';

	// GitHub auto-updater (shared component).
	if ( class_exists( 'DB_GitHub_Updater' ) ) {
		new DB_GitHub_Updater( DBR54_FILE, 'dadebertolino', 'db-recesso-54bis' );
	}

	// Settings + shared privacy first (privacy needs to know which modules are on).
	DBR54_Settings::instance();
	DBR54_Privacy::instance();

	// Always-on admin core: settings page + module toggles, regardless of modules.
	if ( is_admin() ) {
		DBR54_Admin_Core::instance();
	}

	// Modulo A — Recesso (art. 54-bis). Independently toggleable.
	if ( DBR54_Settings::instance()->get( 'module_recesso_enabled', true ) ) {
		DBR54_Recesso::instance();
		DBR54_Frontend::instance();
		if ( is_admin() ) {
			DBR54_Admin::instance();
		}
	}

	// Modulo B — Garanzia legale (art. 128-135). Independently toggleable, off by default.
	if ( DBR54_Settings::instance()->get( 'module_garanzia_enabled', false ) ) {
		DBR54_Garanzia::instance();
		DBR54_Garanzia_Frontend::instance();
		if ( is_admin() ) {
			DBR54_Garanzia_Admin::instance();
		}
	}

	// Flush rewrite rules once after a module toggle changed the endpoints.
	if ( get_option( 'dbr54_flush_needed' ) ) {
		delete_option( 'dbr54_flush_needed' );
		add_action( 'wp_loaded', 'flush_rewrite_rules' );
	}

	load_plugin_textdomain( 'db-recesso-54bis', false, dirname( DBR54_BASENAME ) . '/languages' );

	// Lightweight DB migration: ensure both tables exist after updates that
	// don't re-run the activation hook (e.g. GitHub auto-updater).
	if ( is_admin() && version_compare( (string) get_option( 'dbr54_db_version', '0' ), DBR54_DB::DB_VERSION, '<' ) ) {
		DBR54_DB::create_table();
		DBR54_Garanzia_DB::create_table();
		update_option( 'dbr54_db_version', DBR54_DB::DB_VERSION );
		update_option( 'dbr54_flush_needed', 1 );
	}
}

/**
 * Activation: create table, add rewrite endpoint, flush rules.
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once DBR54_PATH . 'inc/class-db.php';
		require_once DBR54_PATH . 'inc/class-garanzia-db.php';
		DBR54_DB::create_table();
		DBR54_Garanzia_DB::create_table();

		// Register endpoints then flush so both routes work immediately.
		add_rewrite_endpoint( 'recesso', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'garanzia', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();

		add_option( 'dbr54_db_version', DBR54_DB::DB_VERSION );
	}
);

/**
 * Deactivation: flush rewrite rules and unschedule the retention purge.
 * Data is preserved.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'dbr54_retention_purge' );
		flush_rewrite_rules();
	}
);
