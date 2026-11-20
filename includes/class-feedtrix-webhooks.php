<?php

defined( 'ABSPATH' ) || exit;

/**
 * Collects product changes during the request and sends them in one non-blocking
 * POST at the end (shutdown). FeedTrix then invalidates its cache through NATS.
 *
 * POST {endpoint}/webhooks/woocommerce
 * {"site_url": "...", "sent_at": "...", "events": [
 *   {"type": "product.updated", "product_id": 12, "changes": ["price"],
 *    "snapshot": {"price": "199.99", "regular_price": "249.99", "in_stock": true}}
 * ]}
 */
final class FeedTrix_Webhooks {

	public const PATH = '/webhooks/woocommerce';

	private const PRICE_PROPS = array( 'price', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' );
	private const STOCK_PROPS = array( 'stock_quantity', 'stock_status', 'manage_stock', 'backorders' );

	/** @var array<int, array{type: string, changes: array<string, true>}> */
	private array $queue = array();

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
		if ( ! $this->settings->is_connected() ) {
			return;
		}

		add_action( 'woocommerce_new_product', array( $this, 'on_created' ), 10, 1 );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'on_props_updated' ), 10, 2 );
		add_action( 'woocommerce_update_product', array( $this, 'on_updated' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_updated' ), 10, 1 );

		// Stock changes from orders bypass updated_props (written directly to the data store).
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_set' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_set' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_set' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status_set' ), 10, 1 );

		add_action( 'wp_trash_post', array( $this, 'on_deleted' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_deleted' ), 10, 1 );

		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/** Number of registered hooks (reported to the connection tester). */
	public static function hook_count(): int {
		return 10;
	}

	public function on_created( int $product_id ): void {
		$this->enqueue( $product_id, 'product.created' );
	}

	public function on_updated( int $product_id ): void {
		$this->enqueue( $product_id, 'product.updated' );
	}

	/** @param string[] $props */
	public function on_props_updated( WC_Product $product, array $props ): void {
		$changes = array();
		if ( array_intersect( $props, self::PRICE_PROPS ) ) {
			$changes[] = 'price';
		}
		if ( array_intersect( $props, self::STOCK_PROPS ) ) {
			$changes[] = 'stock';
		}
		$this->enqueue( $product->get_id(), 'product.updated', $changes );
	}

	public function on_stock_set( WC_Product $product ): void {
		$this->enqueue( $product->get_id(), 'product.updated', array( 'stock' ) );
	}

	/** @param int|WC_Product $product */
	public function on_stock_status_set( $product ): void {
		$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
		$this->enqueue( $id, 'product.updated', array( 'stock' ) );
	}

	public function on_deleted( int $post_id ): void {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			$this->enqueue( $post_id, 'product.deleted' );
		}
	}

	/** @param string[] $changes */
	private function enqueue( int $product_id, string $type, array $changes = array() ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$current = $this->queue[ $product_id ] ?? array( 'type' => $type, 'changes' => array() );

		// deleted > created > updated
		$priority = array( 'product.updated' => 0, 'product.created' => 1, 'product.deleted' => 2 );
		if ( $priority[ $type ] > $priority[ $current['type'] ] ) {
			$current['type'] = $type;
		}
		foreach ( $changes as $change ) {
			$current['changes'][ $change ] = true;
		}

		$this->queue[ $product_id ] = $current;
	}

	public function flush(): void {
		if ( ! $this->queue ) {
			return;
		}

		$events = array();
		foreach ( $this->queue as $product_id => $item ) {
			$event = array(
				'type'       => $item['type'],
				'product_id' => $product_id,
				'changes'    => array_keys( $item['changes'] ),
			);
			if ( 'product.deleted' !== $item['type'] ) {
				$snapshot = self::snapshot( $product_id );
				if ( $snapshot ) {
					$event['snapshot'] = $snapshot;
				}
			}
			$events[] = $event;
		}
		$this->queue = array();

		// the local llms.txt copy is stale: the next request fetches a fresh one from FeedTrix
		FeedTrix_Llms_Txt::purge_local();

		$this->client->post(
			self::PATH,
			array(
				'site_url' => home_url( '/' ),
				'sent_at'  => gmdate( 'c' ),
				'events'   => $events,
			)
		);
	}

	/**
	 * Current price and availability: FeedTrix updates them immediately, before the worker fetches the full product.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function snapshot( int $product_id ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return array(
			'price'         => '' !== (string) $product->get_price() ? (string) $product->get_price() : null,
			'regular_price' => '' !== (string) $product->get_regular_price() ? (string) $product->get_regular_price() : null,
			'in_stock'      => $product->is_in_stock(),
		);
	}
}
