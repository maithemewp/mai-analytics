(function () {
	'use strict';

	var API     = maiAnalytics.restBase;
	var headers = { 'X-WP-Nonce': maiAnalytics.nonce };

	// State.
	var activeTab     = 'posts';
	var chartMetric   = 'total';
	var chartSource   = 'all';
	var chartInstance  = null;
	var currentPage   = 1;
	var currentOrderby = 'views';
	var searchQuery   = '';
	var searchTimer   = null;
	var filtersData   = null;

	/**
	 * Initialize on DOM ready.
	 */
	document.addEventListener('DOMContentLoaded', function () {
		loadSummary();
		loadChart();
		loadFilters();
		loadTable();
		bindEvents();
	});

	/**
	 * Bind click and change events.
	 */
	function bindEvents() {
		// Tab switching.
		document.querySelectorAll('.mai-analytics-tabs .nav-tab').forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				document.querySelector('.nav-tab-active').classList.remove('nav-tab-active');
				this.classList.add('nav-tab-active');
				activeTab      = this.dataset.tab;
				currentPage    = 1;
				currentOrderby = 'views';
				searchQuery    = '';
				document.getElementById('mai-analytics-search').value = '';
				updateFilterVisibility();
				loadTable();
			});
		});

		// Chart toggles.
		document.querySelectorAll('.mai-analytics-toggle-group .button').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var group = this.closest('.mai-analytics-toggle-group');
				group.querySelector('.active').classList.remove('active');
				this.classList.add('active');

				if (group.dataset.toggle === 'metric') {
					chartMetric = this.dataset.value;
				} else {
					chartSource = this.dataset.value;
				}

				loadChart();
			});
		});

		// Filter changes.
		document.getElementById('mai-analytics-post-type').addEventListener('change', function () {
			currentPage = 1;
			loadTable();
		});

		document.getElementById('mai-analytics-taxonomy').addEventListener('change', function () {
			currentPage = 1;
			updateTermDropdown();
			loadTable();
		});

		// Per-page selector.
		document.getElementById('mai-analytics-per-page').addEventListener('change', function () {
			currentPage = 1;
			loadTable();
		});

		// Table search.
		document.getElementById('mai-analytics-search').addEventListener('input', function () {
			clearTimeout(searchTimer);
			var val = this.value.trim();
			searchTimer = setTimeout(function () {
				searchQuery = val;
				currentPage = 1;
				loadTable();
			}, 300);
		});

		// Author autocomplete.
		initAutocomplete({
			wrapperId:  'mai-analytics-author-wrap',
			searchType: 'author',
			getParams:  function () { return {}; },
			onChange:    function () {
				currentPage = 1;
				loadTable();
			},
		});

		// Term autocomplete.
		initAutocomplete({
			wrapperId:  'mai-analytics-term-wrap',
			searchType: 'term',
			getParams:  function () {
				return { taxonomy: document.getElementById('mai-analytics-taxonomy').value };
			},
			onChange: function () {
				currentPage = 1;
				loadTable();
			},
		});
	}

	/**
	 * Fetch summary data and populate cards.
	 */
	function loadSummary() {
		apiFetch('summary').then(function (data) {
			setCardValue('total_views', formatNumber(data.total_views));
			setCardValue('views_today', formatNumber(data.views_today));
			setCardValue('last_sync', data.last_sync || '—');
			setCardValue('trending_count', formatNumber(data.trending_count));
			setCardValue('buffer_rows', formatNumber(data.buffer_rows));
		});
	}

	/**
	 * Fetch chart data and render Chart.js.
	 */
	function loadChart() {
		if (typeof Chart === 'undefined') {
			return;
		}

		var params = new URLSearchParams({ metric: chartMetric, source: chartSource });

		apiFetch('chart?' + params.toString()).then(function (data) {
			var canvas = document.getElementById('mai-analytics-chart');
			var ctx    = canvas.getContext('2d');

			if (chartInstance) {
				chartInstance.destroy();
			}

			var colors   = ['#2271b1', '#d63638', '#00a32a'];
			var datasets = data.datasets.map(function (ds, i) {
				var color = colors[i] || colors[0];
				return {
					label:           ds.label,
					data:            ds.data,
					borderColor:     color,
					backgroundColor: color + '20',
					fill:            true,
					tension:         0.3,
					pointRadius:     4,
				};
			});

			chartInstance = new Chart(ctx, {
				type: 'line',
				data: {
					labels:   data.labels,
					datasets: datasets,
				},
				options: {
					responsive:          true,
					maintainAspectRatio: false,
					interaction: { intersect: false, mode: 'index' },
					scales: {
						y: {
							beginAtZero: true,
							ticks: { callback: function (v) { return formatNumber(v); } },
						},
					},
					plugins: {
						legend: { display: datasets.length > 1 },
						tooltip: {
							callbacks: {
								label: function (ctx) { return ctx.dataset.label + ': ' + formatNumber(ctx.raw); },
							},
						},
					},
				},
			});
		});
	}

	/**
	 * Fetch filter options and populate dropdowns.
	 */
	function loadFilters() {
		apiFetch('filters').then(function (data) {
			filtersData = data;

			var ptSelect = document.getElementById('mai-analytics-post-type');
			data.post_types.forEach(function (pt) {
				ptSelect.add(new Option(pt.label, pt.slug));
			});

			var taxSelect = document.getElementById('mai-analytics-taxonomy');
			data.taxonomies.forEach(function (tax) {
				taxSelect.add(new Option(tax.label, tax.slug));
			});

			updateFilterVisibility();
			updateTermDropdown();
		});
	}

	/**
	 * Fetch table data based on active tab and filters.
	 */
	function loadTable() {
		var endpoint = 'top/' + activeTab;
		var params   = new URLSearchParams({
			orderby:  currentOrderby,
			page:     currentPage,
			per_page: document.getElementById('mai-analytics-per-page').value,
		});

		if (activeTab === 'posts') {
			var pt     = document.getElementById('mai-analytics-post-type').value;
			var tax    = document.getElementById('mai-analytics-taxonomy').value;
			var term   = document.getElementById('mai-analytics-term').value;
			var author = document.getElementById('mai-analytics-author').value;

			if (pt)     params.set('post_type', pt);
			if (tax)    params.set('taxonomy', tax);
			if (term)   params.set('term_id', term);
			if (author) params.set('author', author);
		} else if (activeTab === 'terms') {
			var tax2 = document.getElementById('mai-analytics-taxonomy').value;
			if (tax2) params.set('taxonomy', tax2);
		}

		if (searchQuery) {
			params.set('search', searchQuery);
		}

		showLoading(true);

		apiFetch(endpoint + '?' + params.toString()).then(function (data) {
			renderTable(data);
			renderPagination(data.total, data.pages);
			showLoading(false);
		}).catch(function () {
			showLoading(false);
		});
	}

	/**
	 * Render table rows using safe DOM methods.
	 */
	function renderTable(data) {
		var table = document.querySelector('.mai-analytics-table');
		var thead = table.querySelector('thead tr');
		var tbody = table.querySelector('tbody');
		var empty = document.querySelector('.mai-analytics-empty');

		// Clear existing content safely.
		while (thead.firstChild) thead.removeChild(thead.firstChild);
		while (tbody.firstChild) tbody.removeChild(tbody.firstChild);

		if (!data.items || data.items.length === 0) {
			table.style.display = 'none';
			empty.style.display = '';
			return;
		}

		table.style.display = '';
		empty.style.display = 'none';

		var columns = getColumns();

		// Build header.
		columns.forEach(function (col) {
			var th       = document.createElement('th');
			th.className = 'column-' + col.key;
			th.textContent = col.label;

			if (col.key === 'views' || col.key === 'trending') {
				th.classList.add('sortable');
				if (currentOrderby === col.key) {
					th.classList.add('sorted');
				}
				th.addEventListener('click', function () {
					currentOrderby = col.key;
					currentPage = 1;
					loadTable();
				});
			}

			thead.appendChild(th);
		});

		// Build rows.
		data.items.forEach(function (item) {
			var tr = document.createElement('tr');

			columns.forEach(function (col) {
				var td       = document.createElement('td');
				td.className = 'column-' + col.key;

				if (col.key === 'title' || col.key === 'name') {
					var val = item.title || item.name || '(no title)';
					if (item.url) {
						var a      = document.createElement('a');
						a.href     = item.url;
						a.target   = '_blank';
						a.textContent = val;
						td.appendChild(a);
					} else {
						td.textContent = val;
					}
				} else if (col.key === 'views' || col.key === 'trending' || col.key === 'web' || col.key === 'app') {
					td.textContent = formatNumber(item[col.key] || 0);
				} else {
					td.textContent = item[col.key] || '';
				}

				tr.appendChild(td);
			});

			tbody.appendChild(tr);
		});
	}

	/**
	 * Get column definitions for the active tab.
	 */
	function getColumns() {
		var cols = {
			posts: [
				{ key: 'title', label: 'Title' },
				{ key: 'post_type', label: 'Type' },
				{ key: 'views', label: 'Views' },
				{ key: 'trending', label: 'Trending' },
				{ key: 'web', label: 'Web' },
				{ key: 'app', label: 'App' },
			],
			terms: [
				{ key: 'name', label: 'Name' },
				{ key: 'taxonomy', label: 'Taxonomy' },
				{ key: 'views', label: 'Views' },
				{ key: 'trending', label: 'Trending' },
				{ key: 'web', label: 'Web' },
				{ key: 'app', label: 'App' },
			],
			authors: [
				{ key: 'name', label: 'Author' },
				{ key: 'views', label: 'Views' },
				{ key: 'trending', label: 'Trending' },
				{ key: 'web', label: 'Web' },
				{ key: 'app', label: 'App' },
			],
			archives: [
				{ key: 'name', label: 'Post Type' },
				{ key: 'views', label: 'Views' },
				{ key: 'trending', label: 'Trending' },
				{ key: 'web', label: 'Web' },
				{ key: 'app', label: 'App' },
			],
		};

		return cols[activeTab] || [];
	}

	/**
	 * Render pagination controls using safe DOM methods.
	 */
	function renderPagination(total, pages) {
		var wrap = document.querySelector('.mai-analytics-pagination');
		var info = wrap.querySelector('.mai-analytics-pagination__info');
		var btns = wrap.querySelector('.mai-analytics-pagination__buttons');

		if (pages <= 1) {
			wrap.style.display = 'none';
			return;
		}

		wrap.style.display = '';
		info.textContent = 'Page ' + currentPage + ' of ' + pages + ' (' + formatNumber(total) + ' items)';

		// Clear buttons safely.
		while (btns.firstChild) btns.removeChild(btns.firstChild);

		// Previous.
		if (currentPage > 1) {
			btns.appendChild(createPageButton('\u2039 Prev', currentPage - 1));
		}

		// Page numbers (max 5 centered around current).
		var start = Math.max(1, currentPage - 2);
		var end   = Math.min(pages, start + 4);
		start     = Math.max(1, end - 4);

		for (var i = start; i <= end; i++) {
			btns.appendChild(createPageButton(String(i), i));
		}

		// Next.
		if (currentPage < pages) {
			btns.appendChild(createPageButton('Next \u203A', currentPage + 1));
		}
	}

	/**
	 * Create a pagination button element.
	 */
	function createPageButton(label, page) {
		var btn       = document.createElement('button');
		btn.className = 'button';
		btn.textContent = label;

		if (page === currentPage) {
			btn.classList.add('current');
		} else {
			btn.addEventListener('click', function () {
				currentPage = page;
				loadTable();
			});
		}

		return btn;
	}

	/**
	 * Show/hide filters based on active tab.
	 */
	function updateFilterVisibility() {
		var showPosts = (activeTab === 'posts');
		var showTerms = (activeTab === 'terms' || activeTab === 'posts');

		document.querySelectorAll('.mai-analytics-filter-posts').forEach(function (el) {
			el.style.display = showPosts ? '' : 'none';
		});

		document.querySelectorAll('.mai-analytics-filter-terms').forEach(function (el) {
			el.style.display = showTerms ? '' : 'none';
		});

		// Hide all filters for authors and archives tabs.
		if (activeTab === 'authors' || activeTab === 'archives') {
			document.querySelectorAll('.mai-analytics-filters select, .mai-analytics-filters .mai-analytics-autocomplete').forEach(function (el) {
				el.style.display = 'none';
			});
		}
	}

	/**
	 * Update the term dropdown based on selected taxonomy.
	 */
	function updateTermDropdown() {
		var termWrap = document.getElementById('mai-analytics-term-wrap');
		var taxonomy = document.getElementById('mai-analytics-taxonomy').value;

		if (!taxonomy) {
			termWrap.style.display = 'none';
			// Clear any existing selection.
			clearAutocomplete(termWrap);
			return;
		}

		termWrap.style.display = '';
	}

	/**
	 * Initialize an AJAX autocomplete on a wrapper element.
	 */
	function initAutocomplete(config) {
		var wrap    = document.getElementById(config.wrapperId);
		var input   = wrap.querySelector('input[type="text"]');
		var hidden  = wrap.querySelector('input[type="hidden"]');
		var clear   = wrap.querySelector('.mai-analytics-autocomplete__clear');
		var results = wrap.querySelector('.mai-analytics-autocomplete__results');
		var timer   = null;

		input.addEventListener('input', function () {
			clearTimeout(timer);
			var val = input.value.trim();

			if (val.length < 2) {
				results.style.display = 'none';
				return;
			}

			timer = setTimeout(function () {
				var params = new URLSearchParams({ type: config.searchType, search: val });
				var extra  = config.getParams ? config.getParams() : {};

				Object.keys(extra).forEach(function (k) {
					if (extra[k]) params.set(k, extra[k]);
				});

				apiFetch('search?' + params.toString()).then(function (items) {
					while (results.firstChild) results.removeChild(results.firstChild);

					if (!items.length) {
						var noResult       = document.createElement('div');
						noResult.className = 'mai-analytics-autocomplete__no-results';
						noResult.textContent = 'No results found';
						results.appendChild(noResult);
						results.style.display = '';
						return;
					}

					items.forEach(function (item) {
						var div       = document.createElement('div');
						div.className = 'mai-analytics-autocomplete__item';
						div.textContent = item.name;
						div.dataset.id  = item.id;

						div.addEventListener('click', function () {
							hidden.value          = item.id;
							input.value           = item.name;
							input.readOnly        = true;
							clear.style.display   = '';
							results.style.display = 'none';
							config.onChange(item.id);
						});

						results.appendChild(div);
					});

					results.style.display = '';
				});
			}, 300);
		});

		clear.addEventListener('click', function () {
			hidden.value          = '';
			input.value           = '';
			input.readOnly        = false;
			clear.style.display   = 'none';
			results.style.display = 'none';
			config.onChange('');
		});

		// Close results when clicking outside.
		document.addEventListener('click', function (e) {
			if (!wrap.contains(e.target)) {
				results.style.display = 'none';
			}
		});
	}

	/**
	 * Clear an autocomplete wrapper's selection.
	 */
	function clearAutocomplete(wrap) {
		var hidden  = wrap.querySelector('input[type="hidden"]');
		var input   = wrap.querySelector('input[type="text"]');
		var clear   = wrap.querySelector('.mai-analytics-autocomplete__clear');
		var results = wrap.querySelector('.mai-analytics-autocomplete__results');

		hidden.value          = '';
		input.value           = '';
		input.readOnly        = false;
		clear.style.display   = 'none';
		results.style.display = 'none';
	}

	/**
	 * Show or hide loading state.
	 */
	function showLoading(show) {
		document.querySelector('.mai-analytics-loading').style.display = show ? '' : 'none';
		document.querySelector('.mai-analytics-table').style.display   = show ? 'none' : '';

		// Only hide pagination when loading starts. renderPagination() controls whether it shows.
		if (show) {
			document.querySelector('.mai-analytics-pagination').style.display = 'none';
		}
	}

	/**
	 * Set a summary card value.
	 */
	function setCardValue(key, value) {
		var card = document.querySelector('[data-card="' + key + '"] .mai-analytics-card__value');
		if (card) {
			card.textContent = value;
		}
	}

	/**
	 * Fetch from the admin REST API.
	 */
	function apiFetch(path) {
		return fetch(API + path, { headers: headers })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('API error: ' + response.status);
				}
				return response.json();
			});
	}

	/**
	 * Format a number with locale-aware commas.
	 */
	function formatNumber(n) {
		return Number(n).toLocaleString();
	}
})();
