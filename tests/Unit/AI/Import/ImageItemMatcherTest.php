<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Import;

use App\Domain\AI\Import\ImageItemMatcher;
use Tests\TestCase;

class ImageItemMatcherTest extends TestCase
{
    /** @param  list<int>  $pages */
    private function ordered(array $pages): array
    {
        return array_map(fn (int $p, int $i) => [
            'path' => "/tmp/img{$i}.png", 'page' => $p, 'width' => 200, 'height' => 200,
        ], $pages, array_keys($pages));
    }

    public function test_pdf_page_aware_zip_matches_within_each_page(): void
    {
        $draft = ['itens' => [
            ['description' => 'A', 'page' => 1],
            ['description' => 'B', 'page' => 1],
            ['description' => 'C', 'page' => 2],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([1, 1, 2])];

        [$pool, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertCount(3, $pool);
        $this->assertSame(['id' => 0, 'path' => '/tmp/img0.png', 'page' => 1], $pool[0]);
        $this->assertSame([0 => 0, 1 => 1, 2 => 2], $itemPhoto);
    }

    public function test_pdf_count_mismatch_leaves_leftovers_unassigned_instead_of_shifting(): void
    {
        // Page 1 has an extra image (e.g. a factory photo); page 2 aligns.
        $draft = ['itens' => [
            ['description' => 'A', 'page' => 1],
            ['description' => 'B', 'page' => 1],
            ['description' => 'C', 'page' => 2],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([1, 1, 1, 2])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        // Items on page 1 take the first two page-1 images; page 2 is NOT shifted.
        $this->assertSame([0 => 0, 1 => 1, 2 => 3], $itemPhoto);
    }

    public function test_pdf_item_with_more_items_than_images_on_page_gets_null(): void
    {
        $draft = ['itens' => [
            ['description' => 'A', 'page' => 1],
            ['description' => 'B', 'page' => 1],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([1])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertSame([0 => 0, 1 => null], $itemPhoto);
    }

    public function test_pdf_item_without_page_stays_null_when_pages_are_in_play(): void
    {
        $draft = ['itens' => [
            ['description' => 'A', 'page' => 1],
            ['description' => 'B'],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([1, 1])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertSame([0 => 0, 1 => null], $itemPhoto);
    }

    public function test_pdf_falls_back_to_positional_zip_when_no_item_has_page(): void
    {
        $draft = ['itens' => [
            ['description' => 'A'],
            ['description' => 'B'],
            ['description' => 'C'],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([1, 1])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertSame([0 => 0, 1 => 1, 2 => null], $itemPhoto);
    }

    public function test_pdf_falls_back_to_positional_zip_when_pool_has_no_pages(): void
    {
        // Legacy pool rebuilt after a chat edit may carry page 0 everywhere.
        $draft = ['itens' => [
            ['description' => 'A', 'page' => 1],
            ['description' => 'B', 'page' => 2],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([0, 0])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertSame([0 => 0, 1 => 1], $itemPhoto);
    }

    public function test_xlsx_anchor_row_matching_unchanged(): void
    {
        $draft = ['itens' => [
            ['description' => 'A', 'source_row' => 4],
            ['description' => 'B', 'source_row' => 9],
            ['description' => 'C'],
        ]];
        $images = ['by_row' => [9 => '/tmp/r9.png', 4 => '/tmp/r4.png'], 'ordered' => []];

        [$pool, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertSame(['id' => 0, 'path' => '/tmp/r4.png', 'page' => 0], $pool[0]);
        $this->assertSame(['id' => 1, 'path' => '/tmp/r9.png', 'page' => 0], $pool[1]);
        $this->assertSame([0 => 0, 1 => 1, 2 => null], $itemPhoto);
    }

    public function test_variant_group_shares_one_image_without_consuming_extra_slots(): void
    {
        // Página 1: kettlebell expandido em 3 variantes + um item simples; só 2 fotos.
        $draft = ['itens' => [
            ['description' => 'KB — 8kg', 'page' => 1, '_variant_group' => 0],
            ['description' => 'KB — 10kg', 'page' => 1, '_variant_group' => 0],
            ['description' => 'KB — 12kg', 'page' => 1, '_variant_group' => 0],
            ['description' => 'Trampoline', 'page' => 1],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([1, 1])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        // O grupo consome UMA foto (compartilhada); o item seguinte pega a próxima.
        $this->assertSame([0 => 0, 1 => 0, 2 => 0, 3 => 1], $itemPhoto);
    }

    public function test_variant_group_shares_image_in_legacy_positional_mode(): void
    {
        $draft = ['itens' => [
            ['description' => 'KB — 8kg', '_variant_group' => 0],
            ['description' => 'KB — 10kg', '_variant_group' => 0],
            ['description' => 'Rope'],
        ]];
        $images = ['by_row' => [], 'ordered' => $this->ordered([0, 0])];

        [, $itemPhoto] = (new ImageItemMatcher)->match($draft, $images);

        $this->assertSame([0 => 0, 1 => 0, 2 => 1], $itemPhoto);
    }

    public function test_no_images_yields_empty_pool_and_map(): void
    {
        [$pool, $itemPhoto] = (new ImageItemMatcher)->match(
            ['itens' => [['description' => 'A']]],
            ['by_row' => [], 'ordered' => []],
        );

        $this->assertSame([], $pool);
        $this->assertSame([], $itemPhoto);
    }
}
