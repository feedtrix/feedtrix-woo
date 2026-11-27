<?php

defined( 'ABSPATH' ) || exit;

/**
 * Basic /llms.txt built locally from the WooCommerce catalog, for stores without a FeedTrix account.
 *
 * Store name and description, product categories, up to 50 products with current price and stock, and the
 * shop, terms and privacy pages. No AI descriptions and no /llms-full.txt (these come from FeedTrix with an account).
 * Cached for an hour; any product or category change clears the cache.
 */
final class FeedTrix_Llms_Local {

	private const TRANSIENT = 'feedtrix_llms_local';
	private const PRODUCTS  = 50;

	public static function register(): void {
		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'woocommerce_product_set_stock_status', 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ) as $hook ) {
			add_action( $hook, array( self::class, 'purge' ) );
		}
		add_action( 'update_option_blogdescription', array( self::class, 'purge' ) );
		add_action( 'update_option_blogname', array( self::class, 'purge' ) );
	}

	public static function purge(): void {
		delete_transient( self::TRANSIENT );
	}

	public static function content(): string {
		$cached = get_transient( self::TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$content = self::build();
		set_transient( self::TRANSIENT, $content, HOUR_IN_SECONDS );

		return $content;
	}

	private static function line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
	}

	private static function build(): string {
		$out   = array( '# ' . self::line( get_bloginfo( 'name' ) ), '' );
		$about = self::line( get_bloginfo( 'description' ) );
		$out[] = '> ' . ( '' !== $about ? $about : sprintf( 'Online store at %s.', wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$out[] = '';

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 30,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_array( $categories ) && $categories ) {
			$out[] = '## Categories';
			$out[] = '';
			foreach ( $categories as $term ) {
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) || 'uncategorized' === $term->slug ) {
					continue;
				}
				$desc  = self::line( (string) $term->description );
				$out[] = sprintf( '- [%s](%s): %d products%s', self::line( $term->name ), $link, (int) $term->count, '' !== $desc ? '. ' . mb_substr( $desc, 0, 140 ) : '' );
			}
			$out[] = '';
		}

		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'status'     => 'publish',
					'visibility' => 'catalog',
					'limit'      => self::PRODUCTS,
					'orderby'    => 'popularity',
				)
			);
			if ( $products ) {
				$out[] = '## Products';
				$out[] = '';
				foreach ( $products as $product ) {
					if ( ! $product instanceof WC_Product ) {
						continue;
					}
					$price   = '' !== $product->get_price() ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ) : '';
					$stock   = $product->is_in_stock() ? 'in stock' : 'out of stock';
					$summary = self::line( $product->get_short_description() );
					$out[]   = sprintf( '- [%s](%s): %s%s%s', self::line( $product->get_name() ), $product->get_permalink(), '' !== $price ? $price . ', ' : '', $stock, '' !== $summary ? '. ' . mb_substr( $summary, 0, 160 ) : '' );
				}
				$out[] = '';
			}
		}

		$pages = array(
			'Shop'           => function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0,
			'Terms'          => function_exists( 'wc_terms_and_conditions_page_id' ) ? wc_terms_and_conditions_page_id() : 0,
			'Privacy policy' => (int) get_option( 'wp_page_for_privacy_policy' ),
		);
		$info  = array();
		foreach ( $pages as $label => $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$info[] = sprintf( '- [%s](%s)', $label, get_permalink( $id ) );
			}
		}
		if ( $info ) {
			$out[] = '## Information';
			$out[] = '';
			array_push( $out, ...$info );
			$out[] = '';
		}

		return implode( "\n", $out );
	}
}
