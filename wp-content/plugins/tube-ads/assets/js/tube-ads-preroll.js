/**
 * tube-ads VAST pre-roll — gates the existing tube-player click-to-load
 * activation behind an optional video ad, then reuses that exact same
 * activation path unmodified.
 *
 * THE GATE (2026-08-26 architecture): tube-player.js (never edited by
 * this plugin) attaches one document-level, bubble-phase click listener
 * for `.tube-player__play` that calls its own private activate(), which
 * (a) sets data-tube-player-active as a same-player-instance guard and
 * (b) fires exactly one view-recording POST — see that file's own
 * comments. This script attaches a SECOND, document-level, CAPTURE-phase
 * click listener for the same selector. Capture always runs before
 * bubble, so when pre-roll should show, this handler calls
 * event.stopImmediatePropagation() to prevent tube-player.js's listener
 * from ever running for that click, shows the ad, and — on completion,
 * skip, or ANY failure — dispatches a brand-new synthetic click on the
 * exact same button. That synthetic click is NOT intercepted a second
 * time (guarded by data-tube-ads-consumed below) and flows straight
 * into tube-player.js's own unmodified listener, which calls the real
 * activate() exactly once. Because activate() is never called directly
 * by this script, and is only ever reached through that one real click
 * (original or synthetic), tube-player.js's existing exactly-once view
 * guard is untouched and still the only thing that ever fires a view.
 *
 * FAIL OPEN (2026-08-26 §10, the critical requirement): every rejection
 * path below — VAST timeout, HTTP error, malformed XML, empty response,
 * empty/exhausted wrapper chain, no supported MediaFile, a real
 * <video> playback error, the max-duration safeguard — converges on the
 * same finish() function, which always ends by replaying the click.
 * There is no code path in this file that can leave a visitor without
 * the real video ever activating.
 */
(function () {
    'use strict';

    if (!window.TubeAdsConfig || !window.TubeAdsConfig.preroll) {
        return;
    }

    var cfg = window.TubeAdsConfig.preroll;
    var debug = !!window.TubeAdsConfig.debug;

    if (!cfg.vastUrl) {
        return;
    }

    var SESSION_SHOWN_KEY = 'tube_ads_preroll_shown';
    var SESSION_LAST_KEY = 'tube_ads_preroll_last_at';

    function log(event, detail) {
        if (!debug || !window.console) {
            return;
        }

        try {
            console.log('[tube-ads]', event, detail === undefined ? '' : detail);
        } catch (ignored) {
            // Debug logging must never throw into the real ad flow.
        }
    }

    function isDesktop() {
        return window.matchMedia && window.matchMedia('(min-width: 1024px)').matches;
    }

    function deviceEligible() {
        return isDesktop() ? !!cfg.desktopEnabled : !!cfg.mobileEnabled;
    }

    /**
     * Whether the frequency cap allows showing pre-roll right now.
     * Storage-unavailable (private browsing, blocked) fails open to
     * "allow" — the same posture this whole system uses everywhere else.
     */
    function frequencyAllows() {
        try {
            if ('every_play' === cfg.frequency) {
                return true;
            }

            if ('once_per_session' === cfg.frequency) {
                return !sessionStorage.getItem(SESSION_SHOWN_KEY);
            }

            if ('every_n_minutes' === cfg.frequency) {
                var last = parseInt(sessionStorage.getItem(SESSION_LAST_KEY) || '0', 10);
                var minutes = cfg.frequencyMinutes > 0 ? cfg.frequencyMinutes : 30;

                return (Date.now() - last) > (minutes * 60000);
            }
        } catch (ignored) {
            return true;
        }

        return true;
    }

    function markShown() {
        try {
            sessionStorage.setItem(SESSION_SHOWN_KEY, '1');
            sessionStorage.setItem(SESSION_LAST_KEY, String(Date.now()));
        } catch (ignored) {
            // No persistent storage available -- pre-roll simply re-evaluates fresh next time, never blocks playback.
        }
    }

    /**
     * Fire one or more tracking beacons, best-effort, never blocking
     * playback on the response (2026-08-26 §8 — beacon/fetch behavior,
     * no CF Stream token or any credential ever included).
     */
    function fireBeacons(urls) {
        (urls || []).forEach(function (url) {
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(url);
                } else {
                    fetch(url, { method: 'GET', mode: 'no-cors', keepalive: true, credentials: 'omit' }).catch(function () {});
                }
            } catch (ignored) {
                // A tracking pixel failing must never affect ad or main-video playback.
            }
        });
    }

    function formatLabel(secondsLeft) {
        return 'Quảng cáo • ' + Math.max(0, Math.ceil(secondsLeft)) + 's';
    }

    /**
     * Whether `value` is safe to navigate the advertiser tab to: an
     * absolute, http(s) URL, and nothing else. Deliberately parsed with
     * NO base argument -- `new URL(value, someBase)` would silently
     * resolve an empty string or a relative path into an absolute URL
     * on the CURRENT origin, which is exactly the forbidden "falls back
     * to the current page" behavior this whole feature exists to rule
     * out (2026-08-27). Without a base, anything that isn't already a
     * complete absolute URL simply throws and is rejected here.
     */
    function isSafeAdvertiserUrl(value) {
        if (!value) {
            return false;
        }

        try {
            var parsed = new URL(value);

            return 'http:' === parsed.protocol || 'https:' === parsed.protocol;
        } catch (ignored) {
            return false;
        }
    }

    /**
     * The one deterministic advertiser-click destination resolver.
     *
     * PRECEDENCE (changed 2026-08-27, site-operator request): the
     * manual admin Advertiser URL (Tube Ads -> Pre-roll) now ALWAYS
     * wins when it is itself a safe absolute http(s) URL -- this site's
     * operator wants direct control over where a click goes regardless
     * of what any given VAST tag declares. The VAST ClickThrough is
     * used only as a fallback, when the admin field is empty or
     * invalid. (Previously VAST ClickThrough won; that direction is
     * intentionally reversed here, not a bug.)
     *
     * If neither is a safe absolute http(s) URL, there is no
     * destination at all -- never the current page, the
     * VAST/MediaFile/Cloudflare URL, or any other same-origin value
     * (isSafeAdvertiserUrl() parses with no base argument specifically
     * so an empty/relative/malformed value can never silently resolve
     * to this site instead of being rejected).
     *
     * This precedence is independent of VAST ClickTracking: whichever
     * destination wins here, every ClickTracking URL the ad declares
     * still fires on a creative click (see that click handler below) --
     * destination selection and measurement are separate concerns.
     */
    function resolveAdvertiserDestination(ad) {
        if (isSafeAdvertiserUrl(cfg.advertiserUrl)) {
            return cfg.advertiserUrl;
        }

        if (ad && isSafeAdvertiserUrl(ad.clickThrough)) {
            return ad.clickThrough;
        }

        return null;
    }

    /**
     * Build the ad overlay's DOM — visually separate from the main
     * Cloudflare player's own controls, compact on mobile, real
     * <button> elements for keyboard access (2026-08-26 §22).
     */
    function buildOverlay() {
        var root = document.createElement('div');
        root.className = 'tube-ads-preroll';
        root.setAttribute('role', 'group');
        root.setAttribute('aria-label', 'Quảng cáo video');

        var video = document.createElement('video');
        video.className = 'tube-ads-preroll__video';
        video.setAttribute('playsinline', '');
        // Audible by default: the ad only ever starts from the visitor's
        // own explicit click on .tube-player__play (never on page load),
        // so that click is the real user activation this playback rides
        // on. No `autoplay` attribute here -- playback is started
        // explicitly via attemptPlayback() below so its Promise can be
        // observed and a muted fallback attempted if the browser rejects
        // audible playback (2026-08-27, see attemptPlayback()).
        video.muted = false;
        video.defaultMuted = false;
        video.volume = 1;

        var badge = document.createElement('div');
        badge.className = 'tube-ads-preroll__badge';
        badge.textContent = formatLabel(0);

        var skipButton = document.createElement('button');
        skipButton.type = 'button';
        skipButton.className = 'tube-ads-preroll__skip';
        skipButton.hidden = true;

        var unmuteButton = document.createElement('button');
        unmuteButton.type = 'button';
        unmuteButton.className = 'tube-ads-preroll__unmute';
        unmuteButton.hidden = true;
        unmuteButton.textContent = '🔇 Bật tiếng';
        unmuteButton.setAttribute('aria-label', 'Bật tiếng quảng cáo');

        root.appendChild(video);
        root.appendChild(badge);
        root.appendChild(skipButton);
        root.appendChild(unmuteButton);

        return { root: root, video: video, badge: badge, skipButton: skipButton, unmuteButton: unmuteButton };
    }

    /**
     * Run one pre-roll attempt against `player` (the [data-tube-player]
     * element) / `button` (the real .tube-player__play that was
     * clicked). Always ends by calling replay(button) exactly once,
     * whatever happens.
     */
    function playPreroll(player, button) {
        var overlay = buildOverlay();
        var finished = false;
        var startedTracked = false;
        var quartilesTracked = { firstQuartile: false, midpoint: false, thirdQuartile: false };
        var completeTracked = false;
        var timers = [];
        var ad = null;

        function clearTimers() {
            timers.forEach(function (id) {
                clearTimeout(id);
            });
            timers = [];
        }

        function finish(reason) {
            if (finished) {
                return;
            }

            finished = true;
            clearTimers();
            log('preroll_finish', reason);

            if (overlay.root.parentNode) {
                overlay.root.parentNode.removeChild(overlay.root);
            }

            replay(player, button);
        }

        player.appendChild(overlay.root);

        var maxDurationTimer = setTimeout(function () {
            finish('max_duration_reached');
        }, Math.max(1, cfg.maxDurationSeconds || 60) * 1000);
        timers.push(maxDurationTimer);

        /**
         * Start (or retry) ad playback. `isRetryMuted` distinguishes the
         * first, audible attempt from the muted fallback so a second
         * rejection (muted playback itself blocked -- effectively never
         * happens in practice, since browsers always allow muted
         * autoplay, but this is the same fail-open posture as everywhere
         * else in this file) doesn't try to mute-and-retry forever.
         *
         * The audible attempt is the real user-activation handoff this
         * task exists for: the click on .tube-player__play IS the
         * media-triggering user gesture, and it is still "sticky" on the
         * frame by the time this runs (a real, trusted click's
         * activation does not expire while the VAST fetch/parse above
         * was in flight) -- so calling .play() unmuted here is exactly
         * the same gesture-linked call a simple synchronous player would
         * make. If the browser rejects it anyway (e.g. a low Media
         * Engagement Index, or a non-trusted/synthetic click), the
         * fallback below mutes, shows a visible unmute control, and
         * retries -- it never traps the visitor with silent, ungrantable
         * audio and no way to turn it on (2026-08-27).
         */
        function attemptPlayback(isRetryMuted) {
            var playPromise;

            try {
                playPromise = overlay.video.play();
            } catch (syncPlayError) {
                playPromise = null;
            }

            if (!playPromise || typeof playPromise.catch !== 'function') {
                return;
            }

            playPromise.catch(function () {
                if (!isRetryMuted) {
                    log('vast_audible_play_rejected');
                    overlay.video.muted = true;
                    overlay.unmuteButton.hidden = false;
                    attemptPlayback(true);
                    return;
                }

                log('vast_muted_play_rejected');
                finish('autoplay_blocked');
            });
        }

        overlay.skipButton.addEventListener('click', function () {
            // Race guard (skip click vs. the video's own 'ended' event
            // landing in the same event-loop tick): `finished` is set
            // synchronously, as the very first thing finish() does, so
            // whichever of these two listeners the browser happens to run
            // first wins the terminal outcome and the other becomes a
            // no-op below -- never both a skip AND a complete beacon for
            // one playback (2026-08-27).
            if (finished) {
                return;
            }

            log('vast_skip');
            fireBeacons(ad && ad.tracking && ad.tracking.skip);
            finish('skipped');
        });

        overlay.unmuteButton.addEventListener('click', function () {
            overlay.video.muted = false;
            overlay.video.volume = 1;
            overlay.unmuteButton.hidden = true;
        });

        overlay.video.addEventListener('error', function () {
            log('vast_media_error');
            finish('media_error');
        });

        overlay.video.addEventListener('timeupdate', function () {
            if (!ad || !overlay.video.duration || !isFinite(overlay.video.duration)) {
                return;
            }

            var current = overlay.video.currentTime;
            var duration = overlay.video.duration;
            var remaining = duration - current;

            overlay.badge.textContent = formatLabel(remaining);

            if (!startedTracked && current > 0) {
                startedTracked = true;
                log('vast_started');
                fireBeacons(ad.tracking.start);
            }

            var ratio = current / duration;

            if (!quartilesTracked.firstQuartile && ratio >= 0.25) {
                quartilesTracked.firstQuartile = true;
                fireBeacons(ad.tracking.firstQuartile);
            }

            if (!quartilesTracked.midpoint && ratio >= 0.5) {
                quartilesTracked.midpoint = true;
                fireBeacons(ad.tracking.midpoint);
            }

            if (!quartilesTracked.thirdQuartile && ratio >= 0.75) {
                quartilesTracked.thirdQuartile = true;
                fireBeacons(ad.tracking.thirdQuartile);
            }

            // Precedence rule (2026-08-27, "Configurable Skip After"):
            // this site's own Skip After setting (cfg.skipAfterSeconds,
            // Tube Ads -> Pre-roll admin screen) is the single source of
            // truth for when THIS player's Skip button appears --
            // ad.skipOffsetSeconds (VAST's own <Linear skipoffset="...">,
            // still parsed by tube-ads-vast.js) is deliberately NOT
            // consulted here. This system's VAST tags are the site's own
            // (its own ad-network integration/local test fixtures, not a
            // third-party contract this player must defer to), so a
            // second, VAST-driven skip threshold would only ever create
            // two conflicting skip systems for one button -- the operator
            // sets one number in one place and it always applies,
            // regardless of what any given ad tag happens to declare.
            // skipAfterSeconds === 0 is its own sentinel, not "skippable
            // immediately": it means a mandatory, non-skippable ad, so
            // the button must never appear at all in that case.
            if (cfg.skipEnabled && cfg.skipAfterSeconds > 0) {
                var skipAfter = cfg.skipAfterSeconds;

                if (current >= skipAfter) {
                    // Bug found live during QA (2026-08-26): this branch
                    // updated the label/hidden state but never cleared
                    // `disabled`, so the button *looked* skippable
                    // ("Bỏ qua quảng cáo") but a real click on it did
                    // nothing -- disabled form controls never dispatch a
                    // click event, in any browser.
                    overlay.skipButton.hidden = false;
                    overlay.skipButton.disabled = false;
                    overlay.skipButton.textContent = 'Bỏ qua quảng cáo';
                    overlay.skipButton.setAttribute('aria-label', 'Bỏ qua quảng cáo');
                } else {
                    overlay.skipButton.hidden = false;
                    overlay.skipButton.disabled = true;
                    var secondsLeft = Math.ceil(skipAfter - current);
                    overlay.skipButton.textContent = 'Bỏ qua sau ' + secondsLeft;
                    overlay.skipButton.setAttribute('aria-label', 'Bỏ qua quảng cáo sau ' + secondsLeft + ' giây');
                }
            }
        });

        overlay.video.addEventListener('ended', function () {
            // See the skip button's click listener above for why this
            // guard must come first, not just the pre-existing
            // completeTracked flag: both guard against the SAME race,
            // this one against 'ended' running after a skip already won.
            if (finished) {
                return;
            }

            if (!completeTracked) {
                completeTracked = true;
                log('vast_complete');
                fireBeacons(ad && ad.tracking && ad.tracking.complete);
            }

            finish('completed');
        });

        overlay.video.addEventListener('click', function (event) {
            // Scoped to this one handler only (never a global/document
            // stop) -- keeps this click from being treated as a second,
            // unrelated click anywhere else this event might otherwise
            // bubble to. It does not affect Skip/Unmute: those are
            // sibling elements of the video, not ancestors, so a click
            // on either of them was never reaching this listener in the
            // first place -- DOM bubbling only travels up an element's
            // own ancestor chain, never sideways to siblings.
            event.stopPropagation();

            var destination = resolveAdvertiserDestination(ad);

            if (!destination) {
                return;
            }

            // window.open() FIRST, synchronously, still inside this
            // real click's own call stack -- fired before any tracking
            // work so the new tab is unambiguously still riding the
            // user's own activation. Waiting on fireBeacons() (or
            // anything else async) first risks the browser treating the
            // eventual window.open() as an unrequested popup instead of
            // a direct response to the click (2026-08-27).
            window.open(destination, '_blank', 'noopener,noreferrer');

            log('vast_click', destination);
            fireBeacons(ad && ad.clickTracking);
            fireBeacons(ad && ad.tracking && ad.tracking.click);

            // Clicking the creative is a navigation/measurement action,
            // not a terminal outcome -- it must never itself skip,
            // complete, or otherwise touch finish()/the ad's playback
            // state. The ad keeps playing in this (still-open) tab
            // exactly as if the click hadn't happened.
        });

        // No initial skip-button state to set here beyond buildOverlay()'s
        // own `hidden = true` default: skipAfterSeconds === 0 (mandatory
        // ad) must leave the button hidden for the ad's entire duration,
        // never shown as immediately skippable -- see the timeupdate
        // handler's own comment above for the full precedence rule.

        log('vast_request', cfg.vastUrl);

        window.TubeAdsVast.resolveAd(cfg.vastUrl, Math.max(1, cfg.timeoutSeconds || 8) * 1000)
            .then(function (resolvedAd) {
                if (finished) {
                    return;
                }

                var mediaFile = window.TubeAdsVast.pickMediaFile(resolvedAd.mediaFiles);

                if (!mediaFile) {
                    throw new Error('no_supported_media');
                }

                ad = resolvedAd;
                fireBeacons(ad.impressions);
                overlay.video.src = mediaFile.url;
                attemptPlayback(false);
            })
            .catch(function (error) {
                log('vast_no_fill_or_error', error && error.message);
                finish('error');
            });
    }

    /**
     * Dispatch a fresh, real click on the exact button the visitor
     * clicked — tube-player.js's own bubble-phase listener receives it
     * unmodified and runs the real activate(). `data-tube-ads-replaying`
     * is set for the duration of this synchronous dispatch only, so the
     * click handler below can tell "this is my own replay" apart from
     * "a real, extra click arrived while an ad is already in progress"
     * — see that handler's own comment for why that distinction is the
     * fix for a real bug found live during QA.
     */
    function replay(player, button) {
        player.setAttribute('data-tube-ads-replaying', '');
        button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
        player.removeAttribute('data-tube-ads-replaying');
    }

    document.addEventListener(
        'click',
        function (event) {
            var button = event.target.closest ? event.target.closest('.tube-player__play') : null;

            if (!button) {
                return;
            }

            var player = button.closest('[data-tube-player]');

            if (!player || player.hasAttribute('data-tube-player-active')) {
                return;
            }

            if (player.hasAttribute('data-tube-ads-replaying')) {
                // This dispatch IS replay()'s own synthetic click -- let
                // it flow through to tube-player.js untouched.
                return;
            }

            if (player.hasAttribute('data-tube-ads-consumed')) {
                // An ad is already in progress (or already finished and
                // mid-replay) for this player. Bug found live during QA
                // (2026-08-26): this branch used to just `return` here,
                // which let a rapid second/third click on the same
                // button — landing before the first click's VAST fetch
                // even resolved — fall through to tube-player.js's own
                // bubble-phase listener and call the REAL activate()
                // immediately, which clears the player's children
                // (including the ad overlay the first click had just
                // appended) and starts the real video while the ad kept
                // running invisibly in the background. Main-video view
                // counting stayed correct only because tube-player.js's
                // OWN data-tube-player-active guard blocked the *next*
                // activation attempt — the ad itself was still silently
                // bypassed. Stopping propagation here, exactly like the
                // real gate below does, closes that window: every extra
                // click while an ad is in progress is swallowed, not
                // just ignored.
                event.stopImmediatePropagation();
                event.preventDefault();
                return;
            }

            if (!deviceEligible() || !frequencyAllows()) {
                return;
            }

            player.setAttribute('data-tube-ads-consumed', '');
            event.stopImmediatePropagation();
            event.preventDefault();
            markShown();
            playPreroll(player, button);
        },
        true
    );
})();
