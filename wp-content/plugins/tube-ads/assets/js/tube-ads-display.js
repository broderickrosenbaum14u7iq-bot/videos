/**
 * tube-ads display-slot placement — moves each server-rendered
 * `<template data-tube-ads-slot="...">` (echoed by Tube_Ads\Plugin::
 * maybe_output_slots() on wp_footer) to its real position in the DOM,
 * next to a stable selector the existing, unedited theme/plugin
 * templates already render. A `<template>` element is inert — nothing
 * inside it is part of the live document, and any `<script>` tag inside
 * never executes — until its `.content` is cloned into the real DOM,
 * which this script does explicitly, and re-creates `<script>` tags via
 * the standard clone-and-replace trick so provider ad-tag code (Custom
 * HTML/JS placements) actually runs.
 *
 * No framework, no build step, matching every other script in this project.
 */
(function () {
    'use strict';

    /**
     * Re-create every <script> tag inside `root` so the browser actually
     * executes it — cloning/inserting via innerHTML or a <template>'s
     * .content never runs embedded scripts on its own.
     */
    function activateScripts(root) {
        var scripts = root.querySelectorAll('script');

        Array.prototype.forEach.call(scripts, function (oldScript) {
            var newScript = document.createElement('script');

            Array.prototype.forEach.call(oldScript.attributes, function (attr) {
                newScript.setAttribute(attr.name, attr.value);
            });

            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function insertClone(template, anchor, position) {
        var fragment = template.content.cloneNode(true);
        var wrapper = document.createElement('div');
        wrapper.appendChild(fragment);
        var node = wrapper.firstElementChild || wrapper;

        anchor.insertAdjacentElement(position, node);
        // Scoped to the freshly-inserted node ONLY -- never document.body,
        // which would re-select and re-execute EVERY <script> tag already
        // on the page, including this very script's own tag, causing an
        // infinite re-execution loop (caught live during QA, 2026-08-26).
        activateScripts(node);
    }

    function placeInto(template, container, position) {
        if (!container) {
            return;
        }

        var fragment = template.content.cloneNode(true);
        var wrapper = document.createElement('div');
        wrapper.appendChild(fragment);
        var node = wrapper.firstElementChild;

        if (!node) {
            return;
        }

        if ('append' === position) {
            container.appendChild(node);
        } else {
            container.insertBefore(node, container.firstChild);
        }

        // See insertClone()'s comment above for why this is scoped to `node`, never document.body.
        activateScripts(node);
    }

    function placeAfterNthChild(template, container, n) {
        if (!container) {
            return;
        }

        var fragment = template.content.cloneNode(true);
        var wrapper = document.createElement('div');
        wrapper.appendChild(fragment);
        var node = wrapper.firstElementChild;

        if (!node) {
            return;
        }

        var children = container.children;

        if (children.length >= n) {
            container.insertBefore(node, children[n] || null);
        } else {
            container.appendChild(node);
        }

        // See insertClone()'s comment above for why this is scoped to `node`, never document.body.
        activateScripts(node);
    }

    /**
     * Where each placement's slot goes, and how — every selector here is
     * read from the existing, unedited templates (single-video.php,
     * front-page.php, footer.php, template-parts/hero.php), never
     * guessed. A placement with no matching anchor on the current page
     * (e.g. player_above's <template> only ever exists on a video page
     * anyway, per Tube_Ads\Plugin::maybe_output_slots()) simply finds
     * nothing and is a no-op.
     */
    function placeSlot(placementId, template) {
        switch (placementId) {
            case 'player_above': {
                var wrap = document.querySelector('.video-player-wrap');
                if (wrap) {
                    insertClone(template, wrap, 'beforebegin');
                }
                break;
            }
            case 'player_below': {
                var article = document.querySelector('.video-single');
                if (article) {
                    insertClone(template, article, 'afterend');
                }
                break;
            }
            case 'watch_sidebar_top':
                placeInto(template, document.querySelector('.watch-layout__sidebar'), 'prepend');
                break;
            case 'watch_sidebar_middle':
                placeInto(template, document.querySelector('.watch-layout__sidebar'), 'append');
                break;
            case 'related_grid': {
                var grid = document.querySelector('.watch-layout__related-main .video-grid');
                var position = parseInt(template.getAttribute('data-position') || '4', 10);
                placeAfterNthChild(template, grid, position);
                break;
            }
            case 'homepage_top': {
                var hero = document.querySelector('.hero');
                if (hero) {
                    insertClone(template, hero, 'afterend');
                }
                break;
            }
            case 'homepage_between_sections': {
                var firstSection = document.querySelector('.section');
                if (firstSection) {
                    insertClone(template, firstSection, 'afterend');
                }
                break;
            }
            case 'footer_banner': {
                var footer = document.querySelector('.site-footer');
                if (footer) {
                    insertClone(template, footer, 'beforebegin');
                }
                break;
            }
            default:
                break;
        }
    }

    var templates = document.querySelectorAll('template[data-tube-ads-slot]');

    Array.prototype.forEach.call(templates, function (template) {
        placeSlot(template.getAttribute('data-tube-ads-slot'), template);
        // The <template> itself is inert either way (never rendered,
        // never affects layout) -- removed purely so it doesn't linger
        // in the DOM after its content has been cloned elsewhere.
        template.remove();
    });
})();
