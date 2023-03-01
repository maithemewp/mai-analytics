# Mai Analytics
Track first-party analytics with Matomo.

## Getting Started
The following constants can be overridden in `wp-config.php`:

```
MAI_ANALYTICS
```
`bool`: Must be true for Mai Analytics to be used.

```
MAI_ANALYTICS_SITE_ID
```
`integer`: The site ID.

```
MAI_ANALYTICS_URL
```
`string`: The authentication url. Defaults to `https://analytics.bizbudding.com`.

```
MAI_ANALYTICS_TOKEN
```
`string`: The Matomo analytics token.

```
MAI_ANALYTICS_ADMIN
```
`bool`: Must be true for Mai Analytics to track back end data.

```
MAI_ANALYTICS_DEBUG
```
`bool`: Whether Mai Analytics should log via the console and Spatie Ray, if available.

## Functions
Use `mai_analytics_tracker()` to return the Matomo instance for custom tracking.

```
/**
 * Adds custom tracking.
 *
 * @uses https://developer.matomo.org/api-reference/PHP-Matomo-Tracker
 *
 * @return void
 */
add_action( 'wp_head', function() {
	$analytics = function_exists( 'mai_analytics_tracker' ) ? mai_analytics_tracker() : false;

	// Bail if the Matomo instance is not available of not authenticated.
	if ( ! $analytics ) {
		return;
	}

	// Do your custom tracking with the stored instance.
});
```