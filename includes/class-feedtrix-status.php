<?php

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/feedtrix/status, for the connection tester in the FeedTrix dashboard.
 * The request must be signed with the store's API key (same headers as in the other direction).
 * It also sends FeedTrix a "test.ping" test webhook to confirm
 * webhook delivery in both directions.
 */
final class FeedTrix_Status {

	private const MAX_SKEW = 300;

	/** @var FeedTrix_Settings */
	private $settings;
	/** @var FeedTrix_Client */
	private $client;

	public function __construct(
		FeedTrix_Settings $settings,
		FeedTrix_Client $client
	) {
		$this->settings = $settings;
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'feedtrix',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'verify' ),
			)
		);
	}

	/** @return true|WP_Error */
	public function verify( WP_REST_Request $request ) {
		return self::verify_signature( $this->settings, $request );
	}

	/**
	 * Checks the FeedTrix signature: X-FeedTrix-Shop, X-FeedTrix-Timestamp, X-FeedTrix-Signature.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_signature( FeedTrix_Settings $settings, WP_REST_Request $request ) {
		if ( ! $settings->is_connected() ) {
			return new WP_Error( 'feedtrix_not_configured', 'FeedTrix is not configured.', array( 'status' => 403 ) );
		}

		$store_key = (string) $request->get_header( 'x_feedtrix_shop' );
		$timestamp = (string) $request->get_header( 'x_feedtrix_timestamp' );
		$signature = (string) $request->get_header( 'x_feedtrix_signature' );

		if ( ! hash_equals( $settings->shop_id(), $store_key ) || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return new WP_Error( 'feedtrix_forbidden', 'Invalid store key or timestamp.', array( 'status' => 401 ) );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $settings->api_key() );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'feedtrix_forbidden', 'Invalid signature.', array( 'status' => 401 ) );
		}

		return true;
	}

	public function status(): WP_REST_Response {
		$ping = $this->client->post(
			FeedTrix_Webhooks::PATH,
			array(
				'site_url' => home_url( '/' ),
				'sent_at'  => gmdate( 'c' ),
				'events'   => array( array( 'type' => 'test.ping' ) ),
			),
			true
		);

		return new WP_REST_Response(
			array(
				'version'      => FEEDTRIX_VERSION,
				'woocommerce'  => class_exists( 'WooCommerce' ),
				'wc_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'wp_version'   => get_bloginfo( 'version' ),
				'php_version'  => PHP_VERSION,
				'llms_txt'     => $this->settings->llms_txt_enabled(),
				'bot_tracking' => $this->settings->bot_tracking_enabled(),
				'attribution'  => $this->settings->attribution_enabled(),
				'json_ld'      => $this->settings->json_ld_enabled(),
				'remote'       => $this->settings->remote_state(), // switches and cache times, as in GET /settings
				'llms_full'    => true,
				'hooks'        => class_exists( 'WooCommerce' ) ? FeedTrix_Webhooks::hook_count() : 0,
				'webhook_test' => is_wp_error( $ping ) ? $ping->get_error_message() : (int) wp_remote_retrieve_response_code( $ping ),
				'static_llms'  => file_exists( ABSPATH . 'llms.txt' ),
				'indexnow'     => '' !== $this->settings->indexnow_key(),
			)
		);
	}
}
