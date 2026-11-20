<?php

defined( 'ABSPATH' ) || exit;

final class FeedTrix_Settings {

	public const OPTION         = 'feedtrix_settings';
	public const ACCOUNT_OPTION = 'feedtrix_account';
	public const PAGE           = 'feedtrix';

	private const DEFAULTS = array(
		'endpoint'      => 'https://api.feedtrix.com',
		'shop_id'       => '',
		'api_key'       => '',
		'indexnow_key'  => '',
		'llms_txt'      => 1,
		'bot_tracking'  => 1,
		'attribution'   => 1,
		'json_ld'       => 1,
		'faq_tab'       => 0,
		'product_cache_ttl' => DAY_IN_SECONDS, // set from FeedTrix (POST /wp-json/feedtrix/settings)
		'llms_cache_ttl'    => DAY_IN_SECONDS,
	);

	/** Feature switches that can also be changed from the FeedTrix dashboard. */
	public const TOGGLES = array( 'llms_txt', 'bot_tracking', 'attribution', 'json_ld', 'faq_tab' );

	private const TTL_MIN = 5 * MINUTE_IN_SECONDS;
	private const TTL_MAX = 30 * DAY_IN_SECONDS;

	private const CONNECTION_KEYS = array( 'endpoint', 'shop_id', 'api_key', 'indexnow_key' );

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FEEDTRIX_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Precedence: defaults < feedtrix-config.php (from the .zip package from the dashboard) < settings saved in WP.
	 * Empty connection fields in the settings do not override values from the config file.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$defaults = self::DEFAULTS;
		if ( defined( 'FEEDTRIX_ENDPOINT' ) ) {
			$defaults['endpoint'] = (string) FEEDTRIX_ENDPOINT; // e.g. a test environment: define( 'FEEDTRIX_ENDPOINT', 'http://feedtrix-api:81' );
		}
		$values = array_merge( $defaults, self::bundled_config() );
		$saved  = get_option( self::OPTION, array() );
		foreach ( is_array( $saved ) ? $saved : array() as $key => $value ) {
			if ( in_array( $key, self::CONNECTION_KEYS, true ) && '' === (string) $value ) {
				continue;
			}
			$values[ $key ] = $value;
		}

		return $this->cache = $values;
	}

	/** @return array<string, string> */
	private static function bundled_config(): array {
		$file = FEEDTRIX_DIR . 'feedtrix-config.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$config = include $file;

		return is_array( $config ) ? array_intersect_key( array_map( 'strval', $config ), array_flip( self::CONNECTION_KEYS ) ) : array();
	}

	public function has_bundled_config(): bool {
		return array() !== self::bundled_config();
	}

	/**
	 * Saves the access data received from FeedTrix (connection with keys from the dashboard).
	 *
	 * @param array<string, string> $connection endpoint, shop_id, api_key, indexnow_key
	 * @param array<string, mixed>  $account    email, verified, panel_url, account
	 */
	public function save_connection( array $connection, array $account ): void {
		$saved = get_option( self::OPTION, array() );
		$saved = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
		foreach ( self::CONNECTION_KEYS as $key ) {
			$saved[ $key ] = (string) ( $connection[ $key ] ?? '' );
		}
		if ( '' === $saved['endpoint'] ) {
			$saved['endpoint'] = $this->endpoint(); // disconnecting: the FeedTrix URL stays unchanged
		}
		update_option( self::OPTION, $saved );
		if ( array() === $account ) {
			delete_option( self::ACCOUNT_OPTION );
		} else {
			update_option( self::ACCOUNT_OPTION, $account, false );
		}
		$this->flush_cache();
	}

	/** @return array<string, mixed> */
	public function account(): array {
		$account = get_option( self::ACCOUNT_OPTION, array() );

		return is_array( $account ) ? $account : array();
	}

	/**
	 * FeedTrix dashboard home. Older API versions returned the store page URL (/shops/{id}),
	 * which gives a 404 when another account is logged in in the browser, so only the dashboard URL is kept.
	 */
	public function panel_url(): string {
		$url   = (string) ( $this->account()['panel_url'] ?? '' );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/';
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public function flush_cache(): void {
		$this->cache = null;
	}

	public function endpoint(): string {
		return untrailingslashit( (string) $this->all()['endpoint'] );
	}

	public function shop_id(): string {
		return (string) $this->all()['shop_id'];
	}

	public function api_key(): string {
		return (string) $this->all()['api_key'];
	}

	public function indexnow_key(): string {
		$key = strtolower( (string) $this->all()['indexnow_key'] );

		return 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ? $key : '';
	}

	public function is_connected(): bool {
		return '' !== $this->shop_id() && '' !== $this->api_key();
	}

	public function llms_txt_enabled(): bool {
		return (bool) $this->all()['llms_txt'];
	}

	public function bot_tracking_enabled(): bool {
		return (bool) $this->all()['bot_tracking'];
	}

	public function attribution_enabled(): bool {
		return (bool) $this->all()['attribution'];
	}

	public function json_ld_enabled(): bool {
		return (bool) $this->all()['json_ld'];
	}

	public function faq_tab_enabled(): bool {
		return (bool) $this->all()['faq_tab'];
	}

	public function product_cache_ttl(): int {
		return self::clamp_ttl( $this->all()['product_cache_ttl'] ?? DAY_IN_SECONDS );
	}

	public function llms_cache_ttl(): int {
		return self::clamp_ttl( $this->all()['llms_cache_ttl'] ?? DAY_IN_SECONDS );
	}

	/** @param mixed $seconds */
	private static function clamp_ttl( $seconds ): int {
		return max( self::TTL_MIN, min( self::TTL_MAX, (int) $seconds ) );
	}

	/**
	 * Changes from FeedTrix: only the given switches and cache times, the rest stays as it is.
	 *
	 * @param array<string, mixed> $toggles
	 * @param array<string, mixed> $cache product_ttl, llms_ttl
	 */
	public function update_remote( array $toggles, array $cache ): void {
		$saved = get_option( self::OPTION, array() );
		$saved = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
		foreach ( self::TOGGLES as $key ) {
			if ( array_key_exists( $key, $toggles ) ) {
				$saved[ $key ] = empty( $toggles[ $key ] ) ? 0 : 1;
			}
		}
		foreach ( array( 'product_ttl' => 'product_cache_ttl', 'llms_ttl' => 'llms_cache_ttl' ) as $from => $key ) {
			if ( isset( $cache[ $from ] ) && is_numeric( $cache[ $from ] ) ) {
				$saved[ $key ] = self::clamp_ttl( $cache[ $from ] );
			}
		}
		$product_ttl_changed = $saved['product_cache_ttl'] !== $this->product_cache_ttl();
		update_option( self::OPTION, $saved ); // also drops the local /llms.txt copy (FeedTrix_Llms_Txt::purge_local)
		$this->flush_cache();
		if ( $product_ttl_changed ) {
			FeedTrix_Json_Ld::purge_all(); // copies saved with the old time
		}
	}

	/** @return array{settings: array<string, bool>, cache: array{product_ttl: int, llms_ttl: int}} */
	public function remote_state(): array {
		$settings = array();
		foreach ( self::TOGGLES as $key ) {
			$settings[ $key ] = (bool) $this->all()[ $key ];
		}

		return array(
			'settings' => $settings,
			'cache'    => array(
				'product_ttl' => $this->product_cache_ttl(),
				'llms_ttl'    => $this->llms_cache_ttl(),
			),
		);
	}

	public function add_menu(): void {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page( $parent, 'FeedTrix: ' . __( 'AI search optimization (AEO) for your store', 'feedtrix' ), 'FeedTrix', 'manage_options', self::PAGE, array( $this, 'render_page' ) );
	}

	/** @param string[] $links */
	public function action_links( array $links ): array {
		$url = admin_url( ( class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php' ) . '?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'feedtrix' ) . '</a>' );

		return $links;
	}

	public function register_settings(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'feedtrix-admin', plugins_url( 'assets/admin.css', FEEDTRIX_FILE ), array(), FEEDTRIX_VERSION );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// connection data is saved by onboarding (save_connection); the settings form does not change it
		$values = array(
			'endpoint'     => esc_url_raw( trim( (string) ( $input['endpoint'] ?? $saved['endpoint'] ?? self::DEFAULTS['endpoint'] ) ), array( 'https', 'http' ) ),
			'llms_txt'     => empty( $input['llms_txt'] ) ? 0 : 1,
			'bot_tracking' => empty( $input['bot_tracking'] ) ? 0 : 1,
			'attribution'  => empty( $input['attribution'] ) ? 0 : 1,
			'json_ld'      => empty( $input['json_ld'] ) ? 0 : 1,
			'faq_tab'      => empty( $input['faq_tab'] ) ? 0 : 1,
		);
		// cache times are set from FeedTrix only; the settings form keeps them
		foreach ( array( 'product_cache_ttl', 'llms_cache_ttl' ) as $key ) {
			$values[ $key ] = self::clamp_ttl( $input[ $key ] ?? $saved[ $key ] ?? self::DEFAULTS[ $key ] );
		}
		foreach ( array( 'shop_id', 'api_key', 'indexnow_key' ) as $key ) {
			$values[ $key ] = array_key_exists( $key, $input ) ? sanitize_text_field( (string) $input[ $key ] ) : (string) ( $saved[ $key ] ?? '' );
		}
		if ( '' === $values['endpoint'] ) {
			$values['endpoint'] = self::DEFAULTS['endpoint'];
		}

		return $values;
	}

	private ?FeedTrix_Onboarding $onboarding = null;

	public function set_onboarding( FeedTrix_Onboarding $onboarding ): void {
		$this->onboarding = $onboarding;
	}

	public static function page_tab_url( string $tab ): string {
		return 'settings' === $tab ? add_query_arg( 'tab', 'settings', self::page_url() ) : self::page_url();
	}

	/**
	 * Sign-up in the FeedTrix dashboard. Dashboard URL: the FEEDTRIX_PANEL_URL constant (test environment),
	 * otherwise the API host with "api." replaced by "panel." (api.feedtrix.com -> panel.feedtrix.com).
	 */
	public function signup_url(): string {
		if ( defined( 'FEEDTRIX_PANEL_URL' ) ) {
			$panel = untrailingslashit( (string) FEEDTRIX_PANEL_URL );
		} else {
			$parts = wp_parse_url( $this->endpoint() );
			$host  = (string) ( $parts['host'] ?? '' );
			$panel = str_starts_with( $host, 'api.' )
				? ( $parts['scheme'] ?? 'https' ) . '://panel.' . substr( $host, 4 ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
				: 'https://panel.feedtrix.com';
		}

		return add_query_arg( array( 'utm_source' => 'woocommerce-plugin', 'utm_medium' => 'plugin' ), $panel . '/register' );
	}

	/** Masked key: only the start and end are visible (ftx_504b••••••ed12). */
	private static function mask( string $value, int $head = 4, int $tail = 4 ): string {
		if ( strlen( $value ) <= $head + $tail ) {
			return str_repeat( '•', 8 );
		}

		return substr( $value, 0, $head ) . str_repeat( '•', 10 ) . substr( $value, -$tail );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = FeedTrix_Onboarding::take_notice();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection only
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : (string) ( $notice['tab'] ?? '' );
		$tab = 'settings' === $tab ? 'settings' : 'overview';
		?>
		<div class="wrap feedtrix-wrap">
			<h1 class="feedtrix-title">
				<img src="<?php echo esc_url( plugins_url( 'assets/icon.svg', FEEDTRIX_FILE ) ); ?>" alt="" width="36" height="36">
				<span>FeedTrix <span class="feedtrix-tagline"><?php esc_html_e( 'AI search optimization (AEO) for your store', 'feedtrix' ); ?></span></span>
			</h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::page_tab_url( 'overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'feedtrix' ); ?></a>
				<a href="<?php echo esc_url( self::page_tab_url( 'settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'feedtrix' ); ?></a>
			</nav>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> inline"><p><?php echo esc_html( (string) $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( file_exists( ABSPATH . 'llms.txt' ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'There is an llms.txt file in the WordPress root directory. The web server will serve it instead of the FeedTrix version. Delete it to enable the dynamic file.', 'feedtrix' ); ?>
				</p></div>
			<?php endif; ?>
			<?php
			if ( 'settings' === $tab ) {
				settings_errors();
				$this->render_settings( is_array( $notice ) ? $notice : array() );
			} elseif ( $this->is_connected() ) {
				$this->render_connected();
				$this->render_features_status();
				$this->render_chat_preview();
			} else {
				$this->render_intro();
				$this->render_chat_preview();
			}
			?>
		</div>
		<?php
	}

	/** Before connecting: what FeedTrix is (infographic) and sign-up in the dashboard. */
	private function render_intro(): void {
		$icon = static fn ( string $path ): string => '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
		$steps = array(
			array( $icon( '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/>' ), __( 'Your store', 'feedtrix' ), __( 'WooCommerce products, prices and stock', 'feedtrix' ), false ),
			array( $icon( '<path d="M12 3l2.5 5.5L20 11l-5.5 2.5L12 19l-2.5-5.5L4 11l5.5-2.5z"/>' ), 'FeedTrix', __( 'Writes /llms.txt, structured data and AI descriptions', 'feedtrix' ), true ),
			array( $icon( '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8.5 11h7M8.5 14h4"/>' ), __( 'AI assistants', 'feedtrix' ), 'ChatGPT, Perplexity, Claude, Gemini', false ),
			array( $icon( '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 11.2a1.5 1.5 0 0 0 1.5 1.1h8.4a1.5 1.5 0 0 0 1.5-1.1L21 7H6"/>' ), __( 'New customers', 'feedtrix' ), __( 'AI recommends your products and links to your store', 'feedtrix' ), false ),
		);
		?>
		<section class="feedtrix-hero">
			<h2><?php esc_html_e( 'Get your store recommended by ChatGPT, Perplexity and Claude', 'feedtrix' ); ?></h2>
			<p class="lead"><?php esc_html_e( 'More and more customers ask AI assistants what to buy. FeedTrix prepares your catalog so that AI understands it, recommends your products and sends buyers to your store.', 'feedtrix' ); ?></p>

			<div class="feedtrix-flow">
				<?php foreach ( $steps as $i => $step ) : ?>
					<?php if ( $i > 0 ) : ?><div class="feedtrix-arrow" aria-hidden="true">→</div><?php endif; ?>
					<div class="feedtrix-step <?php echo $step[3] ? 'is-brand' : ''; ?>">
						<div class="icon"><?php echo $step[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant SVG ?></div>
						<strong><?php echo esc_html( $step[1] ); ?></strong>
						<span><?php echo esc_html( $step[2] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="feedtrix-benefits">
				<div class="feedtrix-benefit"><strong><?php esc_html_e( 'Visible in AI answers', 'feedtrix' ); ?></strong><span><?php esc_html_e( '/llms.txt and JSON-LD generated from your catalog, updated after every price or stock change.', 'feedtrix' ); ?></span></div>
				<div class="feedtrix-benefit"><strong><?php esc_html_e( 'AI bot statistics', 'feedtrix' ); ?></strong><span><?php esc_html_e( 'See which AI bots visit your store and which pages they read.', 'feedtrix' ); ?></span></div>
				<div class="feedtrix-benefit"><strong><?php esc_html_e( 'Sales from AI', 'feedtrix' ); ?></strong><span><?php esc_html_e( 'Orders from customers who came from AI assistants, in one report.', 'feedtrix' ); ?></span></div>
			</div>

			<div class="feedtrix-start">
				<ol>
					<li><?php esc_html_e( 'Sign up in FeedTrix', 'feedtrix' ); ?></li>
					<li><?php esc_html_e( 'Add your store and copy its keys', 'feedtrix' ); ?></li>
					<li><?php esc_html_e( 'Paste the keys in Settings', 'feedtrix' ); ?></li>
				</ol>
				<div class="feedtrix-cta">
					<a class="button feedtrix-signup" href="<?php echo esc_url( $this->signup_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Sign up', 'feedtrix' ); ?></a>
					<a href="<?php echo esc_url( self::page_tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'I already have keys', 'feedtrix' ); ?> →</a>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_connected(): void {
		$account = $this->account();
		?>
		<section class="feedtrix-card">
			<h2 class="feedtrix-status"><span class="feedtrix-dot"></span><?php esc_html_e( 'Your store is connected to FeedTrix', 'feedtrix' ); ?></h2>
			<dl class="feedtrix-facts">
				<?php if ( ! empty( $account['email'] ) ) : ?>
					<dt><?php esc_html_e( 'Account:', 'feedtrix' ); ?></dt><dd><strong><?php echo esc_html( (string) $account['email'] ); ?></strong></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'File for AI bots:', 'feedtrix' ); ?></dt>
				<dd><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></dd>
			</dl>
			<?php if ( isset( $account['verified'] ) && ! $account['verified'] ) : ?>
				<p class="description">✉ <?php esc_html_e( 'Confirm your email address using the link we sent you. Then choose a plan in the panel (7 days free).', 'feedtrix' ); ?></p>
			<?php endif; ?>
			<div class="feedtrix-actions">
				<?php if ( '' !== $this->panel_url() ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $this->panel_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open FeedTrix panel', 'feedtrix' ); ?></a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( self::page_tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'feedtrix' ); ?></a>
			</div>
		</section>
		<?php
	}

	/** @return array<string, array{title: string, description: string, icon: string}> plugin features (settings and overview tiles) */
	private function features(): array {
		return array(
			'llms_txt'     => array(
				'title'       => __( 'Serve /llms.txt', 'feedtrix' ),
				'description' => __( 'A file for AI bots with your catalog, served from your domain.', 'feedtrix' ),
				'icon'        => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
			),
			'bot_tracking' => array(
				'title'       => __( 'AI bot statistics', 'feedtrix' ),
				'description' => __( 'Counts visits of AI bots (no personal data) and sends them to FeedTrix every hour.', 'feedtrix' ),
				'icon'        => '<rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 8V4M9 13h.01M15 13h.01M9.5 16.5h5"/>',
			),
			'attribution'  => array(
				'title'       => __( 'Sales from AI (order attribution)', 'feedtrix' ),
				'description' => __( 'Marks paid orders from customers who came from AI assistants in the last 30 days.', 'feedtrix' ),
				'icon'        => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
			),
			'json_ld'      => array(
				'title'       => __( 'FeedTrix structured data (JSON-LD) on product pages', 'feedtrix' ),
				'description' => __( 'Adds AI descriptions, attributes and FAQ to the product data read by AI and search engines.', 'feedtrix' ),
				'icon'        => '<path d="M8 4c-2 0-3 1-3 3v2c0 1.5-1 2.5-2 3 1 .5 2 1.5 2 3v2c0 2 1 3 3 3M16 4c2 0 3 1 3 3v2c0 1.5 1 2.5 2 3-1 .5-2 1.5-2 3v2c0 2-1 3-3 3"/>',
			),
			'faq_tab'      => array(
				'title'       => __( 'Show the questions and answers tab on product pages', 'feedtrix' ),
				'description' => __( 'A visible tab with questions and answers prepared by FeedTrix.', 'feedtrix' ),
				'icon'        => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.4M12 17h.01"/>',
			),
		);
	}

	private static function icon( string $paths, int $size = 22 ): string {
		return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
	}

	/** After connecting: what FeedTrix does in the store (tiles with icons and on / off state). */
	private function render_features_status(): void {
		$values = $this->all();
		?>
		<section class="feedtrix-card">
			<h2><?php esc_html_e( 'Working in your store', 'feedtrix' ); ?></h2>
			<div class="feedtrix-tiles">
				<?php foreach ( $this->features() as $key => $feature ) : ?>
					<?php $on = (bool) $values[ $key ]; ?>
					<div class="feedtrix-tile <?php echo $on ? '' : 'is-off'; ?>">
						<div class="icon"><?php echo self::icon( $feature['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant SVG ?></div>
						<div>
							<span class="feedtrix-chip"><?php echo $on ? esc_html__( 'On', 'feedtrix' ) : esc_html__( 'Off', 'feedtrix' ); ?></span>
							<strong><?php echo esc_html( $feature['title'] ); ?></strong>
							<span class="description"><?php echo esc_html( $feature['description'] ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<p><a href="<?php echo esc_url( self::page_tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Change in Settings', 'feedtrix' ); ?> →</a></p>
		</section>
		<?php
	}

	/**
	 * Random in-stock product with a price for the AI chat preview, different on every page load.
	 * Products with an image are preferred; with no suitable product a placeholder is shown.
	 */
	private function preview_product(): array {
		$product = null;
		if ( function_exists( 'wc_get_products' ) ) {
			$candidates = wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 20, 'orderby' => 'rand', 'stock_status' => 'instock' ) );
			$priced     = array_values( array_filter( $candidates, static fn ( $p ) => $p instanceof WC_Product && '' !== $p->get_price() ) );
			$with_image = array_values( array_filter( $priced, static fn ( WC_Product $p ) => (int) $p->get_image_id() > 0 ) );
			$product    = $with_image[0] ?? $priced[0] ?? null;
		}
		if ( $product instanceof WC_Product ) {
			$image = wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' );

			return array(
				'name'  => $product->get_name(),
				'price' => function_exists( 'wc_price' ) && '' !== $product->get_price() ? wp_strip_all_tags( wc_price( (float) $product->get_price() ) ) : '',
				'image' => $image ? (string) $image : '',
			);
		}

		return array( 'name' => __( 'Your bestseller', 'feedtrix' ), 'price' => '', 'image' => '' );
	}

	/** Chat mockup: an AI assistant recommends a product from this store and links to it. */
	private function render_chat_preview(): void {
		$product = $this->preview_product();
		$domain  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<section class="feedtrix-card feedtrix-preview">
			<div class="feedtrix-preview-text">
				<h2><?php esc_html_e( 'What your customer sees', 'feedtrix' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This is how an AI assistant can recommend your store once it knows your catalog.', 'feedtrix' ); ?></p>
				<ul class="feedtrix-checks">
					<li><?php esc_html_e( 'Up-to-date prices and availability', 'feedtrix' ); ?></li>
					<li><?php esc_html_e( 'A direct link to the product in your store', 'feedtrix' ); ?></li>
					<li><?php esc_html_e( 'The sale is attributed to AI in your report', 'feedtrix' ); ?></li>
				</ul>
			</div>
			<div class="feedtrix-chat" aria-hidden="true">
				<div class="feedtrix-chat-bar"><i></i><i></i><i></i><span><?php esc_html_e( 'AI assistant', 'feedtrix' ); ?></span></div>
				<div class="feedtrix-bubble is-user">
					<?php
					/* translators: %s: product name */
					printf( esc_html__( 'I\'m looking for: %s. Where is the best place to buy it?', 'feedtrix' ), esc_html( $product['name'] ) );
					?>
				</div>
				<div class="feedtrix-bubble is-ai">
					<?php
					/* translators: 1: product name, 2: store domain */
					printf( esc_html__( 'I recommend %1$s from %2$s. It is in stock, and the product page has the full specification and current price.', 'feedtrix' ), '<strong>' . esc_html( $product['name'] ) . '</strong>', '<strong>' . esc_html( $domain ) . '</strong>' );
					?>
					<div class="feedtrix-product">
						<?php if ( '' !== $product['image'] ) : ?>
							<img src="<?php echo esc_url( $product['image'] ); ?>" alt="" width="56" height="56">
						<?php else : ?>
							<span class="feedtrix-product-img"><?php echo self::icon( '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/>', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant SVG ?></span>
						<?php endif; ?>
						<span>
							<strong><?php echo esc_html( $product['name'] ); ?></strong>
							<small><?php echo esc_html( $domain ); ?><?php echo '' !== $product['price'] ? ' · ' . esc_html( $product['price'] ) : ''; ?></small>
						</span>
						<em><?php esc_html_e( 'In stock', 'feedtrix' ); ?></em>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $notice */
	private function render_settings( array $notice ): void {
		$this->render_connection( is_array( $notice['old'] ?? null ) ? $notice['old'] : array() );

		$values   = $this->all();
		$features = $this->features();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::PAGE ); ?>
			<section class="feedtrix-card">
				<h2><?php esc_html_e( 'Features', 'feedtrix' ); ?></h2>
				<div class="feedtrix-toggles">
					<?php foreach ( $features as $key => $feature ) : ?>
						<label class="feedtrix-toggle">
							<span><strong><?php echo esc_html( $feature['title'] ); ?></strong><span class="description"><?php echo esc_html( $feature['description'] ); ?></span></span>
							<span class="feedtrix-switch"><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( (bool) $values[ $key ] ); ?>><i></i></span>
						</label>
					<?php endforeach; ?>
				</div>
				<details class="feedtrix-advanced">
					<summary><?php esc_html_e( 'Advanced', 'feedtrix' ); ?></summary>
					<p><label for="ft-endpoint"><?php esc_html_e( 'FeedTrix API URL', 'feedtrix' ); ?></label><br>
						<input type="url" id="ft-endpoint" name="<?php echo esc_attr( self::OPTION . '[endpoint]' ); ?>" value="<?php echo esc_attr( (string) $values['endpoint'] ); ?>" class="regular-text" autocomplete="off"></p>
				</details>
				<?php submit_button(); ?>
			</section>
		</form>
		<?php
	}

	/**
	 * Store keys: the form (before connecting) or masked keys and "Disconnect".
	 *
	 * @param array<string, mixed> $old form values after an error
	 */
	private function render_connection( array $old ): void {
		$post = admin_url( 'admin-post.php' );
		?>
		<section class="feedtrix-card" id="feedtrix-connection">
			<?php if ( $this->is_connected() ) : ?>
				<h2 class="feedtrix-status"><span class="feedtrix-dot"></span><?php esc_html_e( 'Store keys', 'feedtrix' ); ?></h2>
				<?php if ( $this->has_bundled_config() ) : ?>
					<p class="description"><?php esc_html_e( 'This plugin was downloaded from the FeedTrix panel and already contains your store keys.', 'feedtrix' ); ?></p>
				<?php endif; ?>
				<dl class="feedtrix-facts">
					<dt><?php esc_html_e( 'Store Key', 'feedtrix' ); ?></dt><dd><code><?php echo esc_html( self::mask( $this->shop_id(), 8 ) ); ?></code></dd>
					<dt><?php esc_html_e( 'API key', 'feedtrix' ); ?></dt><dd><code><?php echo esc_html( self::mask( $this->api_key(), 0 ) ); ?></code></dd>
				</dl>
				<?php if ( ! $this->has_bundled_config() ) : ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect your store from FeedTrix?', 'feedtrix' ) ); ?>')">
						<input type="hidden" name="action" value="feedtrix_disconnect">
						<?php wp_nonce_field( 'feedtrix_disconnect' ); ?>
						<button class="button button-link-delete" type="submit"><?php esc_html_e( 'Disconnect', 'feedtrix' ); ?></button>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<h2 class="feedtrix-status"><span class="feedtrix-dot is-off"></span><?php esc_html_e( 'Connect your store to FeedTrix', 'feedtrix' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Add this store in the FeedTrix panel, then copy its keys from: Shops → your shop → Integration keys.', 'feedtrix' ); ?>
					<a href="<?php echo esc_url( $this->signup_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'No account yet? Sign up', 'feedtrix' ); ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<input type="hidden" name="action" value="feedtrix_keys">
					<?php wp_nonce_field( 'feedtrix_keys' ); ?>
					<div class="feedtrix-keys">
						<p><label for="ft-store-key"><?php esc_html_e( 'Store Key', 'feedtrix' ); ?></label>
							<input type="text" id="ft-store-key" name="store_key" required pattern="ftx_[a-f0-9]{24}" placeholder="ftx_…" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( (string) ( $old['store_key'] ?? '' ) ); ?>"></p>
						<p><label for="ft-api-key"><?php esc_html_e( 'API key', 'feedtrix' ); ?></label>
							<input type="password" id="ft-api-key" name="api_key" required autocomplete="off" spellcheck="false"></p>
					</div>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect store', 'feedtrix' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}
}
