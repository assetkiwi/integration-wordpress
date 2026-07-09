<?php
/**
 * Tests for AssetKiwi_Client.
 *
 * Run with: vendor/bin/phpunit tests/test-client.php
 *
 * These tests use WP_Mock to stub WordPress HTTP functions and avoid
 * any real network calls.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_Mock\Tools\TestCase as WPTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-assetkiwi-client.php';

/**
 * @covers AssetKiwi_Client
 */
class AssetKiwi_ClientTest extends WPTestCase {

	protected AssetKiwi_Client $client;

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->client = new AssetKiwi_Client( 'https://dam.example.com', 'test-token' );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get_assets
	// -------------------------------------------------------------------------

	public function test_get_assets_returns_data_array(): void {
		$expected = array(
			'data' => array(
				array( 'uuid' => 'abc-123', 'original_name' => 'photo.jpg' ),
			),
			'meta' => array( 'total' => 1, 'per_page' => 24, 'last_page' => 1 ),
		);

		WP_Mock::userFunction( 'add_query_arg' )
			->once()
			->andReturnUsing( function ( $params, $url ) {
				return $url . '?' . http_build_query( $params );
			} );

		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $expected ) ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( $expected ) );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

		$result = $this->client->get_assets();

		$this->assertSame( $expected, $result );
	}

	public function test_get_assets_returns_empty_on_wp_error(): void {
		$error = new WP_Error( 'http_request_failed', 'Connection refused.' );

		WP_Mock::userFunction( 'add_query_arg' )->andReturnArg( 1 );
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( $error );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );
		WP_Mock::userFunction( 'error_log' )->andReturn( null );

		$result = $this->client->get_assets();

		$this->assertSame( array(), $result );
	}

	// -------------------------------------------------------------------------
	// get_asset
	// -------------------------------------------------------------------------

	public function test_get_asset_returns_single_asset(): void {
		$expected = array( 'uuid' => 'abc-123', 'original_name' => 'photo.jpg' );

		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( $expected ) );

		$result = $this->client->get_asset( 'abc-123' );

		$this->assertSame( $expected, $result );
	}

	// -------------------------------------------------------------------------
	// normalize_asset
	// -------------------------------------------------------------------------

	public function test_normalize_asset_unwraps_data_key(): void {
		$inner = array( 'uuid' => 'abc', 'original_name' => 'test.jpg' );
		$raw   = array( 'data' => $inner );

		$this->assertSame( $inner, $this->client->normalize_asset( $raw ) );
	}

	public function test_normalize_asset_passes_through_flat_payload(): void {
		$asset = array( 'uuid' => 'abc', 'original_name' => 'test.jpg' );

		$this->assertSame( $asset, $this->client->normalize_asset( $asset ) );
	}

	// -------------------------------------------------------------------------
	// resolve_variant
	// -------------------------------------------------------------------------

	public function test_resolve_variant_returns_matching_variant(): void {
		$asset = array(
			'uuid'     => 'abc',
			'variants' => array(
				array( 'variant_name' => 'thumb', 'url' => 'https://cdn.example.com/thumb.jpg', 'width' => 150, 'height' => 150 ),
				array( 'variant_name' => 'large', 'url' => 'https://cdn.example.com/large.jpg', 'width' => 1200, 'height' => 900 ),
			),
		);

		$variant = $this->client->resolve_variant( $asset, 'thumb' );

		$this->assertNotNull( $variant );
		$this->assertSame( 'https://cdn.example.com/thumb.jpg', $variant['url'] );
	}

	public function test_resolve_variant_returns_null_when_not_found(): void {
		$asset = array(
			'uuid'     => 'abc',
			'variants' => array(
				array( 'variant_name' => 'thumb', 'url' => 'https://cdn.example.com/thumb.jpg' ),
			),
		);

		$this->assertNull( $this->client->resolve_variant( $asset, 'does-not-exist' ) );
	}

	// -------------------------------------------------------------------------
	// normalize_pager
	// -------------------------------------------------------------------------

	public function test_normalize_pager_uses_meta(): void {
		$response = array(
			'data' => array(),
			'meta' => array( 'total' => 100, 'per_page' => 20, 'last_page' => 5 ),
		);

		$pager = $this->client->normalize_pager( $response, 2 );

		$this->assertSame( 2, $pager['current_page'] );
		$this->assertSame( 100, $pager['total'] );
		$this->assertSame( 5, $pager['last_page'] );
	}

	public function test_normalize_pager_falls_back_to_item_count(): void {
		$response = array(
			array( 'uuid' => '1' ),
			array( 'uuid' => '2' ),
		);

		$pager = $this->client->normalize_pager( $response, 1 );

		$this->assertSame( 2, $pager['total'] );
		$this->assertSame( 1, $pager['last_page'] );
	}

	// -------------------------------------------------------------------------
	// get_asset_display_name
	// -------------------------------------------------------------------------

	public function test_get_asset_display_name_prefers_original_name(): void {
		$asset = array( 'original_name' => 'My Photo', 'filename' => 'my-photo.jpg' );
		$this->assertSame( 'My Photo', $this->client->get_asset_display_name( $asset ) );
	}

	public function test_get_asset_display_name_falls_back_to_filename(): void {
		$asset = array( 'filename' => 'my-photo.jpg' );
		$this->assertSame( 'my-photo.jpg', $this->client->get_asset_display_name( $asset ) );
	}

	public function test_get_asset_display_name_final_fallback(): void {
		$this->assertSame( 'Untitled', $this->client->get_asset_display_name( array() ) );
	}

	// -------------------------------------------------------------------------
	// set_credentials
	// -------------------------------------------------------------------------

	public function test_set_credentials_updates_api_url(): void {
		$this->client->set_credentials( 'https://new.dam.example.com/', 'new-token' );

		// Verify by making a call and checking the URL passed to wp_remote_get.
		WP_Mock::userFunction( 'add_query_arg' )
			->once()
			->andReturnUsing( function ( $params, $url ) {
				$this->assertStringContainsString( 'new.dam.example.com', $url );
				return $url;
			} );

		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( array() );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '[]' );

		$this->client->get_assets();
	}

	// -------------------------------------------------------------------------
	// report_usage / remove_usage (smoke tests — no real HTTP)
	// -------------------------------------------------------------------------

	public function test_report_usage_calls_wp_remote_post(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.example.com' );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		WP_Mock::userFunction( 'wp_remote_post' )->once()->andReturn( array() );

		$this->client->report_usage( 'abc-123', 'post', 42, 'https://site.example.com/hello' );
		$this->addToAssertionCount( 1 );
	}

	public function test_remove_usage_calls_wp_remote_request(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.example.com' );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		WP_Mock::userFunction( 'wp_remote_request' )->once()->andReturn( array() );

		$this->client->remove_usage( 'abc-123', 'post', 42 );
		$this->addToAssertionCount( 1 );
	}
}

/**
 * Minimal WP_Error stub so the client tests can run without a full WP install.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
