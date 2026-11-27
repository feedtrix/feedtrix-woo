<?php
/**
 * Plugin Name:       FeedTrix – AI Search, llms.txt, MCP & WebMCP for WooCommerce
 * Plugin URI:        https://feedtrix.com
 * Description:       Gets your WooCommerce store found in ChatGPT and AI search: free AI readiness audit, dynamic /llms.txt, AI-ready JSON-LD, WebMCP tools for AI agents, AI bot statistics, sales from AI and IndexNow.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            FeedTrix
 * License:           GPL-2.0-or-later
 * Text Domain:       feedtrix
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:     11.3
 */

defined( 'ABSPATH' ) || exit;

define( 'FEEDTRIX_VERSION', '1.2.0' );
define( 'FEEDTRIX_FILE', __FILE__ );
define( 'FEEDTRIX_DIR', plugin_dir_path( __FILE__ ) );

require_once FEEDTRIX_DIR . 'includes/class-feedtrix-settings.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-client.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-bots.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-webhooks.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-llms-txt.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-llms-local.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-bot-tracker.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-bot-stats.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-status.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-purge.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-indexnow.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-onboarding.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-attribution.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-json-ld.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-webmcp.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-audit.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-review.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-site-health.php';
require_once FEEDTRIX_DIR . 'includes/class-feedtrix-plugin.php';

register_activation_hook( __FILE__, array( 'FeedTrix_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FeedTrix_Plugin', 'deactivate' ) );

// HPOS (High-Performance Order Storage) compatibility: order metadata only through the WC_Order API.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'FeedTrix_Plugin', 'instance' ) );
