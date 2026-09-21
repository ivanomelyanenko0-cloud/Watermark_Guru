<?php
/**
 * Site Health integration: image library, cache directory and delivery checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array $tests Site Health tests.
 * @return array
 */
function wmguru_register_site_health_tests( $tests ) {
	$tests['direct']['wmguru_image_library'] = array(
		'label' => __( 'Watermark Guru image library', 'watermark-guru' ),
		'test'  => 'wmguru_test_image_library',
	);
	$tests['direct']['wmguru_delivery']      = array(
		'label' => __( 'Watermark Guru delivery', 'watermark-guru' ),
		'test'  => 'wmguru_test_delivery',
	);

	return $tests;
}
add_filter( 'site_status_tests', 'wmguru_register_site_health_tests' );

/**
 * @param string $label       Label.
 * @param string $status      good|recommended|critical.
 * @param string $description Description (plain text).
 * @param string $test        Test id.
 * @return array
 */
function wmguru_health_result( $label, $status, $description, $test ) {
	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Watermark Guru', 'watermark-guru' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => '',
		'test'        => $test,
	);
}

/**
 * @return array
 */
function wmguru_test_image_library() {
	$support = wmguru_image_support();
	$missing = array();

	foreach ( array( 'image/jpeg', 'image/png', 'image/webp' ) as $mime ) {
		if ( empty( $support['gd'][ $mime ] ) && empty( $support['imagick'][ $mime ] ) ) {
			$missing[] = $mime;
		}
	}

	if ( $missing ) {
		return wmguru_health_result(
			__( 'No image library can watermark some common formats', 'watermark-guru' ),
			'critical',
			/* translators: %s: comma separated mime types */
			sprintf( __( 'Neither GD nor Imagick can process: %s. Ask your host to enable GD with FreeType, or Imagick.', 'watermark-guru' ), implode( ', ', $missing ) ),
			'wmguru_image_library'
		);
	}

	return wmguru_health_result(
		__( 'Watermark Guru has an image library', 'watermark-guru' ),
		'good',
		__( 'JPEG, PNG and WebP can be watermarked.', 'watermark-guru' ),
		'wmguru_image_library'
	);
}

/**
 * @return array
 */
function wmguru_test_delivery() {
	if ( ! wmguru_ensure_cache_dir() ) {
		return wmguru_health_result(
			__( 'The Watermark Guru cache directory is not writable', 'watermark-guru' ),
			'critical',
			__( 'Watermarked copies are stored in wp-content/uploads/wmguru-cache. Make the uploads directory writable by the web server.', 'watermark-guru' ),
			'wmguru_delivery'
		);
	}

	$status = get_option( 'wmguru_delivery_status', array() );
	if ( is_array( $status ) && isset( $status['pretty'] ) && false === $status['pretty'] ) {
		return wmguru_health_result(
			__( 'Watermarked images use the fallback delivery mode', 'watermark-guru' ),
			'recommended',
			__( 'Your server does not pass missing image URLs to WordPress, so Watermark Guru uses signed query-string URLs instead. Everything works; the URLs are just less pretty.', 'watermark-guru' ),
			'wmguru_delivery'
		);
	}

	return wmguru_health_result(
		__( 'Watermarked image delivery works', 'watermark-guru' ),
		'good',
		__( 'The cache directory is writable and image URLs are routed correctly.', 'watermark-guru' ),
		'wmguru_delivery'
	);
}
