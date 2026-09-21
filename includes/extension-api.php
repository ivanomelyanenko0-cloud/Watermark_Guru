<?php
/**
 * Free extension API consumed by Watermark Guru Pro.
 *
 * wmguru_register_pro_slot() is a validated wrapper over add_filter()/add_action()
 * against a documented registry of extension points below. It never carries
 * any conditional "is premium" logic - it fires unconditionally for whoever
 * hooks in, keeping the Free plugin fully self-contained.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documented extension points a Pro (or any third-party) plugin can hook into.
 *
 * @return array<string,string> Hook name mapped to filter or action.
 */
function wmguru_get_extension_points() {
	return apply_filters(
		'wmguru_extension_points_registry',
		array(
			// (array $profiles) => array   profile id mapped to a profile array (id, label, layers).
			'wmguru_profiles'             => 'filter',
			// (int $max, string $profile_id) => int   maximum layers a profile may hold.
			'wmguru_max_layers'           => 'filter',
			// (string $profile_id, int $attachment_id) => string   empty or the word none disables watermarking.
			'wmguru_profile_for_attachment' => 'filter',
			// (array $handlers) => array   mime type mapped to a handler callable.
			'wmguru_file_handlers'        => 'filter',
			// (array $fonts) => array   font key mapped to label and file path.
			'wmguru_font_choices'         => 'filter',
			// (bool $rewrite) => bool   whether the current request should get watermarked URLs.
			'wmguru_should_rewrite'       => 'filter',
			// (array $data, array $profile) => array   extra data folded into a profile's cache key.
			'wmguru_profile_hash_data'    => 'filter',
			// (int $pixels) => int   largest image (width x height) that will be processed.
			'wmguru_max_pixels'           => 'filter',
			// Fires at the bottom of the settings page, inside the wrap.
			'wmguru_settings_page_after'  => 'action',
			// Fires after the cache directory has been emptied.
			'wmguru_cache_purged'         => 'action',
		)
	);
}

/**
 * Register a callback against a documented Watermark Guru extension point.
 *
 * @param string   $hook          One of the keys returned by wmguru_get_extension_points().
 * @param callable $callback      Callback to attach.
 * @param int      $priority      Hook priority.
 * @param int      $accepted_args Number of arguments the callback accepts.
 * @return bool True on success, false if the hook is undocumented or the callback isn't callable.
 */
function wmguru_register_pro_slot( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$points = wmguru_get_extension_points();

	if ( ! isset( $points[ $hook ] ) || ! is_callable( $callback ) ) {
		return false;
	}

	if ( 'action' === $points[ $hook ] ) {
		add_action( $hook, $callback, $priority, $accepted_args );
	} else {
		add_filter( $hook, $callback, $priority, $accepted_args );
	}

	return true;
}
