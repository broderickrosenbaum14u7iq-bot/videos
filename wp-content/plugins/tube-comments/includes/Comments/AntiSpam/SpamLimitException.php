<?php
/**
 * Thrown when a member's comment/reply creation is blocked by an
 * anti-spam rate/duplicate/flood rule.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\AntiSpam;

use RuntimeException;

/**
 * Thrown by `SpamGuard` when creation is blocked by a time-based
 * anti-spam rule (the root-comment-per-video window, reply burst/daily
 * caps, the global daily cap, duplicate content, or flood detection) —
 * distinct from `Tube_Comments\Comments\ValidationException`, which
 * covers content that is simply malformed (empty, too short) rather
 * than rate-limited. Callers should respond 429, matching this
 * project's existing comment-creation rate limiter's own status code.
 *
 * Carries a machine-readable `code` (for the frontend to branch on,
 * e.g. re-rendering the root composer as blocked) and an optional
 * `available_at` instant the caller can format for the visitor and
 * derive a `retry_after` duration from — never exposes which internal
 * table/query produced the block.
 */
final class SpamLimitException extends RuntimeException
{
    /**
     * A stable, machine-readable identifier for this block (e.g.
     * `tube_comment_video_daily_limit`). Deliberately NOT named `$code`
     * — `Exception` already declares a non-readonly `$code` property
     * (its numeric exception code), and PHP forbids a subclass from
     * redeclaring an inherited property as `readonly`.
     *
     * @var string
     */
    private readonly string $spam_code;

    /**
     * Construct with the block's identifier, display message, and optional retry instant.
     *
     * @param string      $code         A stable, machine-readable identifier (e.g. `tube_comment_video_daily_limit`).
     * @param string      $message      An already-Vietnamese, already-safe-to-display message.
     * @param string|null $available_at ISO 8601 instant the visitor may retry, or null if not precisely known.
     */
    public function __construct(string $code, string $message, private readonly ?string $available_at = null)
    {
        parent::__construct($message);

        $this->spam_code = $code;
    }

    /**
     * The stable, machine-readable identifier for this block.
     */
    public function code(): string
    {
        return $this->spam_code;
    }

    /**
     * ISO 8601 instant the visitor may retry, or null if not precisely known.
     */
    public function available_at(): ?string
    {
        return $this->available_at;
    }
}
