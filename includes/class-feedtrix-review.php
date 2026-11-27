<?php

defined( 'ABSPATH' ) || exit;

/**
 * Asks for a WordPress.org review on the FeedTrix screen only (never elsewhere in the dashboard), after a success:
 * at least 50 AI bot visits in 30 days (connected stores, at least 3 days after installation), otherwise 14 days after installation. "Later" asks again in 30 days,
 * "Leave a review" and "I already did" never ask again.
 */
final class FeedTrix_Review {

	public const OPTION  = 'feedtrix_review';
	private const ACTION = 'feedtrix_review';
	private const URL    = 'https://wordpress.org/support/plugin/feedtrix/reviews/#new-post';
	private const DELAY  = 14 * DAY_IN_SECONDS;
	private const SNOOZE = 30 * DAY_IN_SECONDS;
	private const VISITS = 50;

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/** Remembers the first installation time (also for sites updated from an older version). */
	public static function install(): void {
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) ) {
			add_option( self::OPTION, array( 'ask_at' => time() + self::DELAY, 'installed' => time() ), '', false );
		}
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'feedtrix' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$answer = sanitize_key( wp_unslash( $_GET['answer'] ?? '' ) );
		if ( 'later' === $answer ) {
			$state = get_option( self::OPTION );
			update_option( self::OPTION, array( 'ask_at' => time() + self::SNOOZE, 'snoozed' => 1, 'installed' => is_array( $state ) ? (int) ( $state['installed'] ?? 0 ) : 0 ), false );
			wp_safe_redirect( FeedTrix_Settings::page_url() );
			exit;
		}
		update_option( self::OPTION, array( 'done' => true ), false );
		if ( 'review' === $answer ) {
			wp_redirect( self::URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed wordpress.org URL
			exit;
		}
		wp_safe_redirect( FeedTrix_Settings::page_url() );
		exit;
	}

	private static function due(): bool {
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) ) {
			self::install();
			return false;
		}

		if ( ! empty( $state['done'] ) ) {
			return false;
		}
		$snoozed = isset( $state['snoozed'] ) && time() < (int) $state['ask_at'];
		$success = ! $snoozed && time() - (int) ( $state['installed'] ?? 0 ) >= 3 * DAY_IN_SECONDS && self::visits() >= self::VISITS;

		return $success || time() >= (int) ( $state['ask_at'] ?? PHP_INT_MAX );
	}

	/** AI bot visits in 30 days; bot statistics are a feature of connected (paid) stores. */
	private static function visits(): int {
		return FeedTrix_Plugin::instance()->settings->is_connected() && ! FeedTrix_Bot_Tracker::subscription_inactive() ? FeedTrix_Bot_Stats::total() : 0;
	}

	private static function link( string $answer ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&answer=' . $answer ), self::ACTION );
	}

	/** Review card at the top of the FeedTrix screen; mentions what the plugin achieved when the audit has a good score. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::due() ) {
			return;
		}
		$visits = self::visits();
		$audit  = get_option( FeedTrix_Audit::OPTION, array() );
		$score  = is_array( $audit ) && isset( $audit['result']['score'] ) ? (int) $audit['result']['score'] : null;
		?>
		<div class="notice notice-info feedtrix-review">
			<p>
				<strong><?php esc_html_e( 'Is FeedTrix helping your store?', 'feedtrix' ); ?></strong>
				<?php
				if ( $visits >= self::VISITS ) {
					/* translators: %s: number of AI bot visits */
					printf( esc_html__( 'AI bots visited your store %s times in the last 30 days.', 'feedtrix' ), esc_html( number_format_i18n( $visits ) ) );
					echo ' ';
				} elseif ( null !== $score && $score >= 50 ) {
					/* translators: %d: AI readiness score 0-100 */
					printf( esc_html__( 'Your store scores %d/100 in the AI readiness audit.', 'feedtrix' ), (int) $score );
					echo ' ';
				}
				esc_html_e( 'A short review on WordPress.org helps other store owners find the plugin, and it takes a minute.', 'feedtrix' );
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::link( 'review' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review ★★★★★', 'feedtrix' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::link( 'later' ) ); ?>"><?php esc_html_e( 'Maybe later', 'feedtrix' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( self::link( 'done' ) ); ?>"><?php esc_html_e( 'I already did', 'feedtrix' ); ?></a>
			</p>
		</div>
		<?php
	}
}
