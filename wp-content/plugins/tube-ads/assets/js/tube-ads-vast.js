/**
 * tube-ads VAST 3/4 fetch + parse — pure, provider-neutral. Handles
 * InLine/Wrapper (following wrapper chains up to a maximum depth),
 * Impression (accumulated across the whole chain, per the VAST spec),
 * Linear/Duration/MediaFiles/TrackingEvents/VideoClicks
 * (ClickThrough + ClickTracking), and skipoffset. Does not attempt every obscure VAST extension
 * (AdParameters, non-linear ads, companion ads) — this project only
 * needs a working pre-roll, not full VAST compliance.
 *
 * No framework, no build step, matching every other script in this
 * project (see tube-player.js). Exposes one object, window.TubeAdsVast,
 * with resolveAd()/pickMediaFile() — assets/js/tube-ads-preroll.js is
 * the one consumer.
 */
(function () {
    'use strict';

    var MAX_WRAPPER_DEPTH = 5;

    /**
     * Fetch a URL as text, aborting after timeoutMs. Rejects on a
     * non-2xx status, a network error, CORS failure, or timeout — every
     * one of those is a real, anticipated failure mode
     * (assets/js/tube-ads-preroll.js's caller always treats a rejected
     * promise the same way: fail open to the real video).
     */
    function fetchText(url, timeoutMs) {
        if (!window.fetch || !window.AbortController) {
            return Promise.reject(new Error('fetch_unsupported'));
        }

        var controller = new AbortController();
        var timedOut = false;
        var timer = setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, timeoutMs);

        return fetch(url, { signal: controller.signal, credentials: 'omit' })
            .then(function (response) {
                clearTimeout(timer);

                if (!response.ok) {
                    throw new Error('http_' + response.status);
                }

                return response.text();
            })
            .catch(function (error) {
                clearTimeout(timer);

                if (timedOut) {
                    throw new Error('timeout');
                }

                throw error;
            });
    }

    /**
     * Parse an XML string, rejecting on malformed/empty input rather
     * than returning a document with no useful content.
     */
    function parseXml(text) {
        if (!text || !text.trim()) {
            throw new Error('empty_response');
        }

        var doc = new DOMParser().parseFromString(text, 'text/xml');

        if (doc.getElementsByTagName('parsererror').length > 0) {
            throw new Error('parse_error');
        }

        return doc;
    }

    function textOf(node) {
        return node ? (node.textContent || '').trim() : '';
    }

    function firstChild(parent, tagName) {
        return parent ? parent.getElementsByTagName(tagName)[0] || null : null;
    }

    function allChildren(parent, tagName) {
        return parent ? Array.prototype.slice.call(parent.getElementsByTagName(tagName)) : [];
    }

    /**
     * Parse a VAST duration string (HH:MM:SS(.mmm)) into whole seconds, or null if it doesn't match.
     */
    function parseVastDuration(value) {
        if (!value) {
            return null;
        }

        var match = /^(\d+):(\d{2}):(\d{2})(?:\.\d+)?$/.exec(value.trim());

        if (!match) {
            return null;
        }

        return (parseInt(match[1], 10) * 3600) + (parseInt(match[2], 10) * 60) + parseInt(match[3], 10);
    }

    /**
     * Pick the best playable MediaFile for this browser — video/mp4
     * first (2026-08-26 §7 explicit priority), then any other type the
     * browser reports it can play, skipping anything it can't. Returns
     * null when nothing is playable (a real, anticipated no-fill case).
     */
    function pickMediaFile(mediaFiles) {
        if (!mediaFiles || 0 === mediaFiles.length) {
            return null;
        }

        var probe = document.createElement('video');
        var ranked = mediaFiles.slice().sort(function (a, b) {
            return rank(a.type) - rank(b.type);
        });

        function rank(type) {
            if (/mp4/i.test(type)) {
                return 0;
            }

            if (/webm/i.test(type)) {
                return 1;
            }

            return 2;
        }

        for (var i = 0; i < ranked.length; i++) {
            var candidate = ranked[i];

            if (!candidate.url) {
                continue;
            }

            if (!candidate.type || probe.canPlayType(candidate.type)) {
                return candidate;
            }
        }

        return null;
    }

    /**
     * Extract the one Linear creative this pre-roll cares about from a real InLine element.
     */
    function extractInline(inlineEl) {
        var linear = inlineEl.querySelector ? inlineEl.querySelector('Creatives > Creative > Linear') : null;

        if (!linear) {
            var creatives = firstChild(inlineEl, 'Creatives');
            var creativeEls = allChildren(creatives, 'Creative');

            for (var i = 0; i < creativeEls.length && !linear; i++) {
                linear = firstChild(creativeEls[i], 'Linear');
            }
        }

        if (!linear) {
            return null;
        }

        var mediaFilesParent = firstChild(linear, 'MediaFiles');
        var mediaFiles = allChildren(mediaFilesParent, 'MediaFile').map(function (mediaFileEl) {
            return {
                url: textOf(mediaFileEl),
                type: mediaFileEl.getAttribute('type') || '',
            };
        }).filter(function (mediaFile) {
            return '' !== mediaFile.url;
        });

        var tracking = {};
        var trackingParent = firstChild(linear, 'TrackingEvents');

        allChildren(trackingParent, 'Tracking').forEach(function (trackingEl) {
            var event = trackingEl.getAttribute('event');
            var url = textOf(trackingEl);

            if (!event || !url) {
                return;
            }

            tracking[event] = tracking[event] || [];
            tracking[event].push(url);
        });

        // VideoClicks: ClickThrough (the advertiser's own landing page —
        // navigation destination) and ClickTracking (one or more
        // measurement-only beacon URLs, fired alongside navigation,
        // never used AS a destination) are deliberately kept separate
        // fields here — see assets/js/tube-ads-preroll.js's own creative
        // click handler for why conflating them would be wrong.
        var videoClicks = firstChild(linear, 'VideoClicks');
        var clickThrough = textOf(firstChild(videoClicks, 'ClickThrough'));
        var clickTracking = allChildren(videoClicks, 'ClickTracking').map(textOf).filter(Boolean);

        return {
            duration: parseVastDuration(textOf(firstChild(linear, 'Duration'))),
            mediaFiles: mediaFiles,
            tracking: tracking,
            clickThrough: clickThrough,
            clickTracking: clickTracking,
            skipOffsetSeconds: parseVastDuration(linear.getAttribute('skipoffset') || ''),
        };
    }

    /**
     * Resolve one VAST tag URL into a normalized ad object, following
     * Wrapper chains up to maxDepth. Rejects with a descriptive Error on
     * any failure (malformed XML, no Ad, empty Wrapper, exhausted
     * depth, no InLine content) — every rejection is an anticipated,
     * fail-open case for the caller, never left unhandled.
     */
    function resolveAd(url, maxDepth, timeoutMs, accumulatedImpressions) {
        accumulatedImpressions = accumulatedImpressions || [];

        if (maxDepth < 0) {
            return Promise.reject(new Error('max_wrapper_depth_exceeded'));
        }

        return fetchText(url, timeoutMs).then(function (text) {
            var doc = parseXml(text);
            var ad = doc.querySelector ? doc.querySelector('VAST > Ad') : firstChild(doc.documentElement, 'Ad');

            if (!ad) {
                throw new Error('no_ad');
            }

            var inline = firstChild(ad, 'InLine');

            if (inline) {
                var impressions = allChildren(inline, 'Impression').map(textOf).filter(Boolean);
                var result = extractInline(inline);

                if (!result) {
                    throw new Error('no_linear_creative');
                }

                result.impressions = accumulatedImpressions.concat(impressions);

                return result;
            }

            var wrapper = firstChild(ad, 'Wrapper');

            if (wrapper) {
                var nextUrl = textOf(firstChild(wrapper, 'VASTAdTagURI'));

                if (!nextUrl) {
                    throw new Error('empty_wrapper');
                }

                var wrapperImpressions = allChildren(wrapper, 'Impression').map(textOf).filter(Boolean);

                return resolveAd(nextUrl, maxDepth - 1, timeoutMs, accumulatedImpressions.concat(wrapperImpressions));
            }

            throw new Error('unsupported_ad_type');
        });
    }

    window.TubeAdsVast = {
        resolveAd: function (url, timeoutMs) {
            return resolveAd(url, MAX_WRAPPER_DEPTH, timeoutMs, []);
        },
        pickMediaFile: pickMediaFile,
    };
})();
