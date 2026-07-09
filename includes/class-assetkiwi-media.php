<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects the asset.kiwi tab into the WordPress media modal and handles all
 * AJAX requests that power the asset browser.
 */
class AssetKiwi_Media {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'print_media_templates', array( $this, 'print_media_templates' ) );
		add_action( 'wp_ajax_assetkiwi_browse', array( $this, 'ajax_browse' ) );
		add_action( 'wp_ajax_assetkiwi_select_asset', array( $this, 'ajax_select_asset' ) );
	}

	public function enqueue_assets( string $hook ): void {
		// Only load on pages that include the media modal.
		if ( ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'assetkiwi-media-browser',
			ASSETKIWI_PLUGIN_URL . 'assets/js/media-browser.js',
			array( 'jquery', 'media-views' ),
			ASSETKIWI_VERSION,
			true
		);

		wp_localize_script(
			'assetkiwi-media-browser',
			'assetkiwiMedia',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'assetkiwi_media' ),
				'pluginUrl' => ASSETKIWI_PLUGIN_URL,
				'i18n'      => array(
					'tabLabel'    => __( 'asset.kiwi', 'assetkiwi-connect' ),
					'loading'     => __( 'Loading…', 'assetkiwi-connect' ),
					'searchLabel' => __( 'Search assets', 'assetkiwi-connect' ),
					'noResults'   => __( 'No assets found.', 'assetkiwi-connect' ),
					'select'      => __( 'Select', 'assetkiwi-connect' ),
					'insert'      => __( 'Insert', 'assetkiwi-connect' ),
					'prev'        => __( '&laquo; Prev', 'assetkiwi-connect' ),
					'next'        => __( 'Next &raquo;', 'assetkiwi-connect' ),
				),
			)
		);

		wp_enqueue_style(
			'assetkiwi-media-browser',
			ASSETKIWI_PLUGIN_URL . 'assets/css/media-browser.css',
			array(),
			ASSETKIWI_VERSION
		);
	}

	/**
	 * Renders Underscore/HTML templates used by the media modal JS.
	 */
	public function print_media_templates(): void {
		// Placeholder — the browser UI is built entirely in media-browser.js.
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_browse(): void {
		check_ajax_referer( 'assetkiwi_media', 'nonce' );

		$client = assetkiwi_client();

		$params = array(
			'page'     => max( 1, (int) ( $_GET['page'] ?? 1 ) ),
			'per_page' => 24,
		);

		if ( ! empty( $_GET['search'] ) ) {
			$params['search'] = sanitize_text_field( wp_unslash( $_GET['search'] ) );
		}
		if ( ! empty( $_GET['collection'] ) ) {
			$params['collection'] = sanitize_text_field( wp_unslash( $_GET['collection'] ) );
		}
		if ( ! empty( $_GET['tag'] ) ) {
			$params['tag'] = sanitize_text_field( wp_unslash( $_GET['tag'] ) );
		}
		if ( ! empty( $_GET['mime_type'] ) ) {
			$params['mime_type'] = sanitize_text_field( wp_unslash( $_GET['mime_type'] ) );
		}

		$response    = $client->get_assets( $params );
		$assets      = $response['data'] ?? array();
		$pager       = $client->normalize_pager( $response, $params['page'] );
		$collections = $client->get_collections();
		$tags        = $client->get_tags();

		ob_start();
		include ASSETKIWI_PLUGIN_DIR . 'templates/media-browser.php';
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'        => $html,
				'assets'      => $assets,
				'pager'       => $pager,
				'collections' => $collections['data'] ?? $collections,
				'tags'        => $tags['data'] ?? $tags,
			)
		);
	}

	/**
	 * Creates (or retrieves) a WordPress attachment that represents a DAM asset,
	 * then returns the data the JS needs to insert the image.
	 */
	public function ajax_select_asset(): void {
		check_ajax_referer( 'assetkiwi_media', 'nonce' );

		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( ! $uuid ) {
			wp_send_json_error( 'Missing UUID.' );
		}

		$client = assetkiwi_client();
		$raw    = $client->get_asset( $uuid );
		$asset  = $client->normalize_asset( $raw );

		if ( empty( $asset ) ) {
			wp_send_json_error( 'Asset not found.' );
		}

		$attachment_id = $this->get_or_create_attachment( $asset );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( $attachment_id->get_error_message() );
		}

		wp_send_json_success(
			array(
				'attachment_id' => $attachment_id,
				'url'           => $asset['url'] ?? '',
				'alt'           => $asset['alt_text'] ?? '',
				'caption'       => $asset['description'] ?? '',
				'width'         => $asset['width'] ?? 0,
				'height'        => $asset['height'] ?? 0,
				'mime_type'     => $asset['mime_type'] ?? '',
				'filename'      => $asset['filename'] ?? '',
				'variants'      => $asset['variants'] ?? array(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Attachment helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns an existing attachment for this DAM UUID or creates a stub one.
	 *
	 * The stub stores the DAM URL as the guid and attachment metadata so
	 * WordPress can serve it without re-hosting the file unless needed.
	 *
	 * @return int|\WP_Error
	 */
	protected function get_or_create_attachment( array $asset ) {
		$uuid = $asset['uuid'] ?? '';

		// Check if we already have an attachment for this UUID.
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => '_assetkiwi_uuid',
				'meta_value'     => $uuid,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $asset['original_name'] ?? $asset['filename'] ?? $uuid,
				'post_content'   => $asset['description'] ?? '',
				'post_excerpt'   => $asset['alt_text'] ?? '',
				'post_mime_type' => $asset['mime_type'] ?? 'application/octet-stream',
				'post_status'    => 'inherit',
				'guid'           => $asset['url'] ?? '',
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Store DAM metadata on the attachment.
		update_post_meta( $attachment_id, '_assetkiwi_uuid', $uuid );
		update_post_meta( $attachment_id, '_assetkiwi_url', $asset['url'] ?? '' );
		update_post_meta( $attachment_id, '_assetkiwi_variants', $asset['variants'] ?? array() );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $this->build_attachment_metadata( $asset ) );
		update_post_meta( $attachment_id, '_wp_attached_file', $asset['filename'] ?? '' );

		if ( ! empty( $asset['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $asset['alt_text'] );
		}

		return $attachment_id;
	}

	protected function build_attachment_metadata( array $asset ): array {
		$sizes = array();

		foreach ( $asset['variants'] ?? array() as $variant ) {
			$name = $variant['variant_name'] ?? '';
			if ( ! $name ) {
				continue;
			}
			$sizes[ $name ] = array(
				'file'      => basename( $variant['url'] ?? '' ),
				'width'     => $variant['width'] ?? 0,
				'height'    => $variant['height'] ?? 0,
				'mime-type' => $asset['mime_type'] ?? '',
				'url'       => $variant['url'] ?? '',
			);
		}

		return array(
			'width'  => $asset['width'] ?? 0,
			'height' => $asset['height'] ?? 0,
			'file'   => $asset['filename'] ?? '',
			'sizes'  => $sizes,
		);
	}
}
