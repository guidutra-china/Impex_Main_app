<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Extracts entity references like "@PI-2026-00123" from message bodies
 * and resolves them to Eloquent models for downstream MessageReference rows.
 *
 * Reference format produced by GenerateReferenceAction: {TYPE}-{YEAR}-{NNNNN}
 * where TYPE is the DocumentType enum value (2-3 letters).
 */
class ReferenceResolver
{
    /**
     * Map DocumentType prefix → fully qualified Eloquent model class.
     * Only entries that exist in the codebase are mapped; unknown prefixes
     * are silently skipped (the token stays as plain text).
     */
    public const MODEL_MAP = [
        DocumentType::INQUIRY->value             => Inquiry::class,
        DocumentType::SUPPLIER_QUOTATION->value  => SupplierQuotation::class,
        DocumentType::QUOTATION->value           => Quotation::class,
        DocumentType::PROFORMA_INVOICE->value    => ProformaInvoice::class,
        DocumentType::PURCHASE_ORDER->value      => PurchaseOrder::class,
        DocumentType::SHIPMENT->value            => Shipment::class,
        DocumentType::PRODUCTION_SCHEDULE->value => ProductionSchedule::class,
    ];

    /**
     * Regex matches `@TYPE-YYYY-NNNNN` (5 or 6 digit number, 2-3 letter type).
     * `@` prefix is required so plain mentions of references don't get linked.
     */
    public const TOKEN_REGEX = '/@([A-Z]{2,3})-(\d{4})-(\d{5,6})/';

    /**
     * Extract every unique reference token from the body.
     *
     * @return array<int, array{token: string, type: string, code: string}>
     */
    public function extractTokens(string $body): array
    {
        if (! preg_match_all(self::TOKEN_REGEX, $body, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $seen = [];
        $tokens = [];

        foreach ($matches as $match) {
            $code = "{$match[1]}-{$match[2]}-{$match[3]}";

            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            $tokens[] = [
                'token' => $match[0],          // "@PI-2026-00123"
                'type'  => $match[1],          // "PI"
                'code'  => $code,              // "PI-2026-00123"
            ];
        }

        return $tokens;
    }

    /**
     * Resolve tokens to actual Eloquent models keyed by reference_code.
     *
     * Returns: [
     *   "PI-2026-00123" => ['type' => 'PI', 'model' => ProformaInvoice, 'code' => '...'],
     *   ...
     * ]
     *
     * Tokens whose prefix isn't mapped, or whose model can't be found, are dropped.
     *
     * @return Collection<string, array{type: string, model: Model, code: string}>
     */
    public function resolve(string $body): Collection
    {
        $tokens = $this->extractTokens($body);

        $resolved = collect();

        // Group by document type to query each model class once.
        $byType = collect($tokens)->groupBy('type');

        foreach ($byType as $type => $group) {
            $modelClass = self::MODEL_MAP[$type] ?? null;
            if ($modelClass === null) {
                continue;
            }

            $codes = $group->pluck('code')->all();

            /** @var Collection<int, Model> $models */
            $models = $modelClass::query()
                ->whereIn('reference', $codes)
                ->get()
                ->keyBy('reference');

            foreach ($group as $entry) {
                $model = $models->get($entry['code']);
                if ($model !== null) {
                    $resolved->put($entry['code'], [
                        'type'  => $type,
                        'model' => $model,
                        'code'  => $entry['code'],
                    ]);
                }
            }
        }

        return $resolved;
    }
}
