<?php
/**
 * Partial: one WordPress Media Library poster/OG-image picker field (ADR-0001).
 *
 * Included twice from `views/edit.php` (once per image field) with
 * $tube_admin_field_name/$tube_admin_field_value already in scope. Every
 * local variable this file itself defines is `tube_admin_`-prefixed, per
 * `tube-theme`'s own PrefixAllGlobals convention for top-level template
 * files, extended here to plugin view partials for the same reason.
 *
 * @package Tube_Admin
 *
 * @var string   $tube_admin_field_name  The `$_POST` field name (`poster_image_id` or `og_image_id`).
 * @var int|null $tube_admin_field_value The currently-stored WordPress attachment ID, if any.
 *
 * $tube_admin_unsaved_label (optional, not declared via @var since a caller may not set it at all —
 * unlike the two required vars above, its presence/type genuinely isn't guaranteed): the "not saved
 * yet" notice text, since the save action differs by caller (VideoDetailsScreen's own "Save Video
 * Details" button vs. the native Videos → Add New/Edit Video screen's "Publish"/"Update"). Defaults
 * to the former when unset or not a string.
 */

declare(strict_types=1);

$tube_admin_picker_id     = 'tube-admin-media-picker-' . $tube_admin_field_name;
$tube_admin_unsaved_label = isset($tube_admin_unsaved_label) && is_string($tube_admin_unsaved_label)
    ? $tube_admin_unsaved_label
    : __('Not saved yet — click "Save Video Details" below.', 'tube-admin');
$tube_admin_preview_url   = null === $tube_admin_field_value
    ? false
    : wp_get_attachment_image_url($tube_admin_field_value, 'medium');
?>
<div class="tube-admin-media-picker" id="<?php echo esc_attr($tube_admin_picker_id); ?>">
    <div class="tube-admin-media-picker__preview" style="margin-bottom:8px;">
        <?php if (is_string($tube_admin_preview_url)) : ?>
            <img
                class="tube-admin-media-picker__preview-image"
                src="<?php echo esc_url($tube_admin_preview_url); ?>"
                style="max-width:200px;height:auto;display:block;"
                alt=""
            />
        <?php endif; ?>
    </div>
    <input
        type="hidden"
        class="tube-admin-media-picker__value"
        name="<?php echo esc_attr($tube_admin_field_name); ?>"
        value="<?php echo esc_attr(null === $tube_admin_field_value ? '' : strval($tube_admin_field_value)); ?>"
    />
    <button type="button" class="button tube-admin-media-picker__select">
        <?php esc_html_e('Select/Upload Image', 'tube-admin'); ?>
    </button>
    <button
        type="button"
        class="button tube-admin-media-picker__remove"
        <?php echo null === $tube_admin_field_value ? 'style="display:none;"' : ''; ?>
    >
        <?php esc_html_e('Remove', 'tube-admin'); ?>
    </button>
    <strong class="tube-admin-media-picker__unsaved" style="display:none;color:#b32d2e;margin-left:8px;">
        <?php echo esc_html($tube_admin_unsaved_label); ?>
    </strong>
</div>
