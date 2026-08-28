<?php
/**
 * Footer administration — Appearance → Customize → Footer.
 *
 * No `ABSPATH` guard here — `functions.php` already exits before
 * `require_once`-ing this file, the same posture `inc/template-functions.php`'s
 * own docblock already documents for this theme.
 *
 * 2026-08-27: the footer used to be entirely hardcoded PHP with no admin
 * surface at all. This is deliberately the native WordPress Customizer
 * (`theme_mods`, `WP_Customize_Manager`) rather than a bespoke settings
 * page or a page-builder plugin — exactly the "lightweight native
 * solution" this task asked for: built into WordPress core, needs no
 * new dependency, and gives a free live-preview for every control. Link
 * COLUMNS themselves are real WordPress Navigation Menus
 * ({@see register_nav_menus()} below) — Customizer only ever controls
 * each column's own title text and a handful of brand/copyright/
 * visibility fields, never raw link markup.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

/**
 * Register this theme's three footer menu locations. Called from
 * `functions.php`'s own existing `after_setup_theme` closure (the same
 * hook `add_theme_support()` already runs on there), not a second
 * `after_setup_theme` registration.
 */
function tube_theme_register_footer_menus(): void
{
    register_nav_menus(
        [
            'footer_menu_1' => __('Footer Column 1', 'tube-theme'),
            'footer_menu_2' => __('Footer Column 2', 'tube-theme'),
            'footer_menu_3' => __('Footer Column 3', 'tube-theme'),
        ]
    );
}

/**
 * A checkbox control's sanitized value — WordPress core's own
 * `WP_Customize_Manager` convention for a checkbox (the same shape
 * `sanitize_checkbox` example in the Customizer docs uses): present/
 * truthy becomes `true`, anything else `false`. Never stored as a
 * string, so callers can rely on a genuine PHP bool.
 *
 * @param mixed $value The raw submitted control value.
 */
function tube_theme_sanitize_checkbox($value): bool
{
    return (bool) $value;
}

/**
 * A footer social/link URL field's sanitized value — the same "require
 * an explicit http/https scheme via an allow-list, not a blocklist"
 * posture already established for `Tube_Members\Admin\SettingsSanitizer::
 * advertiser_url()` (2026-08-27 email-verification task): `esc_url_raw()`
 * alone does not reject every dangerous scheme (a stray protocol added
 * to WordPress's own allowed-protocols list elsewhere would slip
 * through), so the scheme is checked directly first. An empty value is
 * always valid — it just means "no link," never rendered.
 *
 * @param mixed $value The raw submitted control value.
 */
function tube_theme_sanitize_footer_url($value): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = trim($value);

    if ('' === $value) {
        return '';
    }

    $scheme = wp_parse_url($value, PHP_URL_SCHEME);

    if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
        return '';
    }

    return esc_url_raw($value);
}

/**
 * A footer text field's sanitized value (brand text, column titles,
 * copyright template) — plain single-line text, never HTML.
 *
 * @param mixed $value The raw submitted control value.
 */
function tube_theme_sanitize_footer_text($value): string
{
    return sanitize_text_field(is_scalar($value) ? (string) $value : '');
}

/**
 * The footer description field's sanitized value — the one multi-line
 * field in this settings group; still plain text (no HTML/shortcodes),
 * just with newlines preserved.
 *
 * @param mixed $value The raw submitted control value.
 */
function tube_theme_sanitize_footer_textarea($value): string
{
    return sanitize_textarea_field(is_scalar($value) ? (string) $value : '');
}

/**
 * The explicit allow-list for a footer column's custom rich-text content
 * (2026-08-28) — shared by the sanitize callback below (save time) and
 * `footer.php` (render time), so both call sites agree on exactly the
 * same set of tags no matter how the stored theme_mod was written.
 * Intentionally excludes `target`/`rel`/`class`/`style` attributes on
 * `<a>` — the task's own Part B2 asks only for text, paragraphs, line
 * breaks, links, bold, italic, and optionally lists.
 *
 * @return array<string, array<string, bool>>
 */
function tube_theme_footer_content_allowed_html(): array
{
    return [
        'a'      => [
            'href'  => true,
            'title' => true,
        ],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'br'     => [],
        'p'      => [],
        'ul'     => [],
        'li'     => [],
    ];
}

/**
 * A footer column content field's sanitized value — `wp_kses()` against
 * the explicit allow-list above (never `wp_kses_post()`'s much larger
 * default set). `wp_kses()` already strips `javascript:`/unknown-scheme
 * `href` values on its own (it checks every URL against
 * `wp_allowed_protocols`), so no separate scheme check is needed here
 * the way `tube_theme_sanitize_footer_url()` above needs one for a bare
 * URL field.
 *
 * @param mixed $value The raw submitted control value.
 */
function tube_theme_sanitize_footer_content($value): string
{
    $value = is_scalar($value) ? (string) $value : '';

    return trim(wp_kses($value, tube_theme_footer_content_allowed_html()));
}

/**
 * `customize_register` callback: the single "Footer" section, every
 * control it needs, per Part 9's own admin-UX outline (Brand,
 * Description, three column titles, Social links, Copyright,
 * visibility toggles) — deliberately one flat section, not a panel of
 * several, since every control here is a genuine single footer concern.
 *
 * @param WP_Customize_Manager $wp_customize The Customizer instance core hands this callback.
 */
function tube_theme_customize_register(WP_Customize_Manager $wp_customize): void
{
    $wp_customize->add_section(
        'tube_theme_footer',
        [
            'title'    => __('Footer', 'tube-theme'),
            'priority' => 160,
        ]
    );

    // ---- Brand -------------------------------------------------------

    $wp_customize->add_setting(
        'tube_theme_footer_show_brand',
        [
            'default'           => '1',
            'sanitize_callback' => 'tube_theme_sanitize_checkbox',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_show_brand',
        [
            'section' => 'tube_theme_footer',
            'label'   => __('Show brand/logo', 'tube-theme'),
            'type'    => 'checkbox',
        ]
    );

    $wp_customize->add_setting(
        'tube_theme_footer_logo',
        [
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        new WP_Customize_Image_Control(
            $wp_customize,
            'tube_theme_footer_logo',
            [
                'section'     => 'tube_theme_footer',
                'label'       => __('Footer Logo', 'tube-theme'),
                'description' => __('Optional. If set, replaces the brand text below with this image.', 'tube-theme'),
            ]
        )
    );

    $wp_customize->add_setting(
        'tube_theme_footer_brand_text',
        [
            'default'           => get_bloginfo('name'),
            'sanitize_callback' => 'tube_theme_sanitize_footer_text',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_brand_text',
        [
            'section'     => 'tube_theme_footer',
            'label'       => __('Footer Brand Text', 'tube-theme'),
            'description' => __('Used only when no logo image is set above.', 'tube-theme'),
            'type'        => 'text',
        ]
    );

    // ---- Description ---------------------------------------------------

    $wp_customize->add_setting(
        'tube_theme_footer_show_description',
        [
            'default'           => '1',
            'sanitize_callback' => 'tube_theme_sanitize_checkbox',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_show_description',
        [
            'section' => 'tube_theme_footer',
            'label'   => __('Show description', 'tube-theme'),
            'type'    => 'checkbox',
        ]
    );

    $wp_customize->add_setting(
        'tube_theme_footer_description',
        [
            'default'           => '',
            'sanitize_callback' => 'tube_theme_sanitize_footer_textarea',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_description',
        [
            'section'     => 'tube_theme_footer',
            'label'       => __('Footer Description', 'tube-theme'),
            'description' => __(
                'Optional short line under the brand, e.g. "Kho video cập nhật mới mỗi ngày."',
                'tube-theme'
            ),
            'type'        => 'textarea',
        ]
    );

    // ---- Menu columns ----------------------------------------------

    $wp_customize->add_setting(
        'tube_theme_footer_show_menus',
        [
            'default'           => '1',
            'sanitize_callback' => 'tube_theme_sanitize_checkbox',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_show_menus',
        [
            'section' => 'tube_theme_footer',
            'label'   => __('Show footer menus', 'tube-theme'),
            'type'    => 'checkbox',
        ]
    );

    $tube_theme_column_defaults = [
        1 => __('Danh mục', 'tube-theme'),
        2 => __('Khám phá', 'tube-theme'),
        3 => __('Thông tin', 'tube-theme'),
    ];

    foreach ($tube_theme_column_defaults as $tube_theme_column_number => $tube_theme_column_default) {
        $tube_theme_setting_id = 'tube_theme_footer_column_' . $tube_theme_column_number . '_title';

        $wp_customize->add_setting(
            $tube_theme_setting_id,
            [
                'default'           => $tube_theme_column_default,
                'sanitize_callback' => 'tube_theme_sanitize_footer_text',
                'transport'         => 'refresh',
            ]
        );
        $wp_customize->add_control(
            $tube_theme_setting_id,
            [
                'section'     => 'tube_theme_footer',
                'label'       => sprintf(
                    /* translators: %d: column number (1-3). */
                    __('Column %d Title', 'tube-theme'),
                    $tube_theme_column_number
                ),
                'description' => sprintf(
                    /* translators: %d: column number (1-3). */
                    __('Links themselves are managed at Appearance → Menus → "Footer Column %d".', 'tube-theme'),
                    $tube_theme_column_number
                ),
                'type'        => 'text',
            ]
        );
    }

    // ---- Column 2/3 custom content (2026-08-28, Part B) ---------------
    // A native `textarea` control, not a bespoke TinyMCE/WYSIWYG bolted
    // into the Customizer's preview iframe — the "cleanest native
    // alternative" the task's own Part B1 asks for when full `wp_editor()`
    // is inappropriate here. Content is typed as the small allowed-tag
    // subset itself (`<p>`, `<a href="">`, `<strong>`, ...) — the exact
    // same convention `tube_theme_footer_description` above already uses
    // for its own one multi-line field, just with a wider (still
    // explicit) tag allow-list. See Part B4 for the content > menu >
    // fallback precedence this feeds into in `footer.php`.
    foreach ([2, 3] as $tube_theme_content_column_number) {
        $tube_theme_content_setting_id = 'tube_theme_footer_column_' . $tube_theme_content_column_number . '_content';

        $wp_customize->add_setting(
            $tube_theme_content_setting_id,
            [
                'default'           => '',
                'sanitize_callback' => 'tube_theme_sanitize_footer_content',
                'transport'         => 'refresh',
            ]
        );
        $wp_customize->add_control(
            $tube_theme_content_setting_id,
            [
                'section'     => 'tube_theme_footer',
                'label'       => sprintf(
                    /* translators: %d: column number (2-3). */
                    __('Column %d Content', 'tube-theme'),
                    $tube_theme_content_column_number
                ),
                'description' => sprintf(
                    /* translators: %d: column number (2-3). */
                    __(
                        'Nhập nội dung, văn bản hoặc liên kết cho cột này. Nếu có nội dung ở đây, nó sẽ được ưu tiên hơn Footer Column %d Menu. Hỗ trợ: <p>, <br>, <a href="">, <strong>, <em>, <ul><li>. Ví dụ: <p><a href="https://example.com/lien-he">Liên hệ quảng cáo</a></p>',
                        'tube-theme'
                    ),
                    $tube_theme_content_column_number
                ),
                'type'        => 'textarea',
            ]
        );
    }

    // ---- Social links ------------------------------------------------

    $wp_customize->add_setting(
        'tube_theme_footer_show_social',
        [
            'default'           => '1',
            'sanitize_callback' => 'tube_theme_sanitize_checkbox',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_show_social',
        [
            'section' => 'tube_theme_footer',
            'label'   => __('Show social icons', 'tube-theme'),
            'type'    => 'checkbox',
        ]
    );

    $tube_theme_social_labels = [
        'facebook' => 'Facebook URL',
        'telegram' => 'Telegram URL',
        'twitter'  => 'X / Twitter URL',
        'youtube'  => 'YouTube URL',
    ];

    foreach ($tube_theme_social_labels as $tube_theme_social_key => $tube_theme_social_label) {
        $tube_theme_setting_id = 'tube_theme_footer_social_' . $tube_theme_social_key;

        $wp_customize->add_setting(
            $tube_theme_setting_id,
            [
                'default'           => '',
                'sanitize_callback' => 'tube_theme_sanitize_footer_url',
                'transport'         => 'refresh',
            ]
        );
        $wp_customize->add_control(
            $tube_theme_setting_id,
            [
                'section' => 'tube_theme_footer',
                'label'   => $tube_theme_social_label,
                'type'    => 'url',
            ]
        );
    }

    // ---- Copyright -----------------------------------------------------

    $wp_customize->add_setting(
        'tube_theme_footer_show_copyright',
        [
            'default'           => '1',
            'sanitize_callback' => 'tube_theme_sanitize_checkbox',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_show_copyright',
        [
            'section' => 'tube_theme_footer',
            'label'   => __('Show copyright', 'tube-theme'),
            'type'    => 'checkbox',
        ]
    );

    $wp_customize->add_setting(
        'tube_theme_footer_copyright',
        [
            'default'           => '© {year} {site_name}',
            'sanitize_callback' => 'tube_theme_sanitize_footer_text',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_footer_copyright',
        [
            'section'     => 'tube_theme_footer',
            'label'       => __('Copyright', 'tube-theme'),
            'description' => __('Supports {year} and {site_name} placeholders.', 'tube-theme'),
            'type'        => 'text',
        ]
    );
}
add_action('customize_register', 'tube_theme_customize_register');

/**
 * A header-brand-mode field's sanitized value — explicit allow-list
 * (`text`/`logo`), same posture `tube_theme_sanitize_header_brand_mode()`'s
 * sibling sanitizers in this file already use for every other field:
 * an unrecognized/tampered value always falls back to `text`, never
 * passes through as-is.
 *
 * @param mixed $value The raw submitted control value.
 */
function tube_theme_sanitize_header_brand_mode($value): string
{
    return in_array($value, ['text', 'logo'], true) ? $value : 'text';
}

/**
 * `customize_register` callback: one radio control, added to WordPress
 * core's own built-in `title_tagline` (Site Identity) section rather
 * than a new custom section — the task this implements explicitly asks
 * for Logo / Site Title / Tagline / Site Icon / this mode switch to all
 * live together under Appearance → Customize → Site Identity. A
 * separate `add_action('customize_register', ...)` call (not folded
 * into `tube_theme_customize_register()` above) because that function
 * is specifically the Footer section per this file's own docblock —
 * header branding is a different concern.
 *
 * @param WP_Customize_Manager $wp_customize The Customizer instance core hands this callback.
 */
function tube_theme_customize_register_header_brand(WP_Customize_Manager $wp_customize): void
{
    $wp_customize->add_setting(
        'tube_theme_header_brand_mode',
        [
            'default'           => 'text',
            'sanitize_callback' => 'tube_theme_sanitize_header_brand_mode',
            'transport'         => 'refresh',
        ]
    );
    $wp_customize->add_control(
        'tube_theme_header_brand_mode',
        [
            'section'     => 'title_tagline',
            'label'       => __('Hiển thị Header', 'tube-theme'),
            'description' => __(
                'Chọn "Logo" để hiển thị Logo đã tải lên ở trên thay cho Tên website. Nếu chưa có Logo, Tên website sẽ tự động hiển thị.',
                'tube-theme'
            ),
            'type'        => 'radio',
            'choices'     => [
                'text' => __('Tên website', 'tube-theme'),
                'logo' => __('Logo', 'tube-theme'),
            ],
            'priority'    => 9,
        ]
    );
}
add_action('customize_register', 'tube_theme_customize_register_header_brand');

/**
 * The footer copyright line, with `{year}`/`{site_name}` placeholders
 * replaced — the one piece of copyright logic every caller (currently
 * only `footer.php`) shares, so "how a placeholder resolves" has one
 * owner. Never requires the site owner to manually update the year.
 */
function tube_theme_footer_copyright_text(): string
{
    $template = get_theme_mod('tube_theme_footer_copyright', '© {year} {site_name}');
    $template = is_string($template) ? $template : '';

    if ('' === trim($template)) {
        $template = '© {year} {site_name}';
    }

    return strtr(
        $template,
        [
            '{year}'      => gmdate('Y'),
            '{site_name}' => get_bloginfo('name'),
        ]
    );
}
