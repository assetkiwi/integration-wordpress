<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetKiwi_OAuth {

	/**
	 * Check if per-user OAuth mode is enabled.
	 */
	public static function is_enabled(): bool {
		return get_option( 'assetkiwi_oauth_mode', 'shared_token' ) === 'per_user';
	}

	/**
	 * Build the authorization URL with PKCE challenge.
	 */
	public static function get_authorization_url(): string {
		$code_verifier  = bin2hex( random_bytes( 32 ) );
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		$state          = wp_create_nonce( 'assetkiwi_oauth' );

		// Store in transient (10-minute lifetime).
		set_transient( 'assetkiwi_oauth_code_verifier_' . get_current_user_id(), $code_verifier, 600 );
		set_transient( 'assetkiwi_oauth_state_' . get_current_user_id(), $state, 600 );

		$params = array(
			'client_id'             => get_option( 'assetkiwi_oauth_client_id' ),
			'redirect_uri'          => self::get_redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => get_option( 'assetkiwi_oauth_scopes', 'assets:read' ),
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		);

		return self::get_authorize_url() . '?' . http_build_query( $params );
	}

	/**
	 * Exchange authorization code for access token.
	 *
	 * @return array|null Token data array, or null on failure.
	 */
	public static function exchange_code( string $code, string $code_verifier ): ?array {
		$response = wp_remote_post(
			self::get_token_url(),
			array(
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => get_option( 'assetkiwi_oauth_client_id' ),
					'client_secret' => get_option( 'assetkiwi_oauth_client_secret' ),
					'code_verifier' => $code_verifier,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Store the access token for the current user.
	 */
	public static function store_token( int $user_id, array $token_data ): void {
		update_user_meta( $user_id, 'assetkiwi_oauth_access_token', $token_data['access_token'] );
		update_user_meta( $user_id, 'assetkiwi_oauth_token_expires', time() + (int) ( $token_data['expires_in'] ?? 3600 ) );
		update_user_meta( $user_id, 'assetkiwi_oauth_scope', $token_data['scope'] ?? '' );
	}

	/**
	 * Get the stored access token for a user.
	 *
	 * Returns null if no token exists or if the token has expired.
	 */
	public static function get_token( int $user_id ): ?string {
		$token   = get_user_meta( $user_id, 'assetkiwi_oauth_access_token', true );
		$expires = get_user_meta( $user_id, 'assetkiwi_oauth_token_expires', true );

		if ( ! $token || ( $expires && (int) $expires < time() ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Get the OAuth callback URL.
	 */
	public static function get_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=assetkiwi_oauth_callback' );
	}

	/**
	 * The asset.kiwi OAuth2 authorization endpoint, derived from the API URL.
	 *
	 * asset.kiwi's OAuth routes are fixed (/oauth/authorize, /oauth/token), so
	 * there's no need to make the admin type them in separately — they'd just
	 * be another way to get the DAM URL wrong.
	 */
	public static function get_authorize_url(): string {
		return rtrim( (string) get_option( 'assetkiwi_api_url', '' ), '/' ) . '/oauth/authorize';
	}

	/**
	 * The asset.kiwi OAuth2 token endpoint, derived from the API URL.
	 */
	public static function get_token_url(): string {
		return rtrim( (string) get_option( 'assetkiwi_api_url', '' ), '/' ) . '/oauth/token';
	}
}
