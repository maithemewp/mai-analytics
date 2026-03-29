<?php

namespace Mai\Views;

class BotFilter {

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
	 * Gets the list of bot user-agent patterns. Filterable via mai_views_bot_patterns.
	 *
	 * @return string[] Array of bot user-agent substring patterns.
	 */
	public static function get_patterns(): array {
		$patterns = require MAI_VIEWS_PLUGIN_DIR . 'data/bot-patterns.php';

		return apply_filters( 'mai_views_bot_patterns', $patterns );
	}
}
