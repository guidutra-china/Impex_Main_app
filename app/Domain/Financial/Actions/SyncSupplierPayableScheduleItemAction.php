<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AdditionalCostSideStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Upserts/removes the [supplier-payable] PaymentScheduleItem of an
 * AdditionalCost: the amount Impex owes a supplier for this cost, mirroring
 * the forwarder-payable pattern used by FREIGHT costs.
 *
 * Ancoragem por processo: recebe-se do cliente pela PI e repassa-se ao
 * fornecedor pela PO — então a linha ancora na PO do fornecedor vinculada à
 * PI quando ela existe; enquanto a PO não foi criada, cai para o documento
 * dono (PI/Shipment) e re-ancora automaticamente no próximo sync (rodado
 * também pelo GeneratePurchaseOrdersAction). Linhas com alocações nunca
 * mudam de payable.
 *
 * Called from both sync paths (AdditionalCostsRelationManager and
 * GeneratePaymentScheduleAction) so the row survives schedule regeneration.
 */
class SyncSupplierPayableScheduleItemAction
{
    public function execute(AdditionalCost $cost, Model $owner): void
    {
        $tag = PaymentScheduleItem::SUPPLIER_PAYABLE_TAG;

        $existing = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag($tag)
            ->first();

        if (! $cost->hasSupplierPayableSide()) {
            if ($existing && ! $existing->isPinnedByAllocations()) {
                $existing->delete();
            }

            return;
        }

        $anchor = $this->resolveAnchor($cost, $owner);

        $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
            ? $cost->cost_type->getEnglishLabel()
            : $cost->cost_type;

        $supplierName = $cost->supplierCompany?->name ?? 'Supplier';

        // Perna de DESCONTO do fornecedor é um CRÉDITO (abate do que a Impex
        // paga na PO); as demais são pagáveis (Impex paga ao fornecedor).
        $isDiscountLeg = $cost->isDiscount();
        $label = $isDiscountLeg
            ? mb_substr("Discount: {$supplierName} - {$cost->description}", 0, 100)
            : mb_substr("{$costTypeLabel} payable: {$supplierName} - {$cost->description}", 0, 100);

        $maxSortOrder = PaymentScheduleItem::where('payable_type', get_class($anchor))
            ->where('payable_id', $anchor->getKey())
            ->max('sort_order') ?? 0;

        $scheduleData = [
            'payable_type' => get_class($anchor),
            'payable_id' => $anchor->getKey(),
            'label' => $label,
            'percentage' => 0,
            'amount' => abs((int) $cost->supplier_payable_amount),
            'currency_code' => $cost->supplier_payable_currency_code ?? $anchor->currency_code ?? 'USD',
            'due_date' => $cost->supplier_payable_due_date,
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => $isDiscountLeg,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => $maxSortOrder + 1,
            'notes' => trim("{$tag} ".($cost->notes ?? '')),
        ];

        $item = $existing;

        if ($existing) {
            if ($existing->isPinnedByAllocations()) {
                // Payable stays put: the allocation was made against that document.
                $existing->update([
                    'label' => $scheduleData['label'],
                    'amount' => $scheduleData['amount'],
                    'currency_code' => $scheduleData['currency_code'],
                    'due_date' => $scheduleData['due_date'],
                    'notes' => $scheduleData['notes'],
                ]);
            } else {
                // Full update re-anchors unallocated rows (e.g. onto a PO created
                // after the cost, or back off a cancelled PO).
                $existing->update($scheduleData);
            }
        } else {
            $item = PaymentScheduleItem::create($scheduleData);
        }

        // Lado fornecedor nasce com o status da parcela; o reconcile assume
        // daí em diante (o seed nunca sobrescreve um status já gravado).
        AdditionalCostSideStatus::seedFromScheduleItem($cost, $item);
    }

    /**
     * PO do fornecedor vinculada à PI, quando existir e não estiver cancelada;
     * senão o documento dono do custo.
     */
    protected function resolveAnchor(AdditionalCost $cost, Model $owner): Model
    {
        return self::resolveSupplierPo($owner, $cost->supplier_company_id) ?? $owner;
    }

    /**
     * PO (não cancelada) de um fornecedor dentro da PI — usada pela ancoragem
     * e pelo aviso dinâmico do formulário de custos.
     */
    public static function resolveSupplierPo(?Model $owner, ?int $supplierId): ?PurchaseOrder
    {
        if (! $owner instanceof ProformaInvoice || ! $supplierId) {
            return null;
        }

        return $owner->purchaseOrders()
            ->where('supplier_company_id', $supplierId)
            ->where('status', '!=', PurchaseOrderStatus::CANCELLED->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * Form-side guard: turning the side off, switching supplier, or switching
     * currency after a payment was allocated against the payable row must be
     * blocked — the allocation was made against that counterparty in that
     * currency. A null $newCurrency skips the currency check.
     */
    public static function assertSideRemovable(AdditionalCost $cost, bool $willHaveSide, ?int $newSupplierId, ?string $newCurrency = null): void
    {
        $existing = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->first();

        if (! $existing || ! $existing->isPinnedByAllocations()) {
            return;
        }

        $supplierChanged = $willHaveSide && (int) $newSupplierId !== (int) $cost->supplier_company_id;

        if (! $willHaveSide) {
            throw ValidationException::withMessages([
                'has_supplier_payable' => __('forms.validation.supplier_payable_has_allocations'),
            ]);
        }

        if ($supplierChanged) {
            throw ValidationException::withMessages([
                'supplier_company_id' => __('forms.validation.supplier_payable_has_allocations'),
            ]);
        }

        $currencyChanged = $willHaveSide
            && $newCurrency !== null
            && $cost->supplier_payable_currency_code !== null
            && $newCurrency !== $cost->supplier_payable_currency_code;

        if ($currencyChanged) {
            throw ValidationException::withMessages([
                'supplier_payable_currency_code' => __('forms.validation.supplier_payable_has_allocations'),
            ]);
        }
    }
}
