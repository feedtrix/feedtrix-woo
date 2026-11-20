<?php

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the FeedTrix API. Every request is signed:
 *   X-FeedTrix-Shop:      <shop_id>
 *   X-FeedTrix-Timestamp: <unix time>
 *   X-FeedTrix-Signature: sha256=HMAC_SHA256("<timestamp>.<body>", api_key)
 */
final class FeedTrix_Client {

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array|WP_Error
	 */
	public function post( string $path, array $payload, bool $blocking = false ) {
		$body = (string) wp_json_encode( $payload );

		return wp_remote_post(
			$this->settings->endpoint() . $path,
			array(
				'body'     => $body,
				'timeout'  => $blocking ? 10 : 2,
				'blocking' => $blocking,
				'headers'  => $this->headers( $body ) + array( 'Content-Type' => 'application/json' ),
			)
		);
	}

	/** @return array|WP_Error */
	public function get( string $path, int $timeout = 5 ) {
		return wp_remote_get(
			$this->settings->endpoint() . $path,
			array(
				'timeout' => $timeout,
				'headers' => $this->headers( '' ),
			)
		);
	}

	/** @return array<string, string> */
	private function headers( string $body ): array {
		$timestamp = (string) time();

		return array(
			'User-Agent'           => 'FeedTrix-WordPress/' . FEEDTRIX_VERSION . '; ' . home_url( '/' ),
			'X-FeedTrix-Shop'      => $this->settings->shop_id(),
			'X-FeedTrix-Timestamp' => $timestamp,
			'X-FeedTrix-Signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $this->settings->api_key() ),
		);
	}
}
