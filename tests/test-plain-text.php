<?php

/**
 * Text people read carries no em-dash. Code comments are left alone.
 */
class Test_Plain_Text extends WP_UnitTestCase {

	public function test_no_em_dash_outside_comments(): void {
		$root  = dirname( __DIR__ );
		$found = [];

		foreach ( [ 'src', 'includes' ] as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );

			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
					if ( ! is_array( $token ) || in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
						continue;
					}

					if ( str_contains( $token[1], "\u{2014}" ) ) {
						$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . $token[2];
					}
				}
			}
		}

		$this->assertSame( [], $found, 'Em-dash found outside a comment.' );
	}
}
