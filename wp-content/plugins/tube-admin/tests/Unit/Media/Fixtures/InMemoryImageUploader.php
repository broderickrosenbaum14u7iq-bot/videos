<?php
/**
 * Test fixture: an in-memory ImageUploaderInterface.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Unit\Media\Fixtures;

use Tube_Admin\Media\ImageUploadException;
use Tube_Admin\Media\ImageUploaderInterface;

/**
 * An in-memory ImageUploaderInterface — no network. Stateful (not just a
 * call recorder): {@see self::seed_next_id()}/{@see self::seed_failure()}
 * control what the next upload()/delete() call does, and every call is
 * recorded for assertions.
 */
final class InMemoryImageUploader implements ImageUploaderInterface
{
    /**
     * The ID upload() should return next; auto-increments if never seeded.
     *
     * @var int
     */
    private int $next_id = 1000;

    /**
     * If set, the next call throws this instead of succeeding.
     *
     * @var ImageUploadException|null
     */
    private ?ImageUploadException $next_failure = null;

    /**
     * Every upload() call this fake received, in order.
     *
     * @var list<array{file_path: string, filename: string}>
     */
    public array $upload_calls = [];

    /**
     * Every delete() call this fake received, in order.
     *
     * @var list<int>
     */
    public array $delete_calls = [];

    /**
     * Seed the ID the next upload() call should return.
     *
     * @param int $image_id The ID to return.
     */
    public function seed_next_id(int $image_id): void
    {
        $this->next_id = $image_id;
    }

    /**
     * Seed the next upload()/delete() call to throw instead of succeeding.
     *
     * @param ImageUploadException $exception The exception to throw.
     */
    public function seed_failure(ImageUploadException $exception): void
    {
        $this->next_failure = $exception;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $file_path A local filesystem path to the uploaded file's temporary contents.
     * @param string $filename  The original filename.
     *
     * @throws ImageUploadException If seeded via self::seed_failure().
     */
    public function upload(string $file_path, string $filename): int
    {
        $this->upload_calls[] = [
            'file_path' => $file_path,
            'filename'  => $filename,
        ];

        if (null !== $this->next_failure) {
            $failure            = $this->next_failure;
            $this->next_failure = null;

            throw $failure;
        }

        return $this->next_id;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $image_id The Cloudflare Images ID to delete.
     *
     * @throws ImageUploadException If seeded via self::seed_failure().
     */
    public function delete(int $image_id): void
    {
        $this->delete_calls[] = $image_id;

        if (null !== $this->next_failure) {
            $failure            = $this->next_failure;
            $this->next_failure = null;

            throw $failure;
        }
    }
}
