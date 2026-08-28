/**
 * Tube Theme's client-side glue (Phase 13). No framework, no build step,
 * no dependency beyond browser-native APIs — same constraint this file
 * has followed since Phase 8.
 *
 * Sections:
 *  1. Search form redirect (Phase 8, unchanged) — also what the header's
 *     now-always-visible mobile search field submits through, no
 *     separate mobile handling needed.
 *  2. Mega-menu touch-tap toggle (desktop pointer/keyboard is handled
 *     entirely by CSS :hover/:focus-within — this is only needed for
 *     touch devices, where :hover doesn't apply). Also mobile's only
 *     nav-reveal mechanism now (2026-08-28) — see its own comment below.
 *  3. Infinite scroll — progressive enhancement over real, already-
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

	/* 2. Mega-menu touch-tap toggle -------------------------------------
	   2026-08-28: this is now the ONLY mobile nav-reveal mechanism —
	   `.site-nav` (Danh Mục dropdown + the same nav links) is visible at
	   every width (CSS-only, wrapped onto its own row below brand/search/
	   account by tube-theme.css's `@media (max-width: 1023px)` rules),
	   replacing the old separate off-canvas `.mobile-nav` drawer and
	   icon-triggered `.mobile-search-overlay` (both removed from
	   header.php — their open/close handlers here went with them; the
	   search field itself needs no JS at all, it's a plain visible
	   `<input>` now, submitted by section 1 above like any other width). */

	var navItems = document.querySelectorAll('.site-nav__item');

	// Below 1024px, `.mega-menu` is `position: fixed` (see tube-theme.css)
	// instead of desktop's `position: absolute` -- `.site-nav` is a
	// horizontally-scrolling row there (`overflow-x: auto`), which per
	// the CSS overflow spec also forces its own `overflow-y` to `auto`
	// (an element can't clip one axis and not the other), so a plain
	// `position: absolute` descendant would get clipped to nothing the
	// moment it tried to expand downward past the row's own height --
	// `position: fixed` is what actually escapes that.
	//
	// But `.site-nav` ALSO carries a `mask-image` (the row's right-edge
	// scroll-affordance fade) -- and a non-`none` `mask`/`mask-image`,
	// like `transform`/`filter`, makes its OWN element a *containing
	// block* for `position: fixed` descendants too (confirmed live
	// during this task's own QA: `document.elementFromPoint()` on the
	// open menu's own visible area kept returning the hero image
	// underneath it, at every z-index, until this was found -- removing
	// `.site-header`'s unrelated `backdrop-filter` didn't change
	// anything, which is what pointed at `.site-nav`'s mask instead).
	// `position: fixed` set from *inside* that containing block just
	// positions relative to the mask owner, not the viewport --
	// `top`/`left`/`right` end up correct-looking in isolation but the
	// element paints trapped behind whatever comes after `.site-nav` in
	// the DOM. The fix is a "portal": move the actual `.mega-menu` node
	// out to a direct child of `<body>` while it's open (matching what
	// e.g. any dropdown/modal library does for the same reason), then
	// move it back to its own `.site-nav__item` on close so desktop's
	// `.site-nav__item:hover > .mega-menu` CSS selector still finds it
	// there. Desktop itself never portals (only runs below 1024px),
	// so its own hover/focus reveal is untouched.
	var MOBILE_MEGA_MENU_MAX_WIDTH = 1023;

	function closeNavItem(item) {
		item.classList.remove('is-open');

		var trigger = item.querySelector('button.site-nav__link');

		if (trigger) {
			trigger.setAttribute('aria-expanded', 'false');
		}

		var menu = item._tubeMegaMenu;

		if (menu) {
			menu.classList.remove('mega-menu--open');

			if (menu.parentNode !== item) {
				item.appendChild(menu);
			}
		}
	}

	navItems.forEach(function (item) {
		var trigger = item.querySelector('button.site-nav__link');
		var menu = item.querySelector('.mega-menu');

		item._tubeMegaMenu = menu;

		if (!trigger) {
			return;
		}

		trigger.addEventListener('click', function () {
			var wasOpen = item.classList.contains('is-open');

			navItems.forEach(closeNavItem);

			if (wasOpen) {
				return;
			}

			item.classList.add('is-open');
			trigger.setAttribute('aria-expanded', 'true');

			if (!menu) {
				return;
			}

			if (window.innerWidth <= MOBILE_MEGA_MENU_MAX_WIDTH) {
				var header = document.querySelector('.site-header');

				if (header) {
					menu.style.top = header.getBoundingClientRect().bottom + 'px';
				}

				// Once portalled to <body>, `.mega-menu` is no longer a
				// direct child of `.site-nav__item.is-open`, so the CSS
				// reveal selector (`.site-nav__item.is-open >
				// .mega-menu`) can never match it again -- this class is
				// what tube-theme.css's mobile rule keys off instead.
				menu.classList.add('mega-menu--open');
				document.body.appendChild(menu);
			}
		});
	});

	document.addEventListener('click', function (event) {
		navItems.forEach(function (item) {
			var menu = item._tubeMegaMenu;
			var clickedInsideMenu = menu ? menu.contains(event.target) : false;

			if (!item.contains(event.target) && !clickedInsideMenu) {
				closeNavItem(item);
			}
		});
	});

	/* 3. Infinite scroll ------------------------------------------------ */

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

	/* 5. Watch page: Like / Save / Share -------------------------------- */

	/**
	 * Compact display formatting for a live-updated count -- the exact
	 * client-side counterpart to `tube_theme_compact_number()` (PHP),
	 * kept in sync by hand since this is the one place a count changes
	 * without a full page reload. Formatting only; the server-side
	 * `likes_total` in the response is the real number this reads from.
	 *
	 * @param {number} count
	 * @returns {string}
	 */
	function compactNumber(count) {
		if (count < 1000) {
			return count.toLocaleString('vi-VN');
		}

		if (count < 1000000) {
			var thousands = count / 1000;

			return thousands.toLocaleString('vi-VN', { maximumFractionDigits: thousands < 10 ? 1 : 0 }) + 'K';
		}

		var millions = count / 1000000;

		return millions.toLocaleString('vi-VN', { maximumFractionDigits: millions < 10 ? 1 : 0 }) + 'M';
	}

	document.querySelectorAll('[data-tube-video-actions]').forEach(function (actions) {
		var likeBtn = actions.querySelector('[data-tube-like-btn]');
		var saveBtn = actions.querySelector('[data-tube-save-btn]');
		var shareBtn = actions.querySelector('[data-tube-share-btn]');
		var toast = actions.parentNode ? actions.parentNode.querySelector('[data-tube-share-toast]') : null;

		// If tube-members' login modal authenticates this visitor without
		// a page reload, this page's own `data-rest-nonce` (rendered at
		// the PREVIOUS, logged-out page load) is now stale -- WordPress
		// nonces are bound to the session token, which just changed on
		// login. Adopting the fresh one here is what lets a Like/Save
		// click right after an in-page login correctly land under the
		// new account rather than erroring out until a manual refresh.
		window.addEventListener('tube-members:authenticated', function (event) {
			if (event && event.detail && event.detail.restNonce) {
				actions.setAttribute('data-rest-nonce', event.detail.restNonce);
			}
		});

		/**
		 * POST to a toggle endpoint (like/save) and hand the parsed JSON
		 * response to onSuccess -- a no-op, silently-ignored request on
		 * failure, the same "never surface an error to the visitor, never
		 * block on it" posture tube-player.js's own recordView() already
		 * documents. A `data-tube-busy` guard (cleared in `finally`)
		 * stops a rapid double-tap from firing a second overlapping
		 * request before the first resolves.
		 *
		 * @param {HTMLButtonElement} btn
		 * @param {string} url
		 * @param {function(Object): void} onSuccess
		 */
		function postToggle(btn, url, onSuccess) {
			if (!btn || !url || btn.hasAttribute('data-tube-busy')) {
				return;
			}

			btn.setAttribute('data-tube-busy', '');

			// X-WP-Nonce (2026-08-26, member system Phase 26/27): without
			// this header, WordPress's own cookie-auth REST layer silently
			// treats EVERY request as logged-out, even a genuinely
			// authenticated member's -- LikeController/SaveController would
			// then only ever see a guest visitor_token, never the real
			// user_id, no matter how correct their own identity logic is.
			// Harmless for an actual guest: `Tube_Core\Likes\LikeController`/
			// `SaveController`'s own `permission_callback: '__return_true'`
			// never required a nonce to begin with, and WordPress only
			// enforces this header at all once a real logged-in cookie was
			// already presented (see video-actions.php's own comment on
			// the `data-rest-nonce` attribute this reads).
			var actionsRoot = btn.closest('[data-tube-video-actions]');
			var restNonce = actionsRoot ? actionsRoot.getAttribute('data-rest-nonce') : null;

			fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: restNonce ? { 'X-WP-Nonce': restNonce } : {},
			})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('tube-theme: toggle request failed with status ' + response.status);
					}

					return response.json();
				})
				.then(onSuccess)
				.catch(function () {
					// Fail silently -- the button's own state is left
					// unchanged, so a retried tap is always safe.
				})
				.finally(function () {
					btn.removeAttribute('data-tube-busy');
				});
		}

		if (likeBtn) {
			var likeLabel = likeBtn.querySelector('[data-tube-like-label]');
			var likeCount = likeBtn.querySelector('[data-tube-like-count]');
			var likeUrl = actions.getAttribute('data-like-url');

			likeBtn.addEventListener('click', function () {
				postToggle(likeBtn, likeUrl, function (data) {
					if ('boolean' !== typeof data.liked) {
						return;
					}

					likeBtn.classList.toggle('is-active', data.liked);
					likeBtn.setAttribute('aria-pressed', data.liked ? 'true' : 'false');

					if (likeLabel) {
						likeLabel.textContent = data.liked ? 'Đã thích' : 'Thích';
					}

					if (likeCount && 'number' === typeof data.likes_total) {
						likeCount.textContent = compactNumber(data.likes_total);
					}
				});
			});
		}

		if (saveBtn) {
			var saveLabel = saveBtn.querySelector('[data-tube-save-label]');
			var saveUrl = actions.getAttribute('data-save-url');

			saveBtn.addEventListener('click', function () {
				postToggle(saveBtn, saveUrl, function (data) {
					if ('boolean' !== typeof data.saved) {
						return;
					}

					saveBtn.classList.toggle('is-active', data.saved);
					saveBtn.setAttribute('aria-pressed', data.saved ? 'true' : 'false');

					if (saveLabel) {
						saveLabel.textContent = data.saved ? 'Đã lưu' : 'Lưu';
					}
				});
			});
		}

		function showToast(text) {
			if (!toast) {
				return;
			}

			toast.textContent = text;
			toast.classList.add('is-visible');

			window.setTimeout(function () {
				toast.classList.remove('is-visible');
			}, 2200);
		}

		if (shareBtn) {
			shareBtn.addEventListener('click', function () {
				var url = shareBtn.getAttribute('data-share-url') || window.location.href;
				var title = shareBtn.getAttribute('data-share-title') || document.title;

				if (navigator.share) {
					// The real Web Share API -- no fallback text needed,
					// the OS-native share sheet supplies its own UI.
					navigator.share({ title: title, url: url }).catch(function () {
						// A visitor cancelling the native share sheet also
						// rejects this promise -- not an error to report.
					});

					return;
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard
						.writeText(url)
						.then(function () {
							showToast('Đã sao chép liên kết');
						})
						.catch(function () {
							showToast(url);
						});

					return;
				}

				// No Web Share API, no async Clipboard API (very old
				// browsers only) -- surface the real URL directly rather
				// than silently doing nothing.
				showToast(url);
			});
		}
	});

	/* 6. Watch page: description expand/collapse ------------------------ */

	document.querySelectorAll('[data-tube-description]').forEach(function (wrapper) {
		var content = wrapper.querySelector('[data-tube-description-content]');
		var toggle = wrapper.querySelector('[data-tube-description-toggle]');
		var label = wrapper.querySelector('[data-tube-description-toggle-label]');

		if (!content || !toggle || !label) {
			return;
		}

		// Only show the toggle when the description genuinely overflows
		// its collapsed height -- a short description gets no "Xem
		// thêm" control at all (Part 13's explicit requirement).
		if (content.scrollHeight <= content.clientHeight + 2) {
			return;
		}

		toggle.hidden = false;

		toggle.addEventListener('click', function () {
			var expanded = wrapper.classList.toggle('is-expanded');
			label.textContent = expanded ? 'Thu gọn' : 'Xem thêm';
		});
	});
})();
