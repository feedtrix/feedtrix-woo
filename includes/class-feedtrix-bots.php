<?php

defined( 'ABSPATH' ) || exit;

/** Detects AI bots by User-Agent (list kept in sync with infra/docker/nginx/default.conf). */
final class FeedTrix_Bots {

	private const PATTERNS = array(
		'GPTBot'             => 'GPTBot',
		'OAI-SearchBot'      => 'OAI-SearchBot',
		'ChatGPT-User'       => 'ChatGPT-User',
		'PerplexityBot'      => 'PerplexityBot',
		'Perplexity-User'    => 'Perplexity-User',
		'ClaudeBot'          => 'ClaudeBot',
		'Claude-User'        => 'Claude-User',
		'Claude-SearchBot'   => 'Claude-SearchBot',
		'Google-Extended'    => 'Google-Extended',
		'Applebot-Extended'  => 'Applebot-Extended',
		'CCBot'              => 'CCBot',
		'Bytespider'         => 'Bytespider',
		'meta-externalagent' => 'Meta-ExternalAgent',
	);

	public static function detect( string $user_agent ): ?string {
		if ( '' === $user_agent ) {
			return null;
		}
		foreach ( self::PATTERNS as $needle => $name ) {
			if ( false !== stripos( $user_agent, $needle ) ) {
				return $name;
			}
		}

		return null;
	}

	public static function current(): ?string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return self::detect( $ua );
	}
}
