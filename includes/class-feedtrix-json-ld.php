<?php

defined( 'ABSPATH' ) || exit;

/**
 * FeedTrix JSON-LD on the product page.
 *
 * Content prepared by FeedTrix (description for AI assistants, attributes from the description, questions and answers)
 * goes into the product's structured data:
 *  - when WooCommerce (or Yoast / Rank Math) outputs its own Product node, the plugin extends it,
 *    so the page keeps a single product with the store's price, availability and reviews,
 *  - when the theme outputs no structured data, the plugin adds its own Product node
 *    with the current price and availability from the store,
 *  - questions and answers go into a separate FAQPage node and, when the option is on, also into
 *    a visible tab on the product page.
 *
 * GET {endpoint}/shops/{store_key}/products/{id}/json-ld, local copy in a transient. FeedTrix drops the copy
 * through POST /wp-json/feedtrix/purge when the product content changes, so it can live long.
 */
final class FeedTrix_Json_Ld {

	private const TRANSIENT   = 'feedtrix_jsonld_';
	private const GENERATION  = 'feedtrix_jsonld_generation'; // bumped to drop all copies at once
	private const TTL_ERROR   = HOUR_IN_SECONDS; // the API is not responding

	/** Whether another generator's Product node has already been extended in this request. */
	private bool $merged = false;

	/** @var array<int, array<string, mixed>|null> */
	private array $loaded = array();

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
		// the local copy is stale after every product change (also when the feature is off)
		add_action( 'woocommerce_update_product', array( self::class, 'purge' ), 10, 1 );

		if ( ! $this->settings->is_connected() || ! $this->settings->json_ld_enabled() ) {
			return;
		}
		add_filter( 'woocommerce_structured_data_product', array( $this, 'merge_woocommerce' ), 20, 2 );
		add_filter( 'wpseo_schema_product', array( $this, 'merge_current' ), 20 );                              // Yoast WooCommerce SEO
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'merge_current' ), 20 );     // Rank Math
		add_action( 'wp_footer', array( $this, 'print_nodes' ), 20 );

		if ( $this->settings->faq_tab_enabled() ) {
			add_filter( 'woocommerce_product_tabs', array( $this, 'faq_tab' ), 30 );
		}
	}

	public static function purge( int $product_id ): void {
		delete_transient( self::key( $product_id ) );
	}

	/** Drops every product copy (old ones expire on their own). */
	public static function purge_all(): void {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, true );
	}

	private static function key( int $product_id ): string {
		$generation = (int) get_option( self::GENERATION, 0 );

		return self::TRANSIENT . ( $generation ? $generation . '_' : '' ) . $product_id;
	}

	/**
	 * FeedTrix response for the product: product, faq, ai. Null: unknown product or the API is not responding.
	 *
	 * @return array<string, mixed>|null
	 */
	public function data( int $product_id ): ?array {
		if ( array_key_exists( $product_id, $this->loaded ) ) {
			return $this->loaded[ $product_id ];
		}
		$cached = get_transient( self::key( $product_id ) );
		if ( is_array( $cached ) ) {
			return $this->loaded[ $product_id ] = isset( $cached['product'] ) ? $cached : null;
		}

		$response = $this->client->get( '/shops/' . rawurlencode( $this->settings->shop_id() ) . '/products/' . $product_id . '/json-ld', 2 );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data     = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		$data     = is_array( $data ) && is_array( $data['product'] ?? null ) ? $data : null;

		// Missing data is cached too (empty array), so the API is not called on every page view.
		// A valid answer (also "no AI content yet" or 404) lives as long as set in FeedTrix (1 day by default): FeedTrix purges it when the content changes.
		// Only an API failure gets the short time, so the plugin retries soon.
		$answered = 200 === $code || 404 === $code;
		set_transient( self::key( $product_id ), $data ?? array(), $answered ? $this->settings->product_cache_ttl() : self::TTL_ERROR );

		return $this->loaded[ $product_id ] = $data;
	}

	/**
	 * Extends the existing Product node with FeedTrix content. Price, availability and reviews stay from the store.
	 *
	 * @param array<string, mixed> $markup
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function merge( array $markup, array $data ): array {
		$ours = $data['product'];
		if ( ! empty( $data['ai'] ) && ! empty( $ours['description'] ) ) {
			$markup['description'] = $ours['description'];
		}

		$properties = array();
		foreach ( array_merge( (array) ( $markup['additionalProperty'] ?? array() ), (array) ( $ours['additionalProperty'] ?? array() ) ) as $property ) {
			$key = is_array( $property ) ? strtolower( (string) ( $property['name'] ?? '' ) ) : '';
			if ( '' !== $key && ! isset( $properties[ $key ] ) ) {
				$properties[ $key ] = $property; // the store's attributes take precedence
			}
		}
		if ( $properties ) {
			$markup['additionalProperty'] = array_values( $properties );
		}

		// offer link with the FeedTrix tag (AI sales attribution)
		if ( isset( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			$single = isset( $markup['offers']['@type'] );
			$offers = $single ? array( $markup['offers'] ) : $markup['offers'];
			foreach ( $offers as $i => $offer ) {
				if ( is_array( $offer ) && ! empty( $offer['url'] ) && false === strpos( (string) $offer['url'], 'utm_source=' ) ) {
					$offers[ $i ]['url'] = add_query_arg( array( 'utm_source' => 'feedtrix', 'utm_medium' => 'json_ld' ), (string) $offer['url'] );
				}
			}
			$markup['offers'] = $single ? $offers[0] : $offers;
		}

		return $markup;
	}

	/**
	 * @param array<string, mixed> $markup
	 * @param WC_Product           $product
	 * @return array<string, mixed>
	 */
	public function merge_woocommerce( $markup, $product ) {
		$data = is_array( $markup ) && $product instanceof WC_Product ? $this->data( $product->get_id() ) : null;
		if ( null === $data ) {
			return $markup;
		}
		$this->merged = true;

		return self::merge( $markup, $data );
	}

	/**
	 * Yoast WooCommerce SEO and Rank Math: the Product node of the current product page.
	 *
	 * @param array<string, mixed> $entity
	 * @return array<string, mixed>
	 */
	public function merge_current( $entity ) {
		$data = is_array( $entity ) && function_exists( 'is_product' ) && is_product() ? $this->data( (int) get_queried_object_id() ) : null;
		if ( null === $data ) {
			return $entity;
		}
		$this->merged = true;

		return self::merge( $entity, $data );
	}

	/** Product page footer: FAQPage and, when nothing else output a Product node, also Product. */
	public function print_nodes(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		$data    = $product instanceof WC_Product ? $this->data( $product->get_id() ) : null;
		if ( null === $data ) {
			return;
		}

		$nodes = array();
		if ( ! $this->merged ) {
			$nodes[] = self::with_live_offer( $data['product'], $product );
		}
		if ( is_array( $data['faq'] ?? null ) && ! empty( $data['faq']['mainEntity'] ) ) {
			$nodes[] = array( '@context' => 'https://schema.org' ) + $data['faq'];
		}
		foreach ( $nodes as $node ) {
			// default slash escaping and JSON_HEX_TAG: the content must not be able to close the <script> tag
			echo '<script type="application/ld+json" class="feedtrix-json-ld">' . wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Price and availability always come from the store (FeedTrix data may be a few minutes old).
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function with_live_offer( array $node, WC_Product $product ): array {
		$node = array( '@context' => 'https://schema.org' ) + $node;
		if ( '' === (string) $product->get_price() ) {
			unset( $node['offers'] );

			return $node;
		}
		$node['offers'] = array(
			'@type'         => 'Offer',
			'url'           => add_query_arg( array( 'utm_source' => 'feedtrix', 'utm_medium' => 'json_ld' ), $product->get_permalink() ),
			'price'         => wc_format_decimal( wc_get_price_to_display( $product ), wc_get_price_decimals() ),
			'priceCurrency' => get_woocommerce_currency(),
			'availability'  => 'https://schema.org/' . ( $product->is_in_stock() ? 'InStock' : 'OutOfStock' ),
			'itemCondition' => 'https://schema.org/NewCondition',
		);

		return $node;
	}

	/**
	 * Visible questions and answers tab (settings option, off by default).
	 *
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function faq_tab( $tabs ) {
		$data = is_array( $tabs ) && function_exists( 'is_product' ) && is_product() ? $this->data( (int) get_queried_object_id() ) : null;
		$faq  = is_array( $data['faq']['mainEntity'] ?? null ) ? $data['faq']['mainEntity'] : array();
		if ( ! $faq ) {
			return $tabs;
		}
		$tabs['feedtrix_faq'] = array(
			'title'    => __( 'Questions and answers', 'feedtrix' ),
			'priority' => 25,
			'callback' => static function () use ( $faq ): void {
				echo '<h2>' . esc_html__( 'Questions and answers', 'feedtrix' ) . '</h2><dl class="feedtrix-faq">';
				foreach ( $faq as $item ) {
					printf( '<dt><strong>%s</strong></dt><dd>%s</dd>', esc_html( (string) ( $item['name'] ?? '' ) ), esc_html( (string) ( $item['acceptedAnswer']['text'] ?? '' ) ) );
				}
				echo '</dl>';
			},
		);

		return $tabs;
	}
}
