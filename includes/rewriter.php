<?php
/**
 * Front-end URL rewriting: swaps upload URLs for watermarked cache URLs at
 * output time. Nothing is written to the Media Library or the database, so
 * deactivating the plugin restores the original URLs instantly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request should receive watermarked URLs.
 *
 * Only safe front-end reads qualify. Anything that can persist a URL (POST,
 * WP-CLI, cron, admin, the block editor) keeps original URLs, so a cache URL
 * never ends up stored in post content.
 *
 * @return bool
 */
function wmguru_should_rewrite() {
	static $memo = null;

	if ( null === $memo ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$memo   = wmguru_is_enabled()
			&& in_array( $method, array( 'GET', 'HEAD' ), true )
			&& ! is_admin()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI );

		// The block editor reads media through the REST API; keep it on originals.
		if ( $memo && defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'upload_files' ) ) {
			$memo = false;
		}

		$memo = (bool) apply_filters( 'wmguru_should_rewrite', $memo );
	}

	return $memo;
}

/**
 * Swap an upload URL for its watermarked cache URL when the file qualifies.
 *
 * Idempotent: an existing cache URL is first mapped back to the original and
 * decided again, because the size it points at may not qualify. When the
 * request gets no watermarks the original URL is returned.
 *
 * @param string $url           URL.
 * @param int    $attachment_id Attachment id, 0 to look it up.
 * @return string
 */
function wmguru_rewrite_url( $url, $attachment_id = 0 ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	$original = wmguru_original_url( $url );
	$rel      = wmguru_url_to_rel( $original );
	if ( false === $rel ) {
		return $url;
	}

	// Not a request that gets watermarks: hand back the original, which also
	// undoes any cache URL that was stored in content earlier.
	if ( ! wmguru_should_rewrite() ) {
		return $original;
	}

	$attachment_id = $attachment_id ? (int) $attachment_id : wmguru_attachment_id_from_rel( $rel );
	if ( ! $attachment_id ) {
		return $original;
	}

	$profile = wmguru_watermark_profile_for( $attachment_id, $rel );
	if ( ! $profile ) {
		return $original;
	}

	$rewritten = wmguru_build_url( wmguru_profile_key( $profile ), $rel );

	// Keep a query string (e.g. ?ver=) that the original URL carried.
	$query = wp_parse_url( $url, PHP_URL_QUERY );
	if ( $query && false === strpos( $rewritten, '?' ) ) {
		$rewritten .= '?' . $query;
	}

	return $rewritten;
}

/**
 * @param string $url           Attachment URL.
 * @param int    $attachment_id Attachment id.
 * @return string
 */
function wmguru_filter_attachment_url( $url, $attachment_id ) {
	return wmguru_rewrite_url( $url, $attachment_id );
}
add_filter( 'wp_get_attachment_url', 'wmguru_filter_attachment_url', 20, 2 );

/**
 * @param array|false $image         array( url, width, height, is_intermediate ).
 * @param int         $attachment_id Attachment id.
 * @return array|false
 */
function wmguru_filter_image_src( $image, $attachment_id ) {
	if ( is_array( $image ) && ! empty( $image[0] ) ) {
		$image[0] = wmguru_rewrite_url( $image[0], $attachment_id );
	}

	return $image;
}
add_filter( 'wp_get_attachment_image_src', 'wmguru_filter_image_src', 20, 2 );

/**
 * @param array|false $sources       srcset sources.
 * @param array       $size_array    Size.
 * @param string      $image_src     Src.
 * @param array       $image_meta    Meta.
 * @param int         $attachment_id Attachment id.
 * @return array|false
 */
function wmguru_filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	if ( ! is_array( $sources ) ) {
		return $sources;
	}

	foreach ( $sources as $width => $source ) {
		$sources[ $width ]['url'] = wmguru_rewrite_url( $source['url'], $attachment_id );
	}

	return $sources;
}
add_filter( 'wp_calculate_image_srcset', 'wmguru_filter_srcset', 20, 5 );

/**
 * @param string $srcset        srcset attribute value.
 * @param int    $attachment_id Attachment id, 0 to look up per URL.
 * @return string
 */
function wmguru_rewrite_srcset_string( $srcset, $attachment_id ) {
	$entries = explode( ',', $srcset );

	foreach ( $entries as $i => $entry ) {
		$bits = preg_split( '/\s+/', trim( $entry ), 2 );
		if ( ! $bits || '' === $bits[0] ) {
			continue;
		}
		$bits[0]       = wmguru_rewrite_url( $bits[0], $attachment_id );
		$entries[ $i ] = implode( ' ', $bits );
	}

	return implode( ', ', $entries );
}

/**
 * Rewrite <img> src/srcset inside post content. Runs after core has added
 * srcset (priority 10) so both attributes are final.
 *
 * @param string $content Post content.
 * @return string
 */
function wmguru_filter_content( $content ) {
	if ( ! is_string( $content ) || false === strpos( $content, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $content;
	}

	$uploads = wmguru_uploads();
	$path    = $uploads ? wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) : '';
	if ( ! $path || false === strpos( $content, $path ) ) {
		return $content;
	}

	// Without watermarks there is only work to do for cache URLs stored earlier.
	if ( ! wmguru_should_rewrite() && false === strpos( $content, '/wmguru-cache/' ) ) {
		return $content;
	}

	$processor = new WP_HTML_Tag_Processor( $content );
	while ( $processor->next_tag( 'img' ) ) {
		$attachment_id = 0;
		$class         = $processor->get_attribute( 'class' );
		if ( is_string( $class ) && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $m ) ) {
			$attachment_id = (int) $m[1];
		}

		$src = $processor->get_attribute( 'src' );
		if ( is_string( $src ) ) {
			$new = wmguru_rewrite_url( $src, $attachment_id );
			if ( $new !== $src ) {
				$processor->set_attribute( 'src', $new );
			}
		}

		$srcset = $processor->get_attribute( 'srcset' );
		if ( is_string( $srcset ) && '' !== $srcset ) {
			$new = wmguru_rewrite_srcset_string( $srcset, $attachment_id );
			if ( $new !== $srcset ) {
				$processor->set_attribute( 'srcset', $new );
			}
		}
	}

	return $processor->get_updated_html();
}
add_filter( 'the_content', 'wmguru_filter_content', 20 );
