<?php
/**
 * Mai_Logger — lightweight logger for WordPress plugins.
 *
 * @version 0.1.0
 *
 * Loaded lazily by Mai_Logger_Bootstrap's autoloader, which selects the
 * newest version registered across all installed plugins.
 *
 * API stability contract:
 * - Public methods are ADDITIVE ONLY. Never rename or remove.
 * - Constructor signature is frozen: ( string $name_or_file ).
 * - If you ever need a true breaking change, fork to a new class name.
 */

defined( 'ABSPATH' ) || exit;

class Mai_Logger {

	const VERSION = '0.1.0';

	/**
	 * Display name used as the prefix on every log line.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Construct.
	 *
	 * Accepts either:
	 * - A plugin slug string ('mai-sportsdataio'), used verbatim.
	 * - A file path (typically __FILE__ from the plugin's main file), in which case
	 *   the slug is derived via plugin_basename( dirname( $value ) ).
	 *
	 * @param string $name_or_file Plugin slug or absolute file path.
	 */
	public function __construct( string $name_or_file ) {
		if ( '' !== $name_or_file && file_exists( $name_or_file ) && function_exists( 'plugin_basename' ) ) {
			$this->name = plugin_basename( dirname( $name_or_file ) );
		} else {
			$this->name = $name_or_file;
		}
	}

	/**
	 * Log an error message. Always logs (even with WP_DEBUG off).
	 */
	public function error( string $message, ...$args ): void {
		$this->log( $message, 'error', ...$args );
	}

	/**
	 * Log a warning. Logs to debug.log when WP_DEBUG is on.
	 */
	public function warning( string $message, ...$args ): void {
		$this->log( $message, 'warning', ...$args );
	}

	/**
	 * Log an info message. Goes to Ray / WP-CLI only — never to debug.log.
	 */
	public function info( string $message, ...$args ): void {
		$this->log( $message, 'info', ...$args );
	}

	/**
	 * Log a success message. Goes to Ray / WP-CLI only — never to debug.log.
	 */
	public function success( string $message, ...$args ): void {
		$this->log( $message, 'success', ...$args );
	}

	/**
	 * Internal log dispatcher.
	 */
	private function log( string $message, string $type, ...$args ): void {
		// Always log errors. Other types only when WP_DEBUG is on.
		if ( 'error' !== $type && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) {
			return;
		}

		$formatted_message = sprintf( '%s [%s]: %s', $this->name, strtoupper( $type ), $message );
		$formatted_args    = $this->format_args( ...$args );
		$formatted_full    = trim( $formatted_message . ' ' . $formatted_args );

		// Ray output whenever logging is active.
		if ( function_exists( 'ray' ) ) {
			/** @disregard P1010 */
			ray( $formatted_message )->label( $this->name );

			if ( ! empty( $args ) ) {
				/** @disregard P1010 */
				ray( ...$args )->label( $this->name );
			}
		}

		// WP-CLI: route to console; do not also write to debug.log.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			switch ( $type ) {
				case 'error':
					/** @disregard P1009 */
					\WP_CLI::error( $formatted_full, false );
					break;
				case 'success':
					/** @disregard P1009 */
					\WP_CLI::success( $formatted_full );
					break;
				case 'warning':
					/** @disregard P1009 */
					\WP_CLI::warning( $formatted_full );
					break;
				default:
					/** @disregard P1009 */
					\WP_CLI::log( $formatted_full );
					break;
			}

			return;
		}

		// Only errors and warnings go to the WP debug log.
		// info/success are dev-only (Ray, WP-CLI) and never pollute production logs.
		if ( in_array( $type, [ 'error', 'warning' ], true ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $formatted_full );
		}
	}

	/**
	 * Format additional arguments for log output.
	 */
	private function format_args( ...$args ): string {
		if ( empty( $args ) ) {
			return '';
		}

		$formatted = [];

		foreach ( $args as $arg ) {
			if ( is_int( $arg ) || is_float( $arg ) ) {
				$formatted[] = (string) $arg;
			} elseif ( is_string( $arg ) ) {
				$formatted[] = $arg;
			} elseif ( is_array( $arg ) || is_object( $arg ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				$formatted[] = print_r( $arg, true );
			} elseif ( is_bool( $arg ) ) {
				$formatted[] = $arg ? 'true' : 'false';
			} elseif ( is_null( $arg ) ) {
				$formatted[] = 'null';
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				$formatted[] = gettype( $arg ) . ': ' . print_r( $arg, true );
			}
		}

		return ! empty( $formatted ) ? "\n" . implode( "\n", $formatted ) : '';
	}
}
