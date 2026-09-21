=== Watermark Guru ===
Contributors: lukystile
Tags: watermark, image watermark, protect images, webp, copyright
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Text or logo watermarks generated on the fly and cached. Your original images are never modified. Works with JPEG, PNG, WebP, AVIF, GIF.

== Description ==

**Watermark Guru** protects and brands your images the safe way: your original files are **never touched**. Watermarked copies are created the first time an image is requested, stored in a cache, and swapped into your pages at output time.

That design removes the problems most watermark plugins have:

* **No "restore" needed.** Originals stay exactly as uploaded, so there is nothing to undo.
* **No quality loss on your originals.** Only the copy is re-encoded, at a quality you choose.
* **Deactivate = instantly clean.** Turn the plugin off and visitors get the untouched images straight away.
* **Works whatever way the image got in.** Because it acts on the URL, it does not matter whether images arrive through the Media Library, an importer, a gallery block or a regenerate-thumbnails run.
* **Modern formats.** JPEG, PNG, WebP, AVIF and (static) GIF.
* **Any language.** Bundled fonts cover Latin, Cyrillic and Greek.

= Features =

* Text watermark and/or logo watermark (up to two layers).
* Live preview rendered by the same engine that creates the real copies.
* Nine-position placement, margin, opacity, rotation.
* Size relative to each image, with minimum and maximum limits.
* Choose which image sizes get a watermark and skip small images.
* "Do not watermark this image" switch on every attachment.
* Text tokens: `{site}`, `{year}`, `{url}`.
* Works with GD or Imagick (chosen automatically); EXIF orientation is respected.
* Site Health checks for the image library, cache folder and delivery.
* Automatic fallback for hosts that do not pass missing image URLs to WordPress.

Need several watermark profiles, unlimited layers, or rules that pick a watermark by post type, category or file type? They are in **[Watermark Guru Pro](https://cognitolab.net/products/watermark-guru)**, a separate, optional add-on. Everything described above works fully in the free plugin, with no restrictions or nag screens.

== External services ==

This plugin does not connect to any external service. No data leaves your site. Watermarked copies are stored in `wp-content/uploads/wmguru-cache`.

== Installation ==

1. Upload the `watermark-guru` folder to `/wp-content/plugins/`, or install it from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Media → Watermark Guru**.
4. Set up your text and/or logo, check the live preview, then tick **Show watermarked images on the front end** and save.

== Frequently Asked Questions ==

= Does it change my original files? =

No. Originals are never modified or re-encoded. Watermarked copies live in `wp-content/uploads/wmguru-cache` and can be deleted at any time; they are regenerated on demand.

= What happens if I deactivate or uninstall the plugin? =

Deactivating stops the URL swapping at once, so visitors see the originals. Uninstalling also deletes the cache folder. Your settings are kept unless you ticked "Also delete these settings".

= Can someone still get the original image? =

Yes, if they know the original file URL. Watermark Guru replaces the URLs your site outputs; it does not block direct requests to files in `wp-content/uploads`.

= Are animated GIFs and WebP watermarked? =

Not yet. They are served unchanged.

= It says direct file URLs do not work on my host. =

Some hosts answer 404 for missing image files without asking WordPress. Watermark Guru detects this and switches to signed query-string URLs automatically. Everything still works.

= Will my page-builder images be watermarked? =

Images output through WordPress image functions and post content are. Page builders that print raw upload URLs from their own data may not be; support for those is planned.

= Which image library do I need? =

GD (with FreeType) or Imagick. Imagick keeps colour profiles and metadata of the source; GD drops them. AVIF needs Imagick with AVIF support, or PHP 8.1+ with GD AVIF support.

== Third-party resources ==

Bundled fonts: Noto Sans and Noto Serif, © The Noto Project Authors, licensed under the SIL Open Font License 1.1 (see `fonts/OFL.txt`).

== Changelog ==

= 1.0.0 =
* First public release.
