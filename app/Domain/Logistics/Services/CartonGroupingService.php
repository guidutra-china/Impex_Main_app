<?php

namespace App\Domain\Logistics\Services;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use Illuminate\Support\Collection;

class CartonGroupingService
{
    public const SIGNATURE_MIXED = '__mixed__';

    public const SIGNATURE_EMPTY = '__empty__';

    /**
     * Group cartons by content signature so the UI can render each subgroup
     * with its own expand/collapse control.
     *
     * Signature rules:
     *   - 0 contents → SIGNATURE_EMPTY (all empty boxes form one "Vazias" subgroup)
     *   - all contents share product + part_label → "item:{id}|part:{label}|pcs:{sum}"
     *     (linhas repetidas da mesma mercadoria são somadas — adicionar o mesmo
     *     produto em duas operações não torna a caixa "mista")
     *   - 2+ distinct product/part combinations → SIGNATURE_MIXED
     *     (all mixed boxes collapse into one "Mistas" subgroup)
     *
     * The returned Collection is keyed by signature and ordered by the lowest
     * sort_order in each subgroup, preserving the natural reading order.
     *
     * @param  Collection<int, Carton>  $cartons
     * @return Collection<string, Collection<int, Carton>>
     */
    public function groupByContent(Collection $cartons): Collection
    {
        return $cartons
            ->groupBy(fn (Carton $c) => $this->signatureFor($c))
            ->sortBy(fn (Collection $group) => (int) $group->min('sort_order'))
            ->values()
            ->mapWithKeys(fn (Collection $group) => [
                $this->signatureFor($group->first()) => $group,
            ]);
    }

    private function signatureFor(Carton $carton): string
    {
        $contents = $carton->contents;

        if ($contents->isEmpty()) {
            return self::SIGNATURE_EMPTY;
        }

        $distinct = $contents->groupBy(
            fn (CartonContent $content) => $content->shipment_item_id.'|'.($content->part_label ?? ''),
        );

        if ($distinct->count() !== 1) {
            return self::SIGNATURE_MIXED;
        }

        $first = $contents->first();

        return sprintf(
            'item:%d|part:%s|pcs:%d',
            (int) $first->shipment_item_id,
            (string) ($first->part_label ?? ''),
            (int) $contents->sum('pieces'),
        );
    }
}
