<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetKiwi_Webhook {

	/**
	 * How far out of date a delivery may be before it is refused, in seconds.
	 */
	private const MAX_AGE_SECONDS = 300;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			'assetkiwi/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true', // Auth handled manually via HMAC.
			)
		);
	}

	public function receive( WP_REST_Request $request ): WP_REST_Response {
		$secret = get_option( 'assetkiwi_webhook_secret', '' );

		if ( empty( $secret ) ) {
			error_log( 'asset.kiwi: Webhook secret not configured.' );
			return new WP_REST_Response( array( 'error' => 'Webhook secret not configured.' ), 401 );
		}

		// The sender (Modules\Webhooks\Services\WebhookDispatcher::send()) signs
		// the TIMESTAMP AND BODY together and ships the timestamp alongside:
		//
		//   X-Webhook-Timestamp: <unix>
		//   X-Webhook-Signature: hash_hmac('sha256', "{$ts}.{$body}", $secret)
		//
		// Binding the timestamp into the signed message is what makes replay
		// detectable — a bare-body signature stays valid forever, so a captured
		// delivery could be resent indefinitely. Deliveries outside the window
		// are refused even when the HMAC itself is intact.
		$timestamp = (string) $request->get_header( 'x_webhook_timestamp' );

		if ( '' === $timestamp || ! ctype_digit( $timestamp ) ) {
			error_log( 'asset.kiwi: webhook rejected — missing or malformed timestamp.' );
			return new WP_REST_Response( array( 'error' => 'Missing or malformed timestamp' ), 401 );
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_AGE_SECONDS ) {
			error_log( 'asset.kiwi: webhook rejected — timestamp outside the accepted window.' );
			return new WP_REST_Response( array( 'error' => 'Timestamp outside the accepted window' ), 401 );
		}

		$signature = $request->get_header( 'x_webhook_signature' );
		$body      = $request->get_body();
		$expected  = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			error_log( 'asset.kiwi: webhook signature verification failed.' );
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
		}

		$payload = $request->get_json_params();

		if ( empty( $payload ) || empty( $payload['event'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
		}

		$event = $payload['event'];
		$data  = $payload['data'] ?? array();

		switch ( $event ) {
			case 'asset.updated':
				$this->handle_asset_updated( $data );
				break;

			case 'asset.deleted':
				$this->handle_asset_deleted( $data );
				break;

			case 'webhook.test':
				break;

			default:
				error_log( 'asset.kiwi: unhandled webhook event "' . $event . '".' );
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	// -------------------------------------------------------------------------
	// Event handlers
	// -------------------------------------------------------------------------

	protected function handle_asset_updated( array $data ): void {
		$uuid = $data['uuid'] ?? $data['id'] ?? null;
		if ( ! $uuid ) {
			return;
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => '_assetkiwi_uuid',
				'meta_value'     => $uuid,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $attachments ) ) {
			return;
		}

		foreach ( $attachments as $attachment_id ) {
			if ( isset( $data['alt_text'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $data['alt_text'] ) );
			}

			if ( isset( $data['original_name'] ) || isset( $data['description'] ) ) {
				$update = array( 'ID' => $attachment_id );

				if ( isset( $data['original_name'] ) ) {
					$update['post_title'] = sanitize_text_field( $data['original_name'] );
				}
				if ( isset( $data['description'] ) ) {
					$update['post_content'] = sanitize_textarea_field( $data['description'] );
				}

				wp_update_post( $update );
			}

			if ( isset( $data['variants'] ) ) {
				update_post_meta( $attachment_id, '_assetkiwi_variants', $data['variants'] );
			}
		}

		error_log( sprintf( 'asset.kiwi: synced %d attachment(s) for asset %s.', count( $attachments ), $uuid ) );
	}

	protected function handle_asset_deleted( array $data ): void {
		$uuid = $data['uuid'] ?? $data['id'] ?? null;
		if ( ! $uuid ) {
			return;
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => '_assetkiwi_uuid',
				'meta_value'     => $uuid,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $attachments ) ) {
			return;
		}

		foreach ( $attachments as $attachment_id ) {
			// Trash rather than hard-delete to preserve any existing content references.
			wp_trash_post( $attachment_id );
		}

		error_log( sprintf( 'asset.kiwi: trashed %d attachment(s) for deleted asset %s.', count( $attachments ), $uuid ) );
	}
}
