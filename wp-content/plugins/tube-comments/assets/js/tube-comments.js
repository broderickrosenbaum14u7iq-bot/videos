/**
 * Tube Comments — comment list, composer, replies, likes, reports,
 * edit/delete, and timestamp-seek (Phases 11-20, 23-25, 37).
 *
 * No framework, no build step, same constraint as every other tube-*
 * frontend script. Guests can read; every write action gates through
 * `window.TubeMembersAuth.open()` (tube-members' modal) rather than
 * building a second login UI (Phase 12).
 */
(function () {
	'use strict';

	var config = window.TubeCommentsConfig || {};

	/* ==================================================================
	 * Vietnamese relative time
	 * ================================================================== */

	function relativeTime(isoString) {
		var then = new Date(isoString).getTime();

		if (isNaN(then)) {
			return '';
		}

		var seconds = Math.max(0, Math.floor((Date.now() - then) / 1000));

		if (seconds < 60) {
			return 'Vừa xong';
		}

		var minutes = Math.floor(seconds / 60);

		if (minutes < 60) {
			return minutes + ' phút trước';
		}

		var hours = Math.floor(minutes / 60);

		if (hours < 24) {
			return hours + ' giờ trước';
		}

		var days = Math.floor(hours / 24);

		if (days < 30) {
			return days + ' ngày trước';
		}

		var months = Math.floor(days / 30);

		if (months < 12) {
			return months + ' tháng trước';
		}

		return Math.floor(months / 12) + ' năm trước';
	}

	function compactNumber(count) {
		if (count < 1000) {
			return String(count);
		}

		if (count < 1000000) {
			return (count / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + 'K';
		}

		return (count / 1000000).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + 'M';
	}

	/* ==================================================================
	 * Content rendering: escape, then linkify timestamps + URLs + line breaks
	 * ================================================================== */

	var TIMESTAMP_RE = /\b(?:([0-9]{1,2}):)?([0-5]?[0-9]):([0-5][0-9])\b/g;
	var URL_RE = /\bhttps?:\/\/[^\s<]+[^\s<.,:;!?)'"]/g;

	function timestampToSeconds(hours, minutes, seconds) {
		return (hours ? parseInt(hours, 10) * 3600 : 0) + parseInt(minutes, 10) * 60 + parseInt(seconds, 10);
	}

	/**
	 * Builds safe DOM nodes from plain-text comment content: escapes
	 * everything by construction (text nodes only, never innerHTML with
	 * unsanitized input -- Phase 20's XSS requirement), then replaces
	 * recognized timestamp/URL spans with real elements.
	 *
	 * @param {string} text
	 * @param {number} videoDuration Seconds; 0/unknown means "no upper bound".
	 * @returns {DocumentFragment}
	 */
	function renderContent(text, videoDuration) {
		var fragment = document.createDocumentFragment();
		var lines = String(text).split('\n');

		lines.forEach(function (line, lineIndex) {
			if (lineIndex > 0) {
				fragment.appendChild(document.createElement('br'));
			}

			appendLinkified(fragment, line, videoDuration);
		});

		return fragment;
	}

	function appendLinkified(fragment, line, videoDuration) {
		var lastIndex = 0;
		var matches = [];
		var match;

		TIMESTAMP_RE.lastIndex = 0;

		while ((match = TIMESTAMP_RE.exec(line))) {
			var seconds = timestampToSeconds(match[1], match[2], match[3]);

			if (videoDuration > 0 && seconds > videoDuration) {
				continue; // Phase 19: beyond the video's real length -- plain text, not a link.
			}

			matches.push({ index: match.index, length: match[0].length, text: match[0], seconds: seconds, type: 'time' });
		}

		URL_RE.lastIndex = 0;

		while ((match = URL_RE.exec(line))) {
			matches.push({ index: match.index, length: match[0].length, text: match[0], type: 'url' });
		}

		matches.sort(function (a, b) {
			return a.index - b.index;
		});

		matches.forEach(function (m) {
			if (m.index < lastIndex) {
				return; // Overlapping match (rare) -- keep the earlier one only.
			}

			if (m.index > lastIndex) {
				fragment.appendChild(document.createTextNode(line.slice(lastIndex, m.index)));
			}

			if ('time' === m.type) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'tube-comments__timestamp';
				btn.setAttribute('data-tube-comments-seek', String(m.seconds));
				btn.textContent = m.text;
				fragment.appendChild(btn);
			} else {
				var a = document.createElement('a');
				a.href = m.text;
				a.rel = 'ugc nofollow noopener';
				a.target = '_blank';
				a.textContent = m.text;
				fragment.appendChild(a);
			}

			lastIndex = m.index + m.length;
		});

		if (lastIndex < line.length) {
			fragment.appendChild(document.createTextNode(line.slice(lastIndex)));
		}
	}

	/* ==================================================================
	 * Cloudflare Stream seek bridge (Phase 19/37) -- smallest possible
	 * bridge over Cloudflare's own documented iframe Player SDK
	 * (embed.cloudflarestream.com/embed/sdk.latest.js -> Stream(iframe)),
	 * lazy-loaded only the first time a timestamp is actually clicked.
	 * tube-player.js itself is never modified -- this only ever
	 * dispatches a real click on the SAME .tube-player__play button a
	 * visitor would click, so an eligible pre-roll (tube-ads) still runs
	 * exactly as it would for a normal click; this never seeks past that
	 * gate.
	 * ================================================================== */

	var streamSdkPromise = null;

	function loadStreamSdk() {
		if (streamSdkPromise) {
			return streamSdkPromise;
		}

		streamSdkPromise = new Promise(function (resolve) {
			if (window.Stream) {
				resolve(window.Stream);

				return;
			}

			var script = document.createElement('script');
			script.src = 'https://embed.cloudflarestream.com/embed/sdk.latest.js';
			script.onload = function () {
				resolve(window.Stream || null);
			};
			script.onerror = function () {
				resolve(null);
			};
			document.head.appendChild(script);
		});

		return streamSdkPromise;
	}

	function seekMainPlayer(seconds) {
		var player = document.querySelector('[data-tube-player]');

		if (!player) {
			return;
		}

		function seekWhenReady() {
			var iframe = player.querySelector('iframe');

			if (!iframe) {
				return;
			}

			loadStreamSdk().then(function (Stream) {
				if (!Stream) {
					return;
				}

				try {
					var api = Stream(iframe);
					api.currentTime = seconds;

					if (api.paused) {
						api.play().catch(function () {});
					}
				} catch (error) {
					// Seeking is a convenience, never critical -- fail silently.
				}
			});
		}

		if (player.hasAttribute('data-tube-player-active')) {
			seekWhenReady();

			return;
		}

		// Not yet activated: trigger the real Play button so an eligible
		// pre-roll still runs (Phase 37 -- never bypass it), then seek
		// once the iframe actually appears.
		var playBtn = player.querySelector('.tube-player__play');

		if (!playBtn) {
			return;
		}

		var observer = new MutationObserver(function () {
			if (player.hasAttribute('data-tube-player-active')) {
				observer.disconnect();
				window.setTimeout(seekWhenReady, 300);
			}
		});

		observer.observe(player, { attributes: true, childList: true });
		window.setTimeout(function () {
			observer.disconnect();
		}, 30000);

		playBtn.click();
	}

	/* ==================================================================
	 * REST helpers
	 * ================================================================== */

	function restFetch(url, options) {
		options = options || {};
		options.credentials = 'same-origin';
		options.headers = options.headers || {};
		options.headers['X-WP-Nonce'] = config.restNonce || '';

		return fetch(url, options).then(function (response) {
			return response.json().then(function (data) {
				return { ok: response.ok, status: response.status, data: data };
			});
		});
	}

	function postJson(url, body) {
		return restFetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body || {}),
		});
	}

	/* ==================================================================
	 * Guest gating -- open tube-members' modal, remember + resume the
	 * pending action once authenticated (Phase 5/12/34).
	 * ================================================================== */

	function requireAuth(onAuthenticated) {
		if (config.isLoggedIn) {
			onAuthenticated();

			return;
		}

		if (window.TubeMembersAuth) {
			window.TubeMembersAuth.open('login');
		}

		window.addEventListener(
			'tube-members:authenticated',
			function once(event) {
				window.removeEventListener('tube-members:authenticated', once);
				config.isLoggedIn = true;
				config.currentUserId = 0; // Refined lazily -- not needed for the resumed action itself.

				// tube-members' own page-load nonce is stale the moment
				// login succeeds (WordPress nonces are bound to the
				// session token, which just changed) -- this plugin's
				// separate TubeCommentsConfig.restNonce carries the exact
				// same staleness and must adopt the same fresh value, or
				// every write this event is about to resume (comment,
				// like, reply, report) would fail with a 403.
				if (event && event.detail && event.detail.restNonce) {
					config.restNonce = event.detail.restNonce;
				}

				// A brand-new registration starts unverified (2026-08-27
				// email-verification task) -- carried here so the resumed
				// action below (requireVerifiedAuth(), used by the
				// composer/reply/report call sites) correctly blocks with
				// a verification notice INSTEAD of submitting, preserving
				// whatever the visitor already typed rather than losing
				// it (Phase 29).
				if (event && event.detail && event.detail.user) {
					config.isEmailVerified = !!event.detail.user.email_verified;
				}

				onAuthenticated();
			},
			{ once: true }
		);
	}

	/**
	 * Build one detached "you must verify your email" notice: a message
	 * plus a "Gửi lại email xác thực" button wired to tube-members' own
	 * `resendVerificationEmail()` (2026-08-27 email-verification task,
	 * Phases 14/15/16) -- the one notice builder every gated action
	 * (root comment, reply, report) inserts into its own context, so the
	 * three call sites never diverge in wording/behavior.
	 */
	function buildVerificationNotice(message) {
		var el = document.createElement('div');
		el.className = 'tube-comments__verify-notice';

		var text = document.createElement('span');
		text.textContent = message;
		el.appendChild(text);

		if (window.TubeMembersAuth && window.TubeMembersAuth.resendVerificationEmail) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'tube-comments__btn-ghost';
			btn.textContent = 'Gửi lại email xác thực';
			btn.addEventListener('click', function () {
				btn.disabled = true;
				window.TubeMembersAuth.resendVerificationEmail(function (result) {
					btn.disabled = false;
					text.textContent = (result && result.message) || message;
				});
			});
			el.appendChild(btn);
		}

		return el;
	}

	/**
	 * A small modal notice for actions with no natural inline slot of
	 * their own (report lives in a dropdown menu item, not a persistent
	 * form) -- reuses the report picker's own `.tube-comments__report-*`
	 * classes so it's visually consistent with zero new CSS beyond the
	 * notice/button styling {@see buildVerificationNotice()} already needs.
	 *
	 * @param {string} message The Vietnamese notice text.
	 */
	function openVerificationDialog(message) {
		var existing = document.querySelector('.tube-comments__report-dialog');

		if (existing) {
			existing.remove();
		}

		var dialog = document.createElement('div');
		dialog.className = 'tube-comments__report-dialog';

		var panel = document.createElement('div');
		panel.className = 'tube-comments__report-panel';
		panel.appendChild(buildVerificationNotice(message));

		var closeBtn = document.createElement('button');
		closeBtn.type = 'button';
		closeBtn.className = 'tube-comments__btn-ghost';
		closeBtn.textContent = 'Đóng';
		closeBtn.addEventListener('click', function () {
			dialog.remove();
		});
		panel.appendChild(closeBtn);

		dialog.appendChild(panel);
		document.body.appendChild(dialog);

		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				dialog.remove();
			}
		});
	}

	/**
	 * requireAuth(), plus an email-verification gate for the three
	 * account-gated-but-verification-required actions (root comment,
	 * reply, report -- explicitly NOT comment like, which stays plain
	 * requireAuth() per Phase 12's own product rule). `onVerified` runs
	 * only once BOTH checks pass; `onUnverified` renders whatever notice
	 * fits that call site's own DOM shape (the root composer's dedicated
	 * blocked-state element, a reply's own holder, a report dialog).
	 *
	 * @param {function():void} onVerified   Runs once logged in AND verified.
	 * @param {function():void} onUnverified Runs when logged in but NOT verified.
	 */
	function requireVerifiedAuth(onVerified, onUnverified) {
		requireAuth(function () {
			if (config.isEmailVerified) {
				onVerified();

				return;
			}

			onUnverified();
		});
	}

	// Phase 30: a verification completed in another tab/session must be
	// able to unlock an already-open composer/reply/report notice
	// without a full reload -- window.TubeMembersAuth dispatches this
	// the moment its own focus-triggered refresh (or an explicit
	// "Kiểm tra lại" call) discovers the flip to verified.
	window.addEventListener('tube-members:email-verified', function () {
		config.isEmailVerified = true;
	});

	/* ==================================================================
	 * Comment section widget (watch page)
	 * ================================================================== */

	var section = document.querySelector('[data-tube-comments]');

	if (section) {
		initSection(section);
	}

	function initSection(root) {
		var videoDuration = parseInt(root.getAttribute('data-video-duration'), 10) || 0;
		var listUrl = root.getAttribute('data-list-url');
		var createUrl = root.getAttribute('data-create-url');
		var repliesUrlBase = root.getAttribute('data-replies-url-base');
		var listEl = root.querySelector('[data-tube-comments-list]');
		var skeletonEl = root.querySelector('[data-tube-comments-skeleton]');
		var loadMoreBtn = root.querySelector('[data-tube-comments-load-more]');
		var countEl = root.querySelector('[data-tube-comments-count]');
		var composerForm = root.querySelector('[data-tube-comments-composer]');
		var composerInput = root.querySelector('[data-tube-comments-composer-input]');
		var composerActions = root.querySelector('[data-tube-comments-composer-actions]');
		var composerFields = root.querySelector('[data-tube-comments-composer-fields]');
		var composerBlocked = root.querySelector('[data-tube-comments-composer-blocked]');

		var sort = 'recent';
		var nextCursor = null;
		var isLoadingPage = false;
		var loadMoreDefaultText = loadMoreBtn ? loadMoreBtn.textContent : '';

		// Watch-page comment collapse (2026-08-27): the very first load,
		// and every reset caused by switching "Mới nhất"/"Phổ biến" (both
		// call loadPage(true) below), always asks for exactly 3 roots --
		// only an actual "Xem thêm bình luận" click (loadPage(false))
		// asks for the next batch of 5. Neither number is a client-side
		// display trick: both travel as the real `limit` REST param
		// CommentListController now reads (previously a fixed, always-20
		// PAGE_SIZE), so the server itself only ever queries/returns
		// this many rows -- never "fetch a big page and hide the rest."
		var INITIAL_LIMIT = 3;
		var LOAD_MORE_LIMIT = 5;

		/* ---- Root-comment blocked state (server-driven, never localStorage) - */

		function formatRemaining(availableAtIso) {
			var ms = new Date(availableAtIso).getTime() - Date.now();

			if (isNaN(ms) || ms <= 0) {
				return '';
			}

			var totalMinutes = Math.ceil(ms / 60000);
			var hours = Math.floor(totalMinutes / 60);
			var minutes = totalMinutes % 60;

			if (hours > 0) {
				return ' Có thể bình luận lại sau ' + hours + ' giờ ' + minutes + ' phút.';
			}

			return ' Có thể bình luận lại sau ' + Math.max(1, minutes) + ' phút.';
		}

		var lastRootCommentStatus = null;

		function applyRootCommentStatus(status) {
			lastRootCommentStatus = status;

			if (!composerFields || !composerBlocked) {
				return;
			}

			// Verification takes priority over the daily-lock display
			// (2026-08-27 email-verification task, Phase 14): an
			// unverified visitor needs to know THAT first, not "you
			// already commented today," which isn't the real reason
			// they can't comment right now.
			if (config.isLoggedIn && !config.isEmailVerified) {
				composerFields.hidden = true;
				composerBlocked.innerHTML = '';
				composerBlocked.appendChild(buildVerificationNotice('Bạn cần xác thực email để bình luận.'));
				composerBlocked.hidden = false;

				return;
			}

			if (status && status.blocked) {
				composerFields.hidden = true;
				composerBlocked.textContent = 'Bạn đã bình luận video này.' + formatRemaining(status.available_at);
				composerBlocked.hidden = false;
			} else {
				composerFields.hidden = false;
				composerBlocked.hidden = true;
			}
		}

		// Render the initial gate synchronously from the page-load config
		// (before any network round trip) so an unverified visitor sees
		// the notice immediately rather than a flash of the normal
		// composer -- the real list fetch's own viewer_root_comment_status
		// (below) corrects/refines this once it arrives.
		applyRootCommentStatus(null);

		// Phase 30: a verification completed in another tab/session, or
		// caught by TubeMembersAuth's own focus-triggered refresh, must
		// unlock this already-rendered composer immediately -- re-render
		// with the same last-known root-comment status now that
		// config.isEmailVerified (updated by the module-scope listener
		// above) is true.
		window.addEventListener('tube-members:email-verified', function () {
			applyRootCommentStatus(lastRootCommentStatus);
		});

		// A login completed WITHOUT a page reload (Phase 34 -- the modal
		// never reloads the page) leaves this section's composer showing
		// whatever state it last fetched (as a guest, or as whoever was
		// logged in before). Re-fetching just this field (never the list
		// itself, to avoid racing the comment a guest may be about to post
		// via requireAuth()'s own one-time listener on this same event)
		// keeps the composer's blocked/available state server-accurate the
		// moment a member logs in through ANY path -- the header menu, not
		// just a gated comment/reply attempt.
		//
		// Must adopt event.detail.restNonce before fetching, for the exact
		// reason requireAuth()'s own listener already does: a nonce minted
		// before login is bound to the pre-login session token and 403s
		// (rest_cookie_invalid_nonce) the instant it's sent after login
		// changes that token -- this listener has no gated action of its
		// own to piggyback the nonce refresh on, so it must do it itself.
		window.addEventListener('tube-members:authenticated', function (event) {
			if (event && event.detail && event.detail.restNonce) {
				config.restNonce = event.detail.restNonce;
			}

			restFetch(buildUrl(listUrl, { sort: sort, after: null })).then(function (result) {
				if (result.ok && result.data) {
					applyRootCommentStatus(result.data.viewer_root_comment_status);
				}
			});
		});

		/* ---- Transient inline error for a failed create/reply --------- */

		function showComposerError(container, message) {
			if (!container || !message) {
				return;
			}

			var existing = container.querySelector('.tube-comments__composer-error');

			if (existing) {
				existing.remove();
			}

			var error = document.createElement('p');
			error.className = 'tube-comments__composer-error';
			error.textContent = message;
			container.appendChild(error);

			window.setTimeout(function () {
				error.remove();
			}, 5000);
		}

		function buildUrl(base, params) {
			var url = new URL(base, window.location.origin);

			Object.keys(params).forEach(function (key) {
				if (null !== params[key] && undefined !== params[key]) {
					url.searchParams.set(key, params[key]);
				}
			});

			return url.toString();
		}

		function loadPage(reset) {
			// Race guard (Part I: rapid double-click Load More must be
			// ONE logical batch, never two): a second call -- another
			// click, or a sort switch fired mid-request -- while a
			// request is already in flight is simply ignored rather than
			// firing a second overlapping fetch that could append the
			// same batch twice or race the reset below.
			if (isLoadingPage) {
				return;
			}

			isLoadingPage = true;

			if (reset) {
				listEl.innerHTML = '';
				nextCursor = null;
			}

			if (skeletonEl) {
				skeletonEl.hidden = false;
			}

			if (!reset && loadMoreBtn) {
				loadMoreBtn.disabled = true;
				loadMoreBtn.textContent = 'Đang tải...';
			}

			var limit = reset ? INITIAL_LIMIT : LOAD_MORE_LIMIT;

			restFetch(buildUrl(listUrl, { sort: sort, after: nextCursor, limit: limit }))
				.then(function (result) {
					if (skeletonEl) {
						skeletonEl.hidden = true;
					}

					if (!result.ok || !result.data) {
						return;
					}

					var items = result.data.items || [];

					if (0 === items.length && reset) {
						var empty = document.createElement('p');
						empty.className = 'tube-comments__empty';
						empty.textContent = 'Chưa có bình luận nào. Hãy là người đầu tiên bình luận.';
						listEl.appendChild(empty);
					}

					items.forEach(function (item) {
						listEl.appendChild(renderComment(item, false));
					});

					nextCursor = result.data.next;
					loadMoreBtn.hidden = !nextCursor;

					if (reset) {
						applyRootCommentStatus(result.data.viewer_root_comment_status);
					}
				})
				.catch(function () {
					if (skeletonEl) {
						skeletonEl.hidden = true;
					}
				})
				.finally(function () {
					isLoadingPage = false;

					if (loadMoreBtn) {
						loadMoreBtn.disabled = false;
						loadMoreBtn.textContent = loadMoreDefaultText;
					}
				});
		}

		function renderComment(item, isReply) {
			var el = document.createElement('div');
			el.className = 'tube-comments__item' + (isReply ? ' tube-comments__item--reply' : '');
			el.setAttribute('data-comment-id', item.id);
			el.setAttribute('data-replies-total', item.replies_total || 0);

			if (item.is_deleted) {
				el.classList.add('tube-comments__item--deleted');
				var placeholder = document.createElement('p');
				placeholder.className = 'tube-comments__deleted-text';
				placeholder.textContent = '[Bình luận đã bị xóa]';
				el.appendChild(placeholder);

				if (!isReply && item.replies_total > 0) {
					el.appendChild(buildRepliesArea(item, repliesUrlBase, videoDuration, renderComment));
				}

				return el;
			}

			var avatar = document.createElement('img');
			avatar.className = 'tube-comments__avatar';
			avatar.src = (item.author && item.author.avatar_url) || '';
			avatar.alt = '';
			avatar.width = isReply ? 28 : 36;
			avatar.height = isReply ? 28 : 36;

			var body = document.createElement('div');
			body.className = 'tube-comments__body';

			var meta = document.createElement('div');
			meta.className = 'tube-comments__meta';

			var name = document.createElement('span');
			name.className = 'tube-comments__author';
			name.textContent = (item.author && item.author.display_name) || '';

			var time = document.createElement('span');
			time.className = 'tube-comments__time';
			time.textContent = relativeTime(item.created_at) + (item.edited ? ' · đã chỉnh sửa' : '');

			meta.appendChild(name);
			meta.appendChild(time);

			var contentEl = document.createElement('div');
			contentEl.className = 'tube-comments__content';

			if (item.reply_to && item.reply_to.display_name) {
				var mention = document.createElement('span');
				mention.className = 'tube-comments__mention';
				mention.textContent = '@' + item.reply_to.display_name + ' ';
				contentEl.appendChild(mention);
			}

			contentEl.appendChild(renderContent(item.content, videoDuration));

			var actions = document.createElement('div');
			actions.className = 'tube-comments__actions';

			var likeBtn = document.createElement('button');
			likeBtn.type = 'button';
			likeBtn.className = 'tube-comments__action-btn tube-comments__like-btn' + (item.liked ? ' is-active' : '');
			likeBtn.setAttribute('data-tube-comments-like', item.id);
			likeBtn.innerHTML =
				'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-10-9.3C.4 8 1.8 4.5 5 3.6c2.1-.6 4.2.2 5.5 2 .5.6.9 1.3 1.5 1.3s1-.7 1.5-1.3c1.3-1.8 3.4-2.6 5.5-2 3.2.9 4.6 4.4 3 7.6-2.5 4.7-10 9.3-10 9.3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg> ';
			var likeCount = document.createElement('span');
			likeCount.setAttribute('data-tube-comments-like-count', '');
			likeCount.textContent = compactNumber(item.likes_total);
			likeBtn.appendChild(likeCount);

			actions.appendChild(likeBtn);

			if (!isReply) {
				var replyBtn = document.createElement('button');
				replyBtn.type = 'button';
				replyBtn.className = 'tube-comments__action-btn';
				replyBtn.textContent = 'Trả lời';
				replyBtn.setAttribute('data-tube-comments-reply-toggle', item.id);
				actions.appendChild(replyBtn);
			}

			var menuWrap = document.createElement('div');
			menuWrap.className = 'tube-comments__menu-wrap';

			var menuBtn = document.createElement('button');
			menuBtn.type = 'button';
			menuBtn.className = 'tube-comments__action-btn tube-comments__menu-btn';
			menuBtn.textContent = '⋯';
			menuBtn.setAttribute('data-tube-comments-menu-toggle', '');

			var menu = document.createElement('div');
			menu.className = 'tube-comments__menu';
			menu.hidden = true;

			if (item.is_mine) {
				menu.appendChild(menuItem('Chỉnh sửa', 'edit', item.id));
				menu.appendChild(menuItem('Xóa', 'delete', item.id));
			} else {
				menu.appendChild(menuItem('Báo cáo', 'report', item.id));
			}

			menuWrap.appendChild(menuBtn);
			menuWrap.appendChild(menu);
			actions.appendChild(menuWrap);

			body.appendChild(meta);
			body.appendChild(contentEl);
			body.appendChild(actions);

			el.appendChild(avatar);
			el.appendChild(body);

			if (!isReply) {
				if (item.replies_total > 0) {
					el.appendChild(buildRepliesArea(item, repliesUrlBase, videoDuration, renderComment));
				}

				var replyComposerHolder = document.createElement('div');
				replyComposerHolder.className = 'tube-comments__reply-composer-holder';
				replyComposerHolder.hidden = true;
				el.appendChild(replyComposerHolder);
			}

			return el;
		}

		function menuItem(label, action, commentId) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'tube-comments__menu-item';
			btn.textContent = label;
			btn.setAttribute('data-tube-comments-menu-action', action);
			btn.setAttribute('data-comment-id', commentId);

			return btn;
		}

		function buildRepliesArea(item, base, videoDuration, renderFn) {
			var wrap = document.createElement('div');
			wrap.className = 'tube-comments__replies';

			var toggle = document.createElement('button');
			toggle.type = 'button';
			toggle.className = 'tube-comments__view-replies';
			toggle.textContent = 'Xem ' + item.replies_total + ' câu trả lời';
			toggle.setAttribute('data-tube-comments-replies-toggle', item.id);

			var list = document.createElement('div');
			list.className = 'tube-comments__replies-list';
			list.hidden = true;

			var loaded = false;

			toggle.addEventListener('click', function () {
				list.hidden = !list.hidden;

				if (list.hidden || loaded) {
					return;
				}

				loaded = true;

				restFetch(base + item.id + '/replies').then(function (result) {
					if (!result.ok || !result.data) {
						return;
					}

					(result.data.items || []).forEach(function (reply) {
						list.appendChild(renderFn(reply, true));
					});
				});
			});

			wrap.appendChild(toggle);
			wrap.appendChild(list);

			return wrap;
		}

		/* ---- Composer (root comment) ------------------------------- */

		function autoExpand(textarea) {
			textarea.style.height = 'auto';
			textarea.style.height = textarea.scrollHeight + 'px';
		}

		if (composerInput) {
			composerInput.addEventListener('focus', function () {
				composerActions.hidden = false;
			});

			composerInput.addEventListener('input', function () {
				autoExpand(composerInput);
			});
		}

		var composerCancel = root.querySelector('[data-tube-comments-composer-cancel]');

		if (composerCancel) {
			composerCancel.addEventListener('click', function () {
				composerInput.value = '';
				composerActions.hidden = true;
			});
		}

		if (composerForm) {
			composerForm.addEventListener('submit', function (event) {
				event.preventDefault();

				var content = composerInput.value.trim();

				if ('' === content) {
					return;
				}

				requireVerifiedAuth(
					function () {
						postJson(createUrl, { content: content }).then(function (result) {
							if (result.ok && result.data && result.data.success && result.data.comment) {
								if (listEl.querySelector('.tube-comments__empty')) {
									listEl.innerHTML = '';
								}

								listEl.insertBefore(renderComment(result.data.comment, false), listEl.firstChild);
								composerInput.value = '';
								composerActions.hidden = true;

								if (countEl) {
									countEl.textContent = compactNumber(parseInt(countEl.textContent, 10) + 1 || 1);
								}

								applyRootCommentStatus(result.data.viewer_root_comment_status);

								return;
							}

							var data = result.data || {};

							if ('tube_comment_video_daily_limit' === data.code) {
								applyRootCommentStatus({ blocked: true, available_at: data.available_at });

								return;
							}

							if ('tube_email_verification_required' === data.code) {
								config.isEmailVerified = false;
								applyRootCommentStatus(lastRootCommentStatus);

								return;
							}

							showComposerError(
								composerForm.querySelector('.tube-comments__composer-body'),
								data.message || data.error
							);
						});
					},
					function () {
						applyRootCommentStatus(lastRootCommentStatus);
					}
				);
			});
		}

		/* ---- Sort ---------------------------------------------------- */

		root.querySelectorAll('[data-tube-comments-sort]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (btn.classList.contains('is-active')) {
					return;
				}

				root.querySelectorAll('[data-tube-comments-sort]').forEach(function (b) {
					b.classList.remove('is-active');
				});
				btn.classList.add('is-active');

				sort = btn.getAttribute('data-tube-comments-sort');
				loadPage(true);
			});
		});

		if (loadMoreBtn) {
			loadMoreBtn.addEventListener('click', function () {
				loadPage(false);
			});
		}

		/* ---- Delegated actions: like, reply toggle, menu, timestamp --- */

		root.addEventListener('click', function (event) {
			var likeBtn = event.target.closest('[data-tube-comments-like]');

			if (likeBtn) {
				var commentId = likeBtn.getAttribute('data-tube-comments-like');

				requireAuth(function () {
					var wasActive = likeBtn.classList.contains('is-active');
					likeBtn.classList.toggle('is-active');

					postJson(repliesUrlBase + commentId + '/like', {}).then(function (result) {
						if (!result.ok || !result.data) {
							likeBtn.classList.toggle('is-active', wasActive);

							return;
						}

						likeBtn.classList.toggle('is-active', !!result.data.liked);
						var countSpan = likeBtn.querySelector('[data-tube-comments-like-count]');

						if (countSpan) {
							countSpan.textContent = compactNumber(result.data.likes_total);
						}
					});
				});

				return;
			}

			var replyToggle = event.target.closest('[data-tube-comments-reply-toggle]');

			if (replyToggle) {
				openReplyComposer(replyToggle);

				return;
			}

			var menuToggle = event.target.closest('[data-tube-comments-menu-toggle]');

			if (menuToggle) {
				var menu = menuToggle.nextElementSibling;
				closeAllMenus();

				if (menu) {
					menu.hidden = false;
				}

				return;
			}

			var menuAction = event.target.closest('[data-tube-comments-menu-action]');

			if (menuAction) {
				closeAllMenus();
				handleMenuAction(
					menuAction.getAttribute('data-tube-comments-menu-action'),
					parseInt(menuAction.getAttribute('data-comment-id'), 10),
					menuAction.closest('.tube-comments__item')
				);

				return;
			}

			var seekBtn = event.target.closest('[data-tube-comments-seek]');

			if (seekBtn) {
				seekMainPlayer(parseInt(seekBtn.getAttribute('data-tube-comments-seek'), 10));

				return;
			}

			if (!event.target.closest('.tube-comments__menu-wrap')) {
				closeAllMenus();
			}
		});

		function closeAllMenus() {
			root.querySelectorAll('.tube-comments__menu').forEach(function (m) {
				m.hidden = true;
			});
		}

		function openReplyComposer(toggleBtn) {
			var item = toggleBtn.closest('.tube-comments__item');
			var holder = item.querySelector('.tube-comments__reply-composer-holder');

			if (!holder) {
				return;
			}

			if (!holder.hidden) {
				holder.hidden = true;

				return;
			}

			holder.hidden = false;

			if (holder.dataset.built) {
				var input = holder.querySelector('textarea');

				if (input) {
					input.focus();
				}

				return;
			}

			holder.dataset.built = '1';

			var commentId = item.getAttribute('data-comment-id');
			var form = document.createElement('form');
			form.className = 'tube-comments__reply-composer';

			var textarea = document.createElement('textarea');
			textarea.rows = 1;
			textarea.maxLength = 2000;
			textarea.placeholder = 'Viết câu trả lời...';

			var submit = document.createElement('button');
			submit.type = 'submit';
			submit.className = 'tube-comments__btn-primary';
			submit.textContent = 'Trả lời';

			form.appendChild(textarea);
			form.appendChild(submit);
			holder.appendChild(form);
			textarea.focus();

			form.addEventListener('submit', function (event) {
				event.preventDefault();

				var content = textarea.value.trim();

				if ('' === content) {
					return;
				}

				requireVerifiedAuth(
					function () {
						postJson(createUrl, { content: content, reply_to: commentId }).then(function (result) {
							if (result.ok && result.data && result.data.success && result.data.comment) {
								var repliesArea = item.querySelector('.tube-comments__replies');

								if (!repliesArea) {
									var fakeRoot = { id: commentId, replies_total: 1 };
									repliesArea = buildRepliesArea(
										fakeRoot,
										repliesUrlBase,
										videoDuration,
										renderComment
									);
									item.insertBefore(repliesArea, holder);
								}

								var list = repliesArea.querySelector('.tube-comments__replies-list');
								list.hidden = false;
								list.appendChild(renderComment(result.data.comment, true));

								item.setAttribute(
									'data-replies-total',
									(parseInt(item.getAttribute('data-replies-total'), 10) || 0) + 1
								);

								textarea.value = '';
								holder.hidden = true;

								return;
							}

							var data = result.data || {};

							if ('tube_email_verification_required' === data.code) {
								config.isEmailVerified = false;
								showReplyVerificationNotice(holder, data.message);

								return;
							}

							showComposerError(form, data.message || data.error);
						});
					},
					function () {
						showReplyVerificationNotice(holder, 'Bạn cần xác thực email trước khi trả lời bình luận.');
					}
				);
			});
		}

		/**
		 * Show a persistent verification notice inside a reply composer's
		 * holder, WITHOUT touching the form/textarea (Phase 15: the reply
		 * text the visitor already typed is never destroyed, just left
		 * unsubmitted).
		 *
		 * @param {HTMLElement} holder  The reply composer's own holder element.
		 * @param {string}      message The Vietnamese notice text.
		 */
		function showReplyVerificationNotice(holder, message) {
			var existing = holder.querySelector('.tube-comments__verify-notice');

			if (existing) {
				existing.remove();
			}

			holder.appendChild(buildVerificationNotice(message));
		}

		function handleMenuAction(action, commentId, itemEl) {
			if ('report' === action) {
				requireVerifiedAuth(
					function () {
						openReportPicker(commentId);
					},
					function () {
						openVerificationDialog('Bạn cần xác thực email trước khi báo cáo bình luận.');
					}
				);

				return;
			}

			if ('edit' === action) {
				requireAuth(function () {
					startEdit(commentId, itemEl);
				});

				return;
			}

			if ('delete' === action) {
				requireAuth(function () {
					if (!window.confirm('Xóa bình luận này?')) {
						return;
					}

					postJson(repliesUrlBase + commentId + '/delete', {}).then(function (result) {
						if (result.ok && result.data && result.data.success) {
							var isRoot = !itemEl.classList.contains('tube-comments__item--reply');
							var repliesTotal = parseInt(itemEl.getAttribute('data-replies-total'), 10) || 0;

							if (isRoot && repliesTotal > 0) {
								itemEl.classList.add('tube-comments__item--deleted');
								itemEl.querySelector('.tube-comments__body').outerHTML =
									'<p class="tube-comments__deleted-text">[Bình luận đã bị xóa]</p>';
							} else {
								itemEl.remove();
							}
						}
					});
				});
			}
		}

		function startEdit(commentId, itemEl) {
			var contentEl = itemEl.querySelector('.tube-comments__content');

			if (!contentEl || contentEl.querySelector('textarea')) {
				return;
			}

			var originalHtml = contentEl.innerHTML;
			var textarea = document.createElement('textarea');
			textarea.className = 'tube-comments__edit-input';
			textarea.value = contentEl.textContent.replace(/^@\S+\s/, '');

			var save = document.createElement('button');
			save.type = 'button';
			save.className = 'tube-comments__btn-primary';
			save.textContent = 'Lưu';

			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'tube-comments__btn-ghost';
			cancel.textContent = 'Hủy';

			contentEl.innerHTML = '';
			contentEl.appendChild(textarea);
			contentEl.appendChild(save);
			contentEl.appendChild(cancel);
			textarea.focus();

			cancel.addEventListener('click', function () {
				contentEl.innerHTML = originalHtml;
			});

			save.addEventListener('click', function () {
				var content = textarea.value.trim();

				if ('' === content) {
					return;
				}

				postJson(repliesUrlBase + commentId, { content: content }).then(function (result) {
					if (result.ok && result.data && result.data.success && result.data.comment) {
						contentEl.innerHTML = '';
						contentEl.appendChild(renderContent(result.data.comment.content, videoDuration));

						var meta = itemEl.querySelector('.tube-comments__time');

						if (meta && -1 === meta.textContent.indexOf('đã chỉnh sửa')) {
							meta.textContent += ' · đã chỉnh sửa';
						}
					}
				});
			});
		}

		var reportReasons = [
			{ value: 'spam', label: 'Spam' },
			{ value: 'inappropriate', label: 'Nội dung không phù hợp' },
			{ value: 'harassment', label: 'Quấy rối' },
			{ value: 'other', label: 'Khác' },
		];

		function openReportPicker(commentId) {
			var existing = document.querySelector('.tube-comments__report-dialog');

			if (existing) {
				existing.remove();
			}

			var dialog = document.createElement('div');
			dialog.className = 'tube-comments__report-dialog';

			var panel = document.createElement('div');
			panel.className = 'tube-comments__report-panel';

			var title = document.createElement('h3');
			title.textContent = 'Báo cáo bình luận';
			panel.appendChild(title);

			reportReasons.forEach(function (reason) {
				var label = document.createElement('label');
				label.className = 'tube-comments__report-option';

				var radio = document.createElement('input');
				radio.type = 'radio';
				radio.name = 'tube-comments-report-reason';
				radio.value = reason.value;

				label.appendChild(radio);
				label.appendChild(document.createTextNode(' ' + reason.label));
				panel.appendChild(label);
			});

			var actions = document.createElement('div');
			actions.className = 'tube-comments__report-actions';

			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'tube-comments__btn-ghost';
			cancelBtn.textContent = 'Hủy';
			cancelBtn.addEventListener('click', function () {
				dialog.remove();
			});

			var submitBtn = document.createElement('button');
			submitBtn.type = 'button';
			submitBtn.className = 'tube-comments__btn-primary';
			submitBtn.textContent = 'Gửi báo cáo';
			submitBtn.addEventListener('click', function () {
				var selected = panel.querySelector('input[name="tube-comments-report-reason"]:checked');

				if (!selected) {
					return;
				}

				postJson(repliesUrlBase + commentId + '/report', { reason: selected.value }).then(function (result) {
					var data = result.data || {};

					if ('tube_email_verification_required' === data.code) {
						config.isEmailVerified = false;
						dialog.remove();
						openVerificationDialog(data.message);

						return;
					}

					dialog.remove();
				});
			});

			actions.appendChild(cancelBtn);
			actions.appendChild(submitBtn);
			panel.appendChild(actions);
			dialog.appendChild(panel);
			document.body.appendChild(dialog);

			dialog.addEventListener('click', function (event) {
				if (event.target === dialog) {
					dialog.remove();
				}
			});
		}

		loadPage(true);
	}

	/* ==================================================================
	 * "Bình luận của tôi" mount (frontend account page, Phase 9)
	 * ================================================================== */

	var mineRoot = document.querySelector('[data-tube-comments-mine]');

	if (mineRoot) {
		var mineUrl = mineRoot.getAttribute('data-mine-url');

		restFetch(mineUrl).then(function (result) {
			mineRoot.innerHTML = '';

			var items = result.ok && result.data ? result.data.items || [] : [];

			if (0 === items.length) {
				var empty = document.createElement('p');
				empty.className = 'tube-comments-mine__empty';
				empty.textContent = 'Bạn chưa có bình luận nào.';
				mineRoot.appendChild(empty);

				return;
			}

			items.forEach(function (item) {
				var row = document.createElement('div');
				row.className = 'tube-comments-mine__row';

				var link = document.createElement('a');
				link.href = (item.video && item.video.permalink) || '#';
				link.className = 'tube-comments-mine__video';
				link.textContent = (item.video && item.video.title) || '';

				var content = document.createElement('p');
				content.className = 'tube-comments-mine__content';
				content.textContent = item.is_deleted ? '[Bình luận đã bị xóa]' : item.content;

				var meta = document.createElement('span');
				meta.className = 'tube-comments-mine__meta';
				meta.textContent =
					relativeTime(item.created_at) + ('pending' === item.status ? ' · Đang chờ duyệt' : '');

				row.appendChild(link);
				row.appendChild(content);
				row.appendChild(meta);
				mineRoot.appendChild(row);
			});
		});
	}
})();
