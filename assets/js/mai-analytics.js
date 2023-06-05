/**
 * Run Matomo instance.
 * This file will only be loaded if the PHP tracker properly authenticated.
 *
 * @since 0.1.0
 */
(function() {
	var _paq = window._paq = window._paq || [];

	// Sets user ID as user email.
	if ( maiAnalyticsVars.userId ) {
		_paq.push( [ 'setUserId', maiAnalyticsVars.userId ] );
	}

	// Adds all custom dimensions passed through PHP. Must be before trackPageView.
	for ( const key in maiAnalyticsVars.dimensions ) {
		_paq.push( [ 'setCustomDimension', key, maiAnalyticsVars.dimensions[ key ] ] );
	}

	_paq.push( [ 'enableLinkTracking' ] );
	_paq.push( [ 'trackPageView' ] );
	_paq.push( [ 'trackVisibleContentImpressions' ] );
	// _paq.push( [ 'trackAllContentImpressions' ] );

	(function() {
		var u = maiAnalyticsVars.trackerUrl;

		_paq.push( [ 'setTrackerUrl', u + 'matomo.php' ] );
		_paq.push( [ 'setSiteId', maiAnalyticsVars.siteId ] );

		var d = document,
			g = d.createElement( 'script' ),
			s = d.getElementsByTagName( 'script' )[0];

		g.async = true;
		g.src   = u + 'matomo.js';
		s.parentNode.insertBefore( g, s );
	})();

	// If we have a page URL and ID.
	if ( maiAnalyticsVars.ajaxUrl && maiAnalyticsVars.nonce && maiAnalyticsVars.type && maiAnalyticsVars.id && maiAnalyticsVars.url && maiAnalyticsVars.current ) {
		// Send ajax request.
		fetch( maiAnalyticsVars.ajaxUrl, {
			method: "POST",
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'Cache-Control': 'no-cache',
			},
			body: new URLSearchParams(
				{
					action: 'mai_analytics_views',
					nonce: maiAnalyticsVars.nonce,
					type: maiAnalyticsVars.type,
					id: maiAnalyticsVars.id,
					url: maiAnalyticsVars.url,
					current: maiAnalyticsVars.current,
				}
			),
		})
		.then(function( response ) {
			if ( ! response.ok ) {
				throw new Error( response.statusText );
			}

			return response.json();
		})
		.then(function( data ) {
		})
		.catch(function( error ) {
			console.log( 'Mai Analytics' );
			console.log( error.name, error.message );
		});
	}
})();