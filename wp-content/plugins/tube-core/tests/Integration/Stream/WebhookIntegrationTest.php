<?php
/**
 * Integration tests for the Cloudflare Stream webhook, end-to-end
 * through the real REST API.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Stream;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Events\WordPressHookBus;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\Repositories\VideoMetadataRepository;
use WP_REST_Request;

/**
 * Dispatches real HTTP-shaped requests at `POST
 * /tube/v1/webhooks/cloudflare-stream` through WordPress's actual REST
 * server (`rest_do_request()`), against a real video post and real
 * wp_tube_video_metadata row — no fakes, no mocked signature
 * verification. Requires `TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET` to
 * be configured (it is, in the staging docker-compose stack); a test is
 * added below that fails loudly instead of silently skipping if it isn't.
 */
final class WebhookIntegrationTest extends TestCase
{
    /**
     * The real metadata repository, read directly to assert on outcomes.
     *
     * @var VideoMetadataRepository
     */
    private VideoMetadataRepository $metadata_repository;

    /**
     * Video post IDs created during a test, deleted in tearDown().
     *
     * @var list<int>
     */
    private array $created_post_ids = [];

    /**
     * Build a real metadata repository for each test.
     */
    protected function setUp(): void
    {
        $this->metadata_repository = new VideoMetadataRepository();
        $this->created_post_ids    = [];

        self::assertTrue(
            defined('TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET')
            && is_string(TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET)
            && '' !== TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET,
            'TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET must be configured for this suite to mean anything.'
        );
    }

    /**
     * Delete every video post created during the test.
     *
     * @throws RuntimeException If a query template is malformed (a bug in this method, not in any argument).
     */
    protected function tearDown(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.
        $table = $wpdb->prefix . 'tube_video_metadata';

        foreach ($this->created_post_ids as $post_id) {
            $sql = $wpdb->prepare('DELETE FROM %i WHERE video_id = %d', $table, $post_id);

            if (null === $sql) {
                throw new RuntimeException(
                    'wpdb::prepare() returned null for the metadata cleanup query in ' . self::class . '.'
                );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
            $wpdb->query($sql);

            wp_delete_post($post_id, true);
        }
    }

    /**
     * Create a draft video + wp_tube_video_metadata row seeded as Pending.
     *
     * @return array{video_id: int, cf_stream_uid: string}
     */
    private function seed_pending_video(): array
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Webhook Integration Test Video',
                'post_status' => 'draft',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_post_ids[] = $video_id;

        $cf_stream_uid = 'uid-' . uniqid('', true);
        $this->metadata_repository->create($video_id, $cf_stream_uid, CfStreamStatus::Pending);

        return [
            'video_id'      => $video_id,
            'cf_stream_uid' => $cf_stream_uid,
        ];
    }

    /**
     * Compute a real `Webhook-Signature` header value the same way
     * WebhookSignatureVerifier itself verifies one.
     *
     * @param string $body The exact raw request body being signed.
     */
    private function sign(string $body): string
    {
        $secret = defined('TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET')
            ? TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET
            : '';

        // setUp() already asserts this constant is a non-empty string for
        // every test in this class; re-checked here (rather than widening
        // this method's own contract) purely so PHPStan can see it, the
        // same is_string() narrowing WebhookController::check_signature()
        // itself uses for the same constant.
        self::assertIsString($secret);

        $time = time();
        $sig  = hash_hmac('sha256', $time . '.' . $body, $secret);

        return "time={$time},sig1={$sig}";
    }

    /**
     * Dispatch a signed POST to the webhook route through the real REST server.
     *
     * @param string      $body       The raw JSON body.
     * @param string|null $signature  Override header value, or null to sign $body correctly.
     */
    private function dispatch_webhook(string $body, ?string $signature = null): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/tube/v1/webhooks/cloudflare-stream');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('webhook-signature', $signature ?? $this->sign($body));
        $request->set_body($body);

        return rest_do_request($request);
    }

    /**
     * A validly-signed webhook reporting "ready" updates the stored
     * status/duration and publishes the still-draft video.
     */
    public function test_valid_webhook_updates_status_and_publishes_on_ready(): void
    {
        $seed = $this->seed_pending_video();
        $body = wp_json_encode(
            [
                'uid'      => $seed['cf_stream_uid'],
                'status'   => 'ready',
                'duration' => 125,
            ]
        );
        self::assertIsString($body);

        $response = $this->dispatch_webhook($body);

        self::assertSame(200, $response->get_status());
        self::assertSame(['success' => true], $response->get_data());

        self::assertSame(CfStreamStatus::Ready, $this->metadata_repository->status_for($seed['video_id']));

        $post = get_post($seed['video_id']);
        self::assertNotNull($post);
        self::assertSame('publish', $post->post_status);
    }

    /**
     * A redelivered webhook reporting the same status again, with no new
     * duration, is a safe no-op: no second VIDEO_STREAM_STATUS_CHANGED
     * dispatch, no error, still a 200.
     */
    public function test_duplicate_webhook_delivery_is_a_safe_no_op(): void
    {
        $seed = $this->seed_pending_video();

        $captured = [];
        (new Dispatcher(new WordPressHookBus()))->listen(
            EventCatalog::VIDEO_STREAM_STATUS_CHANGED,
            static function (array $payload) use (&$captured): void {
                $captured[] = $payload;
            }
        );

        $first_body = wp_json_encode(
            [
                'uid'      => $seed['cf_stream_uid'],
                'status'   => 'ready',
                'duration' => 90,
            ]
        );
        self::assertIsString($first_body);

        $first_response = $this->dispatch_webhook($first_body);
        self::assertSame(200, $first_response->get_status());
        self::assertCount(1, $captured);

        // Redelivery: same status, no duration this time -> the true no-op branch.
        $second_body = wp_json_encode(
            [
                'uid'    => $seed['cf_stream_uid'],
                'status' => 'ready',
            ]
        );
        self::assertIsString($second_body);

        $second_response = $this->dispatch_webhook($second_body);
        self::assertSame(200, $second_response->get_status());
        self::assertSame(['success' => true], $second_response->get_data());

        // No new dispatch for the redelivery.
        self::assertCount(1, $captured);
        self::assertSame(CfStreamStatus::Ready, $this->metadata_repository->status_for($seed['video_id']));
    }

    /**
     * A tampered/invalid signature is rejected with 401, and the stored
     * status is left untouched.
     */
    public function test_invalid_signature_is_rejected(): void
    {
        $seed = $this->seed_pending_video();
        $body = wp_json_encode(
            [
                'uid'    => $seed['cf_stream_uid'],
                'status' => 'ready',
            ]
        );
        self::assertIsString($body);

        $response = $this->dispatch_webhook($body, 'time=' . time() . ',sig1=' . str_repeat('0', 64));

        self::assertSame(401, $response->get_status());
        self::assertSame(CfStreamStatus::Pending, $this->metadata_repository->status_for($seed['video_id']));
    }

    /**
     * A validly-signed webhook for an unrecognized Cloudflare Stream UID
     * is rejected with 404, not silently accepted.
     */
    public function test_unrecognized_uid_returns_404(): void
    {
        $body = wp_json_encode(
            [
                'uid'    => 'uid-does-not-exist-' . uniqid('', true),
                'status' => 'ready',
            ]
        );
        self::assertIsString($body);

        $response = $this->dispatch_webhook($body);

        self::assertSame(404, $response->get_status());
    }
}
