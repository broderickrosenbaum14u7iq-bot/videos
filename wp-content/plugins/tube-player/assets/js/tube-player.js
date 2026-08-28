/**
 * tube-player click-to-load: swaps a poster for a real iframe on
 * activation, and records one view. No framework, no build step -- both
 * the embed URL and the view-recording URL are already server-rendered
 * into data-embed-url/data-view-url (ARCHITECTURE.md §12 Phase 6; view
 * recording added 2026-08-25), so this script never constructs a URL
 * itself. One delegated document-level listener handles every player on
 * the page instead of one listener per instance.
 */
(function () {
    'use strict';

    function recordView(player) {
        var viewUrl = player.getAttribute('data-view-url');

        if (!viewUrl) {
            return;
        }

        // Fire-and-forget: the caller doesn't need the response, and
        // `keepalive` lets the request finish even if the visitor
        // navigates away immediately after clicking play. Never blocks
        // or delays activate() itself -- swapping in the iframe below
        // does not wait on this.
        fetch(viewUrl, { method: 'POST', keepalive: true }).catch(function () {
            // Recording a view must never surface an error to the
            // visitor or block playback -- a failed/dropped request here
            // simply means this one view isn't counted, the same
            // fail-open posture the server-side counter itself already
            // documents (RedisViewCounter).
        });
    }

    function activate(player) {
        if (player.hasAttribute('data-tube-player-active')) {
            return;
        }

        var embedUrl = player.getAttribute('data-embed-url');

        if (!embedUrl) {
            return;
        }

        var iframe = document.createElement('iframe');
        iframe.src = embedUrl;
        iframe.title = player.getAttribute('data-title') || '';
        iframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;

        player.textContent = '';
        player.appendChild(iframe);
        player.setAttribute('data-tube-player-active', '');

        iframe.focus();

        // Reuses the same data-tube-player-active guard above -- this
        // function already returns early on a second activation attempt
        // for the same player instance, so recordView() below is
        // reached at most once per real play action, not a second,
        // independent dedup mechanism.
        recordView(player);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.tube-player__play');

        if (!button) {
            return;
        }

        var player = button.closest('[data-tube-player]');

        if (player) {
            activate(player);
        }
    });
})();
