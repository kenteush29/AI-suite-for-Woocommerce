<?php
/**
 * Plugin Name:       Restock for WooCommerce
 * Plugin URI:        https://github.com/kenteush29/AI-suite-for-Woocommerce
 * Description:       Restock backlog for WooCommerce: lists out-of-stock product-lines (simple & variable) ranked by total sales, with per-variation drill-down and WPML-aware sales aggregation.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Kula Tactical
 * License:           GPL-2.0-or-later
 * Text Domain:       restock-for-woocommerce
 * Update URI:        https://github.com/kenteush29/AI-suite-for-Woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'RSTK_VERSION', '1.0.0' );
define( 'RSTK_FILE',    __FILE__ );
define( 'RSTK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RSTK_URL',     plugin_dir_url( __FILE__ ) );

// Autoloader: RSTK_Class_Name → includes/class-class-name.php
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'RSTK_' ) !== 0 ) {
		return;
	}
	$file = RSTK_DIR . 'includes/class-' . strtolower( str_replace( [ 'RSTK_', '_' ], [ '', '-' ], $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ 'RSTK_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'RSTK_Plugin', 'deactivate' ] );

final class RSTK_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init(): void {
		load_plugin_textdomain( 'restock-for-woocommerce', false, dirname( plugin_basename( RSTK_FILE ) ) . '/languages' );

		// Update checker runs in admin regardless of WooCommerce so updates always work.
		if ( is_admin() ) {
			RSTK_Updater::instance();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_woo_missing' ] );
			return;
		}

		RSTK_Restock::instance();
	}

	public static function activate(): void {
		// Schedule the weekly sales recalculation.
		if ( ! wp_next_scheduled( RSTK_Restock::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', RSTK_Restock::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( RSTK_Restock::CRON_HOOK );
	}

	public function notice_woo_missing(): void {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Restock for WooCommerce requires WooCommerce to be active.', 'restock-for-woocommerce' ) .
			'</p></div>';
	}
}

RSTK_Plugin::instance();
