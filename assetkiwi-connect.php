<?php
/**
 * Plugin Name:  AssetKiwi Connect
 * Description:  Connects WordPress to the asset.kiwi digital asset management platform.
 * Version:      1.0.0
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

define( 'ASSETKIWI_VERSION', '1.0.0' );
define( 'ASSETKIWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASSETKIWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASSETKIWI_PLUGIN_FILE', __FILE__ );

require_once ASSETKIWI_PLUGIN_DIR . 'includes/class-assetkiwi-client.php';
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
