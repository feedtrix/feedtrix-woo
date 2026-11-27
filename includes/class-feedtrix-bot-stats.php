<?php

defined( 'ABSPATH' ) || exit;

/**
 * Local daily counts of AI bot visits (last 30 days), kept separately from the hourly queue sent to FeedTrix.
 * Counted only in connected stores with an active subscription; shown in a dashboard widget and used for the review request.
 */
final class FeedTrix_Bot_Stats {

	public const OPTION = 'feedtrix_bot_stats';
	private const DAYS  = 30;

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}

	public static function record( string $bot ): void {
		$stats = get_option( self::OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$day   = gmdate( 'Y-m-d' );
		if ( ! isset( $stats[ $day ] ) ) {
			$stats = array_slice( $stats, -( self::DAYS - 1 ), null, true ); // keep the last 30 days
			$stats[ $day ] = array();
		}
		$stats[ $day ][ $bot ] = (int) ( $stats[ $day ][ $bot ] ?? 0 ) + 1;
		update_option( self::OPTION, $stats, false );
	}

	/** @return array<string, int> visits per bot in the last $days days, most active first */
	public static function per_bot( int $days = self::DAYS ): array {
		$stats = get_option( self::OPTION, array() );
		$from  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$out   = array();
		foreach ( is_array( $stats ) ? $stats : array() as $day => $bots ) {
			if ( $day < $from || ! is_array( $bots ) ) {
				continue;
			}
			foreach ( $bots as $bot => $count ) {
				$out[ $bot ] = ( $out[ $bot ] ?? 0 ) + (int) $count;
			}
		}
		arsort( $out );

		return $out;
	}

	public static function total( int $days = self::DAYS ): int {
		return (int) array_sum( self::per_bot( $days ) );
	}

	public function dashboard_widget(): void {
		if ( ! $this->settings->is_connected() || ! $this->settings->bot_tracking_enabled() || FeedTrix_Bot_Tracker::subscription_inactive() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'feedtrix_bot_stats', __( 'FeedTrix: AI bots in your store', 'feedtrix' ), array( $this, 'render_widget' ) );
	}

	public function render_widget(): void {
		$week  = self::per_bot( 7 );
		$month = self::total();
		$max   = $week ? max( $week ) : 0;
		?>
		<div class="feedtrix-widget">
			<p class="feedtrix-widget-total">
				<strong><?php echo esc_html( number_format_i18n( array_sum( $week ) ) ); ?></strong>
				<?php esc_html_e( 'AI bot visits in the last 7 days', 'feedtrix' ); ?>
				<span class="description">
					<?php
					/* translators: %s: number of visits */
					printf( esc_html__( '(%s in 30 days)', 'feedtrix' ), esc_html( number_format_i18n( $month ) ) );
					?>
				</span>
			</p>
			<?php if ( $week ) : ?>
				<ul class="feedtrix-widget-bots">
					<?php foreach ( array_slice( $week, 0, 6, true ) as $bot => $count ) : ?>
						<li><span><?php echo esc_html( (string) $bot ); ?></span><i><em style="width:<?php echo esc_attr( (string) max( 4, round( 100 * $count / $max ) ) ); ?>%"></em></i><b><?php echo esc_html( number_format_i18n( $count ) ); ?></b></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No AI bot visits yet. They usually appear within a few days after /llms.txt is available.', 'feedtrix' ); ?></p>
			<?php endif; ?>
			<p>
				<?php if ( '' !== $this->settings->panel_url() ) : ?>
					<a href="<?php echo esc_url( $this->settings->panel_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Pages read by AI and sales from AI in the FeedTrix panel', 'feedtrix' ); ?> →</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
