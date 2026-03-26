/**
 * Mai Analytics Settings Page JS.
 *
 * Handles Warm Stats and Sync Now buttons.
 * Provider field visibility is handled by CSS :has().
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		bindButton('mai-analytics-sync-now', 'sync-now', 'Syncing...');
		bindButton('mai-analytics-warm', 'warm', 'Warming stats...');
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

	function showStatus(el, text, color) {
		if (!el) return;
		el.textContent = text;
		el.style.color = color;
		el.style.display = text ? 'block' : 'none';
	}
})();
