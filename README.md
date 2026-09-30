# UTM.coupons for WP

Connect your WordPress store to [UTM.coupons](https://utm.coupons/). Coupons sync
automatically and every discounted order is attributed back to the click that
earned it — no manual exports, no code.

- **Plugin page:** https://utm.coupons/wp-plugin/
- **Integration guide:** https://utm.coupons/docs/wp-integrations/
- **Dashboard:** https://app.utm.coupons
- **Version:** 1.03 · **Requires:** WordPress 6.0+, PHP 7.4+
- **License:** GPL-2.0-or-later

## What it does

- **One-click connect** — approve the connection in your UTM.coupons dashboard
  and the plugin provisions its API key, webhook endpoint and per-workspace
  signing secret automatically, with no copy/paste across browsers.
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
- **Coupons list with attribution** — every coupon on your workspace with the
  revenue and orders it earned, plus an attribution summary (revenue, clicks,
  conversion rate, attribution coverage) over the last 7, 30 or 90 days.
- **Event logs and a dashboard widget** — see what was reported, right inside
  wp-admin.

## Installation

1. Upload this folder to `/wp-content/plugins/utm-coupons-for-wp/` (or install via
   the Plugins screen), then activate **UTM.coupons for WP**.
2. Go to **UTM.coupons → Connection** and click **Connect with UTM.coupons**. You
   will be asked to approve the connection in your dashboard — an API key, a
   webhook endpoint and the per-workspace conversion signing secret are created
   automatically. No copy/paste across browsers.
3. That's it. New coupons sync and orders are attributed automatically.

## Showing a coupon on your store

Syncing keeps UTM.coupons up to date; the widget is what shows the coupon to
shoppers. Two ways to place one:

**Shortcode** — put it in a post, page or widget area:

```
[utm_coupon code="SUMMER20"]
[utm_coupon code="SUMMER20" variant="button" button-style="pill" button-text="Get 25% off"]
[utm_coupon code="SUMMER20" variant="bar" discount="25" unit="%" theme="dark"]
```

**Copy embed** — the Coupons screen has a *Copy embed* button on every row that
puts the runnable snippet on your clipboard:

```html
<script async src="https://utm.coupons/embed/v1.js"></script>
<utm-coupon code="SUMMER20"></utm-coupon>
```

The script tag is not optional — a bare `<utm-coupon>` element renders nothing.

### Attributes

| Attribute | Values | Notes |
| --- | --- | --- |
| `code` | your coupon code | Required. Without it nothing is rendered. |
| `variant` | `ticket` (default) · `button` · `mini` · `bar` · `badge` | Anything unrecognised falls back to `ticket`. |
| `button-style` | `solid` (default) · `outline` · `pill` · `reveal` · `stacked` | Button variant only. |
| `button-text` | any short label | Button variant only, and only for `solid` / `pill` — the other styles have no text slot. |
| `theme` | `light` (default) · `dark` · `brand` · `minimal` | |
| `description` · `discount` · `unit` · `expires` | | Optional detail shown by the roomier variants. |
| `logo` · `brand` · `domain` | | Override the identity shown in the ticket plate. |

Defaults are never written out: the shortcode emits `variant="ticket"` only when
you actually asked for something else, so the snippet stays readable.

The ticket variant ships a share sheet — X, Facebook, Reddit, Bluesky, Email and
a copy action — so a shopper can spread the code without you building anything.

## How reporting works

Orders and refunds are signed and POSTed to the conversion endpoint:

```
POST https://hooks.utm.coupons/v1/conversions
x-utm-signature: <HMAC-SHA256 of the request body>
```

The signature is computed with your workspace's conversion signing secret
(provisioned automatically when you connect), and the payload carries your
workspace id so the platform can verify it against the right secret — a forged
report is rejected. Failed deliveries are queued and retried, so a temporary
network problem never silently drops an order.

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

### 0.1.1

Added the `[utm_coupon]` shortcode and a Copy embed button on the Coupons
screen, so a synced coupon can be displayed on the store without leaving
WordPress.

### 0.1.0

Initial release: connection wizard, click capture, signed conversion reporting,
retry queue, platform detection, coupons list, logs and dashboard widget.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
