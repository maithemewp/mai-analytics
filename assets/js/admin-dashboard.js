(function () {
	'use strict';

	var API     = maiAnalytics.restBase;
	var headers = { 'X-WP-Nonce': maiAnalytics.nonce };

	// State. Initial tab comes from server-rendered active class so ?subtab=
	// deep-links land on the right table without a second render pass.
	var initialTab     = document.querySelector('.mai-analytics-tabs .nav-tab-active');
	var activeTab      = (initialTab && initialTab.dataset.tab) || 'posts';
	var currentPage   = 1;
	var currentOrderby = 'views';
	var currentOrder   = 'desc';
	var searchQuery   = '';
	var searchTimer   = null;
	var filtersData   = null;

	// Tom Select instances. Every filter dropdown is a Tom Select for visual
	// uniformity; ajaxFilters use remote search, staticFilters use a fixed list.
	var ptSelect            = null;
	var taxSelect           = null;
	var termSelect          = null;
	var authorSelect        = null;
	var publishedDaysFilter = null;

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
		document.querySelectorAll('.mai-analytics-tabs .nav-tab').forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				document.querySelector('.mai-analytics-tabs .nav-tab-active').classList.remove('nav-tab-active');
				this.classList.add('nav-tab-active');
				activeTab      = this.dataset.tab;
				currentPage    = 1;
				currentOrderby = 'views';
				currentOrder   = 'desc';
				searchQuery    = '';
				document.getElementById('mai-analytics-search').value = '';
				if (publishedDaysFilter) publishedDaysFilter.clear();
				updateFilterVisibility();
				loadTable();

				// Reflect the active tab in the URL so it's bookmarkable / shareable.
				if (this.href) {
					window.history.replaceState({}, '', this.href);
				}
			});
		});

		// Filter changes are wired via Tom Select onChange in initSelects() —
		// post-type, taxonomy, author, and published-days all dispatch through
		// their TomSelect instance, so no native `change` listeners are needed
		// for those filters here.

		// Per-page selector.
		document.getElementById('mai-analytics-per-page').addEventListener('change', function () {
			currentPage = 1;
			loadTable();
		});

		// Table search.
		var searchSpinner = document.querySelector('.mai-analytics-search-spinner');

		document.getElementById('mai-analytics-search').addEventListener('input', function () {
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
	 * Initialize Tom Select instances on every filter dropdown so the row of
	 * controls is visually uniform (same height, border, chevron). Static
	 * single-selects (post type, taxonomy, publish dates) get options added by
	 * loadFilters(); ajax multi-selects (term, author) load on focus/search.
	 */
	function initSelects() {
		ptSelect = initTomSelectStatic('mai-analytics-post-type', function () {
			currentPage = 1;
			loadTable();
		});

		taxSelect = initTomSelectStatic('mai-analytics-taxonomy', function () {
			currentPage = 1;
			updateTermDropdown();
			updateFilterVisibility();
			loadTable();
		});

		publishedDaysFilter = new PublishedDaysFilter(
			document.querySelector('.mai-analytics-filters__published'),
			function () { currentPage = 1; loadTable(); }
		);

		termSelect = initTomSelect('mai-analytics-term', 'term', function () {
			return { taxonomy: taxSelect ? taxSelect.getValue() : '' };
		});

		authorSelect = initTomSelect('mai-analytics-author', 'author', function () {
			return {};
		});

		// Term wrapper is hidden until a taxonomy is selected.
		termSelect.wrapper.style.display = 'none';
	}

	/**
	 * Encapsulates the "Publish Dates" filter — the only filter with real
	 * internal state (preset → "Custom Days" → debounced number input). Owns
	 * its Tom Select, its sub-input, the `is-custom` class toggle, and the
	 * resolved day count. Consumers read via getValue() / clear().
	 *
	 * @param {HTMLElement} rootEl   The .mai-analytics-filters__published cell.
	 * @param {Function}    onChange Fires after value is committed.
	 */
	function PublishedDaysFilter(rootEl, onChange) {
		var self = this;

		this.root         = rootEl;
		this.selectEl     = rootEl.querySelector('select');
		this.customInput  = rootEl.querySelector('.mai-analytics-filters__custom-days');
		this.value        = 0;
		this.onChange     = onChange || function () {};
		this._customTimer = null;

		var placeholder = this.selectEl.getAttribute('placeholder') || '';

		this.tomSelect = new TomSelect(this.selectEl, {
			placeholder:  placeholder,
			plugins:      ['clear_button'],
			// Eight presets — search is noise. Click/arrow-keys still work.
			controlInput: null,
			onChange:     function () { self._handleSelectChange(); },
		});

		if (placeholder) {
			this.tomSelect.control.setAttribute('data-placeholder', placeholder);
		}

		this.customInput.addEventListener('input', function () {
			clearTimeout(self._customTimer);
			var input = this;

			self._customTimer = setTimeout(function () {
				var val = parseInt(input.value, 10);
				if (val > 365) { val = 365; input.value = 365; }
				self.value = val > 0 ? val : 0;
				self.onChange();
			}, 400);
		});
	}

	PublishedDaysFilter.prototype._handleSelectChange = function () {
		var val = this.tomSelect.getValue();

		if ('custom' === val) {
			this.root.classList.add('is-custom');
			this.customInput.focus();
			return;
		}

		this.root.classList.remove('is-custom');
		this.customInput.value = '';
		this.value             = val ? parseInt(val, 10) : 0;
		this.onChange();
	};

	PublishedDaysFilter.prototype.getValue = function () {
		return this.value;
	};

	PublishedDaysFilter.prototype.clear = function () {
		this.tomSelect.setValue('', true);
		this.customInput.value = '';
		this.root.classList.remove('is-custom');
		this.value = 0;
	};

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
	 * Fetch filter options and populate dropdowns.
	 */
	function loadFilters() {
		apiFetch('filters').then(function (data) {
			filtersData = data;

			data.post_types.forEach(function (pt) {
				ptSelect.addOption({ value: pt.slug, text: pt.label });
			});
			ptSelect.refreshOptions(false);

			data.taxonomies.forEach(function (tax) {
				taxSelect.addOption({ value: tax.slug, text: tax.label });
			});
			taxSelect.refreshOptions(false);

			updateFilterVisibility();
			updateTermDropdown();
		});
	}

	/**
	 * Reset the published-within filter UI without changing state.
	 */

	/**
	 * Fetch table data based on active tab and filters.
	 */
	function loadTable() {
		var endpoint = 'top/' + activeTab;
		var params   = new URLSearchParams({
			orderby:  currentOrderby,
			order:    currentOrder,
			page:     currentPage,
			per_page: document.getElementById('mai-analytics-per-page').value,
		});

		if (activeTab === 'posts') {
			var pt      = ptSelect      ? ptSelect.getValue()      : '';
			var tax     = taxSelect     ? taxSelect.getValue()     : '';
			var terms   = termSelect    ? termSelect.getValue()    : [];
			var authors = authorSelect  ? authorSelect.getValue()  : [];

			if (pt)                params.set('post_type', pt);
			if (tax)               params.set('taxonomy', tax);
			if (terms.length)      params.set('term_id', terms.join(','));
			if (authors.length)    params.set('author', authors.join(','));
			var pubDays = publishedDaysFilter ? publishedDaysFilter.getValue() : 0;
			if (pubDays > 0) params.set('published_days', pubDays);
		} else if (activeTab === 'terms') {
			var tax2 = taxSelect ? taxSelect.getValue() : '';
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
			document.querySelector('.mai-analytics-search-spinner').style.display = 'none';
		}).catch(function () {
			showLoading(false);
			document.querySelector('.mai-analytics-search-spinner').style.display = 'none';
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
				caret.className = 'mai-analytics-caret';

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
	 * Show/hide each filter cell based on whether it carries the
	 * `--<activeTab>` BEM modifier. Conditional sub-rules (term needs a
	 * taxonomy, custom-days needs the "Custom Days" option) layer on top.
	 */
	function updateFilterVisibility() {
		var modifier = 'mai-analytics-filters__field--' + activeTab;
		var fields   = document.querySelectorAll('.mai-analytics-filters__field');

		fields.forEach(function (field) {
			var target  = field.closest('.ts-wrapper') || field;
			var inTab   = field.classList.contains(modifier);

			if (!inTab) {
				target.style.display = 'none';
				return;
			}

			// Term: only when a taxonomy is selected.
			if (field.id === 'mai-analytics-term') {
				var taxonomy = taxSelect ? taxSelect.getValue() : '';
				target.style.display = taxonomy ? '' : 'none';
				return;
			}

			target.style.display = '';
		});
	}

	/**
	 * Update the term dropdown based on selected taxonomy.
	 */
	function updateTermDropdown() {
		if (!termSelect) {
			return;
		}

		var taxonomy = taxSelect ? taxSelect.getValue() : '';
		var wrapper  = termSelect.wrapper;

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
	 * Initialize a Tom Select on a static single-select with a fixed list of
	 * options. Keeps "All X" as the empty-value option (allowEmptyOption).
	 * The caller supplies the change handler so each filter can layer in its
	 * own side effects (e.g. the taxonomy filter resetting the term list).
	 */
	function initTomSelectStatic(elementId, onChange) {
		var el          = document.getElementById(elementId);
		var placeholder = el.getAttribute('placeholder') || '';

		var ts = new TomSelect(el, {
			placeholder:  placeholder,
			plugins:      ['clear_button'],
			// Static lists are short (typically < 10) — search is more noise
			// than help. Click + arrow-key navigation still work.
			controlInput: null,
			onChange:     onChange,
		});

		// Tom Select normally renders the placeholder on its control input;
		// with controlInput disabled there's nothing to render it. Stash the
		// text on the control so our CSS can surface it via ::before.
		if (placeholder) {
			ts.control.setAttribute('data-placeholder', placeholder);
		}

		return ts;
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

		return date + '<span class="mai-analytics-card__time">' + time + '</span>';
	}
})();
