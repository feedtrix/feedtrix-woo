<?php

defined( 'ABSPATH' ) || exit;

/**
 * AI sales attribution.
 *
 * 1. A script in the store footer detects a visit from an AI assistant (referrer chatgpt.com, perplexity.ai… or
 *    utm_source=feedtrix from links in /llms.txt and JSON-LD) and stores it in the ft_ai_source cookie for 30 days.
 * 2. When an order is placed, the cookie content is saved to the order metadata (_feedtrix_ai_attribution).
 * 3. When the order is paid (status "processing" or "completed"), the plugin sends FeedTrix an order.created
 *    event: order number, amounts, currency and visit source. No buyer data.
 *    Orders without an AI visit are not sent.
 */
final class FeedTrix_Attribution {

	public const COOKIE = 'ft_ai_source';
	public const META   = '_feedtrix_ai_attribution';
	private const SENT  = '_feedtrix_ai_reported';
	private const WINDOW = 30 * DAY_IN_SECONDS;

	private const DOMAINS = array( 'chatgpt.com', 'openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com' );
	private const MEDIA   = array( 'llms_txt', 'llms_full', 'json_ld', 'mcp', 'organic_ai_referral' );

	/** @var int[] orders to report after the response is sent to the client */
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
		if ( ! $this->settings->is_connected() || ! $this->settings->attribution_enabled() ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'print_script' ), 99 );
		// orders from the classic checkout and the block checkout (Store API)
		add_action( 'woocommerce_checkout_order_created', array( $this, 'capture' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'enqueue' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'enqueue' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'flush' ), 20 );
	}

	/** Whether the cookie may be set: store filter and, with a consent plugin (WP Consent API), statistics consent. */
	private function allowed(): bool {
		$allowed = ! function_exists( 'wp_has_consent' ) || wp_has_consent( 'statistics' );

		return (bool) apply_filters( 'feedtrix_attribution_allowed', $allowed );
	}

	public function print_script(): void {
		if ( is_admin() || ! $this->allowed() ) {
			return;
		}
		$domains = wp_json_encode( self::DOMAINS );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- constant script, domain list from wp_json_encode
		echo "<script id=\"feedtrix-ai-source\">(function(){try{var d={$domains},q=new URLSearchParams(location.search),"
			. "us=(q.get('utm_source')||'').toLowerCase(),um=(q.get('utm_medium')||'').toLowerCase(),rh='';"
			. "try{rh=document.referrer?new URL(document.referrer).hostname.toLowerCase().replace(/^www\\./,''):''}catch(e){}"
			. "function m(h){for(var i=0;i<d.length;i++){if(h===d[i]||h.slice(-d[i].length-1)==='.'+d[i])return d[i]}return ''}"
			. "var s=m(rh)||m(us),md='organic_ai_referral';"
			. "if(us==='feedtrix'){if(!s)s='feedtrix';if(um==='llms_txt'||um==='llms_full'||um==='json_ld')md=um}"
			. "if(!s)return;"
			. "var v=JSON.stringify({source:s,medium:md,landing_page:location.pathname.slice(0,200),timestamp:Math.floor(Date.now()/1000)});"
			. "document.cookie='" . self::COOKIE . "='+encodeURIComponent(v)+';path=/;max-age=" . self::WINDOW . ";SameSite=Lax'+(location.protocol==='https:'?';Secure':'');"
			. "try{localStorage.setItem('" . self::COOKIE . "',v)}catch(e){}"
			. "}catch(e){}})();</script>\n";
		// phpcs:enable
	}

	/**
	 * Validated cookie content or null (missing, invalid format, visit older than 30 days).
	 *
	 * @return array{source: string, medium: string, landing_page: string, timestamp: int}|null
	 */
	public static function from_cookie(): ?array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$data = json_decode( rawurldecode( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- fields validated below
		if ( ! is_array( $data ) ) {
			return null;
		}
		$source    = strtolower( (string) ( $data['source'] ?? '' ) );
		$timestamp = (int) ( $data['timestamp'] ?? 0 );
		if ( ! in_array( $source, array_merge( self::DOMAINS, array( 'feedtrix' ) ), true ) || $timestamp > time() + HOUR_IN_SECONDS || $timestamp < time() - self::WINDOW ) {
			return null;
		}

		return array(
			'source'       => $source,
			'medium'       => in_array( $data['medium'] ?? '', self::MEDIA, true ) ? (string) $data['medium'] : 'organic_ai_referral',
			'landing_page' => substr( sanitize_text_field( (string) ( $data['landing_page'] ?? '' ) ), 0, 200 ),
			'timestamp'    => $timestamp,
		);
	}

	/** @param WC_Order $order */
	public function capture( $order ): void {
		$attribution = self::from_cookie();
		if ( ! $order instanceof WC_Order || null === $attribution || $order->get_meta( self::META ) ) {
			return;
		}
		$order->update_meta_data( self::META, $attribution );
		$order->save();
	}

	public function enqueue( int $order_id ): void {
		$this->queue[ $order_id ] = $order_id;
	}

	/** Sent after the response to the client; a reported order gets a flag, a failed one is retried on the next status change. */
	public function flush(): void {
		if ( ! $this->queue ) {
			return;
		}
		$events = array();
		$orders = array();
		foreach ( $this->queue as $order_id ) {
			$order       = wc_get_order( $order_id );
			$attribution = $order instanceof WC_Order ? $order->get_meta( self::META ) : null;
			if ( ! is_array( $attribution ) || $order->get_meta( self::SENT ) ) {
				continue;
			}
			$created  = $order->get_date_created();
			$events[] = array(
				'type'        => 'order.created',
				'order_id'    => (string) $order->get_id(),
				'total_net'   => wc_format_decimal( (float) $order->get_total() - (float) $order->get_total_tax(), 2 ),
				'total_gross' => wc_format_decimal( (float) $order->get_total(), 2 ),
				'currency'    => $order->get_currency(),
				'created_at'  => $created ? $created->format( 'c' ) : gmdate( 'c' ),
				'attribution' => $attribution,
			);
			$orders[] = $order;
		}
		$this->queue = array();
		if ( ! $events ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$response = $this->client->post(
			FeedTrix_Webhooks::PATH,
			array(
				'site_url' => home_url( '/' ),
				'sent_at'  => gmdate( 'c' ),
				'events'   => $events,
			),
			true
		);
		if ( is_wp_error( $response ) || 202 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return;
		}
		foreach ( $orders as $order ) {
			$order->update_meta_data( self::SENT, gmdate( 'c' ) );
			$order->save();
		}
	}
}
