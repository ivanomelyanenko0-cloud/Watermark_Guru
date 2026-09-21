<?php
/**
 * Rendering engine: layout maths shared by both image drivers.
 *
 * A driver (GD or Imagick) only knows how to open an image, prepare a layer
 * (text or image) and report its size, draw the prepared layer at x/y, and
 * save. Everything about *where* and *how big* lives here so both drivers
 * produce the same layout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WMGURU_PLUGIN_DIR . 'includes/drivers/class-wmguru-driver-gd.php';
require_once WMGURU_PLUGIN_DIR . 'includes/drivers/class-wmguru-driver-imagick.php';

/**
 * Bundled fonts (SIL OFL, Latin + Cyrillic + Greek).
 *
 * @return array<string,array> key => array( 'label', 'file' )
 */
function wmguru_font_choices() {
	$dir = WMGURU_PLUGIN_DIR . 'fonts/';

	return apply_filters(
		'wmguru_font_choices',
		array(
			'noto-sans'       => array(
				'label' => 'Noto Sans',
				'file'  => $dir . 'NotoSans-Regular.ttf',
			),
			'noto-sans-bold'  => array(
				'label' => 'Noto Sans Bold',
				'file'  => $dir . 'NotoSans-Bold.ttf',
			),
			'noto-serif-bold' => array(
				'label' => 'Noto Serif Bold',
				'file'  => $dir . 'NotoSerif-Bold.ttf',
			),
		)
	);
}

/**
 * Largest image (width x height) that will be processed; protects against
 * out-of-memory on huge originals.
 *
 * @return int
 */
function wmguru_max_pixels() {
	return max( 1000000, (int) apply_filters( 'wmguru_max_pixels', 40000000 ) );
}

/**
 * Which formats each image library can read and write.
 *
 * @return array array( 'gd' => array( mime => bool ), 'imagick' => array( mime => bool ) )
 */
function wmguru_image_support() {
	static $support = null;

	if ( null !== $support ) {
		return $support;
	}

	$mimes   = array(
		'image/jpeg' => array( 'JPEG', 'imagejpeg' ),
		'image/png'  => array( 'PNG', 'imagepng' ),
		'image/gif'  => array( 'GIF', 'imagegif' ),
		'image/webp' => array( 'WEBP', 'imagewebp' ),
		'image/avif' => array( 'AVIF', 'imageavif' ),
	);
	$support = array(
		'gd'      => array(),
		'imagick' => array(),
	);

	$formats = array();
	if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
		try {
			$formats = array_map( 'strtoupper', Imagick::queryFormats() );
		} catch ( Exception $e ) {
			$formats = array();
		}
	}

	foreach ( $mimes as $mime => $info ) {
		$support['gd'][ $mime ]      = extension_loaded( 'gd' ) && function_exists( $info[1] ) && function_exists( 'imagettftext' );
		$support['imagick'][ $mime ] = in_array( $info[0], $formats, true );
	}

	return $support;
}

/**
 * Drivers to try for a mime type, best first.
 *
 * @param string $mime      Mime type.
 * @param string $preferred 'auto', 'gd' or 'imagick'.
 * @return string[]
 */
function wmguru_driver_candidates( $mime, $preferred ) {
	$support = wmguru_image_support();
	$order   = 'gd' === $preferred ? array( 'gd', 'imagick' ) : array( 'imagick', 'gd' );

	return array_values(
		array_filter(
			$order,
			function ( $driver ) use ( $support, $mime ) {
				return ! empty( $support[ $driver ][ $mime ] );
			}
		)
	);
}

/**
 * Where a layer of size $layer_w x $layer_h goes inside a $canvas_w x $canvas_h canvas.
 *
 * @param string $position Grid key such as 'br'.
 * @param int    $canvas_w Canvas width.
 * @param int    $canvas_h Canvas height.
 * @param int    $layer_w  Layer width.
 * @param int    $layer_h  Layer height.
 * @param int    $margin   Margin in px.
 * @return int[] array( x, y )
 */
function wmguru_layout_position( $position, $canvas_w, $canvas_h, $layer_w, $layer_h, $margin ) {
	$row = substr( $position, 0, 1 );
	$col = substr( $position, 1, 1 );

	if ( 'l' === $col ) {
		$x = $margin;
	} elseif ( 'r' === $col ) {
		$x = $canvas_w - $layer_w - $margin;
	} else {
		$x = (int) round( ( $canvas_w - $layer_w ) / 2 );
	}

	if ( 't' === $row ) {
		$y = $margin;
	} elseif ( 'b' === $row ) {
		$y = $canvas_h - $layer_h - $margin;
	} else {
		$y = (int) round( ( $canvas_h - $layer_h ) / 2 );
	}

	return array( max( 0, (int) $x ), max( 0, (int) $y ) );
}

/**
 * Render a watermarked copy of an image.
 *
 * @param string $src       Source path.
 * @param string $dest      Destination path (written in the source's format).
 * @param array  $profile   Profile.
 * @param string $mime      Source mime type.
 * @param array  $overrides Optional 'quality' / 'driver' (used by the live preview).
 * @return true|WP_Error
 */
function wmguru_render_image( $src, $dest, $profile, $mime, $overrides = array() ) {
	$layers = wmguru_effective_layers( $profile );
	if ( ! $layers ) {
		return new WP_Error( 'wmguru_no_layers', __( 'The profile has no layer to draw.', 'watermark-guru' ) );
	}

	wp_raise_memory_limit( 'image' );

	$settings = array_merge( wmguru_get_settings(), $overrides );
	$drivers  = wmguru_driver_candidates( $mime, $settings['driver'] );
	if ( ! $drivers ) {
		return new WP_Error( 'wmguru_no_driver', __( 'No image library supports this file type.', 'watermark-guru' ) );
	}

	$error = null;
	foreach ( $drivers as $name ) {
		$result = wmguru_render_with_driver( $name, $src, $dest, $layers, $mime, (int) $settings['quality'] );
		if ( true === $result ) {
			return true;
		}
		if ( 'wmguru_too_large' === $result->get_error_code() ) {
			return $result;
		}
		$error = $result;
	}

	return $error;
}

/**
 * @param string $name    'gd' or 'imagick'.
 * @param string $src     Source path.
 * @param string $dest    Destination path.
 * @param array  $layers  Effective layers.
 * @param string $mime    Mime type.
 * @param int    $quality Output quality.
 * @return true|WP_Error
 */
function wmguru_render_with_driver( $name, $src, $dest, $layers, $mime, $quality ) {
	$driver = 'imagick' === $name ? new WMGuru_Driver_Imagick( $mime ) : new WMGuru_Driver_GD( $mime );

	$opened = $driver->open( $src );
	if ( is_wp_error( $opened ) ) {
		return $opened;
	}

	$img_w = $driver->width();
	$img_h = $driver->height();

	$drawn = 0;
	foreach ( $layers as $layer ) {
		$opacity = $layer['opacity'] / 100;
		$max     = max( $layer['min_px'], $layer['max_px'] );
		$px      = (int) round( min( $max, max( $layer['min_px'], $img_w * $layer['size'] / 100 ) ) );

		if ( 'text' === $layer['type'] ) {
			$dims = $driver->prepare_text( $layer['text'], wmguru_font_path( $layer['font'] ), $px, (int) $layer['rotation'], $layer['color'], $opacity );
		} else {
			$dims = $driver->prepare_image( $layer['path'], min( $img_w, $px ), (int) $layer['rotation'], $opacity );
		}

		if ( is_wp_error( $dims ) ) {
			wmguru_log_error( $dims->get_error_message() );
			continue;
		}

		list( $x, $y ) = wmguru_layout_position( $layer['position'], $img_w, $img_h, $dims[0], $dims[1], (int) round( min( $img_w, $img_h ) * $layer['margin'] / 100 ) );
		$driver->draw_prepared( $x, $y );
		++$drawn;
	}

	if ( ! $drawn ) {
		$driver->close();
		return new WP_Error( 'wmguru_nothing_drawn', __( 'No layer could be drawn.', 'watermark-guru' ) );
	}

	$saved = $driver->save( $dest, $mime, $quality );
	$driver->close();

	return $saved;
}

/**
 * Keep the last few rendering problems for the settings screen.
 *
 * @param string $message Message.
 */
function wmguru_log_error( $message ) {
	$log   = get_option( 'wmguru_error_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'time'    => time(),
		'message' => mb_substr( $message, 0, 300 ),
	);
	update_option( 'wmguru_error_log', array_slice( $log, -15 ), false );

	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( '[Watermark Guru] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
