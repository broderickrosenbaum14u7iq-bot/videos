<?php
/**
 * Integration tests for PosterImageMetaBox::save() against real tube-core infrastructure.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Integration\Video;

use PHPUnit\Framework\TestCase;
use Tube_Admin\Video\PosterImageMetaBox;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;

/**
 * Exercises `PosterImageMetaBox::save()` — the handler behind the
 * "Poster Image" meta box on WordPress's native `Videos → Add New`/
 * `Edit Video` screen (ADR-0001's 2026-08-25 addendum) — against a real
 * `wp_tube_video_metadata` table via `VideoMetadataRepository`, the same
 * canonical storage `StreamUidMetaBoxIntegrationTest` already exercises
 * for the sibling meta box. `$_POST`/nonce are populated directly rather
 * than via a real HTTP request, the same pattern that test uses.
 */
final class PosterImageMetaBoxIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Attachment posts created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_attachment_ids = [];

    /**
     * An administrator user, so `save()`'s `current_user_can( 'edit_post', ... )`
     * check passes the same way it does for a real logged-in editor.
     *
     * @var int
     */
    private int $user_id;

    /**
     * Create and log in as a real administrator.
     */
    protected function setUp(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $user_id = wp_insert_user(
            [
                'user_login' => 'poster-image-test-' . uniqid('', true),
                'user_pass'  => wp_generate_password(),
                'role'       => 'administrator',
            ]
        );

        self::assertIsInt($user_id);
        $this->user_id = $user_id;

        wp_set_current_user($user_id);
    }

    /**
     * Delete every video post/metadata row/attachment created by the test, log out, reset superglobals.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table.
            $wpdb->delete($wpdb->prefix . 'tube_video_metadata', ['video_id' => $video_id], ['%d']);
            wp_delete_post($video_id, true);
        }

        foreach ($this->created_attachment_ids as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        $this->created_video_ids      = [];
        $this->created_attachment_ids = [];
        $_POST                        = [];

        wp_set_current_user(0);
        wp_delete_user($this->user_id);
    }

    /**
     * A video with no metadata row yet (no Stream UID ever set) is a
     * silent no-op — there is nothing to attach a poster to.
     */
    public function test_save_does_nothing_for_a_video_with_no_metadata_row(): void
    {
        $video_id      = $this->create_video();
        $attachment_id = $this->create_test_attachment();

        $this->submit($attachment_id);
        PosterImageMetaBox::save($video_id);

        self::assertNull(Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id));
    }

    /**
     * A video that already has metadata (a Stream UID set) gets its poster_image_id written.
     */
    public function test_save_sets_the_poster_for_a_video_that_already_has_metadata(): void
    {
        $video_id      = $this->create_video_with_metadata();
        $attachment_id = $this->create_test_attachment();

        $this->submit($attachment_id);
        PosterImageMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame($attachment_id, $metadata->poster_image_id);
    }

    /**
     * Changing the poster on a video that already has one updates it, and leaves og_image_id untouched.
     */
    public function test_save_changes_an_existing_poster_without_touching_og_image(): void
    {
        $video_id = $this->create_video_with_metadata();
        $og_id    = $this->create_test_attachment();

        Tube_Core_Plugin::instance()->video_metadata_repository()->update_images($video_id, null, $og_id);

        $first  = $this->create_test_attachment();
        $second = $this->create_test_attachment();

        $this->submit($first);
        PosterImageMetaBox::save($video_id);

        $this->submit($second);
        PosterImageMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame($second, $metadata->poster_image_id);
        self::assertSame($og_id, $metadata->og_image_id);
    }

    /**
     * Submitting an empty value clears the poster (the picker's "Remove" button).
     */
    public function test_save_clears_the_poster_when_submitted_empty(): void
    {
        $video_id      = $this->create_video_with_metadata();
        $attachment_id = $this->create_test_attachment();

        $this->submit($attachment_id);
        PosterImageMetaBox::save($video_id);

        $this->submit(null);
        PosterImageMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertNull($metadata->poster_image_id);
    }

    /**
     * A submitted attachment ID that isn't a real image attachment is
     * rejected — the previously-stored poster (if any) is kept, not
     * silently replaced by a broken reference.
     */
    public function test_save_rejects_an_invalid_attachment_id_and_keeps_the_previous_poster(): void
    {
        $video_id      = $this->create_video_with_metadata();
        $attachment_id = $this->create_test_attachment();

        $this->submit($attachment_id);
        PosterImageMetaBox::save($video_id);

        $this->submit(999999999);
        PosterImageMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame($attachment_id, $metadata->poster_image_id);
    }

    /**
     * A missing/invalid nonce is a silent no-op.
     */
    public function test_save_does_nothing_without_a_valid_nonce(): void
    {
        $video_id      = $this->create_video_with_metadata();
        $attachment_id = $this->create_test_attachment();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- deliberately testing the no-nonce case itself.
        $_POST = ['poster_image_id' => (string) $attachment_id];

        PosterImageMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertNull($metadata->poster_image_id);
    }

    /**
     * Populate $_POST as the meta box's own form would, with a real, valid nonce.
     *
     * @param int|null $attachment_id The attachment ID to submit, or null to submit an empty value.
     */
    private function submit(?int $attachment_id): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this *is* the code constructing the nonce the real form field would carry.
        $_POST = [
            'tube_admin_video_poster_image_nonce' => wp_create_nonce('tube_admin_video_poster_image'),
            'poster_image_id'                     => null === $attachment_id ? '' : (string) $attachment_id,
        ];
    }

    /**
     * Create a real draft video post, tracked for teardown.
     */
    private function create_video(): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Poster Image Meta Box Integration Test Video',
                'post_status' => 'draft',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }

    /**
     * Create a real draft video post with a real wp_tube_video_metadata row already attached.
     */
    private function create_video_with_metadata(): int
    {
        $video_id = $this->create_video();

        Tube_Core_Plugin::instance()->video_metadata_repository()->create(
            $video_id,
            'poster-meta-box-test-uid-' . uniqid('', true),
            CfStreamStatus::Pending
        );

        return $video_id;
    }

    /**
     * Create a real `attachment` post backed by a real 1x1 PNG file with
     * real generated `_wp_attachment_metadata` — the same shape a genuine
     * `wp.media()` upload/selection produces, and what
     * `wp_attachment_is_image()` (self::resolve_poster_image_id()'s own
     * validation) checks against.
     */
    private function create_test_attachment(): int
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload_dir = wp_upload_dir();
        $filename   = 'tube-admin-poster-metabox-test-' . uniqid('', true) . '.png';
        $file_path  = trailingslashit($upload_dir['path']) . $filename;

        // A real, minimal 1x1 transparent PNG.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a fixed, hardcoded test-fixture image, not obfuscation.
        $png_bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png_bytes);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture writing to this run's own uploads dir, not a runtime request path.
        file_put_contents($file_path, $png_bytes);

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => 'image/png',
                'post_title'     => 'Poster Image Meta Box Integration Test Attachment',
                'post_status'    => 'inherit',
            ],
            $file_path
        );

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));

        $this->created_attachment_ids[] = $attachment_id;

        return $attachment_id;
    }
}
