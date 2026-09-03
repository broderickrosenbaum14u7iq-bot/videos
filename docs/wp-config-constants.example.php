<?php
/**
 * Example project-specific wp-config.php constants for a new site.
 *
 * Every value below is an empty placeholder. Copy the block you need
 * OUT of this file into the new site's own wp-config.php (between the
 * database/salts section and "That's all, stop editing!"), filling in
 * real values there -- never commit this file with real values, and
 * never commit a site's actual wp-config.php at all.
 *
 * See docs/DEPLOY_NEW_SITE.md for what each constant does, whether it's
 * required, and whether it's safe to share the same value across
 * multiple sites or must be unique per site.
 *
 * @package Tube_Core
 */

// -- Redis (tube-cache) -------------------------------------------------
// TUBE_CACHE_REDIS_DB must be a value unique to this site if it shares a
// Redis server with any other site on the same host.
define('TUBE_CACHE_REDIS_HOST', '');
define('TUBE_CACHE_REDIS_PORT', 6379);
define('TUBE_CACHE_REDIS_DB', 0);

// -- Redis (tube-core view counters; also used by tube-members/tube-comments rate limiting) --
// TUBE_CORE_REDIS_DB must be a value unique to this site if it shares a
// Redis server with any other site on the same host.
define('TUBE_CORE_REDIS_HOST', '');
define('TUBE_CORE_REDIS_PORT', 6379);
define('TUBE_CORE_REDIS_DB', 0);

// -- Cloudflare R2 / direct-MP4 video source -----------------------------
// Only needed if this site serves R2 videos. May be the same values as
// another site's if intentionally sharing one R2 bucket/Worker.
define('TUBE_CORE_R2_MEDIA_BASE_URL', '');
define('TUBE_CORE_R2_SIGNING_SECRET', '');

// -- Cloudflare Stream video source --------------------------------------
// Only needed if this site serves Stream videos.
define('TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID', '');
define('TUBE_CORE_CLOUDFLARE_STREAM_API_TOKEN', '');
// Only meaningful for the one site actually registered as the Cloudflare
// Stream account's webhook endpoint -- leave unset on every other site
// sharing that same Stream account.
define('TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET', '');
define('TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE', '');

// -- Optional: signed Cloudflare Stream playback URLs --------------------
// Leave both empty for unsigned playback (the default).
define('TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_ID', '');
define('TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_PEM_BASE64', '');
define('TUBE_PLAYER_SIGNED_URL_TTL_SECONDS', 3600);

// -- Optional: Cloudflare Images (actor/studio photos) -------------------
define('TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH', '');

// -- Optional: site visual brand ------------------------------------------
// Selects this site's assets/css/site-{brand}.css and the matching
// body.site-brand-{brand} class -- omit entirely (or leave undefined) for
// the shared default brand. Never set the same non-default brand on two
// different sites; each of the brands below is that one site's own
// distinct identity, not a reusable theme. Known values as of this
// release: 'dongtoico', 'clipbanquat', 'clipphotvn'. A brand-new site
// needing its own identity gets a new value here plus a new
// assets/css/site-{brand}.css -- see docs/DEPLOY_NEW_SITE.md.
// define('TUBE_THEME_SITE_BRAND', '');

// -- Standard WordPress production hardening -----------------------------
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
