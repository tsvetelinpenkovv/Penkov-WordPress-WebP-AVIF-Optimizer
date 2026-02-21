# Penkov WebP/AVIF Optimizer — Documentation

**Version:** 1.0.1  
**Author:** [PenkovStudio.eu](https://penkovstudio.eu)  
**License:** GPL-2.0-or-later

---

## 📦 Installation

1. Upload the `penkov-webp-avif-optimizer` folder to `/wp-content/plugins/`.
2. Activate through **Plugins → Installed Plugins**.
3. Navigate to **PenkovStudio → Image Optimizer** in the admin sidebar.
4. Review the **Dashboard** tab to verify server capabilities (Imagick, GD, WebP, AVIF).
5. Configure **Settings** (format, quality preset, auto-optimize behavior).
6. Use **Bulk Optimize** to process existing images.

---

## 🏗 Architecture

```
penkov-webp-avif-optimizer/
├── penkov-webp-avif-optimizer.php   # Bootstrap: constants, autoloader, activation hooks
├── uninstall.php                     # Clean removal of options, meta, crons
├── readme.txt                        # WordPress.org readme
│
├── includes/
│   ├── class-core.php               # Singleton orchestrator, on-upload hook
│   ├── class-capabilities.php       # Server detection: Imagick/GD, WebP/AVIF support
│   ├── class-converter.php          # Conversion engine: Imagick → GD fallback
│   ├── class-bulk-optimizer.php     # AJAX batch processing, queries, stats
│   ├── class-backup.php             # Backup/restore to /uploads/penkov-backups/
│   ├── class-delivery.php           # Frontend: <picture>, srcset, lazy load, CDN, LCP
│   ├── class-media-columns.php      # Media Library columns: status & savings
│   ├── class-logger.php             # File + transient log system
│   └── class-cron.php               # WP-Cron: backup cleanup, background bulk
│
├── admin/
│   └── class-admin.php              # Menus, settings, all tab renderers
│
├── assets/
│   ├── css/admin.css                # Modern admin UI styles
│   └── js/admin.js                  # Bulk AJAX, modals, UI interactions
│
└── logs/                             # Auto-created log directory (protected)
```

### Namespace

All classes live under `PenkovStudio\ImageOptimizer`. The autoloader maps class names to file paths automatically.

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| `image.jpg.webp` naming | Appending `.webp`/`.avif` to the original filename avoids collisions and allows easy file discovery. |
| Imagick priority, GD fallback | Imagick produces better compression and supports more options. GD is universally available as fallback. |
| Converted-larger-than-original check | If WebP/AVIF is *larger*, the file is discarded. No point wasting space. |
| Transient-based recent logs | Fast admin display without DB queries. File logs persist for audit. |
| `<picture>` as primary delivery | Maximum browser compatibility: AVIF source → WebP source → original `<img>` fallback. |

---

## ⚙ How Bulk Optimization Works

1. User clicks **Start Bulk Optimization** on the Bulk tab.
2. JS calls `penkov_opt_bulk_status` AJAX to get total/remaining counts.
3. JS calls `penkov_opt_bulk_process` AJAX in a loop:
   - Server fetches N unoptimized attachment IDs (batch size: configurable, default 20).
   - For each ID: backup (if enabled) → convert → save meta → optionally delete original.
   - **Time guard:** stops if approaching `max_execution_time`.
   - **Memory guard:** stops if memory usage > 85% of limit.
   - Returns results + remaining count.
4. JS updates progress bar, logs each result, and calls next batch.
5. **Pause:** JS sets a flag; next batch call is skipped.
6. **Resume:** flag cleared, batches resume.
7. When `remaining === 0`, process completes.

**Background mode:** If the user navigates away mid-bulk, a WP-Cron event can continue processing (10 images per minute).

---

## 🛡 Safety Mechanism for "Delete Originals"

This is the most dangerous feature and is **disabled by default**. When enabled:

### Safeguards:
1. **UI Warning Card** — Red danger zone with bold warning text.
2. **Checkbox: "I understand the risk"** — Must be checked.
3. **Modal confirmation** — Second popup asking "Are you sure?".
4. **Backup-first policy** — Before deleting any original, a copy is saved to `/uploads/penkov-backups/` (mirrors directory structure).
5. **All-or-nothing conversion** — Originals are only deleted if ALL format conversions (WebP *and* AVIF, if both selected) succeeded for ALL sizes.
6. **No backup = no delete** — If backups are enabled but a backup fails, the original is NOT deleted.
7. **Restore button** — Available per-image in Media Library if a backup exists.
8. **Backup retention** — Configurable (default 30 days). WP-Cron cleans expired backups daily.

### URL Compatibility After Deletion

When originals are deleted, existing URLs (e.g., `image.jpg`) would return 404. The plugin's strategy:

- The `.webp`/`.avif` files remain alongside (e.g., `image.jpg.webp`).
- The `<picture>` tag delivery ensures frontend visitors get the correct format.
- For direct URL access, we recommend adding an `.htaccess` rewrite rule:

```apache
# In /wp-content/uploads/.htaccess
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP_ACCEPT} image/webp
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME}.webp -f
RewriteRule ^(.+)\.(jpe?g|png|gif)$ $1.$2.webp [T=image/webp,L]
</IfModule>
```

This is logged as a recommendation in the admin; the plugin does not auto-modify `.htaccess` for safety.

---

## 🖼 Frontend Delivery

### `<picture>` Tag
Every `<img>` in `the_content` and `post_thumbnail_html` is wrapped:

```html
<picture>
  <source srcset="image.jpg.avif" type="image/avif">
  <source srcset="image.jpg.webp" type="image/webp">
  <img src="image.jpg" alt="..." loading="lazy">
</picture>
```

### Lazy Loading
- Uses native `loading="lazy"` attribute.
- **Skip first image:** The first `<img>` in content (likely above-fold) is NOT lazy-loaded.

### LCP Preload
- Specify an attachment ID or URL in Advanced settings.
- Outputs `<link rel="preload" as="image" href="..." type="image/webp">` in `<head>`.

### CDN Rewrite
- Set a CDN base URL (e.g., `https://cdn.example.com`).
- All image URLs are rewritten from `site_url` to CDN URL.

---

## ✅ Testing Checklist

### Server Environment

| Test | Expected |
|------|----------|
| PHP 7.4+ | Plugin activates without errors |
| PHP 8.0/8.1/8.2 | No deprecation warnings |
| Imagick available | Dashboard shows "Available", WebP/AVIF flags correct |
| Imagick unavailable, GD available | Dashboard shows GD as fallback, conversion still works |
| Neither Imagick nor GD | Plugin shows error, no crashes |
| AVIF not supported | Warning displayed, only WebP generated, no errors |
| Low memory (128MB) | Bulk optimizer stops gracefully before OOM |
| Short max_execution_time (30s) | Batch processing respects time limit |

### Conversion

| Test | Expected |
|------|----------|
| Upload JPEG | Auto-converted to WebP (and/or AVIF). Meta saved. |
| Upload PNG (transparent) | Converted with alpha preserved |
| Upload GIF (static) | Converted normally |
| Upload animated GIF (skip policy) | Skipped, logged |
| Upload animated GIF (convert policy) | Converted (animation may be lost) |
| Upload SVG | Ignored (not in allowed MIME types) |
| Upload image < skip_under_kb | Skipped |
| Converted file larger than original | Discarded, original kept |
| All thumbnails converted | Check each WP size has .webp/.avif |

### Bulk Optimization

| Test | Expected |
|------|----------|
| Start bulk with 100+ images | Progress bar advances, log updates |
| Pause mid-bulk | Processing stops cleanly |
| Resume | Continues from where it left off |
| Complete bulk | "All images optimized!" message, remaining = 0 |
| Navigate away mid-bulk | Can restart; cron may continue background |

### Frontend Delivery

| Test | Expected |
|------|----------|
| View post with images (Chrome) | `<picture>` tags with WebP/AVIF sources |
| View post (Safari 14/older) | Falls back to original format |
| View page source | `<picture>` wrapping, `loading="lazy"` on non-first images |
| Hero image with LCP preload | `<link rel="preload">` in `<head>` |
| CDN URL set | Image URLs rewritten to CDN domain |
| Query strings removed | No `?ver=` on image URLs |

### Delete Originals

| Test | Expected |
|------|----------|
| Enable toggle | Modal appears for confirmation |
| Confirm without "understand risk" | Feature does not activate |
| Confirm with modal + checkbox | Feature activates |
| Delete with backups ON | Originals backed up before deletion |
| Delete with backups OFF | Warning shown; originals deleted permanently |
| Restore from Media Library | Original file restored, WebP/AVIF removed |
| Backup retention cleanup | Old backups removed after X days via cron |

### Cache Plugin Compatibility

| Plugin | Test |
|--------|------|
| LiteSpeed Cache | Clear cache after bulk; verify WebP served |
| WP Rocket | Disable WP Rocket's WebP; verify no conflict |
| W3 Total Cache | Verify CDN integration works together |
| No cache plugin | Plugin works standalone |

### Hosting Environments

| Host Type | Notes |
|-----------|-------|
| Shared hosting (cPanel) | May have Imagick; verify GD fallback |
| VPS (Ubuntu/CentOS) | Full Imagick + AVIF likely available |
| Managed WP (Kinsta, WP Engine) | Check if Imagick is enabled |
| Docker/Local (DDEV, Local) | Test all capabilities |

---

## 📝 Name Alternatives Considered

1. **Penkov WebP/AVIF Optimizer** ← **CHOSEN** (clear, SEO-friendly, descriptive)
2. Penkov Image Accelerator — Too generic
3. Penkov PixelPress — Creative but unclear purpose
4. Penkov MediaShrink — Good but less professional
5. Penkov ImageForge — Good for branding but less SEO value

---

## 📄 License

GPL-2.0-or-later — Free to use, modify, and distribute.

**Made with ❤ by [PenkovStudio.eu](https://penkovstudio.eu)**
