<?php
/**
 * Unit tests for WebhookSignatureVerifier.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Stream;

use PHPUnit\Framework\TestCase;
use Tube_Core\Stream\WebhookSignatureVerifier;

/**
 * Exercises WebhookSignatureVerifier — no WordPress, no network; every
 * signature here is computed the same way the class itself computes one,
 * so these tests prove the verification logic matches the signing logic
 * exactly, not just that "some string" is accepted.
 */
final class WebhookSignatureVerifierTest extends TestCase
{
    /**
     * The verifier under test.
     *
     * @var WebhookSignatureVerifier
     */
    private WebhookSignatureVerifier $verifier;

    /**
     * Build a fresh verifier for each test.
     */
    protected function setUp(): void
    {
        $this->verifier = new WebhookSignatureVerifier();
    }

    /**
     * A correctly-signed, fresh request is accepted.
     */
    public function test_a_correctly_signed_fresh_request_is_accepted(): void
    {
        $body   = '{"uid":"abc123","status":"ready"}';
        $secret = 'test-secret';
        $header = $this->sign($body, $secret, time());

        self::assertTrue($this->verifier->verify($body, $header, $secret));
    }

    /**
     * A request signed with the wrong secret is rejected.
     */
    public function test_wrong_secret_is_rejected(): void
    {
        $body   = '{"uid":"abc123","status":"ready"}';
        $header = $this->sign($body, 'the-real-secret', time());

        self::assertFalse($this->verifier->verify($body, $header, 'a-different-secret'));
    }

    /**
     * A body that doesn't match what was signed is rejected — proves the
     * signature actually covers the body, not just the timestamp.
     */
    public function test_a_tampered_body_is_rejected(): void
    {
        $secret = 'test-secret';
        $header = $this->sign('{"uid":"abc123","status":"ready"}', $secret, time());

        self::assertFalse($this->verifier->verify('{"uid":"abc123","status":"error"}', $header, $secret));
    }

    /**
     * A signature older than the 5-minute freshness window is rejected,
     * even though it is genuinely valid — replay protection.
     */
    public function test_a_stale_signature_is_rejected(): void
    {
        $body   = '{"uid":"abc123","status":"ready"}';
        $secret = 'test-secret';
        $header = $this->sign($body, $secret, time() - 301);

        self::assertFalse($this->verifier->verify($body, $header, $secret));
    }

    /**
     * A malformed Webhook-Signature header is rejected, not fatal.
     */
    public function test_a_malformed_header_is_rejected(): void
    {
        self::assertFalse($this->verifier->verify('{}', 'not-a-real-signature-header', 'test-secret'));
    }

    /**
     * An empty Webhook-Signature header is rejected, not fatal.
     */
    public function test_an_empty_header_is_rejected(): void
    {
        self::assertFalse($this->verifier->verify('{}', '', 'test-secret'));
    }

    /**
     * Build a `Webhook-Signature` header the same way
     * WebhookSignatureVerifier itself expects one to be built.
     *
     * @param string $body      The request body the signature covers.
     * @param string $secret    The shared secret.
     * @param int    $timestamp The signature's timestamp.
     */
    private function sign(string $body, string $secret, int $timestamp): string
    {
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return "time={$timestamp},sig1={$signature}";
    }
}
