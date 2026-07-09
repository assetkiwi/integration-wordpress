<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetKiwi_Block {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_assets' ) );
		add_action( 'wp_ajax_assetkiwi_block_get_asset', array( $this, 'ajax_get_asset' ) );
	}

	public function enqueue_block_assets(): void {
		wp_enqueue_script(
			'assetkiwi-block',
			ASSETKIWI_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor' ),
			ASSETKIWI_VERSION,
			true
		);

		wp_localize_script(
			'assetkiwi-block',
			'assetkiwiBlock',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'assetkiwi_block' ),
				'i18n'    => array(
					'blockTitle'       => __( 'asset.kiwi Asset', 'assetkiwi-connect' ),
					'blockDescription' => __( 'Embed an asset from asset.kiwi.', 'assetkiwi-connect' ),
					'selectAsset'      => __( 'Select Asset', 'assetkiwi-connect' ),
					'changeAsset'      => __( 'Change Asset', 'assetkiwi-connect' ),
					'variant'          => __( 'Variant', 'assetkiwi-connect' ),
					'original'         => __( 'Original', 'assetkiwi-connect' ),
					'loading'          => __( 'Loading…', 'assetkiwi-connect' ),
					'noVariants'       => __( 'No variants available.', 'assetkiwi-connect' ),
					'altText'          => __( 'Alt text', 'assetkiwi-connect' ),
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
	 * Returns a single asset's data for the block editor.
	 */
	public function ajax_get_asset(): void {
		check_ajax_referer( 'assetkiwi_block', 'nonce' );

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

		wp_send_json_success( $asset );
	}
}
