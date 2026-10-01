<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps post content pointing at the right files when a format conversion
 * (or a restore that undoes one) changes an attachment's URLs.
 *
 * Core blocks don't look images up by attachment ID at render time — the
 * Image and Cover blocks, among others, save the file URL straight into
 * post_content (in the block's JSON attributes and in its HTML). So once
 * photo.jpg becomes photo.webp and the .jpg is deleted, every page using it
 * breaks until the image is reselected.
 *
 * The rewrite is limited to post_content, which is plain text (block markup
 * or classic HTML), so a straight string replace is safe. Post meta and
 * options are deliberately left alone: page builders often store URLs there
 * as PHP-serialized data, where a naive replace changes string lengths and
 * corrupts the value. The Conversions Log's WP-CLI command covers those.
 */
class SIO_Content_Urls {

	/**
	 * Every public URL for an attachment, keyed by size name ('full' for the
	 * attached file, '_original' for the pre-"-scaled" upload if there is
	 * one), so URLs from before and after a change can be paired by size.
	 */
	public static function url_map( $attachment_id ) {
		$full = wp_get_attachment_url( $attachment_id );
		if ( ! $full ) {
			return array();
		}

		$map  = array( 'full' => $full );
		$base = trailingslashit( dirname( $full ) );
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( ! empty( $size['file'] ) ) {
					$map[ $name ] = $base . $size['file'];
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$map['_original'] = $base . $meta['original_image'];
		}

		return $map;
	}

	/**
	 * Pairs each old URL with its new equivalent: the same size if it still
	 * exists, otherwise the new full-size file (the closest safe fallback —
	 * never a URL that no longer resolves).
	 */
	public static function replacements( $old_map, $new_map ) {
		if ( empty( $new_map['full'] ) ) {
			return array();
		}

		$pairs = array();
		foreach ( $old_map as $name => $old_url ) {
			$new_url = isset( $new_map[ $name ] ) ? $new_map[ $name ] : $new_map['full'];
			if ( $old_url !== $new_url ) {
				$pairs[ $old_url ] = $new_url;
			}
		}
		return $pairs;
	}

	/**
	 * Applies the replacements to post_content across all post types except
	 * revisions (history stays as it was) and attachments. Covers posts,
	 * pages, synced patterns, and block-theme templates/template parts.
	 *
	 * Returns the number of posts updated.
	 */
	public static function rewrite( $pairs ) {
		global $wpdb;

		if ( empty( $pairs ) ) {
			return 0;
		}

		// Some builders and plugins save JSON with escaped slashes
		// (https:\/\/…) inside post_content — catch that form too.
		foreach ( $pairs as $old => $new ) {
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		}

		$likes = array();
		$args  = array();
		foreach ( array_keys( $pairs ) as $old ) {
			$likes[] = 'post_content LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $old ) . '%';
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				WHERE post_type NOT IN ( 'revision', 'attachment' )
				AND ( " . implode( ' OR ', $likes ) . ' )',
				$args
			)
		);

		$updated = 0;
		foreach ( $rows as $row ) {
			// strtr() matches longest keys first and never re-replaces its own
			// output, so overlapping URLs can't interfere with each other.
			$content = strtr( $row->post_content, $pairs );
			if ( $content === $row->post_content ) {
				continue;
			}

			// Written directly rather than via wp_update_post(): that would run
			// content through kses (which can strip markup for users without
			// unfiltered_html), bump the modified date, and spawn a revision
			// for what is purely a file-path fix.
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $row->ID ) );
			clean_post_cache( (int) $row->ID );
			$updated++;
		}

		return $updated;
	}
}
