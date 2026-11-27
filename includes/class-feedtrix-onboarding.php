<?php

defined( 'ABSPATH' ) || exit;

/**
 * Connecting the store to FeedTrix: Store Key + API key copied from the FeedTrix dashboard (the account and store are created there).
 * The request to /plugin/connect-keys is signed with these keys, which confirms they are valid.
 */
final class FeedTrix_Onboarding {

	private const NOTICE = 'feedtrix_onboarding_notice';

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'admin_post_feedtrix_keys', array( $this, 'handle_keys' ) );
		add_action( 'admin_post_feedtrix_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	/** Store URL that FeedTrix compares with the store URL in the dashboard (overridden by the FEEDTRIX_SHOP_URL constant in a test environment). */
	public static function shop_url(): string {
		return untrailingslashit( defined( 'FEEDTRIX_SHOP_URL' ) ? (string) FEEDTRIX_SHOP_URL : home_url() );
	}

	/** Keys copied from the FeedTrix dashboard: a request signed with them confirms they are valid. */
	public function handle_keys(): void {
		$this->guard( 'feedtrix_keys' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard() above
		$store_key = sanitize_text_field( wp_unslash( $_POST['store_key'] ?? '' ) );
		$api_key   = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- secret passed through unchanged
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$old       = array( 'store_key' => $store_key );

		if ( 1 !== preg_match( '/^ftx_[a-f0-9]{24}$/', $store_key ) || '' === $api_key ) {
			$this->redirect( array( 'type' => 'error', 'message' => __( 'Enter the Store Key (ftx_…) and the API key from the FeedTrix panel.', 'feedtrix' ), 'tab' => 'settings', 'old' => $old ) );
		}

		$body      = (string) wp_json_encode( array( 'platform' => 'woocommerce', 'shop_url' => self::shop_url() ) );
		$timestamp = (string) time();
		$this->call(
			$body,
			$old,
			array(
				'X-FeedTrix-Shop'      => $store_key,
				'X-FeedTrix-Timestamp' => $timestamp,
				'X-FeedTrix-Signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $api_key ),
			)
		);
	}

	public function handle_disconnect(): void {
		$this->guard( 'feedtrix_disconnect' );
		$this->settings->save_connection( array( 'shop_id' => '', 'api_key' => '', 'indexnow_key' => '' ), array() );
		$this->redirect( array( 'type' => 'success', 'message' => __( 'Your store has been disconnected from your FeedTrix account.', 'feedtrix' ), 'tab' => 'settings' ) );
	}

	/**
	 * @param array<string, string> $old     form values to show again after an error
	 * @param array<string, string> $headers signature with the store keys
	 */
	private function call( string $body, array $old, array $headers ): void {
		$response = wp_remote_post(
			$this->settings->endpoint() . '/plugin/connect-keys',
			array(
				'timeout' => 30,
				'headers' => $headers + array( 'Content-Type' => 'application/json', 'Accept-Language' => FeedTrix_Plugin::api_language(), 'User-Agent' => 'FeedTrix-WordPress/' . FEEDTRIX_VERSION ),
				'body'    => $body,
			)
		);

		$data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['store_key'] ) ) {
			$message = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : ( is_wp_error( $response ) ? $response->get_error_message() : __( 'Unexpected response from FeedTrix.', 'feedtrix' ) );
			$this->redirect( array( 'type' => 'error', 'message' => $message, 'tab' => 'settings', 'old' => $old ) );
		}

		$this->settings->save_connection(
			array(
				'endpoint'     => (string) $data['endpoint'],
				'shop_id'      => (string) $data['store_key'],
				'api_key'      => (string) $data['api_key'],
				'indexnow_key' => (string) $data['indexnow_key'],
			),
			array(
				'email'     => (string) ( $data['email'] ?? '' ),
				'verified'  => ! empty( $data['verified'] ),
				'panel_url' => (string) ( $data['panel_url'] ?? '' ),
				'account'   => (string) ( $data['account'] ?? '' ),
			)
		);
		FeedTrix_IndexNow::add_rewrite_rule();
		flush_rewrite_rules( false );

		$this->redirect( array( 'type' => 'success', 'message' => __( 'Your store is now connected to your FeedTrix account. We emailed you a link to the panel.', 'feedtrix' ) ) );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'feedtrix' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array<string, mixed> $notice
	 * @return never
	 */
	private function redirect( array $notice ): void {
		set_transient( self::NOTICE . '_' . get_current_user_id(), $notice, 120 );
		wp_safe_redirect( FeedTrix_Settings::page_tab_url( (string) ( $notice['tab'] ?? '' ) ) );
		exit;
	}

	/** @return array<string, mixed>|null */
	public static function take_notice(): ?array {
		$key    = self::NOTICE . '_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}

	/** After activation: one click runs the free audit (nothing is sent before the click), then prompt to connect the store. */
	public function activation_notice(): void {
		// shown only on the Plugins screen, never elsewhere in the dashboard
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $this->settings->is_connected() || ! current_user_can( 'manage_options' ) || ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		if ( ! get_option( FeedTrix_Audit::OPTION ) ) {
			printf(
				'<div class="notice notice-info is-dismissible"><form method="post" action="%s" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:.5em 0"><input type="hidden" name="action" value="%s">%s<span><strong>FeedTrix:</strong> %s</span><button type="submit" class="button button-primary">%s</button></form></div>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( FeedTrix_Audit::ACTION ),
				wp_nonce_field( FeedTrix_Audit::ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core nonce field markup
				esc_html__( 'check in 30 seconds how ChatGPT, Perplexity and Claude see your store. Free, no account needed.', 'feedtrix' ),
				esc_html__( 'Run the free AI audit', 'feedtrix' )
			);
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>FeedTrix:</strong> %s <a class="button button-primary" style="margin-left:8px" href="%s">%s</a></p></div>',
			esc_html__( 'connect your store so that ChatGPT, Perplexity and Claude can learn about your products.', 'feedtrix' ),
			esc_url( FeedTrix_Settings::page_url() ),
			esc_html__( 'Connect store', 'feedtrix' )
		);
	}
}
