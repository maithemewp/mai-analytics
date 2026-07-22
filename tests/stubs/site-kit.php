<?php
/**
 * Minimal stand-ins for the Google Site Kit classes the SiteKit provider depends on.
 *
 * Site Kit is not installed in the test environment, but the provider only
 * reaches it through `class_exists()` plus duck typing, so declaring these three
 * classes makes the whole `get_views()` path drivable without it.
 *
 * IMPORTANT: declaring these makes `SiteKit::has_required_classes()` return true
 * for the entire PHP process. That is safe because `is_available()` and
 * `get_unavailable_reason()` both short-circuit on the undefined
 * GOOGLESITEKIT_VERSION constant first — but never `define()` that constant in a
 * test, as it would leak into every later test in the run.
 *
 * The report/row fakes mirror the real response shape, verified against a live
 * Site Kit 1.181 call: a RunReportResponse exposing getRows(), and Row objects
 * exposing getDimensionValues()/getMetricValues() whose entries expose getValue().
 *
 * @since 1.3.3
 */

namespace Google\Site_Kit {

	class Plugin {

		/**
		 * The instance returned by instance(). Null mirrors Site Kit before bootstrap.
		 *
		 * @var Plugin|null
		 */
		public static $instance = null;

		public static function instance() {
			return self::$instance;
		}

		public function context() {
			return new \stdClass();
		}
	}
}

namespace Google\Site_Kit\Core\Storage {

	class User_Options {

		/**
		 * The user this instance is bound to. Tests assert on it to prove the
		 * module was owner-scoped at construction.
		 *
		 * @var int
		 */
		public $user_id;

		public function __construct( $context, $user_id = 0 ) {
			$this->user_id = (int) $user_id;
		}
	}
}

namespace Google\Site_Kit\Modules {

	class Analytics_4 {

		/**
		 * Queue of values get_data() returns, one per call. A \Throwable in the
		 * queue is thrown instead of returned.
		 *
		 * @var array
		 */
		public static $responses = [];

		/**
		 * Recorded get_data() calls, in order.
		 *
		 * @var array
		 */
		public static $calls = [];

		private $user_options;

		public function __construct( $context, $options = null, $user_options = null ) {
			$this->user_options = $user_options;
		}

		public function get_data( $datapoint, $data = [] ) {
			self::$calls[] = [
				'datapoint' => $datapoint,
				'params'    => $data,
				'owner_id'  => $this->user_options ? $this->user_options->user_id : 0,
			];

			if ( ! self::$responses ) {
				return new \Mai\Analytics\Tests\Fake_Report( [] );
			}

			$next = array_shift( self::$responses );

			if ( $next instanceof \Throwable ) {
				throw $next;
			}

			return $next;
		}

		public static function reset() {
			self::$responses = [];
			self::$calls     = [];
		}
	}
}

namespace Mai\Analytics\Tests {

	class Fake_Value {

		private $value;

		public function __construct( $value ) {
			$this->value = $value;
		}

		public function getValue() {
			return $this->value;
		}
	}

	class Fake_Row {

		private $dimensions;
		private $metrics;

		public function __construct( array $dimensions, array $metrics ) {
			$this->dimensions = $dimensions;
			$this->metrics    = $metrics;
		}

		public function getDimensionValues() {
			return $this->dimensions;
		}

		public function getMetricValues() {
			return $this->metrics;
		}
	}

	class Fake_Report {

		private $rows;

		public function __construct( array $rows ) {
			$this->rows = $rows;
		}

		public function getRows() {
			return $this->rows;
		}

		/**
		 * Builds a report from a simple path => views map.
		 *
		 * @param array $views Map of path to view count.
		 *
		 * @return Fake_Report
		 */
		public static function from( array $views ): Fake_Report {
			$rows = [];

			foreach ( $views as $path => $count ) {
				$rows[] = new Fake_Row(
					[ new Fake_Value( $path ) ],
					[ new Fake_Value( (string) $count ) ]
				);
			}

			return new Fake_Report( $rows );
		}
	}
}
