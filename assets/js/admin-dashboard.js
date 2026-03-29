(function () {
	'use strict';

	var API     = maiViews.restBase;
	var headers = { 'X-WP-Nonce': maiViews.nonce };

	// State.
	var activeTab      = 'posts';
	var chartInstance  = null;
	var currentPage   = 1;
	var currentOrderby = 'views';
	var currentOrder   = 'desc';
	var searchQuery   = '';
	var searchTimer   = null;
	var filtersData   = null;
	var authorSelect  = null;
	var termSelect    = null;

	/**
	 * Initialize on DOM ready.
	 */
	document.addEventListener('DOMContentLoaded', function () {
		initSelects();
		loadSummary();
		loadFilters();
		loadTable();
		bindEvents();
	});

	/**
	 * Bind click and change events.
	 */
	function bindEvents() {
		// Tab switching.
		document.querySelectorAll('.mai-views-tabs .nav-tab').forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				document.querySelector('.nav-tab-active').classList.remove('nav-tab-active');
				this.classList.add('nav-tab-active');
				activeTab      = this.dataset.tab;
				currentPage    = 1;
				currentOrderby = 'views';
				currentOrder   = 'desc';
				searchQuery    = '';
				document.getElementById('mai-views-search').value = '';
				updateFilterVisibility();
				loadTable();
			});
		});

		// Filter changes.
		document.getElementById('mai-views-post-type').addEventListener('change', function () {
			currentPage = 1;
			loadTable();
		});

		document.getElementById('mai-views-taxonomy').addEventListener('change', function () {
			currentPage = 1;
			updateTermDropdown();
			loadTable();
		});

		// Per-page selector.
		document.getElementById('mai-views-per-page').addEventListener('change', function () {
			currentPage = 1;
			loadTable();
		});

		// Table search.
		var searchSpinner = document.querySelector('.mai-views-search-spinner');

		document.getElementById('mai-views-search').addEventListener('input', function () {
			clearTimeout(searchTimer);
			var val = this.value.trim();

			if (val.length >= 2) {
				searchSpinner.style.display = '';
			} else {
				searchSpinner.style.display = 'none';
			}

			searchTimer = setTimeout(function () {
				searchQuery = val;
				currentPage = 1;
				loadTable();
			}, 300);
		});

	}

	/**
	 * Initialize Tom Select instances.
	 */
	function initSelects() {
		authorSelect = initTomSelect('mai-views-author', 'author', function () {
			return {};
		});

		termSelect = initTomSelect('mai-views-term', 'term', function () {
			return { taxonomy: document.getElementById('mai-views-taxonomy').value };
		});

		// Hide term select wrapper initially (no taxonomy selected).
		termSelect.wrapper.style.display = 'none';
	}

	/**
	 * Fetch summary data and populate cards.
	 */
	function loadSummary() {
		apiFetch('summary').then(function (data) {
			setCardValue('total_views', formatNumber(data.total_views));
			setCardValue('trending_views', formatNumber(data.trending_views));
			setCardValue('trending_count', formatNumber(data.trending_count));
			setCardValue('last_sync', data.last_sync ? formatDate(data.last_sync) : '—');
		});
	}

	/**
	 * Update the bar chart from the current table data.
	 */
	function updateChart(items) {
		if (typeof Chart === 'undefined') {
			return;
		}

		var canvas = document.getElementById('mai-views-chart');
		var ctx    = canvas.getContext('2d');
		var section = document.querySelector('.mai-views-chart-section');

		if (chartInstance) {
			chartInstance.destroy();
			chartInstance = null;
		}

		// Take top 10 items for the chart.
		var top = items.slice(0, 10);

		if (!top.length) {
			section.style.display = 'none';
			return;
		}

		section.style.display = '';

		var labels = top.map(function (item) {
			var label = decodeHtml(item.title || item.name || '(no title)');
			return label.length > 30 ? label.substring(0, 27) + '...' : label;
		});

		var viewsData    = top.map(function (item) { return item.views || 0; });
		var trendingData = top.map(function (item) { return item.trending || 0; });

		chartInstance = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{
						label:           'Views',
						data:            viewsData,
						backgroundColor: '#2271b1',
					},
					{
						label:           'Trending',
						data:            trendingData,
						backgroundColor: '#d63638',
					},
				],
			},
			options: {
				indexAxis:           'y',
				responsive:          true,
				maintainAspectRatio: false,
				scales: {
					x: {
						beginAtZero: true,
						ticks: { callback: function (v) { return formatNumber(v); } },
					},
				},
				plugins: {
					legend: { position: 'top' },
					tooltip: {
						callbacks: {
							label: function (ctx) { return ctx.dataset.label + ': ' + formatNumber(ctx.raw); },
						},
					},
				},
			},
		});
	}

	/**
	 * Fetch filter options and populate dropdowns.
	 */
	function loadFilters() {
		apiFetch('filters').then(function (data) {
			filtersData = data;

			var ptSelect = document.getElementById('mai-views-post-type');
			data.post_types.forEach(function (pt) {
				ptSelect.add(new Option(pt.label, pt.slug));
			});

			var taxSelect = document.getElementById('mai-views-taxonomy');
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
			order:    currentOrder,
			page:     currentPage,
			per_page: document.getElementById('mai-views-per-page').value,
		});

		if (activeTab === 'posts') {
			var pt     = document.getElementById('mai-views-post-type').value;
			var tax    = document.getElementById('mai-views-taxonomy').value;
			var terms  = termSelect ? termSelect.getValue() : [];
			var authors = authorSelect ? authorSelect.getValue() : [];

			if (pt)              params.set('post_type', pt);
			if (tax)             params.set('taxonomy', tax);
			if (terms.length)    params.set('term_id', terms.join(','));
			if (authors.length)  params.set('author', authors.join(','));
		} else if (activeTab === 'terms') {
			var tax2 = document.getElementById('mai-views-taxonomy').value;
			if (tax2) params.set('taxonomy', tax2);
		}

		if (searchQuery) {
			params.set('search', searchQuery);
		}

		showLoading(true);

		apiFetch(endpoint + '?' + params.toString()).then(function (data) {
			renderTable(data);
			renderPagination(data.total, data.pages);
			updateChart(data.items || []);
			updateActiveFilters();
			showLoading(false);
			document.querySelector('.mai-views-search-spinner').style.display = 'none';
		}).catch(function () {
			showLoading(false);
			document.querySelector('.mai-views-search-spinner').style.display = 'none';
		});
	}

	/**
	 * Render table rows using safe DOM methods.
	 */
	function renderTable(data) {
		var table = document.querySelector('.mai-views-table');
		var thead = table.querySelector('thead tr');
		var tbody = table.querySelector('tbody');
		var empty = document.querySelector('.mai-views-empty');

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

			if (col.key === 'views' || col.key === 'trending') {
				th.classList.add('sortable');

				var isSorted = (currentOrderby === col.key);

				if (isSorted) {
					th.classList.add('sorted');
				}

				// Label + caret.
				var label = document.createElement('span');
				label.textContent = col.label;
				th.appendChild(label);

				var caret       = document.createElement('span');
				caret.className = 'mai-views-caret';

				if (isSorted) {
					caret.textContent = 'asc' === currentOrder ? ' \u25B2' : ' \u25BC';
				} else {
					caret.textContent = ' \u25BC';
				}

				th.appendChild(caret);

				th.addEventListener('click', function () {
					if (currentOrderby === col.key) {
						currentOrder = 'asc' === currentOrder ? 'desc' : 'asc';
					} else {
						currentOrderby = col.key;
						currentOrder   = 'desc';
					}
					currentPage = 1;
					loadTable();
				});
			} else {
				th.textContent = col.label;
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
					var val = decodeHtml(item.title || item.name || '(no title)');
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
		var wrap = document.querySelector('.mai-views-pagination');
		var info = wrap.querySelector('.mai-views-pagination__info');
		var btns = wrap.querySelector('.mai-views-pagination__buttons');

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

		// Tom Select wraps elements in .ts-wrapper — target those for Tom Select elements.
		document.querySelectorAll('.mai-views-filter-posts').forEach(function (el) {
			var target = el.closest('.ts-wrapper') || el;
			target.style.display = showPosts ? '' : 'none';
		});

		document.querySelectorAll('.mai-views-filter-terms').forEach(function (el) {
			var target = el.closest('.ts-wrapper') || el;
			target.style.display = showTerms ? '' : 'none';
		});

		// Hide all filters for authors and archives tabs.
		if (activeTab === 'authors' || activeTab === 'archives') {
			document.querySelectorAll('.mai-views-filters').forEach(function (wrap) {
				Array.prototype.forEach.call(wrap.children, function (el) {
					el.style.display = 'none';
				});
			});
		}
	}

	/**
	 * Update the term dropdown based on selected taxonomy.
	 */
	function updateTermDropdown() {
		var taxonomy = document.getElementById('mai-views-taxonomy').value;

		if (!termSelect) {
			return;
		}

		var wrapper = termSelect.wrapper;

		if (!taxonomy) {
			wrapper.style.display = 'none';
			termSelect.clear(true);
			termSelect.clearOptions();
			return;
		}

		wrapper.style.display = '';
		termSelect.clear(true);
		termSelect.clearOptions();
		termSelect.load('');
	}

	/**
	 * Build and display active filter tags.
	 */
	function updateActiveFilters() {
		var wrap = document.querySelector('.mai-views-active-filters');

		while (wrap.firstChild) wrap.removeChild(wrap.firstChild);

		var filters = [];

		// Post type.
		var ptSelect = document.getElementById('mai-views-post-type');
		if (ptSelect.value && (activeTab === 'posts')) {
			filters.push(ptSelect.options[ptSelect.selectedIndex].text);
		}

		// Taxonomy.
		var taxSelect = document.getElementById('mai-views-taxonomy');
		if (taxSelect.value && (activeTab === 'posts' || activeTab === 'terms')) {
			filters.push(taxSelect.options[taxSelect.selectedIndex].text);
		}

		// Terms (Tom Select multi).
		if (termSelect && termSelect.getValue().length && (activeTab === 'posts')) {
			termSelect.getValue().forEach(function (val) {
				var item = termSelect.getItem(val);
				if (item) filters.push(item.textContent);
			});
		}

		// Authors (Tom Select multi).
		if (authorSelect && authorSelect.getValue().length && (activeTab === 'posts')) {
			authorSelect.getValue().forEach(function (val) {
				var item = authorSelect.getItem(val);
				if (item) filters.push(item.textContent);
			});
		}

		// Search query.
		if (searchQuery) {
			filters.push('Search: "' + searchQuery + '"');
		}

		if (!filters.length) {
			wrap.style.display = 'none';
			return;
		}

		wrap.style.display = '';

		var label       = document.createElement('span');
		label.className = 'mai-views-active-filters__label';
		label.textContent = 'Filtered by: ';
		wrap.appendChild(label);

		filters.forEach(function (text) {
			var tag       = document.createElement('span');
			tag.className = 'mai-views-active-filters__tag';
			tag.textContent = text;
			wrap.appendChild(tag);
		});
	}

	/**
	 * Initialize a Tom Select instance with AJAX search.
	 */
	function initTomSelect(elementId, searchType, getExtraParams) {
		var el = document.getElementById(elementId);

		return new TomSelect(el, {
			valueField:       'id',
			labelField:       'name',
			searchField:      'name',
			maxItems:         null,
			placeholder:      el.getAttribute('placeholder') || 'Search...',
			openOnFocus:      true,
			preload:          'focus',
			loadThrottle:     300,
			shouldLoad:       function () { return true; },
			plugins:          ['remove_button', 'clear_button'],
			load: function (query, callback) {
				var params = new URLSearchParams({ type: searchType });
				var extra  = getExtraParams();

				if (query) {
					params.set('search', query);
				}

				Object.keys(extra).forEach(function (k) {
					if (extra[k]) params.set(k, extra[k]);
				});

				apiFetch('search?' + params.toString())
					.then(function (items) { callback(items); })
					.catch(function ()     { callback();      });
			},
			onChange: function () {
				currentPage = 1;
				loadTable();
			},
			render: {
				option: function (data, escape) {
					return '<div>' + escape(data.name) + '</div>';
				},
				item: function (data, escape) {
					return '<div>' + escape(data.name) + '</div>';
				},
			},
		});
	}

	/**
	 * Show or hide loading state.
	 */
	function showLoading(show) {
		document.querySelector('.mai-views-loading').style.display = show ? '' : 'none';
		document.querySelector('.mai-views-table').style.display   = show ? 'none' : '';

		// Only hide pagination when loading starts. renderPagination() controls whether it shows.
		if (show) {
			document.querySelector('.mai-views-pagination').style.display = 'none';
		}
	}

	/**
	 * Set a summary card value.
	 */
	function setCardValue(key, value) {
		var card = document.querySelector('[data-card="' + key + '"] .mai-views-card__value');
		if (!card) return;

		// Last sync may contain HTML for the time span.
		if (key === 'last_sync' && typeof value === 'string' && value.indexOf('<') !== -1) {
			card.innerHTML = value; // Safe — generated by our formatDate(), not user input.
		} else {
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

	/**
	 * Decode HTML entities in a string.
	 * Uses a textarea element which safely decodes entities without executing scripts.
	 */
	function decodeHtml(str) {
		var el = document.createElement('textarea');
		el.textContent = str;
		// Textarea's value decodes entities set via textContent in reverse —
		// use the DOM parser approach instead.
		var doc = new DOMParser().parseFromString(str, 'text/html');
		return doc.body.textContent || str;
	}

	/**
	 * Format a date string for display.
	 */
	function formatDate(str) {
		var d = new Date(str.replace(' ', 'T'));

		if (isNaN(d.getTime())) {
			return str;
		}

		var time = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
			.replace(/AM|PM/, function (m) { return m.toLowerCase(); });

		var now     = new Date();
		var isToday = d.getFullYear() === now.getFullYear()
			&& d.getMonth() === now.getMonth()
			&& d.getDate() === now.getDate();

		if (isToday) {
			return time;
		}

		var date = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

		return date + '<span class="mai-views-card__time">' + time + '</span>';
	}
})();
