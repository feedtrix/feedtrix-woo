<?php

defined( 'ABSPATH' ) || exit;

/**
 * Counts AI bot visits to the store (hourly aggregates) and sends them to FeedTrix
 * almost in real time: after the response to the bot, at most once a minute.
 * An hourly cron is the fallback (e.g. when bots stop coming).
 *
 * POST {endpoint}/bot-hits
 * {"site_url": "...", "hits": [{"hour": "2026-11-11T10:00:00Z", "bot": "GPTBot", "target": "product",
 *   "product_id": 12, "count": 3, "last_at": "2026-11-11T10:41:07Z"}]}
 */
final class FeedTrix_Bot_Tracker {

	public const PATH = '/bot-hits';

	private const OPTION        = 'feedtrix_bot_hits';
	private const LAST_SENT_OPT = 'feedtrix_bot_hits_sent_at';
	private const LOCK          = 'feedtrix_bot_hits_lock';
	private const MAX_KEYS      = 5000;
	private const SEND_EVERY    = 60;
	private const INACTIVE      = 'feedtrix_subscription_inactive';

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
		add_action( FeedTrix_Plugin::CRON_BOT_HITS, array( $this, 'send' ) );

		// AI bot statistics are a feature of connected stores with an active FeedTrix subscription
		if ( $this->settings->bot_tracking_enabled() && $this->settings->is_connected() && ! self::subscription_inactive() ) {
			add_action( 'template_redirect', array( $this, 'track' ), -1 );
		}
	}

	/** FeedTrix answered that the subscription is inactive: stop counting for a day, then ask again. */
	public static function subscription_inactive(): bool {
		return (bool) get_transient( self::INACTIVE );
	}

	public static function set_subscription_inactive( bool $inactive ): void {
		if ( $inactive ) {
			set_transient( self::INACTIVE, 1, DAY_IN_SECONDS );
		} else {
			delete_transient( self::INACTIVE );
		}
	}

	public function track(): void {
		$bot = FeedTrix_Bots::current();
		if ( null === $bot ) {
			return;
		}

		FeedTrix_Bot_Stats::record( $bot );

		[ $target, $product_id ] = $this->target();

		$key  = implode( '|', array( gmdate( 'Y-m-d\TH:00:00\Z' ), $bot, $target, $product_id ) );
		$hits = get_option( self::OPTION, array() );
		$hits = is_array( $hits ) ? $hits : array();

		if ( ! isset( $hits[ $key ] ) && count( $hits ) >= self::MAX_KEYS ) {
			return; // keeps the option from growing when sending fails
		}

		$row          = is_array( $hits[ $key ] ?? null ) ? $hits[ $key ] : array( 'c' => 0, 't' => 0 );
		$hits[ $key ] = array( 'c' => (int) $row['c'] + 1, 't' => time() );
		update_option( self::OPTION, $hits, false );

		if ( time() - (int) get_option( self::LAST_SENT_OPT, 0 ) >= self::SEND_EVERY ) {
			// send after the response to the bot is finished (does not slow it down)
			add_action( 'shutdown', array( $this, 'send_after_response' ), 100 );
		}
	}

	public function send_after_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$this->send();
	}

	/** @return array{0: string, 1: int} */
	private function target(): array {
		if ( get_query_var( 'feedtrix_llms_txt' ) ) {
			return array( 'llms_txt', 0 );
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return array( 'product', (int) get_queried_object_id() );
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return array( 'category', 0 );
		}
		if ( is_front_page() ) {
			return array( 'home', 0 );
		}

		return array( 'other', 0 );
	}

	public function send(): void {
		if ( ! $this->settings->is_connected() || get_transient( self::LOCK ) ) {
			return;
		}

		$hits = get_option( self::OPTION, array() );
		if ( ! is_array( $hits ) || ! $hits ) {
			return;
		}

		set_transient( self::LOCK, 1, 30 );
		update_option( self::LAST_SENT_OPT, time(), false );

		$rows = array();
		foreach ( $hits as $key => $value ) {
			[ $hour, $bot, $target, $product_id ] = array_pad( explode( '|', (string) $key ), 4, '0' );
			$count = is_array( $value ) ? (int) $value['c'] : (int) $value;
			$last  = is_array( $value ) && $value['t'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $value['t'] ) : $hour;
			$rows[] = array(
				'hour'       => $hour,
				'bot'        => $bot,
				'target'     => $target,
				'product_id' => (int) $product_id,
				'count'      => $count,
				'last_at'    => $last,
			);
		}

		$response = $this->client->post(
			self::PATH,
			array(
				'site_url' => home_url( '/' ),
				'hits'     => $rows,
			),
			true
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $answer ) && 'inactive' === ( $answer['subscription'] ?? '' ) ) {
				// visits are not stored without an active subscription: drop the queue and stop counting
				self::set_subscription_inactive( true );
				delete_option( self::OPTION );
				delete_option( FeedTrix_Bot_Stats::OPTION );
				delete_transient( self::LOCK );

				return;
			}
			// subtract only the sent counts; hits recorded while sending are kept
			$current = get_option( self::OPTION, array() );
			$current = is_array( $current ) ? $current : array();
			foreach ( $hits as $key => $value ) {
				if ( ! isset( $current[ $key ] ) ) {
					continue;
				}
				$sent = is_array( $value ) ? (int) $value['c'] : (int) $value;
				$now  = is_array( $current[ $key ] ) ? (int) $current[ $key ]['c'] : (int) $current[ $key ];
				if ( $now <= $sent ) {
					unset( $current[ $key ] );
				} else {
					$current[ $key ]['c'] = $now - $sent;
				}
			}
			update_option( self::OPTION, $current, false );
		}

		delete_transient( self::LOCK );
	}
}
