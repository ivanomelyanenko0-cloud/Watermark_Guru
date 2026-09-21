<?php
/**
 * Uninstall handler.
 *
 * The watermark cache is derived data, so it is always removed. Settings and
 * the per-image "do not watermark" flags are removed only when the admin ticked
 * "Also delete these settings" - they are kept by default.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * @param string $dir Directory to delete recursively.
 */
function wmguru_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			wmguru_uninstall_rrmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Runs once per site; wrapped in a function so its variables are not globals.
 */
function wmguru_uninstall_site() {
	$uploads = wp_get_upload_dir();
	if ( empty( $uploads['error'] ) ) {
		wmguru_uninstall_rrmdir( $uploads['basedir'] . '/wmguru-cache' );
	}

	$settings = get_option( 'wmguru_settings' );
	$remove   = is_array( $settings ) && ! empty( $settings['remove_data_uninstall'] );

	delete_option( 'wmguru_delivery_status' );
	delete_option( 'wmguru_error_log' );
	wp_clear_scheduled_hook( 'wmguru_daily_cleanup' );
	wp_clear_scheduled_hook( 'wmguru_run_delivery_test' );

	if ( $remove ) {
		delete_option( 'wmguru_settings' );
		delete_post_meta_by_key( '_wmguru_exclude' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $wmguru_site_id ) {
		switch_to_blog( $wmguru_site_id );
		wmguru_uninstall_site();
		restore_current_blog();
	}
} else {
	wmguru_uninstall_site();
}
