<?php
/**
 * Per-attachment decisions: does this file get a watermark, and with which profile.
 * Also the Media Library "do not watermark" field and cache invalidation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The profile a file should be served with, or null when it stays untouched.
 *
 * @param int    $attachment_id Attachment id.
 * @param string $rel           Path of the requested file relative to uploads.
 * @return array|null
 */
function wmguru_watermark_profile_for( $attachment_id, $rel ) {
	static $memo = array();

	$memo_key = $attachment_id . '|' . $rel;
	if ( array_key_exists( $memo_key, $memo ) ) {
		return $memo[ $memo_key ];
	}
	$memo[ $memo_key ] = null;

	$mime = get_post_mime_type( $attachment_id );
	if ( ! $mime || ! wmguru_get_file_handler( $mime ) ) {
		return null;
	}
	if ( get_post_meta( $attachment_id, '_wmguru_exclude', true ) ) {
		return null;
	}

	$profile_id = apply_filters( 'wmguru_profile_for_attachment', 'default', $attachment_id );
	if ( ! is_string( $profile_id ) || '' === $profile_id || 'none' === $profile_id ) {
		return null;
	}

	$profile = wmguru_get_profile( $profile_id );
	if ( ! $profile || ! wmguru_effective_layers( $profile ) ) {
		return null;
	}

	$settings                = wmguru_get_settings();
	list( $size, $w, $h )    = wmguru_classify_size( $attachment_id, $rel );
	if ( ! in_array( $size, $settings['sizes'], true ) ) {
		return null;
	}
	if ( $w && $h && ( $w < $settings['min_width'] || $h < $settings['min_height'] || $w * $h > wmguru_max_pixels() ) ) {
		return null;
	}

	// Never watermark the watermark image itself.
	foreach ( (array) $profile['layers'] as $layer ) {
		if ( isset( $layer['attachment_id'] ) && (int) $layer['attachment_id'] === (int) $attachment_id ) {
			return null;
		}
	}

	$memo[ $memo_key ] = $profile;

	return $profile;
}

/**
 * "Do not watermark" checkbox on the attachment edit screen and media modal.
 *
 * @param array   $fields Fields.
 * @param WP_Post $post   Attachment.
 * @return array
 */
function wmguru_attachment_fields( $fields, $post ) {
	if ( ! wp_attachment_is_image( $post ) ) {
		return $fields;
	}

	$checked = get_post_meta( $post->ID, '_wmguru_exclude', true ) ? ' checked="checked"' : '';

	$fields['wmguru_exclude'] = array(
		'label' => __( 'Watermark', 'watermark-guru' ),
		'input' => 'html',
		'html'  => '<label><input type="checkbox" name="attachments[' . (int) $post->ID . '][wmguru_exclude]" value="1"' . $checked . ' /> ' . esc_html__( 'Do not watermark this image', 'watermark-guru' ) . '</label>',
	);

	return $fields;
}
add_filter( 'attachment_fields_to_edit', 'wmguru_attachment_fields', 10, 2 );

/**
 * @param array $post       Post data.
 * @param array $attachment Submitted fields.
 * @return array
 */
function wmguru_attachment_fields_save( $post, $attachment ) {
	if ( current_user_can( 'edit_post', $post['ID'] ) ) {
		if ( ! empty( $attachment['wmguru_exclude'] ) ) {
			update_post_meta( $post['ID'], '_wmguru_exclude', 1 );
		} else {
			delete_post_meta( $post['ID'], '_wmguru_exclude' );
		}
	}

	return $post;
}
add_filter( 'attachment_fields_to_save', 'wmguru_attachment_fields_save', 10, 2 );

/**
 * Cached copies go stale when the source file changes (regenerated thumbnails,
 * edited/replaced images) or the attachment is deleted.
 *
 * @param array $data          Attachment metadata.
 * @param int   $attachment_id Attachment id.
 * @return array
 */
function wmguru_purge_on_metadata_update( $data, $attachment_id ) {
	wmguru_purge_attachment( $attachment_id, $data );

	return $data;
}
add_filter( 'wp_update_attachment_metadata', 'wmguru_purge_on_metadata_update', 10, 2 );
add_action( 'delete_attachment', 'wmguru_purge_attachment' );
