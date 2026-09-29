# UTM.coupons for WP

Connect your WordPress store to [UTM.coupons](https://utm.coupons/). Coupons sync
automatically and every discounted order is attributed back to the click that
earned it — no manual exports, no code.

- **Plugin page:** https://utm.coupons/wp-plugin/
- **Integration guide:** https://utm.coupons/docs/wp-integrations/
- **Dashboard:** https://app.utm.coupons
- **Version:** 0.1.0 · **Requires:** WordPress 6.0+, PHP 7.4+
- **License:** GPL-2.0-or-later

## What it does

- **Auto-sync coupons** — create or edit a coupon in WooCommerce or Easy Digital
  Downloads and it appears in UTM.coupons immediately (Store → Platform).
- **Attribution for every order** — when a customer checks out with a coupon, the
  plugin reports the order (id, amount, currency, code) together with the captured
  click id, so UTM.coupons can measure clicks → conversions → revenue.
- **Refund-safe** — refunds and cancellations are reported too, so revenue numbers
  stay honest instead of drifting upward.
- **Four platforms, auto-detected** — WooCommerce, Easy Digital Downloads,
  SureCart and FluentCart.
- **Public landing pages (opt-in)** — give any coupon a clean `/c/` landing page
  and a short link.
- **Coupons list, event logs and a dashboard widget** — see what was synced and
  what was reported, right inside wp-admin.

## Installation

1. Upload this folder to `/wp-content/plugins/utm-coupons-for-wp/` (or install via
   the Plugins screen), then activate **UTM.coupons for WP**.
2. Go to **UTM.coupons → Connection** and paste your UTM.coupons API key.
3. That's it. New coupons sync and orders are attributed automatically.

## How reporting works

Orders and refunds are signed and POSTed to the conversion endpoint:

```
POST https://hooks.utm.coupons/v1/conversions
x-utm-signature: <HMAC-SHA256 of the request body>
```

The signature is computed with your HMAC secret, so a forged report is rejected.
Failed deliveries are queued and retried — a temporary network problem never
silently drops an order.

SureCart and FluentCart have no order hooks of their own, so they report through
the plugin's REST bridge instead:

```
POST /wp-json/utm-coupons/v1/bridge
x-utm-site-token: <site token>
```

## Privacy

No customer PII leaves your site. Only the order id, amount, currency, coupon code
and the anonymous click id used for attribution are reported.

## Requirements

- WordPress 6.0 or later, PHP 7.4 or later.
- Easy Digital Downloads 3.x stores data in custom tables and therefore needs
  **MySQL**. The plugin detects SQLite and shows a notice instead of failing
  silently.

## Development

The plugin is plain PHP with no build step:

```
utm-coupons-for-wp.php        bootstrap, constants
includes/class-settings.php   connection + settings screens
includes/class-platforms.php  WooCommerce / EDD / SureCart / FluentCart hooks
includes/class-reporter.php   signed reporting + retry queue
includes/class-click-capture.php  click id capture
includes/class-logger.php     event log
includes/class-admin.php      admin menus, coupons list, dashboard widget
readme.txt                    WordPress.org readme
```

## Changelog

### 0.1.0

Initial release: connection wizard, click capture, signed conversion reporting,
retry queue, platform detection, coupons list, logs and dashboard widget.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
