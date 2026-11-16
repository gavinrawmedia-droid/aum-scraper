<?php
/**
 * Plugin Name:       AUM Directory
 * Description:       Approved-used mobility listings and dealers for Approved Used Mobility. Receives stock from the dealer scraper, tracks clicks through to dealers and adds structured data for search engines.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Raw Media
 * License:           GPL-2.0-or-later
 * Text Domain:       aum-directory
 */

defined( 'ABSPATH' ) || exit;

define( 'AUM_DIR_VERSION', '0.1.0' );
define( 'AUM_DIR_FILE', __FILE__ );
define( 'AUM_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'AUM_DIR_URL', plugin_dir_url( __FILE__ ) );

require_once AUM_DIR_PATH . 'includes/fields.php';
require_once AUM_DIR_PATH . 'includes/content-types.php';
require_once AUM_DIR_PATH . 'includes/import.php';
require_once AUM_DIR_PATH . 'includes/clicks.php';
require_once AUM_DIR_PATH . 'includes/schema.php';
require_once AUM_DIR_PATH . 'includes/display.php';
require_once AUM_DIR_PATH . 'includes/admin.php';

register_activation_hook( __FILE__, 'aum_dir_activate' );
register_deactivation_hook( __FILE__, 'aum_dir_deactivate' );

function aum_dir_activate(): void {
	aum_register_content_types();
	aum_create_default_terms();
	aum_install_click_table();
	aum_add_roles_and_caps();
	aum_clicks_add_rewrite();
	flush_rewrite_rules();
	update_option( 'aum_dir_version', AUM_DIR_VERSION );
}

function aum_dir_deactivate(): void {
	flush_rewrite_rules();
}

/** Run upgrades when a new version of the plugin is uploaded over an old one. */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'aum_dir_version' ) !== AUM_DIR_VERSION ) {
		aum_install_click_table();
		aum_add_roles_and_caps();
		update_option( 'aum_dir_version', AUM_DIR_VERSION );
		update_option( 'aum_dir_flush_rewrites', 1 );
	}
} );

add_action( 'init', function () {
	if ( get_option( 'aum_dir_flush_rewrites' ) ) {
		delete_option( 'aum_dir_flush_rewrites' );
		flush_rewrite_rules();
	}
}, 99 );
