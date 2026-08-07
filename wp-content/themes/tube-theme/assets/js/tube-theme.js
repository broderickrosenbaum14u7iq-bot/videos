/**
 * Tube Theme's client-side glue (Phase 13). No framework, no build step,
 * no dependency beyond browser-native APIs — same constraint this file
 * has followed since Phase 8.
 *
 * Sections:
 *  1. Search form redirect (Phase 8, unchanged).
 *  2. Mobile off-canvas nav toggle.
 *  3. Mega-menu touch-tap toggle (desktop pointer/keyboard is handled
 *     entirely by CSS :hover/:focus-within — this is only needed for
 *     touch devices, where :hover doesn't apply).
 *  4. Infinite scroll — progressive enhancement over real, already-
 *     SEO-correct paginated URLs (ARCHITECTURE.md §15.2): fetches the
 *     next page's real HTML, extracts its video-grid markup, and
 *     history.pushState()s the browser to that real URL. Never a
 *     distinct "AJAX-only" page state. Runs only against
 *     `[data-tube-infinite-scroll]` listings (archive/search pages,
 *     the only pages with real offset-based pagination) — see
 *     template-parts/video-grid.php's docblock.
 */
(function () {
	'use strict';

	/* 1. Search form redirect --------------------------------------- */

	document.addEventListener('submit', function (event) {
		var form = event.target.closest('[data-tube-search-base]');

		if (!form) {
			return;
		}

		event.preventDefault();

		var input = form.querySelector('input[name="q"]');
		var query = input ? input.value.trim() : '';

		if ('' === query) {
			return;
		}

		window.location.href = form.getAttribute('data-tube-search-base') + encodeURIComponent(query) + '/';
	});

	/* 2. Mobile off-canvas nav ---------------------------------------- */

	var mobileNav = document.querySelector('[data-tube-mobile-nav]');
	var mobileNavOpenBtn = document.querySelector('[data-tube-mobile-nav-open]');
	var mobileNavCloseBtn = document.querySelector('[data-tube-mobile-nav-close]');

	function openMobileNav() {
		if (!mobileNav) {
			return;
		}

		mobileNav.classList.add('is-open');

		if (mobileNavOpenBtn) {
			mobileNavOpenBtn.setAttribute('aria-expanded', 'true');
		}

		document.body.style.overflow = 'hidden';
	}

	function closeMobileNav() {
		if (!mobileNav) {
			return;
		}

		mobileNav.classList.remove('is-open');

		if (mobileNavOpenBtn) {
			mobileNavOpenBtn.setAttribute('aria-expanded', 'false');
		}

		document.body.style.overflow = '';
	}

	if (mobileNavOpenBtn) {
		mobileNavOpenBtn.addEventListener('click', openMobileNav);
	}

	if (mobileNavCloseBtn) {
		mobileNavCloseBtn.addEventListener('click', closeMobileNav);
	}

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key) {
			closeMobileNav();
		}
	});

	/* 3. Mega-menu touch-tap toggle ------------------------------------ */

	var navItems = document.querySelectorAll('.site-nav__item');

	navItems.forEach(function (item) {
		var trigger = item.querySelector('button.site-nav__link');

		if (!trigger) {
			return;
		}

		trigger.addEventListener('click', function () {
			var wasOpen = item.classList.contains('is-open');

			navItems.forEach(function (otherItem) {
				otherItem.classList.remove('is-open');

				var otherTrigger = otherItem.querySelector('button.site-nav__link');

				if (otherTrigger) {
					otherTrigger.setAttribute('aria-expanded', 'false');
				}
			});

			if (!wasOpen) {
				item.classList.add('is-open');
				trigger.setAttribute('aria-expanded', 'true');
			}
		});
	});

	document.addEventListener('click', function (event) {
		navItems.forEach(function (item) {
			if (!item.contains(event.target)) {
				item.classList.remove('is-open');

				var trigger = item.querySelector('button.site-nav__link');

				if (trigger) {
					trigger.setAttribute('aria-expanded', 'false');
				}
			}
		});
	});

	/* 4. Infinite scroll ------------------------------------------------ */

	if ('IntersectionObserver' in window) {
		document.querySelectorAll('[data-tube-infinite-scroll]').forEach(initInfiniteScroll);
	}

	/**
	 * @param {Element} listing A `[data-tube-infinite-scroll]` wrapper (template-parts/video-grid.php's output).
	 */
	function initInfiniteScroll(listing) {
		var grid = listing.querySelector('[data-tube-video-grid]');
		var paginationWrap = listing.querySelector('[data-tube-pagination]');

		if (!grid || !paginationWrap) {
			return;
		}

		var nextLink = paginationWrap.querySelector('a[rel="next"]');

		if (!nextLink) {
			return;
		}

		var i18n = window.tubeThemeI18n || {};
		var loadingText = i18n.loadingMore || 'Loading more videos…';
		var errorText = i18n.loadMoreError || 'Could not load more videos.';

		var statusEl = document.createElement('div');
		statusEl.className = 'listing__status';
		statusEl.setAttribute('role', 'status');
		statusEl.setAttribute('aria-live', 'polite');
		listing.insertBefore(statusEl, paginationWrap);

		var sentinel = document.createElement('div');
		sentinel.className = 'listing__sentinel';
		listing.insertBefore(sentinel, statusEl);

		document.body.classList.add('js-infinite-scroll-active');

		var loading = false;

		function showStatus(text, isError) {
			statusEl.textContent = text;
			statusEl.classList.toggle('listing__error', Boolean(isError));
			statusEl.classList.add('is-visible');
		}

		function hideStatus() {
			statusEl.classList.remove('is-visible');
			statusEl.textContent = '';
		}

		function loadNext() {
			if (loading || !nextLink) {
				return;
			}

			loading = true;
			showStatus(loadingText, false);

			fetch(nextLink.getAttribute('href'), { credentials: 'same-origin' })
				.then(function (response) {
					if (!response.ok) {
						throw new Error('tube-theme: infinite scroll fetch failed with status ' + response.status);
					}

					return response.text();
				})
				.then(function (html) {
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var fetchedGrid = doc.querySelector('[data-tube-video-grid]');
					var fetchedPaginationWrap = doc.querySelector('[data-tube-pagination]');

					if (fetchedGrid) {
						while (fetchedGrid.firstChild) {
							grid.appendChild(fetchedGrid.firstChild);
						}
					}

					var fetchedNextLink = fetchedPaginationWrap
						? fetchedPaginationWrap.querySelector('a[rel="next"]')
						: null;

					if (fetchedNextLink) {
						nextLink = fetchedNextLink;
						window.history.pushState(null, '', nextLink.getAttribute('href'));
					} else {
						nextLink = null;
						observer.disconnect();
					}

					if (doc.title) {
						document.title = doc.title;
					}

					hideStatus();
					loading = false;
				})
				.catch(function () {
					// Leave the real <nav data-tube-pagination> visible/functional
					// as the fallback — never dead-end the page on a failed fetch.
					showStatus(errorText, true);
					loading = false;
				});
		}

		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						loadNext();
					}
				});
			},
			{ rootMargin: '400px 0px' }
		);

		observer.observe(sentinel);
	}
})();
