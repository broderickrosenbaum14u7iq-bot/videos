<?php
/**
 * Orchestrates a poster/OG image override upload/replace sequence.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Media;

/**
 * Orchestrates a poster/OG image override upload/replace sequence:
 * upload the new file first, persist its ID (via the caller-supplied
 * `$persist` callback), then best-effort delete the superseded image —
 * in that order, so a failure never leaves a video with no valid image
 * reference. Depends only on `ImageUploaderInterface` (never on
 * `Tube_Core\Video\Repositories\VideoMetadataRepositoryInterface`
 * directly), so this class stays within tube-admin's own Composer
 * boundary and is unit-testable against `InMemoryImageUploader` without
 * a live network or WordPress bootstrap — the actual tube-core write
 * happens inside the `$persist` callback the caller provides (built by
 * `Tube_Admin\Video\VideoDetailsScreen`, which is WordPress/tube-core
 * -coupled and integration-tested instead).
 */
final class PosterUploadService
{
    /**
     * Construct the service with its uploader.
     *
     * @param ImageUploaderInterface $uploader The Cloudflare Images uploader.
     */
    public function __construct(private readonly ImageUploaderInterface $uploader)
    {
    }

    /**
     * Upload a new override image, persist it, then clean up the image it replaced.
     *
     * @param string              $file_path        A local path to the uploaded file's temporary contents.
     * @param string              $filename         The original filename.
     * @param int|null            $current_image_id The image ID currently stored, if any — deleted after replace.
     * @param callable(int): void $persist          Called with the new image ID once upload succeeds; persists it.
     *
     * @return int The new image ID.
     *
     * @throws ImageUploadException If the upload itself fails — a delete failure for the superseded image is
     *                              never thrown, only reported via {@see self::last_delete_error()}.
     */
    public function replace(string $file_path, string $filename, ?int $current_image_id, callable $persist): int
    {
        $this->last_delete_error = null;

        $new_image_id = $this->uploader->upload($file_path, $filename);

        $persist($new_image_id);

        if (null !== $current_image_id && $current_image_id !== $new_image_id) {
            try {
                $this->uploader->delete($current_image_id);
            } catch (ImageUploadException $exception) {
                // Best-effort: the new image is already live and persisted
                // by this point, so a delete failure for the now-superseded
                // old image must not be treated as this call's own failure
                // -- surfaced via last_delete_error() for the caller to log
                // or notify about, not thrown.
                $this->last_delete_error = $exception->getMessage();
            }
        }

        return $new_image_id;
    }

    /**
     * The most recent best-effort delete failure message, if any — reset
     * at the start of every {@see self::replace()} call.
     */
    public function last_delete_error(): ?string
    {
        return $this->last_delete_error;
    }

    /**
     * Set by {@see self::replace()}; read via {@see self::last_delete_error()}.
     *
     * @var string|null
     */
    private ?string $last_delete_error = null;
}
