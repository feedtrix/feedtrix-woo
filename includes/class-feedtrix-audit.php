<?php

defined( 'ABSPATH' ) || exit;

/**
 * Free AI readiness audit in the plugin dashboard (no FeedTrix account needed).
 *
 * Runs only when the administrator clicks the button: the store's home URL is sent to the public
 * FeedTrix audit API (POST /public/audit), which checks llms.txt, robots.txt, JSON-LD, speed and WebMCP
 * from the outside, like an AI bot. The result is kept in the feedtrix_audit option.
 */
final class FeedTrix_Audit {

	public const OPTION = 'feedtrix_audit';
	public const ACTION = 'feedtrix_audit';

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'feedtrix' ), 403 );
		}
		check_admin_referer( self::ACTION );
		update_option( self::OPTION, $this->run(), false );
		wp_safe_redirect( FeedTrix_Settings::page_url() . '#feedtrix-audit' );
		exit;
	}

	/** @return array<string, mixed> */
	private function run(): array {
		$response = wp_remote_post(
			$this->settings->endpoint() . '/public/audit',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Accept-Language' => FeedTrix_Plugin::api_language(),
					'User-Agent'      => 'FeedTrix-WordPress/' . FEEDTRIX_VERSION,
				),
				'body'    => (string) wp_json_encode( array( 'url' => home_url( '/' ) ) ),
			)
		);
		$time = time();
		if ( is_wp_error( $response ) ) {
			return array( 'time' => $time, 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 429 === $code ) {
			return array( 'time' => $time, 'error' => __( 'Too many audits from this server. Try again in an hour.', 'feedtrix' ) );
		}
		if ( 200 !== $code || ! is_array( $data ) ) {
			return array( 'time' => $time, 'error' => is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', $code ) );
		}

		return array( 'time' => $time, 'result' => $data );
	}

	/** Audit card on the Overview tab: button, score, sections, top fixes and the next step. */
	public static function render( FeedTrix_Settings $settings ): void {
		$saved  = get_option( self::OPTION, array() );
		$saved  = is_array( $saved ) ? $saved : array();
		$result = is_array( $saved['result'] ?? null ) ? $saved['result'] : null;
		$score  = $result ? (int) $result['score'] : null;
		$color  = null === $score ? '#64748b' : ( $score >= 80 ? '#16a34a' : ( $score >= 50 ? '#d97706' : '#dc2626' ) );
		?>
		<section class="feedtrix-card feedtrix-audit" id="feedtrix-audit">
			<div class="feedtrix-audit-head">
				<div>
					<h2><?php esc_html_e( 'Free AI readiness audit', 'feedtrix' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Checks your store from the outside, the way ChatGPT, Perplexity and Claude see it: llms.txt, AI bot access in robots.txt, product structured data, speed and WebMCP tools for AI agents. No account needed.', 'feedtrix' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" class="button <?php echo $result ? '' : 'button-primary'; ?>"><?php echo $result ? esc_html__( 'Run the audit again', 'feedtrix' ) : esc_html__( 'Run the audit', 'feedtrix' ); ?></button>
				</form>
			</div>

			<?php if ( ! empty( $saved['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( (string) $saved['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $result ) : ?>
				<div class="feedtrix-audit-body">
					<div class="feedtrix-score" style="--ftx-score:<?php echo esc_attr( (string) $score ); ?>;--ftx-score-color:<?php echo esc_attr( $color ); ?>">
						<strong><?php echo esc_html( (string) $score ); ?></strong><span>/100</span>
					</div>
					<div class="feedtrix-audit-checks">
						<?php foreach ( (array) ( $result['checks'] ?? array() ) as $check ) : ?>
							<?php $pct = ! empty( $check['max'] ) ? round( 100 * (int) $check['score'] / (int) $check['max'] ) : 0; ?>
							<div class="feedtrix-bar">
								<span><?php echo esc_html( (string) $check['title'] ); ?></span><b><?php echo esc_html( (int) $check['score'] . '/' . (int) $check['max'] ); ?></b>
								<i><em style="width:<?php echo esc_attr( (string) $pct ); ?>%;background:<?php echo esc_attr( $pct >= 80 ? '#16a34a' : ( $pct >= 50 ? '#d97706' : '#dc2626' ) ); ?>"></em></i>
							</div>
						<?php endforeach; ?>
						<div class="feedtrix-bar is-flag">
							<span><?php esc_html_e( 'WebMCP tools for AI agents in the browser', 'feedtrix' ); ?></span>
							<b><?php echo ! empty( $result['webmcp'] ) ? esc_html__( 'Yes', 'feedtrix' ) : esc_html__( 'No', 'feedtrix' ); ?></b>
						</div>
					</div>
				</div>

				<?php foreach ( (array) ( $result['alerts'] ?? array() ) as $alert ) : ?>
					<div class="notice notice-error inline"><p><strong><?php echo esc_html( (string) $alert ); ?></strong></p></div>
				<?php endforeach; ?>

				<?php if ( ! empty( $result['todo'] ) ) : ?>
					<h3><?php esc_html_e( 'What to fix first', 'feedtrix' ); ?></h3>
					<ul class="feedtrix-todo">
						<?php foreach ( (array) $result['todo'] as $item ) : ?>
							<li><span><?php echo esc_html( (string) $item['label'] ); ?></span><b>+<?php echo esc_html( (string) (int) $item['lost'] ); ?></b></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="feedtrix-actions">
					<?php if ( ! $settings->is_connected() && $score < 100 ) : ?>
						<a class="button button-primary feedtrix-signup" href="<?php echo esc_url( $settings->signup_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Fix it with FeedTrix (14 days free)', 'feedtrix' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $result['report_url'] ) && $score > 0 ) : ?>
						<a class="button" href="<?php echo esc_url( (string) $result['report_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full report', 'feedtrix' ); ?></a>
					<?php endif; ?>
					<span class="description">
						<?php
						/* translators: %s: date and time of the audit */
						printf( esc_html__( 'Audit from %s', 'feedtrix' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $saved['time'] ) ) );
						?>
					</span>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
