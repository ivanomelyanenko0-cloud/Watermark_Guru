<?php
/**
 * GD driver. Note: GD re-encodes without metadata and colour profiles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMGuru_Driver_GD {

	/** @var string */
	private $mime;

	/** @var resource|GdImage|null */
	private $im = null;

	/** @var array|null */
	private $prepared = null;

	/**
	 * @param string $mime Source mime type.
	 */
	public function __construct( $mime ) {
		$this->mime = $mime;
	}

	/**
	 * @param string $path Source path.
	 * @return true|WP_Error
	 */
	public function open( $path ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new WP_Error( 'wmguru_gd_read', __( 'Could not read the image file.', 'watermark-guru' ) );
		}

		$info = getimagesizefromstring( $contents );
		if ( ! $info ) {
			return new WP_Error( 'wmguru_gd_read', __( 'Not a readable image.', 'watermark-guru' ) );
		}
		if ( $info[0] * $info[1] > wmguru_max_pixels() ) {
			return new WP_Error( 'wmguru_too_large', __( 'The image is too large to process.', 'watermark-guru' ) );
		}

		$im = imagecreatefromstring( $contents );
		unset( $contents );
		if ( ! $im ) {
			return new WP_Error( 'wmguru_gd_read', __( 'GD could not decode the image.', 'watermark-guru' ) );
		}

		if ( ! imageistruecolor( $im ) ) {
			imagepalettetotruecolor( $im );
		}

		$im = $this->apply_exif_orientation( $im, $path );

		imagealphablending( $im, true );
		imagesavealpha( $im, true );
		$this->im = $im;

		return true;
	}

	/**
	 * GD drops EXIF on save, so bake the orientation into the pixels.
	 *
	 * @param resource|GdImage $im   Image.
	 * @param string           $path Source path.
	 * @return resource|GdImage
	 */
	private function apply_exif_orientation( $im, $path ) {
		if ( 'image/jpeg' !== $this->mime || ! function_exists( 'exif_read_data' ) ) {
			return $im;
		}

		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( empty( $exif['Orientation'] ) ) {
			return $im;
		}

		$angles = array(
			3 => 180,
			6 => -90,
			8 => 90,
		);
		if ( isset( $angles[ (int) $exif['Orientation'] ] ) ) {
			$rotated = imagerotate( $im, $angles[ (int) $exif['Orientation'] ], 0 );
			if ( $rotated ) {
				$im = $rotated;
			}
		}

		return $im;
	}

	/**
	 * @return int
	 */
	public function width() {
		return imagesx( $this->im );
	}

	/**
	 * @return int
	 */
	public function height() {
		return imagesy( $this->im );
	}

	/**
	 * @param string $text     Text.
	 * @param string $font     Font file path.
	 * @param int    $px       Font size in pixels.
	 * @param int    $rotation Degrees clockwise.
	 * @param string $color    Hex colour.
	 * @param float  $opacity  0..1.
	 * @return int[]|WP_Error Size of the rotated bounding box.
	 */
	public function prepare_text( $text, $font, $px, $rotation, $color, $opacity ) {
		$pt    = $px * 0.75; // GD works at 96 dpi: 1 px = 0.75 pt.
		$angle = -$rotation; // GD rotates counter-clockwise.
		$box   = imagettfbbox( $pt, $angle, $font, $text );
		if ( ! $box ) {
			return new WP_Error( 'wmguru_gd_text', __( 'GD could not measure the text (font unreadable?).', 'watermark-guru' ) );
		}

		$xs = array( $box[0], $box[2], $box[4], $box[6] );
		$ys = array( $box[1], $box[3], $box[5], $box[7] );

		$this->prepared = array(
			'kind'    => 'text',
			'text'    => $text,
			'font'    => $font,
			'pt'      => $pt,
			'angle'   => $angle,
			'color'   => $color,
			'opacity' => $opacity,
			'ox'      => -min( $xs ),
			'oy'      => -min( $ys ),
		);

		return array( (int) ceil( max( $xs ) - min( $xs ) ), (int) ceil( max( $ys ) - min( $ys ) ) );
	}

	/**
	 * @param string $path     Watermark image path.
	 * @param int    $width    Target width in px.
	 * @param int    $rotation Degrees clockwise.
	 * @param float  $opacity  0..1.
	 * @return int[]|WP_Error Size of the prepared layer.
	 */
	public function prepare_image( $path, $width, $rotation, $opacity ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src      = false === $contents ? false : imagecreatefromstring( $contents );
		if ( ! $src ) {
			return new WP_Error( 'wmguru_gd_layer', __( 'GD could not read the watermark image.', 'watermark-guru' ) );
		}

		if ( ! imageistruecolor( $src ) ) {
			imagepalettetotruecolor( $src );
		}

		$sw = imagesx( $src );
		$sh = imagesy( $src );
		$th = max( 1, (int) round( $sh * $width / $sw ) );

		$layer = imagecreatetruecolor( $width, $th );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefill( $layer, 0, 0, imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
		imagecopyresampled( $layer, $src, 0, 0, 0, 0, $width, $th, $sw, $sh );
		imagedestroy( $src );

		if ( 0 !== $rotation ) {
			$rotated = imagerotate( $layer, -$rotation, imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
			imagedestroy( $layer );
			if ( ! $rotated ) {
				return new WP_Error( 'wmguru_gd_layer', __( 'GD could not rotate the watermark image.', 'watermark-guru' ) );
			}
			$layer = $rotated;
			imagealphablending( $layer, false );
			imagesavealpha( $layer, true );
		}

		$this->apply_opacity( $layer, $opacity );

		$this->prepared = array(
			'kind'  => 'image',
			'layer' => $layer,
		);

		return array( imagesx( $layer ), imagesy( $layer ) );
	}

	/**
	 * Scale every pixel's alpha by $opacity.
	 *
	 * @param resource|GdImage $layer   Layer with alphablending off.
	 * @param float            $opacity 0..1.
	 */
	private function apply_opacity( $layer, $opacity ) {
		if ( $opacity >= 0.995 ) {
			return;
		}

		$w = imagesx( $layer );
		$h = imagesy( $layer );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba  = imagecolorat( $layer, $x, $y );
				$alpha = ( $rgba >> 24 ) & 0x7F;
				if ( 127 === $alpha ) {
					continue;
				}
				$new = 127 - (int) round( ( 127 - $alpha ) * $opacity );
				imagesetpixel( $layer, $x, $y, ( $rgba & 0xFFFFFF ) | ( $new << 24 ) );
			}
		}
	}

	/**
	 * @param int $x Left.
	 * @param int $y Top.
	 */
	public function draw_prepared( $x, $y ) {
		$p = $this->prepared;
		if ( ! $p ) {
			return;
		}

		imagealphablending( $this->im, true );

		if ( 'text' === $p['kind'] ) {
			$hex   = ltrim( $p['color'], '#' );
			$alpha = 127 - (int) round( 127 * $p['opacity'] );
			$color = imagecolorallocatealpha(
				$this->im,
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
				$alpha
			);
			imagettftext( $this->im, $p['pt'], $p['angle'], (int) round( $x + $p['ox'] ), (int) round( $y + $p['oy'] ), $color, $p['font'], $p['text'] );
		} else {
			imagecopy( $this->im, $p['layer'], $x, $y, 0, 0, imagesx( $p['layer'] ), imagesy( $p['layer'] ) );
			imagedestroy( $p['layer'] );
		}

		$this->prepared = null;
	}

	/**
	 * @param string $dest    Destination path.
	 * @param string $mime    Output mime type.
	 * @param int    $quality Quality 30..100.
	 * @return true|WP_Error
	 */
	public function save( $dest, $mime, $quality ) {
		switch ( $mime ) {
			case 'image/jpeg':
				imageinterlace( $this->im, true );
				$ok = imagejpeg( $this->im, $dest, $quality );
				break;
			case 'image/png':
				imagesavealpha( $this->im, true );
				$ok = imagepng( $this->im, $dest, 6 );
				break;
			case 'image/gif':
				$ok = imagegif( $this->im, $dest );
				break;
			case 'image/webp':
				$ok = imagewebp( $this->im, $dest, $quality );
				break;
			case 'image/avif':
				$ok = function_exists( 'imageavif' ) && imageavif( $this->im, $dest, $quality );
				break;
			default:
				$ok = false;
		}

		if ( ! $ok || ! file_exists( $dest ) || 0 === filesize( $dest ) ) {
			return new WP_Error( 'wmguru_gd_save', __( 'GD could not write the watermarked image.', 'watermark-guru' ) );
		}

		return true;
	}

	public function close() {
		if ( $this->im ) {
			imagedestroy( $this->im );
			$this->im = null;
		}
	}
}
