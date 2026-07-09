# AssetKiwi Connect — WordPress Plugin

Connects WordPress to the [asset.kiwi](https://assetkiwi.com) digital asset management platform.

## Features

- **Settings page** — Configure your API URL, API token, and webhook secret under *Settings › asset.kiwi*.
- **Media Library integration** — Browse and insert DAM assets directly from the WordPress media modal via a dedicated *asset.kiwi* tab.
- **Custom attachment metadata** — DAM UUID and variant info are stored as post meta on every attachment created from a DAM asset.
- **Image sizes** — asset.kiwi variants are mapped to WordPress image sizes via attachment metadata.
- **Webhook receiver** — REST endpoint at `/wp-json/assetkiwi/v1/webhook` handles `asset.updated` and `asset.deleted` events. Requests are verified with HMAC SHA-256.
- **Usage tracking** — Reports asset usage to asset.kiwi on `save_post` and removes it on post delete/trash.
- **Gutenberg block** — *AssetKiwi Asset* block lets editors pick an asset and choose a variant, rendered as a semantic `<figure><img></figure>`.

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

## Development

Install dev dependencies (PHPUnit + WP_Mock) with Composer:

```bash
composer install
composer test
```

No build step is required for the JavaScript — all JS is vanilla ES5 that runs directly in the browser.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
