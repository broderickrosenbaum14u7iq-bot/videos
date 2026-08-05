/**
 * Redirects the header search form to /search/{query}/ on submit —
 * a plain <form method="get"> can't target a pretty-permalink path
 * segment, so this is the one small piece of client-side glue this
 * theme needs. No framework, no build step.
 */
(function () {
	'use strict';

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
})();
