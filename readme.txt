=== UTM.coupons for WP ===
Contributors: utmcoupons
Tags: coupons, woocommerce, easy digital downloads, attribution, coupon tracking
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.01
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce, Easy Digital Downloads, SureCart or FluentCart to UTM.coupons. Coupons sync automatically and every order is attributed with zero setup.

== Description ==

**UTM.coupons for WP** is the official bridge between your WordPress store and [UTM.coupons](https://utm.coupons/). It keeps your coupon catalogue in sync and attributes every discounted order back to the click that earned it — no manual exports, no code.

= What it does =

* **Auto-sync coupons** — create or edit a coupon in WooCommerce / EDD and it appears in UTM.coupons instantly (Store → Platform).
* **Attribution for every order** — when a customer checks out with a coupon, the plugin reports the order (amount, currency, code) together with the captured click id, so UTM.coupons can measure clicks → conversions → revenue.
* **Refund-safe** — refunds and cancellations are reported too, so your numbers stay honest.
* **Works with 4 platforms** — WooCommerce, Easy Digital Downloads, SureCart and FluentCart are detected automatically.
* **Public landing pages (opt-in)** — flip a switch to give any coupon a clean `/c/` landing page and a short link.
* **Dashboard widget** — glance at connection status right from the WordPress admin.

= How it works =

1. Install and activate the plugin.
2. Paste your UTM.coupons API key (or HMAC secret) on the Connection page.
3. That's it. New coupons sync and orders are attributed automatically.

No customer PII leaves your site — the plugin only reports the order id, amount, currency and coupon code.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin.
3. Go to **UTM.coupons → Connection** and paste your API key.

== Frequently Asked Questions ==

= Which stores are supported? =

WooCommerce, Easy Digital Downloads, SureCart and FluentCart.

= Does it send customer data? =

No. Only the order id, amount, currency and coupon code are reported, plus the anonymous click id used for attribution.

= Does EDD need MySQL? =

Yes. EDD 3.x stores data in custom tables that require MySQL. The plugin detects SQLite and shows a notice.

== Changelog ==

= 0.1.1 =
* Added the `[utm_coupon]` shortcode and a Copy embed button on the Coupons screen, so a synced coupon can be displayed on the store without leaving WordPress.

= 0.1.0 =
* Initial release: connection wizard, click capture, signed conversion reporting, retry queue, platform detection, coupons list, logs and dashboard widget.
