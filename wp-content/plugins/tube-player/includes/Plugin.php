<?php
/**
 * Tube Player's bootstrap.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player;

use Tube_Player\Render\ImageHtmlRenderer;
use Tube_Player\Render\PlayerHtmlRenderer;
use Tube_Player\Render\ProfileImageHtmlRenderer;
use Tube_Player\Video\Cloudflare\CloudflareImagesUrlBuilder;
use Tube_Player\Video\Cloudflare\CloudflareStreamProvider;
use Tube_Player\Video\VideoProviderInterface;

/**
 * Tube Player's bootstrap: lazy-singleton accessors for the URL
 * provider and the two HTML renderers, read from configuration — the
 * same composition-root shape `Tube_Core\Plugin`/`Tube_Cache\Plugin`
 * already use.
 *
 * No `plugins_loaded` hook: unlike every other plugin in this project,
 * tube-player has nothing to register at boot (no CPT/taxonomy, no
 * event subscriber) — `includes/template-tags.php`'s functions call
 * `self::instance()` lazily, only when a theme template actually
 * renders a video, which is always well after every plugin has loaded
 * regardless of hook timing.
 */
final class Plugin
{
    /**
     * The shared instance, lazily created by self::instance().
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::video_provider().
     *
     * @var VideoProviderInterface|null
     */
    private ?VideoProviderInterface $video_provider = null;

    /**
     * Lazily created by self::images_url_builder() — stays null if
     * Cloudflare Images isn't configured, which is distinct from "not
     * yet resolved," hence self::$images_url_builder_resolved below.
     *
     * @var CloudflareImagesUrlBuilder|null
     */
    private ?CloudflareImagesUrlBuilder $images_url_builder = null;

    /**
     * Whether self::images_url_builder() has already resolved its
     * result (which may legitimately be null) — see that method.
     *
     * @var bool
     */
    private bool $images_url_builder_resolved = false;

    /**
     * Lazily created by self::image_renderer().
     *
     * @var ImageHtmlRenderer|null
     */
    private ?ImageHtmlRenderer $image_renderer = null;

    /**
     * Lazily created by self::player_renderer().
     *
     * @var PlayerHtmlRenderer|null
     */
    private ?PlayerHtmlRenderer $player_renderer = null;

    /**
     * Lazily created by self::profile_image_renderer().
     *
     * @var ProfileImageHtmlRenderer|null
     */
    private ?ProfileImageHtmlRenderer $profile_image_renderer = null;

    /**
     * Private: use self::instance() instead.
     */
    private function __construct()
    {
    }

    /**
     * The shared Plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The video URL provider, per ARCHITECTURE.md §19.5.
     *
     * Public so a future consumer (tube-admin's Phase 10 preview UI,
     * tube-seo's Phase 8 structured data) can resolve a playback/
     * thumbnail URL without going through the HTML renderers.
     */
    public function video_provider(): VideoProviderInterface
    {
        if (null === $this->video_provider) {
            $signing_key_id  = self::config_string('TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_ID');
            $signing_key_pem = self::decoded_signing_key_pem();

            $this->video_provider = new CloudflareStreamProvider(
                self::config_string('TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE'),
                '' === $signing_key_id ? null : $signing_key_id,
                '' === $signing_key_pem ? null : $signing_key_pem,
                self::config_int('TUBE_PLAYER_SIGNED_URL_TTL_SECONDS', 3600)
            );
        }

        return $this->video_provider;
    }

    /**
     * The Cloudflare Images URL builder, or null if no account hash is
     * configured. Since ADR-0001, this is used only by
     * `self::profile_image_renderer()` (actor/studio photos, Phase 13 —
     * explicitly out of scope for ADR-0001 and left on Cloudflare
     * Images unchanged); video poster/OG-image overrides no longer use
     * Cloudflare Images at all (see `ImageHtmlRenderer::resolve_urls()`).
     */
    public function images_url_builder(): ?CloudflareImagesUrlBuilder
    {
        if (! $this->images_url_builder_resolved) {
            $account_hash = self::config_string('TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH');

            $this->images_url_builder          = '' === $account_hash
                ? null
                : new CloudflareImagesUrlBuilder($account_hash);
            $this->images_url_builder_resolved = true;
        }

        return $this->images_url_builder;
    }

    /**
     * The poster/thumbnail `<img>` renderer, per ARCHITECTURE.md §5/§8.
     *
     * Public: `includes/template-tags.php`'s `tube_player_get_image_html()` is a thin wrapper around this.
     * Since ADR-0001 (refined by its 2026-08-25 addendum), the poster/
     * OG-image is resolved via WordPress's own attachment functions
     * (inside `ImageHtmlRenderer` itself) with no other source — it needs
     * no collaborator, unlike `self::player_renderer()` below, which
     * still needs `self::video_provider()` for the embed URL.
     * `self::images_url_builder()` exists only for
     * `self::profile_image_renderer()`'s unrelated actor/studio-photo
     * use, which ADR-0001 explicitly left unchanged.
     */
    public function image_renderer(): ImageHtmlRenderer
    {
        if (null === $this->image_renderer) {
            $this->image_renderer = new ImageHtmlRenderer();
        }

        return $this->image_renderer;
    }

    /**
     * The click-to-load player renderer, per ARCHITECTURE.md §12 Phase 6.
     *
     * Public: `includes/template-tags.php`'s `tube_player_get_embed_html()` is a thin wrapper around this.
     */
    public function player_renderer(): PlayerHtmlRenderer
    {
        if (null === $this->player_renderer) {
            $this->player_renderer = new PlayerHtmlRenderer($this->video_provider(), $this->image_renderer());
        }

        return $this->player_renderer;
    }

    /**
     * The actor/studio photo `<img>` renderer, per Phase 13.
     *
     * Public: `includes/template-tags.php`'s `tube_player_get_profile_image_html()` is a thin wrapper around this.
     */
    public function profile_image_renderer(): ProfileImageHtmlRenderer
    {
        if (null === $this->profile_image_renderer) {
            $this->profile_image_renderer = new ProfileImageHtmlRenderer($this->images_url_builder());
        }

        return $this->profile_image_renderer;
    }

    /**
     * Decode the base64-encoded Stream signing key PEM constant, if configured.
     *
     * Stored base64-encoded in configuration (`.env`/`docker-compose.yml`)
     * because a multi-line PEM string cannot round-trip cleanly through a
     * single-line `define()` call the way every other constant in this
     * project's config does.
     */
    private static function decoded_signing_key_pem(): string
    {
        $encoded = self::config_string('TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_PEM_BASE64');

        if ('' === $encoded) {
            return '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding this project's own config value, not obfuscation; see this method's docblock for why it's stored base64-encoded.
        $decoded = base64_decode($encoded, true);

        return false === $decoded ? '' : $decoded;
    }

    /**
     * Read a defined string constant, or '' if it isn't defined or isn't a string.
     *
     * @param string $constant_name The constant's name.
     */
    private static function config_string(string $constant_name): string
    {
        if (! defined($constant_name)) {
            return '';
        }

        $value = constant($constant_name);

        return is_string($value) ? $value : '';
    }

    /**
     * Read a defined int constant, or $fallback if it isn't defined or isn't an int.
     *
     * @param string $constant_name The constant's name.
     * @param int    $fallback      The value to use if the constant isn't defined or isn't an int.
     */
    private static function config_int(string $constant_name, int $fallback): int
    {
        if (! defined($constant_name)) {
            return $fallback;
        }

        $value = constant($constant_name);

        return is_int($value) ? $value : $fallback;
    }
}
