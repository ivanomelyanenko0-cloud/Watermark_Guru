<?php
/**
 * Admin: settings screen (Media -> Watermark Guru), live preview and AJAX tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wmguru_admin_menu() {
	add_media_page(
		__( 'Watermark Guru', 'watermark-guru' ),
		__( 'Watermark Guru', 'watermark-guru' ),
		'manage_options',
		'wmguru',
		'wmguru_render_settings_page'
	);
}
add_action( 'admin_menu', 'wmguru_admin_menu' );

function wmguru_register_settings() {
	register_setting(
		'wmguru',
		WMGURU_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wmguru_sanitize_settings',
			'default'           => wmguru_default_settings(),
		)
	);
}
add_action( 'admin_init', 'wmguru_register_settings' );

/**
 * @param array $links Plugin action links.
 * @return array
 */
function wmguru_plugin_action_links( $links ) {
	$url = admin_url( 'upload.php?page=wmguru' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'watermark-guru' ) . '</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WMGURU_PLUGIN_FILE ), 'wmguru_plugin_action_links' );

/**
 * @param string $hook Admin page hook suffix.
 */
function wmguru_admin_assets( $hook ) {
	if ( 'media_page_wmguru' !== $hook ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'wmguru-admin', WMGURU_PLUGIN_URL . 'assets/css/admin.css', array(), WMGURU_VERSION );
	wp_enqueue_script( 'wmguru-admin', WMGURU_PLUGIN_URL . 'assets/js/admin.js', array( 'media-views' ), WMGURU_VERSION, true );
	wp_localize_script(
		'wmguru-admin',
		'wmguruAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wmguru_admin' ),
			'i18n'    => array(
				'chooseImage'   => __( 'Choose an image', 'watermark-guru' ),
				'useImage'      => __( 'Use this image', 'watermark-guru' ),
				'rendering'     => __( 'Rendering preview…', 'watermark-guru' ),
				'previewFailed' => __( 'Preview failed.', 'watermark-guru' ),
				'working'       => __( 'Working…', 'watermark-guru' ),
				'requestFailed' => __( 'The request failed.', 'watermark-guru' ),
				'sampleImage'   => __( 'Built-in sample image', 'watermark-guru' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'wmguru_admin_assets' );

/**
 * 3x3 placement picker.
 *
 * @param string $name  Input name.
 * @param string $value Selected position.
 */
function wmguru_position_picker( $name, $value ) {
	echo '<div class="wmguru-pos" role="radiogroup">';
	foreach ( wmguru_positions() as $key => $label ) {
		printf(
			'<label title="%1$s"><input type="radio" name="%2$s" value="%3$s"%4$s /><span></span><span class="screen-reader-text">%1$s</span></label>',
			esc_attr( $label ),
			esc_attr( $name ),
			esc_attr( $key ),
			checked( $value, $key, false )
		);
	}
	echo '</div>';
}

/**
 * One table row.
 *
 * @param string $label Label.
 * @param string $html  Field HTML (already escaped).
 * @param string $desc  Optional description.
 */
function wmguru_row( $label, $html, $desc = '' ) {
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped markup.
	if ( '' !== $desc ) {
		echo '<p class="description">' . esc_html( $desc ) . '</p>';
	}
	echo '</td></tr>';
}

/**
 * @param string $name  Input name.
 * @param mixed  $value Value.
 * @param array  $attrs min / max / step.
 * @return string
 */
function wmguru_number_input( $name, $value, $attrs ) {
	return sprintf(
		'<input type="number" class="small-text" name="%s" value="%s" min="%s" max="%s" step="%s" />',
		esc_attr( $name ),
		esc_attr( $value ),
		esc_attr( $attrs['min'] ),
		esc_attr( $attrs['max'] ),
		esc_attr( isset( $attrs['step'] ) ? $attrs['step'] : 1 )
	);
}

/**
 * Fields shared by the text and image layer cards.
 *
 * @param string $base  Input name prefix, e.g. wmguru_settings[layers][0].
 * @param array  $layer Layer.
 * @param bool   $is_text True for a text layer.
 */
function wmguru_render_common_layer_fields( $base, $layer, $is_text ) {
	wmguru_row(
		$is_text ? __( 'Text size', 'watermark-guru' ) : __( 'Logo width', 'watermark-guru' ),
		wmguru_number_input(
			$base . '[size]',
			$layer['size'],
			array(
				'min'  => 0.5,
				'max'  => 100,
				'step' => 0.1,
			)
		) . ' % ' . esc_html__( 'of the image width', 'watermark-guru' ) . ' &nbsp; ' .
		esc_html__( 'min', 'watermark-guru' ) . ' ' . wmguru_number_input(
			$base . '[min_px]',
			$layer['min_px'],
			array(
				'min' => 1,
				'max' => 4000,
			)
		) . ' px &nbsp; ' . esc_html__( 'max', 'watermark-guru' ) . ' ' . wmguru_number_input(
			$base . '[max_px]',
			$layer['max_px'],
			array(
				'min' => 1,
				'max' => 8000,
			)
		) . ' px',
		__( 'The watermark scales with each image, within the min/max limits.', 'watermark-guru' )
	);

	ob_start();
	wmguru_position_picker( $base . '[position]', $layer['position'] );
	wmguru_row( __( 'Position', 'watermark-guru' ), ob_get_clean() );

	wmguru_row(
		__( 'Margin', 'watermark-guru' ),
		wmguru_number_input(
			$base . '[margin]',
			$layer['margin'],
			array(
				'min'  => 0,
				'max'  => 30,
				'step' => 0.1,
			)
		) . ' % ' . esc_html__( 'of the shorter side', 'watermark-guru' )
	);
	wmguru_row(
		__( 'Opacity', 'watermark-guru' ),
		wmguru_number_input(
			$base . '[opacity]',
			$layer['opacity'],
			array(
				'min' => 1,
				'max' => 100,
			)
		) . ' %'
	);
	wmguru_row(
		__( 'Rotation', 'watermark-guru' ),
		wmguru_number_input(
			$base . '[rotation]',
			$layer['rotation'],
			array(
				'min' => -180,
				'max' => 180,
			)
		) . '° ' . esc_html__( 'clockwise', 'watermark-guru' )
	);
}

/**
 * @param int   $index Layer slot.
 * @param array $layer Layer.
 */
function wmguru_render_layer_card( $index, $layer ) {
	$base    = WMGURU_OPTION . '[layers][' . (int) $index . ']';
	$is_text = 'text' === $layer['type'];

	echo '<div class="wmguru-card"><h2>' . ( $is_text ? esc_html__( 'Text watermark', 'watermark-guru' ) : esc_html__( 'Image watermark (logo)', 'watermark-guru' ) ) . '</h2>';
	echo '<input type="hidden" name="' . esc_attr( $base ) . '[type]" value="' . esc_attr( $layer['type'] ) . '" />';
	echo '<table class="form-table" role="presentation">';

	wmguru_row(
		__( 'Enabled', 'watermark-guru' ),
		'<label><input type="checkbox" name="' . esc_attr( $base ) . '[enabled]" value="1"' . checked( ! empty( $layer['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Draw this layer', 'watermark-guru' ) . '</label>'
	);

	if ( $is_text ) {
		wmguru_row(
			__( 'Text', 'watermark-guru' ),
			'<input type="text" class="regular-text" maxlength="200" name="' . esc_attr( $base ) . '[text]" value="' . esc_attr( $layer['text'] ) . '" />',
			__( 'Tokens: {site} site title, {year} current year, {url} site domain.', 'watermark-guru' )
		);

		$options = '';
		foreach ( wmguru_font_choices() as $key => $font ) {
			$options .= '<option value="' . esc_attr( $key ) . '"' . selected( $layer['font'], $key, false ) . '>' . esc_html( $font['label'] ) . '</option>';
		}
		wmguru_row(
			__( 'Font', 'watermark-guru' ),
			'<select name="' . esc_attr( $base ) . '[font]">' . $options . '</select>',
			__( 'Bundled fonts cover Latin, Cyrillic and Greek.', 'watermark-guru' )
		);
		wmguru_row(
			__( 'Color', 'watermark-guru' ),
			'<input type="color" name="' . esc_attr( $base ) . '[color]" value="' . esc_attr( $layer['color'] ) . '" />'
		);
	} else {
		$attachment_id = (int) $layer['attachment_id'];
		$thumb         = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		$html          = '<div class="wmguru-media">'
			. '<img class="wmguru-media-preview" src="' . esc_url( $thumb ) . '" alt=""' . ( $thumb ? '' : ' hidden' ) . ' />'
			. '<input type="hidden" name="' . esc_attr( $base ) . '[attachment_id]" value="' . esc_attr( $attachment_id ) . '" />'
			. '<button type="button" class="button wmguru-media-pick">' . esc_html__( 'Choose image', 'watermark-guru' ) . '</button> '
			. '<button type="button" class="button-link wmguru-media-clear"' . ( $attachment_id ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'watermark-guru' ) . '</button>'
			. '</div>';
		wmguru_row( __( 'Logo', 'watermark-guru' ), $html, __( 'A PNG with a transparent background works best.', 'watermark-guru' ) );
	}

	wmguru_render_common_layer_fields( $base, $layer, $is_text );

	echo '</table></div>';
}

function wmguru_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = wmguru_get_settings();
	$slots    = array(
		'text'  => wmguru_default_layer( 'text' ),
		'image' => wmguru_default_layer( 'image' ),
	);
	$seen     = array();
	foreach ( $settings['layers'] as $layer ) {
		if ( isset( $slots[ $layer['type'] ] ) && ! isset( $seen[ $layer['type'] ] ) ) {
			$slots[ $layer['type'] ] = array_merge( $slots[ $layer['type'] ], $layer );
			$seen[ $layer['type'] ]  = true;
		}
	}

	$support = wmguru_image_support();
	$status  = get_option( 'wmguru_delivery_status', array() );
	$stats   = wmguru_cache_stats();
	$log     = get_option( 'wmguru_error_log', array() );

	echo '<div class="wrap wmguru-wrap"><h1>' . esc_html__( 'Watermark Guru', 'watermark-guru' ) . '</h1>';
	settings_errors();

	if ( empty( $settings['enabled'] ) ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Watermarks are switched off. Set up your watermark below, check the preview, then enable it under “General”. Your original files are never modified either way.', 'watermark-guru' ) . '</p></div>';
	}

	echo '<div class="wmguru-layout"><form id="wmguru-form" method="post" action="options.php" class="wmguru-main">';
	settings_fields( 'wmguru' );

	// General.
	echo '<div class="wmguru-card"><h2>' . esc_html__( 'General', 'watermark-guru' ) . '</h2><table class="form-table" role="presentation">';
	wmguru_row(
		__( 'Watermarks', 'watermark-guru' ),
		'<label><input type="checkbox" name="' . esc_attr( WMGURU_OPTION ) . '[enabled]" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Show watermarked images on the front end', 'watermark-guru' ) . '</label>',
		__( 'Copies are generated on first view and cached. Turn this off at any time and visitors get the untouched originals immediately.', 'watermark-guru' )
	);

	$boxes = '';
	foreach ( wmguru_available_sizes() as $size => $label ) {
		$boxes .= '<label class="wmguru-check"><input type="checkbox" name="' . esc_attr( WMGURU_OPTION ) . '[sizes][]" value="' . esc_attr( $size ) . '"' . checked( in_array( $size, $settings['sizes'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}
	wmguru_row( __( 'Apply to sizes', 'watermark-guru' ), $boxes, __( '“Full size” also covers scaled and original uploads.', 'watermark-guru' ) );

	wmguru_row(
		__( 'Skip small images', 'watermark-guru' ),
		esc_html__( 'narrower than', 'watermark-guru' ) . ' ' . wmguru_number_input(
			WMGURU_OPTION . '[min_width]',
			$settings['min_width'],
			array(
				'min' => 0,
				'max' => 10000,
			)
		) . ' px ' . esc_html__( 'or shorter than', 'watermark-guru' ) . ' ' . wmguru_number_input(
			WMGURU_OPTION . '[min_height]',
			$settings['min_height'],
			array(
				'min' => 0,
				'max' => 10000,
			)
		) . ' px'
	);
	wmguru_row(
		__( 'Output quality', 'watermark-guru' ),
		wmguru_number_input(
			WMGURU_OPTION . '[quality]',
			$settings['quality'],
			array(
				'min' => 30,
				'max' => 100,
			)
		) . ' ' . esc_html__( 'for JPEG, WebP and AVIF copies (originals are not re-encoded)', 'watermark-guru' )
	);

	$drivers = array(
		'auto'    => __( 'Automatic (Imagick if available, otherwise GD)', 'watermark-guru' ),
		'imagick' => __( 'Imagick', 'watermark-guru' ),
		'gd'      => __( 'GD', 'watermark-guru' ),
	);
	$options = '';
	foreach ( $drivers as $key => $label ) {
		$options .= '<option value="' . esc_attr( $key ) . '"' . selected( $settings['driver'], $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$formats = array();
	foreach ( array_keys( $support['gd'] ) as $mime ) {
		$libs = array();
		if ( ! empty( $support['imagick'][ $mime ] ) ) {
			$libs[] = 'Imagick';
		}
		if ( ! empty( $support['gd'][ $mime ] ) ) {
			$libs[] = 'GD';
		}
		$formats[] = strtoupper( str_replace( 'image/', '', $mime ) ) . ': ' . ( $libs ? implode( ' + ', $libs ) : __( 'not supported', 'watermark-guru' ) );
	}
	wmguru_row(
		__( 'Image library', 'watermark-guru' ),
		'<select name="' . esc_attr( WMGURU_OPTION ) . '[driver]">' . $options . '</select>',
		implode( ' · ', $formats )
	);
	wmguru_row(
		__( 'On uninstall', 'watermark-guru' ),
		'<label><input type="checkbox" name="' . esc_attr( WMGURU_OPTION ) . '[remove_data_uninstall]" value="1"' . checked( ! empty( $settings['remove_data_uninstall'] ), true, false ) . ' /> ' . esc_html__( 'Also delete these settings (the cache is always removed)', 'watermark-guru' ) . '</label>'
	);
	echo '</table></div>';

	wmguru_render_layer_card( 0, $slots['text'] );
	wmguru_render_layer_card( 1, $slots['image'] );

	// Delivery.
	$modes   = array(
		'auto'   => __( 'Automatic', 'watermark-guru' ),
		'pretty' => __( 'Direct file URLs', 'watermark-guru' ),
		'query'  => __( 'Signed query URLs (fallback)', 'watermark-guru' ),
	);
	$options = '';
	foreach ( $modes as $key => $label ) {
		$options .= '<option value="' . esc_attr( $key ) . '"' . selected( $settings['delivery'], $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	if ( is_array( $status ) && isset( $status['pretty'] ) && true === $status['pretty'] ) {
		$state = __( 'Last test: direct file URLs work.', 'watermark-guru' );
	} elseif ( is_array( $status ) && isset( $status['pretty'] ) && false === $status['pretty'] ) {
		/* translators: %s: error detail */
		$state = sprintf( __( 'Last test: direct URLs do not work here (%s). The fallback is used automatically.', 'watermark-guru' ), isset( $status['error'] ) ? $status['error'] : '' );
	} elseif ( ! empty( $status['error'] ) ) {
		/* translators: %s: error detail */
		$state = sprintf( __( 'Last test could not run (%s). Direct file URLs are assumed.', 'watermark-guru' ), $status['error'] );
	} else {
		$state = __( 'Not tested yet.', 'watermark-guru' );
	}

	echo '<div class="wmguru-card"><h2>' . esc_html__( 'Delivery & cache', 'watermark-guru' ) . '</h2><table class="form-table" role="presentation">';
	wmguru_row(
		__( 'URL mode', 'watermark-guru' ),
		'<select name="' . esc_attr( WMGURU_OPTION ) . '[delivery]">' . $options . '</select> <button type="button" class="button" data-wmguru-action="wmguru_test_delivery">' . esc_html__( 'Test delivery', 'watermark-guru' ) . '</button> <span class="wmguru-action-result" aria-live="polite"></span>',
		$state
	);
	wmguru_row(
		__( 'Cache', 'watermark-guru' ),
		sprintf(
			/* translators: 1: number of files, 2: size */
			esc_html__( '%1$s files, %2$s', 'watermark-guru' ),
			esc_html( number_format_i18n( $stats['files'] ) . ( $stats['capped'] ? '+' : '' ) ),
			esc_html( size_format( $stats['bytes'] ) )
		) . ' <button type="button" class="button" data-wmguru-action="wmguru_purge" data-reload="1">' . esc_html__( 'Clear cache', 'watermark-guru' ) . '</button> <span class="wmguru-action-result" aria-live="polite"></span>',
		__( 'Clearing the cache is always safe: copies are regenerated on demand.', 'watermark-guru' )
	);
	if ( is_array( $log ) && $log ) {
		$items = '';
		foreach ( array_reverse( array_slice( $log, -5 ) ) as $entry ) {
			$items .= '<li><code>' . esc_html( wp_date( 'Y-m-d H:i', $entry['time'] ) ) . '</code> ' . esc_html( $entry['message'] ) . '</li>';
		}
		wmguru_row( __( 'Recent problems', 'watermark-guru' ), '<ul class="wmguru-log">' . $items . '</ul>' );
	}
	echo '</table></div>';

	submit_button();
	echo '</form>';

	// Preview column.
	echo '<aside class="wmguru-side"><div class="wmguru-card wmguru-sticky"><h2>' . esc_html__( 'Live preview', 'watermark-guru' ) . '</h2>';
	echo '<div class="wmguru-preview-box"><img id="wmguru-preview-img" alt="" /><p id="wmguru-preview-msg" class="description" aria-live="polite"></p></div>';
	echo '<p class="wmguru-preview-src"><span id="wmguru-preview-label">' . esc_html__( 'Built-in sample image', 'watermark-guru' ) . '</span> ';
	echo '<button type="button" class="button" id="wmguru-preview-pick">' . esc_html__( 'Use one of my images', 'watermark-guru' ) . '</button> ';
	echo '<button type="button" class="button-link" id="wmguru-preview-reset" hidden>' . esc_html__( 'Back to sample', 'watermark-guru' ) . '</button></p>';
	echo '<p class="description">' . esc_html__( 'The preview is rendered by the same engine that creates the real copies, using your unsaved settings.', 'watermark-guru' ) . '</p>';
	echo '</div>';

	if ( ! defined( 'WMGURU_PRO_VERSION' ) ) {
		echo '<div class="wmguru-card"><h2>' . esc_html__( 'Need more?', 'watermark-guru' ) . '</h2><p>' . esc_html__( 'Multiple watermark profiles, unlimited layers and rules that pick a profile by post type, category or file type are available in Watermark Guru Pro, a separate optional add-on.', 'watermark-guru' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( 'https://cognitolab.net/products/watermark-guru' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about Pro', 'watermark-guru' ) . '</a></p></div>';
	}
	echo '</aside></div>';

	do_action( 'wmguru_settings_page_after' );
	echo '</div>';
}

/**
 * Source file for previews: one of the user's images, or the bundled sample.
 *
 * @param int $attachment_id Attachment id, 0 for the sample.
 * @return array array( path, mime )
 */
function wmguru_preview_source( $attachment_id ) {
	if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
		$mime = get_post_mime_type( $attachment_id );
		$file = get_attached_file( $attachment_id );
		$size = image_get_intermediate_size( $attachment_id, 'large' );
		$uploads = wmguru_uploads();

		if ( $size && ! empty( $size['path'] ) && $uploads && is_readable( $uploads['basedir'] . '/' . $size['path'] ) ) {
			$file = $uploads['basedir'] . '/' . $size['path'];
		}
		if ( $file && is_readable( $file ) && wmguru_get_file_handler( $mime ) ) {
			return array( $file, $mime );
		}
	}

	return array( WMGURU_PLUGIN_DIR . 'assets/images/sample.jpg', 'image/jpeg' );
}

/**
 * Render a profile onto a preview image and return it as a data URI.
 *
 * @param array $profile       Profile.
 * @param int   $attachment_id Image to preview on, 0 for the sample.
 * @param array $overrides     'quality' / 'driver'.
 * @return array|WP_Error array( 'src' => data URI, 'notice' => string )
 */
function wmguru_render_preview( $profile, $attachment_id = 0, $overrides = array() ) {
	list( $src, $mime ) = wmguru_preview_source( (int) $attachment_id );

	$notice = '';
	$tmp    = wp_tempnam( 'wmguru-preview' );
	if ( wmguru_effective_layers( $profile ) ) {
		$result = wmguru_is_animated( $src, $mime ) ? new WP_Error( 'wmguru_animated', __( 'Animated images are shown and served unchanged.', 'watermark-guru' ) ) : wmguru_render_image( $src, $tmp, $profile, $mime, $overrides );
		if ( is_wp_error( $result ) ) {
			wp_delete_file( $tmp );
			return $result;
		}
		$file = $tmp;
	} else {
		$notice = __( 'Nothing to draw yet: enable a layer and give it content.', 'watermark-guru' );
		$file   = $src;
	}

	$data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	wp_delete_file( $tmp );
	if ( false === $data ) {
		return new WP_Error( 'wmguru_preview_read', __( 'Could not read the rendered preview.', 'watermark-guru' ) );
	}

	return array(
		'src'    => 'data:' . $mime . ';base64,' . base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		'notice' => $notice,
	);
}

/**
 * Shared guard for the AJAX endpoints.
 */
function wmguru_ajax_guard() {
	check_ajax_referer( 'wmguru_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'watermark-guru' ) ), 403 );
	}
}

function wmguru_ajax_preview() {
	wmguru_ajax_guard();

	$raw      = isset( $_POST['wmguru_settings'] ) ? wp_unslash( $_POST['wmguru_settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in wmguru_ajax_guard(); sanitized by wmguru_sanitize_settings().
	$settings = wmguru_sanitize_settings( $raw );
	$profile  = array(
		'id'     => 'default',
		'label'  => '',
		'layers' => $settings['layers'],
	);

	$attachment_id = isset( $_POST['preview_id'] ) ? absint( $_POST['preview_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$result        = wmguru_render_preview(
		$profile,
		$attachment_id,
		array(
			'quality' => $settings['quality'],
			'driver'  => $settings['driver'],
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_wmguru_preview', 'wmguru_ajax_preview' );

function wmguru_ajax_test_delivery() {
	wmguru_ajax_guard();

	$status = wmguru_run_delivery_test();
	if ( true === $status['pretty'] ) {
		wp_send_json_success( array( 'message' => __( 'Direct file URLs work.', 'watermark-guru' ) ) );
	}
	if ( false === $status['pretty'] ) {
		wp_send_json_success( array( 'message' => __( 'Direct URLs do not work here; the fallback will be used.', 'watermark-guru' ) ) );
	}

	wp_send_json_error( array( 'message' => $status['error'] ? $status['error'] : __( 'The test could not run.', 'watermark-guru' ) ) );
}
add_action( 'wp_ajax_wmguru_test_delivery', 'wmguru_ajax_test_delivery' );

function wmguru_ajax_purge() {
	wmguru_ajax_guard();

	wmguru_purge_all();
	wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'watermark-guru' ) ) );
}
add_action( 'wp_ajax_wmguru_purge', 'wmguru_ajax_purge' );
