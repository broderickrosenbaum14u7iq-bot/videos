<?php
/**
 * Renders one display placement's markup.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Render;

use DateTimeImmutable;
use Tube_Ads\Admin\SettingsRepository;
use Tube_Ads\Placement\AdType;
use Tube_Ads\Placement\Placement;
use Tube_Ads\Placement\PlacementConfig;

/**
 * Renders one display placement's markup — the class behind
 * `tube_ads_render()` (`includes/template-tags.php`), the one reusable
 * entry point a theme calls; it never needs to know provider/type logic
 * itself. Handles: the global on/off switch, per-placement enabled
 * state, device targeting (a CSS class, not PHP user-agent sniffing —
 * 2026-08-26 §17), the direct-sold schedule window, and which of the
 * two creative types (`AdType::CustomHtml`/`AdType::ImageBanner`) to
 * build. Outputs nothing at all for a disabled/empty/out-of-schedule
 * placement — never a placeholder box.
 */
final class PlacementRenderer
{
    /**
     * Construct around the collaborator this renderer reads settings from.
     *
     * @param SettingsRepository $repository Reads the current settings.
     */
    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    /**
     * Build one placement's markup, or `''` if it shouldn't render at all right now.
     *
     * @param Placement $placement Which placement to render.
     */
    public function render(Placement $placement): string
    {
        $settings = $this->repository->get();

        if (! $settings->enabled) {
            return '';
        }

        $config = $settings->placement($placement);

        if (! $config->has_content()) {
            return '';
        }

        if (! $config->is_within_schedule(new DateTimeImmutable())) {
            return '';
        }

        if (! $config->enabled) {
            return '';
        }

        return self::wrap($placement, $config, self::creative($config));
    }

    /**
     * A placement's configured grid position (`Placement::RelatedGrid`
     * only) — the card index its creative should be inserted after,
     * client-side. Exposed separately from `self::render()`'s own
     * markup since it's DOM-placement metadata, not part of the
     * creative itself.
     *
     * @param Placement $placement Which placement to read.
     */
    public function grid_position(Placement $placement): int
    {
        return $this->repository->get()->placement($placement)->grid_position;
    }

    /**
     * Build the inner creative markup for one placement — the part that
     * differs by `AdType`, before the device-targeting wrapper.
     *
     * @param PlacementConfig $config The placement's configuration.
     */
    private static function creative(PlacementConfig $config): string
    {
        return match ($config->type) {
            // The one deliberate raw-HTML/JS output point in this whole
            // plugin: $config->html was already sanitized on the way
            // into storage (Tube_Ads\Admin\SettingsSanitizer — wp_kses_post()
            // unless the saving user had unfiltered_html), so it is
            // frontend markup exactly as an administrator configured it,
            // not user input reaching this render path unescaped.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment above; sanitized on save.
            AdType::CustomHtml => $config->html,
            AdType::ImageBanner => self::image_banner($config),
        };
    }

    /**
     * Build one image-banner creative's markup.
     *
     * @param PlacementConfig $config The placement's configuration.
     */
    private static function image_banner(PlacementConfig $config): string
    {
        $image = sprintf(
            '<img src="%s" alt="%s" loading="lazy" class="tube-ads__banner-image" />',
            esc_url($config->image_url),
            esc_attr($config->alt_text)
        );

        if ('' === trim($config->link_url)) {
            return $image;
        }

        $target = $config->open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

        return sprintf(
            '<a href="%s"%s class="tube-ads__banner-link">%s</a>',
            esc_url($config->link_url),
            $target,
            $image
        );
    }

    /**
     * Wrap one placement's creative markup in its device-targeting/identifying container.
     *
     * @param Placement       $placement The placement being rendered.
     * @param PlacementConfig $config    Its configuration.
     * @param string          $creative  The already-built inner markup.
     */
    private static function wrap(Placement $placement, PlacementConfig $config, string $creative): string
    {
        if ('' === $creative) {
            return '';
        }

        $classes = ['tube-ads-slot', 'tube-ads-slot--' . str_replace('_', '-', $placement->value)];

        if ($config->desktop_enabled && ! $config->mobile_enabled) {
            $classes[] = 'tube-ads-desktop-only';
        } elseif ($config->mobile_enabled && ! $config->desktop_enabled) {
            $classes[] = 'tube-ads-mobile-only';
        }

        return sprintf(
            '<div class="%s" data-tube-ads-placement="%s">%s</div>',
            esc_attr(implode(' ', $classes)),
            esc_attr($placement->value),
            $creative
        );
    }
}
