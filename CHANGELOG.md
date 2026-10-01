# Changelog

All notable changes to this project are documented here.

## [1.2.2] - 2026-10-01

### Added
- The plugin version is now shown next to the page title on Media → Image Optimizer, so it's easy to confirm which version is running.

### Fixed
- The 1.2.1 batch-size fix never reached browsers: `SIO_VERSION` (the `?ver=` cache-buster on admin.js) was left at 1.2.0, so the old script stayed cached and bulk runs still sent batches of ~2,000 images (status line read e.g. "Optimising images 21–2020 of 2686"). The version constant now matches the plugin header.
- The server now rejects any bulk batch larger than 20 images, so a stale or faulty script can't send an oversized request again.

## [1.2.1] - 2026-10-01

### Fixed
- A bulk run's batch size could silently balloon to thousands of images in a single request — visible as the progress bar jumping in huge, uneven leaps (5 → 55 → 555 → done) and liable to hit a server timeout on a large library. Caused by `wp_localize_script` sending the batch size through as a string; batch-boundary arithmetic (`index + batchSize`) was doing string concatenation instead of addition. Now explicitly parsed to a number client-side.

### Changed
- Batch size increased from 5 to 20 images per request, now that it's reliably enforced — fewer round trips, faster bulk runs.

## [1.2.0] - 2026-10-01

### Added
- Bulk Optimise progress bar now shows a lighter "in progress" segment and a status line the moment each batch is sent, instead of only updating once it returns — a slow batch no longer looks like a frozen page.
- Rough time-remaining estimate, based on a rolling average of recent batch durations.
- A visible, scrollable Errors panel under the Bulk Optimise progress bar, naming the affected image and the specific reason.

### Fixed
- One bad image (a corrupt file, or one that trips a PHP memory/time limit via Imagick) no longer silently fails its whole batch of 5 with no explanation — it's now caught and reported individually, so the rest of the batch still completes.
- A batch that fails outright (e.g. a server timeout) is now logged with its image IDs and HTTP status instead of just incrementing a silent error count.

## [1.1.0] - 2026-10-01

### Added
- Backup expiry: choose how long originals are kept (7/14/30/90 days, or forever), with a daily cleanup sweep.
- "Delete all backups now" button, plus a live count of backups stored and disk space used.
- Bulk Optimise summary now shows an explicit "optimised" count alongside the total processed, not just the size savings.

### Changed
- Restoring an image now cleans up its own backup immediately, rather than waiting for the next expiry sweep.

## [1.0.0] - 2026-10-01

### Added
- Initial release: resize, recompress, and optional format conversion (PNG→JPEG, PNG/JPEG→WebP) for Media Library images.
- Transparency-aware guard against converting a transparent PNG to JPEG.
- Automatic optimisation of new uploads.
- Bulk tool for existing libraries, batched to avoid hosting execution-time limits.
- Per-attachment backup with one-click restore.
- Conversions log with ready-to-copy `wp search-replace` commands.
