/**
 * Run Matomo instance.
 * This file will only be loaded if the PHP tracker properly authenticated.
 *
 * @since 0.1.0
 */
( function() {
	/**
	 * Bail if Matomo is not defined.
	 * This likely means the Matomo Tracking Code is not loaded.
	 */
	if ( 'undefined' === typeof Matomo ) {
		return
	}

	// Setup tracker with url/siteID localized from PHP.
	const tracker = Matomo.getTracker( maiAnalyticsVars.url, maiAnalyticsVars.siteID );
	// const tracker = Matomo.getAsyncTracker( maiAnalyticsVars.url, maiAnalyticsVars.siteID );

	console.log( tracker );

	// Get all elements by query selector.
	var elements = document.querySelectorAll( '.some-class-or-data-attribute' );

	if ( elements.length ) {
		elements.forEach( function( element ) {
			// tracker.doSomethingHere();
		});
	}

} )();