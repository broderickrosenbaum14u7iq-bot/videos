<?php
/**
 * Unit tests for PosterUploadService.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Tube_Admin\Media\ImageUploadException;
use Tube_Admin\Media\PosterUploadService;
use Tube_Admin\Tests\Unit\Media\Fixtures\InMemoryImageUploader;

/**
 * Exercises PosterUploadService's upload/persist/replace-cleanup
 * sequencing against InMemoryImageUploader — no network, no WordPress.
 */
final class PosterUploadServiceTest extends TestCase
{
    /**
     * A first-time upload (no current image) persists the new ID and deletes nothing.
     */
    public function test_replace_with_no_current_image_persists_and_deletes_nothing(): void
    {
        $uploader = new InMemoryImageUploader();
        $uploader->seed_next_id(555);
        $service = new PosterUploadService($uploader);

        $persisted = null;

        $new_id = $service->replace(
            '/tmp/file',
            'poster.jpg',
            null,
            function (int $id) use (&$persisted): void {
                $persisted = $id;
            }
        );

        self::assertSame(555, $new_id);
        self::assertSame(555, $persisted);
        self::assertSame([], $uploader->delete_calls);
        self::assertNull($service->last_delete_error());
    }

    /**
     * Replacing an existing override persists the new ID and deletes the old one.
     */
    public function test_replace_with_a_current_image_deletes_the_old_one_after_persisting(): void
    {
        $uploader = new InMemoryImageUploader();
        $uploader->seed_next_id(200);
        $service = new PosterUploadService($uploader);

        $calls = [];

        $service->replace(
            '/tmp/file',
            'poster.jpg',
            100,
            function (int $id) use (&$calls): void {
                $calls[] = "persist:{$id}";
            }
        );

        self::assertSame([100], $uploader->delete_calls);
        self::assertSame(['persist:200'], $calls);
        self::assertNull($service->last_delete_error());
    }

    /**
     * If the new upload happens to reuse the same ID as the current image, no delete is attempted.
     */
    public function test_replace_skips_delete_when_new_id_equals_current_id(): void
    {
        $uploader = new InMemoryImageUploader();
        $uploader->seed_next_id(100);
        $service = new PosterUploadService($uploader);

        $service->replace(
            '/tmp/file',
            'poster.jpg',
            100,
            static function (int $id): void {
            }
        );

        self::assertSame([], $uploader->delete_calls);
    }

    /**
     * An upload failure propagates and never calls persist().
     */
    public function test_replace_propagates_an_upload_failure_without_persisting(): void
    {
        $uploader = new InMemoryImageUploader();
        $uploader->seed_failure(new ImageUploadException('upload failed'));
        $service = new PosterUploadService($uploader);

        $persist_was_called = false;

        $this->expectException(ImageUploadException::class);

        try {
            $service->replace(
                '/tmp/file',
                'poster.jpg',
                100,
                function () use (&$persist_was_called): void {
                    $persist_was_called = true;
                }
            );
        } finally {
            self::assertFalse($persist_was_called);
        }
    }

    /**
     * A delete failure for the superseded image is caught, not thrown -- the
     * new image is already persisted by that point and must stay in effect.
     */
    public function test_replace_treats_a_delete_failure_as_non_fatal(): void
    {
        $uploader = new InMemoryImageUploader();
        $uploader->seed_next_id(200);
        $service = new PosterUploadService($uploader);

        $persisted = null;

        $new_id = $service->replace(
            '/tmp/file',
            'poster.jpg',
            100,
            function (int $id) use (&$persisted, $uploader): void {
                $persisted = $id;
                // Seed the failure only after upload() already ran, so it fires on the subsequent delete() call.
                $uploader->seed_failure(new ImageUploadException('delete failed'));
            }
        );

        self::assertSame(200, $new_id);
        self::assertSame(200, $persisted);
        self::assertSame('delete failed', $service->last_delete_error());
    }
}
