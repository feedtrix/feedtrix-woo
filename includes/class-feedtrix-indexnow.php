<?php

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow verification file: https://shop.example/{key}.txt returns the key.
 * FeedTrix submits changed products and /llms.txt to api.indexnow.org (Bing → ChatGPT Search, Copilot).
 */
final class FeedTrix_IndexNow {

	private const QUERY_VAR = 'feedtrix_indexnow';

	/** @var FeedTrix_Settings */
	private $settings;

	public function __construct( FeedTrix_Settings $settings ) {
		$this->settings = $settings;
	}

	public static function add_rewrite_rule(): void {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical_redirect' ) );
	}

	/** @param string[] $vars */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/** @param string|false $redirect */
	public function no_canonical_redirect( $redirect ) {
		return get_query_var( self::QUERY_VAR ) ? false : $redirect;
	}

	public function serve(): void {
		$requested = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $requested ) {
			return;
		}

		$key = $this->settings->indexnow_key();
		if ( '' === $key || ! hash_equals( $key, $requested ) ) {
			// the rule catches every /{32 hex}.txt; an unknown key is a plain 404
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Not found\n";
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Robots-Tag: noindex' );
		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 32 hex characters
		exit;
	}
}
