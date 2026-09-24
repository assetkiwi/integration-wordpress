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

		// OAuth2 settings.
		register_setting( 'assetkiwi_settings', 'assetkiwi_oauth_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'assetkiwi_settings', 'assetkiwi_oauth_client_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'assetkiwi_settings', 'assetkiwi_oauth_client_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'assetkiwi_settings', 'assetkiwi_oauth_scopes', array( 'sanitize_callback' => array( $this, 'sanitize_oauth_scopes' ) ) );

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

		// OAuth section.
		add_settings_section( 'assetkiwi_oauth', __( 'OAuth2 Authentication', 'assetkiwi-connect' ), array( $this, 'section_oauth' ), 'assetkiwi-connect' );

		add_settings_field(
			'assetkiwi_oauth_mode',
			__( 'Authentication Mode', 'assetkiwi-connect' ),
			array( $this, 'field_oauth_mode' ),
			'assetkiwi-connect',
			'assetkiwi_oauth'
		);
		add_settings_field(
			'assetkiwi_oauth_client_id',
			__( 'Client ID', 'assetkiwi-connect' ),
			array( $this, 'field_oauth_client_id' ),
			'assetkiwi-connect',
			'assetkiwi_oauth'
		);
		add_settings_field(
			'assetkiwi_oauth_client_secret',
			__( 'Client Secret', 'assetkiwi-connect' ),
			array( $this, 'field_oauth_client_secret' ),
			'assetkiwi-connect',
			'assetkiwi_oauth'
		);
		add_settings_field(
			'assetkiwi_oauth_scopes',
			__( 'Scopes', 'assetkiwi-connect' ),
			array( $this, 'field_oauth_scopes' ),
			'assetkiwi-connect',
			'assetkiwi_oauth'
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

	public function section_oauth(): void {
		echo '<p class="description">' . esc_html__( 'Optional: configure OAuth2 so each WordPress user gets their own DAM identity. When enabled, users authenticate individually — the API Token above is used only for anonymous/system requests, not as a fallback for a user who hasn\'t connected yet. The authorization and token endpoints are derived automatically from the API URL above.', 'assetkiwi-connect' ) . '</p>';
	}

	public function field_oauth_mode(): void {
		$value = get_option( 'assetkiwi_oauth_mode', 'shared_token' );
		?>
		<fieldset>
			<label>
				<input type="radio" name="assetkiwi_oauth_mode" value="shared_token" <?php checked( $value, 'shared_token' ); ?> />
				<?php esc_html_e( 'Shared Token', 'assetkiwi-connect' ); ?>
				<span class="description"><?php esc_html_e( 'All users share the API Token configured above.', 'assetkiwi-connect' ); ?></span>
			</label>
			<br />
			<label>
				<input type="radio" name="assetkiwi_oauth_mode" value="per_user" <?php checked( $value, 'per_user' ); ?> />
				<?php esc_html_e( 'Per-User OAuth', 'assetkiwi-connect' ); ?>
				<span class="description"><?php esc_html_e( 'Each WordPress user authenticates individually via OAuth2.', 'assetkiwi-connect' ); ?></span>
			</label>
		</fieldset>
		<?php
	}

	public function field_oauth_client_id(): void {
		$value = esc_attr( get_option( 'assetkiwi_oauth_client_id', '' ) );
		echo '<input type="text" id="assetkiwi_oauth_client_id" name="assetkiwi_oauth_client_id" value="' . $value . '" class="regular-text assetkiwi-oauth-field" />';
	}

	public function field_oauth_client_secret(): void {
		$value = esc_attr( get_option( 'assetkiwi_oauth_client_secret', '' ) );
		echo '<input type="password" id="assetkiwi_oauth_client_secret" name="assetkiwi_oauth_client_secret" value="' . $value . '" class="regular-text assetkiwi-oauth-field" autocomplete="off" />';
	}

	public function field_oauth_scopes(): void {
		$current = get_option( 'assetkiwi_oauth_scopes', 'assets:read' );
		$current = is_string( $current ) ? $current : 'assets:read';
		$scopes  = array(
			'assets:read'      => __( 'Read assets', 'assetkiwi-connect' ),
			'assets:write'     => __( 'Write assets', 'assetkiwi-connect' ),
			'collections:read' => __( 'Read collections', 'assetkiwi-connect' ),
			'tags:read'        => __( 'Read tags', 'assetkiwi-connect' ),
		);
		$current_scopes = array_flip( explode( ' ', $current ) );

		echo '<fieldset class="assetkiwi-oauth-field">';
		foreach ( $scopes as $scope => $label ) {
			$id = 'assetkiwi_oauth_scope_' . str_replace( ':', '_', $scope );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" id="%s" name="%s" value="%s" %s /> %s</label>',
				esc_attr( $id ),
				'assetkiwi_oauth_scopes',
				esc_attr( $scope ),
				checked( isset( $current_scopes[ $scope ] ), true, false ),
				esc_html( $label )
			);
		}
		// Hidden input so the form always submits something (checkboxes are absent when unchecked).
		echo '<input type="hidden" name="assetkiwi_oauth_scopes_submitted" value="1" />';
		echo '<p class="description">' . esc_html__( 'Scopes requested during OAuth authorization. At minimum, assets:read is recommended.', 'assetkiwi-connect' ) . '</p>';
		echo '</fieldset>';
	}

	public function sanitize_oauth_scopes( $value ): string {
		// When all checkboxes are unchecked, the field is absent from $_POST.
		// The hidden input 'assetkiwi_oauth_scopes_submitted' tells us the form was submitted.
		if ( isset( $_POST['assetkiwi_oauth_scopes_submitted'] ) ) {
			if ( is_array( $value ) ) {
				return implode( ' ', array_map( 'sanitize_text_field', $value ) );
			}
			return '';
		}
		// Form not submitted (e.g. programmatic save) — preserve existing value.
		return get_option( 'assetkiwi_oauth_scopes', 'assets:read' );
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
			(function () {
				var modeRadios = document.querySelectorAll('input[name="assetkiwi_oauth_mode"]');
				var oauthFields = document.querySelectorAll('.assetkiwi-oauth-field');

				function toggleOAuthFields() {
					var isPerUser = false;
					modeRadios.forEach(function (r) {
						if (r.checked && r.value === 'per_user') {
							isPerUser = true;
						}
					});
					oauthFields.forEach(function (el) {
						// Walk up to the nearest table row (tr).
						var row = el;
						while (row && row.tagName !== 'TR') { row = row.parentNode; }
						if (row) { row.style.display = isPerUser ? '' : 'none'; }
					});
				}

				modeRadios.forEach(function (r) {
					r.addEventListener('change', toggleOAuthFields);
				});
				toggleOAuthFields();

				// Test connection button.
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
			})();
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
