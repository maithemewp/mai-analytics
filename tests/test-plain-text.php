<?php

/**
 * Checks on the plugin's own source text: no em-dash in text people read,
 * and nothing that needs a newer WordPress than the header says.
 */
class Test_Plain_Text extends WP_UnitTestCase {

	/**
	 * The PHP tokens in the plugin's own code, outside comments.
	 *
	 * @param string[] $paths Folders or files, relative to the plugin root.
	 *
	 * @return array<int, array{0: string, 1: int, 2: string}> Each token's text, line and file.
	 */
	private function code_tokens( array $paths ): array {
		$root   = dirname( __DIR__ );
		$tokens = [];

		foreach ( $paths as $path ) {
			$files = is_dir( $root . '/' . $path )
				? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $path, FilesystemIterator::SKIP_DOTS ) )
				: [ new SplFileInfo( $root . '/' . $path ) ];

			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
					if ( ! is_array( $token ) || in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
						continue;
					}

					$tokens[] = [ $token[1], $token[2], substr( $file->getPathname(), strlen( $root ) + 1 ) ];
				}
			}
		}

		return $tokens;
	}

	public function test_no_em_dash_outside_comments(): void {
		$found = [];

		foreach ( $this->code_tokens( [ 'src', 'includes' ] ) as [ $text, $line, $file ] ) {
			if ( str_contains( $text, "\u{2014}" ) ) {
				$found[] = "$file:$line";
			}
		}

		$this->assertSame( [], $found, 'Em-dash found outside a comment.' );
	}

	/**
	 * `%i` in $wpdb->prepare() needs WordPress 6.2 (class-wpdb.php:1442), and the
	 * plugin header says 6.1. On WordPress 6.2 and later it builds the same
	 * query as backtick-quoted names, so only the source can show it.
	 */
	public function test_no_identifier_placeholder(): void {
		$found = [];

		foreach ( $this->code_tokens( [ 'src', 'includes', 'uninstall.php', 'mai-analytics.php' ] ) as [ $text, $line, $file ] ) {
			if ( preg_match( '/(?<!%)%i\b/', $text ) ) {
				$found[] = "$file:$line";
			}
		}

		$this->assertSame( [], $found, '%i found in a query. Quote the trusted name with backticks instead.' );
	}
}
