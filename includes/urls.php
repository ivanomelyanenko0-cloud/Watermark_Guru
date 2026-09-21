<?php
/**
 * URL mapping between original upload URLs and watermarked cache URLs.
 *
 * Two delivery modes share the same cache:
 *  - pretty: {uploads}/wmguru-cache/{key}/{rel}   (a cache miss falls through to WordPress via the standard rewrite)
 *  - query : /?wmguru_k={key}&wmguru_f={base64url rel}&wmguru_s={hmac}   (for hosts that 404 static files themselves)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upload directory info for the current site, memoized per blog.
 *
 * @return array|null array( 'basedir', 'baseurl' ) or null on error.
 */
function wmguru_uploads() {
	static $memo = array();

	$blog = get_current_blog_id();
	if ( ! array_key_exists( $blog, $memo ) ) {
		$uploads      = wp_get_upload_dir();
		$memo[ $blog ] = empty( $uploads['error'] ) ? array(
			'basedir' => untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ),
			'baseurl' => untrailingslashit( $uploads['baseurl'] ),
		) : null;
	}

	return $memo[ $blog ];
}

/**
 * Remove the scheme so http/https variants of the same URL compare equal.
 *
 * @param string $url URL.
 * @return string
 */
function wmguru_strip_scheme( $url ) {
	return preg_replace( '#^https?:#i', '', $url );
}

/**
 * Path of an original upload URL relative to the uploads directory.
 *
 * @param string $url URL.
 * @return string|false False when the URL is not inside uploads or is already a cache URL.
 */
function wmguru_url_to_rel( $url ) {
	$uploads = wmguru_uploads();
	if ( ! $uploads || ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$base = wmguru_strip_scheme( $uploads['baseurl'] ) . '/';
	$url  = wmguru_strip_scheme( $url );
	if ( 0 !== strpos( $url, $base ) ) {
		return false;
	}

	$rel = substr( $url, strlen( $base ) );
	$rel = strtok( $rel, '?#' );
	if ( ! $rel || 0 === strpos( $rel, 'wmguru-cache/' ) ) {
		return false;
	}

	return rawurldecode( $rel );
}

/**
 * Split a pretty cache URL into key and relative path.
 *
 * @param string $url URL.
 * @return array|false array( key, rel ) or false.
 */
function wmguru_cache_url_to_parts( $url ) {
	$uploads = wmguru_uploads();
	if ( ! $uploads || ! is_string( $url ) ) {
		return false;
	}

	$base = wmguru_strip_scheme( $uploads['baseurl'] ) . '/wmguru-cache/';
	$url  = wmguru_strip_scheme( $url );
	if ( 0 !== strpos( $url, $base ) ) {
		return false;
	}

	$path  = strtok( substr( $url, strlen( $base ) ), '?#' );
	$slash = strpos( (string) $path, '/' );
	if ( false === $slash ) {
		return false;
	}

	return array( substr( $path, 0, $slash ), rawurldecode( substr( $path, $slash + 1 ) ) );
}

/**
 * Turn a cache URL back into the original upload URL; other URLs pass through.
 *
 * @param string $url URL.
 * @return string
 */
function wmguru_original_url( $url ) {
	$parts = wmguru_cache_url_to_parts( $url );
	if ( ! $parts ) {
		return $url;
	}

	$uploads = wmguru_uploads();

	return $uploads['baseurl'] . '/' . wmguru_encode_rel( $parts[1] );
}

/**
 * URL-encode a relative path segment by segment (keeps the slashes).
 *
 * @param string $rel Relative path.
 * @return string
 */
function wmguru_encode_rel( $rel ) {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
}

/**
 * The delivery mode in effect: 'pretty' or 'query'.
 *
 * 'auto' follows the last self-test result and assumes pretty until a test says otherwise.
 *
 * @return string
 */
function wmguru_delivery_mode() {
	$settings = wmguru_get_settings();
	if ( 'pretty' === $settings['delivery'] || 'query' === $settings['delivery'] ) {
		return $settings['delivery'];
	}

	$status = get_option( 'wmguru_delivery_status', array() );

	return ( is_array( $status ) && isset( $status['pretty'] ) && false === $status['pretty'] ) ? 'query' : 'pretty';
}

/**
 * @param string $data Data.
 * @return string
 */
function wmguru_b64url_encode( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * @param string $data Data.
 * @return string
 */
function wmguru_b64url_decode( $data ) {
	return (string) base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
}

/**
 * HMAC guarding the query-mode endpoint.
 *
 * @param string $key Cache key.
 * @param string $rel Relative path.
 * @return string
 */
function wmguru_sign( $key, $rel ) {
	return substr( hash_hmac( 'sha256', $key . '|' . $rel, wp_salt( 'auth' ) ), 0, 20 );
}

/**
 * Build the public URL of a watermarked copy.
 *
 * @param string $key Cache key.
 * @param string $rel Relative path.
 * @return string
 */
function wmguru_build_url( $key, $rel ) {
	if ( 'query' === wmguru_delivery_mode() ) {
		return add_query_arg(
			array(
				'wmguru_k' => $key,
				'wmguru_f' => wmguru_b64url_encode( $rel ),
				'wmguru_s' => wmguru_sign( $key, $rel ),
			),
			home_url( '/' )
		);
	}

	$uploads = wmguru_uploads();

	return $uploads['baseurl'] . '/wmguru-cache/' . $key . '/' . wmguru_encode_rel( $rel );
}

/**
 * Attachment id for a path relative to uploads.
 *
 * attachment_url_to_postid() only knows the main file, not the generated
 * sub-sizes ("photo-300x200.jpg"), so for those the size suffix is stripped,
 * the main file is looked up, and the attachment's metadata must confirm that
 * the file really is one of its sizes.
 *
 * @param string $rel Relative path.
 * @return int 0 when the file is not a known attachment.
 */
function wmguru_attachment_id_from_rel( $rel ) {
	static $memo = array();

	if ( isset( $memo[ $rel ] ) ) {
		return $memo[ $rel ];
	}

	$uploads = wmguru_uploads();
	$id      = (int) attachment_url_to_postid( $uploads['baseurl'] . '/' . wmguru_encode_rel( $rel ) );

	if ( ! $id ) {
		$dir  = dirname( $rel );
		$name = wp_basename( $rel );
		if ( preg_match( '/^(.+)-\d+x\d+(\.[A-Za-z0-9]+)$/', $name, $m ) ) {
			$main = ( '.' === $dir ? '' : $dir . '/' ) . $m[1] . $m[2];
			$id   = (int) attachment_url_to_postid( $uploads['baseurl'] . '/' . wmguru_encode_rel( $main ) );

			$meta = $id ? wp_get_attachment_metadata( $id ) : false;
			$is_size = false;
			foreach ( is_array( $meta ) && ! empty( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( ! empty( $size['file'] ) && $size['file'] === $name ) {
					$is_size = true;
					break;
				}
			}
			$id = $is_size ? $id : 0;
		}
	}

	$memo[ $rel ] = $id;

	return $id;
}

/**
 * Which registered size a file is: matches sub-size file names, everything
 * else (original, -scaled, pre-scaled original) counts as 'full'.
 *
 * @param int    $attachment_id Attachment id.
 * @param string $rel           Relative path of the file.
 * @return array array( name, width, height ) - width/height are 0 when unknown.
 */
function wmguru_classify_size( $attachment_id, $rel ) {
	$meta = wp_get_attachment_metadata( $attachment_id );
	$name = wp_basename( $rel );

	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size_name => $size ) {
			if ( ! empty( $size['file'] ) && $size['file'] === $name ) {
				return array( $size_name, (int) $size['width'], (int) $size['height'] );
			}
		}
	}

	return array(
		'full',
		is_array( $meta ) && ! empty( $meta['width'] ) ? (int) $meta['width'] : 0,
		is_array( $meta ) && ! empty( $meta['height'] ) ? (int) $meta['height'] : 0,
	);
}
