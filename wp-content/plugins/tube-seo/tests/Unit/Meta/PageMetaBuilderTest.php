<?php
/**
 * Unit tests for PageMetaBuilder.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Unit\Meta;

use PHPUnit\Framework\TestCase;
use Tube_Seo\Meta\PageMetaBuilder;

/**
 * Exercises PageMetaBuilder's per-page-type text/decision logic — no WordPress.
 */
final class PageMetaBuilderTest extends TestCase
{
    /**
     * For_video() builds an indexable, video.other-typed meta set.
     */
    public function test_for_video(): void
    {
        $meta = PageMetaBuilder::for_video(
            'Example Site',
            'My Video',
            'A description.',
            'https://example.com/watch/my-video/',
            'https://example.com/thumb.jpg'
        );

        self::assertSame('My Video | Example Site', $meta->title);
        self::assertSame('A description.', $meta->description);
        self::assertSame('https://example.com/watch/my-video/', $meta->canonical);
        self::assertSame('index, follow', $meta->robots);
        self::assertSame('video.other', $meta->og_type);
        self::assertSame('https://example.com/thumb.jpg', $meta->image_url);
    }

    /**
     * For_archive() page 1 omits the page number from the title.
     */
    public function test_for_archive_page_one_omits_page_number(): void
    {
        $meta = PageMetaBuilder::for_archive('Example Site', 'Videos', 'Action', 'desc', 'https://x/', 1, 24);

        self::assertSame('Action Videos | Example Site', $meta->title);
        self::assertSame('index, follow', $meta->robots);
    }

    /**
     * For_archive() page 2+ includes the page number in the title.
     */
    public function test_for_archive_page_two_includes_page_number(): void
    {
        $meta = PageMetaBuilder::for_archive('Example Site', 'Videos', 'Action', 'desc', 'https://x/page/2/', 2, 24);

        self::assertStringContainsString('Page 2', $meta->title);
    }

    /**
     * For_archive() with zero items on the page is noindexed.
     */
    public function test_for_archive_with_zero_items_is_noindexed(): void
    {
        $meta = PageMetaBuilder::for_archive('Example Site', 'Videos', 'Action', 'desc', 'https://x/', 1, 0);

        self::assertSame('noindex, follow', $meta->robots);
    }

    /**
     * 2026-08-26 SEO audit P1 fix: $force_noindex noindexes an archive
     * page even though it has real items on it — the thin-tag rule.
     */
    public function test_for_archive_with_force_noindex_is_noindexed_despite_having_items(): void
    {
        $meta = PageMetaBuilder::for_archive('Example Site', 'Videos', 'roma', 'desc', 'https://x/', 1, 2, true);

        self::assertSame('noindex, follow', $meta->robots);
    }

    /**
     * $force_noindex defaults to false — every existing call site
     * (categories, actors, studios) is unaffected.
     */
    public function test_for_archive_without_force_noindex_stays_indexed(): void
    {
        $meta = PageMetaBuilder::for_archive('Example Site', 'Videos', 'Action', 'desc', 'https://x/', 1, 24);

        self::assertSame('index, follow', $meta->robots);
    }

    /**
     * For_search() is always noindexed, regardless of result count.
     */
    public function test_for_search_is_always_noindexed(): void
    {
        $meta_with_results = PageMetaBuilder::for_search('Example Site', 'cats', 'https://x/search/cats/', 10);
        $meta_no_results   = PageMetaBuilder::for_search('Example Site', 'zzz', 'https://x/search/zzz/', 0);

        self::assertSame('noindex, follow', $meta_with_results->robots);
        self::assertSame('noindex, follow', $meta_no_results->robots);
        self::assertStringContainsString('cats', $meta_with_results->title);
    }

    /**
     * For_search() with a blank query uses a generic title, not a broken quoted-empty-string one.
     */
    public function test_for_search_with_blank_query_uses_a_generic_title(): void
    {
        $meta = PageMetaBuilder::for_search('Example Site', '', 'https://x/search/', 0);

        self::assertSame('Search | Example Site', $meta->title);
    }

    /**
     * For_home() is indexable and carries no image.
     */
    public function test_for_home(): void
    {
        $meta = PageMetaBuilder::for_home('Example Site', 'A great tube site.', 'https://example.com/');

        self::assertSame('Example Site', $meta->title);
        self::assertSame('index, follow', $meta->robots);
        self::assertNull($meta->image_url);
    }
}
