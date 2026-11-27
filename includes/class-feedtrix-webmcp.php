<?php

defined( 'ABSPATH' ) || exit;

/**
 * WebMCP (navigator.modelContext): store tools for AI agents running in the customer's browser.
 *
 * The script registers tools (product search, product details, cart, shipping, policies, checkout) that call
 * the public WooCommerce Store API in the customer's own session. In browsers without WebMCP it does nothing.
 * The agent never pays: go_to_checkout only opens the checkout page for the customer.
 */
final class FeedTrix_WebMcp {

	public const HANDLE = 'feedtrix-webmcp';

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		// local feature (Store API in the customer's session): no FeedTrix account needed
		if ( ! $this->settings->webmcp_enabled() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/webmcp.js', FEEDTRIX_FILE ), array(), FEEDTRIX_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( self::HANDLE, 'feedtrixWebMcp', $this->config() );
	}

	/** @return array<string, mixed> */
	private function config(): array {
		$pages = array();
		$ids = array(
			'terms'   => wc_get_page_id( 'terms' ),
			'returns' => (int) get_option( 'woocommerce_refund_returns_page_id' ),
			'privacy' => (int) get_option( 'wp_page_for_privacy_policy' ),
		);
		foreach ( $ids as $key => $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$pages[ $key ] = get_permalink( $id );
			}
		}

		return array(
			'store'    => get_bloginfo( 'name' ),
			'api'      => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wc_store_api' ),
			'cart'     => wc_get_cart_url(),
			'checkout' => wc_get_checkout_url(),
			'currency' => get_woocommerce_currency(),
			'decimals' => wc_get_price_decimals(),
			'pages'    => $pages,
			'cookie'   => FeedTrix_Attribution::COOKIE,
			'track'    => $this->settings->is_connected() && $this->settings->attribution_enabled() && FeedTrix_Attribution::allowed(),
		);
	}
}
