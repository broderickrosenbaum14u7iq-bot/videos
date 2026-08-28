/**
 * Tube Members' login/register modal + account menu (Phases 4/5/7).
 *
 * No framework, no build step — same vanilla-JS constraint tube-theme.js
 * already follows. Exposes `window.TubeMembersAuth` so other plugins
 * (tube-comments) can trigger the same modal from a guest's "Viết bình
 * luận...", "Trả lời", "Thích", "Báo cáo" actions without building a
 * second login UI, and can listen for `tube-members:authenticated` to
 * resume whatever the visitor was doing before login (Phase 5's "restore
 * pending user action" — the comment textarea itself is never touched or
 * removed by this modal, so the visitor's typed text survives login by
 * simply never being destroyed in the first place).
 */
(function () {
	'use strict';

	var config = window.TubeMembersConfig || {};
	var modal = document.querySelector('[data-tube-auth-modal]');
	var lastFocused = null;

	/* ---- Modal open/close ------------------------------------------- */

	function currentView() {
		if (!modal) {
			return null;
		}

		return modal.querySelector('[data-tube-auth-view]:not([hidden])');
	}

	function switchView(name) {
		if (!modal) {
			return;
		}

		var views = modal.querySelectorAll('[data-tube-auth-view]');

		for (var i = 0; i < views.length; i++) {
			var isTarget = views[i].getAttribute('data-tube-auth-view') === name;
			views[i].hidden = !isTarget;

			if (isTarget) {
				clearError(views[i]);
			}
		}
	}

	function openModal(view) {
		if (!modal) {
			return;
		}

		switchView(view || 'login');

		lastFocused = document.activeElement;
		modal.hidden = false;
		document.body.classList.add('tube-auth-modal-open');

		window.setTimeout(function () {
			var view = currentView();
			var firstInput = view ? view.querySelector('input') : null;

			if (firstInput) {
				firstInput.focus();
			}
		}, 0);
	}

	function closeModal() {
		if (!modal || modal.hidden) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove('tube-auth-modal-open');

		if (lastFocused && 'function' === typeof lastFocused.focus) {
			lastFocused.focus();
		}
	}

	if (modal) {
		document.addEventListener('click', function (event) {
			var opener = event.target.closest('[data-tube-auth-open]');

			if (opener) {
				event.preventDefault();
				openModal(opener.getAttribute('data-tube-auth-open'));

				return;
			}

			if (event.target.closest('[data-tube-auth-close]')) {
				closeModal();

				return;
			}

			var switcher = event.target.closest('[data-tube-auth-switch]');

			if (switcher) {
				switchView(switcher.getAttribute('data-tube-auth-switch'));
			}
		});

		document.addEventListener('keydown', function (event) {
			if ('Escape' !== event.key || modal.hidden) {
				return;
			}

			closeModal();
		});

		// Minimal focus trap: Tab/Shift+Tab wraps within the modal panel
		// while it is open, so keyboard focus never silently escapes to
		// the page behind it (Phase 4's "keyboard accessible").
		modal.addEventListener('keydown', function (event) {
			if ('Tab' !== event.key) {
				return;
			}

			var panel = modal.querySelector('.tube-auth-modal__panel');
			var focusable = panel
				? panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
				: [];

			if (0 === focusable.length) {
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
	}

	/* ---- Error display ------------------------------------------------ */

	function showError(view, message) {
		var el = view.querySelector('[data-tube-auth-error]');

		if (!el) {
			return;
		}

		el.textContent = message;
		el.hidden = false;
	}

	function clearError(view) {
		var el = view.querySelector('[data-tube-auth-error]');

		if (el) {
			el.hidden = true;
			el.textContent = '';
		}
	}

	function firstErrorMessage(errors) {
		if (!errors || 'object' !== typeof errors) {
			return 'Đã xảy ra lỗi. Vui lòng thử lại.';
		}

		for (var key in errors) {
			if (Object.prototype.hasOwnProperty.call(errors, key)) {
				return errors[key];
			}
		}

		return 'Đã xảy ra lỗi. Vui lòng thử lại.';
	}

	/* ---- Header account UI --------------------------------------------- */

	/**
	 * Rebuilds the header account slot in place after a successful
	 * login/registration -- no page reload (Phase 4/Phase 34: "Header
	 * immediately reflects logged-in state").
	 *
	 * @param {{display_name: string, avatar_url?: string}} user
	 */
	function renderLoggedInHeader(user) {
		var slot = document.querySelector('[data-tube-header-account]');

		if (!slot) {
			return;
		}

		var avatarUrl = user.avatar_url || '';
		var name = user.display_name || '';

		slot.innerHTML =
			'<div class="tube-account" data-tube-account-menu>' +
			'<button type="button" class="tube-account__trigger" data-tube-account-toggle aria-haspopup="true" aria-expanded="false">' +
			(avatarUrl
				? '<img class="tube-account__avatar" src="' + escapeAttr(avatarUrl) + '" alt="" width="28" height="28">'
				: '') +
			'<span class="tube-account__name">' + escapeHtml(name) + '</span>' +
			'<svg class="tube-account__caret" viewBox="0 0 12 12" aria-hidden="true"><path d="M2 4l4 4 4-4" stroke="currentColor" fill="none" stroke-width="1.5" /></svg>' +
			'</button>' +
			'<div class="tube-account__menu" data-tube-account-dropdown hidden>' +
			'<a class="tube-account__menu-item" href="' + escapeAttr(config.accountUrl || '/') + '">Hồ sơ của tôi</a>' +
			'<a class="tube-account__menu-item" href="' + escapeAttr((config.accountUrl || '/') + '#video-da-luu') + '">Video đã lưu</a>' +
			'<a class="tube-account__menu-item" href="' + escapeAttr((config.accountUrl || '/') + '#binh-luan-cua-toi') + '">Bình luận của tôi</a>' +
			'<button type="button" class="tube-account__menu-item tube-account__menu-item--danger" data-tube-auth-logout>Đăng xuất</button>' +
			'</div></div>';
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text;

		return div.innerHTML;
	}

	function escapeAttr(text) {
		return escapeHtml(text).replace(/"/g, '&quot;');
	}

	// Avatar broken-image fallback: `AvatarService::url_for()` (PHP) can
	// only vouch for the URL it hands back at RENDER time -- a stored
	// Google avatar URL can still go stale/404 later (Google rotates or
	// removes them independently of this site). The fallback URL itself
	// is the exact same `AvatarService::default_avatar_url()` generated-
	// initials data: URI the PHP-rendered "no avatar at all" case already
	// uses -- never a second, different placeholder -- and can't itself
	// fail to load (it's an inline data: URI, no network request), so
	// removing the attribute after one swap is just to stop doing
	// pointless work on further error events, not a loop-prevention
	// necessity.
	function applyAvatarFallback(img) {
		var fallback = img.getAttribute('data-tube-avatar-fallback');

		if (!fallback || img.src === fallback) {
			return;
		}

		img.removeAttribute('data-tube-avatar-fallback');
		img.src = fallback;
	}

	// `error` doesn't bubble, so this listens in the CAPTURE phase on
	// `document` instead of delegating the normal way; still one listener
	// for every avatar, present now or added later (e.g. if
	// `renderLoggedInHeader()` above ever starts rendering one too), not
	// a per-image binding. Covers an avatar image that fails AFTER this
	// script has run.
	document.addEventListener(
		'error',
		function (event) {
			var img = event.target;

			if (img && 'IMG' === img.tagName && img.classList.contains('tube-account__avatar')) {
				applyAvatarFallback(img);
			}
		},
		true
	);

	// Catch-up check for an avatar that already failed BEFORE this
	// listener existed -- this script is footer-enqueued, but the
	// header's own `<img class="tube-account__avatar">` sits at the very
	// top of `<body>`, so the browser can start (and finish failing) that
	// request well before a footer `<script>` ever runs; `error` already
	// fired and is gone by then, with nothing left to delegate to.
	// `img.complete && naturalWidth === 0` is the standard way to detect
	// an already-failed `<img>` after the fact (a genuinely loaded image
	// always has a non-zero naturalWidth) -- confirmed live during this
	// task's own broken-avatar QA: without this check, a stale/404
	// Google avatar stayed broken indefinitely even though the error
	// listener above was correctly wired.
	var existingAvatars = document.querySelectorAll('.tube-account__avatar');

	for (var avatarIndex = 0; avatarIndex < existingAvatars.length; avatarIndex++) {
		var existingAvatar = existingAvatars[avatarIndex];

		if (existingAvatar.complete && 0 === existingAvatar.naturalWidth) {
			applyAvatarFallback(existingAvatar);
		}
	}

	// Account-menu dropdown toggle + outside-click/logout handling --
	// delegated on document since the header slot is replaced in place
	// after login (renderLoggedInHeader() above), so a listener bound to
	// the original element would stop working post-login.
	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('[data-tube-account-toggle]');

		if (toggle) {
			var menu = toggle.parentNode.querySelector('[data-tube-account-dropdown]');

			if (menu) {
				var willOpen = menu.hidden;
				menu.hidden = !willOpen;
				toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			}

			return;
		}

		if (!event.target.closest('[data-tube-account-menu]')) {
			var openMenus = document.querySelectorAll('[data-tube-account-dropdown]:not([hidden])');

			for (var i = 0; i < openMenus.length; i++) {
				openMenus[i].hidden = true;
			}
		}

		if (event.target.closest('[data-tube-auth-logout]')) {
			logout();
		}
	});

	function logout() {
		fetch(config.logoutUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce || '' },
		})
			.catch(function () {
				// Fail silently -- the page reload below still runs, which
				// re-derives real logged-in state from the server either way.
			})
			.finally(function () {
				window.location.reload();
			});
	}

	/* ---- Form submission ------------------------------------------------ */

	function postJson(url, body) {
		// The anonymous auth nonce travels in the JSON body as
		// `_wpnonce`, never the X-WP-Nonce header -- WordPress core's
		// own rest_cookie_check_errors() intercepts that exact header on
		// every REST request and validates it against the 'wp_rest'
		// action specifically, rejecting anything else outright before
		// RegistrationController/LoginController ever run.
		body._wpnonce = config.authNonce || '';

		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
		}).then(function (response) {
			return response.json().then(function (data) {
				return { ok: response.ok, data: data };
			});
		});
	}

	function onAuthenticated(user, restNonce) {
		config.isLoggedIn = true;
		// `user` is either the real /members/me profile (the common case
		// -- see fetchProfileAndFinish() below) or, only if that fetch
		// itself failed, the raw register/login response, which also
		// carries this field (2026-08-27 email-verification task) as a
		// defensive fallback.
		config.isEmailVerified = !!user.email_verified;

		if (restNonce) {
			config.restNonce = restNonce;
		}

		renderLoggedInHeader(user);
		closeModal();

		// Other plugins loaded on this same page (tube-comments) hold
		// their own separate `TubeCommentsConfig.restNonce` -- their own
		// page-load nonce is just as stale post-login as this plugin's
		// was (see AuthSessionService::log_in()'s docblock), so the
		// fresh nonce travels in this event's detail for them to adopt.
		window.dispatchEvent(
			new CustomEvent('tube-members:authenticated', { detail: { user: user, restNonce: config.restNonce } })
		);
	}

	if (modal) {
		modal.addEventListener('submit', function (event) {
			var form = event.target.closest('[data-tube-auth-form]');

			if (!form) {
				return;
			}

			event.preventDefault();

			var kind = form.getAttribute('data-tube-auth-form');
			var view = form.closest('[data-tube-auth-view]');
			var submitBtn = form.querySelector('.tube-auth-modal__submit');

			clearError(view);

			if (submitBtn) {
				submitBtn.disabled = true;
			}

			var url;
			var body;

			if ('login' === kind) {
				url = config.loginUrl;
				body = {
					login: form.login.value,
					password: form.password.value,
					remember: true,
				};
			} else {
				url = config.registerUrl;
				body = {
					display_name: form.display_name.value,
					email: form.email.value,
					password: form.password.value,
					password_confirm: form.password_confirm.value,
				};
			}

			postJson(url, body)
				.then(function (result) {
					if (result.ok && result.data && result.data.success) {
						// Adopt the fresh nonce BEFORE the /members/me
						// fetch below -- that call is itself gated by
						// core's cookie-nonce check, so it must already
						// use the post-login nonce, not the stale one
						// this page loaded with.
						if (result.data.rest_nonce) {
							config.restNonce = result.data.rest_nonce;
						}

						fetchProfileAndFinish(result.data.user);

						return;
					}

					showError(view, firstErrorMessage(result.data ? result.data.errors : null));
				})
				.catch(function () {
					showError(view, 'Không thể kết nối máy chủ. Vui lòng thử lại.');
				})
				.finally(function () {
					if (submitBtn) {
						submitBtn.disabled = false;
					}
				});
		});
	}

	/**
	 * Login/register responses don't include an avatar URL (the account
	 * services keep that concern inside ProfileController) -- fetch it
	 * once so the header shows a real avatar immediately rather than a
	 * blank one until the next full page load.
	 */
	function fetchProfileAndFinish(user) {
		fetch(config.meUrl, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce || '' },
		})
			.then(function (response) {
				return response.ok ? response.json() : null;
			})
			.then(function (profile) {
				onAuthenticated(profile || user);
			})
			.catch(function () {
				onAuthenticated(user);
			});
	}

	/* ---- Email verification (2026-08-27) --------------------------------- */

	/**
	 * Re-fetch this account's real server-side verification state and
	 * update `config.isEmailVerified` to match -- Phase 30's "verified in
	 * another tab" problem: this page's own JS state can only ever be as
	 * fresh as the last time it asked the server. Dispatches
	 * `tube-members:email-verified` (only) the moment a check discovers
	 * the flip from unverified to verified, so any composer/reply/report
	 * notice already showing can unlock itself without the visitor
	 * needing to know to reload.
	 *
	 * @param {function(boolean):void} [onDone] Called with the fresh verified state once known.
	 */
	function refreshEmailVerification(onDone) {
		if (!config.isLoggedIn) {
			if (onDone) {
				onDone(false);
			}

			return;
		}

		fetch(config.meUrl, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce || '' },
		})
			.then(function (response) {
				return response.ok ? response.json() : null;
			})
			.then(function (profile) {
				var wasVerified = !!config.isEmailVerified;
				var isVerified = !!(profile && profile.email_verified);
				config.isEmailVerified = isVerified;

				if (isVerified && !wasVerified) {
					window.dispatchEvent(new CustomEvent('tube-members:email-verified'));
				}

				if (onDone) {
					onDone(isVerified);
				}
			})
			.catch(function () {
				if (onDone) {
					onDone(!!config.isEmailVerified);
				}
			});
	}

	// A tab-switch back to this page is the one moment worth a fresh
	// check (Phase 30: "when browser window regains focus" -- explicitly
	// NOT a polling interval, which the task calls out as unwanted
	// "hammering"). Skipped entirely once already verified/not logged
	// in, and throttled to at most once per 10s so rapid focus/blur
	// (switching between two windows) can't spam the endpoint either.
	var lastFocusCheckAt = 0;

	window.addEventListener('focus', function () {
		if (!config.isLoggedIn || config.isEmailVerified) {
			return;
		}

		var now = Date.now();

		if (now - lastFocusCheckAt < 10000) {
			return;
		}

		lastFocusCheckAt = now;
		refreshEmailVerification();
	});

	/**
	 * Request a fresh verification email for the current account (Phase
	 * 17) -- the one resend implementation every "Gửi lại email xác
	 * thực" button on the page (account page, comment/reply/report
	 * notices) calls through, so the URL/nonce plumbing exists once.
	 *
	 * @param {function(Object):void} [onDone] Called with the parsed JSON response body.
	 */
	function resendVerificationEmail(onDone) {
		fetch(config.resendVerificationUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce || '' },
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (onDone) {
					onDone(data || {});
				}
			})
			.catch(function () {
				if (onDone) {
					onDone({ success: false, message: 'Không thể kết nối máy chủ. Vui lòng thử lại.' });
				}
			});
	}

	/* ---- Public API for other plugins (tube-comments) -------------------- */

	window.TubeMembersAuth = {
		open: openModal,
		close: closeModal,
		isLoggedIn: function () {
			return !!config.isLoggedIn;
		},
		isEmailVerified: function () {
			return !!config.isEmailVerified;
		},
		refreshEmailVerification: refreshEmailVerification,
		resendVerificationEmail: resendVerificationEmail,
	};
})();
