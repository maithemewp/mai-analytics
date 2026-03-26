/**
 * Mai Analytics Settings Page JS.
 *
 * Handles: data source field show/hide, Warm Stats button, Sync Now button.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var dataSourceSelect = document.getElementById('mai-analytics-data-source');
		var syncBtn          = document.getElementById('mai-analytics-sync-now');
		var warmBtn          = document.getElementById('mai-analytics-warm');
		var statusEl         = document.getElementById('mai-analytics-sync-status');

		// Toggle Matomo fields visibility based on data source.
		if (dataSourceSelect) {
			toggleMatomoFields();
			dataSourceSelect.addEventListener('change', toggleMatomoFields);
		}

		// Sync Now button.
		if (syncBtn) {
			syncBtn.addEventListener('click', function () {
				syncBtn.disabled = true;
				statusEl.textContent = 'Syncing...';

				fetch(maiAnalyticsSettings.restBase + 'sync-now', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': maiAnalyticsSettings.nonce,
						'Content-Type': 'application/json',
					},
				})
					.then(function (r) { return r.json(); })
					.then(function (data) {
						statusEl.textContent = data.message || 'Sync complete.';
						syncBtn.disabled = false;
					})
					.catch(function () {
						statusEl.textContent = 'Sync failed.';
						syncBtn.disabled = false;
					});
			});
		}

		// Warm Stats button.
		if (warmBtn) {
			warmBtn.addEventListener('click', function () {
				warmBtn.disabled = true;
				statusEl.textContent = 'Warming stats...';

				fetch(maiAnalyticsSettings.restBase + 'warm', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': maiAnalyticsSettings.nonce,
						'Content-Type': 'application/json',
					},
				})
					.then(function (r) { return r.json(); })
					.then(function (data) {
						statusEl.textContent = data.message || 'Warm complete.';
						warmBtn.disabled = false;
					})
					.catch(function () {
						statusEl.textContent = 'Warm failed.';
						warmBtn.disabled = false;
					});
			});
		}

		function toggleMatomoFields() {
			var matomoSection = document.getElementById('mai_analytics_matomo');

			if (!matomoSection) {
				return;
			}

			// Find the section heading and all rows until the next section.
			var heading = matomoSection.closest('tr') || matomoSection;
			var rows    = [];
			var el      = heading.nextElementSibling;

			// Walk sibling rows in the settings table.
			var table = document.querySelector('.form-table');

			if (!table) {
				return;
			}

			var allRows   = table.querySelectorAll('tr');
			var inSection = false;

			allRows.forEach(function (row) {
				if (row.querySelector('#mai_analytics_matomo') || row.querySelector('th:empty + td #mai_analytics_matomo')) {
					inSection = true;
					rows.push(row);
					return;
				}

				if (inSection) {
					// Stop at next section heading.
					if (row.querySelector('th[colspan]') || row.querySelector('h2')) {
						inSection = false;
						return;
					}

					var input = row.querySelector('input[name*="matomo"]');

					if (input) {
						rows.push(row);
					}
				}
			});

			var show = dataSourceSelect && dataSourceSelect.value === 'matomo';

			rows.forEach(function (row) {
				row.style.display = show ? '' : 'none';
			});
		}
	});
})();
