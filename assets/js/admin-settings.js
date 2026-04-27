/**
 * Mai Views Settings Page JS.
 *
 * Handles Warm Stats and Sync Now buttons.
 * Provider field visibility is handled by CSS :has().
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		bindButton('mai-analytics-sync-now', 'sync-now', 'Syncing...');
		bindWarmButton('mai-analytics-warm');
		bindHealthCheck();
	});

	function bindButton(btnId, endpoint, loadingText) {
		var btn = document.getElementById(btnId);

		if (!btn) {
			return;
		}

		var statusEl = btn.parentNode.querySelector('.mai-analytics-btn-status');

		btn.addEventListener('click', function () {
			btn.disabled = true;
			showStatus(statusEl, loadingText, '#666');

			fetch(maiAnalyticsSettings.restBase + endpoint, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': maiAnalyticsSettings.nonce,
					'Content-Type': 'application/json',
				},
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var isError = !data.message || data.code || data.message.match(/error|can't access|not available|not found|failed/i);
					showStatus(statusEl, data.message || 'Done.', isError ? '#d63638' : '#00a32a');
					btn.disabled = false;
				})
				.catch(function () {
					showStatus(statusEl, 'Request failed.', '#d63638');
					btn.disabled = false;
				});
		});
	}

	/**
	 * Warm Stats button polling loop.
	 *
	 * The /admin/warm endpoint processes one ProviderSync::warm() batch per
	 * request and returns progress + a next_cursor. The client drives the loop
	 * here so each request finishes well within Cloudflare's 100-second 524
	 * gateway window. On error we abort the loop and surface the message;
	 * partial progress is preserved server-side because per-batch meta writes
	 * commit independently inside ProviderSync::warm().
	 */
	function bindWarmButton(btnId) {
		var btn = document.getElementById(btnId);

		if (!btn) {
			return;
		}

		var statusEl = btn.parentNode.querySelector('.mai-analytics-btn-status');

		btn.addEventListener('click', async function () {
			btn.disabled = true;
			showStatus(statusEl, 'Warming stats...', '#666');

			var cursor = 0;
			var totalUpdated = 0;
			// Defensive safety bound: more than 10k batches is almost certainly a
			// server bug, not a real workload.
			var maxIterations = 10000;
			var iterations = 0;

			try {
				while (iterations++ < maxIterations) {
					var res = await fetch(maiAnalyticsSettings.restBase + 'warm', {
						method: 'POST',
						headers: {
							'X-WP-Nonce': maiAnalyticsSettings.nonce,
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({ cursor: cursor, total_updated: totalUpdated }),
					});

					var data = await res.json();

					if (!res.ok) {
						showStatus(statusEl, data.message || 'Warm failed.', '#d63638');
						btn.disabled = false;
						return;
					}

					totalUpdated = data.total_updated || totalUpdated;

					if (data.done) {
						showStatus(
							statusEl,
							data.message || ('Warm complete. Updated ' + totalUpdated + ' objects.'),
							'#00a32a'
						);
						btn.disabled = false;
						return;
					}

					if (data.total) {
						showStatus(
							statusEl,
							'Batch ' + data.batch + ' of ' + data.total + ' · ' + totalUpdated + ' updated',
							'#666'
						);
					}

					cursor = data.next_cursor;
				}

				showStatus(statusEl, 'Warm aborted: too many iterations.', '#d63638');
				btn.disabled = false;
			} catch (e) {
				showStatus(statusEl, 'Request failed.', '#d63638');
				btn.disabled = false;
			}
		});
	}

	function bindHealthCheck() {
		var btn = document.getElementById('mai-analytics-health-check');
		var resultsEl = document.getElementById('mai-analytics-health-results');

		if (!btn || !resultsEl) {
			return;
		}

		btn.addEventListener('click', function () {
			btn.disabled = true;
			btn.textContent = 'Running...';
			resultsEl.style.display = 'block';
			resultsEl.textContent = 'Running health checks...';

			fetch(maiAnalyticsSettings.restBase + 'health', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': maiAnalyticsSettings.nonce,
					'Content-Type': 'application/json',
				},
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					renderHealthResults(resultsEl, data);
					btn.disabled = false;
					btn.textContent = 'Run Health Check';
				})
				.catch(function () {
					resultsEl.textContent = 'Request failed.';
					resultsEl.style.color = '#d63638';
					btn.disabled = false;
					btn.textContent = 'Run Health Check';
				});
		});
	}

	function renderHealthResults(container, data) {
		// Clear previous results safely.
		while (container.firstChild) {
			container.removeChild(container.firstChild);
		}

		var icons = { pass: '\u2713', fail: '\u2717', warn: '!' };
		var colors = { pass: '#00a32a', fail: '#d63638', warn: '#dba617' };
		var currentSection = '';

		data.checks.forEach(function (c) {
			if (c.section !== currentSection) {
				currentSection = c.section;
				var heading = document.createElement('h4');
				heading.textContent = currentSection;
				heading.style.cssText = 'margin:12px 0 4px; font-size:13px; text-transform:uppercase; color:#646970;';
				container.appendChild(heading);
			}

			var row = document.createElement('div');
			row.style.cssText = 'padding:2px 0; font-size:13px;';

			var icon = document.createElement('span');
			icon.textContent = icons[c.status] || '?';
			icon.style.cssText = 'color:' + (colors[c.status] || '#666') + '; font-weight:600; margin-right:6px;';
			row.appendChild(icon);

			var label = document.createTextNode(c.label);
			row.appendChild(label);

			if (c.detail) {
				var detail = document.createElement('span');
				detail.textContent = ' \u2014 ' + c.detail;
				detail.style.color = '#646970';
				row.appendChild(detail);
			}

			container.appendChild(row);
		});

		// Summary.
		var summaryColor = data.fail > 0 ? '#d63638' : (data.warn > 0 ? '#dba617' : '#00a32a');
		var summary = document.createElement('div');
		summary.textContent = data.pass + ' passed, ' + data.fail + ' failed, ' + data.warn + ' warnings';
		summary.style.cssText = 'margin-top:12px; padding-top:12px; border-top:1px solid #c3c4c7; font-weight:600; color:' + summaryColor + ';';
		container.appendChild(summary);
	}

	function showStatus(el, text, color) {
		if (!el) return;
		el.textContent = text;
		el.style.color = color;
		el.style.display = text ? 'block' : 'none';
	}
})();
