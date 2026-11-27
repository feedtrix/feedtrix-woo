<?php

defined( 'ABSPATH' ) || exit;

/**
 * Tools → Site Health: AI search readiness tests (AI bot access in robots.txt, /llms.txt, WebMCP, FeedTrix connection).
 * All tests are local; robots.txt is read with a loopback request to the site itself.
 */
final class FeedTrix_Site_Health {

	/** Bots that fetch pages for AI answers (not training-only crawlers). */
	private const BOTS = array( 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'ClaudeBot', 'Claude-SearchBot', 'Google-Extended' );

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
	}

	/**
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public function tests( array $tests ): array {
		$tests['direct']['feedtrix_ai_bots'] = array( 'label' => __( 'AI bots can read the store', 'feedtrix' ), 'test' => array( $this, 'test_bots' ) );
		$tests['direct']['feedtrix_llms_txt'] = array( 'label' => __( 'The store has an llms.txt file', 'feedtrix' ), 'test' => array( $this, 'test_llms' ) );
		$tests['direct']['feedtrix_webmcp'] = array( 'label' => __( 'WebMCP tools for AI agents', 'feedtrix' ), 'test' => array( $this, 'test_webmcp' ) );

		return $tests;
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $description, array $extra = array() ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'AI search', 'feedtrix' ), 'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ) ),
			'description' => '<p>' . $description . '</p>',
			'actions'     => $extra['actions'] ?? '',
			'test'        => 'feedtrix_' . $test,
		);
	}

	private function audit_link(): string {
		return sprintf( '<p><a href="%s">%s</a></p>', esc_url( FeedTrix_Settings::page_url() . '#feedtrix-audit' ), esc_html__( 'Run the free AI readiness audit', 'feedtrix' ) );
	}

	/** @return list<string> AI bots blocked with "Disallow: /" in robots.txt (also through the * group) */
	public static function blocked_bots( string $robots ): array {
		$groups  = array();
		$agents  = array();
		$in_rule = false;
		foreach ( preg_split( '/\R/', $robots ) ?: array() as $raw ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $raw ) );
			if ( ! preg_match( '/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m ) ) {
				continue;
			}
			$field = strtolower( $m[1] );
			$value = trim( $m[2] );
			if ( 'user-agent' === $field ) {
				if ( $in_rule ) {
					$agents  = array();
					$in_rule = false;
				}
				$agents[] = strtolower( $value );
				continue;
			}
			if ( 'allow' === $field || 'disallow' === $field ) {
				$in_rule = true;
				foreach ( $agents as $agent ) {
					$groups[ $agent ][] = array( $field, $value );
				}
			}
		}
		$blocked = array();
		foreach ( self::BOTS as $bot ) {
			$rules = $groups[ strtolower( $bot ) ] ?? $groups['*'] ?? array();
			$all   = in_array( array( 'disallow', '/' ), $rules, true ) && ! in_array( array( 'allow', '/' ), $rules, true );
			if ( $all ) {
				$blocked[] = $bot;
			}
		}

		return $blocked;
	}

	/** @return array<string, mixed> */
	public function test_bots(): array {
		$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10, 'sslverify' => false ) );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $this->result( 'ai_bots', 'good', __( 'AI bots are not blocked by robots.txt', 'feedtrix' ), esc_html__( 'The store has no robots.txt rules that block AI bots.', 'feedtrix' ) );
		}
		$blocked = self::blocked_bots( (string) wp_remote_retrieve_body( $response ) );
		if ( $blocked ) {
			return $this->result(
				'ai_bots',
				'critical',
				__( 'robots.txt blocks AI bots', 'feedtrix' ),
				sprintf(
					/* translators: %s: list of bot names */
					esc_html__( 'These bots cannot read your store, so ChatGPT, Perplexity, Claude or Gemini will not recommend your products: %s. Remove their "Disallow: /" rules from robots.txt (often added by a security or SEO plugin).', 'feedtrix' ),
					implode( ', ', array_map( static fn ( string $bot ): string => '<code>' . esc_html( $bot ) . '</code>', $blocked ) )
				),
				array( 'actions' => $this->audit_link() )
			);
		}

		return $this->result( 'ai_bots', 'good', __( 'AI bots are not blocked by robots.txt', 'feedtrix' ), esc_html__( 'ChatGPT, Perplexity, Claude and Gemini bots can read your store.', 'feedtrix' ) );
	}

	/** @return array<string, mixed> */
	public function test_llms(): array {
		if ( file_exists( ABSPATH . 'llms.txt' ) || $this->settings->llms_txt_enabled() ) {
			$desc = $this->settings->is_connected()
				? esc_html__( 'FeedTrix serves /llms.txt and /llms-full.txt with your full catalog, AI descriptions, current prices and stock.', 'feedtrix' )
				: esc_html__( 'The store serves a basic /llms.txt built from your catalog. With a FeedTrix account it also gets /llms-full.txt with AI descriptions of all products.', 'feedtrix' );

			return $this->result( 'llms_txt', 'good', __( 'The store has an llms.txt file', 'feedtrix' ), $desc, array( 'actions' => sprintf( '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( home_url( '/llms.txt' ) ), esc_html__( 'Open /llms.txt', 'feedtrix' ) ) ) );
		}

		return $this->result(
			'llms_txt',
			'recommended',
			__( 'The store has no llms.txt file', 'feedtrix' ),
			esc_html__( 'llms.txt describes your store and products for AI assistants in one tidy file. Turn on "Serve /llms.txt" in WooCommerce → FeedTrix → Settings.', 'feedtrix' ),
			array( 'actions' => sprintf( '<p><a href="%s">%s</a></p>', esc_url( FeedTrix_Settings::page_tab_url( 'settings' ) ), esc_html__( 'FeedTrix settings', 'feedtrix' ) ) )
		);
	}

	/** @return array<string, mixed> */
	public function test_webmcp(): array {
		if ( $this->settings->webmcp_enabled() ) {
			return $this->result( 'webmcp', 'good', __( 'WebMCP tools for AI agents are on', 'feedtrix' ), esc_html__( 'AI agents in the browser can search products, check shipping and fill the cart through store tools. The customer always confirms payment.', 'feedtrix' ) );
		}

		return $this->result(
			'webmcp',
			'recommended',
			__( 'WebMCP tools for AI agents are off', 'feedtrix' ),
			esc_html__( 'AI agents in the browser (such as Gemini in Chrome) shop faster and without mistakes in stores with WebMCP tools. Turn them on in WooCommerce → FeedTrix → Settings.', 'feedtrix' ),
			array( 'actions' => sprintf( '<p><a href="%s">%s</a></p>', esc_url( FeedTrix_Settings::page_tab_url( 'settings' ) ), esc_html__( 'FeedTrix settings', 'feedtrix' ) ) )
		);
	}
}
