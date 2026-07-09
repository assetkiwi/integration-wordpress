<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetKiwi_Client {

	protected string $api_url;
	protected string $api_token;

	public function __construct( string $api_url, string $api_token ) {
		$this->api_url   = rtrim( $api_url, '/' );
		$this->api_token = $api_token;
	}

	public function set_credentials( string $api_url, string $api_token ): void {
		$this->api_url   = rtrim( $api_url, '/' );
		$this->api_token = $api_token;
	}

	protected function default_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->api_token,
			'Accept'        => 'application/json',
		);
	}

	protected function endpoint( string $path ): string {
		return $this->api_url . '/api/v1' . $path;
	}

	/**
	 * @param array $params  Query params: search, collection, tag, mime_type, page, per_page.
	 */
	public function get_assets( array $params = array() ): array {
		$response = wp_remote_get(
			add_query_arg( $params, $this->endpoint( '/assets' ) ),
			array( 'headers' => $this->default_headers(), 'timeout' => 15 )
		);

		return $this->decode( $response );
	}

	public function get_asset( string $uuid ): array {
		$response = wp_remote_get(
			$this->endpoint( '/assets/' . $uuid ),
			array( 'headers' => $this->default_headers(), 'timeout' => 15 )
		);

		return $this->decode( $response );
	}

	public function get_collections(): array {
		$response = wp_remote_get(
			$this->endpoint( '/collections' ),
			array( 'headers' => $this->default_headers(), 'timeout' => 15 )
		);

		return $this->decode( $response );
	}

	public function get_tags(): array {
		$response = wp_remote_get(
			$this->endpoint( '/tags' ),
			array( 'headers' => $this->default_headers(), 'timeout' => 15 )
		);

		return $this->decode( $response );
	}

	public function get_image_styles(): array {
		$response = wp_remote_get(
			$this->endpoint( '/image-styles' ),
			array( 'headers' => $this->default_headers(), 'timeout' => 15 )
		);

		return $this->decode( $response );
	}

	public function get_derivatives( string $uuid ): array {
		$response = wp_remote_get(
			$this->endpoint( '/assets/' . $uuid . '/derivatives' ),
			array( 'headers' => $this->default_headers(), 'timeout' => 15 )
		);

		return $this->decode( $response );
	}

	/**
	 * Initiates a download and returns the raw binary body, or null on failure.
	 */
	public function download_asset( string $uuid ): ?string {
		$response = wp_remote_post(
			$this->endpoint( '/assets/' . $uuid . '/download' ),
			array( 'headers' => $this->default_headers(), 'timeout' => 60 )
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return ( 200 === $code ) ? wp_remote_retrieve_body( $response ) : null;
	}

	public function report_usage( string $uuid, string $post_type, int $post_id, string $post_url ): void {
		$headers           = $this->default_headers();
		$headers['Content-Type'] = 'application/json';

		wp_remote_post(
			$this->endpoint( '/assets/' . $uuid . '/usage' ),
			array(
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'source_site'        => home_url(),
						'source_entity_type' => $post_type,
						'source_entity_id'   => (string) $post_id,
						'source_url'         => $post_url,
					)
				),
				'timeout' => 10,
			)
		);
	}

	public function remove_usage( string $uuid, string $post_type, int $post_id ): void {
		$headers                 = $this->default_headers();
		$headers['Content-Type'] = 'application/json';

		wp_remote_request(
			$this->endpoint( '/assets/' . $uuid . '/usage' ),
			array(
				'method'  => 'DELETE',
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'source_site'        => home_url(),
						'source_entity_type' => $post_type,
						'source_entity_id'   => (string) $post_id,
					)
				),
				'timeout' => 10,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalises an asset payload – the API may wrap in `data`.
	 */
	public function normalize_asset( array $asset ): array {
		return $asset['data'] ?? $asset;
	}

	public function get_asset_display_name( array $asset ): string {
		return $asset['original_name'] ?? $asset['filename'] ?? 'Untitled';
	}

	public function resolve_variant( array $asset, string $variant_name ): ?array {
		foreach ( $asset['variants'] ?? array() as $variant ) {
			if ( ( $variant['variant_name'] ?? '' ) === $variant_name ) {
				return $variant;
			}
		}
		return null;
	}

	public function resolve_variant_url( array $asset, string $variant_name ): ?string {
		return $this->resolve_variant( $asset, $variant_name )['url'] ?? null;
	}

	/**
	 * Returns pager metadata in a normalised shape.
	 */
	public function normalize_pager( array $response, int $current_page ): array {
		$items = $response['data'] ?? $response;
		return array(
			'current_page' => $current_page,
			'total'        => $response['meta']['total'] ?? count( $items ),
			'per_page'     => $response['meta']['per_page'] ?? 24,
			'last_page'    => $response['meta']['last_page'] ?? 1,
		);
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	protected function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			error_log( 'asset.kiwi API error: ' . $response->get_error_message() );
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( sprintf( 'asset.kiwi API returned HTTP %d', $code ) );
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : array();
	}
}
