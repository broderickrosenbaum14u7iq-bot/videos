/**
 * Wires every .tube-admin-media-picker on the current page (poster/OG-image
 * pickers, views/media-picker.php) to WordPress's native media modal
 * (ADR-0001). Shared by Tube_Admin\Video\VideoDetailsScreen's own edit
 * screen and Tube_Admin\Video\PosterImageMetaBox's meta box on the native
 * Videos → Add New/Edit Video screen — extracted here once a second real
 * caller existed (previously inline in views/edit.php, when there was
 * only one).
 */
(function () {
    'use strict';

    // Tracks whether any picker's selection has changed since page load
    // without the form having been submitted yet — a real gap found
    // during manual testing: selecting/uploading an image only stages it
    // in a hidden field, and it is easy to mistake that step alone for
    // "saved." markDirty() surfaces an unmissable inline notice per
    // picker and warns before leaving the page with the change unsaved,
    // rather than letting it silently vanish.
    var hasUnsavedChanges = false;

    function markDirty(picker) {
        hasUnsavedChanges = true;

        var badge = picker.querySelector('.tube-admin-media-picker__unsaved');

        if (badge) {
            badge.style.display = '';
        }
    }

    function wire(picker) {
        var select = picker.querySelector('.tube-admin-media-picker__select');
        var remove = picker.querySelector('.tube-admin-media-picker__remove');
        var value = picker.querySelector('.tube-admin-media-picker__value');
        var preview = picker.querySelector('.tube-admin-media-picker__preview');
        var frame = null;

        select.addEventListener('click', function (event) {
            event.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: select.textContent,
                button: { text: select.textContent },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();

                value.value = attachment.id;
                preview.innerHTML = '';

                var img = document.createElement('img');
                img.className = 'tube-admin-media-picker__preview-image';
                img.style.maxWidth = '200px';
                img.style.height = 'auto';
                img.style.display = 'block';
                img.alt = '';
                img.src = (attachment.sizes && attachment.sizes.medium)
                    ? attachment.sizes.medium.url
                    : attachment.url;
                preview.appendChild(img);

                remove.style.display = '';
                markDirty(picker);
            });

            frame.open();
        });

        remove.addEventListener('click', function (event) {
            event.preventDefault();
            value.value = '';
            preview.innerHTML = '';
            remove.style.display = 'none';
            markDirty(picker);
        });
    }

    var pickers = document.querySelectorAll('.tube-admin-media-picker');
    pickers.forEach(wire);

    var form = pickers.length > 0 ? pickers[0].closest('form') : null;

    if (form) {
        form.addEventListener('submit', function () {
            hasUnsavedChanges = false;
        });
    }

    window.addEventListener('beforeunload', function (event) {
        if (!hasUnsavedChanges) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });
}());
