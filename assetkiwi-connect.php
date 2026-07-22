<?php
/**
 * Plugin Name:  AssetKiwi Connect
 * Description:  Connects WordPress to the asset.kiwi digital asset management platform.
 * Version:      1.0.1
 * Author:       asset.kiwi
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  assetkiwi-connect
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASSETKIWI_VERSION', '1.0.1' );
define( 'ASSETKIWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASSETKIWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASSETKIWI_PLUGIN_FILE', __FILE__ );

require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-client.php';
require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-oauth.php';
require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-settings.php';
require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-media.php';
require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-webhook.php';
require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-usage.php';
require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-block.php';

function assetkiwi_init() {
	( new AssetKiwi_Settings() )->register();
	( new AssetKiwi_Media() )->register();
	( new AssetKiwi_Webhook() )->register();
	( new AssetKiwi_Usage() )->register();
	( new AssetKiwi_Block() )->register();
}
add_action( 'init', 'assetkiwi_init' );

/**
 * Returns a shared, lazily-instantiated API client.
 */
function assetkiwi_client(): AssetKiwi_Client {
	static $client = null;
	if ( null === $client ) {
		$client = new AssetKiwi_Client(
			get_option( 'assetkiwi_api_url', '' ),
			get_option( 'assetkiwi_api_token', '' )
		);
	}
	return $client;
}

// ---------------------------------------------------------------------------
// OAuth2 callback handler
// ---------------------------------------------------------------------------

/**
 * Handle the OAuth authorization callback from the DAM.
 */
add_action( 'admin_post_assetkiwi_oauth_callback', 'assetkiwi_handle_oauth_callback' );
add_action( 'admin_post_nopriv_assetkiwi_oauth_callback', 'assetkiwi_handle_oauth_callback' );

function assetkiwi_handle_oauth_callback(): void {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_die( esc_html__( 'You must be logged in to connect your asset.kiwi account.', 'assetkiwi-connect' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
	$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	// phpcs:enable

	$stored_state    = get_transient( 'assetkiwi_oauth_state_' . $user_id );
	$code_verifier   = get_transient( 'assetkiwi_oauth_code_verifier_' . $user_id );

	delete_transient( 'assetkiwi_oauth_state_' . $user_id );
	delete_transient( 'assetkiwi_oauth_code_verifier_' . $user_id );

	if ( ! $stored_state || ! hash_equals( $stored_state, $state ) ) {
		wp_die( esc_html__( 'Invalid OAuth state. Please try again.', 'assetkiwi-connect' ) );
	}

	$token_data = AssetKiwi_OAuth::exchange_code( $code, $code_verifier );

	if ( ! $token_data ) {
		wp_die( esc_html__( 'Failed to connect to asset.kiwi. Please try again.', 'assetkiwi-connect' ) );
	}

	AssetKiwi_OAuth::store_token( $user_id, $token_data );

	wp_safe_redirect( admin_url( 'upload.php' ) );
	exit;
}
