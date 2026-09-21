<?php
/**
 * Settings: defaults, accessors and sanitization.
 *
 * A "profile" is an ordered list of layers (text and/or image). Free ships one
 * profile ("default") capped at two layers; Pro raises the cap and adds more
 * profiles through the filters in extension-api.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMGURU_OPTION', 'wmguru_settings' );

/**
 * Nine-cell placement grid.
 *
 * @return array<string,string> key => label
 */
function wmguru_positions() {
	return array(
		'tl' => __( 'Top left', 'watermark-guru' ),
		'tc' => __( 'Top center', 'watermark-guru' ),
		'tr' => __( 'Top right', 'watermark-guru' ),
		'ml' => __( 'Middle left', 'watermark-guru' ),
		'mc' => __( 'Center', 'watermark-guru' ),
		'mr' => __( 'Middle right', 'watermark-guru' ),
		'bl' => __( 'Bottom left', 'watermark-guru' ),
		'bc' => __( 'Bottom center', 'watermark-guru' ),
		'br' => __( 'Bottom right', 'watermark-guru' ),
	);
}

/**
 * @param string $type 'text' or 'image'.
 * @return array
 */
function wmguru_default_layer( $type ) {
	$common = array(
		'type'     => $type,
		'enabled'  => false,
		'opacity'  => 70,
		'position' => 'br',
		'margin'   => 2.0,
		'rotation' => 0,
	);

	if ( 'image' === $type ) {
		return array_merge(
			$common,
			array(
				'attachment_id' => 0,
				'size'          => 18.0,
				'min_px'        => 48,
				'max_px'        => 600,
				'position'      => 'bl',
			)
		);
	}

	return array_merge(
		$common,
		array(
			'enabled' => true,
			'text'    => '© {site}',
			'font'    => 'noto-sans',
			'color'   => '#ffffff',
			'size'    => 3.5,
			'min_px'  => 12,
			'max_px'  => 96,
		)
	);
}

/**
 * @return array
 */
function wmguru_default_settings() {
	return array(
		'enabled'               => false,
		'layers'                => array( wmguru_default_layer( 'text' ), wmguru_default_layer( 'image' ) ),
		'sizes'                 => array( 'medium', 'medium_large', 'large', 'full' ),
		'min_width'             => 300,
		'min_height'            => 200,
		'quality'               => 88,
		'driver'                => 'auto',
		'delivery'              => 'auto',
		'remove_data_uninstall' => false,
	);
}

/**
 * Saved settings merged over the defaults.
 *
 * @return array
 */
function wmguru_get_settings() {
	$defaults = wmguru_default_settings();
	$saved    = get_option( WMGURU_OPTION, array() );

	if ( ! is_array( $saved ) ) {
		return $defaults;
	}

	$settings = array_merge( $defaults, $saved );
	if ( ! is_array( $settings['layers'] ) ) {
		$settings['layers'] = $defaults['layers'];
	}
	if ( ! is_array( $settings['sizes'] ) ) {
		$settings['sizes'] = $defaults['sizes'];
	}

	return $settings;
}

/**
 * @return bool
 */
function wmguru_is_enabled() {
	$settings = wmguru_get_settings();
	return ! empty( $settings['enabled'] );
}

/**
 * Maximum number of layers a profile may hold.
 *
 * @param string $profile_id Profile id.
 * @return int
 */
function wmguru_max_layers( $profile_id = 'default' ) {
	return max( 1, (int) apply_filters( 'wmguru_max_layers', 2, $profile_id ) );
}

/**
 * Registered image sizes plus 'full' (which also covers -scaled and originals).
 *
 * @return array<string,string> size name => label
 */
function wmguru_available_sizes() {
	$sizes = array();
	foreach ( get_intermediate_image_sizes() as $name ) {
		$sizes[ $name ] = ucwords( str_replace( array( '_', '-' ), ' ', $name ) );
	}
	$sizes['full'] = __( 'Full size', 'watermark-guru' );

	return $sizes;
}

/**
 * Sanitize one layer. Returns null when the type is unknown.
 *
 * @param mixed $raw Raw layer input.
 * @return array|null
 */
function wmguru_sanitize_layer( $raw ) {
	if ( ! is_array( $raw ) || empty( $raw['type'] ) || ! in_array( $raw['type'], array( 'text', 'image' ), true ) ) {
		return null;
	}

	$type  = $raw['type'];
	$layer = wmguru_default_layer( $type );

	$layer['enabled']  = ! empty( $raw['enabled'] );
	$layer['opacity']  = min( 100, max( 1, (int) ( $raw['opacity'] ?? $layer['opacity'] ) ) );
	$layer['margin']   = min( 30.0, max( 0.0, (float) ( $raw['margin'] ?? $layer['margin'] ) ) );
	$layer['rotation'] = min( 180, max( -180, (int) ( $raw['rotation'] ?? $layer['rotation'] ) ) );
	$layer['size']     = min( 100.0, max( 0.5, (float) ( $raw['size'] ?? $layer['size'] ) ) );
	$layer['min_px']   = min( 4000, max( 1, (int) ( $raw['min_px'] ?? $layer['min_px'] ) ) );
	$layer['max_px']   = min( 8000, max( $layer['min_px'], (int) ( $raw['max_px'] ?? $layer['max_px'] ) ) );

	$position          = isset( $raw['position'] ) ? sanitize_key( $raw['position'] ) : $layer['position'];
	$layer['position'] = array_key_exists( $position, wmguru_positions() ) ? $position : $layer['position'];

	if ( 'text' === $type ) {
		$layer['text'] = isset( $raw['text'] ) ? sanitize_text_field( $raw['text'] ) : $layer['text'];
		$layer['text'] = mb_substr( $layer['text'], 0, 200 );

		$font           = isset( $raw['font'] ) ? sanitize_key( $raw['font'] ) : $layer['font'];
		$layer['font']  = array_key_exists( $font, wmguru_font_choices() ) ? $font : 'noto-sans';
		$color          = isset( $raw['color'] ) ? sanitize_hex_color( $raw['color'] ) : '';
		$layer['color'] = $color ? $color : '#ffffff';
	} else {
		$layer['attachment_id'] = absint( $raw['attachment_id'] ?? 0 );
	}

	return $layer;
}

/**
 * Sanitize a list of layers, dropping unknown ones and enforcing the cap.
 *
 * @param mixed  $raw_layers Raw layer list.
 * @param string $profile_id Profile the layers belong to (for the layer cap).
 * @return array
 */
function wmguru_sanitize_layers( $raw_layers, $profile_id = 'default' ) {
	$layers = array();
	if ( ! is_array( $raw_layers ) ) {
		return $layers;
	}

	foreach ( array_values( $raw_layers ) as $raw ) {
		$layer = wmguru_sanitize_layer( $raw );
		if ( null !== $layer ) {
			$layers[] = $layer;
		}
		if ( count( $layers ) >= wmguru_max_layers( $profile_id ) ) {
			break;
		}
	}

	return $layers;
}

/**
 * Sanitize callback for the wmguru_settings option. Pure: no side effects, so
 * the live preview can reuse it on unsaved form data.
 *
 * @param mixed $input Raw form input.
 * @return array
 */
function wmguru_sanitize_settings( $input ) {
	$defaults = wmguru_default_settings();
	$input    = is_array( $input ) ? $input : array();

	$clean = array(
		'enabled'               => ! empty( $input['enabled'] ),
		'remove_data_uninstall' => ! empty( $input['remove_data_uninstall'] ),
		'min_width'             => min( 10000, max( 0, (int) ( $input['min_width'] ?? $defaults['min_width'] ) ) ),
		'min_height'            => min( 10000, max( 0, (int) ( $input['min_height'] ?? $defaults['min_height'] ) ) ),
		'quality'               => min( 100, max( 30, (int) ( $input['quality'] ?? $defaults['quality'] ) ) ),
	);

	$driver          = isset( $input['driver'] ) ? sanitize_key( $input['driver'] ) : 'auto';
	$clean['driver'] = in_array( $driver, array( 'auto', 'gd', 'imagick' ), true ) ? $driver : 'auto';

	$delivery          = isset( $input['delivery'] ) ? sanitize_key( $input['delivery'] ) : 'auto';
	$clean['delivery'] = in_array( $delivery, array( 'auto', 'pretty', 'query' ), true ) ? $delivery : 'auto';

	$known_sizes    = array_keys( wmguru_available_sizes() );
	$posted_sizes   = isset( $input['sizes'] ) && is_array( $input['sizes'] ) ? array_map( 'sanitize_key', $input['sizes'] ) : array();
	$clean['sizes'] = array_values( array_intersect( $known_sizes, $posted_sizes ) );

	$clean['layers'] = wmguru_sanitize_layers( $input['layers'] ?? array(), 'default' );

	return $clean;
}
