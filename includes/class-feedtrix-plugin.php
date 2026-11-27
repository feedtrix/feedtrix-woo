<?php

defined( 'ABSPATH' ) || exit;

final class FeedTrix_Plugin {

	public const CRON_BOT_HITS = 'feedtrix_send_bot_hits';

	private static ?FeedTrix_Plugin $instance = null;

	public FeedTrix_Settings $settings;

	public static function instance(): FeedTrix_Plugin {
		return self::$instance ??= new self();
	}

	/** Languages bundled in languages/; other regional variants (de_AT, es_MX, fr_CA…) get the base language. */
	private const BUNDLED_LOCALES = array(
		'pl' => 'pl_PL',
		'de' => 'de_DE',
		'es' => 'es_ES',
		'fr' => 'fr_FR',
		'it' => 'it_IT',
	);

	/** FeedTrix API message languages (Accept-Language); the API picks the best available one, English by default. */
	private const API_LANGUAGES = array( 'pl', 'en', 'de', 'es', 'fr', 'it' );

	private function __construct() {
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );

		$this->settings = new FeedTrix_Settings();
		$client         = new FeedTrix_Client( $this->settings );

		$this->settings->register();
		$onboarding = new FeedTrix_Onboarding( $this->settings );
		$onboarding->register();
		$this->settings->set_onboarding( $onboarding );
		( new FeedTrix_Llms_Txt( $this->settings, $client ) )->register();
		( new FeedTrix_Bot_Tracker( $this->settings, $client ) )->register();
		( new FeedTrix_Status( $this->settings, $client ) )->register();
		( new FeedTrix_Purge( $this->settings ) )->register();
		( new FeedTrix_IndexNow( $this->settings ) )->register();
		( new FeedTrix_Audit( $this->settings ) )->register();
		( new FeedTrix_Review() )->register();
		( new FeedTrix_Bot_Stats( $this->settings ) )->register();
		( new FeedTrix_Site_Health( $this->settings ) )->register();

		// a new plugin version may add rewrite rules (e.g. /{key}.txt): flush them once after an update
		add_action( 'init', array( $this, 'maybe_upgrade' ), 99 );

		if ( class_exists( 'WooCommerce' ) ) {
			( new FeedTrix_Webhooks( $this->settings, $client ) )->register();
			( new FeedTrix_Attribution( $this->settings, $client ) )->register();
			( new FeedTrix_Json_Ld( $this->settings, $client ) )->register();
			( new FeedTrix_WebMcp( $this->settings ) )->register(); // works without a FeedTrix account
			FeedTrix_Llms_Local::register();
		} else {
			add_action( 'admin_notices', array( $this, 'notice_woocommerce_missing' ) );
		}
	}

	/**
	 * Translation from translate.wordpress.org (wp-content/languages/plugins) first, then the bundled one.
	 * With two files the first takes precedence and the second fills in missing strings.
	 */
	public static function load_textdomain(): void {
		$locale = determine_locale();
		if ( 'en_US' === $locale ) {
			return;
		}
		load_textdomain( 'feedtrix', WP_LANG_DIR . '/plugins/feedtrix-' . $locale . '.mo', $locale );

		$dir = FEEDTRIX_DIR . 'languages/feedtrix-';
		foreach ( array_unique( array( $locale, self::BUNDLED_LOCALES[ substr( $locale, 0, 2 ) ] ?? $locale ) ) as $candidate ) {
			if ( is_readable( $dir . $candidate . '.mo' ) ) {
				load_textdomain( 'feedtrix', $dir . $candidate . '.mo', $locale );
				return;
			}
		}
	}

	/** Administrator language for the FeedTrix API, e.g. "de" (error messages during sign-up and login). */
	public static function api_language(): string {
		$language = strtolower( substr( get_user_locale(), 0, 2 ) );

		return in_array( $language, self::API_LANGUAGES, true ) ? $language : 'en';
	}

	public function maybe_upgrade(): void {
		if ( FEEDTRIX_VERSION !== get_option( 'feedtrix_version' ) ) {
			flush_rewrite_rules( false );
			update_option( 'feedtrix_version', FEEDTRIX_VERSION, true );
		}
	}

	public static function activate(): void {
		FeedTrix_Llms_Txt::add_rewrite_rule();
		FeedTrix_IndexNow::add_rewrite_rule();
		flush_rewrite_rules();
		FeedTrix_Review::install();

		if ( ! wp_next_scheduled( self::CRON_BOT_HITS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_BOT_HITS );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_BOT_HITS );
		flush_rewrite_rules();
	}

	public function notice_woocommerce_missing(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'FeedTrix: WooCommerce is not active. Product sync is disabled.', 'feedtrix' )
			. '</p></div>';
	}
}
