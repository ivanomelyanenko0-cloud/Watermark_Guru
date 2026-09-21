<?php
/**
 * File handler registry.
 *
 * The delivery pipeline (key -> cache -> serve) is file-type agnostic; a
 * handler is just a callable that turns a source file into a watermarked copy:
 *
 *     callable( string $src, string $dest, array $profile, array $context ): true|WP_Error
 *
 * $context carries 'mime', 'attachment_id' and 'rel'. Free registers the raster
 * image types; other plugins add more types through the wmguru_file_handlers filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string,callable> mime type => handler
 */
function wmguru_get_file_handlers() {
	$handlers = apply_filters(
		'wmguru_file_handlers',
		array(
			'image/jpeg' => 'wmguru_handle_image',
			'image/png'  => 'wmguru_handle_image',
			'image/gif'  => 'wmguru_handle_image',
			'image/webp' => 'wmguru_handle_image',
			'image/avif' => 'wmguru_handle_image',
		)
	);

	return array_filter( is_array( $handlers ) ? $handlers : array(), 'is_callable' );
}

/**
 * @param string $mime Mime type.
 * @return callable|null
 */
function wmguru_get_file_handler( $mime ) {
	$handlers = wmguru_get_file_handlers();

	return isset( $handlers[ $mime ] ) ? $handlers[ $mime ] : null;
}

/**
 * Handler for raster images. Animated GIF/WebP cannot be re-encoded frame by
 * frame here, so they are cached as an untouched copy (served fast, not marked).
 *
 * @param string $src     Source path.
 * @param string $dest    Destination path.
 * @param array  $profile Profile.
 * @param array  $context Context.
 * @return true|WP_Error
 */
function wmguru_handle_image( $src, $dest, $profile, $context ) {
	$mime = $context['mime'];

	if ( wmguru_is_animated( $src, $mime ) ) {
		return copy( $src, $dest ) ? true : new WP_Error( 'wmguru_copy_failed', __( 'Could not copy the animated image.', 'watermark-guru' ) );
	}

	return wmguru_render_image( $src, $dest, $profile, $mime );
}

/**
 * Cheap animation check for GIF (more than one graphic-control block) and
 * WebP (animation flag in the VP8X header).
 *
 * @param string $path File path.
 * @param string $mime Mime type.
 * @return bool
 */
function wmguru_is_animated( $path, $mime ) {
	if ( 'image/webp' === $mime ) {
		$head = file_get_contents( $path, false, null, 0, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $head ) && strlen( $head ) >= 21 && 'RIFF' === substr( $head, 0, 4 ) && 'VP8X' === substr( $head, 12, 4 ) && ( ord( $head[20] ) & 0x02 );
	}

	if ( 'image/gif' === $mime ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// One graphic-control block (21 F9 04 ....  00) followed by an image descriptor or extension per frame.
		return is_string( $contents ) && preg_match_all( '#\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $contents ) > 1;
	}

	return false;
}
