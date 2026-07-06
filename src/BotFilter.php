<?php

namespace Mai\Analytics;

class BotFilter {

	/**
	 * Generic non-browser HTTP client substrings.
	 *
	 * data/bot-patterns.php is auto-generated from Matomo's device-detector
	 * list (see its "do not edit manually" header) and only covers named
	 * crawlers/bots, not generic command-line or script HTTP clients. Real
	 * page views never come from these UAs, so they're merged in here
	 * instead of hand-editing the generated file. Kept deliberately
	 * conservative (unambiguous, slash-qualified where needed) to avoid
	 * false-positiving on real browser or app traffic.
	 *
	 * @var string[]
	 */
	const GENERIC_HTTP_CLIENT_PATTERNS = [
		'curl/',
		'wget',
		'python-requests',
		'python-urllib',
		'go-http-client',
		'libwww-perl',
		'java/',
		'guzzlehttp',
		'node-fetch',
		'axios/',
		'scrapy',
		'httpclient',
	];

	/**
	 * Checks if a user-agent string belongs to a known bot.
	 *
	 * @param string|null $user_agent The user-agent header value.
	 *
	 * @return bool True if the user-agent is a bot or empty.
	 */
	public static function is_bot( ?string $user_agent ): bool {
		if ( empty( $user_agent ) ) {
			return true;
		}

		foreach ( self::get_patterns() as $pattern ) {
			if ( false !== stripos( $user_agent, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets the list of bot user-agent patterns. Filterable via mai_analytics_bot_patterns.
	 *
	 * @return string[] Array of bot user-agent substring patterns.
	 */
	public static function get_patterns(): array {
		$patterns = require MAI_ANALYTICS_PLUGIN_DIR . 'data/bot-patterns.php';
		$patterns = array_merge( $patterns, self::GENERIC_HTTP_CLIENT_PATTERNS );

		return apply_filters( 'mai_analytics_bot_patterns', $patterns );
	}
}
