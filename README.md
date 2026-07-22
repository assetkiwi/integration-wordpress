# AssetKiwi Connect — WordPress Plugin

Connects WordPress to the [asset.kiwi](https://assetkiwi.com) digital asset management platform.

## Features

- **Settings page** — Configure your API URL, API token, and webhook secret under *Settings › asset.kiwi*.
- **Media Library integration** — Browse and insert DAM assets directly from the WordPress media modal via a dedicated *asset.kiwi* tab.
- **DynamicDelivery** — On-the-fly image transforms via `get_transform_url()` and `get_transform_url_by_style()`. No pre-generated variants needed — request any width, format, fit, or quality at render time.
- **Custom attachment metadata** — DAM UUID and variant info are stored as post meta on every attachment created from a DAM asset.
- **Image sizes** — asset.kiwi variants are mapped to WordPress image sizes via attachment metadata.
- **Webhook receiver** — REST endpoint at `/wp-json/assetkiwi/v1/webhook` handles `asset.updated` and `asset.deleted` events. Requests are verified with HMAC SHA-256.
- **Usage tracking** — Reports asset usage to asset.kiwi on `save_post` and removes it on post delete/trash.
- **Gutenberg block** — *AssetKiwi Asset* block lets editors pick an asset and choose a variant, rendered as a semantic `<figure><img></figure>`.
- **OAuth2 authentication** — Optional per-user OAuth2 mode so each WordPress user gets their own DAM identity. Supports PKCE for secure authorization code flow.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Copy the `assetkiwi-connect` folder into `wp-content/plugins/`.
2. Activate the plugin in *Plugins › Installed Plugins*.
3. Go to *Settings › asset.kiwi* and enter your API URL and token.

## Webhook Setup

1. In asset.kiwi, create a webhook pointing at:
   ```
   https://yoursite.com/wp-json/assetkiwi/v1/webhook
   ```
2. Copy the generated secret into *Settings › asset.kiwi › Webhook Secret*.

The endpoint accepts `asset.updated` and `asset.deleted` events and verifies each request with an HMAC SHA-256 signature sent in the `X-Webhook-Signature` header.

## DynamicDelivery

DynamicDelivery provides on-the-fly image transforms without pre-generating variants. Request any size, format, or quality at render time:

```php
$client = assetkiwi_client();

// Resize to 800px wide, auto-format (returns optimal modern format):
$url = $client->get_transform_url( $uuid, [
    'w'      => 800,
    'format' => 'auto',
] );

// Use a named Image Style preset:
$url = $client->get_transform_url_by_style( $uuid, $image_style_id );

// With more options:
$url = $client->get_transform_url( $uuid, [
    'w'       => 400,
    'h'       => 300,
    'fit'     => 'crop',
    'gravity' => 'fp:0.4:0.6',
    'format'  => 'webp',
    'q'       => 85,
] );

// Render in a template:
printf( '<img src="%s" alt="" />', esc_url( $url ) );
```

The transform URL follows the pattern `{apiUrl}/api/v1/media/{uuid}/transform?w=800&format=webp` and 302-redirects to a signed imgproxy URL.

The `AssetKiwi_Media` class also exposes convenience helpers:

```php
// Get a thumbnail URL for the media browser (300px wide, auto-format):
$thumb_url = $media->resolve_asset_thumbnail_url( $asset );

// Get a display URL at a specific width:
$display_url = $media->resolve_asset_display_url( $asset, 800 );
```

## Development

Install dev dependencies (PHPUnit + WP_Mock) with Composer:

```bash
composer install
composer test
```

No build step is required for the JavaScript — all JS is vanilla ES5 that runs directly in the browser.

## OAuth2 Authentication (Per-User Mode)

By default, all WordPress users share a single API token configured under *Settings › asset.kiwi*. You can switch to per-user OAuth2 mode so each WordPress user authenticates individually with their own DAM identity.

### Enabling Per-User OAuth

1. Go to *Settings › asset.kiwi*.
2. Under **OAuth2 Authentication**, select **Per-User OAuth**.
3. Fill in the OAuth client details:
   - **Client ID** and **Client Secret** — obtained from your asset.kiwi OAuth client registration.
   - **Authorize URL** — e.g. `https://dam.example.com/oauth/authorize`.
   - **Token URL** — e.g. `https://dam.example.com/oauth/token`.
   - **Scopes** — check the scopes your client needs (minimum `assets:read`).
4. Save changes.

### Creating an OAuth Client in asset.kiwi

1. In your asset.kiwi instance, navigate to OAuth Clients (admin area).
2. Create a new client with:
   - **Grant type**: Authorization Code with PKCE.
   - **Redirect URI**: `https://yoursite.com/wp-admin/admin-post.php?action=assetkiwi_oauth_callback`
   - **Scopes**: match what you selected in the plugin settings.
3. Copy the generated Client ID and Client Secret into the plugin settings.

### User Flow

1. When per-user OAuth is enabled, each user sees a **Connect to asset.kiwi** notice in the media modal.
2. Clicking the link redirects them to the DAM authorization screen.
3. After approving, they are redirected back to WordPress with an access token stored against their user account.
4. All subsequent DAM API requests use that user's personal OAuth token.
5. If a user's token expires or they haven't connected, the plugin falls back to the shared API token (if configured).

### Token Storage

OAuth access tokens are stored as WordPress user meta (`assetkiwi_oauth_access_token`, `assetkiwi_oauth_token_expires`). PKCE code verifiers and state nonces are stored as WordPress transients with a 10-minute lifetime. No tokens are stored in browser sessions or cookies.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
