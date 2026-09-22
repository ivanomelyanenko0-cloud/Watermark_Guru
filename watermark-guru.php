<?php
/**
 * Plugin Name:       Watermark Guru
 * Plugin URI:        https://cognitolab.net/products/watermark-guru
 * Description:       Non-destructive image watermarks generated on the fly and cached. Your original files are never modified.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            CognitoLab
 * Author URI:        https://cognitolab.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       watermark-guru
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMGURU_VERSION', '1.0.0' );
// Bump when the rendering pipeline changes output: it is part of every cache key.
define( 'WMGURU_ENGINE_VERSION', 1 );
define( 'WMGURU_PLUGIN_FILE', __FILE__ );
define( 'WMGURU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMGURU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WMGURU_PLUGIN_DIR . 'includes/extension-api.php';
require_once WMGURU_PLUGIN_DIR . 'includes/settings.php';
require_once WMGURU_PLUGIN_DIR . 'includes/profile.php';
require_once WMGURU_PLUGIN_DIR . 'includes/cache.php';
require_once WMGURU_PLUGIN_DIR . 'includes/urls.php';
require_once WMGURU_PLUGIN_DIR . 'includes/file-handlers.php';
require_once WMGURU_PLUGIN_DIR . 'includes/engine.php';
require_once WMGURU_PLUGIN_DIR . 'includes/attachments.php';
require_once WMGURU_PLUGIN_DIR . 'includes/rewriter.php';
require_once WMGURU_PLUGIN_DIR . 'includes/delivery.php';
require_once WMGURU_PLUGIN_DIR . 'includes/site-health.php';
require_once WMGURU_PLUGIN_DIR . 'includes/admin.php';

/**
 * No load_plugin_textdomain() call: discouraged since WP 4.6 for plugins
 * hosted on wordpress.org - core auto-loads translations using the plugin slug.
 */

/**
 * Create the cache directory, seed default settings and schedule the two
 * background jobs (stale-cache cleanup, first delivery self-test). Runs for
 * whichever site is "current" when called.
 */
function wmguru_setup_site() {
	add_option( WMGURU_OPTION, wmguru_default_settings() );
	wmguru_ensure_cache_dir();

	if ( ! wp_next_scheduled( 'wmguru_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wmguru_daily_cleanup' );
	}
	if ( ! wp_next_scheduled( 'wmguru_run_delivery_test' ) ) {
		wp_schedule_single_event( time() + 15, 'wmguru_run_delivery_test' );
	}
}

/**
 * WordPress fires the activation hook once, in the "current" site's context
 * only - it does not loop over a network's sites even when activated network
 * wide. Do that ourselves so every existing site gets its defaults and,
 * crucially, its delivery self-test (the thing that auto-detects and works
 * around the subdirectory-multisite rewrite-loop this architecture can hit).
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function wmguru_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( $site_id );
			wmguru_setup_site();
			restore_current_blog();
		}
		return;
	}
	wmguru_setup_site();
}
register_activation_hook( WMGURU_PLUGIN_FILE, 'wmguru_activate' );

/**
 * A new site created after the plugin is already network-active never fires
 * the activation hook for it either. Despite switching to the new site while
 * it sets the site up, WordPress fires this action back in the context of
 * the site the request started from (`wp_insert_site()` calls
 * `wp_initialize_site()`, which restores the original blog, and only then
 * fires 'wp_initialize_site') - so an explicit switch is required here, or
 * `wmguru_setup_site()` runs against the wrong site's options.
 *
 * @param WP_Site $new_site The newly created site.
 */
function wmguru_new_site( $new_site ) {
	$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
	if ( ! isset( $network_plugins[ plugin_basename( WMGURU_PLUGIN_FILE ) ] ) ) {
		return;
	}
	switch_to_blog( $new_site->id );
	wmguru_setup_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'wmguru_new_site', 100 );

/**
 * Deactivation only stops the schedules. URL rewriting stops with the hooks
 * themselves, so visitors get the untouched originals immediately; the cache
 * is left in place (it is removed on uninstall).
 */
function wmguru_clear_site_schedules() {
	wp_clear_scheduled_hook( 'wmguru_daily_cleanup' );
	wp_clear_scheduled_hook( 'wmguru_run_delivery_test' );
}

/**
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 */
function wmguru_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( $site_id );
			wmguru_clear_site_schedules();
			restore_current_blog();
		}
		return;
	}
	wmguru_clear_site_schedules();
}
register_deactivation_hook( WMGURU_PLUGIN_FILE, 'wmguru_deactivate' );
