<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetKiwi_Settings {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_assetkiwi_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	public function add_menu_page(): void {
		add_options_page(
			__( 'asset.kiwi', 'assetkiwi-connect' ),
			__( 'asset.kiwi', 'assetkiwi-connect' ),
			'manage_options',
			'assetkiwi-connect',
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting( 'assetkiwi_settings', 'assetkiwi_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'assetkiwi_settings', 'assetkiwi_api_token', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'assetkiwi_settings', 'assetkiwi_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		add_settings_section( 'assetkiwi_main', __( 'API Connection', 'assetkiwi-connect' ), '__return_false', 'assetkiwi-connect' );

		add_settings_field(
			'assetkiwi_api_url',
			__( 'API URL', 'assetkiwi-connect' ),
			array( $this, 'field_api_url' ),
			'assetkiwi-connect',
			'assetkiwi_main'
		);
		add_settings_field(
			'assetkiwi_api_token',
			__( 'API Token', 'assetkiwi-connect' ),
			array( $this, 'field_api_token' ),
			'assetkiwi-connect',
			'assetkiwi_main'
		);
		add_settings_field(
			'assetkiwi_webhook_secret',
			__( 'Webhook Secret', 'assetkiwi-connect' ),
			array( $this, 'field_webhook_secret' ),
			'assetkiwi-connect',
			'assetkiwi_main'
		);
	}

	public function field_api_url(): void {
		$value = esc_attr( get_option( 'assetkiwi_api_url', '' ) );
		echo '<input type="url" id="assetkiwi_api_url" name="assetkiwi_api_url" value="' . $value . '" class="regular-text" placeholder="https://dam.example.com" />';
		echo '<p class="description">' . esc_html__( 'Base URL of your asset.kiwi instance, without a trailing slash.', 'assetkiwi-connect' ) . '</p>';
	}

	public function field_api_token(): void {
		$value = esc_attr( get_option( 'assetkiwi_api_token', '' ) );
		echo '<input type="password" id="assetkiwi_api_token" name="assetkiwi_api_token" value="' . $value . '" class="regular-text" autocomplete="off" />';
	}

	public function field_webhook_secret(): void {
		$value = esc_attr( get_option( 'assetkiwi_webhook_secret', '' ) );
		echo '<input type="password" id="assetkiwi_webhook_secret" name="assetkiwi_webhook_secret" value="' . $value . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . sprintf(
			/* translators: %s webhook URL */
			esc_html__( 'Used to verify incoming webhooks. Configure this secret in asset.kiwi and point it at: %s', 'assetkiwi-connect' ),
			'<code>' . esc_html( rest_url( 'assetkiwi/v1/webhook' ) ) . '</code>'
		) . '</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'assetkiwi_settings' );
				do_settings_sections( 'assetkiwi-connect' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Test Connection', 'assetkiwi-connect' ); ?></h2>
			<p><?php esc_html_e( 'Test the saved credentials without making changes.', 'assetkiwi-connect' ); ?></p>
			<button type="button" id="assetkiwi-test-connection" class="button button-secondary">
				<?php esc_html_e( 'Test Connection', 'assetkiwi-connect' ); ?>
			</button>
			<span id="assetkiwi-test-result" style="margin-left:12px;"></span>

			<script>
			document.getElementById('assetkiwi-test-connection').addEventListener('click', function () {
				var btn    = this;
				var result = document.getElementById('assetkiwi-test-result');
				btn.disabled = true;
				result.textContent = '<?php echo esc_js( __( 'Testing…', 'assetkiwi-connect' ) ); ?>';

				var data = new FormData();
				data.append('action', 'assetkiwi_test_connection');
				data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'assetkiwi_test_connection' ) ); ?>');

				fetch(ajaxurl, { method: 'POST', body: data })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						result.textContent = json.data;
						result.style.color = json.success ? 'green' : 'red';
					})
					.catch(function () {
						result.textContent = '<?php echo esc_js( __( 'Request failed.', 'assetkiwi-connect' ) ); ?>';
						result.style.color = 'red';
					})
					.finally(function () { btn.disabled = false; });
			});
			</script>
		</div>
		<?php
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'assetkiwi_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'assetkiwi-connect' ) );
		}

		$client   = assetkiwi_client();
		$response = $client->get_assets( array( 'per_page' => 1 ) );

		if ( empty( $response ) ) {
			wp_send_json_error( __( 'Connection failed. Check the API URL and token.', 'assetkiwi-connect' ) );
		}

		wp_send_json_success( __( 'Connection successful.', 'assetkiwi-connect' ) );
	}
}
