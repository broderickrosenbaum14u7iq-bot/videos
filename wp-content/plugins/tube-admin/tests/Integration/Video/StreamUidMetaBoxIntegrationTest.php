<?php
/**
 * Integration tests for StreamUidMetaBox::save() against real tube-core infrastructure.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Integration\Video;

use PHPUnit\Framework\TestCase;
use Tube_Admin\Video\StreamUidMetaBox;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\VideoSource;

/**
 * Exercises `StreamUidMetaBox::save()` — the handler behind the
 * Cloudflare Stream UID field on WordPress's native `Videos → Add New`/
 * `Edit Video` screen — against a real `wp_tube_video_metadata` table via
 * `VideoMetadataRepository`, the same canonical storage
 * `Tube_Admin\Video\VideoDetailsScreen` already uses. `$_POST`/nonce are
 * populated directly rather than via a real HTTP request, the same
 * pattern this project's other `admin-post.php`-handler tests use
 * (see `Tube_Admin\Tests\Integration\Assignment\AssignmentServiceIntegrationTest`
 * for the class-level precedent of integration-only, no unit fake, for
 * WordPress-admin-coupled write logic).
 */
final class StreamUidMetaBoxIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * An administrator user, so `save()`'s `current_user_can( 'edit_post', ... )`
     * check passes the same way it does for a real logged-in editor —
     * without this, every test would silently no-op at that check, the
     * same `wp_set_current_user()` precedent
     * `Tube_Core\Tests\Integration\WatchHistory\WatchHistoryIntegrationTest`
     * already established for capability-gated code.
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
                'user_login' => 'stream-uid-test-' . uniqid('', true),
                'user_pass'  => wp_generate_password(),
                'role'       => 'administrator',
            ]
        );

        self::assertIsInt($user_id);
        $this->user_id = $user_id;

        wp_set_current_user($user_id);
    }

    /**
     * Delete every video post/metadata row created by the test, log out, and reset superglobals.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table.
            $wpdb->delete($wpdb->prefix . 'tube_video_metadata', ['video_id' => $video_id], ['%d']);
            wp_delete_post($video_id, true);
            delete_transient('tube_admin_stream_uid_pending_' . $video_id);
        }

        $this->created_video_ids = [];
        $_POST                   = [];

        wp_set_current_user(0);
        wp_delete_user($this->user_id);
    }

    /**
     * An empty submitted UID is a silent no-op — no metadata row is created.
     */
    public function test_save_does_nothing_for_an_empty_submitted_uid(): void
    {
        $video_id = $this->create_video();
        $this->submit('');

        StreamUidMetaBox::save($video_id);

        self::assertNull(Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id));
    }

    /**
     * Saving a new UID also attempts a live Cloudflare Stream sync
     * (`Tube_Core\Plugin::instance()->stream_metadata_syncer()`) — this
     * environment has no real Cloudflare Stream account configured
     * (`TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID`/`API_TOKEN` both empty,
     * see `docker-compose.yml`), so `CloudflareStreamDetailsFetcher`
     * fails closed and the sync attempt is a real, deterministic no-op
     * in this environment. Confirms that attempt genuinely doesn't
     * throw/fatal and doesn't fabricate a duration, rather than being
     * skipped in code entirely — the actual "Cloudflare unavailable"
     * case `StreamMetadataSyncerTest`'s own unit tests exercise against a
     * fake, verified here against the real, currently-unconfigured
     * composition root.
     */
    public function test_save_does_not_fatal_and_leaves_duration_null_when_cloudflare_is_unconfigured(): void
    {
        $video_id = $this->create_video();
        $uid      = 'meta-box-sync-test-uid-' . uniqid('', true);
        $this->submit($uid);

        StreamUidMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame($uid, $metadata->cf_stream_uid);
        self::assertNull($metadata->duration_seconds);
        self::assertSame(CfStreamStatus::Pending, $metadata->cf_status);
    }

    /**
     * A video with no metadata row yet gets one created on first save.
     */
    public function test_save_creates_metadata_for_a_video_with_none_yet(): void
    {
        $video_id = $this->create_video();
        $uid      = 'meta-box-test-uid-' . uniqid('', true);
        $this->submit($uid);

        StreamUidMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame($uid, $metadata->cf_stream_uid);
    }

    /**
     * A changed UID on a video that already has metadata updates the existing row.
     */
    public function test_save_updates_the_uid_for_a_video_that_already_has_metadata(): void
    {
        $video_id = $this->create_video();
        $original = 'meta-box-original-' . uniqid('', true);
        $updated  = 'meta-box-updated-' . uniqid('', true);

        $this->submit($original);
        StreamUidMetaBox::save($video_id);

        $this->submit($updated);
        StreamUidMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame($updated, $metadata->cf_stream_uid);
    }

    /**
     * A UID already owned by a different video is rejected: the second
     * video gets no metadata row, and the first video's UID is untouched.
     */
    public function test_save_rejects_a_uid_already_owned_by_a_different_video(): void
    {
        $first_id  = $this->create_video();
        $second_id = $this->create_video();
        $shared    = 'meta-box-shared-' . uniqid('', true);

        $this->submit($shared);
        StreamUidMetaBox::save($first_id);

        $this->submit($shared);
        StreamUidMetaBox::save($second_id);

        self::assertNull(Tube_Core_Plugin::instance()->video_metadata_repository()->find($second_id));
        self::assertSame(
            $first_id,
            Tube_Core_Plugin::instance()->video_metadata_repository()->find_video_id_by_stream_uid($shared)
        );
    }

    /**
     * A missing/invalid nonce is a silent no-op — no metadata is written
     * regardless of what $_POST otherwise contains.
     */
    public function test_save_does_nothing_without_a_valid_nonce(): void
    {
        $video_id = $this->create_video();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- deliberately testing the no-nonce case itself.
        $_POST = ['tube_admin_cf_stream_uid' => 'should-not-be-saved'];

        StreamUidMetaBox::save($video_id);

        self::assertNull(Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id));
    }

    /**
     * The real R2 object this project's own R2 support was built/tested
     * against (a genuine Vietnamese filename with combining diacritics)
     * — used read-only here, exactly as this feature's own testing
     * instructions direct; never modified/deleted.
     */
    private const REAL_R2_URL = 'https://media.nangcuctvc.com/'
        . 'EM%20Tu%CC%81%20Nhie%CC%82n%20Qua%CC%89ng%20Ninh%20nangcuc.mp4';

    /**
     * A real, reachable R2 URL submitted as the source resolves to
     * `R2Mp4`, `Ready` (a live HEAD request against the real object
     * succeeds), the admin-entered duration is stored, and no Cloudflare
     * Stream UID is ever written — this feature's own "no stale
     * Pending/Processing causing 'Video đang được xử lý'" requirement
     * for this source made concrete.
     *
     * Requires `TUBE_CORE_R2_MEDIA_BASE_URL` to be configured to
     * `https://media.nangcuctvc.com` in this environment (see
     * `docker-compose.yml`) and real outbound internet access — there is
     * no credential-gated "unconfigured" state to fall back to for R2
     * readiness the way Stream's sync has (`R2VideoValidator` always
     * makes a live HEAD request), so this test's own value depends on
     * that real reachability genuinely being exercised.
     */
    public function test_save_r2_with_a_real_reachable_url_is_ready_with_duration(): void
    {
        $video_id = $this->create_video();
        $this->submit_r2(self::REAL_R2_URL, 130);

        StreamUidMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame(VideoSource::R2Mp4, $metadata->source);
        self::assertNull($metadata->cf_stream_uid);
        self::assertSame(CfStreamStatus::Ready, $metadata->cf_status);
        self::assertSame(130, $metadata->duration_seconds);
    }

    /**
     * A bare object key (no scheme/host) is accepted identically to a
     * full URL — both normalize to the same canonical key.
     */
    public function test_save_r2_accepts_a_bare_object_key(): void
    {
        $video_id = $this->create_video();
        $this->submit_r2(
            'EM%20Tu%CC%81%20Nhie%CC%82n%20Qua%CC%89ng%20Ninh%20nangcuc.mp4',
            null
        );

        StreamUidMetaBox::save($video_id);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        self::assertNotNull($metadata);
        self::assertSame(VideoSource::R2Mp4, $metadata->source);
        self::assertSame(CfStreamStatus::Ready, $metadata->cf_status);
        self::assertNull($metadata->duration_seconds);
    }

    /**
     * A URL whose host doesn't match the configured R2 domain is
     * rejected outright: no metadata row is created at all — the R2
     * counterpart to the Stream duplicate-UID rejection, and the concrete
     * proof of this feature's own SSRF-protection requirement.
     */
    public function test_save_r2_rejects_a_url_on_an_unconfigured_host(): void
    {
        $video_id = $this->create_video();
        $this->submit_r2('https://evil.example.com/clip.mp4', null);

        StreamUidMetaBox::save($video_id);

        self::assertNull(Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id));
    }

    /**
     * An object key already owned by a different video is rejected: the
     * second video gets no metadata row, and the first video's key is
     * untouched — the R2 counterpart to
     * {@see self::test_save_rejects_a_uid_already_owned_by_a_different_video()}.
     */
    public function test_save_r2_rejects_a_key_already_owned_by_a_different_video(): void
    {
        $first_id  = $this->create_video();
        $second_id = $this->create_video();
        $shared    = 'videos/shared-' . uniqid('', true) . '.mp4';

        Tube_Core_Plugin::instance()->video_metadata_repository()->create_r2(
            $first_id,
            $shared,
            CfStreamStatus::Ready
        );

        $this->submit_r2($shared, null);
        StreamUidMetaBox::save($second_id);

        self::assertNull(Tube_Core_Plugin::instance()->video_metadata_repository()->find($second_id));
        self::assertSame(
            $first_id,
            Tube_Core_Plugin::instance()->video_metadata_repository()->find_video_id_by_r2_object_key($shared)
        );
    }

    /**
     * Populate $_POST as the meta box's own Cloudflare Stream form field would, with a real, valid nonce.
     *
     * @param string $cf_stream_uid The Stream UID value to submit.
     */
    private function submit(string $cf_stream_uid): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this *is* the code constructing the nonce the real form field would carry.
        $_POST = [
            'tube_admin_video_stream_uid_nonce' => wp_create_nonce('tube_admin_video_stream_uid'),
            'tube_admin_video_source'           => 'cloudflare_stream',
            'tube_admin_cf_stream_uid'          => $cf_stream_uid,
        ];
    }

    /**
     * Populate $_POST as the meta box's own R2 form fields would, with a real, valid nonce.
     *
     * @param string   $r2_source The R2 URL/object key value to submit.
     * @param int|null $duration  The admin-entered duration to submit, if any.
     */
    private function submit_r2(string $r2_source, ?int $duration): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this *is* the code constructing the nonce the real form field would carry.
        $_POST = [
            'tube_admin_video_stream_uid_nonce' => wp_create_nonce('tube_admin_video_stream_uid'),
            'tube_admin_video_source'           => 'r2_mp4',
            'tube_admin_r2_source'              => $r2_source,
            'tube_admin_r2_duration_seconds'    => null === $duration ? '' : (string) $duration,
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
                'post_title'  => 'Stream UID Meta Box Integration Test Video',
                'post_status' => 'draft',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }
}
