=== Penkov WebP/AVIF Optimizer ===
Contributors: penkovstudio
Tags: webp, avif, image optimization, compression, performance, seo, lazy load
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

High-performance image optimizer — converts to WebP/AVIF, aggressive compression, automatic delivery with <picture>/srcset, lazy loading, LCP preload, and CDN support. By PenkovStudio.eu.

== Description ==

**Penkov WebP/AVIF Optimizer** is a professional, fully server-side image optimization plugin for WordPress. No external APIs, no monthly fees — everything runs locally on your server.

= Key Features =

* **WebP & AVIF Conversion** — Choose one or both formats. AVIF for maximum compression, WebP for broad compatibility.
* **Aggressive Compression** — Quality slider (0–100) with presets: Aggressive (60), Balanced (82), Safe (92).
* **Auto-Optimize on Upload** — Every new image is automatically converted.
* **Bulk Optimization** — Process your entire media library with progress bar, pause/resume, and batch processing.
* **Smart Delivery** — `<picture>` tags with AVIF/WebP sources and original fallback. Fully responsive srcset/sizes.
* **Lazy Loading** — Native `loading="lazy"` with skip-first-image option for above-fold content.
* **LCP Preload** — `<link rel="preload">` for your hero image to boost Core Web Vitals.
* **CDN Support** — Optional CDN base URL rewrite.
* **Backup & Restore** — Before deleting originals, backups are stored in `/uploads/penkov-backups/`. One-click restore.
* **Safe "Delete Originals"** — 2-step confirmation, mandatory backup check, conversion verification.
* **Media Library Integration** — "Optimized" column with savings percentage and one-click optimize/restore buttons.
* **Modern Admin UI** — Clean dashboard with stats, server capabilities, logs, and responsive design.

= Server Requirements =

* **Imagick** (recommended) or **GD Library**
* WebP support in Imagick/GD
* AVIF support (optional — plugin gracefully degrades if unavailable)

= Developed by =

[PenkovStudio.eu](https://penkovstudio.eu) — Professional WordPress solutions.

== Installation ==

1. Upload the `penkov-webp-avif-optimizer` folder to `/wp-content/plugins/`.
2. Activate through **Plugins → Installed Plugins**.
3. Go to **PenkovStudio → Image Optimizer** to configure.
4. Check the **Dashboard** tab for server capabilities.
5. Adjust **Settings** (format, quality, behavior).
6. Run **Bulk Optimize** on your existing media library.

== Frequently Asked Questions ==

= Does this plugin require an external API? =
No. Everything runs locally on your server using Imagick or GD.

= What happens if AVIF is not supported? =
The plugin detects this automatically and shows a warning. Only WebP will be generated.

= Is it safe to delete originals? =
The feature is OFF by default. When enabled, there's a 2-step confirmation, mandatory backup option, and conversion verification. We strongly recommend keeping backups enabled.

= Does it work with cache plugins? =
Yes. Compatible with LiteSpeed Cache, WP Rocket, W3 Total Cache, and others. Clear your cache after bulk optimization.

== Changelog ==

= 1.0.0 =
* Initial release.
