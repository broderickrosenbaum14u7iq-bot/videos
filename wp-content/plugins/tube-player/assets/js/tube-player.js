/**
 * tube-player click-to-load: swaps a poster for a real iframe on
 * activation. No framework, no build step, no REST calls -- the embed
 * URL is already server-rendered into data-embed-url (ARCHITECTURE.md
 * §12 Phase 6). One delegated document-level listener handles every
 * player on the page instead of one listener per instance.
 */
(function () {
    'use strict';

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
