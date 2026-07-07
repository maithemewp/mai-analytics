<?php

use Mai\Analytics\BotFilter;

class Test_Bot_Filter extends WP_UnitTestCase {

	public function test_detects_googlebot(): void {
		$this->assertTrue( BotFilter::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
	}

	public function test_detects_gptbot(): void {
		$this->assertTrue( BotFilter::is_bot( 'Mozilla/5.0 AppleWebKit/537.36 GPTBot/1.0' ) );
	}

	public function test_detects_curl(): void {
		$this->assertTrue( BotFilter::is_bot( 'curl/7.68.0' ) );
	}

	public function test_allows_chrome_browser(): void {
		$this->assertFalse( BotFilter::is_bot( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36' ) );
	}

	public function test_allows_safari_mobile(): void {
		$this->assertFalse( BotFilter::is_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1' ) );
	}

	public function test_empty_user_agent_is_bot(): void {
		$this->assertTrue( BotFilter::is_bot( '' ) );
		$this->assertTrue( BotFilter::is_bot( null ) );
	}

	public function test_patterns_are_filterable(): void {
		add_filter( 'mai_analytics_bot_patterns', fn( $p ) => array_merge( $p, [ 'MyCustomBot' ] ) );
		$this->assertTrue( BotFilter::is_bot( 'MyCustomBot/1.0' ) );
	}

	/**
	 * Coverage for the supplementary generic-client patterns (curl is covered by
	 * test_detects_curl). Guards against a typo or accidental removal, and against
	 * the list drifting back toward the ambiguous library UAs we deliberately excluded.
	 *
	 * @dataProvider generic_http_client_provider
	 */
	public function test_detects_generic_http_clients( string $user_agent ): void {
		$this->assertTrue( BotFilter::is_bot( $user_agent ) );
	}

	public static function generic_http_client_provider(): array {
		return [
			'wget'            => [ 'Wget/1.21.3 (linux-gnu)' ],
			'python-requests' => [ 'python-requests/2.31.0' ],
			'python-urllib'   => [ 'Python-urllib/3.9' ],
			'libwww-perl'     => [ 'libwww-perl/6.67' ],
		];
	}
}
