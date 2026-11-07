<?php
/**
 * Plugin Name:       Shakewell Error Reporting for Sentry
 * Plugin URI:        https://github.com/shakewellagency/shakewell-sentry
 * Description:       Reports PHP errors and exceptions to Sentry with the official Sentry PHP SDK. Off until a DSN is set in wp-config.php or the environment.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-sentry
 *
 * @package Shakewell\Sentry
 */

namespace Shakewell\Sentry;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.1';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Plugin.php';

// Start now, not on plugins_loaded, so fatal errors while other plugins load are reported too.
Plugin::init();
