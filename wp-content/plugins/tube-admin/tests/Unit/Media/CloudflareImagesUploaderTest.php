<?php
/**
 * Unit tests for CloudflareImagesUploader::build_multipart_body().
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Tube_Admin\Media\CloudflareImagesUploader;

/**
 * Exercises {@see CloudflareImagesUploader::build_multipart_body()} — the
 * one piece of that class with zero WordPress dependency. Every other
 * method (upload()/delete()) is WordPress-coupled (wp_remote_post()/
 * wp_remote_request()) and not exercised here; see PosterUploadServiceTest
 * for the orchestration logic that sits on top of ImageUploaderInterface,
 * tested against a fake instead.
 */
final class CloudflareImagesUploaderTest extends TestCase
{
    /**
     * The built body contains correctly-delimited id and file parts.
     */
    public function test_build_multipart_body_produces_correct_shape(): void
    {
        $body = CloudflareImagesUploader::build_multipart_body(
            'BOUNDARY123',
            'raw-image-bytes',
            'poster.jpg',
            '42'
        );

        self::assertSame(
            "--BOUNDARY123\r\n"
            . "Content-Disposition: form-data; name=\"id\"\r\n\r\n"
            . "42\r\n"
            . "--BOUNDARY123\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"poster.jpg\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . "raw-image-bytes\r\n"
            . "--BOUNDARY123--\r\n",
            $body
        );
    }

    /**
     * A filename containing characters unsafe for a header value is sanitized.
     */
    public function test_build_multipart_body_sanitizes_an_unsafe_filename(): void
    {
        $body = CloudflareImagesUploader::build_multipart_body(
            'B',
            'bytes',
            'my "poster".jpg; evil',
            '1'
        );

        self::assertStringContainsString('filename="my__poster_.jpg__evil"', $body);
        self::assertStringNotContainsString('"my "poster"', $body);
    }

    /**
     * An empty filename falls back to a safe default rather than producing an empty header value.
     */
    public function test_build_multipart_body_falls_back_for_an_empty_filename(): void
    {
        $body = CloudflareImagesUploader::build_multipart_body('B', 'bytes', '', '1');

        self::assertStringContainsString('filename="upload"', $body);
    }

    /**
     * Binary file content survives untouched inside the body (no corruption from the surrounding text construction).
     */
    public function test_build_multipart_body_preserves_binary_content(): void
    {
        $binary = "\x00\x01\xFF\xFEbinary\x00data";

        $body = CloudflareImagesUploader::build_multipart_body('B', $binary, 'x.bin', '1');

        self::assertStringContainsString($binary, $body);
    }
}
