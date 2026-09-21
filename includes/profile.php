<?php
/**
 * Profiles: the unit a cache key is derived from.
 *
 * key = "{profile id}-{8 hex}". The hex part hashes everything that changes
 * the rendered pixels, so editing a setting (or the year rolling over in a
 * {year} token) produces brand-new URLs; old cache is swept by cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The built-in profile, backed by the Free settings screen.
 *
 * @return array
 */
function wmguru_default_profile() {
	$settings = wmguru_get_settings();

	return array(
		'id'     => 'default',
		'label'  => __( 'Default', 'watermark-guru' ),
		'layers' => $settings['layers'],
	);
}

/**
 * @return array<string,array> profile id => profile
 */
function wmguru_get_profiles() {
	$profiles = apply_filters( 'wmguru_profiles', array( 'default' => wmguru_default_profile() ) );

	return is_array( $profiles ) ? $profiles : array( 'default' => wmguru_default_profile() );
}

/**
 * @param string $profile_id Profile id.
 * @return array|null
 */
function wmguru_get_profile( $profile_id ) {
	$profiles = wmguru_get_profiles();

	return isset( $profiles[ $profile_id ] ) ? $profiles[ $profile_id ] : null;
}

/**
 * Expand the static text tokens.
 *
 * @param string $text Layer text.
 * @return string
 */
function wmguru_expand_tokens( $text ) {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	return strtr(
		$text,
		array(
			'{site}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{year}' => wp_date( 'Y' ),
			'{url}'  => $host ? $host : '',
		)
	);
}

/**
 * Absolute path of a font key, or '' when unknown.
 *
 * @param string $font Font key.
 * @return string
 */
function wmguru_font_path( $font ) {
	$fonts = wmguru_font_choices();

	return isset( $fonts[ $font ]['file'] ) && is_readable( $fonts[ $font ]['file'] ) ? $fonts[ $font ]['file'] : '';
}

/**
 * Layers that will actually draw something: enabled, and with usable content.
 * Text is returned with its tokens already expanded.
 *
 * @param array $profile Profile.
 * @return array
 */
function wmguru_effective_layers( $profile ) {
	$layers = array();

	foreach ( isset( $profile['layers'] ) ? (array) $profile['layers'] : array() as $layer ) {
		if ( empty( $layer['enabled'] ) || empty( $layer['type'] ) ) {
			continue;
		}

		if ( 'text' === $layer['type'] ) {
			$layer['text'] = trim( wmguru_expand_tokens( isset( $layer['text'] ) ? $layer['text'] : '' ) );
			if ( '' === $layer['text'] || '' === wmguru_font_path( isset( $layer['font'] ) ? $layer['font'] : '' ) ) {
				continue;
			}
		} elseif ( 'image' === $layer['type'] ) {
			$path = ! empty( $layer['attachment_id'] ) ? get_attached_file( (int) $layer['attachment_id'] ) : '';
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}
			$layer['path']  = $path;
			$layer['mtime'] = (int) filemtime( $path );
		} else {
			continue;
		}

		$layers[] = $layer;
	}

	return $layers;
}

/**
 * Short hash of everything that affects the rendered output.
 *
 * @param array $profile Profile.
 * @return string 8 hex chars.
 */
function wmguru_profile_hash( $profile ) {
	$settings = wmguru_get_settings();
	$data     = array(
		'engine'  => WMGURU_ENGINE_VERSION,
		'quality' => (int) $settings['quality'],
		'driver'  => $settings['driver'],
		'layers'  => wmguru_effective_layers( $profile ),
	);
	$data     = apply_filters( 'wmguru_profile_hash_data', $data, $profile );

	return substr( md5( wp_json_encode( $data ) ), 0, 8 );
}

/**
 * Cache key used in URLs and on disk: "{id}-{hash}". Memoized per request;
 * any settings change flushes the memo.
 *
 * @param array|null $profile Profile, or null together with $flush.
 * @param bool       $flush   Drop the memo.
 * @return string
 */
function wmguru_profile_key( $profile, $flush = false ) {
	static $memo = array();

	if ( $flush ) {
		$memo = array();
		return '';
	}

	$id = $profile['id'];
	if ( ! isset( $memo[ $id ] ) ) {
		$memo[ $id ] = $id . '-' . wmguru_profile_hash( $profile );
	}

	return $memo[ $id ];
}

/**
 * Forget memoized keys when settings are written mid-request.
 */
function wmguru_flush_profile_keys() {
	wmguru_profile_key( null, true );
}
add_action( 'update_option_' . WMGURU_OPTION, 'wmguru_flush_profile_keys' );
add_action( 'add_option_' . WMGURU_OPTION, 'wmguru_flush_profile_keys' );

/**
 * Split a key into id and hash.
 *
 * @param string $key Cache key.
 * @return array|null array( id, hash ) or null when malformed.
 */
function wmguru_split_key( $key ) {
	if ( ! preg_match( '/^([a-z0-9_]+)-([a-f0-9]{8})$/', $key, $m ) ) {
		return null;
	}

	return array( $m[1], $m[2] );
}
