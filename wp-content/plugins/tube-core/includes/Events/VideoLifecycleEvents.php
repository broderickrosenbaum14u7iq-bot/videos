<?php
/**
 * Translates video CPT WordPress hooks into tube-core events.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Events;

use Tube_Core\Content\VideoPostType;
use WP_Post;

/**
 * Translates video CPT WordPress hooks into tube-core events.
 *
 * Each `handle_*` method is a thin adapter matching a specific
 * WordPress hook signature (and is therefore only usable once WordPress
 * is loaded); each delegates to a `dispatch_*` method that takes only
 * primitives and does the actual decision-making, so that logic can be
 * unit-tested without WordPress — the same split VideoPostType et al.
 * use between "does the WordPress registration" and everything else.
 */
final class VideoLifecycleEvents
{
    /**
     * Construct around the dispatcher every video lifecycle event goes through.
     *
     * @param Dispatcher $dispatcher The dispatcher every video lifecycle event goes through.
     */
    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    /**
     * Wire this class's handlers to the relevant WordPress hooks.
     */
    public function register(): void
    {
        add_action('save_post_' . VideoPostType::POST_TYPE, [$this, 'handle_save'], 10, 3);
        add_action('transition_post_status', [$this, 'handle_status_transition'], 10, 3);
        add_action('before_delete_post', [$this, 'handle_before_delete'], 10, 2);
    }

    /**
     * `save_post_video` handler: dispatches VIDEO_CREATED or VIDEO_UPDATED.
     *
     * Guards against revisions, autosaves, and the transient `auto-draft`
     * status WordPress creates the moment someone opens "Add New Video"
     * — none of those are a real create/update event worth dispatching.
     *
     * @param int     $post_id The video post ID.
     * @param WP_Post $post    The video post.
     * @param bool    $update  True if this is an update to an existing post.
     */
    public function handle_save(int $post_id, WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if ('auto-draft' === $post->post_status) {
            return;
        }

        $this->dispatch_save($post_id, $update);
    }

    /**
     * `transition_post_status` handler: dispatches VIDEO_PUBLISHED.
     *
     * Filters to the `video` post type only — this hook fires for every
     * post type.
     *
     * @param string  $new_status The status being transitioned to.
     * @param string  $old_status The status being transitioned from.
     * @param WP_Post $post       The post transitioning status.
     */
    public function handle_status_transition(string $new_status, string $old_status, WP_Post $post): void
    {
        if (VideoPostType::POST_TYPE !== $post->post_type) {
            return;
        }

        $video_id = (int) $post->ID;

        $this->dispatch_status_transition($video_id, $new_status, $old_status);
    }

    /**
     * `before_delete_post` handler: dispatches VIDEO_DELETED.
     *
     * Filters to the `video` post type only, and to permanent deletion
     * only — trashing a video already fires VIDEO_UPDATED via handle_save().
     *
     * @param int     $post_id The post being deleted.
     * @param WP_Post $post    The post being deleted.
     */
    public function handle_before_delete(int $post_id, WP_Post $post): void
    {
        if (VideoPostType::POST_TYPE !== $post->post_type) {
            return;
        }

        $this->dispatch_delete($post_id);
    }

    /**
     * Dispatch VIDEO_CREATED or VIDEO_UPDATED depending on $update.
     *
     * @param int  $video_id The video post ID.
     * @param bool $update   True if this is an update to an existing post.
     */
    public function dispatch_save(int $video_id, bool $update): void
    {
        $this->dispatcher->dispatch(
            $update ? EventCatalog::VIDEO_UPDATED : EventCatalog::VIDEO_CREATED,
            ['video_id' => $video_id]
        );
    }

    /**
     * Dispatch VIDEO_PUBLISHED if this transition is a genuine first
     * publish (any non-publish status moving to publish).
     *
     * @param int    $video_id   The video post ID.
     * @param string $new_status The status being transitioned to.
     * @param string $old_status The status being transitioned from.
     */
    public function dispatch_status_transition(int $video_id, string $new_status, string $old_status): void
    {
        if ('publish' === $new_status && 'publish' !== $old_status) {
            $this->dispatcher->dispatch(EventCatalog::VIDEO_PUBLISHED, ['video_id' => $video_id]);
        }
    }

    /**
     * Dispatch VIDEO_DELETED.
     *
     * @param int $video_id The video post ID.
     */
    public function dispatch_delete(int $video_id): void
    {
        $this->dispatcher->dispatch(EventCatalog::VIDEO_DELETED, ['video_id' => $video_id]);
    }
}
