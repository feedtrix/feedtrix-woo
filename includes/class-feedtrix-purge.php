<?php

defined( 'ABSPATH' ) || exit;

/**
 * GET|POST /wp-json/feedtrix/settings: switches and cache times controlled from FeedTrix.
 *
 * POST /wp-json/feedtrix/purge: FeedTrix tells the store that its content changed, so the
 * local copies are dropped and the next request fetches fresh ones.
 * The request is signed with the store's API key (same headers as /status).
 *
 * Body: {"llms": true, "products": ["123", "456"]} or {"llms": true, "all_products": true}
 */
final class FeedTrix_Purge {

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'feedtrix',
			'/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => fn ( WP_REST_Request $request ) => FeedTrix_Status::verify_signature( $this->settings, $request ),
			)
		);
		register_rest_route(
			'feedtrix',
			'/settings',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'settings' ),
				'permission_callback' => fn ( WP_REST_Request $request ) => FeedTrix_Status::verify_signature( $this->settings, $request ),
			)
		);
	}

	/**
	 * GET: current switches and cache times. POST: {"settings": {"json_ld": true, …}, "cache": {"product_ttl": 86400, "llms_ttl": 86400}}
	 * changes only the given values (switches from the FeedTrix dashboard, cache times set by FeedTrix).
	 */
	public function settings( WP_REST_Request $request ): WP_REST_Response {
		if ( 'POST' === $request->get_method() ) {
			$body = json_decode( $request->get_body(), true );
			$body = is_array( $body ) ? $body : array();
			$this->settings->update_remote(
				is_array( $body['settings'] ?? null ) ? $body['settings'] : array(),
				is_array( $body['cache'] ?? null ) ? $body['cache'] : array()
			);
		}

		return new WP_REST_Response( $this->settings->remote_state() );
	}

	public function purge( WP_REST_Request $request ): WP_REST_Response {
		$body     = json_decode( $request->get_body(), true );
		$body     = is_array( $body ) ? $body : array();
		$products = 0;

		if ( ! empty( $body['llms'] ) ) {
			FeedTrix_Llms_Txt::purge_local();
		}
		if ( ! empty( $body['all_products'] ) ) {
			FeedTrix_Json_Ld::purge_all();
			$products = -1;
		} elseif ( is_array( $body['products'] ?? null ) ) {
			foreach ( array_slice( $body['products'], 0, 500 ) as $id ) {
				if ( is_scalar( $id ) && ctype_digit( (string) $id ) ) {
					FeedTrix_Json_Ld::purge( (int) $id );
					++$products;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'llms'     => ! empty( $body['llms'] ),
				'products' => $products,
			)
		);
	}
}
