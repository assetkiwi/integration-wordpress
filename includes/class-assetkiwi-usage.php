<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports asset usage to asset.kiwi when a post that contains DAM assets
 * is saved or deleted.
 *
 * We scan post content for attachment IDs that have a `_assetkiwi_uuid` meta
 * value, then call the usage API for each.
 */
class AssetKiwi_Usage {

	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_delete_post' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	public function on_save_post( int $post_id, WP_Post $post ): void {
		// Skip revisions, auto-saves, and attachments themselves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'attachment' === $post->post_type ) {
			return;
		}

		$uuids = $this->extract_dam_uuids( $post );
		if ( empty( $uuids ) ) {
			return;
		}

		$client   = assetkiwi_client();
		$post_url = get_permalink( $post_id );

		foreach ( $uuids as $uuid ) {
			$client->report_usage( $uuid, $post->post_type, $post_id, $post_url ?: '' );
		}
	}

	public function on_delete_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		$uuids = $this->extract_dam_uuids( $post );
		if ( empty( $uuids ) ) {
			return;
		}

		$client = assetkiwi_client();

		foreach ( $uuids as $uuid ) {
			$client->remove_usage( $uuid, $post->post_type, $post_id );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Finds all asset.kiwi UUIDs referenced in a post.
	 *
	 * Checks:
	 *   1. Attachment IDs embedded in post content via `wp-image-{id}` CSS classes.
	 *   2. The `_assetkiwi_uuid` post meta stored directly on the post (e.g. via the block).
	 *
	 * @return string[]
	 */
	protected function extract_dam_uuids( WP_Post $post ): array {
		$uuids = array();

		// Direct UUID meta on the post (set by the Gutenberg block).
		$direct = get_post_meta( $post->ID, '_assetkiwi_uuid', false );
		foreach ( $direct as $uuid ) {
			if ( $uuid ) {
				$uuids[] = $uuid;
			}
		}

		// Scan content for attachment IDs in wp-image-{id} classes.
		if ( preg_match_all( '/class="[^"]*wp-image-(\d+)[^"]*"/', $post->post_content, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $attachment_id ) {
				$uuid = get_post_meta( (int) $attachment_id, '_assetkiwi_uuid', true );
				if ( $uuid ) {
					$uuids[] = $uuid;
				}
			}
		}

		return array_unique( $uuids );
	}
}
