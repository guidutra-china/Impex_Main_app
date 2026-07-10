<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

/**
 * Deterministic item↔photo matching for the import review. Builds the shared image
 * pool and the initial per-item photo assignment: xlsx by drawing anchor row, pdf
 * page-aware (images and items grouped by page, zipped within each page). When page
 * information is missing on either side, falls back to the legacy document-order zip.
 * Unmatched items stay null — the manual picker in the review UI is the fallback.
 */
class ImageItemMatcher
{
    /**
     * @param  array<string,mixed>  $draft
     * @param  array{by_row:array<int,string>,ordered:list<array{path:string,page:int,width:int,height:int}>}  $images
     * @return array{0:list<array{id:int,path:string,page:int}>,1:array<int,?int>}
     */
    public function match(array $draft, array $images): array
    {
        $itens = array_values($draft['itens'] ?? []);

        if ($images['by_row'] !== []) {
            return $this->matchByRow($itens, $images['by_row']);
        }

        if ($images['ordered'] !== []) {
            return $this->matchOrdered($itens, array_values($images['ordered']));
        }

        return [[], []];
    }

    /**
     * @param  list<array<string,mixed>>  $itens
     * @param  array<int,string>  $byRow
     * @return array{0:list<array{id:int,path:string,page:int}>,1:array<int,?int>}
     */
    private function matchByRow(array $itens, array $byRow): array
    {
        ksort($byRow);
        $pool = [];
        $rowToId = [];
        foreach ($byRow as $row => $path) {
            $id = count($pool);
            $pool[] = ['id' => $id, 'path' => $path, 'page' => 0];
            $rowToId[$row] = $id;
        }

        $itemPhoto = [];
        foreach ($itens as $i => $item) {
            $itemPhoto[$i] = $rowToId[(int) ($item['source_row'] ?? 0)] ?? null;
        }

        return [$pool, $itemPhoto];
    }

    /**
     * @param  list<array<string,mixed>>  $itens
     * @param  list<array{path:string,page:int,width:int,height:int}>  $ordered
     * @return array{0:list<array{id:int,path:string,page:int}>,1:array<int,?int>}
     */
    private function matchOrdered(array $itens, array $ordered): array
    {
        $pool = [];
        foreach ($ordered as $i => $img) {
            $pool[] = ['id' => $i, 'path' => $img['path'], 'page' => (int) ($img['page'] ?? 0)];
        }

        $poolHasPages = array_filter($pool, fn (array $e) => $e['page'] > 0) !== [];
        $itemsHavePages = array_filter($itens, fn (array $it) => (int) ($it['page'] ?? 0) > 0) !== [];

        if (! $poolHasPages || ! $itemsHavePages) {
            // Legacy document-order zip when page info is missing on either side
            // (old drafts, unparseable listings): one queue with every image.
            $itemPhoto = $this->assignFromQueues($itens, [0 => array_column($pool, 'id')], fn () => 0);
        } else {
            $poolIdsByPage = [];
            foreach ($pool as $entry) {
                $poolIdsByPage[$entry['page']][] = $entry['id'];
            }

            $itemPhoto = $this->assignFromQueues($itens, $poolIdsByPage, fn (array $it) => (int) ($it['page'] ?? 0));
        }

        $this->propagateVariantGroups($itens, $itemPhoto);

        return [$pool, $itemPhoto];
    }

    /**
     * Consume image queues in item order. A variant group (`_variant_group`, rows
     * expanded from one document line) consumes a single image, shared by all its
     * siblings. On count mismatch within a queue, leftovers stay unassigned rather
     * than shifting onto the wrong product.
     *
     * @param  list<array<string,mixed>>  $itens
     * @param  array<int,list<int>>  $queues  page → ordered pool ids (page 0 = single legacy queue)
     * @param  \Closure(array<string,mixed>):int  $pageOf
     * @return array<int,?int>
     */
    private function assignFromQueues(array $itens, array $queues, \Closure $pageOf): array
    {
        $next = [];
        $groupPhoto = [];
        $itemPhoto = [];

        foreach ($itens as $i => $item) {
            $group = $item['_variant_group'] ?? null;
            if ($group !== null && ($groupPhoto[$group] ?? null) !== null) {
                $itemPhoto[$i] = $groupPhoto[$group];

                continue;
            }

            $page = $pageOf($item);
            $cursor = $next[$page] ?? 0;
            $id = ($queues[$page] ?? [])[$cursor] ?? null;
            if ($id !== null) {
                $next[$page] = $cursor + 1;
            }

            $itemPhoto[$i] = $id;
            if ($group !== null) {
                $groupPhoto[$group] = $id;
            }
        }

        return $itemPhoto;
    }

    /**
     * Siblings of a variant group whose first rows landed on an exhausted queue can
     * still end up split (null + assigned) — fill the nulls with the group's first
     * non-null photo so the shared document photo covers every variant row.
     *
     * @param  list<array<string,mixed>>  $itens
     * @param  array<int,?int>  $itemPhoto
     */
    private function propagateVariantGroups(array $itens, array &$itemPhoto): void
    {
        $firstByGroup = [];
        foreach ($itens as $i => $item) {
            $group = $item['_variant_group'] ?? null;
            if ($group !== null && ($itemPhoto[$i] ?? null) !== null && ! isset($firstByGroup[$group])) {
                $firstByGroup[$group] = $itemPhoto[$i];
            }
        }

        foreach ($itens as $i => $item) {
            $group = $item['_variant_group'] ?? null;
            if ($group !== null && ($itemPhoto[$i] ?? null) === null) {
                $itemPhoto[$i] = $firstByGroup[$group] ?? null;
            }
        }
    }
}
