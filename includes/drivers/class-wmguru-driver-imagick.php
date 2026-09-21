<?php
/**
 * Imagick driver. Keeps colour profiles and metadata of the source.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMGuru_Driver_Imagick {

	/** @var string */
	private $mime;

	/** @var Imagick|null */
	private $image = null;

	/** @var Imagick|null */
	private $layer = null;

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
		try {
			$probe = new Imagick();
			$probe->pingImage( $path );
			$pixels = $probe->getImageWidth() * $probe->getImageHeight();
			$probe->clear();
			if ( $pixels > wmguru_max_pixels() ) {
				return new WP_Error( 'wmguru_too_large', __( 'The image is too large to process.', 'watermark-guru' ) );
			}

			$image = new Imagick();
			$image->readImage( $path );

			switch ( $image->getImageOrientation() ) {
				case Imagick::ORIENTATION_BOTTOMRIGHT:
					$image->rotateImage( new ImagickPixel( 'none' ), 180 );
					break;
				case Imagick::ORIENTATION_RIGHTTOP:
					$image->rotateImage( new ImagickPixel( 'none' ), 90 );
					break;
				case Imagick::ORIENTATION_LEFTBOTTOM:
					$image->rotateImage( new ImagickPixel( 'none' ), -90 );
					break;
			}
			$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );

			if ( Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
				$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}

			$this->image = $image;
		} catch ( Exception $e ) {
			return new WP_Error( 'wmguru_imagick_read', $e->getMessage() );
		}

		return true;
	}

	/**
	 * @return int
	 */
	public function width() {
		return $this->image->getImageWidth();
	}

	/**
	 * @return int
	 */
	public function height() {
		return $this->image->getImageHeight();
	}

	/**
	 * @param string $text     Text.
	 * @param string $font     Font file path.
	 * @param int    $px       Font size in pixels.
	 * @param int    $rotation Degrees clockwise.
	 * @param string $color    Hex colour.
	 * @param float  $opacity  0..1.
	 * @return int[]|WP_Error Size of the rotated layer.
	 */
	public function prepare_text( $text, $font, $px, $rotation, $color, $opacity ) {
		try {
			$draw = new ImagickDraw();
			$draw->setFont( $font );
			$draw->setFontSize( $px );
			$draw->setFillColor( new ImagickPixel( $color ) );
			$draw->setTextAntialias( true );

			$m = $this->image->queryFontMetrics( $draw, $text );
			$w = (int) ceil( $m['textWidth'] ) + 4;
			$h = (int) ceil( $m['ascender'] - $m['descender'] ) + 4;

			$layer = new Imagick();
			$layer->newImage( $w, $h, new ImagickPixel( 'transparent' ), 'png' );
			$layer->annotateImage( $draw, 2, 2 + $m['ascender'], 0, $text );

			return $this->finish_layer( $layer, $rotation, $opacity );
		} catch ( Exception $e ) {
			return new WP_Error( 'wmguru_imagick_text', $e->getMessage() );
		}
	}

	/**
	 * @param string $path     Watermark image path.
	 * @param int    $width    Target width in px.
	 * @param int    $rotation Degrees clockwise.
	 * @param float  $opacity  0..1.
	 * @return int[]|WP_Error Size of the prepared layer.
	 */
	public function prepare_image( $path, $width, $rotation, $opacity ) {
		try {
			$layer = new Imagick();
			$layer->readImage( $path . '[0]' );
			$layer->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );

			$height = max( 1, (int) round( $layer->getImageHeight() * $width / $layer->getImageWidth() ) );
			$layer->resizeImage( $width, $height, Imagick::FILTER_LANCZOS, 1 );

			return $this->finish_layer( $layer, $rotation, $opacity );
		} catch ( Exception $e ) {
			return new WP_Error( 'wmguru_imagick_layer', $e->getMessage() );
		}
	}

	/**
	 * Rotate, apply opacity and remember the layer.
	 *
	 * @param Imagick $layer    Layer.
	 * @param int     $rotation Degrees clockwise.
	 * @param float   $opacity  0..1.
	 * @return int[]
	 */
	private function finish_layer( $layer, $rotation, $opacity ) {
		if ( 0 !== $rotation ) {
			$layer->rotateImage( new ImagickPixel( 'transparent' ), $rotation );
		}
		if ( $opacity < 0.995 ) {
			$layer->evaluateImage( Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA );
		}

		$this->layer = $layer;

		return array( $layer->getImageWidth(), $layer->getImageHeight() );
	}

	/**
	 * @param int $x Left.
	 * @param int $y Top.
	 */
	public function draw_prepared( $x, $y ) {
		if ( ! $this->layer ) {
			return;
		}

		$this->image->compositeImage( $this->layer, Imagick::COMPOSITE_OVER, $x, $y );
		$this->layer->clear();
		$this->layer = null;
	}

	/**
	 * @param string $dest    Destination path.
	 * @param string $mime    Output mime type.
	 * @param int    $quality Quality 30..100.
	 * @return true|WP_Error
	 */
	public function save( $dest, $mime, $quality ) {
		$formats = array(
			'image/jpeg' => 'jpeg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( ! isset( $formats[ $mime ] ) ) {
			return new WP_Error( 'wmguru_imagick_save', __( 'Unsupported output type.', 'watermark-guru' ) );
		}

		try {
			$format = $formats[ $mime ];
			$this->image->setImageFormat( $format );

			if ( in_array( $format, array( 'jpeg', 'webp', 'avif' ), true ) ) {
				$this->image->setImageCompressionQuality( $quality );
			}
			if ( 'jpeg' === $format ) {
				$this->image->setInterlaceScheme( Imagick::INTERLACE_PLANE );
			}
			if ( 'png' === $format ) {
				$this->image->setOption( 'png:compression-level', '6' );
			}

			$this->image->writeImage( $format . ':' . $dest );
		} catch ( Exception $e ) {
			return new WP_Error( 'wmguru_imagick_save', $e->getMessage() );
		}

		if ( ! file_exists( $dest ) || 0 === filesize( $dest ) ) {
			return new WP_Error( 'wmguru_imagick_save', __( 'Imagick could not write the watermarked image.', 'watermark-guru' ) );
		}

		return true;
	}

	public function close() {
		if ( $this->layer ) {
			$this->layer->clear();
			$this->layer = null;
		}
		if ( $this->image ) {
			$this->image->clear();
			$this->image = null;
		}
	}
}
