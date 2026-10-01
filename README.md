# Squeeze Image Optimizer

A WordPress plugin that resizes, recompresses and optionally converts images already in — or newly added to — your Media Library. Built entirely on WordPress's own image editor API (Imagick where available, GD otherwise), so there's no external service, no API key, and no per-image fee.

## Features

- **Max width and quality** settings, same mental model as a desktop image tool
- **Never upscales** — only ever shrinks an image that's wider than your cap
- **Skips files already under a size threshold**, so re-runs are fast
- **Keeps the original if the "optimised" result would be bigger** — never trades a worse result for a smaller one
- **Optional format conversion** (PNG → JPEG, or PNG/JPEG → WebP), with a guard so a transparent PNG is never silently flattened onto a black background by a JPEG conversion
- **Automatic optimisation of new uploads**, applied before thumbnails are generated so core never does the resize twice
- **A bulk tool** for your existing library, processed in small batches so it survives hosting execution-time limits on libraries of thousands of images — with a live progress bar, ETA, and a per-image error log
- **Backups with expiry** — the original is kept the first time an image is touched, with one-click restore from the Media Library, an automatic expiry window (7/14/30/90 days, or forever), and a manual "delete all backups now" option
- **A conversions log** — every format change gets a ready-to-copy `wp search-replace` command for catching any hardcoded URLs left in page content

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Imagick (recommended) or GD — most hosts have one of these already

## Installation

1. Download or clone this repo into `wp-content/plugins/squeeze-image-optimizer`, or zip it up and upload via Plugins → Add New → Upload
2. Activate the plugin
3. Go to **Media → Image Optimizer** to configure settings and run the bulk tool

## How it works

The plugin leans entirely on `wp_get_image_editor()` — the same abstraction WordPress core uses to generate thumbnails — rather than shelling out to external binaries or calling a third-party API. That keeps it portable across hosts and means there's nothing to configure beyond the plugin's own settings.

- **New uploads** are intercepted via the `wp_handle_upload`/`wp_handle_sideload` filters, before any attachment post or thumbnail sizes exist, so the smaller/converted file is what every registered size gets generated from.
- **Existing attachments** go through the bulk tool, which backs up the original once, resizes/recompresses/converts, regenerates thumbnail sizes, and records size-saving stats against the attachment.
- **Format conversion** updates the attachment's own metadata (mime type, attached file) so anything referencing the image by attachment ID — the block editor, most page builders — picks up the new URL automatically. Anything with the URL hand-typed into older content won't update on its own; the Conversions Log tab gives you the exact WP-CLI command to find and fix those safely, rather than the plugin attempting a blind database rewrite itself.

## Important notes

- **Auto-optimise-on-upload doesn't keep a backup** — there's no attachment yet to attach one to at that point in the upload process. Use the bulk tool if you want a backup kept.
- **Backup expiry runs once a day**, not instantly, so a backup can outlive its configured window by up to 24 hours. Restoring an image deletes its own backup immediately afterwards.
- **Animated GIFs are always left alone** — recompressing one through a static image pipeline would collapse the animation.
- **The "Optimised" status column only shows in Media Library List view**, not the default Grid view.
- **Test format conversion on staging first**, especially on a site with a lot of older hand-edited content.
- If your host offloads media to an actual S3 bucket (e.g. via WP Offload Media), note that this plugin assumes direct filesystem access — it hasn't been built against stream-wrapper-based offload setups. It works unmodified on hosts (like WP Engine) where storage is AWS-backed underneath but presented as an ordinary local filesystem.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later.
