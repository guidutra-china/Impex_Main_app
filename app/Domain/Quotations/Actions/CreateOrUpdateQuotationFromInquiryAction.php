<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Support\Facades\DB;

class CreateOrUpdateQuotationFromInquiryAction
{
    private const LOCKED_STATUSES = [
        QuotationStatus::SENT,
        QuotationStatus::NEGOTIATING,
        QuotationStatus::APPROVED,
        QuotationStatus::REJECTED,
        QuotationStatus::EXPIRED,
        QuotationStatus::CANCELLED,
    ];

    public function __construct(
        private CurrencyExchangeResolver $fx,
    ) {}

    /**
     * Cria ou atualiza a Quotation gerada a partir de uma Inquiry (e, opcionalmente, das
     * SupplierQuotations selecionadas).
     *
     * A moeda escolhida (`currencyCode`) vale para o cabeçalho da Quotation e para a
     * conversão de FX dos itens, mas a Inquiry do cliente nunca é reescrita — o cliente
     * pediu na moeda dele; o trader cotar em outra não é motivo para alterar o pedido.
     *
     * @param  array<int, int>  $supplierQuotationIds
     * @param  array<int, array{
     *     unit_cost?: float,
     *     cost_currency_code?: string,
     *     cost_exchange_rate?: float,
     *     commission_rate?: float,
     *     unit_price?: float,
     * }>  $itemOverrides  Keyed by inquiry item id.
     */
    public function execute(
        Inquiry $inquiry,
        array $supplierQuotationIds,
        CommissionType $commissionType,
        float $commissionRate,
        bool $showSuppliers,
        bool $forceNewVersion = false,
        array $itemOverrides = [],
        ?Incoterm $incoterm = null,
        ?int $preferredSupplierQuotationId = null,
        // ATENÇÃO: itemOverrides já traz taxa de câmbio e preço unitário prontos por item.
        // Se currencyCode vier junto com itemOverrides, o override prevalece sobre a conversão
        // — os valores do override não são recalculados na nova moeda. Nenhum chamador atual
        // passa os dois juntos (o modal antigo só manda itemOverrides; o novo orquestrador só
        // manda currencyCode), mas um futuro caller que combine ambos precisa computar o
        // override já na moeda de currencyCode.
        ?string $currencyCode = null,
    ): Quotation {
        if ($preferredSupplierQuotationId !== null && ! in_array($preferredSupplierQuotationId, array_map('intval', $supplierQuotationIds), true)) {
            throw new \InvalidArgumentException(
                'A cotação de fornecedor preferida precisa estar entre as cotações consideradas.',
            );
        }

        if ($currencyCode !== null && ! Currency::query()->where('code', $currencyCode)->where('is_active', true)->exists()) {
            throw new \InvalidArgumentException("Moeda inválida ou inativa: {$currencyCode}.");
        }

        // Arredondado ANTES de comparar com o rate gravado (decimal(5,2)) e de calcular
        // unit_price: sem isto, ex. 10.001 grava commission_rate=10.00 (truncado pela coluna)
        // mas calcula unit_price com o float não truncado — rate e preço dessincronizam, e
        // toda rodada seguinte re-detecta "mudança" (10.00 gravado vs 10.001 recebido) e
        // descarta ajustes manuais item a item sem nenhuma mudança real ter sido pedida.
        $commissionRate = round($commissionRate, 2);

        return DB::transaction(function () use (
            $inquiry, $supplierQuotationIds, $commissionType, $commissionRate, $showSuppliers,
            $forceNewVersion, $itemOverrides, $incoterm, $preferredSupplierQuotationId, $currencyCode
        ) {
            $existing = $inquiry->quotations()->latest('version')->first();

            if ($existing && in_array($existing->status, self::LOCKED_STATUSES, true)) {
                if (! $forceNewVersion) {
                    throw new QuotationLockedException($existing);
                }

                \App\Domain\Quotations\Models\QuotationVersion::create([
                    'quotation_id' => $existing->id,
                    'version' => $existing->version,
                    'snapshot' => $this->snapshotQuotation($existing),
                ]);

                $existing = null;
                $newVersion = ($inquiry->quotations()->max('version') ?? 0) + 1;
            } else {
                $newVersion = 1;
            }

            // Capturados ANTES do update() de cabeçalho sobrescrever commission_type/commission_rate:
            // syncItems() usa esta comparação para decidir se a mudança da comissão do cabeçalho
            // (taxa OU tipo — as duas metades da comissão selecionável no modal) vale como
            // "aplicar em toda a cotação" (recalcula todo item) ou se cada item mantém seu
            // próprio ajuste manual (comissão igual à da rodada anterior).
            $previousHeaderCommissionRate = $existing !== null ? (float) $existing->commission_rate : null;
            $previousHeaderCommissionType = $existing?->commission_type;

            if ($existing) {
                $existing->update([
                    'description' => $existing->description ?? $inquiry->description,
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'currency_code' => $currencyCode ?? $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionRate,
                    'incoterm' => $incoterm ?? $existing->incoterm,
                    'show_suppliers' => $showSuppliers,
                ]);
                $quotation = $existing;
            } else {
                $quotation = Quotation::create([
                    'description' => $inquiry->description,
                    'inquiry_id' => $inquiry->id,
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'status' => QuotationStatus::DRAFT,
                    'version' => $newVersion,
                    'currency_code' => $currencyCode ?? $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionRate,
                    'incoterm' => $incoterm,
                    'show_suppliers' => $showSuppliers,
                ]);
            }

            $headerCommissionChanged = $previousHeaderCommissionRate !== null
                && (abs($previousHeaderCommissionRate - $commissionRate) > 0.0001
                    || $previousHeaderCommissionType !== $commissionType);

            $this->syncItems(
                inquiry: $inquiry,
                quotation: $quotation,
                supplierQuotationIds: $supplierQuotationIds,
                commissionType: $commissionType,
                commissionRate: $commissionRate,
                itemOverrides: $itemOverrides,
                preferredSupplierQuotationId: $preferredSupplierQuotationId,
                headerCommissionChanged: $headerCommissionChanged,
            );

            return $quotation->fresh(['items.suppliers']);
        });
    }

    private function syncItems(
        Inquiry $inquiry,
        Quotation $quotation,
        array $supplierQuotationIds,
        CommissionType $commissionType,
        float $commissionRate,
        array $itemOverrides = [],
        ?int $preferredSupplierQuotationId = null,
        bool $headerCommissionChanged = false,
    ): void {
        $sqItemsByProduct = empty($supplierQuotationIds)
            ? collect()
            : SupplierQuotationItem::query()
                ->whereIn('supplier_quotation_id', $supplierQuotationIds)
                ->where('unit_cost', '>', 0)
                ->with('supplierQuotation.company')
                ->get()
                ->groupBy('product_id');

        $existingItems = $quotation->items()->get()->keyBy('product_id');
        $sortOrder = 0;

        foreach ($inquiry->items as $inquiryItem) {
            $productId = $inquiryItem->product_id;
            $alternatives = $sqItemsByProduct->get($productId, collect());

            // Elect primary by lowest converted cost.
            $quoteCurrency = $quotation->currency_code;
            $referenceDate = optional($quotation->created_at)->toDateString() ?? today()->toDateString();
            $sortedAlternatives = $alternatives->sortBy(function ($sqItem) use ($quoteCurrency, $referenceDate) {
                $resolved = $this->fx->resolve(
                    $sqItem->supplierQuotation->currency_code,
                    $quoteCurrency,
                    $referenceDate,
                );

                return $sqItem->unit_cost * $resolved['rate'];
            });

            $primary = $sortedAlternatives->first();

            // A SQ de origem manda quando ela cota este item; senão, menor custo.
            if ($preferredSupplierQuotationId !== null) {
                $primary = $sortedAlternatives->firstWhere('supplier_quotation_id', $preferredSupplierQuotationId) ?? $primary;
            }

            $unitCost = $primary?->unit_cost ?? 0;
            $sourceCurrency = $primary?->supplierQuotation?->currency_code ?? $quoteCurrency;
            $resolved = $this->fx->resolve(
                $sourceCurrency,
                $quoteCurrency,
                $referenceDate,
            );
            $rate = $resolved['rate'];
            $convertedCost = (int) round($unitCost * $rate);

            $existingItem = $existingItems->get($productId);

            // Mudar a comissão do cabeçalho — taxa OU tipo (EMBEDDED/SEPARATE) — vale como
            // "aplicar em toda a cotação": todo item é recalculado com a nova comissão,
            // descartando ajustes manuais anteriores. Manter a mesma comissão do cabeçalho
            // preserva o ajuste que o usuário tenha feito item a item (ex.: reduzir a margem
            // de um produto específico sem reduzir a comissão geral). Sem incluir o tipo aqui,
            // SEPARATE→EMBEDDED com a taxa inalterada mantinha os itens com rate 0 (herdado da
            // era SEPARATE) e a comissão inteira evaporava silenciosamente.
            $itemCommissionRate = ($existingItem && ! $headerCommissionChanged)
                ? (float) $existingItem->commission_rate
                : ($commissionType === CommissionType::EMBEDDED ? $commissionRate : 0);

            $unitPrice = $commissionType === CommissionType::EMBEDDED && $itemCommissionRate > 0
                ? (int) round($convertedCost * (1 + $itemCommissionRate / 100))
                : $convertedCost;

            $rateCapturedAt = $resolved['rate_date'] ?? null;

            $override = $itemOverrides[$inquiryItem->id] ?? null;
            if ($override) {
                $unitCost = (int) round(($override['unit_cost'] ?? 0) * 10000);
                $sourceCurrency = $override['cost_currency_code'] ?? $sourceCurrency;
                $previousRate = $rate;
                $rate = (float) ($override['cost_exchange_rate'] ?? $rate);
                $itemCommissionRate = (float) ($override['commission_rate'] ?? $itemCommissionRate);
                $resolved = ['currency' => $sourceCurrency, 'rate' => $rate];
                $convertedCost = (int) round($unitCost * $rate);
                if (abs($rate - $previousRate) > 0.000001) {
                    $rateCapturedAt = now()->toDateString();
                }

                $overrideUnitPrice = isset($override['unit_price']) ? (float) $override['unit_price'] : null;
                if ($overrideUnitPrice !== null && $overrideUnitPrice > 0) {
                    $unitPrice = (int) round($overrideUnitPrice * 10000);
                } else {
                    $unitPrice = $commissionType === CommissionType::EMBEDDED && $itemCommissionRate > 0
                        ? (int) round($convertedCost * (1 + $itemCommissionRate / 100))
                        : $convertedCost;
                }
            }

            $payload = [
                'quotation_id' => $quotation->id,
                'product_id' => $productId,
                'supplier_quotation_item_id' => $primary?->id,
                'quantity' => $inquiryItem->quantity,
                // Unidade da SQ eleita > unidade pedida no inquiry > pcs.
                'unit' => $primary?->unit ?: ($inquiryItem->unit ?: 'pcs'),
                'selected_supplier_id' => $primary?->supplierQuotation?->company_id,
                'unit_cost' => $unitCost,
                'cost_currency_code' => $resolved['currency'],
                'cost_exchange_rate' => $rate,
                'cost_exchange_rate_captured_at' => $rateCapturedAt,
                'commission_rate' => $itemCommissionRate,
                'unit_price' => $unitPrice,
                // supplier_quotations.incoterm é texto livre e o banco tem valores
                // como "FOB Shanghai Port", que não existem no enum. Normaliza e
                // cai para o incoterm do item/cabeçalho quando não casar, senão o
                // cast de QuotationItem estoura com ValueError.
                'incoterm' => Incoterm::tryFrom((string) ($primary?->supplierQuotation?->incoterm ?? ''))
                    ?? $existingItem?->incoterm
                    ?? $quotation->incoterm,
                'sort_order' => $sortOrder++,
            ];

            if ($existingItem) {
                $existingItem->update($payload);
            } else {
                QuotationItem::create($payload);
            }

            $persistedItem = $existingItem ?: \App\Domain\Quotations\Models\QuotationItem::where('quotation_id', $quotation->id)
                ->where('product_id', $productId)
                ->latest('id')
                ->first();

            // Sync alternatives — replace any prior set with the current pool.
            // The same supplier company may quote a product across multiple SQs;
            // collapse to one row per company (keeping the lowest converted cost)
            // to respect the (quotation_item_id, company_id) unique constraint.
            $dedupedAlternatives = $sortedAlternatives
                ->unique(fn ($sqItem) => $sqItem->supplierQuotation->company_id)
                ->values();

            $persistedItem->suppliers()->delete();
            foreach ($dedupedAlternatives as $alt) {
                $altResolved = $this->fx->resolve(
                    $alt->supplierQuotation->currency_code,
                    $quoteCurrency,
                    $referenceDate,
                );
                \App\Domain\Quotations\Models\QuotationItemSupplier::create([
                    'quotation_item_id' => $persistedItem->id,
                    'company_id' => $alt->supplierQuotation->company_id,
                    'unit_cost' => $alt->unit_cost,
                    'currency_code' => $altResolved['currency'],
                    'cost_exchange_rate' => $altResolved['rate'],
                    'cost_exchange_rate_captured_at' => $altResolved['rate_date'] ?? null,
                    'lead_time_days' => $alt->lead_time_days,
                    'moq' => $alt->moq,
                ]);
            }
        }

        // Drop QuotationItems whose product is no longer present in the inquiry.
        $inquiryProductIds = $inquiry->items->pluck('product_id')->filter()->toArray();
        $quotation->items()
            ->whereNotNull('product_id')
            ->whereNotIn('product_id', $inquiryProductIds)
            ->delete();
    }

    private function snapshotQuotation(Quotation $quotation): array
    {
        return [
            'quotation' => $quotation->toArray(),
            'items' => $quotation->items()->with('suppliers')->get()->toArray(),
        ];
    }
}
