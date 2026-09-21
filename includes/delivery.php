<?php
/**
 * Delivery: generates a watermarked copy on the first request and serves it.
 *
 * Cache hits on the "pretty" URL never reach PHP (the web server serves the
 * file). A miss falls through WordPress's standard "file not found ->
 * index.php" rewrite and lands here. Hosts that answer 404 for static files
 * themselves are handled by the signed query-string endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Work out whether this request is for a cache URL.
 *
 * @return array|null array( key, rel ) or null.
 */
function wmguru_parse_request() {
	// Query mode.
	if ( isset( $_GET['wmguru_k'], $_GET['wmguru_f'], $_GET['wmguru_s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, signed with an HMAC below.
		$key = sanitize_text_field( wp_unslash( $_GET['wmguru_k'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rel = wmguru_b64url_decode( sanitize_text_field( wp_unslash( $_GET['wmguru_f'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sig = sanitize_text_field( wp_unslash( $_GET['wmguru_s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! hash_equals( wmguru_sign( $key, $rel ), $sig ) ) {
			return null;
		}

		return array( $key, $rel );
	}

	// Pretty mode.
	$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only parsed and validated below, never output.
	if ( false === strpos( $request, '/wmguru-cache/' ) ) {
		return null;
	}

	$uploads = wmguru_uploads();
	if ( ! $uploads ) {
		return null;
	}

	$prefix = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) . '/wmguru-cache/';
	$path   = (string) wp_parse_url( $request, PHP_URL_PATH );
	if ( 0 !== strpos( $path, $prefix ) ) {
		return null;
	}

	$rest  = rawurldecode( substr( $path, strlen( $prefix ) ) );
	$slash = strpos( $rest, '/' );
	if ( false === $slash ) {
		return null;
	}

	return array( substr( $rest, 0, $slash ), substr( $rest, $slash + 1 ) );
}

/**
 * Validate a relative path taken from a request.
 *
 * @param string $rel Relative path.
 * @return bool
 */
function wmguru_is_safe_rel( $rel ) {
	return '' !== $rel
		&& strlen( $rel ) < 500
		&& false === strpos( $rel, "\0" )
		&& false === strpos( $rel, '\\' )
		&& '/' !== $rel[0]
		&& ! in_array( '..', explode( '/', $rel ), true );
}

/**
 * Entry point on every request; does nothing unless it is a cache URL.
 */
function wmguru_maybe_serve() {
	$request = wmguru_parse_request();
	if ( $request ) {
		wmguru_serve( $request[0], $request[1] );
	}
}
add_action( 'init', 'wmguru_maybe_serve', 1 );

/**
 * @param string $url Where to send the visitor.
 */
function wmguru_redirect( $url ) {
	nocache_headers();
	wp_safe_redirect( $url, 302 );
	exit;
}

/**
 * @param string $rel Relative path.
 */
function wmguru_redirect_original( $rel ) {
	$uploads = wmguru_uploads();
	wmguru_redirect( $uploads['baseurl'] . '/' . wmguru_encode_rel( $rel ) );
}

/**
 * Send a plain error and stop.
 *
 * @param int $status HTTP status.
 */
function wmguru_fail( $status ) {
	nocache_headers();
	status_header( $status );
	header( 'Content-Type: text/plain; charset=utf-8' );
	exit;
}

/**
 * Serve a watermarked copy, generating it first when necessary.
 *
 * @param string $key Cache key.
 * @param string $rel Path relative to uploads.
 */
function wmguru_serve( $key, $rel ) {
	$parts = wmguru_split_key( $key );
	if ( ! $parts || ! wmguru_is_safe_rel( $rel ) ) {
		wmguru_fail( 404 );
	}

	if ( 'selftest' === $parts[0] ) {
		wmguru_serve_selftest( $rel );
	}

	$uploads = wmguru_uploads();
	$base    = realpath( $uploads['basedir'] );
	$src     = realpath( $uploads['basedir'] . '/' . $rel );
	if ( ! $base || ! $src || 0 !== strpos( $src, $base . DIRECTORY_SEPARATOR ) || 0 === strpos( $src, $base . DIRECTORY_SEPARATOR . 'wmguru-cache' ) || ! is_file( $src ) ) {
		wmguru_fail( 404 );
	}

	// Only real Media Library files: never expose other files that happen to sit in uploads.
	$attachment_id = wmguru_attachment_id_from_rel( $rel );
	if ( ! $attachment_id ) {
		wmguru_fail( 404 );
	}
	if ( ! wmguru_is_enabled() ) {
		wmguru_redirect_original( $rel );
	}

	$profile = wmguru_watermark_profile_for( $attachment_id, $rel );
	if ( ! $profile ) {
		wmguru_redirect_original( $rel );
	}

	// Stale key (settings changed) or a different profile now applies: send to the current URL.
	$current = wmguru_profile_key( $profile );
	if ( $current !== $key ) {
		wmguru_redirect( wmguru_build_url( $current, $rel ) );
	}

	$mime = get_post_mime_type( $attachment_id );
	$dest = wmguru_cache_file_path( $key, $rel );

	if ( ! file_exists( $dest ) || filemtime( $dest ) < filemtime( $src ) ) {
		$generated = wmguru_generate( $src, $dest, $profile, $mime, $attachment_id, $rel );
		if ( is_wp_error( $generated ) ) {
			wmguru_log_error( $rel . ': ' . $generated->get_error_message() );
			wmguru_redirect_original( $rel );
		}
	}

	wmguru_send_file( $dest, $mime );
}

/**
 * Render into a temp file next to the destination, then move it into place
 * atomically so a concurrent request never sees a half-written file.
 *
 * @param string $src           Source path.
 * @param string $dest          Destination path.
 * @param array  $profile       Profile.
 * @param string $mime          Mime type.
 * @param int    $attachment_id Attachment id.
 * @param string $rel           Relative path.
 * @return true|WP_Error
 */
function wmguru_generate( $src, $dest, $profile, $mime, $attachment_id, $rel ) {
	$handler = wmguru_get_file_handler( $mime );
	if ( ! $handler || ! wmguru_ensure_cache_dir() || ! wp_mkdir_p( dirname( $dest ) ) ) {
		return new WP_Error( 'wmguru_cache_unwritable', __( 'The cache directory is not writable.', 'watermark-guru' ) );
	}

	$tmp    = $dest . '.' . wp_generate_password( 8, false ) . '.tmp';
	$result = call_user_func(
		$handler,
		$src,
		$tmp,
		$profile,
		array(
			'mime'          => $mime,
			'attachment_id' => $attachment_id,
			'rel'           => $rel,
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_delete_file( $tmp );
		return $result;
	}

	// Explicit permissions: a restrictive umask must not leave the file unreadable by the web server.
	chmod( $tmp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	if ( ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		wp_delete_file( $tmp );
		return new WP_Error( 'wmguru_rename_failed', __( 'Could not move the generated file into the cache.', 'watermark-guru' ) );
	}

	return true;
}

/**
 * Stream a file with long-lived cache headers (the URL is versioned by key).
 *
 * @param string $path File path.
 * @param string $mime Mime type.
 */
function wmguru_send_file( $path, $mime ) {
	$mtime  = (int) filemtime( $path );
	$size   = (int) filesize( $path );
	$etag   = '"' . md5( $path . $mtime . $size ) . '"';
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
	$match  = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	header( 'Content-Type: ' . $mime );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: public, max-age=31536000, immutable' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'ETag: ' . $etag );

	if ( $match === $etag ) {
		status_header( 304 );
		exit;
	}

	status_header( 200 );
	header( 'Content-Length: ' . $size );
	if ( 'HEAD' !== $method ) {
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}
	exit;
}

/**
 * Tiny PNG used to probe that unknown cache URLs reach PHP.
 *
 * @param string $rel Relative path from the request.
 */
function wmguru_serve_selftest( $rel ) {
	if ( 'probe.png' !== $rel ) {
		wmguru_fail( 404 );
	}

	$png = wmguru_b64url_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg' );

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: image/png' );
	echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PNG, fixed content.
	exit;
}

/**
 * Check that a cache URL that does not exist on disk reaches PHP. Stores the
 * result; a transport failure is recorded as "unknown", not as a failure.
 *
 * @return array array( 'pretty' => bool|null, 'time' => int, 'error' => string )
 */
function wmguru_run_delivery_test() {
	$uploads = wmguru_uploads();
	$status  = array(
		'pretty' => null,
		'time'   => time(),
		'error'  => '',
	);

	if ( ! wmguru_ensure_cache_dir() ) {
		$status['error'] = __( 'The cache directory could not be created or is not writable.', 'watermark-guru' );
	} elseif ( $uploads ) {
		$response = wp_remote_get(
			$uploads['baseurl'] . '/wmguru-cache/selftest-00000000/probe.png',
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			)
		);

		if ( is_wp_error( $response ) ) {
			$status['error'] = $response->get_error_message();
		} else {
			$ok               = 200 === wp_remote_retrieve_response_code( $response ) && 0 === strpos( wp_remote_retrieve_body( $response ), "\x89PNG" );
			$status['pretty'] = $ok;
			if ( ! $ok ) {
				/* translators: %d: HTTP status code */
				$status['error'] = sprintf( __( 'Unexpected response (HTTP %d) for a not-yet-cached image URL.', 'watermark-guru' ), (int) wp_remote_retrieve_response_code( $response ) );
			}
		}
	}

	update_option( 'wmguru_delivery_status', $status, false );

	return $status;
}
add_action( 'wmguru_run_delivery_test', 'wmguru_run_delivery_test' );
