=== Squeeze Image Optimizer ===
Contributors: inkandwater, inkwaterpaul
Tags: images, optimization, media library, compression, webp
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resizes and recompresses Media Library images, with an optional format conversion, safe backups, and a bulk pass over your existing library.

== Description ==

Squeeze Image Optimizer resizes and recompresses images in the WordPress Media Library using only WordPress's own image-editing APIs (Imagick if your host has it, GD otherwise) — no external services, no API keys, no per-image fees.

Features:

* Max width and quality settings, same as a desktop image tool
* Never upscales — only ever shrinks an image that's wider than your cap
* Skips files already under a size threshold
* If the "optimised" result would be bigger than the original, keeps the original instead
* Optional format conversion (PNG to JPEG, or PNG/JPEG to WebP) — guarded so a transparent PNG is never silently flattened onto a black background by a JPEG conversion
* Automatic optimisation of new uploads
* A bulk tool to run over your whole existing library, in small batches so it won't time out on a large site
* Keeps one backup of each image's original file the first time it's optimised, with a one-click restore from the Media Library
* Backups expire automatically (7/14/30/90 days, or never) so they don't just sit there duplicating your library forever — plus a "delete all backups now" button and a live count of how many backups exist and how much space they're using
* A log of every format conversion, each with a ready-to-copy `wp search-replace` command for catching any hardcoded URLs left in page content

== Important notes ==

**Format conversion changes file extensions and URLs.** The attachment's own metadata is kept consistent automatically, so anything that references an image by its attachment ID (the block editor, most page builders) picks up the new URL on its own. Anything with the old URL hand-typed into content will not update on its own — check the Conversions Log tab after a bulk conversion run.

**Auto-optimise-on-upload does not keep a backup.** There's no attachment yet to attach one to when a file first lands in the uploads folder. If you want a backup, use the Bulk Optimise tool instead (it always backs up before touching anything), or just re-upload if a result looks wrong.

**Backup expiry runs once a day**, not instantly, so a backup can outlive its setting by up to 24 hours. Restoring an image deletes its backup immediately afterwards too, since the live file is the original again at that point.

**Animated GIFs are always left alone** — recompressing one through a static image pipeline would collapse the animation.

**Test on staging first** if you plan to use the format conversion options, especially on a site with a lot of older hand-edited content.

**The "Optimised" status column only shows in Media Library List view**, not the default Grid view — switch using the list icon next to "Add New" at the top of the Media Library screen.

== Installation ==

1. Upload the `squeeze-image-optimizer` folder to `/wp-content/plugins/`
2. Activate the plugin from the Plugins screen
3. Go to Media → Image Optimizer to configure settings and run the bulk tool

== Changelog ==

= 1.2.0 =
* Bulk Optimise progress bar now shows a lighter "in progress" segment and a status line the moment each batch is sent, instead of only updating once it returns — a slow batch no longer looks like a frozen page
* Added a rough time-remaining estimate, based on a rolling average of how long recent batches took
* One bad image (e.g. a corrupt file, or one that trips a PHP memory/time limit via Imagick) no longer silently fails its whole batch of 5 with no explanation — it's now caught and reported individually, by name, so the rest of the batch still completes
* Added a visible, scrollable Errors panel under the Bulk Optimise progress bar, naming the affected image and the specific reason; a batch that fails outright (e.g. a server timeout) is now logged with its image IDs and the HTTP status instead of just incrementing a silent error count

= 1.1.0 =
* Backup expiry: choose how long originals are kept (7/14/30/90 days, or forever), with a daily cleanup sweep
* "Delete all backups now" button plus a live count of backups stored and disk space used
* Restoring an image now cleans up its own backup immediately, rather than waiting for the next expiry sweep
* Bulk Optimise summary now shows an explicit "optimised" count alongside the total processed, not just the size savings

= 1.0.0 =
* Initial release
