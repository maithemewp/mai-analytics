<?php
/**
 * A minimal WP-CLI stand-in, so CLI commands can run under PHPUnit.
 *
 * The plugin only treats WP-CLI as present when the WP_CLI constant is set,
 * which this file never does, so loading it changes nothing else.
 */

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Records what a command prints. error() throws where WP-CLI would exit.
		 */
		class WP_CLI {
			public static array $lines = [];

			public static array $errors = [];

			public static function add_command( ...$args ): void {}

			public static function colorize( string $text ): string {
				return $text;
			}

			public static function log( string $text ): void {
				self::$lines[] = $text;
			}

			public static function success( string $text ): void {
				self::$lines[] = $text;
			}

			public static function warning( string $text ): void {
				self::$lines[] = $text;
			}

			public static function error( string $text, $exit = true ): void {
				self::$errors[] = [ $text, $exit ];

				// As WP-CLI does: exit unless $exit is false or below 1.
				if ( true === $exit || ( is_int( $exit ) && $exit >= 1 ) ) {
					throw new \RuntimeException( 'WP-CLI exit ' . ( true === $exit ? 1 : $exit ) );
				}
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		function get_flag_value( array $assoc_args, string $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}
}
