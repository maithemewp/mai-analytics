(function () {
	'use strict';

	var API     = maiAnalytics.restBase;
	var headers = { 'X-WP-Nonce': maiAnalytics.nonce };

	// State. Initial tab comes from server-rendered active class so ?subtab=
	// deep-links land on the right table without a second render pass. Sort
	// and page come from the URL. Filters, search and per-page come from the
	// server-rendered controls, which already reflect the URL.
	var urlParams      = new URLSearchParams(window.location.search);
	var initialTab     = document.querySelector('.mai-analytics-tabs .nav-tab-active');
	var activeTab      = (initialTab && initialTab.dataset.tab) || 'posts';
	var currentPage    = Math.max(1, parseInt(urlParams.get('paged'), 10) || 1);
	var currentOrderby = 'trending' === urlParams.get('orderby') ? 'trending' : 'views';
	var currentOrder   = 'asc' === urlParams.get('order') ? 'asc' : 'desc';
	var searchQuery    = '';
	var searchTimer    = null;
	// Bumped on every table request so a slow response for an old filter
	// can't overwrite the table and cards after a newer one has landed.
	var requestId      = 0;
	// Whether the site has any app traffic. When false, the Web and App
	// columns are hidden from every tab. For the app-less common case,
	// Views == Web and App is 0, so showing them is just repetition.
	var hasApp         = !! maiAnalytics.hasApp;

	// Tom Select instances. Every filter dropdown is a Tom Select for visual
	// uniformity; ajaxFilters use remote search, staticFilters use a fixed list.
	var ptSelect            = null;
	var taxSelect           = null;
	var termSelect          = null;
	var authorSelect        = null;
	var publishedDaysFilter = null;

	/**
	 * Initialize on DOM ready. Bail on the settings tab — admin-dashboard.js
	 * is enqueued on every Mai Analytics page (Admin::enqueue_assets gates by
	 * page hook, not tab) but the dashboard's required DOM only exists when
	 * the Dashboard tab is rendered. Without this guard, initSelects() throws
	 * on `null.getAttribute(...)` when its target select is absent.
	 */
	document.addEventListener('DOMContentLoaded', function () {
		if (!document.getElementById('mai-analytics-post-type')) {
			return;
		}

		searchQuery = document.getElementById('mai-analytics-search').value.trim();

		initSelects();
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
				if (publishedDaysFilter) publishedDaysFilter.reset();
				document.querySelector('.mai-analytics-filters').dataset.tab = activeTab;
				updateTrendingLabel();
				loadTable();
			});
		});

		// Filter changes are wired via Tom Select onChange in initSelects() —
		// post-type, taxonomy, author, and published-days all dispatch through
		// their TomSelect instance, so no native `change` listeners are needed
		// for those filters here.

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
		ptSelect = initTomSelectStatic('mai-analytics-post-type', true, function () {
			currentPage = 1;
			loadTable();
		});

		taxSelect = initTomSelectStatic('mai-analytics-taxonomy', true, function () {
			currentPage = 1;
			updateTermDropdown();
			loadTable();
		});

		// Always has a value, so no clear button. Tom Select keeps the
		// original select's value in sync, which loadTable() reads.
		initTomSelectStatic('mai-analytics-per-page', false, function () {
			currentPage = 1;
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
	}

	/**
	 * Encapsulates the "Published" filter. It's the only filter with real internal
	 * state (preset → "Custom" → debounced number input). Owns
	 * its Tom Select, its sub-input, the `is-custom` class toggle, and the
	 * resolved day count. It always has a value: 0 means any time, and the
	 * select's `data-default` is what a tab switch goes back to.
	 *
	 * @param {HTMLElement} rootEl   The .mai-analytics-filters__published cell.
	 * @param {Function}    onChange Fires after value is committed.
	 */
	function PublishedDaysFilter(rootEl, onChange) {
		var self = this;

		this.root         = rootEl;
		this.selectEl     = rootEl.querySelector('select');
		this.customInput  = rootEl.querySelector('.mai-analytics-filters__custom-days');
		this.defaultDays  = parseInt(this.selectEl.dataset.default, 10) || 0;
		// The server renders the URL's choice, including a custom day count.
		this.value        = Math.min(365, parseInt('custom' === this.selectEl.value ? this.customInput.value : this.selectEl.value, 10) || 0);
		this.onChange     = onChange || function () {};
		this._customTimer = null;

		var prefix = this.selectEl.dataset.prefix || '';

		this.tomSelect = new TomSelect(this.selectEl, {
			// Eight presets — search is noise. Click/arrow-keys still work.
			controlInput: null,
			onChange:     function () { self._handleSelectChange(); },
			// Options stay short ("30 days"). The closed control adds the
			// prefix ("Published: 30 days") so it can't be read as a views window.
			render: {
				item: function (data, escape) {
					return '<div>' + escape(prefix ? prefix + ' ' + data.text : data.text) + '</div>';
				},
			},
		});

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
		this.value             = parseInt(val, 10) || 0;
		this.onChange();
	};

	PublishedDaysFilter.prototype.getValue = function () {
		return this.value;
	};

	PublishedDaysFilter.prototype.reset = function () {
		this.tomSelect.setValue(String(this.defaultDays), true);
		this.customInput.value = '';
		this.root.classList.remove('is-custom');
		this.value = this.defaultDays;
	};

	/**
	 * Fetch filter options and populate dropdowns.
	 */
	function loadFilters() {
		apiFetch('filters').then(function (data) {
			data.post_types.forEach(function (pt) {
				ptSelect.addOption({ value: pt.slug, text: pt.label });
			});
			ptSelect.refreshOptions(false);

			data.taxonomies.forEach(function (tax) {
				taxSelect.addOption({ value: tax.slug, text: tax.label });
			});
			taxSelect.refreshOptions(false);
		});
	}

	/**
	 * Fetch table data and card totals based on active tab and filters.
	 */
	function loadTable() {
		var endpoint = 'top/' + activeTab;
		var filters  = getFilters();
		var perPage  = document.getElementById('mai-analytics-per-page').value;
		var params   = new URLSearchParams({
			orderby:  currentOrderby,
			order:    currentOrder,
			page:     currentPage,
			per_page: perPage,
		});

		if (filters.postType)          params.set('post_type', filters.postType);
		if (filters.taxonomy)          params.set('taxonomy', filters.taxonomy);
		if (filters.terms.length)      params.set('term_id', filters.terms.join(','));
		if (filters.authors.length)    params.set('author', filters.authors.join(','));
		if (filters.publishedDays > 0) params.set('published_days', filters.publishedDays);
		if (searchQuery)               params.set('search', searchQuery);

		syncUrl(filters, perPage);

		var thisRequest = ++requestId;
		var isFiltered  = params.has('post_type') || params.has('taxonomy') || params.has('term_id')
			|| params.has('author') || params.has('published_days') || params.has('search');

		showLoading(true);

		apiFetch(endpoint + '?' + params.toString()).then(function (data) {
			if (thisRequest !== requestId) {
				return;
			}

			// A shared link can point past the last page once the data moves on.
			if (currentPage > data.pages && data.pages > 0) {
				currentPage = data.pages;
				loadTable();
				return;
			}

			showLoading(false);
			renderCards(data.totals);
			renderTable(data, isFiltered
				? 'Nothing matches these filters.'
				: 'No data yet. Views will appear here once visitors start browsing your site.');
			renderPagination(data.total, data.pages);
		}).catch(function () {
			if (thisRequest !== requestId) {
				return;
			}

			showLoading(false);
			renderCards(null);
			renderTable({ items: [] }, 'Could not load the data. Reload the page to try again.');
		});
	}

	/**
	 * Read the filters that apply to the active tab. Posts-only filters keep
	 * their values while another tab is open, but aren't sent or linked.
	 */
	function getFilters() {
		var filters = { postType: '', taxonomy: '', terms: [], authors: [], publishedDays: null };

		if ('posts' === activeTab) {
			filters.postType      = ptSelect ? ptSelect.getValue() : '';
			filters.terms         = termSelect ? termSelect.getValue() : [];
			filters.authors       = authorSelect ? authorSelect.getValue() : [];
			filters.publishedDays = publishedDaysFilter ? publishedDaysFilter.getValue() : 0;
		}

		if ('posts' === activeTab || 'terms' === activeTab) {
			filters.taxonomy = taxSelect ? taxSelect.getValue() : '';
		}

		return filters;
	}

	/**
	 * Mirror the table's state into the address bar so a view can be
	 * bookmarked or shared. replaceState, so filter changes don't fill Back
	 * history, and defaults are left out to keep links short.
	 *
	 * Only the keys listed here are touched. `page`, and `post_type` when the
	 * menu sits under Mai Ads, stay as WordPress set them. The filters use
	 * `type` and `tax` because wp-admin reads `post_type` and `taxonomy` on
	 * every admin page to pick the menu parent.
	 */
	function syncUrl(filters, perPage) {
		var url   = new URL(window.location.href);
		var query = url.searchParams;

		['subtab', 'orderby', 'order', 'paged', 'per_page', 'search', 'type', 'tax', 'terms', 'authors', 'published'].forEach(function (key) {
			query.delete(key);
		});

		query.set('subtab', activeTab);

		if ('views' !== currentOrderby)  query.set('orderby', currentOrderby);
		if ('desc' !== currentOrder)     query.set('order', currentOrder);
		if (currentPage > 1)             query.set('paged', currentPage);
		if ('25' !== perPage)            query.set('per_page', perPage);
		if (searchQuery)                 query.set('search', searchQuery);
		if (filters.postType)            query.set('type', filters.postType);
		if (filters.taxonomy)            query.set('tax', filters.taxonomy);
		if (filters.terms.length)        query.set('terms', filters.terms.join(','));
		if (filters.authors.length)      query.set('authors', filters.authors.join(','));

		if (null !== filters.publishedDays && filters.publishedDays !== publishedDaysFilter.defaultDays) {
			query.set('published', filters.publishedDays);
		}

		// Commas are fine in a query string and keep ID lists readable.
		url.search = query.toString().replace(/%2C/gi, ',');

		window.history.replaceState(null, '', url);
	}

	/**
	 * Fill the cards from a response's totals. Null shows an ellipsis.
	 */
	function renderCards(totals) {
		['views', 'trending_views', 'trending_count'].forEach(function (key) {
			var value = document.querySelector('[data-card="' + key + '"] .mai-analytics-card__value');

			if (value) {
				value.textContent = totals ? formatNumber(totals[key] || 0) : '…';
			}
		});
	}

	/**
	 * Name the trending count card for the active tab, e.g. "Trending Terms".
	 */
	function updateTrendingLabel() {
		var card   = document.querySelector('[data-card="trending_count"]');
		var labels = card ? JSON.parse(card.dataset.labels || '{}') : {};

		if (card && labels[activeTab]) {
			card.querySelector('.mai-analytics-card__label').textContent = labels[activeTab];
		}
	}

	/**
	 * Render table rows using safe DOM methods.
	 *
	 * @param {Object} data         The response, with an items array.
	 * @param {string} emptyMessage Shown when there are no items.
	 */
	function renderTable(data, emptyMessage) {
		var table = document.querySelector('.mai-analytics-table');
		var thead = table.querySelector('thead tr');
		var tbody = table.querySelector('tbody');
		var empty = document.querySelector('.mai-analytics-empty');

		// Clear existing content safely.
		while (thead.firstChild) thead.removeChild(thead.firstChild);
		while (tbody.firstChild) tbody.removeChild(tbody.firstChild);

		if (!data.items || data.items.length === 0) {
			table.style.display = 'none';
			empty.querySelector('p').textContent = emptyMessage;
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
					caret.textContent = 'asc' === currentOrder ? ' ▲' : ' ▼';
				} else {
					caret.textContent = ' ▼';
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

		var list = cols[activeTab] || [];

		// On app-less sites, drop the Web/App columns since Views == Web
		// and App is always 0 — they're pure repetition. Sites with any
		// app traffic see the full breakdown.
		if ( ! hasApp ) {
			list = list.filter( function ( col ) {
				return col.key !== 'web' && col.key !== 'app';
			} );
		}

		return list;
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
			btns.appendChild(createPageButton('‹ Prev', currentPage - 1));
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
			btns.appendChild(createPageButton('Next ›', currentPage + 1));
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
	 * Reset the term dropdown after the taxonomy changes. Whether it shows is
	 * CSS, keyed on the filters row's has-taxonomy class.
	 */
	function updateTermDropdown() {
		if (!termSelect) {
			return;
		}

		var taxonomy = taxSelect ? taxSelect.getValue() : '';

		document.querySelector('.mai-analytics-filters').classList.toggle('has-taxonomy', !! taxonomy);
		termSelect.clear(true);
		termSelect.clearOptions();

		if (taxonomy) {
			termSelect.load('');
		}
	}

	/**
	 * Initialize a Tom Select on a static single-select with a fixed list of
	 * options. Empty means "All X", shown through the placeholder. The caller
	 * supplies the change handler so each filter can layer in its own side
	 * effects (e.g. the taxonomy filter resetting the term list).
	 *
	 * @param {string}   elementId The select's id.
	 * @param {boolean}  clearable Whether it can be emptied, which adds the × button.
	 * @param {Function} onChange  Fires when the value changes.
	 */
	function initTomSelectStatic(elementId, clearable, onChange) {
		var el          = document.getElementById(elementId);
		var placeholder = el.getAttribute('placeholder') || '';

		var ts = new TomSelect(el, {
			placeholder:  placeholder,
			plugins:      clearable ? ['clear_button'] : [],
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

		var ts = new TomSelect(el, {
			valueField:       'id',
			labelField:       'name',
			searchField:      'name',
			maxItems:         null,
			placeholder:      el.getAttribute('placeholder') || 'Search...',
			openOnFocus:      true,
			preload:          'focus',
			loadThrottle:     300,
			shouldLoad:       function () { return true; },
			hidePlaceholder:  true,
			// Each tag has its own ×, so no clear-all button. checkbox_options
			// lists every pick in the open dropdown, including any behind "+N".
			// input_autogrow sizes the search box to what's typed, so it only
			// takes room from the tags while you're typing.
			plugins:          ['remove_button', 'checkbox_options', 'input_autogrow'],
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
					// The inner span lets a long name end in an ellipsis.
					return '<div><span class="mai-analytics-tag">' + escape(data.name) + '</span></div>';
				},
				// Tom Select debounces the fetch by loadThrottle, then waits on
				// the network. Its default spinner is a faint grey ring that
				// reads as an empty list, so say what's happening in words.
				loading: function () {
					return '<div class="no-results">Loading…</div>';
				},
				no_results: function () {
					return '<div class="no-results">No matches</div>';
				},
			},
		});

		keepTagsOnOneLine(ts);

		return ts;
	}

	/**
	 * Keep a multi-select one line tall. Tags that don't fit are hidden and
	 * counted in a "+N" badge. The checkbox_options plugin lists every pick in
	 * the open dropdown, so hidden tags can still be seen and unticked.
	 *
	 * @param {TomSelect} ts The multi-select.
	 */
	function keepTagsOnOneLine(ts) {
		var control = ts.control;
		var more    = document.createElement('span');

		more.className = 'mai-analytics-more';
		more.hidden    = true;

		var isTag = function (node) {
			return node.classList && node.classList.contains('item');
		};

		var fit = function () {
			var items = Array.prototype.slice.call(control.querySelectorAll('.item'));

			// Measure every tag at its natural width. Only the first tag may
			// shrink, and only once the fit below has been decided.
			items.forEach(function (item) {
				item.hidden = false;
				item.classList.remove('mai-analytics-tag-shrink');
			});
			more.hidden = true;
			control.insertBefore(more, ts.control_input);

			// Nothing to fit, or hidden (another tab, or no taxonomy picked yet).
			// The resize observer runs this again once it shows.
			if (!items.length || !control.clientWidth) {
				return;
			}

			var style  = getComputedStyle(control);
			var room   = control.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) - searchRoom(ts.control_input);
			var widths = items.map(outerWidth);
			var total  = widths.reduce(function (sum, width) { return sum + width; }, 0);

			if (total <= room) {
				return;
			}

			// Measure the badge at its widest count, then fit tags beside it.
			more.hidden      = false;
			more.textContent = '+' + items.length;

			var shown = 0;
			var used  = outerWidth(more);

			while (shown < items.length && used + widths[shown] <= room) {
				used += widths[shown];
				shown++;
			}

			// Always show one tag. CSS ends a long name with an ellipsis, and
			// when that one tag is the only pick there's nothing to count.
			shown = Math.max(1, shown);

			more.hidden      = shown >= items.length;
			more.textContent = '+' + (items.length - shown);
			items.forEach(function (item, index) { item.hidden = index >= shown; });
			items[0].classList.add('mai-analytics-tag-shrink');
		};

		// Watch the tags themselves, not Tom Select events, so a silent
		// clear(true) (as when the taxonomy changes) still updates the badge.
		new MutationObserver(function (mutations) {
			var tagsChanged = mutations.some(function (mutation) {
				return Array.prototype.some.call(mutation.addedNodes, isTag)
					|| Array.prototype.some.call(mutation.removedNodes, isTag);
			});

			if (tagsChanged) {
				fit();
			}
		}).observe(control, { childList: true });

		// Typing widens the search box, and Tom Select clearing it (after a pick,
		// or on blur) shrinks it back. input_autogrow listens to the same events
		// and was registered first, so the width is already updated here.
		['input', 'update', 'blur'].forEach(function (type) {
			ts.control_input.addEventListener(type, fit);
		});

		new ResizeObserver(fit).observe(control);
		fit();
	}

	/**
	 * An element's width plus its left and right margins.
	 */
	function outerWidth(el) {
		var style = getComputedStyle(el);

		return el.getBoundingClientRect().width + parseFloat(style.marginLeft) + parseFloat(style.marginRight);
	}

	/**
	 * Room to keep free for the search box inside a multi-select, plus its
	 * margins. input_autogrow writes the typed text's width to the input's
	 * inline style. That's read instead of the rendered width, because the
	 * rendered box can be squeezed while every tag is briefly shown for measuring.
	 */
	function searchRoom(input) {
		var style = getComputedStyle(input);
		var width = Math.max(parseFloat(style.minWidth) || 0, parseFloat(input.style.width) || 0);

		return width + parseFloat(style.marginLeft) + parseFloat(style.marginRight);
	}

	/**
	 * Show or hide the loading state. While loading, the table, empty state
	 * and pagination are hidden and the cards go back to "…", so old totals
	 * never sit next to a new filter. When loading ends, renderCards(),
	 * renderTable() and renderPagination() decide what shows.
	 */
	function showLoading(show) {
		document.querySelector('.mai-analytics-loading').style.display = show ? '' : 'none';

		if (show) {
			renderCards(null);
			document.querySelector('.mai-analytics-table').style.display      = 'none';
			document.querySelector('.mai-analytics-empty').style.display      = 'none';
			document.querySelector('.mai-analytics-pagination').style.display = 'none';
		} else {
			document.querySelector('.mai-analytics-search-spinner').style.display = 'none';
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
	 * Decode HTML entities in a string. DOMParser builds an inert document,
	 * so no scripts run while decoding.
	 */
	function decodeHtml(str) {
		var doc = new DOMParser().parseFromString(str, 'text/html');
		return doc.body.textContent || str;
	}
})();
