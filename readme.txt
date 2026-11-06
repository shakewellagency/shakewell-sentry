=== Shakewell Error Reporting for Sentry ===
Contributors: shakewell
Tags: sentry, error reporting, errors, monitoring, debugging
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends PHP errors and exceptions to Sentry with the official Sentry PHP SDK. No settings page, no tracking, and off until you set a DSN.

== Description ==

Starts Sentry's official PHP SDK (`sentry/sentry`) from constants or environment variables, so PHP errors, uncaught exceptions and fatal errors on your site show up in your Sentry project.

* Off unless a DSN is set.
* Starts as soon as the plugin file loads, so fatal errors while other plugins load are reported. For errors before regular plugins load, use the optional mu-plugin loader below.
* Sends no personal data by default: no cookies, Authorization headers, request bodies, user email, name or IP address. Values of keys that look like secrets (password, token, key, secret, auth, session, nonce and similar) are replaced with `[Filtered]`, and query strings are reduced to parameter names.
* Tags every event with the WordPress version, the PHP version and whether the site is a multisite.
* PHP only. It does not add Sentry to the browser.
* No settings page. Configuration lives in `wp-config.php` or the environment, where secrets belong.

The release zip includes the production `vendor/` folder with the Sentry SDK. If you install with Composer instead, the SDK comes in as a normal dependency.

= External services =

This plugin sends error events to Sentry, either the hosted service at sentry.io or a self-hosted Sentry, whichever your DSN points to. It sends nothing anywhere else and sends nothing until you set a DSN.

* What is sent: an event for each PHP error or exception that reaches the SDK: the message, the stack trace, the PHP and WordPress version, the environment and release names you set, the request URL without its query values, the request method and non-secret request headers. Cookies, Authorization headers, request bodies and user details are removed before sending.
* When: at the moment an error or exception happens (and, for fatal errors, at shutdown).
* Sentry terms of service: https://sentry.io/terms/
* Sentry privacy policy: https://sentry.io/privacy/

If you use a self-hosted Sentry, your own terms and policies apply to the data you send there.

== Installation ==

1. Install and activate the plugin (upload the release zip, or `composer require` it).
2. Add your DSN to `wp-config.php`, above the line "That's all, stop editing":

`define( 'SHAKEWELL_SENTRY_DSN', 'https://key@o123.ingest.sentry.io/456' );`

3. Optionally set the environment, release and sample rate (see Constants). Trigger a test error and check your Sentry project.

= Constants =

Each can be a PHP constant or an environment variable of the same name. A constant wins over an environment variable.

* `SHAKEWELL_SENTRY_DSN` (also reads `WP_SENTRY_PHP_DSN`): the DSN. Required.
* `SHAKEWELL_SENTRY_ENVIRONMENT` (also reads `WP_SENTRY_ENV`): for example `production` or `staging`.
* `SHAKEWELL_SENTRY_RELEASE` (also reads `WP_SENTRY_VERSION`): a version or commit hash.
* `SHAKEWELL_SENTRY_SAMPLE_RATE`: a number from 0 to 1. Default 1 (every event).

For each setting the order is: Shakewell constant, wp-sentry constant, Shakewell environment variable, wp-sentry environment variable.

= Filters =

* `shakewell_sentry_options` (array): change any Sentry SDK option. Runs once, while the plugin loads, so add it from an mu-plugin or `wp-config.php`.
* `shakewell_sentry_before_send` (Event, hint): runs after the built-in scrubber. Return the event, or null to drop it.
* `shakewell_sentry_send_user_id` (bool, default false): return true to attach the logged-in user's numeric ID to events. Never the email, name or IP address.

= Optional mu-plugin loader =

To catch errors before regular plugins load, create `wp-content/mu-plugins/shakewell-sentry-loader.php`:

`<?php require_once WP_PLUGIN_DIR . '/shakewell-sentry/shakewell-sentry.php';`

= Switching from wp-sentry =

Deactivate and remove `wp-sentry`, install this plugin, and leave your existing `WP_SENTRY_PHP_DSN`, `WP_SENTRY_ENV` and `WP_SENTRY_VERSION` constants as they are: they are read as fallbacks. Move to the `SHAKEWELL_SENTRY_*` names whenever convenient. Only PHP reporting is supported: `WP_SENTRY_BROWSER_*` and other wp-sentry constants are ignored.

== Frequently Asked Questions ==

= Nothing arrives in Sentry. =

Check the DSN, that the site can make outbound HTTPS requests to Sentry, and that the SDK is present (the release zip includes it; a Composer install needs `composer install` to have run). The plugin never prints errors, so check the PHP error log.

= Does it send personal data? =

Not by default. See the Description. Sites that need more can use the filters above, and are then responsible for what they send.

= Does it report browser (JavaScript) errors? =

No.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
