<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetKiwi_Client {

	protected string $api_url;
	protected string $api_token;
	protected ?string $last_error = null;

	public function __construct( string $api_url, string $api_token ) {
		$this->api_url   = rtrim( $api_url, '/' );
		$this->api_token = $api_token;
	}

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function set_credentials( string $api_url, string $api_token ): void {
		$this->api_url   = rtrim( $api_url, '/' );
		$this->api_token = $api_token;
	}

	/**
	 * Resolve the API token to use for the current request.
	 *
	 * In per-user OAuth mode, a logged-in user MUST have their own OAuth
	 * access token — falling back to the shared API token here would
	 * silently bypass the "each user connects individually" requirement and
	 * the browser would never prompt the user to connect. Only genuinely
	 * anonymous requests (no logged-in user at all) fall back to the shared
	 * token. In shared token mode, always returns the shared token.
	 */
	private function resolve_api_token(): ?string {
		if ( ! class_exists( 'AssetKiwi_OAuth' ) || ! AssetKiwi_OAuth::is_enabled() ) {
			return $this->api_token;
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			return AssetKiwi_OAuth::get_token( $user_id );
		}

		return $this->api_token;
	}

	protected function default_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . ( $this->resolve_api_token() ?? '' ),
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

	/**
	 * Upload a file to asset.kiwi. For migration/offloading use only.
	 * End-user uploads must happen through the asset.kiwi app directly.
	 *
	 * Mirrors the Drupal client's uploadAsset(): relies on core's
	 * content-addressed dedup (POST /api/v1/assets returns 409 + existing_uuid
	 * for a byte-identical file already ingested) rather than doing any
	 * client-side "have I uploaded this before" bookkeeping.
	 *
	 * @internal Used by the assetkiwi-connect-migrate plugin.
	 *
	 * @return array{uuid: string, reused: bool}|null  Null on failure — call
	 *   get_last_error() for why.
	 */
	public function upload_asset_for_migration( string $file_path, string $original_filename, string $mime_type ): ?array {
		if ( ! is_readable( $file_path ) ) {
			$this->last_error = 'File not readable: ' . $file_path;
			return null;
		}

		$boundary = wp_generate_password( 24, false );

		$response = wp_remote_post(
			$this->endpoint( '/assets' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . ( $this->resolve_api_token() ?? '' ),
					'Accept'        => 'application/json',
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $this->build_multipart_body( $boundary, $file_path, $original_filename, $mime_type ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( 201 === $code ) {
			$uuid = $data['data']['uuid'] ?? $data['uuid'] ?? null;
			return $uuid ? array( 'uuid' => $uuid, 'reused' => false ) : null;
		}
		if ( 409 === $code ) {
			$uuid = $data['existing_uuid'] ?? null;
			return $uuid ? array( 'uuid' => $uuid, 'reused' => true ) : null;
		}

		$this->last_error = $data['message'] ?? sprintf( 'Upload failed with HTTP %d', $code );
		return null;
	}

	/**
	 * Get a DynamicDelivery transform URL for on-the-fly image processing.
	 *
	 * @param string $uuid Asset UUID.
	 * @param array  $options Transform options: w, h, fit, format, q, gravity.
	 * @return string The API transform URL (302-redirects to signed imgproxy URL).
	 */
	public function get_transform_url( string $uuid, array $options = array() ): string {
		$params = array();
		if ( isset( $options['w'] ) ) {
			$params['w'] = (int) $options['w'];
		}
		if ( isset( $options['h'] ) ) {
			$params['h'] = (int) $options['h'];
		}
		if ( isset( $options['fit'] ) ) {
			$params['fit'] = $options['fit'];
		}
		if ( isset( $options['format'] ) ) {
			$params['format'] = $options['format'];
		}
		if ( isset( $options['q'] ) ) {
			$params['q'] = (int) $options['q'];
		}
		if ( isset( $options['gravity'] ) ) {
			$params['gravity'] = $options['gravity'];
		}

		$query = ! empty( $params ) ? '?' . http_build_query( $params ) : '';
		return $this->api_url . '/api/v1/media/' . $uuid . '/transform' . $query;
	}

	/**
	 * Get a DynamicDelivery transform URL using a named Image Style preset.
	 *
	 * @param string $uuid Asset UUID.
	 * @param int|string $image_style_id Image Style ID.
	 * @return string The API transform URL.
	 */
	public function get_transform_url_by_style( string $uuid, $image_style_id ): string {
		return $this->api_url . '/api/v1/media/' . $uuid . '/transform/' . $image_style_id;
	}

	/**
	 * Builds a raw multipart/form-data body for a single file field.
	 *
	 * wp_remote_post() passes a string 'body' through as-is (only arrays get
	 * http_build_query()'d), so this is the standard way to POST a real file
	 * upload through the WP HTTP API without a dedicated multipart helper.
	 */
	protected function build_multipart_body( string $boundary, string $file_path, string $filename, string $mime_type ): string {
		$eol  = "\r\n";
		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
		$body .= 'Content-Type: ' . $mime_type . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol;
		$body .= '--' . $boundary . '--' . $eol;
		return $body;
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
