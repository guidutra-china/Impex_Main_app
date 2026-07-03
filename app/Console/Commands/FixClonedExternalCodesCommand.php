<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\CompanyProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Repairs company_product pivots polluted by the product Clone action, which
 * (until 2026-07-03) copied external_code/external_name/external_description
 * to every replica. Duplicated values across different products of the same
 * company+role are clone artifacts: the row with the earliest created_at is
 * presumed to be the intentional original and is kept; the field is cleared on
 * the others so documents (CI/Packing List) fall back to the product's own
 * model_number until a real client code is set.
 *
 * Dry-run by default; pass --apply to write.
 */
class FixClonedExternalCodesCommand extends Command
{
    private const FIELDS = ['external_code', 'external_name', 'external_description'];

    /**
     * Consecutive rows created within this window belong to the same batch
     * (one import request inserts its rows with sub-second to few-second gaps).
     * Clones are created one at a time through the UI — even fast cloning
     * takes tens of seconds per product — so they never chain into a batch.
     */
    private const SAME_BATCH_WINDOW_SECONDS = 5;

    /**
     * A batch needs at least this many rows to be presumed an intentional bulk
     * import; cloning this many products through the UI within the window is
     * implausible. The batch containing the oldest row is always kept.
     */
    private const BATCH_MIN_ROWS = 3;

    protected $signature = 'catalog:fix-cloned-external-codes
                            {--apply : Actually clear the duplicated fields (default is a dry-run report)}
                            {--company= : Only scan pivots of this company ID}
                            {--role= : Only scan this pivot role (client or supplier); default both}
                            {--field= : Comma-separated fields to scan (external_code,external_name,external_description); default all}
                            {--skip= : Comma-separated pivot IDs to never clear (kept as-is)}
                            {--include-same-batch : Keep only the single oldest row instead of the whole original import batch}';

    protected $description = 'Clear external code/name/description inherited by cloned products, keeping the oldest pivot of each duplicate group';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $fields = $this->resolveFields();

        if ($fields === null) {
            return self::FAILURE;
        }

        $role = $this->option('role');
        if ($role !== null && ! in_array($role, ['client', 'supplier'], true)) {
            $this->error('Invalid --role. Use client or supplier.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('DRY-RUN — nothing will be changed. Re-run with --apply to write.');
        }

        $totalCleared = 0;

        foreach ($fields as $field) {
            $totalCleared += $this->processField($field, $role, $apply);
        }

        $this->newLine();
        $verb = $apply ? 'cleared' : 'would be cleared';
        $this->info("Done. {$totalCleared} pivot field(s) {$verb}.");

        if (! $apply && $totalCleared > 0) {
            $this->comment('  php artisan catalog:fix-cloned-external-codes --apply');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function resolveFields(): ?array
    {
        $raw = $this->option('field');

        if (blank($raw)) {
            return self::FIELDS;
        }

        $fields = collect(explode(',', (string) $raw))
            ->map(fn (string $f) => trim($f))
            ->filter()
            ->values()
            ->all();

        $invalid = array_diff($fields, self::FIELDS);
        if ($invalid !== []) {
            $this->error('Invalid --field: '.implode(', ', $invalid).'. Allowed: '.implode(', ', self::FIELDS));

            return null;
        }

        return $fields;
    }

    private function processField(string $field, ?string $role, bool $apply): int
    {
        $groups = $this->duplicateGroups($field, $role);

        if ($groups->isEmpty()) {
            $this->info("[{$field}] no duplicate groups found.");

            return 0;
        }

        $this->newLine();
        $this->warn("[{$field}] ".$groups->count().' duplicate group(s):');

        $cleared = 0;
        $rows = [];
        $skipIds = $this->skippedPivotIds();

        foreach ($groups as $group) {
            /** @var Collection<int, CompanyProduct> $pivots */
            $pivots = $group['pivots'];
            $keptIds = $group['kept_ids'];

            foreach ($pivots as $pivot) {
                $isKept = in_array($pivot->id, $keptIds, true);
                $isSkipped = ! $isKept && in_array($pivot->id, $skipIds, true);

                $rows[] = [
                    $isKept ? 'KEEP' : ($isSkipped ? 'SKIP' : ($apply ? 'CLEAR' : 'clear')),
                    $pivot->id,
                    $group['company_name'],
                    $pivot->role,
                    $pivot->product?->sku ?? '—',
                    $pivot->product?->model_number ?? '—',
                    str(($pivot->{$field} ?? ''))->limit(40)->toString(),
                    $pivot->created_at?->format('Y-m-d H:i'),
                ];

                if (! $isKept && ! $isSkipped) {
                    if ($apply) {
                        $pivot->update([$field => null]);
                    }
                    $cleared++;
                }
            }
        }

        $this->table(
            ['Action', 'Pivot ID', 'Company', 'Role', 'SKU', 'Model No. (cadastro)', ucfirst(str_replace('_', ' ', $field)), 'Created'],
            $rows,
        );

        return $cleared;
    }

    /**
     * Groups of pivots sharing the same company+role+field value across
     * DIFFERENT products, ordered so the oldest (presumed original) is first.
     *
     * Rows are clustered into batches (consecutive gap ≤ window). The batch
     * containing the oldest row is kept, as is any batch with ≥ BATCH_MIN_ROWS
     * rows (an intentional bulk import); remaining rows — clones by definition,
     * since a clone is always newer than its original and created one at a
     * time — are cleared. With --include-same-batch only the single oldest row
     * is kept.
     *
     * @return Collection<int, array{company_name: string, pivots: Collection<int, CompanyProduct>, kept_ids: array<int, int>}>
     */
    private function duplicateGroups(string $field, ?string $role): Collection
    {
        $query = CompanyProduct::query()
            ->with([
                'company:id,name',
                'product' => fn ($q) => $q->withTrashed()->select('id', 'sku', 'model_number'),
            ])
            ->whereNotNull($field)
            ->where($field, '!=', '');

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }

        $includeSameBatch = (bool) $this->option('include-same-batch');

        return $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CompanyProduct $p) => $p->company_id.'|'.$p->role.'|'.mb_strtolower(trim((string) $p->{$field})))
            ->filter(fn (Collection $pivots) => $pivots->pluck('product_id')->unique()->count() > 1)
            ->map(function (Collection $pivots) use ($includeSameBatch) {
                $pivots = $pivots->values();
                $oldest = $pivots->first();

                return [
                    'company_name' => $oldest->company?->name ?? ('#'.$oldest->company_id),
                    'pivots' => $pivots,
                    'kept_ids' => $includeSameBatch ? [$oldest->id] : $this->keptIdsFromBatches($pivots),
                ];
            })
            ->filter(fn (array $group) => count($group['kept_ids']) < $group['pivots']->count())
            ->values();
    }

    /**
     * Cluster the (already sorted) pivots into creation batches and return the
     * IDs to keep: the batch holding the oldest row plus any batch large enough
     * to be a bulk import rather than one-by-one cloning.
     *
     * @param  Collection<int, CompanyProduct>  $pivots
     * @return array<int, int>
     */
    private function keptIdsFromBatches(Collection $pivots): array
    {
        $batches = [];
        $current = [];
        $previous = null;

        foreach ($pivots as $pivot) {
            $gap = ($previous?->created_at !== null && $pivot->created_at !== null)
                ? $previous->created_at->diffInSeconds($pivot->created_at, true)
                : 0;

            if ($previous !== null && $gap > self::SAME_BATCH_WINDOW_SECONDS) {
                $batches[] = $current;
                $current = [];
            }

            $current[] = $pivot;
            $previous = $pivot;
        }

        $batches[] = $current;

        $kept = [];

        foreach ($batches as $index => $batch) {
            if ($index === 0 || count($batch) >= self::BATCH_MIN_ROWS) {
                $kept = array_merge($kept, array_map(fn (CompanyProduct $p) => $p->id, $batch));
            }
        }

        return $kept;
    }

    /**
     * @return array<int, int>
     */
    private function skippedPivotIds(): array
    {
        return collect(explode(',', (string) $this->option('skip')))
            ->map(fn (string $id) => (int) trim($id))
            ->filter()
            ->values()
            ->all();
    }
}
