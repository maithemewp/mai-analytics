#!/usr/bin/env node
/**
 * End-to-end test of mai-analytics, on a local site, before a release.
 *
 *   NODE_PATH=~/node_modules node bin/e2e.js --base https://example.test --wp /path/to/wordpress
 *
 * Headless, always: a visible browser takes the keyboard from whoever is typing.
 *
 * It writes to the site, so it only runs against a local one. A site whose
 * WordPress folder holds SITE-LOCK.md is shared and handed out by
 * mai-coordinator, so the run refuses it unless MAI_AUTH_SITE_OK=1 says the
 * site was claimed.
 *
 * What it changes, and how it puts each thing back:
 *   - Option rows. Before the first change it saves every option row named
 *     mai_analytics_*, _transient_mai_analytics_* and
 *     _transient_timeout_mai_analytics_*, plus active_plugins and
 *     recently_activated. At the end it writes each saved row back byte for
 *     byte and deletes every mai_analytics row the run created.
 *   - mai-analytics itself. A plugin that was inactive is activated for the run
 *     and deactivated at the end.
 *   - One must-use file, mu-plugins/e2e-analytics-<run>.php. It turns the
 *     tracker on for requests that carry this run's e2e_analytics cookie (the
 *     tracker is off on non-production sites) and registers a public post
 *     type, e2e_analytics, so a post type archive can be counted. Teardown
 *     deletes it.
 *   - Users, posts, terms and views of its own, all named
 *     e2e-analytics-<run>-*. Teardown deletes them, and the views go with them.
 *   - The buffer table. The run refuses to start unless it is empty, and
 *     empties it at the end.
 *   - Cron. Teardown clears the four mai_analytics hooks and puts back any
 *     that were scheduled before the run.
 *   - A fake Matomo, a server this script runs on localhost (--fake-port,
 *     default 47820) for the provider scenarios. Matomo settings point at it
 *     only during those scenarios.
 *
 * Nothing else is touched. The one exception is a plugin behaviour the run
 * cannot avoid: the health check counts a view of the site's first published
 * post. That buffer row goes with the buffer, and teardown deletes any view
 * meta a sync wrote on that post.
 *
 * Not covered here: uninstall.php (it would delete the site's own
 * mai_analytics options and table; tests/test-uninstall.php covers it), Site
 * Kit and Jetpack (they need a connected account; the unit tests cover them),
 * `wp mai-analytics seed` (it picks random real posts), `update-bots` (it
 * fetches from GitHub) and the REST warm route in provider mode (it walks every
 * post on the site). Each prints a SKIP line, which is never counted as a pass.
 *
 * One run per site at a time. The saved rows go in a marker file in
 * ~/.cache/mai-analytics-e2e, named from the site's WordPress folder, and it
 * stays until cleanup finished. Any run that finds it refuses to start. A run
 * also refuses to start when the site still has users, posts, terms or a
 * must-use file named e2e-analytics-*, or when the buffer table or any view
 * meta (mai_views, mai_views_web, mai_views_app, mai_trending,
 * mai_views_synced_at) is not empty.
 *
 * A stop (Ctrl-C, SIGTERM, SIGHUP) closes the browser, lets the running
 * scenario unwind, and then cleans up. From the stop on, nothing but the
 * cleanup writes to the site. Every wp command runs in its own process group,
 * so a Ctrl-C in the terminal reaches only this script.
 *
 * Needs:
 *   - mai-analytics in the site's plugins folder, as mai-analytics/.
 *   - Playwright, found through NODE_PATH, with Chrome installed (it runs
 *     Chrome's headless mode through channel "chrome").
 *   - WP-CLI on PATH. --wp is the WordPress root it runs in.
 *   - Node 22.19 or newer, to trust the local site's certificate.
 *
 * Optional:
 *   --only a,b           Run only the named scenarios (see SCENARIOS below).
 *   --out <dir>          Where failure screenshots go. Default /tmp/mai-analytics-e2e.
 *   --fake-port <n>      The fake Matomo's port on localhost. Default 47820.
 *   --signal-after <ms>  Send this run a SIGINT that many milliseconds after
 *                        setup, to prove a stopped run still cleans up.
 *
 * Prints one PASS, FAIL, or SKIP line per check and a summary. Exits 1 on any
 * failure, 2 when it refuses to start, and 130 when stopped by a signal.
 */
const { execFileSync, spawnSync } = require( 'child_process' );
const crypto = require( 'crypto' );
const fs = require( 'fs' );
const http = require( 'http' );
const os = require( 'os' );
const path = require( 'path' );
const tls = require( 'tls' );

// The fake Matomo runs in a child process of its own, started by this same
// file, so it keeps answering while this script waits on a wp command.
if ( process.env.MAI_ANALYTICS_E2E_FAKE_PORT ) {
	runFake( Number( process.env.MAI_ANALYTICS_E2E_FAKE_PORT ) );
	return;
}

// The fake Matomo's process. It answers API.getBulkRequest the way Matomo
// does, from counts per path, and logs every request. POST /__e2e sets its
// state ({ reset, counts, failFrom }), and any /__e2e request answers the log.
// From request number `failFrom` on it answers 500. It exits once its parent
// has gone. Self-contained, because it runs before the rest of this file.
function runFake( port ) {
	const parent = process.ppid;
	const state = { counts: {}, failFrom: null, requests: [] };
	const key = ( url ) => {
		try {
			const u = new URL( url );
			return u.pathname + u.search;
		} catch ( e ) {
			return String( url );
		}
	};
	const handler = ( req, res ) => {
		let body = '';
		req.on( 'data', ( c ) => ( body += c ) );
		req.on( 'end', () => {
			if ( req.url.startsWith( '/__e2e' ) ) {
				if ( 'POST' === req.method ) {
					const c = JSON.parse( body || '{}' );
					if ( c.reset ) {
						state.counts = c.counts || {};
						state.requests = [];
						state.failFrom = null;
					}
					if ( 'failFrom' in c ) {
						state.failFrom = c.failFrom;
					}
				}
				res.writeHead( 200, { 'Content-Type': 'application/json' } );
				res.end( JSON.stringify( { requests: state.requests } ) );
				return;
			}
			const p = new URLSearchParams( body );
			const subs = [];
			for ( const [ k, v ] of p ) {
				const m = /^urls\[(\d+)\]$/.exec( k );
				if ( m ) {
					subs[ Number( m[ 1 ] ) ] = Object.fromEntries( new URLSearchParams( v ) );
				}
			}
			state.requests.push( {
				url: req.url,
				method: p.get( 'method' ),
				idSite: p.get( 'idSite' ),
				token: p.get( 'token_auth' ),
				subs,
			} );
			if ( null !== state.failFrom && state.requests.length >= state.failFrom ) {
				res.writeHead( 500, { 'Content-Type': 'text/html' } );
				res.end( '<h1>e2e fake Matomo is down</h1>' );
				return;
			}
			const out = subs.map( ( s ) => {
				const c = state.counts[ key( s.pageUrl ) ] || {};
				const v = 'year' === s.period ? c.all_time || 0 : c.trending || 0;
				return v ? { e2e: [ { nb_visits: v } ] } : [];
			} );
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( out ) );
		} );
	};
	// 127.0.0.1 and ::1, since WordPress may resolve localhost to either.
	for ( const host of [ '127.0.0.1', '::1' ] ) {
		http.createServer( handler )
			.on( 'error', ( e ) => {
				if ( '127.0.0.1' === host ) {
					console.error( 'the fake Matomo could not listen on 127.0.0.1:' + port + ' (' + e.code + ')' );
					process.exit( 3 );
				}
			} )
			.listen( port, host );
	}
	setInterval( () => {
		try {
			process.kill( parent, 0 );
		} catch ( e ) {
			process.exit( 0 );
		}
	}, 1000 );
}

const { chromium } = require(
	require.resolve( 'playwright', {
		paths: [
			...( process.env.NODE_PATH || '' )
				.split( path.delimiter )
				.filter( Boolean ),
			__dirname,
		],
	} )
);

const args = Object.fromEntries(
	process.argv
		.slice( 2 )
		.reduce(
			( pairs, v, i, all ) =>
				v.startsWith( '--' )
					? [ ...pairs, [ v.slice( 2 ), all[ i + 1 ] ] ]
					: pairs,
			[]
		)
);
for ( const need of [ 'base', 'wp' ] ) {
	if ( ! args[ need ] ) {
		console.error(
			'missing --' +
				need +
				'  (--base is the local site URL, --wp is the WordPress root for WP-CLI)'
		);
		process.exit( 2 );
	}
}

const LOCAL_HOST = /(^localhost$|^127\.0\.0\.1$|\.test$|\.local$|\.localhost$)/;
let baseHost = '';
try {
	baseHost = new URL( args.base ).hostname;
} catch ( e ) {
	baseHost = '';
}
if ( ! LOCAL_HOST.test( baseHost ) ) {
	console.error(
		'refusing to run against ' +
			args.base +
			': --base must be a local site (localhost, 127.0.0.1, or a .test, .local or .localhost host). This script writes to the site.'
	);
	process.exit( 2 );
}

if (
	fs.existsSync( path.join( args.wp, 'SITE-LOCK.md' ) ) &&
	'1' !== process.env.MAI_AUTH_SITE_OK
) {
	console.error(
		'refusing to run in ' +
			args.wp +
			': it holds SITE-LOCK.md, so the site is shared and handed out by mai-coordinator. Claim it from mai-coordinator first, then run again with MAI_AUTH_SITE_OK=1.'
	);
	process.exit( 2 );
}

if ( 'function' !== typeof tls.setDefaultCACertificates ) {
	console.error(
		'needs Node 22.19 or newer (tls.setDefaultCACertificates), to trust the local site certificate'
	);
	process.exit( 2 );
}
tls.setDefaultCACertificates( [
	...tls.getCACertificates( 'default' ),
	...tls.getCACertificates( 'system' ),
] );

const BASE = args.base.replace( /\/+$/, '' );
const OUT = args.out || '/tmp/mai-analytics-e2e';
const ONLY = args.only ? args.only.split( ',' ).map( ( s ) => s.trim() ) : null;
const FAKE_PORT = parseInt( args[ 'fake-port' ] || '47820', 10 );
const RUN = Date.now().toString( 36 );
const PREFIX = 'e2e-analytics-' + RUN + '-';
const PLUGIN = 'mai-analytics/mai-analytics.php';
const CPT = 'e2e_analytics';
const HOOKS = [
	'mai_analytics_cron_sync',
	'mai_analytics_provider_catchup',
	'mai_analytics_prune_trending',
	'mai_analytics_prune_trending_now',
];
const META_KEYS = [
	'mai_views',
	'mai_views_web',
	'mai_views_app',
	'mai_trending',
	'mai_views_synced_at',
];
// Option rows saved before the run and put back after it: these patterns
// (LIKE, with `_` escaped) and these names.
const OPTION_LIKE = [
	'mai\\_analytics\\_%',
	'\\_transient\\_mai\\_analytics\\_%',
	'\\_transient\\_timeout\\_mai\\_analytics\\_%',
];
const FIXED_OPTIONS = [ 'active_plugins', 'recently_activated' ];
const UA =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const BOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const PASS = 'E2e-pass-' + RUN + '!';

let WP_REAL = '';
try {
	WP_REAL = fs.realpathSync( args.wp );
} catch ( e ) {
	console.error( 'no WordPress folder at ' + args.wp + ' (--wp)' );
	process.exit( 2 );
}
const MARKER_DIR = path.join( os.homedir(), '.cache', 'mai-analytics-e2e' );
const MARKER = path.join(
	MARKER_DIR,
	'mai-analytics-e2e-' +
		crypto.createHash( 'sha1' ).update( WP_REAL ).digest( 'hex' ).slice( 0, 12 ) +
		'.json'
);

const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

const results = [];
const skipped = [];
const notes = [];
const shots = [];
let scenarioName = '';
let currentPage = null;

function check( name, ok, detail ) {
	const label = ( scenarioName ? scenarioName + ': ' : '' ) + name;
	results.push( { name: label, ok: !! ok } );
	console.log(
		( ok ? 'PASS  ' : 'FAIL  ' ) +
			label +
			( ! ok && undefined !== detail
				? '  (got: ' + String( detail ).slice( 0, 300 ) + ')'
				: '' )
	);
	if ( ! ok && currentPage && ! currentPage.isClosed() ) {
		const file = path.join( OUT, 'fail-' + RUN + '-' + results.length + '.png' );
		shots.push(
			currentPage
				.screenshot( { path: file, fullPage: true, timeout: 10000 } )
				.then( () => console.log( '      screenshot: ' + file ) )
				.catch( () => {} )
		);
	}
	return !! ok;
}

const equal = ( name, got, want ) =>
	check( name, got === want, JSON.stringify( got ) );

function skip( why ) {
	skipped.push( scenarioName + ': ' + why );
	console.log( 'SKIP  ' + scenarioName + ': ' + why );
}

// Something the coordinator should hear about that is not a pass or a fail.
function note( text ) {
	notes.push( scenarioName + ': ' + text );
	console.log( 'NOTE  ' + scenarioName + ': ' + text );
}

// ---------------------------------------------------------------------------
// Stopping
// ---------------------------------------------------------------------------

let stopping = false;
let tearing = null;
let putting = false;

function gate() {
	if ( ( stopping || tearing ) && ! putting ) {
		throw new Error(
			'stopped: the run is cleaning up, so nothing more goes to the site'
		);
	}
}

function asTeardown( fn ) {
	putting = true;
	try {
		return fn();
	} finally {
		putting = false;
	}
}

// ---------------------------------------------------------------------------
// WP-CLI
// ---------------------------------------------------------------------------

function cleanErr( text ) {
	return String( text || '' )
		.split( '\n' )
		.filter( ( l ) => ! /^(PHP )?Deprecated/.test( l ) && l.trim() )
		.slice( -5 )
		.join( '\n' );
}

function wpRun( argv ) {
	gate();
	try {
		return execFileSync( 'wp', argv, {
			cwd: args.wp,
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			encoding: 'utf8',
			detached: true,
		} ).trim();
	} catch ( e ) {
		throw new Error( 'wp ' + argv[ 0 ] + ' failed: ' + cleanErr( e.stderr ) );
	}
}

// Runs a wp command that may fail on purpose. Answers the exit code, stdout and
// stderr (where WP-CLI writes warnings and errors) instead of throwing.
function wpTry( argv ) {
	gate();
	const r = spawnSync( 'wp', argv, {
		cwd: args.wp,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		encoding: 'utf8',
		detached: true,
	} );
	return {
		code: r.status ?? 1,
		out: String( r.stdout || '' ).trim(),
		err: String( r.stderr || '' )
			.split( '\n' )
			.filter( ( l ) => l.trim() && ! /^(PHP )?(Deprecated|Notice)/.test( l ) )
			.join( '\n' ),
	};
}

// Runs PHP with `$d` set to the given data, passed as base64 JSON so no value
// ever needs shell or PHP quoting.
function php( code, data = null ) {
	const payload = Buffer.from( JSON.stringify( data ) ).toString( 'base64' );
	return wpRun( [
		'eval',
		'$d = json_decode( base64_decode( "' + payload + '" ), true ); ' + code,
	] );
}

const phpJson = ( code, data ) => JSON.parse( php( code, data ) || 'null' );

// ---------------------------------------------------------------------------
// Option rows
// ---------------------------------------------------------------------------

// Every row named in $d["names"] or matching a pattern in $d["like"], as
// { name: { option_value (base64), autoload } }, null for a name with no row.
const READ_ROWS =
	'global $wpdb; $names = $d["names"]; foreach ( $d["like"] as $l ) { $names = array_merge( $names, $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $l ) ) ); }' +
	' $out = []; foreach ( array_unique( $names ) as $n ) { $r = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $n ), ARRAY_A ); $out[ $n ] = $r ? [ "option_value" => base64_encode( $r["option_value"] ), "autoload" => $r["autoload"] ] : null; }' +
	' echo wp_json_encode( (object) $out );';

// Writes rows straight back. `$d` is { name: row }, null for a row to delete.
const WRITE_ROWS =
	'global $wpdb; foreach ( $d as $n => $row ) { if ( null === $row ) { $wpdb->delete( $wpdb->options, [ "option_name" => $n ] ); } else { $row["option_value"] = base64_decode( $row["option_value"] );' +
	' $has = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $n ) );' +
	' if ( $has ) { $wpdb->update( $wpdb->options, $row, [ "option_name" => $n ] ); } else { $wpdb->insert( $wpdb->options, $row + [ "option_name" => $n ] ); } }' +
	' wp_cache_delete( $n, "options" ); } wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );';

const readRows = () =>
	phpJson( READ_ROWS, { names: FIXED_OPTIONS, like: OPTION_LIKE } ) || {};

// The saved rows and everything else the marker needs. Undefined until saved.
let saved;
let pluginWasActive = false;
let cronAtStart = [];
let logStart = { file: '', size: 0 };
let muFile = '';

function markerJson() {
	return JSON.stringify(
		{
			plugin: 'mai-analytics',
			wp: WP_REAL,
			run: RUN,
			prefix: PREFIX,
			pid: process.pid,
			started: new Date().toISOString(),
			pluginWasActive,
			muFile,
			cron: cronAtStart,
			options: saved,
		},
		null,
		'\t'
	);
}

function writeMarker() {
	fs.mkdirSync( MARKER_DIR, { recursive: true } );
	try {
		fs.writeFileSync( MARKER, markerJson(), { flag: 'wx' } );
	} catch ( e ) {
		if ( 'EEXIST' === e.code ) {
			refuseMarker();
		}
		throw e;
	}
}

function refuseMarker() {
	let left = {};
	try {
		left = JSON.parse( fs.readFileSync( MARKER, 'utf8' ) ) || {};
	} catch ( e ) {
		// An unreadable marker still stops the run.
	}
	console.error(
		'refusing to run: another mai-analytics e2e run on this site may still be going, or one was killed before it put the site back. Its marker is ' +
			MARKER +
			' (run ' +
			( left.run || 'unknown' ) +
			( left.pid ? ', process ' + left.pid : '' ) +
			( left.started ? ', started ' + left.started : '' ) +
			').\n\nIf that run is still going, wait for it to finish. It deletes the marker when its cleanup passes.\n\n' +
			recoverySteps( left )
	);
	process.exit( 2 );
}

// How to put the site back by hand after a run that did not finish cleanup.
function recoverySteps( m ) {
	const prefix = m.prefix || 'e2e-analytics-<run>-';
	return [
		'To put the site back by hand:',
		'  1. Delete the must-use file ' +
			( m.muFile || 'mu-plugins/e2e-analytics-<run>.php' ) +
			' if it is there.',
		'  2. Delete every post of any type whose slug starts ' +
			prefix +
			', every term whose slug starts ' +
			prefix +
			', and every user whose login or email starts ' +
			prefix +
			' (wp user delete <id> --yes, one at a time).',
		'  3. ' +
			( false === m.pluginWasActive
				? 'mai-analytics was inactive before the run: wp plugin deactivate mai-analytics.'
				: 'mai-analytics was active before the run (or the marker does not say): leave it active.' ),
		'  4. Empty the buffer table, which was empty before the run: DELETE FROM <prefix>mai_analytics_buffer.',
		'  5. Clear the cron hooks ' +
			HOOKS.join( ', ' ) +
			' (wp cron event delete <hook>) and schedule again any listed under "cron" in the marker.',
		'  6. Write the saved option rows back byte for byte, and delete every other mai_analytics_* option and transient. The rows are under "options" in the marker, each option_value base64-encoded, null for a row that did not exist:',
		"     wp --path='" +
			WP_REAL +
			"' eval '$saved = json_decode( file_get_contents( \"" +
			MARKER +
			"\" ), true )[\"options\"]; global $wpdb; $now = $wpdb->get_col( \"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \\x27mai\\\\_analytics\\\\_%\\x27 OR option_name LIKE \\x27\\\\_transient\\\\_mai\\\\_analytics\\\\_%\\x27 OR option_name LIKE \\x27\\\\_transient\\\\_timeout\\\\_mai\\\\_analytics\\\\_%\\x27\" ); $d = $saved; foreach ( $now as $n ) { if ( ! array_key_exists( $n, $d ) ) { $d[ $n ] = null; } } " +
			WRITE_ROWS +
			"'",
		'  7. Delete ' + MARKER + ', then run again.',
	].join( '\n' );
}

// Puts the saved rows back and deletes mai_analytics rows the run created.
// Answers the names that do not match afterwards.
function restoreRows() {
	const now = readRows();
	const write = { ...saved };
	for ( const name of Object.keys( now ) ) {
		if ( ! ( name in saved ) ) {
			write[ name ] = null;
		}
	}
	php( WRITE_ROWS, write );
	const after = readRows();
	const wrong = [];
	for ( const name of Object.keys( saved ) ) {
		if ( JSON.stringify( after[ name ] ?? null ) !== JSON.stringify( saved[ name ] ) ) {
			wrong.push( name );
		}
	}
	for ( const name of Object.keys( after ) ) {
		if ( ! ( name in saved ) && null !== after[ name ] ) {
			wrong.push( name + ' (made by the run)' );
		}
	}
	return wrong;
}

// Writes one saved row back now, mid-run.
function restoreOne( name ) {
	php( WRITE_ROWS, { [ name ]: saved[ name ] ?? null } );
	const now = phpJson( READ_ROWS, { names: [ name ], like: [] } );
	return JSON.stringify( now[ name ] ?? null ) === JSON.stringify( saved[ name ] ?? null );
}

// ---------------------------------------------------------------------------
// Site state helpers
// ---------------------------------------------------------------------------

// The mai_analytics events in the cron array, as [ hook, timestamp, schedule ].
const cronEvents = () =>
	phpJson(
		'$out = []; foreach ( (array) _get_cron_array() as $ts => $hooks ) { foreach ( $hooks as $hook => $events ) { if ( in_array( $hook, $d, true ) ) { foreach ( $events as $e ) { $out[] = [ $hook, (int) $ts, $e["schedule"] ?: "" ]; } } } } echo wp_json_encode( $out );',
		HOOKS
	);

const nextScheduled = ( hook ) =>
	Number( php( 'echo (int) wp_next_scheduled( $d );', hook ) );

// Runs a cron hook the way wp-cron.php does: a recurring event is moved to its
// next run first, a single event is taken off the schedule, then the hook
// fires. DISABLE_WP_CRON may be set, so no event fires on its own. With
// `backoffZero` the provider error backoff is 0 for this run only, as an
// expired backoff would be.
function runCron( hook, { backoffZero = false } = {} ) {
	return phpJson(
		'$hook = $d["hook"]; if ( $d["zero"] ) { add_filter( "mai_analytics_provider_error_backoff", "__return_zero" ); }' +
			' $e = wp_get_scheduled_event( $hook ); $a = $e ? $e->args : [];' +
			' if ( $e ) { if ( $e->schedule ) { wp_reschedule_event( $e->timestamp, $e->schedule, $hook, $a ); } wp_unschedule_event( $e->timestamp, $hook, $a ); }' +
			' do_action_ref_array( $hook, $a ); echo wp_json_encode( [ "had_event" => (bool) $e ] );',
		{ hook, zero: backoffZero }
	);
}

const opt = ( name ) =>
	phpJson( 'echo wp_json_encode( get_option( $d, null ) );', name );

const setOpt = ( name, value ) =>
	php( 'update_option( $d["n"], $d["v"], false );', { n: name, v: value } );

// The five view meta values of one object, null for a key with no row. Reads
// the raw rows, since the plugin registers the keys with a default of 0
// (src/Meta.php), which get_post_meta() would answer for a missing row.
const metaOf = ( type, id ) =>
	phpJson(
		'$o = []; foreach ( $d["keys"] as $k ) { $v = get_metadata_raw( $d["type"], (int) $d["id"], $k, true ); $o[ $k ] = null === $v ? null : (int) $v; } echo wp_json_encode( $o );',
		{ type, id, keys: META_KEYS }
	);

const setMeta = ( type, id, values ) =>
	php(
		'$f = [ "post" => "update_post_meta", "term" => "update_term_meta", "user" => "update_user_meta" ][ $d["type"] ]; foreach ( $d["v"] as $k => $v ) { $f( (int) $d["id"], $k, (string) $v ); }',
		{ type, id, v: values }
	);

// The views an object shows: [ views, web, app, trending ], each 0 when absent.
const counts = ( type, id ) => {
	const m = metaOf( type, id );
	return [
		m.mai_views || 0,
		m.mai_views_web || 0,
		m.mai_views_app || 0,
		m.mai_trending || 0,
	];
};

const sameList = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

const BUFFER_TABLE = 'global $wpdb; $t = $wpdb->prefix . "mai_analytics_buffer";';

// Buffer rows grouped by object, key and source.
const bufferRows = () =>
	phpJson(
		BUFFER_TABLE +
			' echo wp_json_encode( $wpdb->get_results( "SELECT object_id, object_type, object_key, source, COUNT(*) AS n FROM $t GROUP BY object_id, object_type, object_key, source", ARRAY_A ) );'
	).map( ( r ) => ( { ...r, object_id: Number( r.object_id ), n: Number( r.n ) } ) );

const rowsFor = ( rows, type, id, source, key = '' ) =>
	rows
		.filter(
			( r ) =>
				r.object_type === type &&
				r.object_id === id &&
				r.source === source &&
				r.object_key === key
		)
		.reduce( ( s, r ) => s + r.n, 0 );

const bufferCount = () =>
	Number( php( BUFFER_TABLE + ' echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );' ) );

// Inserts buffer rows. Each is { id, type, key, source, ago } with `ago` in
// seconds. `noKey` leaves object_key out of the insert, as 1.3.7's seed did.
function insertRows( rows, { noKey = false } = {} ) {
	const n = Number(
		php(
			BUFFER_TABLE +
				' $n = 0; foreach ( $d["rows"] as $r ) { $row = [ "object_id" => (int) $r["id"], "object_type" => $r["type"], "viewed_at" => gmdate( "Y-m-d H:i:s", time() - (int) $r["ago"] ), "source" => $r["source"] ]; if ( ! $d["noKey"] ) { $row["object_key"] = $r["key"] ?? ""; } $n += (int) $wpdb->insert( $t, $row ); } echo $n;',
			{ rows, noKey }
		)
	);
	if ( n !== rows.length ) {
		throw new Error( 'inserted ' + n + ' of ' + rows.length + ' buffer rows' );
	}
}

// Rows of any view meta key anywhere on the site, up to ten of each table.
const viewMetaLeft = () =>
	phpJson(
		'global $wpdb; $in = "\'" . implode( "\',\'", $d ) . "\'"; $out = [];' +
			' foreach ( [ "post" => [ $wpdb->postmeta, "post_id" ], "term" => [ $wpdb->termmeta, "term_id" ], "user" => [ $wpdb->usermeta, "user_id" ] ] as $type => [ $table, $col ] ) { $n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE meta_key IN ($in)" ); if ( $n ) { $out[ $type ] = [ "count" => $n, "rows" => $wpdb->get_results( "SELECT $col AS id, meta_key FROM $table WHERE meta_key IN ($in) LIMIT 10", ARRAY_A ) ]; } }' +
			' echo wp_json_encode( (object) $out );',
		META_KEYS
	);

// ---------------------------------------------------------------------------
// Users, posts and terms the run makes
// ---------------------------------------------------------------------------

const madeUsers = new Set();
const madePosts = new Set();
const madeTerms = [];
// The post the health check counts a view of, once a check ran.
let healthPostId = 0;

function createUser( tag, role = 'subscriber' ) {
	const id = Number(
		php(
			'$id = wp_insert_user( [ "user_login" => $d["login"], "user_email" => $d["login"] . "@example.com", "user_pass" => $d["pass"], "role" => $d["role"], "display_name" => $d["login"] ] ); echo is_wp_error( $id ) ? 0 : $id;',
			{ login: PREFIX + tag, pass: PASS, role }
		)
	);
	if ( ! id ) {
		throw new Error( 'could not create user ' + tag );
	}
	madeUsers.add( id );
	return {
		id,
		login: PREFIX + tag,
		url: php( 'echo get_author_posts_url( (int) $d );', id ),
	};
}

// A post of the given type, published unless `status` says otherwise.
function createPost( tag, { type = 'post', status = 'publish', author = 0, terms = [] } = {} ) {
	const made = phpJson(
		'$id = wp_insert_post( [ "post_type" => $d["type"], "post_status" => $d["status"], "post_title" => $d["slug"], "post_name" => $d["slug"], "post_content" => "<p>Mai Analytics e2e.</p>", "post_author" => (int) $d["author"] ], true );' +
			' if ( is_wp_error( $id ) ) { echo wp_json_encode( [ "id" => 0, "error" => $id->get_error_message() ] ); return; }' +
			' if ( $d["terms"] ) { wp_set_object_terms( $id, array_map( "intval", $d["terms"] ), "category" ); }' +
			' echo wp_json_encode( [ "id" => $id, "url" => get_permalink( $id ) ] );',
		{ type, status, slug: PREFIX + tag, author, terms }
	);
	if ( ! made.id ) {
		throw new Error( 'could not create ' + type + ' ' + tag + ': ' + made.error );
	}
	madePosts.add( made.id );
	return { id: made.id, url: made.url };
}

// Many published posts of the e2e post type at once, inserted straight into the
// posts table so no other plugin's save hooks run for each. Answers them in ID
// order.
function createBulkPosts( count, author ) {
	const ids = phpJson(
		'global $wpdb; $ids = []; $now = current_time( "mysql" ); $gmt = current_time( "mysql", true );' +
			' for ( $i = 1; $i <= (int) $d["n"]; $i++ ) { $slug = $d["prefix"] . "bulk-" . $i; $ok = $wpdb->insert( $wpdb->posts, [ "post_author" => (int) $d["author"], "post_date" => $now, "post_date_gmt" => $gmt, "post_modified" => $now, "post_modified_gmt" => $gmt, "post_content" => "", "post_title" => $slug, "post_excerpt" => "", "post_status" => "publish", "post_name" => $slug, "post_type" => $d["type"], "to_ping" => "", "pinged" => "", "post_content_filtered" => "", "comment_status" => "closed", "ping_status" => "closed" ] ); if ( $ok ) { $ids[] = (int) $wpdb->insert_id; } }' +
			' echo wp_json_encode( $ids );',
		{ n: count, prefix: PREFIX, author, type: CPT }
	);
	ids.forEach( ( id ) => madePosts.add( id ) );
	if ( ids.length !== count ) {
		throw new Error( 'made ' + ids.length + ' of ' + count + ' bulk posts' );
	}
	const urls = phpJson(
		'echo wp_json_encode( array_map( "get_permalink", array_map( "intval", $d ) ) );',
		ids
	);
	return ids.map( ( id, i ) => ( { id, url: urls[ i ] } ) );
}

function createTerm( tag ) {
	const made = phpJson(
		'$t = wp_insert_term( $d, "category", [ "slug" => $d ] ); if ( is_wp_error( $t ) ) { echo wp_json_encode( [ "id" => 0 ] ); return; } echo wp_json_encode( [ "id" => (int) $t["term_id"], "url" => get_term_link( (int) $t["term_id"] ) ] );',
		PREFIX + tag
	);
	if ( ! made.id ) {
		throw new Error( 'could not create term ' + tag );
	}
	madeTerms.push( made.id );
	return made;
}

const archiveUrl = () => php( 'echo get_post_type_archive_link( $d );', CPT );

// The path a provider is asked about for a URL, as ProviderSync builds it.
const pathKey = ( url ) => {
	const u = new URL( url );
	return u.pathname + u.search;
};

const postIdsForRun = () =>
	phpJson(
		'global $wpdb; echo wp_json_encode( array_map( "intval", $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE %s", $wpdb->esc_like( $d ) . "%" ) ) ) );',
		PREFIX
	);
const termIdsForRun = () =>
	phpJson(
		'global $wpdb; echo wp_json_encode( array_map( "intval", $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE slug LIKE %s", $wpdb->esc_like( $d ) . "%" ) ) ) );',
		PREFIX
	);
const userIdsForRun = () =>
	phpJson(
		'global $wpdb; $like = $wpdb->esc_like( $d ) . "%"; echo wp_json_encode( array_map( "intval", $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s OR user_email LIKE %s", $like, $like ) ) ) );',
		PREFIX
	);

// Anything an earlier e2e-analytics run left behind.
const leftovers = () =>
	phpJson(
		'global $wpdb; $like = $wpdb->esc_like( "e2e-analytics-" ) . "%"; echo wp_json_encode( [' +
			' "users" => $wpdb->get_col( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE %s OR user_email LIKE %s LIMIT 10", $like, $like ) ),' +
			' "posts" => $wpdb->get_col( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE post_name LIKE %s LIMIT 10", $like ) ),' +
			' "terms" => $wpdb->get_col( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE slug LIKE %s LIMIT 10", $like ) ),' +
			' "mu" => array_map( "basename", glob( WPMU_PLUGIN_DIR . "/e2e-analytics-*.php" ) ?: [] ),' +
			' ] );'
	);

// ---------------------------------------------------------------------------
// Sign-ins. A session made in PHP: the auth and logged-in cookies, and a REST
// nonce tied to them, as a wp-login.php sign-in would give.
// ---------------------------------------------------------------------------

const sessions = new Map();

function sessionFor( user ) {
	if ( sessions.has( user.id ) ) {
		return sessions.get( user.id );
	}
	const s = phpJson(
		'$id = (int) $d; $exp = time() + 4 * HOUR_IN_SECONDS; $token = WP_Session_Tokens::get_instance( $id )->create( $exp );' +
			' $secure = "https" === wp_parse_url( site_url(), PHP_URL_SCHEME );' +
			' $li = wp_generate_auth_cookie( $id, $exp, "logged_in", $token ); $auth = wp_generate_auth_cookie( $id, $exp, $secure ? "secure_auth" : "auth", $token );' +
			' $_COOKIE[ LOGGED_IN_COOKIE ] = $li; wp_set_current_user( $id );' +
			' echo wp_json_encode( [ "secure" => $secure, "cookies" => [ [ "name" => LOGGED_IN_COOKIE, "value" => $li ], [ "name" => $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE, "value" => $auth ] ], "nonce" => wp_create_nonce( "wp_rest" ) ] );',
		user.id
	);
	sessions.set( user.id, s );
	return s;
}

// ---------------------------------------------------------------------------
// REST
// ---------------------------------------------------------------------------

let REST_ROOT = '';

// Calls a route relative to rest_url(). `as` is a user to sign in as, by
// cookie and nonce. `body` is sent as JSON.
async function rest( method, route, { as, body, ua = UA } = {} ) {
	const headers = { Accept: 'application/json', 'User-Agent': ua };
	if ( undefined !== body ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	if ( as ) {
		const s = sessionFor( as );
		headers.Cookie = s.cookies
			.map( ( c ) => c.name + '=' + encodeURIComponent( c.value ) )
			.join( '; ' );
		headers[ 'X-WP-Nonce' ] = s.nonce;
	}
	gate();
	const res = await fetch( REST_ROOT + route.replace( /^\/+/, '' ), {
		method,
		headers,
		body: undefined !== body ? JSON.stringify( body ) : undefined,
		redirect: 'manual',
	} );
	const text = await res.text();
	let json = null;
	try {
		json = JSON.parse( text );
	} catch ( e ) {
		json = null;
	}
	return { status: res.status, json: json ?? {}, text, headers: res.headers };
}

const API = 'mai-analytics/v1/';

// Records `n` views of an object through the public route.
async function views( type, id, n = 1, source = 'web' ) {
	for ( let i = 0; i < n; i++ ) {
		const r = await rest( 'POST', API + 'view/' + type + '/' + id, {
			body: { source },
		} );
		if ( 200 !== r.status || true !== r.json.success ) {
			throw new Error(
				'POST view/' + type + '/' + id + ' answered ' + r.status + ' ' + r.text.slice( 0, 200 )
			);
		}
	}
}

// ---------------------------------------------------------------------------
// The fake Matomo, in its own process (see runFake() at the top). The site
// calls it at `localhost`, which WordPress lets through WP_HTTP_BLOCK_EXTERNAL
// (wp-includes/class-wp-http.php, block_request()).
// ---------------------------------------------------------------------------

let fakeChild = null;
const FAKE_URL = 'http://localhost:' + FAKE_PORT + '/';

async function fakeControl( body ) {
	const r = await fetch(
		'http://127.0.0.1:' + FAKE_PORT + '/__e2e',
		body ? { method: 'POST', body: JSON.stringify( body ) } : {}
	);
	return r.json();
}

async function startFake() {
	const { spawn } = require( 'child_process' );
	fakeChild = spawn( process.execPath, [ __filename ], {
		env: { ...process.env, MAI_ANALYTICS_E2E_FAKE_PORT: String( FAKE_PORT ) },
		stdio: [ 'ignore', 'inherit', 'inherit' ],
	} );
	for ( let i = 0; i < 50 && null === fakeChild.exitCode; i++ ) {
		try {
			await fakeControl();
			return;
		} catch ( e ) {
			await sleep( 100 );
		}
	}
	throw new Error( 'the fake Matomo did not start on 127.0.0.1:' + FAKE_PORT + '. Pick another --fake-port.' );
}

function stopFake() {
	if ( fakeChild && null === fakeChild.exitCode ) {
		fakeChild.kill();
	}
	fakeChild = null;
}

// Every request the fake got since its last reset.
const fakeLog = async () => ( await fakeControl() ).requests;

// From the nth request since the last reset on, the fake answers 500. Null
// turns that off.
const failFrom = ( n ) => fakeControl( { failFrom: n } );

// Clears the log and the failure, and sets the counts, keyed by URL.
async function resetFake( countsByUrl = {} ) {
	const counts = {};
	for ( const [ url, c ] of Object.entries( countsByUrl ) ) {
		counts[ pathKey( url ) ] = c;
	}
	await fakeControl( { reset: true, counts } );
}

// Proves the site reaches the fake before a provider scenario relies on it.
// Each scenario resets the fake afterwards, which clears this request.
function fakeReachable() {
	const got = php(
		'$r = wp_remote_post( $d . "index.php", [ "body" => [ "module" => "API", "method" => "API.getBulkRequest", "format" => "json" ], "timeout" => 10 ] ); echo is_wp_error( $r ) ? "error: " . $r->get_error_message() : wp_remote_retrieve_response_code( $r );',
		FAKE_URL
	);
	if ( '200' !== got ) {
		throw new Error( 'the site cannot reach the fake Matomo at ' + FAKE_URL + ': ' + got );
	}
}

// ---------------------------------------------------------------------------
// Settings each scenario starts from
// ---------------------------------------------------------------------------

let admin = null;

const selfHosted = () => ( {
	data_source: 'self_hosted',
	sync_user: admin.id,
	trending_window: 7,
} );

const matomo = () => ( {
	data_source: 'matomo',
	sync_user: admin.id,
	trending_window: 7,
	matomo_url: FAKE_URL,
	matomo_site_id: 1,
	matomo_token: 'e2e-' + RUN,
	matomo_bulk_chunk: 10,
} );

// Empties the buffer, sets the settings, marks both syncs as a minute old so
// no fallback sync fires, clears locks, the cached dashboard filters, the
// provider error and the per-archive options, deletes view meta on the run's objects, and clears the catchup and
// one-off prune events.
function freshState( settings ) {
	php(
		BUFFER_TABLE +
			' $wpdb->query( "DELETE FROM $t" );' +
			' foreach ( [ "mai_analytics_syncing", "mai_analytics_provider_syncing", "mai_analytics_admin_filters" ] as $k ) { delete_transient( $k ); }' +
			' update_option( "mai_analytics_settings", $d["settings"], false );' +
			' update_option( "mai_analytics_synced", time() - 60, false ); update_option( "mai_analytics_provider_last_sync", time() - 60, false );' +
			' \\Mai\\Analytics\\Sync::clear_provider_error();' +
			' foreach ( [ "", "_web", "_app" ] as $s ) { update_option( "mai_analytics_post_type_views" . $s, [], false ); } update_option( "mai_analytics_post_type_trending", [], false ); update_option( "mai_analytics_post_type_views_synced_at", [], false );' +
			' foreach ( $d["posts"] as $id ) { foreach ( $d["keys"] as $k ) { delete_post_meta( (int) $id, $k ); } }' +
			' foreach ( $d["terms"] as $id ) { foreach ( $d["keys"] as $k ) { delete_term_meta( (int) $id, $k ); } }' +
			' foreach ( $d["users"] as $id ) { foreach ( $d["keys"] as $k ) { delete_user_meta( (int) $id, $k ); } }' +
			' wp_clear_scheduled_hook( "mai_analytics_provider_catchup" ); wp_clear_scheduled_hook( "mai_analytics_prune_trending_now" );',
		{
			settings,
			posts: [ ...madePosts ],
			terms: madeTerms,
			users: [ ...madeUsers ],
			keys: META_KEYS,
		}
	);
}

// The post the health check counts a view of: the first published post.
function noteHealthPost() {
	healthPostId = Number(
		php(
			'global $wpdb; echo (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_status = \'publish\' AND post_type = \'post\' ORDER BY ID ASC LIMIT 1" );'
		)
	);
}

// ---------------------------------------------------------------------------
// Browser
// ---------------------------------------------------------------------------

let browser;
let openPages = [];

// A page in a new context. `as` signs a user in by cookie. `track` adds this
// run's e2e_analytics cookie, which turns the tracker on.
async function newPage( { as, track = true, ua = UA } = {} ) {
	const context = await browser.newContext( {
		ignoreHTTPSErrors: true,
		viewport: { width: 1280, height: 900 },
		userAgent: ua,
	} );
	const secure = BASE.startsWith( 'https:' );
	const cookies = [];
	if ( track ) {
		cookies.push( { name: 'e2e_analytics', value: RUN, domain: baseHost, path: '/', secure } );
	}
	if ( as ) {
		for ( const c of sessionFor( as ).cookies ) {
			cookies.push( {
				name: c.name,
				value: encodeURIComponent( c.value ),
				domain: baseHost,
				path: '/',
				httpOnly: true,
				secure,
			} );
		}
	}
	if ( cookies.length ) {
		await context.addCookies( cookies );
	}
	const page = await context.newPage();
	page.setDefaultTimeout( 20000 );
	page.scriptErrors = [];
	page.on( 'pageerror', ( e ) => {
		if ( /mai-analytics/.test( String( e.stack ) ) ) {
			page.scriptErrors.push( e.message );
		}
	} );
	currentPage = page;
	openPages.push( page );
	return page;
}

async function closeAll( failed ) {
	for ( const page of openPages ) {
		if ( failed && ! page.isClosed() ) {
			const file = path.join( OUT, 'fail-' + RUN + '-' + results.length + '-' + openPages.indexOf( page ) + '.png' );
			await page
				.screenshot( { path: file, fullPage: true, timeout: 10000 } )
				.then( () => console.log( '      screenshot: ' + file ) )
				.catch( () => {} );
		}
		check(
			'no mai-analytics script errors' +
				( openPages.length > 1 ? ' (page ' + ( openPages.indexOf( page ) + 1 ) + ')' : '' ),
			0 === page.scriptErrors.length,
			page.scriptErrors.join( '; ' )
		);
	}
	await Promise.all( shots.splice( 0 ) );
	for ( const page of openPages ) {
		await page.context().close().catch( () => {} );
	}
	openPages = [];
	currentPage = null;
}

// Waits until the buffer holds `want` rows for an object, or the time runs out.
// Answers the last count seen.
async function waitRows( type, id, source, want, key = '', timeout = 10000 ) {
	const until = Date.now() + timeout;
	let got = 0;
	do {
		got = rowsFor( bufferRows(), type, id, source, key );
		if ( got >= want ) {
			return got;
		}
		await sleep( 500 );
	} while ( Date.now() < until );
	return got;
}

// Opens a URL. A refused connection is tried once more, and the retry is
// printed as a note, since the first full run met one on the site's own host.
async function open( page, url ) {
	try {
		return await page.goto( url, { waitUntil: 'load' } );
	} catch ( e ) {
		if ( ! /ERR_CONNECTION_REFUSED/.test( e.message ) ) {
			throw e;
		}
		note( 'retried ' + url + ' once after ERR_CONNECTION_REFUSED' );
		await sleep( 2000 );
		return page.goto( url, { waitUntil: 'load' } );
	}
}

// Whether a page's HTML holds the tracker's beacon. wp_json_encode() escapes
// the slashes in the URL it prints.
const hasBeacon = ( html ) => /mai-analytics\\?\/v1\\?\/view/.test( html );

const textOf = ( page, sel ) =>
	page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		return el ? el.innerText.replace( /\s+/g, ' ' ).trim() : null;
	}, sel );

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

const SCENARIOS = {};
// Checks that run after teardown, for the harness self-check.
const afterTeardown = [];

// Proves the harness: it leaves one of each trace teardown must clean, and
// after teardown checks that each is gone or back as it was. A decoy user named
// like another suite's must survive; the check deletes it itself at the end.
SCENARIOS[ 'harness-self-check' ] = async () => {
	const name = scenarioName;
	const decoyLogin = 'e2e-other-' + RUN;
	const decoy = Number(
		php(
			'$id = wp_insert_user( [ "user_login" => $d, "user_email" => $d . "@example.com", "user_pass" => wp_generate_password(), "role" => "subscriber" ] ); echo is_wp_error( $id ) ? 0 : $id;',
			decoyLogin
		)
	);
	afterTeardown.push( () => {
		scenarioName = name + ' (after teardown)';
		equal(
			'the decoy e2e-other user is still there',
			php( '$u = get_user_by( "login", $d ); echo $u ? $u->ID : 0;', decoyLogin ),
			String( decoy )
		);
		php( 'require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user( (int) $d );', decoy );
		equal( 'and is gone once the check removes it', php( 'echo get_userdata( (int) $d ) ? 1 : 0;', decoy ), '0' );
	} );
	check( 'made the decoy user', decoy > 0 );

	freshState( selfHosted() );
	const who = createUser( 'self' );
	const post = createPost( 'self', { author: who.id } );
	const term = createTerm( 'self' );
	setMeta( 'post', post.id, { mai_views: 3, mai_trending: 1 } );
	setMeta( 'term', term.id, { mai_views: 2 } );
	setMeta( 'user', who.id, { mai_views: 1 } );
	insertRows( [ { id: post.id, type: 'post', source: 'web', ago: 5 } ] );
	const standIn = 'mai_analytics_e2e_' + RUN;
	php(
		'update_option( $d, "e2e", false ); set_transient( $d, 1, 600 ); wp_schedule_single_event( time() + 600, "mai_analytics_provider_catchup" );',
		standIn
	);
	check( 'the must-use file is in place', fs.existsSync( muFile ) );
	equal( 'the stand-in option is set', opt( standIn ), 'e2e' );

	afterTeardown.push( () => {
		scenarioName = name + ' (after teardown)';
		check( 'the must-use file is gone', ! fs.existsSync( muFile ) );
		const wrong = [];
		const now = readRows();
		for ( const n of Object.keys( saved ) ) {
			if ( JSON.stringify( now[ n ] ?? null ) !== JSON.stringify( saved[ n ] ) ) {
				wrong.push( n );
			}
		}
		equal( 'every saved option row holds the bytes it held before the run', wrong.join( ', ' ), '' );
		equal(
			'no option or transient made by the run is left (' + standIn + ' among them)',
			Object.keys( now ).filter( ( n ) => ! ( n in saved ) && null !== now[ n ] ).join( ', ' ),
			''
		);
		equal( 'the user, post and term are deleted', [ ...userIdsForRun(), ...postIdsForRun(), ...termIdsForRun() ].length, 0 );
		equal( 'the buffer is empty', bufferCount(), 0 );
		equal( 'no view meta is left anywhere', JSON.stringify( viewMetaLeft() ), '{}' );
		check( 'the mai_analytics cron events are as they were', sameList( cronEvents(), cronAtStart ), JSON.stringify( cronEvents() ) );
		equal(
			'mai-analytics is ' + ( pluginWasActive ? 'active' : 'inactive' ) + ' again',
			php( 'require_once ABSPATH . "wp-admin/includes/plugin.php"; echo is_plugin_active( $d ) ? 1 : 0;', PLUGIN ),
			pluginWasActive ? '1' : '0'
		);
	} );
};

SCENARIOS.activation = async () => {
	equal(
		'the buffer table exists',
		php( 'echo \\Mai\\Analytics\\Database::table_exists() ? 1 : 0;' ),
		'1'
	);
	equal(
		'the stored database version is the code version',
		php( 'echo get_option( "mai_analytics_db_version" ) === MAI_ANALYTICS_DB_VERSION ? 1 : 0;' ),
		'1'
	);
	const sync = phpJson( '$e = wp_get_scheduled_event( "mai_analytics_cron_sync" ); echo wp_json_encode( $e ? $e->schedule : null );' );
	equal( 'the sync runs every 15 minutes', sync, 'mai_analytics_15min' );
	check( 'the daily trending prune is scheduled', nextScheduled( 'mai_analytics_prune_trending' ) > 0 );

	php( 'wp_schedule_single_event( time() + 600, "mai_analytics_provider_catchup" ); wp_schedule_single_event( time() + 600, "mai_analytics_prune_trending_now" );' );
	const rowsBefore = bufferCount();
	wpRun( [ 'plugin', 'deactivate', 'mai-analytics' ] );
	equal( 'deactivation clears all four cron hooks', JSON.stringify( cronEvents() ), '[]' );
	equal(
		'the data stays: the table and the version option are still there',
		php( 'global $wpdb; echo ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . "mai_analytics_buffer" ) ) && get_option( "mai_analytics_version" ) ) ? 1 : 0;' ),
		'1'
	);
	equal( 'and so do the buffer rows', bufferCount(), rowsBefore );
	wpRun( [ 'plugin', 'activate', 'mai-analytics' ] );
	check( 'activating again schedules the sync', nextScheduled( 'mai_analytics_cron_sync' ) > 0 );
	check( 'and the daily prune', nextScheduled( 'mai_analytics_prune_trending' ) > 0 );
	equal( 'but no catchup or one-off prune', nextScheduled( 'mai_analytics_provider_catchup' ) + nextScheduled( 'mai_analytics_prune_trending_now' ), 0 );
};

SCENARIOS[ 'tracker-web' ] = async () => {
	freshState( selfHosted() );
	const author = createUser( 'tw-author', 'author' );
	const term = createTerm( 'tw-cat' );
	const post = createPost( 'tw-post', { author: author.id, terms: [ term.id ] } );
	const cpt = createPost( 'tw-cpt', { type: CPT, author: author.id } );
	const archive = archiveUrl();
	check( 'the e2e post type has an archive link', !! archive && !! cpt.id, archive );

	// A site behind restricted-site-access sends a signed-out visitor to the
	// sign-in page. The tracker is then tested as a signed-in subscriber.
	gate();
	const probe = await fetch( post.url, { redirect: 'manual', headers: { 'User-Agent': UA } } );
	let visitor;
	if ( probe.status >= 300 && probe.status < 400 ) {
		visitor = createUser( 'tw-visitor' );
		note( 'a signed-out view of a post answered ' + probe.status + ' to ' + probe.headers.get( 'location' ) + ', so the tracker is tested with a signed-in subscriber' );
	}

	const pages = [
		[ 'a post', post.url, 'post', post.id, '' ],
		[ 'a category archive', term.url, 'term', term.id, '' ],
		[ 'an author archive', author.url, 'user', author.id, '' ],
		[ 'a post type archive', archive, 'post_type', 0, CPT ],
	];
	for ( const [ what, url, type, id, key ] of pages ) {
		const page = await newPage( { as: visitor } );
		await open( page, url );
		const html = await page.content();
		const route = 'post_type' === type ? 'view/post_type/' + key : 'view/' + type + '/' + id;
		check( 'a view of ' + what + ' prints the beacon for ' + route, html.includes( 'sendBeacon' ) && html.includes( route.replace( /\//g, '\\/' ) ), url );
		equal( 'and adds one web row', await waitRows( type, id, 'web', 1, key ), 1 );
	}

	// The tracker is off on a non-production site unless something turns it on.
	const off = await newPage( { as: visitor, track: false } );
	await open( off, post.url );
	check( 'without the tracking filter a local site prints no beacon', ! hasBeacon( await off.content() ) );

	// Editors are never counted.
	const editor = await newPage( { as: admin } );
	await open( editor, post.url );
	check( 'a signed-in editor gets no beacon', ! hasBeacon( await editor.content() ) );

	await sleep( 1500 );
	equal( 'those two views add no rows', rowsFor( bufferRows(), 'post', post.id, 'web' ), 1 );

	if ( 'yes' === php( 'echo \\Mai\\Analytics\\BotFilter::is_bot( $d ) ? "yes" : "no";', BOT_UA ) ) {
		const bot = await newPage( { as: visitor, ua: BOT_UA } );
		const beacon = bot.waitForResponse( ( r ) => r.url().includes( '/mai-analytics/v1/view/post/' + post.id ), { timeout: 10000 } ).catch( () => null );
		await open( bot, post.url );
		const r = await beacon;
		check( "a bot's beacon is refused", ! r || 403 === r.status(), r && r.status() );
		await sleep( 1000 );
		equal( 'and adds no row', rowsFor( bufferRows(), 'post', post.id, 'web' ), 1 );
		const direct = await rest( 'POST', API + 'view/post/' + post.id, { ua: BOT_UA } );
		equal( 'POST view with a bot user agent answers 403', direct.status, 403 );
		equal( 'with the bot code', direct.json.code, 'mai_analytics_bot' );
	} else {
		skip( 'the bot filter does not list ' + BOT_UA );
	}

	const hidden = createPost( 'tw-private', { status: 'private' } );
	let r = await rest( 'POST', API + 'view/post/' + hidden.id );
	equal( 'a private post answers 404', r.status, 404 );
	equal( 'with the not found code', r.json.code, 'mai_analytics_not_found' );
	const attachment = Number(
		php(
			'$id = wp_insert_attachment( [ "post_title" => $d["slug"], "post_name" => $d["slug"], "post_mime_type" => "image/png", "post_status" => "inherit" ], false, (int) $d["parent"] ); echo is_wp_error( $id ) ? 0 : $id;',
			{ slug: PREFIX + 'tw-attachment', parent: post.id }
		)
	);
	madePosts.add( attachment );
	r = await rest( 'POST', API + 'view/post/' + attachment );
	equal( 'an attachment answers 404', r.status, 404 );
	equal( 'and adds no row', rowsFor( bufferRows(), 'post', attachment, 'web' ), 0 );
};

SCENARIOS[ 'self-hosted-sync' ] = async () => {
	freshState( selfHosted() );
	const author = createUser( 'sh-author', 'author' );
	const term = createTerm( 'sh-cat' );
	const a = createPost( 'sh-a', { author: author.id, terms: [ term.id ] } );
	const b = createPost( 'sh-b' );
	const c = createPost( 'sh-c' );
	createPost( 'sh-cpt', { type: CPT } );

	await views( 'post', a.id, 3 );
	await views( 'post', a.id, 2, 'app' );
	await views( 'post', b.id, 1 );
	await views( 'term', term.id, 2 );
	await views( 'user', author.id, 1 );
	const archiveView = async ( n, source = 'web' ) => {
		for ( let i = 0; i < n; i++ ) {
			const v = await rest( 'POST', API + 'view/post_type/' + CPT, { body: { source } } );
			if ( 200 !== v.status ) {
				throw new Error( 'POST view/post_type answered ' + v.status + ' ' + v.text.slice( 0, 200 ) );
			}
		}
	};
	await archiveView( 2 );
	await archiveView( 1, 'app' );
	// A view older than the 14-day retention, and C, which was trending but has
	// no view inside the 7-day window any more.
	insertRows( [
		{ id: b.id, type: 'post', source: 'web', ago: 20 * 86400 },
		{ id: c.id, type: 'post', source: 'web', ago: 10 * 86400 },
	] );
	setMeta( 'post', c.id, { mai_trending: 5 } );
	const before = Number( opt( 'mai_analytics_synced' ) );

	runCron( 'mai_analytics_cron_sync' );
	check( 'post A counts 3 web and 2 app views: views, web, app, trending', sameList( counts( 'post', a.id ), [ 5, 3, 2, 5 ] ), JSON.stringify( counts( 'post', a.id ) ) );
	check( 'post B counts its one new view, not the old one', sameList( counts( 'post', b.id ), [ 1, 1, 0, 1 ] ), JSON.stringify( counts( 'post', b.id ) ) );
	equal( 'post C fell out of the trending window, so its trending row is deleted', metaOf( 'post', c.id ).mai_trending, null );
	check( 'the term counts 2', sameList( counts( 'term', term.id ), [ 2, 2, 0, 2 ] ), JSON.stringify( counts( 'term', term.id ) ) );
	check( 'the author counts 1', sameList( counts( 'user', author.id ), [ 1, 1, 0, 1 ] ), JSON.stringify( counts( 'user', author.id ) ) );
	const pt = phpJson( 'echo wp_json_encode( array_map( fn( $n ) => get_option( $n )[ $d ] ?? null, [ "mai_analytics_post_type_views", "mai_analytics_post_type_views_web", "mai_analytics_post_type_views_app", "mai_analytics_post_type_trending" ] ) );', CPT );
	check( 'the post type archive counts 2 web and 1 app view', sameList( pt, [ 3, 2, 1, 3 ] ), JSON.stringify( pt ) );
	const rows = bufferRows();
	equal( 'the view past retention is pruned from the buffer', rowsFor( rows, 'post', b.id, 'web' ), 1 );
	equal( 'the 10-day view is kept, inside retention', rowsFor( rows, 'post', c.id, 'web' ), 1 );
	check( 'the sync time moved forward', Number( opt( 'mai_analytics_synced' ) ) >= before, opt( 'mai_analytics_synced' ) );
	equal( 'the lock is released', php( 'echo get_transient( "mai_analytics_syncing" ) ? 1 : 0;' ), '0' );

	runCron( 'mai_analytics_cron_sync' );
	check( 'a second sync with no new views changes nothing', sameList( counts( 'post', a.id ), [ 5, 3, 2, 5 ] ), JSON.stringify( counts( 'post', a.id ) ) );

	// A view in the same second as the sync's start falls on the boundary, so
	// each new view waits a second.
	await sleep( 1100 );
	await views( 'post', a.id, 1 );
	runCron( 'mai_analytics_cron_sync' );
	check( 'one more view adds one', sameList( counts( 'post', a.id ), [ 6, 4, 2, 6 ] ), JSON.stringify( counts( 'post', a.id ) ) );

	let r = await rest( 'GET', API + 'views/post/' + a.id );
	check( 'GET views/post answers its views and trending', 200 === r.status && 6 === r.json.views && 6 === r.json.trending, r.text );
	r = await rest( 'GET', API + 'views/term/' + term.id );
	check( 'GET views/term too', 200 === r.status && 2 === r.json.views, r.text );
	r = await rest( 'GET', API + 'views/user/' + author.id );
	check( 'GET views/user too', 200 === r.status && 1 === r.json.views, r.text );
	r = await rest( 'GET', API + 'views/post_type/' + CPT );
	check( 'GET views/post_type too', 200 === r.status && 3 === r.json.views && 3 === r.json.trending, r.text );

	// A held lock: the cron run does nothing, and the views wait for the next one.
	php( 'set_transient( "mai_analytics_syncing", 1, 300 );' );
	await sleep( 1100 );
	await views( 'post', b.id, 1 );
	runCron( 'mai_analytics_cron_sync' );
	check( 'with the lock held the sync does nothing', sameList( counts( 'post', b.id ), [ 1, 1, 0, 1 ] ), JSON.stringify( counts( 'post', b.id ) ) );
	php( 'delete_transient( "mai_analytics_syncing" );' );
	runCron( 'mai_analytics_cron_sync' );
	check( 'and the next sync counts the waiting view', sameList( counts( 'post', b.id ), [ 2, 2, 0, 2 ] ), JSON.stringify( counts( 'post', b.id ) ) );
};

SCENARIOS[ 'rest-public' ] = async () => {
	freshState( selfHosted() );
	const author = createUser( 'rp-author', 'author' );
	const term = createTerm( 'rp-cat' );
	const a = createPost( 'rp-a', { author: author.id, terms: [ term.id ] } );
	const b = createPost( 'rp-b', { author: author.id } );
	createPost( 'rp-cpt', { type: CPT } );
	await views( 'post', a.id, 3 );
	await views( 'post', b.id, 1 );
	await views( 'term', term.id, 2 );
	await views( 'user', author.id, 1 );
	const pv = await rest( 'POST', API + 'view/post_type/' + CPT );
	equal( 'POST view/post_type answers 200', pv.status, 200 );
	await views( 'post', b.id, 1, 'app' );
	equal( 'source app is saved as an app view', rowsFor( bufferRows(), 'post', b.id, 'app' ), 1 );

	let r = await rest( 'GET', API + 'views/trending?type=post&period=24h' );
	const ids = ( Array.isArray( r.json ) ? r.json : [] ).map( ( x ) => x.id );
	check( 'trending posts lists A then B', 200 === r.status && ids.indexOf( a.id ) === 0 && ids.indexOf( b.id ) === 1, r.text.slice( 0, 300 ) );
	const first = ( r.json || [] )[ 0 ] || {};
	check( 'with the count, title and link', 3 === first.view_count && PREFIX + 'rp-a' === first.title && first.url === a.url, JSON.stringify( first ) );

	r = await rest( 'GET', API + 'views/trending?type=post&period=24h&taxonomy=category&terms=' + term.id );
	check( 'a category filter keeps only A', sameList( ( r.json || [] ).map( ( x ) => x.id ), [ a.id ] ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'views/trending?type=post&period=24h&taxonomy=e2e-unknown&terms=' + term.id );
	check( 'an unknown taxonomy answers an empty list', 200 === r.status && sameList( r.json, [] ), r.text );
	r = await rest( 'GET', API + 'views/trending?type=term&period=24h' );
	check( 'trending terms lists the term with its name', ( r.json || [] ).some( ( x ) => x.id === term.id && x.name === PREFIX + 'rp-cat' && 2 === x.view_count ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'views/trending?type=term&period=24h&taxonomy=category' );
	check( 'and filtered to categories', ( r.json || [] ).some( ( x ) => x.id === term.id ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'views/trending?type=term&period=24h&taxonomy=e2e-unknown' );
	check( 'trending terms in an unknown taxonomy is empty', 200 === r.status && sameList( r.json, [] ), r.text );
	r = await rest( 'GET', API + 'views/trending?type=user&period=24h' );
	check( 'trending authors lists the author', ( r.json || [] ).some( ( x ) => x.id === author.id && 1 === x.view_count ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'views/trending?type=post_type&period=24h' );
	check( 'trending archives lists the e2e post type', ( r.json || [] ).some( ( x ) => x.post_type === CPT && 1 === x.view_count ), r.text.slice( 0, 300 ) );

	php( 'wp_update_post( [ "ID" => (int) $d, "post_status" => "private" ] );', a.id );
	r = await rest( 'GET', API + 'views/trending?type=post&period=24h' );
	check( 'a post made private drops out of trending', ! ( r.json || [] ).some( ( x ) => x.id === a.id ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'views/post/' + a.id );
	equal( 'and GET views for it answers 404', r.status, 404 );

	for ( const [ what, method, route, status, code ] of [
		[ 'per_page over 100', 'GET', 'views/trending?per_page=101', 400, 'mai_analytics_invalid_request' ],
		[ 'an unknown period', 'GET', 'views/trending?period=1y', 400, 'mai_analytics_invalid_request' ],
		[ 'a missing post (GET)', 'GET', 'views/post/999999999', 404, 'mai_analytics_not_found' ],
		[ 'a missing post (POST)', 'POST', 'view/post/999999999', 404, 'mai_analytics_not_found' ],
		[ 'an unknown source', 'POST', 'view/post/' + b.id + '?source=nope', 400, 'mai_analytics_invalid_request' ],
		[ 'an unknown post type', 'POST', 'view/post_type/e2e-no-type', 404, 'mai_analytics_not_found' ],
		[ 'an unknown object type', 'GET', 'views/thing/1', 404, 'rest_no_route' ],
	] ) {
		r = await rest( method, API + route );
		check( what + ' answers ' + status + ' ' + code, status === r.status && code === r.json.code, r.status + ' ' + r.text.slice( 0, 200 ) );
	}
};

SCENARIOS[ 'rest-admin' ] = async () => {
	freshState( selfHosted() );
	const sub = createUser( 'ra-sub' );
	const author = createUser( 'ra-author', 'author' );
	const term = createTerm( 'ra-cat' );
	const a = createPost( 'ra-a', { author: author.id, terms: [ term.id ] } );
	createPost( 'ra-cpt', { type: CPT } );
	await views( 'post', a.id, 2 );
	await views( 'term', term.id, 1 );
	await views( 'user', author.id, 1 );
	await rest( 'POST', API + 'view/post_type/' + CPT );
	runCron( 'mai_analytics_cron_sync' );

	const as = admin;
	let r = await rest( 'GET', API + 'admin/top/posts?search=' + encodeURIComponent( PREFIX + 'ra-a' ), { as } );
	const row = ( r.json.items || [] ).find( ( x ) => x.id === a.id );
	check( 'top posts lists A with its views', 200 === r.status && row && 2 === row.views && 2 === row.trending, r.text.slice( 0, 300 ) );
	check( 'with totals and pages', r.json.totals && 'number' === typeof r.json.total && 'number' === typeof r.json.pages, r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'admin/top/posts?orderby=trending&order=asc&per_page=5', { as } );
	equal( 'ordered by trending it answers 200', r.status, 200 );
	r = await rest( 'GET', API + 'admin/top/terms', { as } );
	check( 'top terms lists the term', ( r.json.items || [] ).some( ( x ) => x.id === term.id && 1 === x.views ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'admin/top/authors', { as } );
	check( 'top authors lists the author', ( r.json.items || [] ).some( ( x ) => x.id === author.id && 1 === x.views ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'admin/top/archives', { as } );
	check( 'top archives lists the e2e post type', 200 === r.status && JSON.stringify( r.json.items || [] ).includes( CPT ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'admin/filters', { as } );
	// Filters lists only post types and taxonomies with counted views.
	check(
		'filters lists posts and categories, which have views',
		200 === r.status && ( r.json.post_types || [] ).some( ( x ) => 'post' === x.slug ) && ( r.json.taxonomies || [] ).some( ( x ) => 'category' === x.slug ),
		r.text.slice( 0, 300 )
	);
	r = await rest( 'GET', API + 'admin/search?type=author&search=' + encodeURIComponent( PREFIX + 'ra-author' ), { as } );
	check( 'search finds the author', 200 === r.status && ( Array.isArray( r.json ) ? r.json : [] ).some( ( x ) => x.id === author.id ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'admin/search?type=term&taxonomy=category&search=' + encodeURIComponent( PREFIX + 'ra-cat' ), { as } );
	check( 'search finds the term, with X-WP-Total', 200 === r.status && ( Array.isArray( r.json ) ? r.json : [] ).some( ( x ) => x.id === term.id ) && null !== r.headers.get( 'x-wp-total' ), r.text.slice( 0, 300 ) );
	r = await rest( 'GET', API + 'admin/top/posts?per_page=101', { as } );
	check( 'per_page over 100 answers 400 invalid_request', 400 === r.status && 'mai_analytics_invalid_request' === r.json.code, r.text.slice( 0, 200 ) );

	r = await rest( 'POST', API + 'admin/sync-now', { as } );
	check( 'sync-now runs the self-hosted sync', 200 === r.status && /complete/i.test( r.json.message || '' ), r.text.slice( 0, 200 ) );
	php( 'set_transient( "mai_analytics_syncing", 1, 300 );' );
	r = await rest( 'POST', API + 'admin/sync-now', { as } );
	check( 'with the lock held sync-now answers 429 mai_analytics_busy', 429 === r.status && 'mai_analytics_busy' === r.json.code, r.text.slice( 0, 200 ) );
	check( 'with Retry-After 1 and retry_after 1', '1' === r.headers.get( 'retry-after' ) && 1 === r.json.data?.retry_after, r.headers.get( 'retry-after' ) + ' ' + r.text.slice( 0, 200 ) );
	php( 'delete_transient( "mai_analytics_syncing" );' );
	r = await rest( 'POST', API + 'admin/warm', { as } );
	check( 'warm in self-hosted mode answers 501 provider_required', 501 === r.status && 'mai_analytics_provider_required' === r.json.code, r.text.slice( 0, 200 ) );
	php( '\\Mai\\Analytics\\Sync::set_provider_error( "e2e" );' );
	r = await rest( 'POST', API + 'admin/dismiss-error', { as } );
	check( 'dismiss-error clears the provider error', 200 === r.status && true === r.json.dismissed && '' === php( 'echo \\Mai\\Analytics\\Sync::get_last_error()["message"];' ), r.text );
	noteHealthPost();
	r = await rest( 'POST', API + 'admin/health', { as } );
	check( 'health answers its checks', 200 === r.status && Array.isArray( r.json.checks ) && r.json.checks.length > 5, r.text.slice( 0, 200 ) );
	if ( r.json.fail ) {
		note( 'the health check reports ' + r.json.fail + ' failing: ' + ( r.json.checks || [] ).filter( ( c ) => 'fail' === c.status ).map( ( c ) => c.label + ' (' + c.detail + ')' ).join( '; ' ) );
	}

	const routes = [
		[ 'GET', 'admin/top/posts' ],
		[ 'GET', 'admin/top/terms' ],
		[ 'GET', 'admin/top/authors' ],
		[ 'GET', 'admin/top/archives' ],
		[ 'GET', 'admin/filters' ],
		[ 'GET', 'admin/search?type=author' ],
		[ 'POST', 'admin/sync-now' ],
		[ 'POST', 'admin/warm' ],
		[ 'POST', 'admin/health' ],
		[ 'POST', 'admin/dismiss-error' ],
	];
	for ( const [ method, route ] of routes ) {
		r = await rest( method, API + route, { as: sub } );
		equal( method + ' ' + route + ' as a subscriber answers 403', r.status, 403 );
		r = await rest( method, API + route );
		equal( method + ' ' + route + ' signed out answers 401', r.status, 401 );
	}
};

SCENARIOS.dashboard = async () => {
	freshState( selfHosted() );
	const author = createUser( 'db-author', 'author' );
	const a = createPost( 'db-a', { author: author.id } );
	const b = createPost( 'db-b', { author: author.id } );
	await views( 'post', a.id, 3 );
	await views( 'post', b.id, 1 );
	// The authors tab counts views of author archives, not of their posts.
	await views( 'user', author.id, 2 );
	runCron( 'mai_analytics_cron_sync' );
	const dash = BASE + '/wp-admin/admin.php?page=mai-analytics';

	const page = await newPage( { as: admin, track: false } );
	await open( page, dash );
	await page.waitForFunction( () => document.querySelector( '.mai-analytics-table tbody tr' ), null, { timeout: 20000 } ).catch( () => {} );
	const table = await textOf( page, '.mai-analytics-table tbody' );
	check( 'the posts table lists A and B', !! table && table.includes( PREFIX + 'db-a' ) && table.includes( PREFIX + 'db-b' ), table );
	equal( 'the Total Views card shows 4', await textOf( page, '[data-card="views"] .mai-analytics-card__value' ), '4' );
	check( 'the filters notice stays hidden', await page.evaluate( () => document.getElementById( 'mai-analytics-filters-error' )?.hidden ) );
	// The filters load into Tom Select's own list, not the select's options.
	await page.waitForFunction( () => 'post' in ( document.getElementById( 'mai-analytics-post-type' )?.tomselect?.options || {} ), null, { timeout: 15000 } ).catch( () => {} );
	check( 'the post type filter offers Posts', await page.evaluate( () => 'post' in ( document.getElementById( 'mai-analytics-post-type' )?.tomselect?.options || {} ) ) );
	for ( const tab of [ 'terms', 'authors', 'archives' ] ) {
		await page.click( '.mai-analytics-tabs .nav-tab[data-tab="' + tab + '"]' );
		await sleep( 1500 );
		const empty = await textOf( page, '.mai-analytics-empty p' );
		check( 'the ' + tab + ' tab loads', ! /Could not load/.test( empty || '' ), empty );
	}
	await page.click( '.mai-analytics-tabs .nav-tab[data-tab="authors"]' );
	await page.waitForFunction( ( n ) => ( document.querySelector( '.mai-analytics-table tbody' )?.innerText || '' ).includes( n ), PREFIX + 'db-author', { timeout: 15000 } ).catch( () => {} );
	check( 'the authors tab lists the author', ( ( await textOf( page, '.mai-analytics-table tbody' ) ) || '' ).includes( PREFIX + 'db-author' ), await textOf( page, '.mai-analytics-table tbody' ) );

	// The filters request fails: the notice shows, and the table still loads.
	const broken = await newPage( { as: admin, track: false } );
	await broken.route( /\/mai-analytics\/v1\/admin\/filters/, ( route ) =>
		route.fulfill( { status: 503, contentType: 'application/json', body: JSON.stringify( { code: 'mai_analytics_database_error', message: 'e2e', data: { status: 503 } } ) } )
	);
	await open( broken, dash );
	await broken.waitForFunction( () => ! document.getElementById( 'mai-analytics-filters-error' )?.hidden, null, { timeout: 15000 } ).catch( () => {} );
	equal( 'a failed filters request shows the notice', await textOf( broken, '#mai-analytics-filters-error' ), 'Could not load the filters. Reload the page to try again.' );
	await broken.waitForFunction( () => document.querySelector( '.mai-analytics-table tbody tr' ), null, { timeout: 15000 } ).catch( () => {} );
	check( 'and the table still loads', ( ( await textOf( broken, '.mai-analytics-table tbody' ) ) || '' ).includes( PREFIX + 'db-a' ) );

	// The settings tab's health check.
	noteHealthPost();
	const settings = await newPage( { as: admin, track: false } );
	await open( settings, dash + '&tab=settings' );
	await settings.click( '#mai-analytics-health-check' );
	await settings.waitForFunction( () => /passed/.test( document.getElementById( 'mai-analytics-health-results' )?.innerText || '' ), null, { timeout: 30000 } ).catch( () => {} );
	check( 'Run Health Check shows its results', /\d+ passed, \d+ failed/.test( ( await textOf( settings, '#mai-analytics-health-results' ) ) || '' ), await textOf( settings, '#mai-analytics-health-results' ) );
};

SCENARIOS.cli = async () => {
	freshState( selfHosted() );
	const a = createPost( 'cli-a' );
	const b = createPost( 'cli-b' );
	await views( 'post', a.id, 2 );

	let r = wpTry( [ 'mai-analytics', 'stats' ] );
	check( 'stats prints the data source and buffer rows', 0 === r.code && /Data source:\s+self_hosted/.test( r.out ) && /Buffer table rows:\s+2/.test( r.out ), r.out + r.err );
	r = wpTry( [ 'mai-analytics', 'sync' ] );
	check( 'sync succeeds', 0 === r.code && /Sync complete/.test( r.out ), r.out + r.err );
	check( 'and counts the views', sameList( counts( 'post', a.id ), [ 2, 2, 0, 2 ] ), JSON.stringify( counts( 'post', a.id ) ) );
	php( 'set_transient( "mai_analytics_syncing", 1, 300 );' );
	r = wpTry( [ 'mai-analytics', 'sync' ] );
	check( 'with the lock held sync fails and says why', 0 !== r.code && /A sync is already running/.test( r.err ), r.code + ' ' + r.err );
	php( 'delete_transient( "mai_analytics_syncing" );' );

	insertRows( [ { id: b.id, type: 'post', source: 'web', ago: 20 * 86400 } ] );
	r = wpTry( [ 'mai-analytics', 'prune', '--dry-run' ] );
	check( 'prune --dry-run counts the one old row', 0 === r.code && /Would prune 1 rows older than 14d/.test( r.out ), r.out + r.err );
	r = wpTry( [ 'mai-analytics', 'prune', '--older-than=15d' ] );
	check( 'prune --older-than=15d deletes it', 0 === r.code && /Pruned 1 rows/.test( r.out ) && 0 === rowsFor( bufferRows(), 'post', b.id, 'web' ), r.out + r.err );
	r = wpTry( [ 'mai-analytics', 'prune', '--older-than=xx' ] );
	check( 'a bad duration fails', 0 !== r.code && /Invalid duration format/.test( r.err ), r.err );

	setMeta( 'post', b.id, { mai_trending: 0 } );
	r = wpTry( [ 'mai-analytics', 'prune-trending', '--dry-run' ] );
	check( 'prune-trending --dry-run counts the zero row', 0 === r.code && /^Success: 1 trending rows would be deleted/m.test( r.out ), r.out + r.err );
	r = wpTry( [ 'mai-analytics', 'prune-trending' ] );
	check( 'prune-trending deletes it', 0 === r.code && null === metaOf( 'post', b.id ).mai_trending, r.out + r.err );
	equal( 'and keeps the live one', metaOf( 'post', a.id ).mai_trending, 2 );

	const flags = [ opt( 'mai_analytics_migrated_from_analytics' ), opt( 'mai_analytics_table_migrated' ) ];
	r = wpTry( [ 'mai-analytics', 'migrate' ] );
	check( 'migrate completes', 0 === r.code && /Migration complete/.test( r.out ), r.out + r.err );
	check( 'and leaves the migration flags as they were', sameList( [ opt( 'mai_analytics_migrated_from_analytics' ), opt( 'mai_analytics_table_migrated' ) ], flags ) );

	noteHealthPost();
	r = wpTry( [ 'mai-analytics', 'health' ] );
	check( 'health prints its sections and a summary', /=== .+ ===/.test( r.out ) && /passed, \d+ failed/.test( r.out + r.err ), r.out.slice( -300 ) + r.err );

	r = wpTry( [ 'mai-analytics', 'warm' ] );
	check( 'warm in self-hosted mode fails and says why', 0 !== r.code && /only available when an external data source/.test( r.err ), r.err );

	r = wpTry( [ 'mai-analytics', 'reset', '--yes' ] );
	check( 'reset --yes succeeds', 0 === r.code && /has been reset/.test( r.out ), r.out + r.err );
	equal( 'and empties the buffer', bufferCount(), 0 );
	equal( "and deletes A's views", metaOf( 'post', a.id ).mai_views, null );

	skip( 'seed is not run: it picks random real posts' );
	skip( 'update-bots is not run: it fetches from GitHub' );
};

SCENARIOS[ 'provider-sync' ] = async () => {
	freshState( matomo() );
	fakeReachable();
	const author = createUser( 'ps-author', 'author' );
	const term = createTerm( 'ps-cat' );
	const p1 = createPost( 'ps-1', { author: author.id, terms: [ term.id ] } );
	const p2 = createPost( 'ps-2' );
	createPost( 'ps-cpt', { type: CPT } );
	const archive = archiveUrl();
	await resetFake( {
		[ p1.url ]: { trending: 7, all_time: 40 },
		[ p2.url ]: { trending: 0, all_time: 3 },
		[ term.url ]: { trending: 5, all_time: 10 },
		[ author.url ]: { trending: 1, all_time: 2 },
		[ archive ]: { trending: 4, all_time: 9 },
	} );

	await views( 'post', p1.id, 2 );
	await views( 'post', p1.id, 2, 'app' );
	await views( 'post', p2.id, 1 );
	await views( 'term', term.id, 1 );
	await views( 'user', author.id, 1 );
	await rest( 'POST', API + 'view/post_type/' + CPT );
	await rest( 'POST', API + 'view/post_type/' + CPT, { body: { source: 'app' } } );
	let rows = bufferRows();
	equal( 'two web views of P1 queue one row', rowsFor( rows, 'post', p1.id, 'web' ), 1 );
	equal( 'and its two app views two rows', rowsFor( rows, 'post', p1.id, 'app' ), 2 );

	const before = Number( opt( 'mai_analytics_provider_last_sync' ) );
	runCron( 'mai_analytics_cron_sync' );
	const log = await fakeLog();
	check( 'the sync asked the fake Matomo', log.length > 0, log.length );
	check(
		'every request is API.getBulkRequest with the site ID and token',
		log.every( ( q ) => 'API.getBulkRequest' === q.method && '1' === q.idSite && 'e2e-' + RUN === q.token && '/index.php' === q.url ),
		JSON.stringify( log.map( ( q ) => [ q.method, q.idSite, q.url ] ) )
	);
	const subs = log.flatMap( ( q ) => q.subs ).filter( ( s ) => s && pathKey( s.pageUrl ) === pathKey( p1.url ) );
	check(
		'P1 is asked about for the last 7 days and the last 5 years',
		subs.some( ( s ) => 'day' === s.period && 'last7' === s.date ) && subs.some( ( s ) => 'year' === s.period && 'last5' === s.date ),
		JSON.stringify( subs )
	);
	check( "P1 gets Matomo's 40 plus its 2 app views, trending 7 plus 2", sameList( counts( 'post', p1.id ), [ 42, 40, 2, 9 ] ), JSON.stringify( counts( 'post', p1.id ) ) );
	check( 'and a synced time', metaOf( 'post', p1.id ).mai_views_synced_at > 0 );
	check( 'P2 gets 3, with no trending row', sameList( counts( 'post', p2.id ), [ 3, 3, 0, 0 ] ) && null === metaOf( 'post', p2.id ).mai_trending, JSON.stringify( metaOf( 'post', p2.id ) ) );
	check( 'the term gets 10, trending 5', sameList( counts( 'term', term.id ), [ 10, 10, 0, 5 ] ), JSON.stringify( counts( 'term', term.id ) ) );
	check( 'the author gets 2, trending 1', sameList( counts( 'user', author.id ), [ 2, 2, 0, 1 ] ), JSON.stringify( counts( 'user', author.id ) ) );
	const pt = phpJson( 'echo wp_json_encode( array_map( fn( $n ) => get_option( $n )[ $d ] ?? null, [ "mai_analytics_post_type_views", "mai_analytics_post_type_views_web", "mai_analytics_post_type_views_app", "mai_analytics_post_type_trending" ] ) );', CPT );
	check( 'the archive gets 9 plus 1 app view, trending 4 plus 1', sameList( pt, [ 10, 9, 1, 5 ] ), JSON.stringify( pt ) );
	rows = bufferRows();
	equal( 'the web rows are consumed', rows.filter( ( x ) => 'web' === x.source ).length, 0 );
	equal( 'the app rows stay', rowsFor( rows, 'post', p1.id, 'app' ), 2 );
	check( 'the provider sync time moved forward', Number( opt( 'mai_analytics_provider_last_sync' ) ) >= before );
	equal( 'no provider error is on file', php( 'echo \\Mai\\Analytics\\Sync::get_last_error()["message"];' ), '' );
	equal( 'the lock is released', php( 'echo get_transient( "mai_analytics_provider_syncing" ) ? 1 : 0;' ), '0' );

	// A second sync with nothing new keeps the counts, and app views are not
	// added twice. Each new view waits a second, off the last sync's boundary.
	await sleep( 1100 );
	await views( 'post', p1.id, 1 );
	runCron( 'mai_analytics_cron_sync' );
	check( 'a later sync keeps P1 at 42, trending 9', sameList( counts( 'post', p1.id ), [ 42, 40, 2, 9 ] ), JSON.stringify( counts( 'post', p1.id ) ) );
	let r = await rest( 'GET', API + 'views/post/' + p1.id );
	check( 'GET views/post answers 42 and 9', 42 === r.json.views && 9 === r.json.trending, r.text );

	await sleep( 1100 );
	await views( 'post', p2.id, 1 );
	r = await rest( 'POST', API + 'admin/sync-now', { as: admin } );
	check( 'sync-now syncs the one queued object', 200 === r.status && /Processed 1 objects/.test( r.json.message || '' ), r.text.slice( 0, 200 ) );
	r = await rest( 'POST', API + 'admin/sync-now', { as: admin } );
	check( 'and with nothing queued says the provider is connected', 200 === r.status && /No pages in the queue/.test( r.json.message || '' ), r.text.slice( 0, 200 ) );

	const page = await newPage( { as: admin, track: false } );
	await open( page, BASE + '/wp-admin/admin.php?page=mai-analytics&tab=settings' );
	await page.click( '#mai-analytics-sync-now' );
	await page.waitForFunction( () => /queue|complete|failed|error/i.test( document.querySelector( '#mai-analytics-sync-now' )?.parentNode.querySelector( '.mai-analytics-btn-status' )?.innerText || '' ), null, { timeout: 30000 } ).catch( () => {} );
	const status = await page.evaluate( () => document.querySelector( '#mai-analytics-sync-now' )?.parentNode.querySelector( '.mai-analytics-btn-status' )?.innerText || '' );
	check( 'the Sync Now button shows the answer', /No pages in the queue/.test( status ), status );
};

SCENARIOS[ 'provider-breaker' ] = async () => {
	freshState( matomo() );
	fakeReachable();
	const p1 = createPost( 'pb-1' );
	await resetFake( { [ p1.url ]: { trending: 7, all_time: 40 } } );
	await views( 'post', p1.id, 1 );
	runCron( 'mai_analytics_cron_sync' );
	check( 'a first good sync gives P1 40', sameList( counts( 'post', p1.id ), [ 40, 40, 0, 7 ] ), JSON.stringify( counts( 'post', p1.id ) ) );
	const synced = metaOf( 'post', p1.id ).mai_views_synced_at;

	await failFrom( ( await fakeLog() ).length + 1 );
	await sleep( 1100 );
	await views( 'post', p1.id, 1 );
	runCron( 'mai_analytics_cron_sync' );
	const error = php( 'echo \\Mai\\Analytics\\Sync::get_last_error()["message"];' );
	check( 'a failing Matomo leaves the error on file, with its answer', /HTTP 500/.test( error ) && /e2e fake Matomo is down/.test( error ), error );
	check( "P1's counts are kept, not zeroed", sameList( counts( 'post', p1.id ), [ 40, 40, 0, 7 ] ), JSON.stringify( counts( 'post', p1.id ) ) );
	equal( 'and its synced time is not moved', metaOf( 'post', p1.id ).mai_views_synced_at, synced );
	equal( 'the queued row is consumed', rowsFor( bufferRows(), 'post', p1.id, 'web' ), 0 );

	const asked = ( await fakeLog() ).length;
	await views( 'post', p1.id, 1 );
	const now = Math.floor( Date.now() / 1000 );
	runCron( 'mai_analytics_cron_sync' );
	equal( 'the next sync is held back by the breaker and asks nothing', ( await fakeLog() ).length, asked );
	const at = nextScheduled( 'mai_analytics_provider_catchup' );
	check( 'and schedules a catchup for after the backoff', at >= now + 60 && at <= now + 400, at - now );
	equal( 'the queued row waits', rowsFor( bufferRows(), 'post', p1.id, 'web' ), 1 );

	let r = await rest( 'POST', API + 'admin/sync-now', { as: admin } );
	check( 'sync-now answers 502 provider_request_failed', 502 === r.status && 'mai_analytics_provider_request_failed' === r.json.code && /HTTP 500/.test( r.json.message || '' ), r.text.slice( 0, 200 ) );
	r = wpTry( [ 'mai-analytics', 'sync' ] );
	check( 'wp mai-analytics sync skips with a warning and exits 0', 0 === r.code && /Skipped: provider error/.test( r.err + r.out ), r.code + ' ' + r.out + r.err );
};

SCENARIOS[ 'provider-stopped' ] = async () => {
	freshState( matomo() );
	fakeReachable();
	// 201 objects make three batches of up to 100. The fake answers the first
	// batch's ten requests (ten paths each) and fails from the eleventh, so the
	// second batch fails, the run stops, and the third is never reached.
	const bulk = createBulkPosts( 201, admin.id );
	await resetFake( Object.fromEntries( bulk.map( ( p ) => [ p.url, { trending: 2, all_time: 5 } ] ) ) );
	insertRows( [
		...bulk.map( ( p ) => ( { id: p.id, type: 'post', source: 'web', ago: 30 } ) ),
		{ id: bulk[ 150 ].id, type: 'post', source: 'app', ago: 30 },
		{ id: bulk[ 180 ].id, type: 'post', source: 'app', ago: 30 },
		{ id: bulk[ 180 ].id, type: 'post', source: 'app', ago: 30 },
		{ id: bulk[ 200 ].id, type: 'post', source: 'app', ago: 30 },
	] );
	await failFrom( 11 );
	const now = Math.floor( Date.now() / 1000 );
	runCron( 'mai_analytics_cron_sync' );

	equal( 'the run sent eleven requests and stopped', ( await fakeLog() ).length, 11 );
	// Raw rows, null when absent (the keys have a registered default of 0).
	const webCounts = phpJson(
		'echo wp_json_encode( array_map( fn( $id ) => get_metadata_raw( "post", (int) $id, "mai_views_web", true ), $d ) );',
		bulk.map( ( p ) => p.id )
	);
	equal( 'the first batch of 100 has web counts', webCounts.slice( 0, 100 ).filter( ( v ) => '5' === v ).length, 100 );
	equal( 'the failed second batch has none', webCounts.slice( 100, 200 ).filter( ( v ) => null !== v ).length, 0 );
	equal( 'nor does the unreached third', webCounts[ 200 ], null );
	check( 'the failed batch still adds its app views', sameList( counts( 'post', bulk[ 150 ].id ), [ 1, 0, 1, 0 ] ) && sameList( counts( 'post', bulk[ 180 ].id ), [ 2, 0, 2, 0 ] ), JSON.stringify( [ counts( 'post', bulk[ 150 ].id ), counts( 'post', bulk[ 180 ].id ) ] ) );
	check( 'and so does the unreached batch', sameList( counts( 'post', bulk[ 200 ].id ), [ 1, 0, 1, 0 ] ), JSON.stringify( counts( 'post', bulk[ 200 ].id ) ) );
	const rows = bufferRows();
	equal( "the second batch's web rows are consumed", bulk.slice( 100, 200 ).reduce( ( s, p ) => s + rowsFor( rows, 'post', p.id, 'web' ), 0 ), 0 );
	equal( "the third batch's web row is kept", rowsFor( rows, 'post', bulk[ 200 ].id, 'web' ), 1 );
	check( 'the provider error is on file', /HTTP 500/.test( php( 'echo \\Mai\\Analytics\\Sync::get_last_error()["message"];' ) ) );
	const at = nextScheduled( 'mai_analytics_provider_catchup' );
	check( 'a catchup is scheduled about a minute out', at >= now + 30 && at <= now + 180, at - now );

	// The catchup fires while the error is fresh: the breaker holds it back.
	runCron( 'mai_analytics_provider_catchup' );
	equal( 'a catchup inside the backoff asks nothing', ( await fakeLog() ).length, 11 );
	check( 'and schedules itself again', nextScheduled( 'mai_analytics_provider_catchup' ) > 0 );

	// Matomo is back, a new view of the unreached post comes in, and the backoff
	// is over: the catchup finishes the work.
	await failFrom( null );
	await sleep( 1100 );
	await views( 'post', bulk[ 200 ].id, 1 );
	runCron( 'mai_analytics_provider_catchup', { backoffZero: true } );
	equal( 'the catchup asks about the one queued post', ( await fakeLog() ).length, 12 );
	check( 'which gets its web count, its app view kept, trending 2 plus 1', sameList( counts( 'post', bulk[ 200 ].id ), [ 6, 5, 1, 3 ] ), JSON.stringify( counts( 'post', bulk[ 200 ].id ) ) );
	equal( 'the error is cleared', php( 'echo \\Mai\\Analytics\\Sync::get_last_error()["message"];' ), '' );
	equal( 'no catchup is left', nextScheduled( 'mai_analytics_provider_catchup' ), 0 );
	equal( 'the lock is released', php( 'echo get_transient( "mai_analytics_provider_syncing" ) ? 1 : 0;' ), '0' );
};

SCENARIOS[ 'provider-warm' ] = async () => {
	freshState( matomo() );
	fakeReachable();
	const p1 = createPost( 'pw-1' );
	const p2 = createPost( 'pw-2' );
	await resetFake( { [ p1.url ]: { trending: 8, all_time: 50 }, [ p2.url ]: { trending: 0, all_time: 6 } } );
	const ids = p1.id + ',' + p2.id;

	let r = wpTry( [ 'mai-analytics', 'warm', '--type=post', '--ids=' + ids, '--force' ] );
	check( 'warm updates the two posts', 0 === r.code && /Warm complete\. Updated 2 objects/.test( r.out ), r.out + r.err );
	check( 'P1 gets 50, trending 8', sameList( counts( 'post', p1.id ), [ 50, 50, 0, 8 ] ), JSON.stringify( counts( 'post', p1.id ) ) );
	check( 'P2 gets 6, with no trending row', sameList( counts( 'post', p2.id ), [ 6, 6, 0, 0 ] ) && null === metaOf( 'post', p2.id ).mai_trending );
	const asked = ( await fakeLog() ).flatMap( ( q ) => q.subs ).filter( Boolean ).map( ( s ) => pathKey( s.pageUrl ) );
	check( 'and asks about those two only', asked.length > 0 && asked.every( ( k ) => k === pathKey( p1.url ) || k === pathKey( p2.url ) ), JSON.stringify( [ ...new Set( asked ) ] ) );

	const sent = ( await fakeLog() ).length;
	r = wpTry( [ 'mai-analytics', 'warm', '--type=post', '--ids=' + ids ] );
	const more = ( await fakeLog() ).length - sent;
	check( 'without --force it skips posts synced within the hour', 0 === r.code && /Updated 0 objects/.test( r.out ) && 0 === more, r.out + r.err + ' requests: ' + more );

	await failFrom( sent + more + 1 );
	r = wpTry( [ 'mai-analytics', 'warm', '--type=post', '--ids=' + ids, '--force' ] );
	check( 'a failing Matomo updates nothing and says so', 0 === r.code && /Updated 0 of 2 objects/.test( r.out ), r.out + r.err );
	check( 'and keeps the counts', sameList( counts( 'post', p1.id ), [ 50, 50, 0, 8 ] ), JSON.stringify( counts( 'post', p1.id ) ) );
	php( '\\Mai\\Analytics\\Sync::clear_provider_error();' );

	skip( 'the REST warm route is not run in provider mode: it walks every post on the site' );
};

// The upgrade from 1.3.7. The schema and meta keys are the same, so the run
// writes data the way 1.3.7 did, on its own posts only, forces the stored
// version back, and checks what the first request and the cron events do.
SCENARIOS.upgrade = async () => {
	freshState( matomo() );
	fakeReachable();
	const author = createUser( 'up-author', 'author' );
	const u1 = createPost( 'up-1', { author: author.id } );
	const u2 = createPost( 'up-2', { author: author.id } );
	const u3 = createPost( 'up-3', { author: author.id } );
	const old = Math.floor( Date.now() / 1000 ) - 2 * 86400;
	setMeta( 'post', u1.id, { mai_views: 12, mai_views_web: 10, mai_views_app: 2, mai_trending: 4, mai_views_synced_at: old } );
	setMeta( 'post', u2.id, { mai_views: 3, mai_views_web: 3 } );
	setMeta( 'post', u3.id, { mai_trending: 0 } );
	insertRows(
		[
			{ id: u1.id, type: 'post', source: 'web', ago: 600 },
			{ id: u1.id, type: 'post', source: 'app', ago: 600 },
			{ id: u1.id, type: 'post', source: 'app', ago: 600 },
			{ id: u1.id, type: 'post', source: 'app', ago: 600 },
			{ id: u2.id, type: 'post', source: 'web', ago: 600 },
		],
		{ noKey: true }
	);
	const rowsBefore = bufferCount();
	const dbVersion = opt( 'mai_analytics_db_version' );
	const codeVersion = php( 'echo MAI_ANALYTICS_VERSION;' );
	// The last WP-CLI call before the request, since every load of the plugin
	// runs the upgrade check, WP-CLI's included.
	php(
		'update_option( "mai_analytics_provider_last_sync", time() - HOUR_IN_SECONDS, false );' +
			' wp_clear_scheduled_hook( "mai_analytics_prune_trending" ); wp_clear_scheduled_hook( "mai_analytics_prune_trending_now" );' +
			// 1.3.7's ProviderSync::schedule_catchup( time() + 60 ).
			' wp_schedule_single_event( time() + 60, "mai_analytics_provider_catchup" );' +
			' update_option( "mai_analytics_version", "1.3.6", false );'
	);

	gate();
	const home = await fetch( BASE + '/', { headers: { 'User-Agent': UA } } );
	check( 'the first request after the update loads', home.status < 500, home.status );
	equal( 'it records the code version', opt( 'mai_analytics_version' ), codeVersion );
	const pruneNow = nextScheduled( 'mai_analytics_prune_trending_now' );
	check( 'schedules the one-off trending prune now', pruneNow > 0 && pruneNow <= Math.floor( Date.now() / 1000 ) + 5, pruneNow );
	check( 'and the daily prune', nextScheduled( 'mai_analytics_prune_trending' ) > 0 );
	equal( 'the database version is unchanged', opt( 'mai_analytics_db_version' ), dbVersion );
	equal( 'the buffer rows are untouched', bufferCount(), rowsBefore );
	check( "and so are U1's counts", sameList( counts( 'post', u1.id ), [ 12, 10, 2, 4 ] ), JSON.stringify( counts( 'post', u1.id ) ) );
	gate();
	await fetch( BASE + '/', { headers: { 'User-Agent': UA } } );
	equal( 'a second request schedules no second prune', cronEvents().filter( ( e ) => 'mai_analytics_prune_trending_now' === e[ 0 ] ).length, 1 );

	await resetFake( { [ u1.url ]: { trending: 6, all_time: 25 }, [ u2.url ]: { trending: 1, all_time: 4 } } );
	let ran = true;
	try {
		runCron( 'mai_analytics_provider_catchup' );
	} catch ( e ) {
		ran = false;
		check( 'the catchup event left by 1.3.7 runs without a fatal error', false, e.message );
	}
	if ( ran ) {
		check( 'the catchup event left by 1.3.7 runs without a fatal error', true );
		check( 'and syncs: U1 gets 25 plus its 5 app views, trending 6 plus 3', sameList( counts( 'post', u1.id ), [ 30, 25, 5, 9 ] ), JSON.stringify( counts( 'post', u1.id ) ) );
		check( 'U2 gets 4, trending 1', sameList( counts( 'post', u2.id ), [ 4, 4, 0, 1 ] ), JSON.stringify( counts( 'post', u2.id ) ) );
		equal( 'no catchup is left', nextScheduled( 'mai_analytics_provider_catchup' ), 0 );
	}

	runCron( 'mai_analytics_prune_trending_now' );
	equal( "the one-off prune deletes U3's zero trending row", metaOf( 'post', u3.id ).mai_trending, null );
	equal( "and keeps U1's", metaOf( 'post', u1.id ).mai_trending, 9 );
	check( 'the stored version is back as it was before the run', restoreOne( 'mai_analytics_version' ) );
};

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

const teardown = () => ( tearing ??= doTeardown() );

let scenariosOver;
const scenariosDone = new Promise( ( resolve ) => ( scenariosOver = resolve ) );
const STOP_WAIT = 20000;

async function doTeardown() {
	scenarioName = 'teardown';
	currentPage = null;
	if ( browser ) {
		await browser.close().catch( () => {} );
	}
	await sleep( 1000 );
	asTeardown( putBack );
}

// One teardown step: a failure is a failed check, and the next step still runs.
function step( name, fn ) {
	try {
		return fn();
	} catch ( e ) {
		check( name, false, e.message );
		return undefined;
	}
}

function putBack() {
	const firstCheck = results.length;
	stopFake();
	if ( undefined === saved ) {
		return;
	}
	step( 'mai-analytics is put back as it was', () => {
		const active = php( 'require_once ABSPATH . "wp-admin/includes/plugin.php"; echo is_plugin_active( $d ) ? 1 : 0;', PLUGIN );
		if ( ! pluginWasActive && '1' === active ) {
			wpRun( [ 'plugin', 'deactivate', 'mai-analytics' ] );
		}
		equal(
			'mai-analytics is ' + ( pluginWasActive ? 'active' : 'inactive' ),
			php( 'require_once ABSPATH . "wp-admin/includes/plugin.php"; echo is_plugin_active( $d ) ? 1 : 0;', PLUGIN ),
			pluginWasActive ? '1' : '0'
		);
	} );
	step( 'the must-use file is deleted', () => {
		fs.rmSync( muFile, { force: true } );
		check( 'the must-use file is deleted', ! fs.existsSync( muFile ) );
	} );
	step( "the run's posts are deleted", () => {
		const ids = [ ...new Set( [ ...madePosts, ...postIdsForRun() ] ) ];
		const failed = phpJson( '$f = []; foreach ( $d as $id ) { if ( ! wp_delete_post( (int) $id, true ) ) { $f[] = $id; } } echo wp_json_encode( $f );', ids );
		const left = postIdsForRun();
		check( "the run's " + ids.length + ' posts are deleted', 0 === left.length, 'left: ' + left.join( ', ' ) + ' failed: ' + failed.join( ', ' ) );
	} );
	step( "the run's terms are deleted", () => {
		const ids = [ ...new Set( [ ...madeTerms, ...termIdsForRun() ] ) ];
		php( 'foreach ( $d as $id ) { wp_delete_term( (int) $id, "category" ); }', ids );
		const left = termIdsForRun();
		check( "the run's " + ids.length + ' terms are deleted', 0 === left.length, 'left: ' + left.join( ', ' ) );
	} );
	step( "the run's users are deleted", () => {
		const ids = [ ...new Set( [ ...madeUsers, ...userIdsForRun() ] ) ];
		const errors = [];
		for ( const id of ids ) {
			try {
				wpRun( [ 'user', 'delete', String( id ), '--yes' ] );
			} catch ( e ) {
				errors.push( id + ': ' + e.message );
			}
		}
		const left = userIdsForRun();
		check( "the run's " + ids.length + ' users are deleted', 0 === left.length, [ 'left: ' + left.join( ', ' ), ...errors ].join( '; ' ) );
	} );
	if ( healthPostId && ! madePosts.has( healthPostId ) ) {
		step( 'view meta on the health check post is deleted', () => {
			php( 'foreach ( $d["keys"] as $k ) { delete_post_meta( (int) $d["id"], $k ); }', { id: healthPostId, keys: META_KEYS } );
			equal( 'view meta the run caused on post ' + healthPostId + ' (the health check post) is deleted', JSON.stringify( Object.values( metaOf( 'post', healthPostId ) ).filter( ( v ) => null !== v ) ), '[]' );
		} );
	}
	step( 'the buffer is emptied', () => {
		php( BUFFER_TABLE + ' $wpdb->query( "DELETE FROM $t" );' );
		equal( 'the buffer is empty again', bufferCount(), 0 );
	} );
	step( 'the cron events are put back', () => {
		php(
			'foreach ( $d["hooks"] as $h ) { wp_clear_scheduled_hook( $h ); } foreach ( $d["saved"] as [ $h, $ts, $s ] ) { $s ? wp_schedule_event( $ts, $s, $h ) : wp_schedule_single_event( $ts, $h ); }',
			{ hooks: HOOKS, saved: cronAtStart }
		);
		check( 'the mai_analytics cron events are as they were', sameList( cronEvents(), cronAtStart ), JSON.stringify( cronEvents() ) );
	} );
	step( 'the option rows are put back', () => {
		const wrong = restoreRows();
		check( 'every saved option row is back byte for byte, and no row the run made is left (' + Object.keys( saved ).length + ' saved)', 0 === wrong.length, wrong.join( ', ' ) );
	} );
	step( 'no view meta is left', () => {
		const left = viewMetaLeft();
		check( 'no view meta is left anywhere on the site', '{}' === JSON.stringify( left ), JSON.stringify( left ) );
	} );
	step( 'the debug log has no new PHP error from mai-analytics', () => {
		if ( ! logStart.file || ! fs.existsSync( logStart.file ) ) {
			skip( 'no debug log to read' );
			return;
		}
		const fd = fs.openSync( logStart.file, 'r' );
		const size = fs.fstatSync( fd ).size;
		const buf = Buffer.alloc( Math.max( 0, size - logStart.size ) );
		fs.readSync( fd, buf, 0, buf.length, logStart.size );
		fs.closeSync( fd );
		const lines = buf.toString( 'utf8' ).split( '\n' );
		const bad = lines.filter( ( l ) => /PHP (Fatal error|Parse error|Warning|Notice)/.test( l ) && /mai-analytics/.test( l ) );
		check( 'the debug log has no new PHP fatal, warning or notice from mai-analytics', 0 === bad.length, bad.slice( 0, 3 ).join( ' | ' ) );
		const logged = lines.filter( ( l ) => /mai-analytics/i.test( l ) && ! /PHP /.test( l ) ).length;
		if ( logged ) {
			console.log( '      the plugin logged ' + logged + ' lines during the run (the provider scenarios log their failures)' );
		}
	} );
	if ( results.slice( firstCheck ).every( ( r ) => r.ok ) ) {
		fs.rmSync( MARKER, { force: true } );
	} else {
		console.error(
			'\n!!! Cleanup did not finish. ' +
				MARKER +
				' stays, so the next run stops until you finish it by hand.\n' +
				recoverySteps( JSON.parse( markerJson() ) ) +
				'\n'
		);
	}
}

function summary( started ) {
	const failed = results.filter( ( r ) => ! r.ok );
	console.log(
		'\n' +
			( results.length - failed.length ) +
			' passed, ' +
			failed.length +
			' failed, ' +
			results.length +
			' checks in ' +
			Math.round( ( Date.now() - started ) / 1000 ) +
			's'
	);
	if ( skipped.length ) {
		console.log( skipped.length + ' skipped, not counted as passing:\n' + skipped.map( ( r ) => '  ' + r ).join( '\n' ) );
	}
	if ( notes.length ) {
		console.log( 'Notes:\n' + notes.map( ( r ) => '  ' + r ).join( '\n' ) );
	}
	if ( failed.length ) {
		console.log( 'Failed:\n' + failed.map( ( r ) => '  ' + r.name ).join( '\n' ) );
	}
	return failed.length ? 1 : 0;
}

function refuse( message ) {
	console.error( 'refusing to run: ' + message );
	process.exit( 2 );
}

( async () => {
	const started = Date.now();
	if ( fs.existsSync( MARKER ) ) {
		refuseMarker();
	}
	fs.mkdirSync( OUT, { recursive: true } );
	const names = ONLY || Object.keys( SCENARIOS );
	const unknown = names.filter( ( n ) => ! SCENARIOS[ n ] );
	if ( unknown.length ) {
		console.error( 'unknown scenario: ' + unknown.join( ', ' ) + '\nscenarios: ' + Object.keys( SCENARIOS ).join( ', ' ) );
		process.exit( 2 );
	}

	let site;
	try {
		site = phpJson(
			'require_once ABSPATH . "wp-admin/includes/plugin.php"; global $wpdb;' +
				' $log = defined( "WP_DEBUG_LOG" ) ? ( true === WP_DEBUG_LOG ? WP_CONTENT_DIR . "/debug.log" : ( is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : "" ) ) : "";' +
				' echo wp_json_encode( [ "rest" => rest_url(), "active" => is_plugin_active( $d ), "installed" => file_exists( WP_PLUGIN_DIR . "/" . $d ), "mu" => WPMU_PLUGIN_DIR, "log" => $log,' +
				' "table" => (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . "mai_analytics_buffer" ) ) ] );',
			PLUGIN
		);
	} catch ( e ) {
		console.error( 'WP-CLI could not load the site at ' + args.wp + ': ' + e.message );
		process.exit( 2 );
	}
	if ( ! site.installed ) {
		refuse( 'mai-analytics is not in the plugins folder at ' + args.wp + ' as ' + PLUGIN + '.' );
	}
	REST_ROOT = site.rest;
	let restHost = '';
	try {
		restHost = new URL( REST_ROOT ).hostname;
	} catch ( e ) {
		restHost = '';
	}
	if ( restHost !== baseHost ) {
		refuse( '--wp and --base point at different sites, or the site home is not local. rest_url() is ' + REST_ROOT + ' but --base is ' + BASE + '.' );
	}

	const left = leftovers();
	if ( left.users.length || left.posts.length || left.terms.length || left.mu.length ) {
		refuse(
			'the site still has e2e-analytics-* data from an earlier run that may not have cleaned up: ' +
				JSON.stringify( left ) +
				'. Delete those users (wp user delete <id> --yes), posts (wp post delete <id> --force), terms and must-use files, check the mai_analytics options against your baseline, then run again.'
		);
	}
	if ( site.table ) {
		const n = Number( php( BUFFER_TABLE + ' echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );' ) );
		if ( n ) {
			refuse( 'the buffer table holds ' + n + ' rows. The run empties it at the end, so it only starts on an empty one.' );
		}
	}
	const meta = viewMetaLeft();
	if ( '{}' !== JSON.stringify( meta ) ) {
		refuse( 'the site has view meta already: ' + JSON.stringify( meta ) + '. The run checks that none is left at the end, so it only starts without any.' );
	}

	for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
		process.on( signal, () => {
			if ( stopping ) {
				console.log( '\n' + signal + ' ignored: already stopping, cleaning up first' );
				return;
			}
			stopping = true;
			console.log( '\nstopping on ' + signal + ', cleaning up first' );
			try {
				browser?.close().catch( () => {} );
			} catch ( e ) {
				// Teardown closes it again.
			}
			Promise.race( [ scenariosDone, sleep( STOP_WAIT ) ] )
				.then( teardown )
				.catch( ( e ) => console.error( 'cleanup failed: ' + e.message ) )
				.then( () => process.exit( 130 ) );
		} );
	}

	try {
		pluginWasActive = !! site.active;
		cronAtStart = cronEvents();
		muFile = path.join( site.mu, 'e2e-analytics-' + RUN + '.php' );
		if ( site.log && fs.existsSync( site.log ) ) {
			logStart = { file: site.log, size: fs.statSync( site.log ).size };
		} else {
			logStart = { file: site.log, size: 0 };
		}
		saved = readRows();
		writeMarker();

		fs.mkdirSync( site.mu, { recursive: true } );
		fs.writeFileSync(
			muFile,
			[
				'<?php',
				'/**',
				' * Written by mai-analytics bin/e2e.js for run ' + RUN + ', and deleted by its teardown.',
				' * Turns the tracker on for requests carrying this run\'s cookie, and registers',
				' * a public post type so a post type archive can be counted.',
				' */',
				"if ( isset( $_COOKIE['e2e_analytics'] ) && '" + RUN + "' === $_COOKIE['e2e_analytics'] ) {",
				"\tadd_filter( 'mai_analytics_tracking_enabled', '__return_true' );",
				'}',
				"add_action( 'init', function () {",
				"\tregister_post_type( '" + CPT + "', [ 'label' => 'E2e analytics', 'public' => true, 'has_archive' => true, 'rewrite' => false, 'query_var' => '" + CPT + "' ] );",
				'} );',
				'',
			].join( '\n' )
		);

		if ( ! pluginWasActive ) {
			wpRun( [ 'plugin', 'activate', 'mai-analytics' ] );
		}
		const loaded = phpJson( 'echo wp_json_encode( [ defined( "MAI_ANALYTICS_VERSION" ) ? MAI_ANALYTICS_VERSION : "", defined( "MAI_ANALYTICS_PLUGIN_DIR" ) ? realpath( MAI_ANALYTICS_PLUGIN_DIR ) : "" ] );' );
		if ( ! loaded[ 0 ] ) {
			throw new Error( 'mai-analytics did not load after activation' );
		}
		console.log( 'mai-analytics e2e on ' + BASE + ', run ' + RUN );
		console.log( 'mai-analytics ' + loaded[ 0 ] + ' from ' + loaded[ 1 ] );
		console.log( 'fake Matomo on ' + FAKE_URL + '\n' );

		await startFake();
		admin = createUser( 'admin', 'administrator' );
		browser = await chromium.launch( {
			headless: true,
			channel: 'chrome',
			handleSIGINT: false,
			handleSIGTERM: false,
			handleSIGHUP: false,
		} );

		if ( undefined !== args[ 'signal-after' ] ) {
			const wait = Math.max( 0, parseInt( args[ 'signal-after' ], 10 ) || 0 );
			console.log( '--signal-after: SIGINT to this process in ' + wait + ' ms\n' );
			setTimeout( () => process.kill( process.pid, 'SIGINT' ), wait );
			for ( let i = 0; 0 === wait && ! stopping && i < 500; i++ ) {
				await sleep( 10 );
			}
		}

		for ( const name of names ) {
			if ( stopping ) {
				break;
			}
			scenarioName = name;
			let failed = false;
			try {
				await SCENARIOS[ name ]();
			} catch ( e ) {
				currentPage = null;
				const why = e.message.split( '\n' ).filter( ( l ) => l.trim() && ! /^=+/.test( l.trim() ) ).slice( 0, 3 ).join( ' / ' );
				if ( stopping ) {
					skip( 'stopped before the end (' + why + ')' );
				} else {
					failed = true;
					check( 'ran to the end', false, why );
				}
			}
			await closeAll( failed );
		}
	} catch ( e ) {
		scenarioName = 'setup';
		if ( stopping ) {
			skip( 'stopped during setup (' + e.message + ')' );
		} else {
			check( 'set up', false, e.message );
		}
	} finally {
		scenariosOver();
	}
	await teardown();
	for ( const fn of afterTeardown ) {
		try {
			asTeardown( fn );
		} catch ( e ) {
			check( 'after-teardown check ran', false, e.message );
		}
	}
	const code = summary( started );
	process.exit( stopping ? 130 : code );
} )();
